<?php

namespace App\Services;

use App\Models\User;
use App\Services\Landlord\CurrentModuleAccess;
use Illuminate\Validation\ValidationException;

class ReportDesignerCatalog
{
    public const PRESENTATION_TABLE = 'table';

    public const PRESENTATION_BAR = 'bar';

    public const PRESENTATION_DONUT = 'donut';

    public const PRESENTATION_LOLLIPOP = 'lollipop';

    public const PRESENTATION_LINE = 'line';

    public const PRESENTATION_TREEMAP = 'treemap';

    public const PRESENTATION_HOTBAR = 'hotbar';

    public const PRESENTATION_PERFORMANCE_MAP = 'performance_map';

    public const PRESENTATION_LEADERBOARD = 'leaderboard';

    public const CALCULATION_LIMIT = 5;

    public const STUDENTS = 'students';

    public const COURSES = 'courses';

    public const GROUPS = 'groups';

    public const STUDENT_ATTENDANCE = 'student_attendance';

    public const MEMORIZATION_SESSIONS = 'memorization_sessions';

    public const QURAN_TESTS = 'quran_tests';

    public const QURAN_PARTIAL_TESTS = 'quran_partial_tests';

    public const QURAN_FINAL_TESTS = 'quran_final_tests';

    public const ASSESSMENTS = 'assessments';

    public const ASSESSMENT_RESULTS = 'assessment_results';

    public const TEACHERS = 'teachers';

    public const FINANCE_TRANSACTIONS = 'finance_transactions';

    public function __construct(protected CurrentModuleAccess $modules) {}

    public function sources(?User $user = null): array
    {
        $sources = [];

        if ($this->modules->enabled('students')) {
            $sources[self::STUDENTS] = [
                'label' => __('report_designer.sources.students.label'),
                'description' => __('report_designer.sources.students.description'),
            ];
        }

        if ($this->modules->enabled('classes')) {
            $sources[self::COURSES] = [
                'label' => __('report_designer.sources.courses.label'),
                'description' => __('report_designer.sources.courses.description'),
            ];
            $sources[self::GROUPS] = [
                'label' => __('report_designer.sources.groups.label'),
                'description' => __('report_designer.sources.groups.description'),
            ];
        }

        if ($this->modules->enabled('student_attendance')) {
            $sources[self::STUDENT_ATTENDANCE] = [
                'label' => __('report_designer.sources.student_attendance.label'),
                'description' => __('report_designer.sources.student_attendance.description'),
            ];
        }

        if ($this->modules->enabled('memorization')) {
            $sources[self::MEMORIZATION_SESSIONS] = [
                'label' => __('report_designer.sources.memorization_sessions.label'),
                'description' => __('report_designer.sources.memorization_sessions.description'),
            ];
        }

        if ($this->modules->enabled('quran_tests')) {
            $sources[self::QURAN_TESTS] = [
                'label' => __('report_designer.sources.quran_tests.label'),
                'description' => __('report_designer.sources.quran_tests.description'),
            ];
            $sources[self::QURAN_PARTIAL_TESTS] = [
                'label' => __('report_designer.sources.quran_partial_tests.label'),
                'description' => __('report_designer.sources.quran_partial_tests.description'),
            ];
            $sources[self::QURAN_FINAL_TESTS] = [
                'label' => __('report_designer.sources.quran_final_tests.label'),
                'description' => __('report_designer.sources.quran_final_tests.description'),
            ];
        }

        if ($this->modules->enabled('assessments')) {
            $sources[self::ASSESSMENTS] = [
                'label' => __('report_designer.sources.assessments.label'),
                'description' => __('report_designer.sources.assessments.description'),
            ];
            $sources[self::ASSESSMENT_RESULTS] = [
                'label' => __('report_designer.sources.assessment_results.label'),
                'description' => __('report_designer.sources.assessment_results.description'),
            ];
        }

        if ($this->modules->enabled('teachers')) {
            $sources[self::TEACHERS] = [
                'label' => __('report_designer.sources.teachers.label'),
                'description' => __('report_designer.sources.teachers.description'),
            ];
        }

        if ($this->modules->enabled('finance') && ($user === null || $user->can('finance.reports.view'))) {
            $sources[self::FINANCE_TRANSACTIONS] = [
                'label' => __('report_designer.sources.finance_transactions.label'),
                'description' => __('report_designer.sources.finance_transactions.description'),
            ];
        }

        return $sources;
    }

    public function librarySources(): array
    {
        return collect([
            self::STUDENTS,
            self::COURSES,
            self::GROUPS,
            self::STUDENT_ATTENDANCE,
            self::MEMORIZATION_SESSIONS,
            self::QURAN_TESTS,
            self::QURAN_PARTIAL_TESTS,
            self::QURAN_FINAL_TESTS,
            self::ASSESSMENTS,
            self::ASSESSMENT_RESULTS,
            self::TEACHERS,
            self::FINANCE_TRANSACTIONS,
        ])->mapWithKeys(fn (string $source): array => [$source => [
            'label' => __('report_designer.sources.'.$source.'.label'),
            'description' => __('report_designer.sources.'.$source.'.description'),
            'required_modules' => $this->requiredModules($source),
        ]])->all();
    }

    public function requiredModules(string $source): array
    {
        return match ($source) {
            self::STUDENTS => ['students'],
            self::COURSES, self::GROUPS => ['classes'],
            self::STUDENT_ATTENDANCE => ['student_attendance'],
            self::MEMORIZATION_SESSIONS => ['memorization'],
            self::QURAN_TESTS, self::QURAN_PARTIAL_TESTS, self::QURAN_FINAL_TESTS => ['quran_tests'],
            self::ASSESSMENTS, self::ASSESSMENT_RESULTS => ['assessments'],
            self::TEACHERS => ['teachers'],
            self::FINANCE_TRANSACTIONS => ['finance'],
            default => abort(404),
        };
    }

    public function fields(string $source): array
    {
        return match ($source) {
            self::STUDENTS => array_merge([
                'student_number' => $this->field('student_number', 'text'),
                'full_name' => $this->field('full_name', 'text'),
                'student_identity' => $this->field('student_identity', 'text'),
                'status' => $this->field('status', 'status'),
                'joined_at' => $this->field('joined_at', 'date'),
                'birth_date' => $this->field('birth_date', 'date'),
                'grade_level' => $this->field('grade_level', 'text'),
                'current_group' => $this->field('current_group', 'text'),
            ], $this->modules->enabled('points_rewards') ? [
                'points_balance' => $this->field('points_balance', 'number'),
            ] : [], $this->modules->enabled('memorization') ? [
                'memorized_pages' => $this->field('memorized_pages', 'number'),
            ] : []),
            self::COURSES => [
                'course_name' => $this->field('course_name', 'text'),
                'academic_year' => $this->field('academic_year', 'text'),
                'status' => $this->field('status', 'status'),
                'starts_on' => $this->field('starts_on', 'date'),
                'ends_on' => $this->field('ends_on', 'date'),
                'groups_count' => $this->field('groups_count', 'number'),
                'active_groups_count' => $this->field('active_groups_count', 'number'),
                'active_enrollments_count' => $this->field('active_enrollments_count', 'number'),
            ],
            self::GROUPS => array_merge([
                'group_name' => $this->field('group_name', 'text'),
                'course_name' => $this->field('course_name', 'text'),
                'academic_year' => $this->field('academic_year', 'text'),
                'teacher_name' => $this->field('teacher_name', 'text'),
                'assistant_teacher_name' => $this->field('assistant_teacher_name', 'text'),
                'grade_level' => $this->field('grade_level', 'text'),
                'capacity' => $this->field('capacity', 'number'),
                'active_enrollments_count' => $this->field('active_enrollments_count', 'number'),
                'available_places' => $this->field('available_places', 'number'),
                'status' => $this->field('status', 'status'),
                'starts_on' => $this->field('starts_on', 'date'),
                'ends_on' => $this->field('ends_on', 'date'),
            ], $this->modules->enabled('curriculum') ? [
                'curriculum_name' => $this->field('curriculum_name', 'text'),
                'curriculum_completed_lessons' => $this->field('curriculum_completed_lessons', 'number'),
                'curriculum_total_lessons' => $this->field('curriculum_total_lessons', 'number'),
                'curriculum_progress_percentage' => $this->field('curriculum_progress_percentage', 'number'),
            ] : []),
            self::STUDENT_ATTENDANCE => [
                'attendance_date' => $this->field('attendance_date', 'date'),
                'student_number' => $this->field('student_number', 'text'),
                'full_name' => $this->field('full_name', 'text'),
                'attendance_status' => $this->field('attendance_status', 'status'),
                'presence_result' => $this->field('presence_result', 'status'),
                'attendance_scope' => $this->field('attendance_scope', 'status'),
                'course_name' => $this->field('course_name', 'text'),
                'group_name' => $this->field('group_name', 'text'),
                'notes' => $this->field('notes', 'text'),
            ],
            self::MEMORIZATION_SESSIONS => array_merge([
                'recorded_on' => $this->field('recorded_on', 'date'),
                'student_number' => $this->field('student_number', 'text'),
                'full_name' => $this->field('full_name', 'text'),
                'student_identity' => $this->field('student_identity', 'text'),
                'entry_type' => $this->field('entry_type', 'status'),
                'from_page' => $this->field('from_page', 'number'),
                'to_page' => $this->field('to_page', 'number'),
                'pages_count' => $this->field('pages_count', 'number'),
                'teacher_name' => $this->field('teacher_name', 'text'),
            ], $this->classContextFields(), [
                'notes' => $this->field('notes', 'text'),
            ]),
            self::QURAN_TESTS => array_merge([
                'tested_on' => $this->field('tested_on', 'date'),
                'student_number' => $this->field('student_number', 'text'),
                'full_name' => $this->field('full_name', 'text'),
                'test_type' => $this->field('test_type', 'text'),
                'juz_number' => $this->field('juz_number', 'number'),
                'test_status' => $this->field('test_status', 'status'),
                'score' => $this->field('score', 'number'),
                'attempt_number' => $this->field('attempt_number', 'number'),
                'teacher_name' => $this->field('teacher_name', 'text'),
            ], $this->classContextFields(), [
                'notes' => $this->field('notes', 'text'),
            ]),
            self::QURAN_PARTIAL_TESTS => array_merge($this->quranWorkflowFields(), [
                'passed_parts_count' => $this->field('passed_parts_count', 'number'),
                'parts_count' => $this->field('parts_count', 'number'),
                'latest_mistake_count' => $this->field('latest_mistake_count', 'number'),
            ]),
            self::QURAN_FINAL_TESTS => $this->quranWorkflowFields(),
            self::ASSESSMENTS => [
                'assessment_title' => $this->field('assessment_title', 'text'),
                'assessment_type' => $this->field('assessment_type', 'text'),
                'assessment_groups' => $this->field('assessment_groups', 'text'),
                'scheduled_at' => $this->field('scheduled_at', 'date'),
                'due_at' => $this->field('due_at', 'date'),
                'total_mark' => $this->field('total_mark', 'number'),
                'pass_mark' => $this->field('pass_mark', 'number'),
                'status' => $this->field('status', 'status'),
                'results_count' => $this->field('results_count', 'number'),
                'passed_results_count' => $this->field('passed_results_count', 'number'),
                'failed_results_count' => $this->field('failed_results_count', 'number'),
                'average_score' => $this->field('average_score', 'number'),
                'description' => $this->field('description', 'text'),
            ],
            self::ASSESSMENT_RESULTS => array_merge([
                'due_at' => $this->field('due_at', 'date'),
                'assessment_title' => $this->field('assessment_title', 'text'),
                'assessment_type' => $this->field('assessment_type', 'text'),
                'student_number' => $this->field('student_number', 'text'),
                'full_name' => $this->field('full_name', 'text'),
                'score' => $this->field('score', 'number'),
                'result_status' => $this->field('result_status', 'status'),
                'attempt_number' => $this->field('attempt_number', 'number'),
                'teacher_name' => $this->field('teacher_name', 'text'),
            ], $this->classContextFields(), [
                'notes' => $this->field('notes', 'text'),
            ]),
            self::TEACHERS => array_merge([
                'full_name' => $this->field('full_name', 'text'),
                'teacher_status' => $this->field('teacher_status', 'status'),
                'job_title' => $this->field('job_title', 'text'),
                'hired_at' => $this->field('hired_at', 'date'),
                'is_helping' => $this->field('is_helping', 'status'),
            ], $this->teacherWorkloadFields()),
            self::FINANCE_TRANSACTIONS => [
                'transaction_date' => $this->field('transaction_date', 'date'),
                'transaction_number' => $this->field('transaction_number', 'text'),
                'transaction_type' => $this->field('transaction_type', 'status'),
                'transaction_direction' => $this->field('transaction_direction', 'status'),
                'finance_category' => $this->field('finance_category', 'text'),
                'cash_box' => $this->field('cash_box', 'text'),
                'currency' => $this->field('currency', 'text'),
                'amount' => $this->field('amount', 'number'),
                'signed_amount' => $this->field('signed_amount', 'number'),
                'local_amount' => $this->field('local_amount', 'number'),
                'entered_by' => $this->field('entered_by', 'text'),
                'description' => $this->field('description', 'text'),
            ],
            default => abort(404),
        };
    }

    public function defaultFields(string $source): array
    {
        $this->fields($source);

        $defaults = match ($source) {
            self::STUDENTS => ['student_number', 'full_name', 'status', 'current_group'],
            self::COURSES => ['course_name', 'academic_year', 'status', 'groups_count', 'active_enrollments_count'],
            self::GROUPS => ['group_name', 'course_name', 'teacher_name', 'capacity', 'active_enrollments_count', 'available_places', 'status'],
            self::STUDENT_ATTENDANCE => ['attendance_date', 'student_number', 'full_name', 'attendance_status', 'presence_result', 'group_name'],
            self::MEMORIZATION_SESSIONS => ['recorded_on', 'student_number', 'full_name', 'entry_type', 'pages_count', 'teacher_name', 'group_name'],
            self::QURAN_TESTS => ['tested_on', 'student_number', 'full_name', 'test_type', 'juz_number', 'test_status', 'score', 'attempt_number'],
            self::QURAN_PARTIAL_TESTS => ['full_name', 'juz_number', 'test_status', 'passed_parts_count', 'parts_count', 'attempts_count', 'latest_score', 'latest_tested_on'],
            self::QURAN_FINAL_TESTS => ['full_name', 'juz_number', 'test_status', 'attempts_count', 'latest_score', 'latest_tested_on', 'passed_on'],
            self::ASSESSMENTS => ['assessment_title', 'assessment_type', 'assessment_groups', 'due_at', 'total_mark', 'pass_mark', 'status', 'results_count', 'average_score'],
            self::ASSESSMENT_RESULTS => ['due_at', 'assessment_title', 'full_name', 'score', 'result_status', 'attempt_number', 'group_name'],
            self::TEACHERS => ['full_name', 'teacher_status', 'job_title', 'assigned_groups_count', 'assisted_groups_count', 'active_groups_count', 'active_enrollments_count'],
            self::FINANCE_TRANSACTIONS => ['transaction_date', 'transaction_number', 'transaction_type', 'finance_category', 'cash_box', 'currency', 'amount', 'local_amount'],
        };

        return array_values(array_intersect($defaults, array_keys($this->fields($source))));
    }

    public function presentationTypes(): array
    {
        return [
            self::PRESENTATION_TABLE => __('report_designer.presentation.types.table'),
            self::PRESENTATION_BAR => __('report_designer.presentation.types.bar'),
            self::PRESENTATION_DONUT => __('report_designer.presentation.types.donut'),
        ];
    }

    public function specializedPresentationTypes(): array
    {
        return [
            self::PRESENTATION_LOLLIPOP => __('report_designer.presentation.types.lollipop'),
            self::PRESENTATION_LINE => __('report_designer.presentation.types.line'),
            self::PRESENTATION_TREEMAP => __('report_designer.presentation.types.treemap'),
            self::PRESENTATION_HOTBAR => __('report_designer.presentation.types.hotbar'),
            self::PRESENTATION_PERFORMANCE_MAP => __('report_designer.presentation.types.performance_map'),
            self::PRESENTATION_LEADERBOARD => __('report_designer.presentation.types.leaderboard'),
        ];
    }

    public function libraryPresentationTypes(): array
    {
        return $this->presentationTypes() + $this->specializedPresentationTypes();
    }

    public function tableDensities(): array
    {
        return [
            'comfortable' => __('report_designer.presentation.densities.comfortable'),
            'compact' => __('report_designer.presentation.densities.compact'),
        ];
    }

    public function validatePresentation(array $presentation, ?string $groupBy, bool $allowSpecialized = false, ?string $source = null, array $calculations = []): array
    {
        $type = (string) ($presentation['type'] ?? self::PRESENTATION_TABLE);
        $density = (string) ($presentation['density'] ?? 'comfortable');
        $metric = (string) ($presentation['metric'] ?? 'record_count');

        $types = $allowSpecialized ? $this->libraryPresentationTypes() : $this->presentationTypes();

        if (! array_key_exists($type, $types)) {
            throw ValidationException::withMessages([
                'presentationType' => __('report_designer.validation.invalid_presentation'),
            ]);
        }

        if ($type !== self::PRESENTATION_TABLE && blank($groupBy)) {
            throw ValidationException::withMessages([
                'presentationType' => __('report_designer.validation.chart_requires_grouping'),
            ]);
        }

        if ($type === self::PRESENTATION_LINE
            && ($source === null || ! in_array($groupBy, $this->chronologicalGroupFields($source), true))) {
            throw ValidationException::withMessages([
                'presentationType' => __('report_designer.validation.line_requires_date_grouping'),
            ]);
        }

        if ($type === self::PRESENTATION_HOTBAR
            && ($source !== self::GROUPS || $groupBy !== 'group_name')) {
            throw ValidationException::withMessages([
                'presentationType' => __('report_designer.validation.hotbar_requires_groups'),
            ]);
        }

        if ($type === self::PRESENTATION_PERFORMANCE_MAP
            && ($source !== self::STUDENTS || $groupBy !== 'student_identity')) {
            throw ValidationException::withMessages([
                'presentationType' => __('report_designer.validation.performance_map_requires_students'),
            ]);
        }

        if ($type === self::PRESENTATION_LEADERBOARD
            && ($source !== self::MEMORIZATION_SESSIONS || $groupBy !== 'student_identity')) {
            throw ValidationException::withMessages([
                'presentationType' => __('report_designer.validation.leaderboard_requires_memorization'),
            ]);
        }

        if (! array_key_exists($density, $this->tableDensities())) {
            throw ValidationException::withMessages([
                'tableDensity' => __('report_designer.validation.invalid_table_density'),
            ]);
        }

        $allowedMetrics = collect($calculations)
            ->mapWithKeys(fn (array $calculation, int $index): array => ($calculation['operation'] ?? null) === 'count'
                ? []
                : ['report_calculation_'.$index => true])
            ->prepend(true, 'record_count');
        if (! $allowedMetrics->has($metric)) {
            throw ValidationException::withMessages([
                'presentationMetric' => __('report_designer.validation.invalid_presentation_metric'),
            ]);
        }

        $totalMetric = (string) ($presentation['total_metric'] ?? '');
        $calculationForMetric = function (string $key) use ($calculations): ?array {
            if (! preg_match('/^report_calculation_(\d+)$/', $key, $matches)) {
                return null;
            }

            return $calculations[(int) $matches[1]] ?? null;
        };
        $hotbarPercentage = $calculationForMetric($metric);
        $hotbarTotal = $calculationForMetric($totalMetric);
        if ($type === self::PRESENTATION_HOTBAR
            && ($metric === 'record_count'
                || ! $allowedMetrics->has($totalMetric)
                || $totalMetric === 'record_count'
                || $hotbarPercentage !== ['operation' => 'avg', 'field' => 'curriculum_progress_percentage']
                || $hotbarTotal !== ['operation' => 'max', 'field' => 'curriculum_total_lessons'])) {
            throw ValidationException::withMessages([
                'presentationMetric' => __('report_designer.validation.hotbar_requires_measures'),
            ]);
        }

        $xMetric = (string) ($presentation['x_metric'] ?? '');
        $performanceX = $calculationForMetric($xMetric);
        $performanceY = $calculationForMetric($metric);
        if ($type === self::PRESENTATION_PERFORMANCE_MAP
            && ($performanceX !== ['operation' => 'sum', 'field' => 'memorized_pages']
                || $performanceY !== ['operation' => 'sum', 'field' => 'points_balance'])) {
            throw ValidationException::withMessages([
                'presentationMetric' => __('report_designer.validation.performance_map_requires_measures'),
            ]);
        }

        $leaderboardMetric = $calculationForMetric($metric);
        if ($type === self::PRESENTATION_LEADERBOARD
            && $leaderboardMetric !== ['operation' => 'sum', 'field' => 'pages_count']) {
            throw ValidationException::withMessages([
                'presentationMetric' => __('report_designer.validation.leaderboard_requires_pages'),
            ]);
        }

        return array_filter([
            'type' => $type,
            'density' => $density,
            'metric' => $metric === 'record_count' ? null : $metric,
            'total_metric' => $type === self::PRESENTATION_HOTBAR ? $totalMetric : null,
            'x_metric' => $type === self::PRESENTATION_PERFORMANCE_MAP ? $xMetric : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    public function sortableFields(string $source): array
    {
        $sortable = match ($source) {
            self::STUDENTS => ['student_number', 'full_name', 'status', 'joined_at', 'birth_date'],
            self::COURSES => ['course_name', 'status', 'starts_on', 'ends_on', 'groups_count', 'active_groups_count', 'active_enrollments_count'],
            self::GROUPS => ['group_name', 'status', 'starts_on', 'ends_on', 'capacity', 'active_enrollments_count'],
            self::STUDENT_ATTENDANCE => [],
            self::MEMORIZATION_SESSIONS => ['recorded_on', 'entry_type', 'from_page', 'to_page', 'pages_count'],
            self::QURAN_TESTS => ['tested_on', 'test_status', 'score', 'attempt_number'],
            self::QURAN_PARTIAL_TESTS, self::QURAN_FINAL_TESTS => ['test_status', 'passed_on'],
            self::ASSESSMENTS => ['assessment_title', 'scheduled_at', 'due_at', 'total_mark', 'pass_mark', 'status', 'results_count', 'passed_results_count', 'failed_results_count', 'average_score'],
            self::ASSESSMENT_RESULTS => ['score', 'result_status', 'attempt_number'],
            self::TEACHERS => ['full_name', 'teacher_status', 'hired_at'],
            self::FINANCE_TRANSACTIONS => ['transaction_date', 'transaction_number', 'transaction_type', 'amount', 'signed_amount', 'local_amount'],
        };

        return collect($this->fields($source))->only($sortable)->all();
    }

    public function statusFilters(string $source): array
    {
        $this->fields($source);

        return match ($source) {
            self::STUDENT_ATTENDANCE => [
                'all' => __('report_designer.attendance_filters.all'),
                'present' => __('report_designer.attendance_filters.present'),
                'not_present' => __('report_designer.attendance_filters.not_present'),
            ],
            self::MEMORIZATION_SESSIONS => [
                'all' => __('report_designer.entry_filters.all'),
                'new' => __('report_designer.entry_types.new'),
                'review' => __('report_designer.entry_types.review'),
                'correction' => __('report_designer.entry_types.correction'),
            ],
            self::QURAN_TESTS => [
                'all' => __('report_designer.test_filters.all'),
                'passed' => __('report_designer.test_statuses.passed'),
                'failed' => __('report_designer.test_statuses.failed'),
                'cancelled' => __('report_designer.test_statuses.cancelled'),
            ],
            self::QURAN_PARTIAL_TESTS, self::QURAN_FINAL_TESTS => [
                'all' => __('report_designer.test_filters.all'),
                'passed' => __('report_designer.test_statuses.passed'),
                'in_progress' => __('report_designer.test_statuses.in_progress'),
            ],
            self::ASSESSMENT_RESULTS => [
                'all' => __('report_designer.assessment_filters.all'),
                'passed' => __('report_designer.assessment_statuses.passed'),
                'failed' => __('report_designer.assessment_statuses.failed'),
                'absent' => __('report_designer.assessment_statuses.absent'),
                'pending' => __('report_designer.assessment_statuses.pending'),
            ],
            self::TEACHERS => [
                'all' => __('report_designer.teacher_filters.all'),
                'active' => __('report_designer.teacher_statuses.active'),
                'inactive' => __('report_designer.teacher_statuses.inactive'),
                'pending' => __('report_designer.teacher_statuses.pending'),
                'blocked' => __('report_designer.teacher_statuses.blocked'),
                'declined' => __('report_designer.teacher_statuses.declined'),
            ],
            self::FINANCE_TRANSACTIONS => [
                'all' => __('report_designer.finance_filters.all'),
                'income' => __('report_designer.finance_transaction_types.income'),
                'expense' => __('report_designer.finance_transaction_types.expense'),
                'return' => __('report_designer.finance_transaction_types.return'),
                'exchange' => __('report_designer.finance_transaction_types.exchange'),
                'transfer' => __('report_designer.finance_transaction_types.transfer'),
            ],
            default => [
                'all' => __('report_designer.filter_statuses.all'),
                'active' => __('report_designer.filter_statuses.active'),
                'inactive' => __('report_designer.filter_statuses.inactive'),
            ],
        };
    }

    public function validateFields(string $source, array $fields): array
    {
        $allowed = array_keys($this->fields($source));
        $normalized = collect($fields)->map(fn ($field) => (string) $field)->unique()->values()->all();

        if ($normalized === [] || array_diff($normalized, $allowed) !== []) {
            throw ValidationException::withMessages([
                'selectedFields' => __('report_designer.validation.invalid_fields'),
            ]);
        }

        return array_values(array_intersect($allowed, $normalized));
    }

    public function validateSort(string $source, ?string $field, string $direction): array
    {
        $field = filled($field) ? $field : null;
        $direction = strtolower($direction);

        if (($field !== null && ! array_key_exists($field, $this->sortableFields($source)))
            || ! in_array($direction, ['asc', 'desc'], true)) {
            throw ValidationException::withMessages([
                'sortField' => __('report_designer.validation.invalid_sort'),
            ]);
        }

        return [$field, $direction];
    }

    public function calculationOperations(): array
    {
        return [
            'count' => __('report_designer.calculation_operations.count'),
            'sum' => __('report_designer.calculation_operations.sum'),
            'absolute_sum' => __('report_designer.calculation_operations.absolute_sum'),
            'avg' => __('report_designer.calculation_operations.avg'),
            'min' => __('report_designer.calculation_operations.min'),
            'max' => __('report_designer.calculation_operations.max'),
        ];
    }

    public function calculableFields(string $source): array
    {
        $fieldKeys = match ($source) {
            self::STUDENTS => ['points_balance', 'memorized_pages'],
            self::COURSES => ['groups_count', 'active_groups_count', 'active_enrollments_count'],
            self::GROUPS => ['capacity', 'active_enrollments_count', 'available_places', 'curriculum_completed_lessons', 'curriculum_total_lessons', 'curriculum_progress_percentage'],
            self::MEMORIZATION_SESSIONS => ['from_page', 'to_page', 'pages_count'],
            self::QURAN_TESTS => ['score', 'attempt_number'],
            self::QURAN_PARTIAL_TESTS => ['passed_parts_count', 'parts_count', 'latest_mistake_count', 'attempts_count', 'latest_score'],
            self::QURAN_FINAL_TESTS => ['attempts_count', 'latest_score'],
            self::ASSESSMENTS => ['total_mark', 'pass_mark', 'results_count', 'passed_results_count', 'failed_results_count', 'average_score'],
            self::ASSESSMENT_RESULTS => ['score', 'attempt_number'],
            self::TEACHERS => ['assigned_groups_count', 'assisted_groups_count', 'active_groups_count', 'active_enrollments_count'],
            self::FINANCE_TRANSACTIONS => ['amount', 'signed_amount', 'local_amount'],
            default => [],
        };

        return collect($this->fields($source))->only($fieldKeys)->all();
    }

    public function groupableFields(string $source): array
    {
        $fieldKeys = match ($source) {
            self::STUDENTS => ['student_identity', 'status', 'grade_level', 'current_group'],
            self::COURSES => ['academic_year', 'status'],
            self::GROUPS => ['group_name', 'course_name', 'academic_year', 'teacher_name', 'assistant_teacher_name', 'grade_level', 'status'],
            self::STUDENT_ATTENDANCE => ['attendance_date', 'attendance_status', 'presence_result', 'attendance_scope', 'course_name', 'group_name'],
            self::MEMORIZATION_SESSIONS => ['recorded_on', 'student_identity', 'entry_type', 'teacher_name', 'course_name', 'group_name'],
            self::QURAN_TESTS => ['tested_on', 'test_type', 'juz_number', 'test_status', 'teacher_name', 'course_name', 'group_name'],
            self::QURAN_PARTIAL_TESTS, self::QURAN_FINAL_TESTS => ['juz_number', 'test_status', 'latest_attempt_status', 'teacher_name', 'course_name', 'group_name'],
            self::ASSESSMENTS => ['due_at', 'assessment_type', 'assessment_groups', 'status'],
            self::ASSESSMENT_RESULTS => ['due_at', 'assessment_type', 'result_status', 'teacher_name', 'course_name', 'group_name'],
            self::TEACHERS => ['teacher_status', 'job_title', 'is_helping', 'assigned_courses'],
            self::FINANCE_TRANSACTIONS => ['transaction_date', 'transaction_type', 'transaction_direction', 'finance_category', 'cash_box', 'currency'],
            default => [],
        };

        $fields = collect($this->fields($source))->only($fieldKeys)->all();

        if ($source === self::FINANCE_TRANSACTIONS) {
            $fields['transaction_quarter'] = $this->field('transaction_quarter', 'date');
        }

        return $fields;
    }

    public function chronologicalGroupFields(string $source): array
    {
        return match ($source) {
            self::STUDENT_ATTENDANCE => ['attendance_date'],
            self::MEMORIZATION_SESSIONS => ['recorded_on'],
            self::QURAN_TESTS => ['tested_on'],
            self::ASSESSMENTS, self::ASSESSMENT_RESULTS => ['due_at'],
            self::FINANCE_TRANSACTIONS => ['transaction_date', 'transaction_quarter'],
            default => [],
        };
    }

    public function validateGrouping(string $source, ?string $field): ?string
    {
        $field = filled($field) ? (string) $field : null;

        if ($field !== null && ! array_key_exists($field, $this->groupableFields($source))) {
            throw ValidationException::withMessages([
                'groupBy' => __('report_designer.validation.invalid_grouping'),
            ]);
        }

        return $field;
    }

    public function requiredModulesForDefinition(string $source, array $fields): array
    {
        $modules = $this->requiredModules($source);
        if ($source === self::GROUPS && array_intersect($fields, [
            'curriculum_name',
            'curriculum_completed_lessons',
            'curriculum_total_lessons',
            'curriculum_progress_percentage',
        ]) !== []) {
            $modules[] = 'curriculum';
        }
        if ($source === self::STUDENTS && in_array('points_balance', $fields, true)) {
            $modules[] = 'points_rewards';
        }
        if ($source === self::STUDENTS && in_array('memorized_pages', $fields, true)) {
            $modules[] = 'memorization';
        }

        return array_values(array_unique($modules));
    }

    public function validateCalculations(string $source, array $calculations): array
    {
        if (count($calculations) > self::CALCULATION_LIMIT) {
            throw ValidationException::withMessages([
                'calculations' => __('report_designer.validation.too_many_calculations', ['count' => self::CALCULATION_LIMIT]),
            ]);
        }

        $operations = array_keys($this->calculationOperations());
        $fields = array_keys($this->calculableFields($source));
        $normalized = collect($calculations)->map(function ($calculation, int $index) use ($fields, $operations): array {
            $operation = (string) data_get($calculation, 'operation', '');
            $field = filled(data_get($calculation, 'field')) ? (string) data_get($calculation, 'field') : null;

            if (! in_array($operation, $operations, true)
                || ($operation === 'count' && $field !== null)
                || ($operation !== 'count' && ! in_array($field, $fields, true))) {
                throw ValidationException::withMessages([
                    "calculations.$index" => __('report_designer.validation.invalid_calculation'),
                ]);
            }

            return ['operation' => $operation, 'field' => $field];
        })->unique(fn (array $calculation) => $calculation['operation'].':'.($calculation['field'] ?? 'records'))->values();

        if ($normalized->count() !== count($calculations)) {
            throw ValidationException::withMessages([
                'calculations' => __('report_designer.validation.duplicate_calculation'),
            ]);
        }

        return $normalized->all();
    }

    public function calculationLabel(string $source, string $operation, ?string $field): string
    {
        if ($operation === 'count') {
            return __('report_designer.calculations.record_count');
        }

        return __('report_designer.calculations.field', [
            'operation' => $this->calculationOperations()[$operation],
            'field' => $this->calculableFields($source)[$field]['label'],
        ]);
    }

    private function field(string $key, string $type): array
    {
        return [
            'label' => __('report_designer.fields.'.$key),
            'type' => $type,
        ];
    }

    private function classContextFields(): array
    {
        return $this->modules->enabled('classes')
            ? [
                'course_name' => $this->field('course_name', 'text'),
                'group_name' => $this->field('group_name', 'text'),
            ]
            : [];
    }

    private function quranWorkflowFields(): array
    {
        return array_merge([
            'student_number' => $this->field('student_number', 'text'),
            'full_name' => $this->field('full_name', 'text'),
            'juz_number' => $this->field('juz_number', 'number'),
            'test_status' => $this->field('test_status', 'status'),
            'attempts_count' => $this->field('attempts_count', 'number'),
            'latest_tested_on' => $this->field('latest_tested_on', 'date'),
            'latest_score' => $this->field('latest_score', 'number'),
            'latest_attempt_status' => $this->field('latest_attempt_status', 'status'),
            'teacher_name' => $this->field('teacher_name', 'text'),
            'passed_on' => $this->field('passed_on', 'date'),
        ], $this->classContextFields(), [
            'latest_notes' => $this->field('latest_notes', 'text'),
        ]);
    }

    private function teacherWorkloadFields(): array
    {
        return $this->modules->enabled('classes')
            ? [
                'assigned_groups_count' => $this->field('assigned_groups_count', 'number'),
                'assisted_groups_count' => $this->field('assisted_groups_count', 'number'),
                'active_groups_count' => $this->field('active_groups_count', 'number'),
                'active_enrollments_count' => $this->field('active_enrollments_count', 'number'),
                'assigned_groups' => $this->field('assigned_groups', 'text'),
                'assigned_courses' => $this->field('assigned_courses', 'text'),
            ]
            : [];
    }
}
