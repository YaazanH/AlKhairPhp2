<?php

namespace App\Services;

use App\Services\Landlord\CurrentModuleAccess;
use Illuminate\Validation\ValidationException;

class ReportDesignerCatalog
{
    public const STUDENTS = 'students';

    public const COURSES = 'courses';

    public const GROUPS = 'groups';

    public const STUDENT_ATTENDANCE = 'student_attendance';

    public const MEMORIZATION_SESSIONS = 'memorization_sessions';

    public const QURAN_TESTS = 'quran_tests';

    public function __construct(protected CurrentModuleAccess $modules) {}

    public function sources(): array
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
        }

        return $sources;
    }

    public function fields(string $source): array
    {
        return match ($source) {
            self::STUDENTS => [
                'student_number' => $this->field('student_number', 'text'),
                'full_name' => $this->field('full_name', 'text'),
                'status' => $this->field('status', 'status'),
                'joined_at' => $this->field('joined_at', 'date'),
                'birth_date' => $this->field('birth_date', 'date'),
                'grade_level' => $this->field('grade_level', 'text'),
                'current_group' => $this->field('current_group', 'text'),
            ],
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
            self::GROUPS => [
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
            ],
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
        };

        return array_values(array_intersect($defaults, array_keys($this->fields($source))));
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
}
