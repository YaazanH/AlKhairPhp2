<?php

namespace App\Services;

use App\Exceptions\ReportQueryTimeoutException;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\Course;
use App\Models\FinanceCashBox;
use App\Models\FinanceCategory;
use App\Models\FinanceCurrency;
use App\Models\FinanceTransaction;
use App\Models\Group;
use App\Models\MemorizationSession;
use App\Models\QuranFinalTest;
use App\Models\QuranPartialTest;
use App\Models\QuranTest;
use App\Models\Student;
use App\Models\StudentAttendanceRecord;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Landlord\CurrentModuleAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ReportDesignerQueryService
{
    public const PREVIEW_LIMIT = 25;

    public const EXPORT_LIMIT = 5000;

    public const GROUP_PREVIEW_LIMIT = 25;

    protected array $activeCalculations = [];

    protected array $calculationValues = [];

    protected ?string $activeGroupBy = null;

    protected ?array $groupingResult = null;

    protected array $activePresentation = [];

    protected array $curriculumSummaries = [];

    protected int $rowLimit = self::PREVIEW_LIMIT;

    protected int $groupLimit = self::GROUP_PREVIEW_LIMIT;

    public function __construct(
        protected AccessScopeService $accessScopes,
        protected ReportDesignerCatalog $catalog,
        protected FinanceService $finance,
        protected CurrentModuleAccess $modules,
    ) {}

    public function preview(array $definition, ?User $user): array
    {
        $this->rowLimit = self::PREVIEW_LIMIT;
        $this->groupLimit = self::GROUP_PREVIEW_LIMIT;

        return $this->withinQueryTimeout(fn (): array => $this->run($definition, $user));
    }

    public function export(array $definition, ?User $user): array
    {
        $this->rowLimit = self::EXPORT_LIMIT;
        $this->groupLimit = self::EXPORT_LIMIT;

        return $this->withinQueryTimeout(fn (): array => $this->run($definition, $user));
    }

    protected function withinQueryTimeout(callable $callback): mixed
    {
        $milliseconds = (int) config('performance.report_query_timeout_ms', 5000);
        $connection = DB::connection();

        if ($milliseconds <= 0 || $connection->getDriverName() !== 'mysql') {
            return $callback();
        }

        $previous = (int) data_get(
            $connection->selectOne('SELECT @@SESSION.MAX_EXECUTION_TIME AS value'),
            'value',
            0,
        );

        $connection->unprepared('SET SESSION MAX_EXECUTION_TIME = '.max(1, $milliseconds));

        try {
            return $callback();
        } catch (QueryException $exception) {
            if ($this->isQueryTimeout($exception)) {
                throw new ReportQueryTimeoutException($exception);
            }

            throw $exception;
        } finally {
            $connection->unprepared('SET SESSION MAX_EXECUTION_TIME = '.$previous);
        }
    }

    protected function isQueryTimeout(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return (int) ($exception->errorInfo[1] ?? 0) === 3024
            || str_contains($message, 'maximum statement execution time exceeded')
            || str_contains($message, 'max_execution_time');
    }

    protected function run(array $definition, ?User $user): array
    {
        $source = (string) ($definition['data_source'] ?? '');
        abort_unless(array_key_exists($source, $this->catalog->sources($user)), 403);
        $fields = $this->catalog->validateFields($source, (array) ($definition['selected_fields'] ?? []));
        $this->activeCalculations = $this->catalog->validateCalculations($source, (array) ($definition['calculations'] ?? []));
        $this->activeGroupBy = $this->catalog->validateGrouping($source, $definition['group_by'] ?? null);
        $this->activePresentation = $this->catalog->validatePresentation(
            (array) ($definition['presentation'] ?? []),
            $this->activeGroupBy,
            true,
            $source,
            $this->activeCalculations,
        );
        $this->calculationValues = [];
        $this->groupingResult = null;
        $this->curriculumSummaries = [];
        [$sortField, $sortDirection] = $this->catalog->validateSort(
            $source,
            $definition['sort_field'] ?? null,
            (string) ($definition['sort_direction'] ?? 'asc'),
        );

        return match ($source) {
            ReportDesignerCatalog::STUDENTS => $this->studentPreview(
                $fields,
                (array) ($definition['filters'] ?? []),
                $sortField,
                $sortDirection,
                $user,
            ),
            ReportDesignerCatalog::COURSES => $this->coursePreview(
                $fields,
                (array) ($definition['filters'] ?? []),
                $sortField,
                $sortDirection,
                $user,
            ),
            ReportDesignerCatalog::GROUPS => $this->groupPreview(
                $fields,
                (array) ($definition['filters'] ?? []),
                $sortField,
                $sortDirection,
                $user,
            ),
            ReportDesignerCatalog::STUDENT_ATTENDANCE => $this->studentAttendancePreview(
                $fields,
                (array) ($definition['filters'] ?? []),
                $sortField,
                $sortDirection,
                $user,
            ),
            ReportDesignerCatalog::MEMORIZATION_SESSIONS => $this->memorizationPreview(
                $fields,
                (array) ($definition['filters'] ?? []),
                $sortField,
                $sortDirection,
                $user,
            ),
            ReportDesignerCatalog::QURAN_TESTS => $this->quranTestPreview(
                $fields,
                (array) ($definition['filters'] ?? []),
                $sortField,
                $sortDirection,
                $user,
            ),
            ReportDesignerCatalog::QURAN_PARTIAL_TESTS => $this->quranPartialTestPreview(
                $fields,
                (array) ($definition['filters'] ?? []),
                $sortField,
                $sortDirection,
                $user,
            ),
            ReportDesignerCatalog::QURAN_FINAL_TESTS => $this->quranFinalTestPreview(
                $fields,
                (array) ($definition['filters'] ?? []),
                $sortField,
                $sortDirection,
                $user,
            ),
            ReportDesignerCatalog::ASSESSMENTS => $this->assessmentPreview(
                $fields,
                (array) ($definition['filters'] ?? []),
                $sortField,
                $sortDirection,
                $user,
            ),
            ReportDesignerCatalog::ASSESSMENT_RESULTS => $this->assessmentResultPreview(
                $fields,
                (array) ($definition['filters'] ?? []),
                $sortField,
                $sortDirection,
                $user,
            ),
            ReportDesignerCatalog::TEACHERS => $this->teacherPreview(
                $fields,
                (array) ($definition['filters'] ?? []),
                $sortField,
                $sortDirection,
                $user,
            ),
            ReportDesignerCatalog::FINANCE_TRANSACTIONS => $this->financeTransactionPreview(
                $fields,
                (array) ($definition['filters'] ?? []),
                $sortField,
                $sortDirection,
                $user,
            ),
        };
    }

    protected function studentPreview(array $fields, array $filters, ?string $sortField, string $sortDirection, ?User $user): array
    {
        $query = $this->accessScopes->scopeStudents(
            Student::query()->with([
                'gradeLevel:id,name',
                'enrollments' => fn ($query) => $query
                    ->where('status', 'active')
                    ->with('group:id,name')
                    ->orderByDesc('enrolled_at')
                    ->orderByDesc('id'),
            ]),
            $user,
        );

        $status = (string) ($filters['status'] ?? 'all');
        if (in_array($status, ['active', 'inactive'], true)) {
            $query->where('status', $status);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('student_number', 'like', '%'.$search.'%')
                    ->orWhere('first_name', 'like', '%'.$search.'%')
                    ->orWhere('last_name', 'like', '%'.$search.'%');
            });
        }

        $joinedFrom = (string) ($filters['date_from'] ?? $filters['joined_from'] ?? '');
        $joinedTo = (string) ($filters['date_to'] ?? $filters['joined_to'] ?? '');
        $query->when($joinedFrom !== '', fn (Builder $builder) => $builder->whereDate('joined_at', '>=', $joinedFrom));
        $query->when($joinedTo !== '', fn (Builder $builder) => $builder->whereDate('joined_at', '<=', $joinedTo));

        $total = (clone $query)->count();
        $this->prepareOperationalSummaries(ReportDesignerCatalog::STUDENTS, $query, $this->studentValue(...));
        $this->applyStudentSort($query, $sortField, $sortDirection);

        $rows = $query->limit($this->rowLimit)->get()->map(function (Student $student) use ($fields): array {
            return collect($fields)->mapWithKeys(fn (string $field) => [
                $field => $this->studentValue($student, $field),
            ])->all();
        })->all();

        return $this->result(ReportDesignerCatalog::STUDENTS, $fields, $rows, $total);
    }

    protected function coursePreview(array $fields, array $filters, ?string $sortField, string $sortDirection, ?User $user): array
    {
        $groupIds = $this->accessScopes->isUnrestricted($user)
            ? null
            : $this->accessScopes->accessibleGroupIds($user);
        $scopeGroups = static function (Builder $builder) use ($groupIds): void {
            $builder->when($groupIds !== null, fn (Builder $query) => $query->whereIn('groups.id', $groupIds));
        };

        $query = Course::query()
            ->with('academicYear:id,name')
            ->withCount([
                'groups' => $scopeGroups,
                'groups as active_groups_count' => function (Builder $builder) use ($scopeGroups): void {
                    $scopeGroups($builder);
                    $builder->where('is_active', true);
                },
                'groups as active_enrollments_count' => function (Builder $builder) use ($scopeGroups): void {
                    $scopeGroups($builder);
                    $builder
                        ->join('enrollments', 'groups.id', '=', 'enrollments.group_id')
                        ->where('enrollments.status', 'active')
                        ->whereNull('enrollments.deleted_at');
                },
            ]);

        if ($groupIds !== null) {
            $query->whereHas('groups', fn (Builder $builder) => $builder->whereIn('groups.id', $groupIds));
        }

        $this->applyCommonFilters($query, $filters, ['name'], 'starts_on');
        $total = (clone $query)->count();
        $this->prepareOperationalSummaries(ReportDesignerCatalog::COURSES, $query, $this->courseValue(...));
        $this->applyCourseSort($query, $sortField, $sortDirection);

        $rows = $query->limit($this->rowLimit)->get()->map(function (Course $course) use ($fields): array {
            return collect($fields)->mapWithKeys(fn (string $field) => [
                $field => $this->courseValue($course, $field),
            ])->all();
        })->all();

        return $this->result(ReportDesignerCatalog::COURSES, $fields, $rows, $total);
    }

    protected function groupPreview(array $fields, array $filters, ?string $sortField, string $sortDirection, ?User $user): array
    {
        $relations = ['course:id,name', 'academicYear:id,name', 'teacher:id,first_name,last_name', 'assistantTeacher:id,first_name,last_name', 'gradeLevel:id,name'];
        $usesCurriculum = collect($fields)
            ->merge(collect($this->activeCalculations)->pluck('field'))
            ->contains(fn (?string $field): bool => in_array($field, [
                'curriculum_name',
                'curriculum_completed_lessons',
                'curriculum_total_lessons',
                'curriculum_progress_percentage',
            ], true));
        if ($usesCurriculum) {
            $relations = array_merge($relations, [
                'curriculum.subjects.definition',
                'curriculum.subjects.lessons.topics',
                'curriculumProgresses',
                'curriculumTopicProgresses',
                'customCurriculumLessons',
            ]);
        }

        $query = $this->accessScopes->scopeGroups(
            Group::query()
                ->with($relations)
                ->withCount(['enrollments as active_enrollments_count' => fn (Builder $builder) => $builder->where('status', 'active')]),
            $user,
        );

        $this->applyCommonFilters($query, $filters, ['name'], 'starts_on', function (Builder $builder, string $search): void {
            $builder
                ->orWhereHas('course', fn (Builder $relation) => $relation->where('name', 'like', '%'.$search.'%'))
                ->orWhereHas('teacher', fn (Builder $relation) => $relation
                    ->where('first_name', 'like', '%'.$search.'%')
                    ->orWhere('last_name', 'like', '%'.$search.'%'));
        });
        $total = (clone $query)->count();
        $this->prepareOperationalSummaries(ReportDesignerCatalog::GROUPS, $query, $this->groupValue(...));
        $this->applyGroupSort($query, $sortField, $sortDirection);

        $rows = $query->limit($this->rowLimit)->get()->map(function (Group $group) use ($fields): array {
            return collect($fields)->mapWithKeys(fn (string $field) => [
                $field => $this->groupValue($group, $field),
            ])->all();
        })->all();

        return $this->result(ReportDesignerCatalog::GROUPS, $fields, $rows, $total);
    }

    protected function studentAttendancePreview(array $fields, array $filters, ?string $sortField, string $sortDirection, ?User $user): array
    {
        $query = $this->accessScopes->scopeStudentAttendanceRecords(
            StudentAttendanceRecord::query()->with([
                'status:id,name,is_present',
                'student:id,student_number,first_name,last_name',
                'studentAttendanceDay.course',
                'attendanceDay.group.course',
                'enrollment.student:id,student_number,first_name,last_name',
                'enrollment.group.course',
            ]),
            $user,
        );

        if (! $this->modules->enabled('classes')) {
            $query->whereNotNull('student_attendance_day_id');
        }

        $status = (string) ($filters['status'] ?? 'all');
        if (in_array($status, ['present', 'not_present'], true)) {
            $query->whereHas('status', fn (Builder $builder) => $builder->where('is_present', $status === 'present'));
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->whereHas('student', fn (Builder $student) => $this->applyStudentSearch($student, $search))
                    ->orWhereHas('enrollment.student', fn (Builder $student) => $this->applyStudentSearch($student, $search));
            });
        }

        $dateFrom = (string) ($filters['date_from'] ?? '');
        $dateTo = (string) ($filters['date_to'] ?? '');
        if ($dateFrom !== '' || $dateTo !== '') {
            $query->where(function (Builder $builder) use ($dateFrom, $dateTo): void {
                $builder
                    ->whereHas('studentAttendanceDay', fn (Builder $day) => $this->applyDateBounds($day, 'attendance_date', $dateFrom, $dateTo))
                    ->orWhereHas('attendanceDay', fn (Builder $day) => $this->applyDateBounds($day, 'attendance_date', $dateFrom, $dateTo));
            });
        }

        $total = (clone $query)->count();
        $this->prepareOperationalSummaries(ReportDesignerCatalog::STUDENT_ATTENDANCE, $query, $this->studentAttendanceValue(...));
        $query->orderByDesc('id');

        $rows = $query->limit($this->rowLimit)->get()->map(function (StudentAttendanceRecord $record) use ($fields): array {
            return collect($fields)->mapWithKeys(fn (string $field) => [
                $field => $this->studentAttendanceValue($record, $field),
            ])->all();
        })->all();

        return $this->result(ReportDesignerCatalog::STUDENT_ATTENDANCE, $fields, $rows, $total);
    }

    protected function memorizationPreview(array $fields, array $filters, ?string $sortField, string $sortDirection, ?User $user): array
    {
        $query = $this->accessScopes->scopeMemorizationSessions(
            MemorizationSession::query()->with([
                'student:id,student_number,first_name,last_name',
                'teacher:id,first_name,last_name',
                'enrollment.group.course',
            ]),
            $user,
        );

        $entryType = (string) ($filters['status'] ?? 'all');
        if (in_array($entryType, ['new', 'review', 'correction'], true)) {
            $query->where('entry_type', $entryType);
        }

        $this->applyRelatedStudentSearch($query, $filters);
        $this->applyDateBounds(
            $query,
            'recorded_on',
            (string) ($filters['date_from'] ?? ''),
            (string) ($filters['date_to'] ?? ''),
        );

        $total = (clone $query)->count();
        $this->prepareOperationalSummaries(ReportDesignerCatalog::MEMORIZATION_SESSIONS, $query, $this->memorizationValue(...));
        $this->applyMemorizationSort($query, $sortField, $sortDirection);

        $rows = $query->limit($this->rowLimit)->get()->map(function (MemorizationSession $session) use ($fields): array {
            return collect($fields)->mapWithKeys(fn (string $field) => [
                $field => $this->memorizationValue($session, $field),
            ])->all();
        })->all();

        return $this->result(ReportDesignerCatalog::MEMORIZATION_SESSIONS, $fields, $rows, $total);
    }

    protected function quranTestPreview(array $fields, array $filters, ?string $sortField, string $sortDirection, ?User $user): array
    {
        $query = $this->accessScopes->scopeQuranTests(
            QuranTest::query()->with([
                'student:id,student_number,first_name,last_name',
                'teacher:id,first_name,last_name',
                'type:id,name,code',
                'juz:id,juz_number',
                'enrollment.group.course',
            ]),
            $user,
        );

        $status = (string) ($filters['status'] ?? 'all');
        if (in_array($status, ['passed', 'failed', 'cancelled'], true)) {
            $query->where('status', $status);
        }

        $this->applyRelatedStudentSearch($query, $filters);
        $this->applyDateBounds(
            $query,
            'tested_on',
            (string) ($filters['date_from'] ?? ''),
            (string) ($filters['date_to'] ?? ''),
        );

        $total = (clone $query)->count();
        $this->prepareOperationalSummaries(ReportDesignerCatalog::QURAN_TESTS, $query, $this->quranTestValue(...));
        $this->applyQuranTestSort($query, $sortField, $sortDirection);

        $rows = $query->limit($this->rowLimit)->get()->map(function (QuranTest $test) use ($fields): array {
            return collect($fields)->mapWithKeys(fn (string $field) => [
                $field => $this->quranTestValue($test, $field),
            ])->all();
        })->all();

        return $this->result(ReportDesignerCatalog::QURAN_TESTS, $fields, $rows, $total);
    }

    protected function quranPartialTestPreview(array $fields, array $filters, ?string $sortField, string $sortDirection, ?User $user): array
    {
        $query = $this->accessScopes->scopeQuranPartialTests(
            QuranPartialTest::query()->with([
                'student:id,student_number,first_name,last_name',
                'juz:id,juz_number',
                'enrollment.group.course',
                'parts.attempts.teacher:id,first_name,last_name',
            ]),
            $user,
        );

        $this->applyQuranWorkflowFilters($query, $filters, 'parts.attempts');
        $total = (clone $query)->count();
        $this->prepareOperationalSummaries(ReportDesignerCatalog::QURAN_PARTIAL_TESTS, $query, $this->quranPartialTestValue(...));
        $this->applyQuranWorkflowSort($query, $sortField, $sortDirection);

        $rows = $query->limit($this->rowLimit)->get()->map(function (QuranPartialTest $test) use ($fields): array {
            return collect($fields)->mapWithKeys(fn (string $field) => [
                $field => $this->quranPartialTestValue($test, $field),
            ])->all();
        })->all();

        return $this->result(ReportDesignerCatalog::QURAN_PARTIAL_TESTS, $fields, $rows, $total);
    }

    protected function quranFinalTestPreview(array $fields, array $filters, ?string $sortField, string $sortDirection, ?User $user): array
    {
        $query = $this->accessScopes->scopeQuranFinalTests(
            QuranFinalTest::query()->with([
                'student:id,student_number,first_name,last_name',
                'juz:id,juz_number',
                'enrollment.group.course',
                'attempts.teacher:id,first_name,last_name',
            ]),
            $user,
        );

        $this->applyQuranWorkflowFilters($query, $filters, 'attempts');
        $total = (clone $query)->count();
        $this->prepareOperationalSummaries(ReportDesignerCatalog::QURAN_FINAL_TESTS, $query, $this->quranFinalTestValue(...));
        $this->applyQuranWorkflowSort($query, $sortField, $sortDirection);

        $rows = $query->limit($this->rowLimit)->get()->map(function (QuranFinalTest $test) use ($fields): array {
            return collect($fields)->mapWithKeys(fn (string $field) => [
                $field => $this->quranFinalTestValue($test, $field),
            ])->all();
        })->all();

        return $this->result(ReportDesignerCatalog::QURAN_FINAL_TESTS, $fields, $rows, $total);
    }

    protected function assessmentPreview(array $fields, array $filters, ?string $sortField, string $sortDirection, ?User $user): array
    {
        $groupIds = $this->accessScopes->isUnrestricted($user)
            ? null
            : $this->accessScopes->accessibleGroupIds($user);
        $enrollmentIds = $this->accessScopes->isUnrestricted($user)
            ? null
            : $this->accessScopes->accessibleEnrollmentIds($user);
        $scopeResults = static function (Builder $builder) use ($enrollmentIds): void {
            $builder->when($enrollmentIds !== null, fn (Builder $query) => $query->whereIn('assessment_results.enrollment_id', $enrollmentIds));
        };
        $scopeGroups = static function ($builder) use ($groupIds): void {
            $builder->when($groupIds !== null, fn ($query) => $query->whereIn('groups.id', $groupIds));
        };

        $query = $this->accessScopes->scopeAssessments(
            Assessment::query()
                ->with([
                    'type:id,name',
                    'group' => $scopeGroups,
                    'groups' => $scopeGroups,
                ])
                ->withCount([
                    'results' => $scopeResults,
                    'results as passed_results_count' => function (Builder $builder) use ($scopeResults): void {
                        $scopeResults($builder);
                        $builder->where('status', 'passed');
                    },
                    'results as failed_results_count' => function (Builder $builder) use ($scopeResults): void {
                        $scopeResults($builder);
                        $builder->where('status', 'failed');
                    },
                ])
                ->withAvg(['results as average_score' => $scopeResults], 'score'),
            $user,
        );

        $this->applyCommonFilters($query, $filters, ['title', 'description'], 'due_at', function (Builder $builder, string $search): void {
            $builder
                ->orWhereHas('type', fn (Builder $relation) => $relation->where('name', 'like', '%'.$search.'%'))
                ->orWhereHas('groups', fn (Builder $relation) => $relation->where('name', 'like', '%'.$search.'%'));
        });
        $total = (clone $query)->count();
        $this->prepareOperationalSummaries(ReportDesignerCatalog::ASSESSMENTS, $query, $this->assessmentValue(...));
        $this->applyAssessmentSort($query, $sortField, $sortDirection);

        $rows = $query->limit($this->rowLimit)->get()->map(function (Assessment $assessment) use ($fields): array {
            return collect($fields)->mapWithKeys(fn (string $field) => [
                $field => $this->assessmentValue($assessment, $field),
            ])->all();
        })->all();

        return $this->result(ReportDesignerCatalog::ASSESSMENTS, $fields, $rows, $total);
    }

    protected function assessmentResultPreview(array $fields, array $filters, ?string $sortField, string $sortDirection, ?User $user): array
    {
        $query = $this->accessScopes->scopeAssessmentResults(
            AssessmentResult::query()->with([
                'assessment.type:id,name',
                'student:id,student_number,first_name,last_name',
                'teacher:id,first_name,last_name',
                'enrollment.group.course',
            ]),
            $user,
        );

        $status = (string) ($filters['status'] ?? 'all');
        if (in_array($status, ['passed', 'failed', 'absent', 'pending'], true)) {
            $query->where('status', $status);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->whereHas('student', fn (Builder $student) => $this->applyStudentSearch($student, $search))
                    ->orWhereHas('assessment', fn (Builder $assessment) => $assessment->where('title', 'like', '%'.$search.'%'));
            });
        }

        $dateFrom = (string) ($filters['date_from'] ?? '');
        $dateTo = (string) ($filters['date_to'] ?? '');
        if ($dateFrom !== '' || $dateTo !== '') {
            $query->whereHas('assessment', fn (Builder $assessment) => $this->applyDateBounds($assessment, 'due_at', $dateFrom, $dateTo));
        }

        $total = (clone $query)->count();
        $this->prepareOperationalSummaries(ReportDesignerCatalog::ASSESSMENT_RESULTS, $query, $this->assessmentResultValue(...));
        $this->applyAssessmentResultSort($query, $sortField, $sortDirection);

        $rows = $query->limit($this->rowLimit)->get()->map(function (AssessmentResult $result) use ($fields): array {
            return collect($fields)->mapWithKeys(fn (string $field) => [
                $field => $this->assessmentResultValue($result, $field),
            ])->all();
        })->all();

        return $this->result(ReportDesignerCatalog::ASSESSMENT_RESULTS, $fields, $rows, $total);
    }

    protected function teacherPreview(array $fields, array $filters, ?string $sortField, string $sortDirection, ?User $user): array
    {
        $relations = ['jobTitle:id,name'];

        if ($this->modules->enabled('classes')) {
            $withWorkload = static fn ($query) => $query
                ->with('course:id,name')
                ->withCount([
                    'enrollments as active_enrollments_count' => fn (Builder $enrollments) => $enrollments->where('status', 'active'),
                ]);
            $relations['assignedGroups'] = $withWorkload;
            $relations['assistedGroups'] = $withWorkload;
        }

        $query = $this->accessScopes->scopeTeachers(
            Teacher::query()->with($relations),
            $user,
        );

        $status = (string) ($filters['status'] ?? 'all');
        if (in_array($status, ['active', 'inactive', 'pending', 'blocked', 'declined'], true)) {
            $query->where('status', $status);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('first_name', 'like', '%'.$search.'%')
                    ->orWhere('last_name', 'like', '%'.$search.'%')
                    ->orWhere('job_title', 'like', '%'.$search.'%')
                    ->orWhereHas('jobTitle', fn (Builder $jobTitle) => $jobTitle->where('name', 'like', '%'.$search.'%'));
            });
        }

        $dateFrom = (string) ($filters['date_from'] ?? '');
        $dateTo = (string) ($filters['date_to'] ?? '');
        $this->applyDateBounds($query, 'hired_at', $dateFrom, $dateTo);

        $total = (clone $query)->count();
        $this->prepareOperationalSummaries(ReportDesignerCatalog::TEACHERS, $query, $this->teacherValue(...));
        $this->applyTeacherSort($query, $sortField, $sortDirection);

        $rows = $query->limit($this->rowLimit)->get()->map(function (Teacher $teacher) use ($fields): array {
            return collect($fields)->mapWithKeys(fn (string $field) => [
                $field => $this->teacherValue($teacher, $field),
            ])->all();
        })->all();

        return $this->result(ReportDesignerCatalog::TEACHERS, $fields, $rows, $total);
    }

    protected function financeTransactionPreview(array $fields, array $filters, ?string $sortField, string $sortDirection, ?User $user): array
    {
        abort_unless($user?->can('finance.reports.view'), 403);

        $cashBoxIds = $this->finance->accessibleCashBoxes($user, activeOnly: false)
            ->select('finance_cash_boxes.id');
        $query = FinanceTransaction::query()
            ->with([
                'cashBox:id,name',
                'category:id,name',
                'currency:id,code,name',
                'enteredBy:id,name,username',
                'financeRequest.category:id,name',
                'financeRequest.pullRequestKind:id,name',
            ])
            ->whereIn('cash_box_id', $cashBoxIds);

        $type = (string) ($filters['status'] ?? 'all');
        if (in_array($type, ['income', 'expense', 'return', 'exchange', 'transfer'], true)) {
            $query->where('type', $type);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('transaction_no', 'like', '%'.$search.'%')
                    ->orWhere('special_transaction_no', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%')
                    ->orWhereHas('category', fn (Builder $category) => $category->where('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('cashBox', fn (Builder $cashBox) => $cashBox->where('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('currency', fn (Builder $currency) => $currency
                        ->where('code', 'like', '%'.$search.'%')
                        ->orWhere('name', 'like', '%'.$search.'%'));
            });
        }

        $this->applyDateBounds(
            $query,
            'transaction_date',
            (string) ($filters['date_from'] ?? ''),
            (string) ($filters['date_to'] ?? ''),
        );

        $total = (clone $query)->count();
        $this->calculationValues = $this->financeCalculationValues($query);
        $this->groupingResult = $this->financeGroupingResult($query);
        $this->applyFinanceTransactionSort($query, $sortField, $sortDirection);

        $rows = $query->limit($this->rowLimit)->get()->map(function (FinanceTransaction $transaction) use ($fields): array {
            return collect($fields)->mapWithKeys(fn (string $field) => [
                $field => $this->financeTransactionValue($transaction, $field),
            ])->all();
        })->all();

        return $this->result(ReportDesignerCatalog::FINANCE_TRANSACTIONS, $fields, $rows, $total);
    }

    protected function applyQuranWorkflowFilters(Builder $query, array $filters, string $attemptRelation): void
    {
        $status = (string) ($filters['status'] ?? 'all');
        if (in_array($status, ['passed', 'in_progress'], true)) {
            $query->where('status', $status);
        }

        $this->applyRelatedStudentSearch($query, $filters);

        $dateFrom = (string) ($filters['date_from'] ?? '');
        $dateTo = (string) ($filters['date_to'] ?? '');
        if ($dateFrom !== '' || $dateTo !== '') {
            $query->where(function (Builder $builder) use ($attemptRelation, $dateFrom, $dateTo): void {
                $builder
                    ->where(fn (Builder $passed) => $this->applyDateBounds($passed, 'passed_on', $dateFrom, $dateTo))
                    ->orWhereHas($attemptRelation, fn (Builder $attempt) => $this->applyDateBounds($attempt, 'tested_on', $dateFrom, $dateTo));
            });
        }
    }

    protected function applyRelatedStudentSearch(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->whereHas('student', fn (Builder $student) => $this->applyStudentSearch($student, $search));
        }
    }

    protected function applyStudentSearch(Builder $query, string $search): void
    {
        $query->where(function (Builder $builder) use ($search): void {
            $builder
                ->where('student_number', 'like', '%'.$search.'%')
                ->orWhere('first_name', 'like', '%'.$search.'%')
                ->orWhere('last_name', 'like', '%'.$search.'%');
        });
    }

    protected function applyDateBounds(Builder $query, string $column, string $dateFrom, string $dateTo): void
    {
        $query->when($dateFrom !== '', fn (Builder $builder) => $builder->whereDate($column, '>=', $dateFrom));
        $query->when($dateTo !== '', fn (Builder $builder) => $builder->whereDate($column, '<=', $dateTo));
    }

    protected function applyCommonFilters(Builder $query, array $filters, array $searchColumns, string $dateColumn, ?callable $extendSearch = null): void
    {
        $status = (string) ($filters['status'] ?? 'all');
        if (in_array($status, ['active', 'inactive'], true)) {
            $query->where('is_active', $status === 'active');
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($extendSearch, $search, $searchColumns): void {
                foreach ($searchColumns as $index => $column) {
                    $builder->{$index === 0 ? 'where' : 'orWhere'}($column, 'like', '%'.$search.'%');
                }

                $extendSearch?->__invoke($builder, $search);
            });
        }

        $dateFrom = (string) ($filters['date_from'] ?? '');
        $dateTo = (string) ($filters['date_to'] ?? '');
        $query->when($dateFrom !== '', fn (Builder $builder) => $builder->whereDate($dateColumn, '>=', $dateFrom));
        $query->when($dateTo !== '', fn (Builder $builder) => $builder->whereDate($dateColumn, '<=', $dateTo));
    }

    protected function applyCourseSort(Builder $query, ?string $field, string $direction): void
    {
        match ($field) {
            'course_name' => $query->orderBy('name', $direction),
            'status' => $query->orderBy('is_active', $direction),
            'starts_on', 'ends_on', 'groups_count', 'active_groups_count', 'active_enrollments_count' => $query->orderBy($field, $direction),
            default => $query->orderByDesc('id'),
        };
    }

    protected function applyGroupSort(Builder $query, ?string $field, string $direction): void
    {
        match ($field) {
            'group_name' => $query->orderBy('name', $direction),
            'status' => $query->orderBy('is_active', $direction),
            'starts_on', 'ends_on', 'capacity', 'active_enrollments_count' => $query->orderBy($field, $direction),
            default => $query->orderByDesc('id'),
        };
    }

    protected function applyMemorizationSort(Builder $query, ?string $field, string $direction): void
    {
        match ($field) {
            'recorded_on', 'entry_type', 'from_page', 'to_page', 'pages_count' => $query->orderBy($field, $direction),
            default => $query->orderByDesc('recorded_on')->orderByDesc('id'),
        };
    }

    protected function applyQuranTestSort(Builder $query, ?string $field, string $direction): void
    {
        match ($field) {
            'tested_on' => $query->orderBy('tested_on', $direction),
            'test_status' => $query->orderBy('status', $direction),
            'score' => $query->orderBy('score', $direction),
            'attempt_number' => $query->orderBy('attempt_no', $direction),
            default => $query->orderByDesc('tested_on')->orderByDesc('id'),
        };
    }

    protected function applyQuranWorkflowSort(Builder $query, ?string $field, string $direction): void
    {
        match ($field) {
            'test_status' => $query->orderBy('status', $direction),
            'passed_on' => $query->orderBy('passed_on', $direction),
            default => $query->orderByDesc('id'),
        };
    }

    protected function applyAssessmentSort(Builder $query, ?string $field, string $direction): void
    {
        match ($field) {
            'assessment_title' => $query->orderBy('title', $direction),
            'status' => $query->orderBy('is_active', $direction),
            'scheduled_at', 'due_at', 'total_mark', 'pass_mark', 'results_count', 'passed_results_count', 'failed_results_count', 'average_score' => $query->orderBy($field, $direction),
            default => $query->orderByDesc('due_at')->orderByDesc('id'),
        };
    }

    protected function applyAssessmentResultSort(Builder $query, ?string $field, string $direction): void
    {
        match ($field) {
            'score' => $query->orderBy('score', $direction),
            'result_status' => $query->orderBy('status', $direction),
            'attempt_number' => $query->orderBy('attempt_no', $direction),
            default => $query->orderByDesc('id'),
        };
    }

    protected function applyTeacherSort(Builder $query, ?string $field, string $direction): void
    {
        match ($field) {
            'full_name' => $query->orderBy('first_name', $direction)->orderBy('last_name', $direction),
            'teacher_status' => $query->orderBy('status', $direction),
            'hired_at' => $query->orderBy('hired_at', $direction),
            default => $query->orderByDesc('id'),
        };
    }

    protected function applyFinanceTransactionSort(Builder $query, ?string $field, string $direction): void
    {
        match ($field) {
            'transaction_number' => $query->orderBy('transaction_no', $direction),
            'transaction_type' => $query->orderBy('type', $direction),
            'transaction_date', 'amount', 'signed_amount', 'local_amount' => $query->orderBy($field, $direction),
            default => $query->orderByDesc('transaction_date')->orderByDesc('id'),
        };
    }

    protected function courseValue(Course $course, string $field): mixed
    {
        return match ($field) {
            'course_name' => $course->name,
            'academic_year' => $course->academicYear?->name,
            'status' => __('report_designer.record_statuses.'.($course->is_active ? 'active' : 'inactive')),
            'starts_on' => $course->starts_on?->format('Y-m-d'),
            'ends_on' => $course->ends_on?->format('Y-m-d'),
            'groups_count' => $course->groups_count,
            'active_groups_count' => $course->active_groups_count,
            'active_enrollments_count' => $course->active_enrollments_count,
        };
    }

    protected function groupValue(Group $group, string $field): mixed
    {
        return match ($field) {
            'group_name' => $group->name,
            'course_name' => $group->course?->name,
            'academic_year' => $group->academicYear?->name,
            'teacher_name' => $this->personName($group->teacher),
            'assistant_teacher_name' => $this->personName($group->assistantTeacher),
            'grade_level' => $group->gradeLevel?->name,
            'capacity' => $group->capacity,
            'active_enrollments_count' => $group->active_enrollments_count,
            'available_places' => max(0, (int) $group->capacity - (int) $group->active_enrollments_count),
            'curriculum_name' => $group->curriculum?->name,
            'curriculum_completed_lessons' => $this->curriculumSummary($group)['completed'],
            'curriculum_total_lessons' => $this->curriculumSummary($group)['total'],
            'curriculum_progress_percentage' => $this->curriculumSummary($group)['percentage'],
            'status' => __('report_designer.record_statuses.'.($group->is_active ? 'active' : 'inactive')),
            'starts_on' => $group->starts_on?->format('Y-m-d'),
            'ends_on' => $group->ends_on?->format('Y-m-d'),
        };
    }

    protected function curriculumSummary(Group $group): array
    {
        return $this->curriculumSummaries[$group->id]
            ??= app(CurriculumProgressService::class)->summary($group);
    }

    protected function studentAttendanceValue(StudentAttendanceRecord $record, string $field): mixed
    {
        $student = $record->student ?? $record->enrollment?->student;
        $group = $record->attendanceDay?->group ?? $record->enrollment?->group;
        $course = $record->studentAttendanceDay?->course ?? $group?->course;

        return match ($field) {
            'attendance_date' => ($record->studentAttendanceDay?->attendance_date ?? $record->attendanceDay?->attendance_date)?->format('Y-m-d'),
            'student_number' => $student?->student_number,
            'full_name' => $this->personName($student),
            'attendance_status' => $record->status?->name,
            'presence_result' => $record->status
                ? __('report_designer.presence_results.'.($record->status->is_present ? 'present' : 'not_present'))
                : __('report_designer.presence_results.unknown'),
            'attendance_scope' => __('report_designer.attendance_scopes.'.($record->student_attendance_day_id ? 'center' : 'groups')),
            'course_name' => $course?->name,
            'group_name' => $group?->name,
            'notes' => $record->notes,
        };
    }

    protected function memorizationValue(MemorizationSession $session, string $field): mixed
    {
        return match ($field) {
            'recorded_on' => $session->recorded_on?->format('Y-m-d'),
            'student_number' => $session->student?->student_number,
            'full_name' => $this->personName($session->student),
            'student_identity' => $this->personName($session->student).' ('.$session->student?->student_number.')',
            'entry_type' => __('report_designer.entry_types.'.$session->entry_type),
            'from_page' => $session->from_page,
            'to_page' => $session->to_page,
            'pages_count' => $session->pages_count,
            'teacher_name' => $this->personName($session->teacher),
            'course_name' => $session->enrollment?->group?->course?->name,
            'group_name' => $session->enrollment?->group?->name,
            'notes' => $session->notes,
        };
    }

    protected function quranTestValue(QuranTest $test, string $field): mixed
    {
        return match ($field) {
            'tested_on' => $test->tested_on?->format('Y-m-d'),
            'student_number' => $test->student?->student_number,
            'full_name' => $this->personName($test->student),
            'test_type' => $test->type?->name,
            'juz_number' => $test->juz?->juz_number,
            'test_status' => __('report_designer.test_statuses.'.$test->status),
            'score' => $test->score !== null ? (float) $test->score : null,
            'attempt_number' => $test->attempt_no,
            'teacher_name' => $this->personName($test->teacher),
            'course_name' => $test->enrollment?->group?->course?->name,
            'group_name' => $test->enrollment?->group?->name,
            'notes' => $test->notes,
        };
    }

    protected function quranPartialTestValue(QuranPartialTest $test, string $field): mixed
    {
        $attempts = $test->parts->flatMap(fn ($part) => $part->attempts);
        $latestAttempt = $this->latestAttempt($attempts);

        return match ($field) {
            'student_number' => $test->student?->student_number,
            'full_name' => $this->personName($test->student),
            'juz_number' => $test->juz?->juz_number,
            'test_status' => __('report_designer.test_statuses.'.$test->status),
            'attempts_count' => $attempts->count(),
            'latest_tested_on' => $latestAttempt?->tested_on?->format('Y-m-d'),
            'latest_score' => $latestAttempt?->score !== null ? (float) $latestAttempt->score : null,
            'latest_attempt_status' => $latestAttempt ? __('report_designer.test_statuses.'.$latestAttempt->status) : null,
            'teacher_name' => $this->personName($latestAttempt?->teacher),
            'passed_on' => $test->passed_on?->format('Y-m-d'),
            'course_name' => $test->enrollment?->group?->course?->name,
            'group_name' => $test->enrollment?->group?->name,
            'latest_notes' => $latestAttempt?->notes,
            'passed_parts_count' => $test->parts->where('status', 'passed')->count(),
            'parts_count' => $test->parts->count(),
            'latest_mistake_count' => $latestAttempt?->mistake_count,
        };
    }

    protected function quranFinalTestValue(QuranFinalTest $test, string $field): mixed
    {
        $latestAttempt = $this->latestAttempt($test->attempts);

        return match ($field) {
            'student_number' => $test->student?->student_number,
            'full_name' => $this->personName($test->student),
            'juz_number' => $test->juz?->juz_number,
            'test_status' => __('report_designer.test_statuses.'.$test->status),
            'attempts_count' => $test->attempts->count(),
            'latest_tested_on' => $latestAttempt?->tested_on?->format('Y-m-d'),
            'latest_score' => $latestAttempt?->score !== null ? (float) $latestAttempt->score : null,
            'latest_attempt_status' => $latestAttempt ? __('report_designer.test_statuses.'.$latestAttempt->status) : null,
            'teacher_name' => $this->personName($latestAttempt?->teacher),
            'passed_on' => $test->passed_on?->format('Y-m-d'),
            'course_name' => $test->enrollment?->group?->course?->name,
            'group_name' => $test->enrollment?->group?->name,
            'latest_notes' => $latestAttempt?->notes,
        };
    }

    protected function assessmentValue(Assessment $assessment, string $field): mixed
    {
        $groups = $assessment->groups
            ->when($assessment->group, fn ($items) => $items->prepend($assessment->group))
            ->unique('id')
            ->pluck('name')
            ->implode(', ');

        return match ($field) {
            'assessment_title' => $assessment->title,
            'assessment_type' => $assessment->type?->name,
            'assessment_groups' => $groups,
            'scheduled_at' => $assessment->scheduled_at?->format('Y-m-d H:i'),
            'due_at' => $assessment->due_at?->format('Y-m-d H:i'),
            'total_mark' => $assessment->total_mark !== null ? (float) $assessment->total_mark : null,
            'pass_mark' => $assessment->pass_mark !== null ? (float) $assessment->pass_mark : null,
            'status' => __('report_designer.record_statuses.'.($assessment->is_active ? 'active' : 'inactive')),
            'results_count' => $assessment->results_count,
            'passed_results_count' => $assessment->passed_results_count,
            'failed_results_count' => $assessment->failed_results_count,
            'average_score' => $assessment->average_score !== null ? round((float) $assessment->average_score, 2) : null,
            'description' => $assessment->description,
        };
    }

    protected function assessmentResultValue(AssessmentResult $result, string $field): mixed
    {
        return match ($field) {
            'due_at' => $result->assessment?->due_at?->format('Y-m-d H:i'),
            'assessment_title' => $result->assessment?->title,
            'assessment_type' => $result->assessment?->type?->name,
            'student_number' => $result->student?->student_number,
            'full_name' => $this->personName($result->student),
            'score' => $result->score !== null ? (float) $result->score : null,
            'result_status' => __('report_designer.assessment_statuses.'.$result->status),
            'attempt_number' => $result->attempt_no,
            'teacher_name' => $this->personName($result->teacher),
            'course_name' => $result->enrollment?->group?->course?->name,
            'group_name' => $result->enrollment?->group?->name,
            'notes' => $result->notes,
        };
    }

    protected function teacherValue(Teacher $teacher, string $field): mixed
    {
        $groups = $this->teacherGroups($teacher);
        $activeGroups = $groups->where('is_active', true);

        return match ($field) {
            'full_name' => $this->personName($teacher),
            'teacher_status' => __('report_designer.teacher_statuses.'.$teacher->status),
            'job_title' => $teacher->jobTitle?->name ?? $teacher->job_title,
            'hired_at' => $teacher->hired_at?->format('Y-m-d'),
            'is_helping' => __('report_designer.helping_statuses.'.($teacher->is_helping ? 'yes' : 'no')),
            'assigned_groups_count' => $teacher->assignedGroups->count(),
            'assisted_groups_count' => $teacher->assistedGroups->count(),
            'active_groups_count' => $activeGroups->count(),
            'active_enrollments_count' => $activeGroups->sum('active_enrollments_count'),
            'assigned_groups' => $groups->pluck('name')->filter()->implode(', '),
            'assigned_courses' => $groups->pluck('course.name')->filter()->unique()->implode(', '),
        };
    }

    protected function teacherGroups(Teacher $teacher)
    {
        if (! $this->modules->enabled('classes')) {
            return collect();
        }

        return $teacher->assignedGroups
            ->concat($teacher->assistedGroups)
            ->unique('id')
            ->values();
    }

    protected function financeTransactionValue(FinanceTransaction $transaction, string $field): mixed
    {
        return match ($field) {
            'transaction_date' => $transaction->transaction_date?->format('Y-m-d'),
            'transaction_number' => $transaction->transaction_no,
            'transaction_type' => $this->finance->transactionTypeLabel($transaction->type, $transaction),
            'transaction_direction' => __('report_designer.finance_directions.'.$transaction->direction),
            'finance_category' => $this->finance->transactionCategoryLabel($transaction),
            'cash_box' => $transaction->cashBox?->name,
            'currency' => $transaction->currency?->code,
            'amount' => (float) $transaction->amount,
            'signed_amount' => (float) $transaction->signed_amount,
            'local_amount' => (float) $transaction->local_amount,
            'entered_by' => $transaction->enteredBy?->name ?: $transaction->enteredBy?->username,
            'description' => $transaction->description,
        };
    }

    protected function latestAttempt($attempts): mixed
    {
        return $attempts
            ->sortBy([
                ['tested_on', 'desc'],
                ['id', 'desc'],
            ])
            ->first();
    }

    protected function personName(mixed $person): ?string
    {
        return $person ? trim($person->first_name.' '.$person->last_name) : null;
    }

    protected function result(string $source, array $fields, array $rows, int $total): array
    {
        return [
            'columns' => collect($this->catalog->fields($source))->only($fields)->all(),
            'rows' => $rows,
            'total' => $total,
            'limit' => $this->rowLimit,
            'calculations' => collect($this->activeCalculations)->map(function (array $calculation) use ($source, $total): array {
                $key = $this->calculationKey($calculation['operation'], $calculation['field']);

                return [
                    'label' => $this->catalog->calculationLabel($source, $calculation['operation'], $calculation['field']),
                    'value' => $calculation['operation'] === 'count' ? $total : ($this->calculationValues[$key] ?? null),
                ];
            })->all(),
            'grouping' => $this->groupingResult,
        ];
    }

    protected function financeCalculationValues(Builder $query): array
    {
        $columns = [
            'amount' => 'amount',
            'signed_amount' => 'signed_amount',
            'local_amount' => 'local_amount',
        ];

        return collect($this->activeCalculations)
            ->reject(fn (array $calculation) => $calculation['operation'] === 'count')
            ->mapWithKeys(function (array $calculation) use ($columns, $query): array {
                $operation = $calculation['operation'];
                $field = $calculation['field'];
                $value = $operation === 'absolute_sum'
                    ? (clone $query)->sum(DB::raw('ABS('.$columns[$field].')'))
                    : (clone $query)->{$operation}($columns[$field]);

                return [$this->calculationKey($operation, $field) => $value === null ? null : round((float) $value, 2)];
            })
            ->all();
    }

    protected function prepareOperationalSummaries(string $source, Builder $query, callable $valueResolver): void
    {
        $calculations = collect($this->activeCalculations)
            ->reject(fn (array $calculation) => $calculation['operation'] === 'count')
            ->values()
            ->all();

        if ($calculations === [] && $this->activeGroupBy === null) {
            return;
        }

        $calculationStates = [];
        $groupStates = [];

        (clone $query)->reorder()->chunkById(250, function ($records) use (&$calculationStates, &$groupStates, $calculations, $valueResolver): void {
            foreach ($records as $record) {
                foreach ($calculations as $calculation) {
                    $key = $this->calculationKey($calculation['operation'], $calculation['field']);
                    $this->accumulateCalculation($calculationStates[$key], $calculation['operation'], $valueResolver($record, $calculation['field']));
                }

                if ($this->activeGroupBy === null) {
                    continue;
                }

                $label = $valueResolver($record, $this->activeGroupBy);
                $label = filled($label) ? (string) $label : __('report_designer.grouping.unknown');
                $groupStates[$label] ??= ['record_count' => 0, 'calculations' => []];
                $groupStates[$label]['record_count']++;

                foreach ($calculations as $calculation) {
                    $key = $this->calculationKey($calculation['operation'], $calculation['field']);
                    $this->accumulateCalculation(
                        $groupStates[$label]['calculations'][$key],
                        $calculation['operation'],
                        $valueResolver($record, $calculation['field']),
                    );
                }
            }
        });

        $this->calculationValues = collect($calculationStates)
            ->map(fn (array $state) => $this->finalizeCalculation($state))
            ->all();

        if ($this->activeGroupBy !== null) {
            $this->groupingResult = $this->operationalGroupingResult($source, $groupStates, $calculations);
        }
    }

    protected function accumulateCalculation(?array &$state, string $operation, mixed $value): void
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return;
        }

        $number = $operation === 'absolute_sum' ? abs((float) $value) : (float) $value;
        $state ??= ['operation' => $operation, 'count' => 0, 'sum' => 0.0, 'min' => null, 'max' => null];
        $state['count']++;
        $state['sum'] += $number;
        $state['min'] = $state['min'] === null ? $number : min($state['min'], $number);
        $state['max'] = $state['max'] === null ? $number : max($state['max'], $number);
    }

    protected function finalizeCalculation(?array $state): ?float
    {
        if ($state === null || $state['count'] === 0) {
            return null;
        }

        $value = match ($state['operation']) {
            'sum', 'absolute_sum' => $state['sum'],
            'avg' => $state['sum'] / $state['count'],
            'min' => $state['min'],
            'max' => $state['max'],
        };

        return round((float) $value, 2);
    }

    protected function operationalGroupingResult(string $source, array $groupStates, array $calculations): array
    {
        $calculationLabels = [];
        foreach ($this->activeCalculations as $index => $calculation) {
            if ($calculation['operation'] === 'count') {
                continue;
            }

            $calculationLabels['report_calculation_'.$index] = $this->catalog->calculationLabel(
                $source,
                $calculation['operation'],
                $calculation['field'],
            );
        }

        $rows = collect($groupStates)
            ->map(function (array $state, string $label) use ($calculations): array {
                $row = ['group' => $label, 'record_count' => $state['record_count']];

                foreach ($calculations as $calculation) {
                    $activeIndex = collect($this->activeCalculations)->search(fn (array $active) => $active === $calculation);
                    $key = $this->calculationKey($calculation['operation'], $calculation['field']);
                    $row['report_calculation_'.$activeIndex] = $this->finalizeCalculation($state['calculations'][$key] ?? null);
                }

                return $row;
            });

        $chronological = in_array($this->activeGroupBy, $this->catalog->chronologicalGroupFields($source), true);
        $leaderboardMetric = in_array(data_get($this->activePresentation, 'type'), [
            ReportDesignerCatalog::PRESENTATION_LEADERBOARD,
            ReportDesignerCatalog::PRESENTATION_RANKING,
        ], true)
            ? data_get($this->activePresentation, 'metric')
            : null;
        $rows = (filled($leaderboardMetric)
            ? $rows->sort(fn (array $left, array $right) => ($right[$leaderboardMetric] ?? 0) <=> ($left[$leaderboardMetric] ?? 0) ?: strcmp($left['group'], $right['group']))->take($this->groupLimit)
            : ($chronological
                ? $rows->sortBy('group')->take(-$this->groupLimit)
                : $rows->sort(fn (array $left, array $right) => $right['record_count'] <=> $left['record_count'] ?: strcmp($left['group'], $right['group']))->take($this->groupLimit)))
            ->values()
            ->all();

        return [
            'label' => $this->catalog->groupableFields($source)[$this->activeGroupBy]['label'],
            'columns' => array_merge([
                'group' => __('report_designer.grouping.group'),
                'record_count' => __('report_designer.calculations.record_count'),
            ], $calculationLabels),
            'rows' => $rows,
            'limit' => $this->groupLimit,
        ];
    }

    protected function calculationKey(string $operation, ?string $field): string
    {
        return $operation.':'.($field ?? 'records');
    }

    protected function financeGroupingResult(Builder $query): ?array
    {
        if ($this->activeGroupBy === null) {
            return null;
        }

        $baseQuery = (clone $query)->withoutEagerLoads()->reorder()->toBase();
        $groupQuery = DB::query()->fromSub($baseQuery, 'report_rows');
        $groupExpression = match ($this->activeGroupBy) {
            'transaction_date' => 'report_rows.transaction_date',
            'transaction_quarter' => $this->financeQuarterGroupExpression($query),
            'transaction_type' => 'report_rows.type',
            'transaction_direction' => 'report_rows.direction',
            'finance_category' => 'COALESCE(report_rows.finance_category_id, finance_requests.finance_category_id, finance_requests.finance_pull_request_kind_id)',
            'cash_box' => 'report_rows.cash_box_id',
            'currency' => 'report_rows.currency_id',
        };

        if ($this->activeGroupBy === 'finance_category') {
            $groupQuery->leftJoin('finance_requests', 'finance_requests.id', '=', 'report_rows.finance_request_id');
        }

        $groupQuery->selectRaw($groupExpression.' as report_group_key, COUNT(*) as report_group_count');
        $calculationColumns = [
            'amount' => 'report_rows.amount',
            'signed_amount' => 'report_rows.signed_amount',
            'local_amount' => 'report_rows.local_amount',
        ];
        $calculationLabels = [];

        foreach ($this->activeCalculations as $index => $calculation) {
            if ($calculation['operation'] === 'count') {
                continue;
            }

            $alias = 'report_calculation_'.$index;
            $expression = $calculation['operation'] === 'absolute_sum'
                ? 'SUM(ABS('.$calculationColumns[$calculation['field']].'))'
                : strtoupper($calculation['operation']).'('.$calculationColumns[$calculation['field']].')';
            $groupQuery->selectRaw($expression.' as '.$alias);
            $calculationLabels[$alias] = $this->catalog->calculationLabel(
                ReportDesignerCatalog::FINANCE_TRANSACTIONS,
                $calculation['operation'],
                $calculation['field'],
            );
        }

        $groupQuery->groupByRaw($groupExpression);
        if (in_array($this->activeGroupBy, ['transaction_date', 'transaction_quarter'], true)) {
            $groupQuery->orderByDesc('report_group_key');
        } else {
            $groupQuery->orderByDesc('report_group_count')->orderBy('report_group_key');
        }
        $groupRows = $groupQuery->limit($this->groupLimit)->get();
        if (in_array($this->activeGroupBy, ['transaction_date', 'transaction_quarter'], true)) {
            $groupRows = $groupRows->sortBy('report_group_key')->values();
        }
        $groupLabels = $this->financeGroupLabels($this->activeGroupBy, $groupRows->pluck('report_group_key')->all());

        return [
            'label' => $this->catalog->groupableFields(ReportDesignerCatalog::FINANCE_TRANSACTIONS)[$this->activeGroupBy]['label'],
            'columns' => array_merge([
                'group' => __('report_designer.grouping.group'),
                'record_count' => __('report_designer.calculations.record_count'),
            ], $calculationLabels),
            'rows' => $groupRows->map(function ($row) use ($calculationLabels, $groupLabels): array {
                $values = [
                    'group' => $groupLabels[(string) $row->report_group_key] ?? __('report_designer.grouping.unknown'),
                    'record_count' => (int) $row->report_group_count,
                ];

                foreach (array_keys($calculationLabels) as $alias) {
                    $values[$alias] = $row->{$alias} === null ? null : round((float) $row->{$alias}, 2);
                }

                return $values;
            })->all(),
            'limit' => $this->groupLimit,
        ];
    }

    protected function financeGroupLabels(string $field, array $keys): array
    {
        $keys = collect($keys)->filter(fn ($key) => $key !== null)->unique()->values();

        return match ($field) {
            'transaction_date', 'transaction_quarter' => $keys->mapWithKeys(fn ($key) => [(string) $key => (string) $key])->all(),
            'transaction_type' => $keys->mapWithKeys(fn ($key) => [(string) $key => $this->finance->transactionTypeLabel((string) $key)])->all(),
            'transaction_direction' => $keys->mapWithKeys(fn ($key) => [(string) $key => __('report_designer.finance_directions.'.$key)])->all(),
            'finance_category' => FinanceCategory::query()->whereIn('id', $keys)->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all(),
            'cash_box' => FinanceCashBox::query()->whereIn('id', $keys)->pluck('name', 'id')->mapWithKeys(fn ($name, $id) => [(string) $id => $name])->all(),
            'currency' => FinanceCurrency::query()->whereIn('id', $keys)->pluck('code', 'id')->mapWithKeys(fn ($code, $id) => [(string) $id => $code])->all(),
        };
    }

    protected function financeQuarterGroupExpression(Builder $query): string
    {
        return match ($query->getConnection()->getDriverName()) {
            'sqlite' => "printf('%04d-Q%d', CAST(strftime('%Y', report_rows.transaction_date) AS INTEGER), CAST((CAST(strftime('%m', report_rows.transaction_date) AS INTEGER) + 2) / 3 AS INTEGER))",
            'pgsql' => "TO_CHAR(report_rows.transaction_date, 'YYYY-\"Q\"Q')",
            default => "CONCAT(YEAR(report_rows.transaction_date), '-Q', QUARTER(report_rows.transaction_date))",
        };
    }

    protected function applyStudentSort(Builder $query, ?string $field, string $direction): void
    {
        match ($field) {
            'full_name' => $query->orderBy('first_name', $direction)->orderBy('last_name', $direction),
            'student_number', 'status', 'joined_at', 'birth_date' => $query->orderBy($field, $direction),
            default => $query->orderByDesc('id'),
        };
    }

    protected function studentValue(Student $student, string $field): mixed
    {
        return match ($field) {
            'student_number' => $student->student_number,
            'full_name' => trim($student->first_name.' '.$student->last_name),
            'student_identity' => trim($student->first_name.' '.$student->last_name).' ('.$student->student_number.')',
            'status' => __('report_designer.record_statuses.'.$student->status),
            'joined_at' => $student->joined_at?->format('Y-m-d'),
            'birth_date' => $student->birth_date?->format('Y-m-d'),
            'grade_level' => $student->gradeLevel?->name,
            'current_group' => $student->currentActiveEnrollment()?->group?->name,
            'points_balance' => (int) $student->enrollments->sum('final_points_cached'),
            'memorized_pages' => (int) $student->enrollments->sum('memorized_pages_cached'),
        };
    }
}
