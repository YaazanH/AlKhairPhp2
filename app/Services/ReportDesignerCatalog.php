<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class ReportDesignerCatalog
{
    public const STUDENTS = 'students';

    public function sources(): array
    {
        return [
            self::STUDENTS => [
                'label' => __('report_designer.sources.students.label'),
                'description' => __('report_designer.sources.students.description'),
            ],
        ];
    }

    public function fields(string $source): array
    {
        abort_unless($source === self::STUDENTS, 404);

        return [
            'student_number' => $this->field('student_number', 'text'),
            'full_name' => $this->field('full_name', 'text'),
            'status' => $this->field('status', 'status'),
            'joined_at' => $this->field('joined_at', 'date'),
            'birth_date' => $this->field('birth_date', 'date'),
            'grade_level' => $this->field('grade_level', 'text'),
            'current_group' => $this->field('current_group', 'text'),
        ];
    }

    public function defaultFields(string $source): array
    {
        $this->fields($source);

        return ['student_number', 'full_name', 'status', 'current_group'];
    }

    public function sortableFields(string $source): array
    {
        return collect($this->fields($source))
            ->only(['student_number', 'full_name', 'status', 'joined_at', 'birth_date'])
            ->all();
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
