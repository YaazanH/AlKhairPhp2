<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class ReportRelationshipCatalog
{
    public const MODE_SUMMARY = 'summary';

    public const MODE_DETAILED = 'detailed';

    public function __construct(private readonly ReportDesignerCatalog $catalog) {}

    /** @return array<string, array{label: string, description: string, cardinality: string, cardinality_key: string, fields: string[]}> */
    public function available(string $source): array
    {
        $availableFields = array_keys($this->catalog->fields($source));

        return collect($this->definitions()[$source] ?? [])
            ->map(function (array $relationship, string $key) use ($availableFields): array {
                $fields = array_values(array_intersect($relationship['fields'], $availableFields));

                return [
                    'label' => __('report_designer.relationships.items.'.$key.'.label'),
                    'description' => __('report_designer.relationships.items.'.$key.'.description'),
                    'cardinality' => __('report_designer.relationships.cardinality.'.$relationship['cardinality']),
                    'cardinality_key' => $relationship['cardinality'],
                    'fields' => $fields,
                ];
            })
            ->filter(fn (array $relationship): bool => $relationship['fields'] !== [])
            ->all();
    }

    public function infer(string $source, array $selectedFields): array
    {
        return collect($this->available($source))
            ->filter(fn (array $relationship): bool => array_intersect($relationship['fields'], $selectedFields) !== [])
            ->keys()
            ->values()
            ->all();
    }

    public function validate(string $source, mixed $relationships, array $selectedFields): array
    {
        $available = $this->available($source);
        $normalized = collect(is_array($relationships) ? $relationships : [])
            ->map(fn ($relationship): string => (string) $relationship)
            ->unique()
            ->values();

        if ($normalized->contains(fn (string $relationship): bool => ! array_key_exists($relationship, $available))) {
            throw ValidationException::withMessages([
                'relationships' => __('report_designer.relationships.validation.invalid'),
            ]);
        }

        $allowedFields = $this->queryFieldKeys($source, $normalized->all());

        if (array_diff($selectedFields, array_unique($allowedFields)) !== []) {
            throw ValidationException::withMessages([
                'relationships' => __('report_designer.relationships.validation.missing'),
            ]);
        }

        return $normalized->all();
    }

    public function resolve(string $source, mixed $relationships, array $selectedFields): array
    {
        $relationships ??= $this->infer($source, $selectedFields);

        return $this->validate($source, $relationships, $selectedFields);
    }

    public function validateModes(string $source, array $relationships, mixed $modes): array
    {
        $available = $this->available($source);
        $manyRelationships = collect($relationships)
            ->filter(fn (string $relationship): bool => data_get($available, $relationship.'.cardinality_key') === 'many')
            ->values();
        $normalized = collect(is_array($modes) ? $modes : [])
            ->mapWithKeys(fn ($mode, $relationship): array => [(string) $relationship => (string) $mode]);

        if ($normalized->keys()->contains(fn (string $relationship): bool => ! $manyRelationships->contains($relationship))
            || $normalized->contains(fn (string $mode): bool => ! in_array($mode, [self::MODE_SUMMARY, self::MODE_DETAILED], true))) {
            throw ValidationException::withMessages([
                'relationshipModes' => __('report_designer.relationships.validation.invalid_mode'),
            ]);
        }

        $resolved = $manyRelationships
            ->mapWithKeys(fn (string $relationship): array => [
                $relationship => $normalized->get($relationship, self::MODE_SUMMARY),
            ]);

        if ($resolved->where(fn (string $mode): bool => $mode === self::MODE_DETAILED)->count() > 1) {
            throw ValidationException::withMessages([
                'relationshipModes' => __('report_designer.relationships.validation.one_detailed'),
            ]);
        }

        return $resolved->all();
    }

    public function hasDetailedMode(array $modes, string $relationship): bool
    {
        return ($modes[$relationship] ?? self::MODE_SUMMARY) === self::MODE_DETAILED;
    }

    public function fields(string $source, array $relationships): array
    {
        $fields = $this->primaryFields($source);
        $available = $this->available($source);

        foreach ($relationships as $relationship) {
            foreach ($available[$relationship]['fields'] ?? [] as $field) {
                $fields[$field] = $this->catalog->fields($source)[$field];
            }
        }

        return $fields;
    }

    public function queryFieldKeys(string $source, array $relationships): array
    {
        $fields = $this->nonRelationshipFieldKeys($source);
        $available = $this->available($source);

        foreach ($relationships as $relationship) {
            $fields = array_merge($fields, $available[$relationship]['fields'] ?? []);
        }

        return array_values(array_unique($fields));
    }

    /** @return array<int, array{key: string, label: string, fields: array}> */
    public function fieldGroups(string $source, array $relationships): array
    {
        $groups = [[
            'key' => 'primary',
            'label' => __('report_designer.relationships.primary_fields'),
            'fields' => $this->primaryFields($source),
        ]];
        $available = $this->available($source);

        foreach ($relationships as $relationship) {
            if (! isset($available[$relationship])) {
                continue;
            }

            $groups[] = [
                'key' => $relationship,
                'label' => $available[$relationship]['label'],
                'fields' => collect($this->catalog->fields($source))->only($available[$relationship]['fields'])->all(),
            ];
        }

        return $groups;
    }

    public function labels(string $source, array $relationships, array $modes = []): array
    {
        $available = $this->available($source);

        return collect($relationships)
            ->map(function (string $relationship) use ($available, $modes): ?string {
                $label = $available[$relationship]['label'] ?? null;
                if ($label === null || data_get($available, $relationship.'.cardinality_key') !== 'many') {
                    return $label;
                }

                return __('report_designer.relationships.label_with_mode', [
                    'relationship' => $label,
                    'mode' => __('report_designer.relationships.modes.'.($modes[$relationship] ?? self::MODE_SUMMARY).'.short'),
                ]);
            })
            ->filter()
            ->values()
            ->all();
    }

    public function usedFields(array $definition): array
    {
        return collect($definition['selected_fields'] ?? [])
            ->merge(collect($definition['calculations'] ?? [])->pluck('field')->filter())
            ->when(filled($definition['group_by'] ?? null), fn ($fields) => $fields->push($definition['group_by']))
            ->when(filled($definition['sort_field'] ?? null), fn ($fields) => $fields->push($definition['sort_field']))
            ->merge(collect(data_get($definition, 'filters.condition_tree.groups', []))
                ->flatMap(fn (array $group): array => collect($group['conditions'] ?? [])->pluck('field')->filter()->all()))
            ->map(fn ($field): string => (string) $field)
            ->unique()
            ->values()
            ->all();
    }

    private function primaryFields(string $source): array
    {
        $related = collect($this->available($source))->flatMap(fn (array $relationship): array => $relationship['fields'])->unique()->all();

        return collect($this->catalog->fields($source))->except($related)->all();
    }

    private function nonRelationshipFieldKeys(string $source): array
    {
        $related = collect($this->available($source))
            ->flatMap(fn (array $relationship): array => $relationship['fields'])
            ->unique();

        return collect([
            ...array_keys($this->catalog->fields($source)),
            ...array_keys($this->catalog->groupableFields($source)),
            ...array_keys($this->catalog->sortableFields($source)),
            ...array_keys($this->catalog->calculableFields($source)),
        ])->unique()->diff($related)->values()->all();
    }

    private function definitions(): array
    {
        return [
            ReportDesignerCatalog::STUDENTS => [
                'student_grade' => ['cardinality' => 'one', 'fields' => ['grade_level']],
                'student_active_enrollment' => ['cardinality' => 'one', 'fields' => ['current_group', 'points_balance', 'memorized_pages']],
            ],
            ReportDesignerCatalog::COURSES => [
                'course_academic_year' => ['cardinality' => 'one', 'fields' => ['academic_year']],
                'course_groups_summary' => ['cardinality' => 'summary', 'fields' => ['groups_count', 'active_groups_count', 'active_enrollments_count']],
            ],
            ReportDesignerCatalog::GROUPS => [
                'group_course' => ['cardinality' => 'one', 'fields' => ['course_name']],
                'group_academic_year' => ['cardinality' => 'one', 'fields' => ['academic_year']],
                'group_teachers' => ['cardinality' => 'one', 'fields' => ['teacher_name', 'assistant_teacher_name']],
                'group_grade' => ['cardinality' => 'one', 'fields' => ['grade_level']],
                'group_enrollments_summary' => ['cardinality' => 'summary', 'fields' => ['active_enrollments_count', 'available_places']],
                'group_curriculum_summary' => ['cardinality' => 'summary', 'fields' => ['curriculum_name', 'curriculum_completed_lessons', 'curriculum_total_lessons', 'curriculum_progress_percentage']],
            ],
            ReportDesignerCatalog::STUDENT_ATTENDANCE => [
                'attendance_student' => ['cardinality' => 'one', 'fields' => ['student_number', 'full_name']],
                'attendance_status' => ['cardinality' => 'one', 'fields' => ['attendance_status', 'presence_result']],
                'attendance_context' => ['cardinality' => 'one', 'fields' => ['attendance_date', 'attendance_scope', 'course_name', 'group_name']],
            ],
            ReportDesignerCatalog::MEMORIZATION_SESSIONS => [
                'memorization_student' => ['cardinality' => 'one', 'fields' => ['student_number', 'full_name', 'student_identity']],
                'memorization_teacher' => ['cardinality' => 'one', 'fields' => ['teacher_name']],
                'memorization_group' => ['cardinality' => 'one', 'fields' => ['course_name', 'group_name']],
            ],
            ReportDesignerCatalog::QURAN_TESTS => [
                'quran_test_student' => ['cardinality' => 'one', 'fields' => ['student_number', 'full_name']],
                'quran_test_type' => ['cardinality' => 'one', 'fields' => ['test_type']],
                'quran_test_juz' => ['cardinality' => 'one', 'fields' => ['juz_number']],
                'quran_test_teacher' => ['cardinality' => 'one', 'fields' => ['teacher_name']],
                'quran_test_group' => ['cardinality' => 'one', 'fields' => ['course_name', 'group_name']],
            ],
            ReportDesignerCatalog::QURAN_PARTIAL_TESTS => $this->quranWorkflowRelationships('partial'),
            ReportDesignerCatalog::QURAN_FINAL_TESTS => $this->quranWorkflowRelationships('final'),
            ReportDesignerCatalog::ASSESSMENTS => [
                'assessment_type' => ['cardinality' => 'one', 'fields' => ['assessment_type']],
                'assessment_groups' => ['cardinality' => 'many', 'fields' => ['assessment_groups']],
                'assessment_results_summary' => ['cardinality' => 'summary', 'fields' => ['results_count', 'passed_results_count', 'failed_results_count', 'average_score']],
            ],
            ReportDesignerCatalog::ASSESSMENT_RESULTS => [
                'result_assessment' => ['cardinality' => 'one', 'fields' => ['due_at', 'assessment_title', 'assessment_type']],
                'result_student' => ['cardinality' => 'one', 'fields' => ['student_number', 'full_name']],
                'result_teacher' => ['cardinality' => 'one', 'fields' => ['teacher_name']],
                'result_group' => ['cardinality' => 'one', 'fields' => ['course_name', 'group_name']],
            ],
            ReportDesignerCatalog::TEACHERS => [
                'teacher_job_title' => ['cardinality' => 'one', 'fields' => ['job_title']],
                'teacher_groups_summary' => ['cardinality' => 'summary', 'fields' => ['is_helping', 'assigned_groups_count', 'assisted_groups_count', 'active_groups_count', 'active_enrollments_count', 'assigned_groups', 'assigned_courses']],
            ],
            ReportDesignerCatalog::FINANCE_TRANSACTIONS => [
                'finance_category' => ['cardinality' => 'one', 'fields' => ['finance_category']],
                'finance_cash_box' => ['cardinality' => 'one', 'fields' => ['cash_box']],
                'finance_currency' => ['cardinality' => 'one', 'fields' => ['currency']],
                'finance_entered_by' => ['cardinality' => 'one', 'fields' => ['entered_by']],
            ],
        ];
    }

    private function quranWorkflowRelationships(string $prefix): array
    {
        return [
            $prefix.'_workflow_student' => ['cardinality' => 'one', 'fields' => ['student_number', 'full_name']],
            $prefix.'_workflow_juz' => ['cardinality' => 'one', 'fields' => ['juz_number']],
            $prefix.'_workflow_attempts' => ['cardinality' => 'summary', 'fields' => ['attempts_count', 'latest_tested_on', 'latest_score', 'latest_attempt_status', 'teacher_name', 'latest_notes']],
            $prefix.'_workflow_group' => ['cardinality' => 'one', 'fields' => ['course_name', 'group_name']],
        ];
    }
}
