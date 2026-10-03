<?php

namespace App\Services;

use App\Services\Landlord\CurrentModuleAccess;
use Illuminate\Validation\ValidationException;

class ReportDesignerCatalog
{
    public const STUDENTS = 'students';

    public const COURSES = 'courses';

    public const GROUPS = 'groups';

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
            default => abort(404),
        };
    }

    public function defaultFields(string $source): array
    {
        $this->fields($source);

        return match ($source) {
            self::STUDENTS => ['student_number', 'full_name', 'status', 'current_group'],
            self::COURSES => ['course_name', 'academic_year', 'status', 'groups_count', 'active_enrollments_count'],
            self::GROUPS => ['group_name', 'course_name', 'teacher_name', 'capacity', 'active_enrollments_count', 'available_places', 'status'],
        };
    }

    public function sortableFields(string $source): array
    {
        $sortable = match ($source) {
            self::STUDENTS => ['student_number', 'full_name', 'status', 'joined_at', 'birth_date'],
            self::COURSES => ['course_name', 'status', 'starts_on', 'ends_on', 'groups_count', 'active_groups_count', 'active_enrollments_count'],
            self::GROUPS => ['group_name', 'status', 'starts_on', 'ends_on', 'capacity', 'active_enrollments_count'],
        };

        return collect($this->fields($source))->only($sortable)->all();
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
}
