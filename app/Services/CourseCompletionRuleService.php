<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\AppSetting;
use App\Models\AssessmentResult;
use App\Models\AssessmentType;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\QuranFinalTest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CourseCompletionRuleService
{
    public const ADJUSTMENT_POINT_TYPE_CODE = 'course-completion-adjustment';
    public const ADJUSTMENT_SOURCE_TYPE = 'course_completion_rule';

    public function settings(): array
    {
        $settings = AppSetting::groupValues('course_completion');
        $assessmentTypeRequirements = $this->assessmentTypeRequirements($settings);
        $assessmentGradeIds = $this->gradeIds($settings->get('assessment_grade_ids'));

        $resolved = [
            'required_passed_final_tests' => max(0, (int) ($settings->get('required_passed_final_tests') ?? 1)),
            'required_memorized_pages' => max(0, (int) ($settings->get('required_memorized_pages') ?? 0)),
            'final_rule_operator' => in_array($settings->get('final_rule_operator'), ['and', 'or'], true) ? $settings->get('final_rule_operator') : 'and',
            'required_passed_quizzes' => max(0, (int) ($settings->get('required_passed_quizzes') ?? 1)),
            'assessment_type_requirements' => $assessmentTypeRequirements,
            'final_test_grade_ids' => $this->gradeIds($settings->get('final_test_grade_ids')),
            'assessment_grade_ids' => $assessmentGradeIds,
            'assessment_rule_grade_ids' => $this->assessmentRuleGradeIds($settings, $assessmentTypeRequirements, $assessmentGradeIds),
            'retain_percentage' => min(100, max(0, (int) ($settings->get('retain_percentage') ?? 50))),
            'minimum_points' => max(0, (int) ($settings->get('minimum_points') ?? 0)),
        ];

        $resolved['final_rule_rows'] = $this->finalRuleRows($settings, $resolved);

        return $resolved;
    }

    public function saveSettings(array $validated): void
    {
        foreach ([
            'required_passed_final_tests',
            'required_memorized_pages',
            'required_passed_quizzes',
            'retain_percentage',
            'minimum_points',
        ] as $key) {
            AppSetting::storeValue('course_completion', $key, (int) $validated[$key], 'integer');
        }

        $requirements = collect($validated['assessment_type_requirements'] ?? [])
            ->mapWithKeys(fn (mixed $value, mixed $key): array => [(int) $key => max(0, (int) $value)])
            ->filter(fn (int $value): bool => $value > 0)
            ->all();

        if ($requirements === [] && (int) $validated['required_passed_quizzes'] > 0) {
            $quizTypeId = AssessmentType::query()->where('code', 'quiz')->value('id');

            if ($quizTypeId) {
                $requirements[(int) $quizTypeId] = (int) $validated['required_passed_quizzes'];
            }
        }

        AppSetting::storeValue('course_completion', 'assessment_type_requirements', $requirements, 'array');
        AppSetting::storeValue('course_completion', 'final_rule_operator', $validated['final_rule_operator']);
        AppSetting::storeValue('course_completion', 'final_test_grade_ids', $validated['final_test_grade_ids'] ?? [], 'array');
        AppSetting::storeValue('course_completion', 'assessment_grade_ids', $validated['assessment_grade_ids'] ?? [], 'array');
        AppSetting::storeValue('course_completion', 'assessment_rule_grade_ids', $validated['assessment_rule_grade_ids'] ?? [], 'array');
        AppSetting::storeValue('course_completion', 'final_rule_rows', $validated['final_rule_rows'] ?? [[
            'required_passed_final_tests' => (int) $validated['required_passed_final_tests'],
            'required_memorized_pages' => (int) $validated['required_memorized_pages'],
            'final_rule_operator' => $validated['final_rule_operator'],
            'grade_ids' => $validated['final_test_grade_ids'] ?? [],
        ]], 'array');
    }

    public function apply(array $filters, User $actor): array
    {
        $settings = $this->settings();
        $retainPercentage = $settings['retain_percentage'];
        $minimumPoints = $settings['minimum_points'];
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
                ->effectiveActive()
                ->where('source_type', '!=', self::ADJUSTMENT_SOURCE_TYPE)
                ->sum('points');

            if ($basePoints <= 0) {
                $summary['no_positive_points']++;
                $ledger->syncEnrollmentCaches($enrollment->fresh(['student']));

                continue;
            }

            $retainedPoints = (int) ($basePoints * ($retainPercentage / 100));
            $targetPoints = min($basePoints, max($retainedPoints, $minimumPoints));
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
            'courses' => Course::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'is_active']),
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

    public function criteriaForEnrollment(Enrollment $enrollment, ?array $settings = null): array
    {
        $settings ??= $this->settings();
        $passedFinalTests = QuranFinalTest::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('status', 'passed')
            ->count();
        $memorizedPages = max(0, (int) $enrollment->memorized_pages_cached);

        $passedQuizzes = AssessmentResult::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('status', 'passed')
            ->whereHas('assessment.type', fn (Builder $query) => $query->where('code', 'quiz'))
            ->count();
        $assessmentTypeRequirements = $settings['assessment_type_requirements'] ?? [];
        $assessmentTypes = AssessmentType::query()
            ->whereIn('id', array_keys($assessmentTypeRequirements))
            ->get(['id', 'name'])
            ->keyBy('id');
        $passedAssessmentsByType = [];

        foreach ($assessmentTypeRequirements as $assessmentTypeId => $requiredCount) {
            if ($requiredCount <= 0) {
                continue;
            }

            $passedAssessmentsByType[$assessmentTypeId] = AssessmentResult::query()
                ->where('enrollment_id', $enrollment->id)
                ->where('status', 'passed')
                ->whereHas('assessment', fn (Builder $query) => $query->where('assessment_type_id', $assessmentTypeId))
                ->count();
        }

        $unmet = [];

        $gradeLevelId = $enrollment->student?->grade_level_id;
        $finalRule = collect($settings['final_rule_rows'] ?? [])->first(function (array $rule) use ($gradeLevelId): bool {
            $gradeIds = $rule['grade_ids'] ?? [];

            return $gradeIds === [] || in_array($gradeLevelId, $gradeIds, true);
        });

        if ($finalRule) {
            $requiredFinalTests = (int) $finalRule['required_passed_final_tests'];
            $requiredPages = (int) $finalRule['required_memorized_pages'];
            $operator = $finalRule['final_rule_operator'];
            $finalTestsMet = $requiredFinalTests <= 0 || $passedFinalTests >= $requiredFinalTests;
            $pagesMet = $requiredPages <= 0 || $memorizedPages >= $requiredPages;
            $combinedMet = $operator === 'or' ? $finalTestsMet || $pagesMet : $finalTestsMet && $pagesMet;

            if (! $combinedMet) {
                $unmet[] = __('settings.course_completion.criteria.final_saber_pages_progress', [
                    'tests_actual' => $passedFinalTests,
                    'tests_required' => $requiredFinalTests,
                    'pages_actual' => $memorizedPages,
                    'pages_required' => $requiredPages,
                    'operator' => __('settings.course_completion.options.'.$operator),
                ]);
            }
        }

        foreach ($assessmentTypeRequirements as $assessmentTypeId => $requiredCount) {
            $assessmentGradeIds = $settings['assessment_rule_grade_ids'][$assessmentTypeId]
                ?? $settings['assessment_grade_ids']
                ?? [];

            if ($assessmentGradeIds !== [] && ! in_array($gradeLevelId, $assessmentGradeIds, true)) {
                continue;
            }
            if ($requiredCount <= 0) {
                continue;
            }

            $actualCount = $passedAssessmentsByType[$assessmentTypeId] ?? 0;

            if ($actualCount >= $requiredCount) {
                continue;
            }

            $assessmentTypeName = $assessmentTypes->get($assessmentTypeId)?->name ?: __('settings.course_completion.labels.unknown_assessment_type');

            $unmet[] = __('settings.course_completion.criteria.assessment_type_progress', [
                'type' => $assessmentTypeName,
                'actual' => $actualCount,
                'required' => $requiredCount,
            ]);
        }

        return [
            'passed' => $unmet === [],
            'passed_final_tests' => $passedFinalTests,
            'memorized_pages' => $memorizedPages,
            'passed_quizzes' => $passedQuizzes,
            'passed_assessments_by_type' => $passedAssessmentsByType,
            'unmet' => $unmet,
        ];
    }

    protected function assessmentTypeRequirements(Collection $settings): array
    {
        $storedRequirements = $settings->get('assessment_type_requirements');

        $requirements = is_array($storedRequirements)
            ? collect($storedRequirements)
                ->mapWithKeys(fn (mixed $value, mixed $key): array => [(int) $key => max(0, (int) $value)])
                ->filter(fn (int $value): bool => $value > 0)
                ->all()
            : [];

        if ($requirements !== []) {
            return $requirements;
        }

        $requiredQuizzes = max(0, (int) ($settings->get('required_passed_quizzes') ?? 1));
        $quizTypeId = AssessmentType::query()->where('code', 'quiz')->value('id');

        if (! $quizTypeId || $requiredQuizzes <= 0) {
            return [];
        }

        return [(int) $quizTypeId => $requiredQuizzes];
    }

    protected function gradeIds(mixed $stored): array
    {
        return is_array($stored)
            ? collect($stored)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all()
            : \App\Models\GradeLevel::query()->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    protected function assessmentRuleGradeIds(Collection $settings, array $requirements, array $legacyGradeIds): array
    {
        $stored = $settings->get('assessment_rule_grade_ids');

        if (is_array($stored) && $stored !== []) {
            return collect($stored)
                ->filter(fn (mixed $gradeIds, mixed $assessmentTypeId): bool => is_numeric($assessmentTypeId) && is_array($gradeIds))
                ->mapWithKeys(fn (array $gradeIds, mixed $assessmentTypeId): array => [
                    (int) $assessmentTypeId => collect($gradeIds)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all(),
                ])
                ->all();
        }

        return collect(array_keys($requirements))
            ->mapWithKeys(fn (int $assessmentTypeId): array => [$assessmentTypeId => $legacyGradeIds])
            ->all();
    }

    protected function finalRuleRows(Collection $settings, array $legacy): array
    {
        $storedRows = $settings->get('final_rule_rows');

        if (is_array($storedRows) && $storedRows !== []) {
            $rows = collect($storedRows)
                ->filter(fn (mixed $row): bool => is_array($row))
                ->map(function (array $row): array {
                    $operator = $row['final_rule_operator'] ?? 'and';

                    return [
                        'required_passed_final_tests' => max(0, (int) ($row['required_passed_final_tests'] ?? 0)),
                        'required_memorized_pages' => max(0, (int) ($row['required_memorized_pages'] ?? 0)),
                        'final_rule_operator' => in_array($operator, ['and', 'or'], true) ? $operator : 'and',
                        'grade_ids' => is_array($row['grade_ids'] ?? null)
                            ? collect($row['grade_ids'])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all()
                            : [],
                    ];
                })
                ->values()
                ->all();

            if ($rows !== []) {
                return $rows;
            }
        }

        return [[
            'required_passed_final_tests' => $legacy['required_passed_final_tests'],
            'required_memorized_pages' => $legacy['required_memorized_pages'],
            'final_rule_operator' => $legacy['final_rule_operator'],
            'grade_ids' => $legacy['final_test_grade_ids'],
        ]];
    }

    protected function adjustmentNote(Enrollment $enrollment, array $criteria, int $basePoints, int $targetPoints, int $retainPercentage): string
    {
        return __('settings.course_completion.messages.adjustment_note', [
            'student' => $enrollment->student?->full_name ?? '',
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
                'category' => 'Automatic',
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
