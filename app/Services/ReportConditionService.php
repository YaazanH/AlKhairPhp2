<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class ReportConditionService
{
    public const GROUP_LIMIT = 5;

    public const CONDITION_LIMIT = 20;

    public function __construct(private readonly ReportDesignerCatalog $catalog) {}

    public function emptyTree(): array
    {
        return ['operator' => 'and', 'groups' => [['operator' => 'and', 'conditions' => []]]];
    }

    public function editorTree(mixed $tree): array
    {
        if (! is_array($tree) || ! isset($tree['groups']) || ! is_array($tree['groups'])) {
            return $this->emptyTree();
        }

        return [
            'operator' => in_array($tree['operator'] ?? null, ['and', 'or'], true) ? $tree['operator'] : 'and',
            'groups' => collect($tree['groups'])->map(fn ($group): array => [
                'operator' => in_array(data_get($group, 'operator'), ['and', 'or'], true) ? data_get($group, 'operator') : 'and',
                'conditions' => array_values(is_array(data_get($group, 'conditions')) ? data_get($group, 'conditions') : []),
            ])->values()->all() ?: $this->emptyTree()['groups'],
        ];
    }

    public function fields(string $source): array
    {
        $definitions = $this->definitions()[$source] ?? [];
        $fields = $this->catalog->fields($source);

        return collect($definitions)
            ->filter(fn (array $definition, string $key): bool => isset($fields[$key]))
            ->mapWithKeys(function (array $definition, string $key) use ($fields, $source): array {
                $type = $fields[$key]['type'];
                $options = $definition['options'] ?? $this->statusOptions($source, $type);

                return [$key => $fields[$key] + [
                    'operators' => $this->operators($type, $options !== []),
                    'options' => $options,
                ]];
            })->all();
    }

    public function operators(string $type, bool $hasOptions = false): array
    {
        if ($hasOptions || $type === 'status') {
            return [
                'equals' => __('report_designer.conditions.operators.equals'),
                'not_equals' => __('report_designer.conditions.operators.not_equals'),
                'is_empty' => __('report_designer.conditions.operators.is_empty'),
                'is_not_empty' => __('report_designer.conditions.operators.is_not_empty'),
            ];
        }

        return match ($type) {
            'number' => [
                'equals' => __('report_designer.conditions.operators.equals'),
                'not_equals' => __('report_designer.conditions.operators.not_equals'),
                'greater_than' => __('report_designer.conditions.operators.greater_than'),
                'greater_or_equal' => __('report_designer.conditions.operators.greater_or_equal'),
                'less_than' => __('report_designer.conditions.operators.less_than'),
                'less_or_equal' => __('report_designer.conditions.operators.less_or_equal'),
                'between' => __('report_designer.conditions.operators.between'),
                'is_empty' => __('report_designer.conditions.operators.is_empty'),
                'is_not_empty' => __('report_designer.conditions.operators.is_not_empty'),
            ],
            'date' => [
                'on' => __('report_designer.conditions.operators.on'),
                'before' => __('report_designer.conditions.operators.before'),
                'after' => __('report_designer.conditions.operators.after'),
                'between' => __('report_designer.conditions.operators.between'),
                'is_empty' => __('report_designer.conditions.operators.is_empty'),
                'is_not_empty' => __('report_designer.conditions.operators.is_not_empty'),
            ],
            default => [
                'equals' => __('report_designer.conditions.operators.equals'),
                'not_equals' => __('report_designer.conditions.operators.not_equals'),
                'contains' => __('report_designer.conditions.operators.contains'),
                'starts_with' => __('report_designer.conditions.operators.starts_with'),
                'is_empty' => __('report_designer.conditions.operators.is_empty'),
                'is_not_empty' => __('report_designer.conditions.operators.is_not_empty'),
            ],
        };
    }

    public function validate(string $source, mixed $tree): array
    {
        $tree = $this->editorTree($tree);
        $fields = $this->fields($source);
        $groups = $tree['groups'];

        if (count($groups) > self::GROUP_LIMIT || collect($groups)->sum(fn (array $group): int => count($group['conditions'])) > self::CONDITION_LIMIT) {
            throw ValidationException::withMessages([
                'conditionTree' => __('report_designer.conditions.validation.limit', ['count' => self::CONDITION_LIMIT]),
            ]);
        }

        $normalizedGroups = [];
        foreach ($groups as $groupIndex => $group) {
            $conditions = [];
            foreach ($group['conditions'] as $conditionIndex => $condition) {
                $field = (string) data_get($condition, 'field', '');
                $operator = (string) data_get($condition, 'operator', '');
                $fieldDefinition = $fields[$field] ?? null;

                if (! $fieldDefinition || ! array_key_exists($operator, $fieldDefinition['operators'])) {
                    throw ValidationException::withMessages([
                        "conditionTree.groups.$groupIndex.conditions.$conditionIndex" => __('report_designer.conditions.validation.invalid'),
                    ]);
                }

                $value = data_get($condition, 'value');
                $valueTo = data_get($condition, 'value_to');
                if (! in_array($operator, ['is_empty', 'is_not_empty'], true)) {
                    $value = $this->normalizeValue($value, $fieldDefinition, $groupIndex, $conditionIndex);
                } else {
                    $value = null;
                }

                if ($operator === 'between') {
                    $valueTo = $this->normalizeValue($valueTo, $fieldDefinition, $groupIndex, $conditionIndex);
                } else {
                    $valueTo = null;
                }

                $conditions[] = [
                    'field' => $field,
                    'operator' => $operator,
                    'value' => $value,
                    'value_to' => $valueTo,
                ];
            }

            if ($conditions !== []) {
                $normalizedGroups[] = ['operator' => $group['operator'], 'conditions' => $conditions];
            }
        }

        return ['operator' => $tree['operator'], 'groups' => $normalizedGroups];
    }

    public function apply(Builder $query, string $source, mixed $tree): void
    {
        $tree = $this->validate($source, $tree);
        if ($tree['groups'] === []) {
            return;
        }

        $fieldCatalog = $this->fields($source);
        $definitions = collect($this->definitions()[$source])
            ->mapWithKeys(fn (array $definition, string $field): array => [
                $field => $definition + ['type' => $fieldCatalog[$field]['type']],
            ])->all();
        $query->where(function (Builder $root) use ($tree, $definitions): void {
            foreach ($tree['groups'] as $groupIndex => $group) {
                $rootMethod = $groupIndex === 0 || $tree['operator'] === 'and' ? 'where' : 'orWhere';
                $root->{$rootMethod}(function (Builder $nested) use ($group, $definitions): void {
                    foreach ($group['conditions'] as $conditionIndex => $condition) {
                        $method = $conditionIndex === 0 || $group['operator'] === 'and' ? 'where' : 'orWhere';
                        $nested->{$method}(fn (Builder $leaf) => $this->applyLeaf($leaf, $definitions[$condition['field']], $condition));
                    }
                });
            }
        });
    }

    public function describe(string $source, mixed $tree): ?string
    {
        try {
            $tree = $this->validate($source, $tree);
        } catch (ValidationException) {
            return __('report_designer.conditions.incomplete');
        }

        if ($tree['groups'] === []) {
            return null;
        }

        $fields = $this->fields($source);
        $groups = collect($tree['groups'])->map(function (array $group) use ($fields): string {
            $parts = collect($group['conditions'])->map(function (array $condition) use ($fields): string {
                $field = $fields[$condition['field']];
                $value = $field['options'][$condition['value']] ?? $condition['value'];
                $operator = $field['operators'][$condition['operator']];
                $rendered = in_array($condition['operator'], ['is_empty', 'is_not_empty'], true)
                    ? __('report_designer.conditions.description.empty', ['field' => $field['label'], 'operator' => $operator])
                    : __('report_designer.conditions.description.value', ['field' => $field['label'], 'operator' => $operator, 'value' => $value]);

                if ($condition['operator'] === 'between') {
                    $rendered = __('report_designer.conditions.description.between', [
                        'field' => $field['label'],
                        'from' => $value,
                        'to' => $condition['value_to'],
                    ]);
                }

                return $rendered;
            })->all();

            return '('.implode(' '.__('report_designer.conditions.logic.'.$group['operator']).' ', $parts).')';
        })->all();

        return implode(' '.__('report_designer.conditions.logic.'.$tree['operator']).' ', $groups);
    }

    private function normalizeValue(mixed $value, array $field, int $groupIndex, int $conditionIndex): string|float
    {
        $errorKey = "conditionTree.groups.$groupIndex.conditions.$conditionIndex.value";
        if ($field['options'] !== []) {
            if (! array_key_exists((string) $value, $field['options'])) {
                throw ValidationException::withMessages([$errorKey => __('report_designer.conditions.validation.value')]);
            }

            return (string) $value;
        }

        if ($field['type'] === 'number') {
            if (! is_numeric($value)) {
                throw ValidationException::withMessages([$errorKey => __('report_designer.conditions.validation.number')]);
            }

            return (float) $value;
        }

        $value = trim((string) $value);
        $validDate = true;
        if ($field['type'] === 'date') {
            $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            $validDate = $parsedDate !== false && $parsedDate->format('Y-m-d') === $value;
        }

        if ($value === '' || mb_strlen($value) > 255 || ! $validDate) {
            throw ValidationException::withMessages([$errorKey => __('report_designer.conditions.validation.value')]);
        }

        return $value;
    }

    private function applyLeaf(Builder $query, array $definition, array $condition): void
    {
        $column = $definition['column'];
        $operator = $condition['operator'];
        $value = $this->databaseValue($definition, $condition['value']);

        if (($definition['kind'] ?? null) === 'person_name') {
            $driver = $query->getQuery()->getConnection()->getDriverName();
            $expression = in_array($driver, ['sqlite', 'pgsql'], true)
                ? "TRIM(COALESCE({$definition['first']}, '') || ' ' || COALESCE({$definition['last']}, ''))"
                : "TRIM(CONCAT_WS(' ', {$definition['first']}, {$definition['last']}))";
            $this->applyRawOperator($query, $expression, $operator, $value);

            return;
        }

        if (($definition['type'] ?? null) === 'date') {
            match ($operator) {
                'on' => $query->whereDate($column, '=', $value),
                'before' => $query->whereDate($column, '<', $value),
                'after' => $query->whereDate($column, '>', $value),
                'between' => $query->whereDate($column, '>=', $value)
                    ->whereDate($column, '<=', $condition['value_to']),
                'is_empty' => $query->whereNull($column),
                'is_not_empty' => $query->whereNotNull($column),
            };

            return;
        }

        match ($operator) {
            'equals' => $query->where($column, '=', $value),
            'not_equals' => $query->where($column, '!=', $value),
            'contains' => $query->where($column, 'like', '%'.$value.'%'),
            'starts_with' => $query->where($column, 'like', $value.'%'),
            'greater_than' => $query->where($column, '>', $value),
            'greater_or_equal' => $query->where($column, '>=', $value),
            'less_than' => $query->where($column, '<', $value),
            'less_or_equal' => $query->where($column, '<=', $value),
            'between' => $query->whereBetween($column, [$value, $this->databaseValue($definition, $condition['value_to'])]),
            'is_empty' => $query->where(fn (Builder $empty) => $empty->whereNull($column)->orWhere($column, '')),
            'is_not_empty' => $query->whereNotNull($column)->where($column, '!=', ''),
        };
    }

    private function applyRawOperator(Builder $query, string $expression, string $operator, mixed $value): void
    {
        match ($operator) {
            'equals' => $query->whereRaw("$expression = ?", [$value]),
            'not_equals' => $query->whereRaw("$expression != ?", [$value]),
            'contains' => $query->whereRaw("$expression LIKE ?", ['%'.$value.'%']),
            'starts_with' => $query->whereRaw("$expression LIKE ?", [$value.'%']),
            'is_empty' => $query->whereRaw("$expression = ''"),
            'is_not_empty' => $query->whereRaw("$expression != ''"),
        };
    }

    private function databaseValue(array $definition, mixed $value): mixed
    {
        return ($definition['values'] ?? [])[$value] ?? $value;
    }

    private function statusOptions(string $source, string $type): array
    {
        if ($type !== 'status') {
            return [];
        }

        return Arr::except($this->catalog->statusFilters($source), ['all']);
    }

    private function definitions(): array
    {
        $active = [
            'options' => [
                'active' => __('report_designer.filter_statuses.active'),
                'inactive' => __('report_designer.filter_statuses.inactive'),
            ],
            'values' => ['active' => 1, 'inactive' => 0],
        ];
        $person = fn (string $first = 'first_name', string $last = 'last_name'): array => [
            'column' => $first,
            'kind' => 'person_name',
            'first' => $first,
            'last' => $last,
        ];

        return [
            ReportDesignerCatalog::STUDENTS => [
                'student_number' => ['column' => 'student_number'],
                'full_name' => $person(),
                'status' => ['column' => 'status'],
                'joined_at' => ['column' => 'joined_at'],
                'birth_date' => ['column' => 'birth_date'],
            ],
            ReportDesignerCatalog::COURSES => [
                'course_name' => ['column' => 'name'],
                'status' => ['column' => 'is_active'] + $active,
                'starts_on' => ['column' => 'starts_on'],
                'ends_on' => ['column' => 'ends_on'],
            ],
            ReportDesignerCatalog::GROUPS => [
                'group_name' => ['column' => 'name'],
                'capacity' => ['column' => 'capacity'],
                'status' => ['column' => 'is_active'] + $active,
                'starts_on' => ['column' => 'starts_on'],
                'ends_on' => ['column' => 'ends_on'],
            ],
            ReportDesignerCatalog::STUDENT_ATTENDANCE => [
                'notes' => ['column' => 'notes'],
            ],
            ReportDesignerCatalog::MEMORIZATION_SESSIONS => [
                'recorded_on' => ['column' => 'recorded_on'],
                'entry_type' => ['column' => 'entry_type'],
                'from_page' => ['column' => 'from_page'],
                'to_page' => ['column' => 'to_page'],
                'pages_count' => ['column' => 'pages_count'],
                'notes' => ['column' => 'notes'],
            ],
            ReportDesignerCatalog::QURAN_TESTS => [
                'tested_on' => ['column' => 'tested_on'],
                'test_status' => ['column' => 'status'],
                'score' => ['column' => 'score'],
                'attempt_number' => ['column' => 'attempt_no'],
                'notes' => ['column' => 'notes'],
            ],
            ReportDesignerCatalog::QURAN_PARTIAL_TESTS => [
                'test_status' => ['column' => 'status'],
                'passed_on' => ['column' => 'passed_on'],
            ],
            ReportDesignerCatalog::QURAN_FINAL_TESTS => [
                'test_status' => ['column' => 'status'],
                'passed_on' => ['column' => 'passed_on'],
            ],
            ReportDesignerCatalog::ASSESSMENTS => [
                'assessment_title' => ['column' => 'title'],
                'scheduled_at' => ['column' => 'scheduled_at'],
                'due_at' => ['column' => 'due_at'],
                'total_mark' => ['column' => 'total_mark'],
                'pass_mark' => ['column' => 'pass_mark'],
                'status' => ['column' => 'is_active'] + $active,
                'description' => ['column' => 'description'],
            ],
            ReportDesignerCatalog::ASSESSMENT_RESULTS => [
                'score' => ['column' => 'score'],
                'result_status' => ['column' => 'status'],
                'attempt_number' => ['column' => 'attempt_no'],
                'notes' => ['column' => 'notes'],
            ],
            ReportDesignerCatalog::TEACHERS => [
                'full_name' => $person(),
                'teacher_status' => ['column' => 'status'],
                'job_title' => ['column' => 'job_title'],
                'hired_at' => ['column' => 'hired_at'],
            ],
            ReportDesignerCatalog::FINANCE_TRANSACTIONS => [
                'transaction_date' => ['column' => 'transaction_date'],
                'transaction_number' => ['column' => 'transaction_no'],
                'transaction_type' => ['column' => 'type'],
                'transaction_direction' => [
                    'column' => 'direction',
                    'options' => [
                        'in' => __('report_designer.finance_directions.in'),
                        'out' => __('report_designer.finance_directions.out'),
                    ],
                ],
                'amount' => ['column' => 'amount'],
                'signed_amount' => ['column' => 'signed_amount'],
                'local_amount' => ['column' => 'local_amount'],
                'description' => ['column' => 'description'],
            ],
        ];
    }
}
