<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\AppSetting;
use App\Models\AssessmentResult;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\QuranFinalTest;
use App\Models\StudentAttendanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class CourseCompletionRuleService
{
    public const ADJUSTMENT_POINT_TYPE_CODE = 'course-completion-adjustment';
    public const ADJUSTMENT_SOURCE_TYPE = 'course_completion_rule';

    public function settings(): array
    {
        $settings = AppSetting::groupValues('course_completion');

        return [
            'required_passed_final_tests' => max(0, (int) ($settings->get('required_passed_final_tests') ?? 1)),
            'required_passed_quizzes' => max(0, (int) ($settings->get('required_passed_quizzes') ?? 1)),
            'required_present_attendance' => max(0, (int) ($settings->get('required_present_attendance') ?? 1)),
            'retain_percentage' => min(100, max(0, (int) ($settings->get('retain_percentage') ?? 50))),
        ];
    }

    public function saveSettings(array $validated): void
    {
        foreach ([
            'required_passed_final_tests',
            'required_passed_quizzes',
            'required_present_attendance',
            'retain_percentage',
        ] as $key) {
            AppSetting::storeValue('course_completion', $key, (int) $validated[$key], 'integer');
        }
    }

    public function apply(array $filters, User $actor): array
    {
        $settings = $this->settings();
        $retainPercentage = $settings['retain_percentage'];
        $summary = [
            'evaluated' => 0,
            'met_rules' => 0,
            'adjusted' => 0,
            'no_positive_points' => 0,
            'points_removed' => 0,
        ];

        $enrollments = $this->enrollmentsQuery($filters)
            ->with(['student', 'group.course', 'group.academicYear'])
            ->get();

        $ledger = app(PointLedgerService::class);
        $pointType = $this->adjustmentPointType();

        foreach ($enrollments as $enrollment) {
            $summary['evaluated']++;

            $criteria = $this->criteriaForEnrollment($enrollment, $settings);
            $ledger->voidSourceTransactions(
                self::ADJUSTMENT_SOURCE_TYPE,
                $enrollment->id,
                __('settings.course_completion.messages.adjustment_recalculated')
            );

            if ($criteria['passed']) {
                $summary['met_rules']++;
                $ledger->syncEnrollmentCaches($enrollment->fresh(['student']));

                continue;
            }

            $basePoints = (int) PointTransaction::query()
                ->where('enrollment_id', $enrollment->id)
                ->whereNull('voided_at')
                ->where('source_type', '!=', self::ADJUSTMENT_SOURCE_TYPE)
                ->sum('points');

            if ($basePoints <= 0) {
                $summary['no_positive_points']++;
                $ledger->syncEnrollmentCaches($enrollment->fresh(['student']));

                continue;
            }

            $targetPoints = (int) ($basePoints * ($retainPercentage / 100));
            $adjustmentPoints = $targetPoints - $basePoints;

            if ($adjustmentPoints === 0) {
                $ledger->syncEnrollmentCaches($enrollment->fresh(['student']));

                continue;
            }

            PointTransaction::query()->create([
                'student_id' => $enrollment->student_id,
                'enrollment_id' => $enrollment->id,
                'point_type_id' => $pointType->id,
                'policy_id' => null,
                'source_type' => self::ADJUSTMENT_SOURCE_TYPE,
                'source_id' => $enrollment->id,
                'points' => $adjustmentPoints,
                'entered_by' => $actor->id,
                'entered_at' => now(),
                'notes' => $this->adjustmentNote($enrollment, $criteria, $basePoints, $targetPoints, $retainPercentage),
            ]);

            $ledger->syncEnrollmentCaches($enrollment->fresh(['student']));

            $summary['adjusted']++;
            $summary['points_removed'] += abs($adjustmentPoints);
        }

        return $summary;
    }

    public function filters(array $rawFilters = []): array
    {
        return [
            'academic_year_id' => $this->normalizeNullableInteger($rawFilters['academic_year_id'] ?? null),
            'course_id' => $this->normalizeNullableInteger($rawFilters['course_id'] ?? null),
            'group_id' => $this->normalizeNullableInteger($rawFilters['group_id'] ?? null),
            'enrollment_status' => $this->normalizeStatus($rawFilters['enrollment_status'] ?? 'active'),
        ];
    }

    public function options(): array
    {
        return [
            'academicYears' => AcademicYear::query()->where('is_active', true)->orderByDesc('starts_on')->get(['id', 'name']),
            'courses' => Course::query()->orderByDesc('is_active')->orderBy('name')->get(['id', 'name', 'is_active']),
        ];
    }

    public function groups(array $filters): \Illuminate\Support\Collection
    {
        $filters = $this->filters($filters);

        return Group::query()
            ->with(['course', 'academicYear'])
            ->when($filters['academic_year_id'], fn (Builder $query) => $query->where('academic_year_id', $filters['academic_year_id']))
            ->when($filters['course_id'], fn (Builder $query) => $query->where('course_id', $filters['course_id']))
            ->orderBy('name')
            ->get();
    }

    protected function enrollmentsQuery(array $filters): Builder
    {
        $filters = $this->filters($filters);

        return Enrollment::query()
            ->whereHas('group', function (Builder $query) use ($filters) {
                $query
                    ->when($filters['academic_year_id'], fn (Builder $builder) => $builder->where('academic_year_id', $filters['academic_year_id']))
                    ->when($filters['course_id'], fn (Builder $builder) => $builder->where('course_id', $filters['course_id']))
                    ->when($filters['group_id'], fn (Builder $builder) => $builder->whereKey($filters['group_id']));
            })
            ->when($filters['enrollment_status'] !== 'all', fn (Builder $query) => $query->where('status', $filters['enrollment_status']));
    }

    protected function criteriaForEnrollment(Enrollment $enrollment, array $settings): array
    {
        $passedFinalTests = QuranFinalTest::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('status', 'passed')
            ->count();

        $passedQuizzes = AssessmentResult::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('status', 'passed')
            ->whereHas('assessment.type', fn (Builder $query) => $query->where('code', 'quiz'))
            ->count();

        $presentAttendance = StudentAttendanceRecord::query()
            ->where('enrollment_id', $enrollment->id)
            ->whereHas('status', fn (Builder $query) => $query->where('is_present', true))
            ->count();

        $unmet = [];

        if ($settings['required_passed_final_tests'] > 0 && $passedFinalTests < $settings['required_passed_final_tests']) {
            $unmet[] = __('settings.course_completion.criteria.final_tests_progress', [
                'actual' => $passedFinalTests,
                'required' => $settings['required_passed_final_tests'],
            ]);
        }

        if ($settings['required_passed_quizzes'] > 0 && $passedQuizzes < $settings['required_passed_quizzes']) {
            $unmet[] = __('settings.course_completion.criteria.quizzes_progress', [
                'actual' => $passedQuizzes,
                'required' => $settings['required_passed_quizzes'],
            ]);
        }

        if ($settings['required_present_attendance'] > 0 && $presentAttendance < $settings['required_present_attendance']) {
            $unmet[] = __('settings.course_completion.criteria.attendance_progress', [
                'actual' => $presentAttendance,
                'required' => $settings['required_present_attendance'],
            ]);
        }

        return [
            'passed' => $unmet === [],
            'passed_final_tests' => $passedFinalTests,
            'passed_quizzes' => $passedQuizzes,
            'present_attendance' => $presentAttendance,
            'unmet' => $unmet,
        ];
    }

    protected function adjustmentNote(Enrollment $enrollment, array $criteria, int $basePoints, int $targetPoints, int $retainPercentage): string
    {
        return __('settings.course_completion.messages.adjustment_note', [
            'student' => trim(($enrollment->student?->first_name ?? '').' '.($enrollment->student?->last_name ?? '')),
            'base' => $basePoints,
            'target' => $targetPoints,
            'percentage' => $retainPercentage,
            'unmet' => implode(' | ', $criteria['unmet']),
        ]);
    }

    protected function adjustmentPointType(): PointType
    {
        return PointType::query()->firstOrCreate(
            ['code' => self::ADJUSTMENT_POINT_TYPE_CODE],
            [
                'name' => 'Course Completion Adjustment',
                'category' => 'system',
                'default_points' => 0,
                'allow_manual_entry' => false,
                'allow_negative' => true,
                'is_active' => true,
            ],
        );
    }

    protected function normalizeNullableInteger(mixed $value): ?int
    {
        if (is_array($value)) {
            $value = collect($value)->first(fn (mixed $item) => $item !== null && $item !== '');
        }

        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    protected function normalizeStatus(mixed $value): string
    {
        $status = is_string($value) ? $value : 'active';

        return in_array($status, ['all', 'active', 'completed', 'inactive', 'cancelled'], true)
            ? $status
            : 'active';
    }
}
