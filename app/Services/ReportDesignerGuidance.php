<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Throwable;

class ReportDesignerGuidance
{
    public function __construct(
        protected ReportDesignerCatalog $catalog,
        protected ReportConditionService $conditions,
        protected ReportRelationshipCatalog $relationships,
    ) {}

    /**
     * @return array{
     *     summary: string,
     *     sentence: string,
     *     flow: array<int, array{label: string, value: string}>,
     *     facts: array<int, array{label: string, value: string}>,
     *     badges: string[],
     *     warnings: string[]
     * }
     */
    public function build(array $definition, ?User $user): array
    {
        $source = (string) ($definition['data_source'] ?? '');
        $sourceDetails = $this->catalog->sources($user)[$source] ?? null;
        if (! $sourceDetails) {
            return [
                'summary' => '',
                'sentence' => '',
                'flow' => [],
                'facts' => [],
                'badges' => [],
                'warnings' => [],
            ];
        }

        $fields = $this->catalog->fields($source);
        $selectedFields = collect($definition['selected_fields'] ?? [])
            ->filter(fn ($field): bool => array_key_exists((string) $field, $fields))
            ->map(fn ($field): string => (string) $field)
            ->unique()
            ->values();
        $fieldLabels = $selectedFields->map(fn (string $field): string => $fields[$field]['label'])->all();
        $filters = (array) ($definition['filters'] ?? []);
        $status = (string) ($filters['status'] ?? 'all');
        $search = trim((string) ($filters['search'] ?? ''));
        $dateFrom = (string) ($filters['date_from'] ?? '');
        $dateTo = (string) ($filters['date_to'] ?? '');
        $groupBy = filled($definition['group_by'] ?? null) ? (string) $definition['group_by'] : null;
        $presentation = (string) data_get($definition, 'presentation.type', ReportDesignerCatalog::PRESENTATION_TABLE);
        $calculations = collect($definition['calculations'] ?? []);
        $usedFields = $this->relationships->usedFields($definition);
        try {
            $relationshipKeys = $this->relationships->resolve($source, $definition['relationships'] ?? null, $usedFields);
            $relationshipModes = $this->relationships->validateModes(
                $source,
                $relationshipKeys,
                $definition['relationship_modes'] ?? null,
            );
        } catch (ValidationException) {
            $relationshipKeys = $this->relationships->infer($source, $usedFields);
            $relationshipModes = $this->relationships->validateModes($source, $relationshipKeys, null);
        }
        $relationshipLabels = $this->relationships->labels($source, $relationshipKeys, $relationshipModes);
        $detailedRelationshipLabels = collect($relationshipModes)
            ->filter(fn (string $mode): bool => $mode === ReportRelationshipCatalog::MODE_DETAILED)
            ->keys()
            ->map(fn (string $relationship): ?string => data_get($this->relationships->available($source), $relationship.'.label'))
            ->filter()
            ->values()
            ->all();
        $conditionTree = $filters['condition_tree'] ?? [];
        $conditionSummary = $this->conditions->describe($source, $conditionTree);
        $filterSummary = $this->filterSummary($source, $status, $search, $dateFrom, $dateTo, $conditionSummary);
        $groupLabel = $groupBy !== null
            ? (string) data_get($this->catalog->groupableFields($source), $groupBy.'.label', $groupBy)
            : __('report_designer.guidance.values.no_grouping');
        $presentationLabel = (string) ($this->catalog->libraryPresentationTypes()[$presentation] ?? $presentation);
        $calculationLabels = $calculations
            ->map(function ($calculation) use ($source): ?string {
                $operation = (string) data_get($calculation, 'operation', '');
                $field = filled(data_get($calculation, 'field')) ? (string) data_get($calculation, 'field') : null;

                if (! array_key_exists($operation, $this->catalog->calculationOperations())) {
                    return null;
                }

                if ($operation !== 'count' && ($field === null || ! array_key_exists($field, $this->catalog->calculableFields($source)))) {
                    return null;
                }

                return $this->catalog->calculationLabel($source, $operation, $field);
            })
            ->filter()
            ->values()
            ->all();
        $sortLabel = $this->sortSummary(
            $source,
            filled($definition['sort_field'] ?? null) ? (string) $definition['sort_field'] : null,
            (string) ($definition['sort_direction'] ?? 'asc'),
        );

        $facts = [
            [
                'label' => __('report_designer.guidance.facts.fields'),
                'value' => $fieldLabels === []
                    ? __('report_designer.guidance.values.no_fields')
                    : $this->joinedLabels($fieldLabels),
            ],
            ['label' => __('report_designer.guidance.facts.filters'), 'value' => $filterSummary],
            [
                'label' => __('report_designer.guidance.facts.relationships'),
                'value' => $relationshipLabels === []
                    ? __('report_designer.guidance.values.no_relationships')
                    : $this->joinedLabels($relationshipLabels),
            ],
            [
                'label' => __('report_designer.guidance.facts.grouping'),
                'value' => $groupLabel,
            ],
            [
                'label' => __('report_designer.guidance.facts.presentation'),
                'value' => $presentationLabel,
            ],
            [
                'label' => __('report_designer.guidance.facts.calculations'),
                'value' => trans_choice('report_designer.guidance.values.calculation_count', $calculations->count(), [
                    'count' => $calculations->count(),
                ]),
            ],
            ['label' => __('report_designer.guidance.facts.sorting'), 'value' => $sortLabel],
        ];

        $flow = [
            [
                'label' => __('report_designer.guidance.flow.row_basis'),
                'value' => $detailedRelationshipLabels === []
                    ? __('report_designer.guidance.values.one_row_per', ['source' => $sourceDetails['label']])
                    : __('report_designer.guidance.values.one_row_per_related', [
                        'source' => $sourceDetails['label'],
                        'relationship' => $this->joinedLabels($detailedRelationshipLabels),
                    ]),
            ],
        ];

        if ($relationshipLabels !== []) {
            $flow[] = [
                'label' => __('report_designer.guidance.flow.relationships'),
                'value' => $this->joinedLabels($relationshipLabels),
            ];
        }

        $flow[] = [
            'label' => __('report_designer.guidance.flow.scope'),
            'value' => $filterSummary,
        ];

        if ($groupBy !== null || $calculationLabels !== []) {
            $flow[] = [
                'label' => __('report_designer.guidance.flow.summarize'),
                'value' => $this->summaryStep($groupLabel, $groupBy !== null, $calculationLabels),
            ];
        }

        $flow[] = [
            'label' => __('report_designer.guidance.flow.output'),
            'value' => __('report_designer.guidance.values.output_step', [
                'presentation' => $presentationLabel,
                'fields' => $fieldLabels === []
                    ? __('report_designer.guidance.values.no_fields')
                    : $this->joinedLabels($fieldLabels),
            ]),
        ];

        return [
            'summary' => __('report_designer.guidance.summary', [
                'source' => $sourceDetails['label'],
                'count' => trans_choice('report_designer.guidance.values.field_count', $selectedFields->count(), [
                    'count' => $selectedFields->count(),
                ]),
            ]),
            'sentence' => __('report_designer.guidance.'.($detailedRelationshipLabels === [] ? 'sentence' : 'sentence_detailed'), [
                'source' => $sourceDetails['label'],
                'relationship' => $this->joinedLabels($detailedRelationshipLabels),
                'fields' => $fieldLabels === []
                    ? __('report_designer.guidance.values.no_fields')
                    : $this->joinedLabels($fieldLabels),
                'scope' => $filterSummary,
                'sort' => $sortLabel,
            ]),
            'flow' => $flow,
            'facts' => $facts,
            'badges' => array_values(array_filter([
                $sourceDetails['label'],
                $relationshipLabels === [] ? null : trans_choice('report_designer.guidance.values.relationship_count', count($relationshipLabels), ['count' => count($relationshipLabels)]),
                trans_choice('report_designer.guidance.values.field_count', $selectedFields->count(), [
                    'count' => $selectedFields->count(),
                ]),
                $presentationLabel,
            ])),
            'warnings' => $this->warnings(
                $source,
                $selectedFields->count(),
                $status,
                $search,
                $dateFrom,
                $dateTo,
                $groupBy,
                $presentation,
                $calculations->all(),
                $conditionSummary !== null,
                $detailedRelationshipLabels !== [],
            ),
        ];
    }

    protected function summaryStep(string $groupLabel, bool $hasGrouping, array $calculationLabels): string
    {
        if ($hasGrouping && $calculationLabels !== []) {
            return __('report_designer.guidance.values.group_and_calculate', [
                'group' => $groupLabel,
                'calculations' => $this->joinedLabels($calculationLabels),
            ]);
        }

        if ($hasGrouping) {
            return __('report_designer.guidance.values.group_only', ['group' => $groupLabel]);
        }

        return __('report_designer.guidance.values.calculate_only', [
            'calculations' => $this->joinedLabels($calculationLabels),
        ]);
    }

    protected function sortSummary(string $source, ?string $field, string $direction): string
    {
        if ($field === null || ! array_key_exists($field, $this->catalog->sortableFields($source))) {
            return __('report_designer.guidance.values.default_sort');
        }

        return __('report_designer.guidance.values.sort_field', [
            'field' => $this->catalog->sortableFields($source)[$field]['label'],
            'direction' => __('report_designer.form.'.($direction === 'desc' ? 'descending' : 'ascending')),
        ]);
    }

    protected function filterSummary(string $source, string $status, string $search, string $dateFrom, string $dateTo, ?string $conditionSummary): string
    {
        $parts = [];
        $statuses = $this->catalog->statusFilters($source);
        if ($status !== 'all' && isset($statuses[$status])) {
            $parts[] = __('report_designer.guidance.values.status_filter', ['status' => $statuses[$status]]);
        }
        if ($search !== '') {
            $parts[] = __('report_designer.guidance.values.search_filter', ['search' => $search]);
        }
        if ($dateFrom !== '' && $dateTo !== '') {
            $parts[] = __('report_designer.guidance.values.date_range', ['from' => $dateFrom, 'to' => $dateTo]);
        } elseif ($dateFrom !== '') {
            $parts[] = __('report_designer.guidance.values.date_from', ['from' => $dateFrom]);
        } elseif ($dateTo !== '') {
            $parts[] = __('report_designer.guidance.values.date_to', ['to' => $dateTo]);
        }
        if ($conditionSummary !== null) {
            $parts[] = $conditionSummary;
        }

        return $parts === []
            ? __('report_designer.guidance.values.no_filters')
            : $this->joinedLabels($parts);
    }

    protected function warnings(
        string $source,
        int $fieldCount,
        string $status,
        string $search,
        string $dateFrom,
        string $dateTo,
        ?string $groupBy,
        string $presentation,
        array $calculations,
        bool $hasConditions,
        bool $hasDetailedRelationship,
    ): array {
        $warnings = [];
        $activitySources = [
            ReportDesignerCatalog::STUDENT_ATTENDANCE,
            ReportDesignerCatalog::MEMORIZATION_SESSIONS,
            ReportDesignerCatalog::QURAN_TESTS,
            ReportDesignerCatalog::QURAN_PARTIAL_TESTS,
            ReportDesignerCatalog::QURAN_FINAL_TESTS,
            ReportDesignerCatalog::ASSESSMENTS,
            ReportDesignerCatalog::ASSESSMENT_RESULTS,
            ReportDesignerCatalog::FINANCE_TRANSACTIONS,
        ];

        if ($dateFrom === '' && $dateTo === '' && in_array($source, $activitySources, true)) {
            $warnings[] = __('report_designer.guidance.warnings.no_date_range');
        } elseif ($status === 'all' && $search === '' && $dateFrom === '' && $dateTo === '' && ! $hasConditions) {
            $warnings[] = __('report_designer.guidance.warnings.no_filters');
        }

        if ($this->dateRangeDays($dateFrom, $dateTo) > 366) {
            $warnings[] = __('report_designer.guidance.warnings.large_date_range');
        }
        if ($fieldCount === 0) {
            $warnings[] = __('report_designer.guidance.warnings.no_fields');
        }
        if ($fieldCount > 8) {
            $warnings[] = __('report_designer.guidance.warnings.many_fields');
        }
        if (collect($calculations)->contains(
            fn ($calculation): bool => blank(data_get($calculation, 'operation'))
                || (data_get($calculation, 'operation') !== 'count' && blank(data_get($calculation, 'field'))),
        )) {
            $warnings[] = __('report_designer.guidance.warnings.incomplete_calculation');
        }
        if ($source === ReportDesignerCatalog::FINANCE_TRANSACTIONS && collect($calculations)->contains(
            fn ($calculation): bool => in_array(data_get($calculation, 'field'), ['amount', 'signed_amount'], true),
        )) {
            $warnings[] = __('report_designer.guidance.warnings.mixed_currency');
        }
        if ($presentation !== ReportDesignerCatalog::PRESENTATION_TABLE && $groupBy === null) {
            $warnings[] = __('report_designer.guidance.warnings.chart_without_grouping');
        }
        if ($hasDetailedRelationship && $calculations !== []) {
            $warnings[] = __('report_designer.guidance.warnings.detailed_relationship_calculations');
        }

        return array_values(array_unique($warnings));
    }

    protected function dateRangeDays(string $dateFrom, string $dateTo): int
    {
        if ($dateFrom === '' || $dateTo === '') {
            return 0;
        }

        try {
            return (int) CarbonImmutable::parse($dateFrom)->diffInDays(CarbonImmutable::parse($dateTo));
        } catch (Throwable) {
            return 0;
        }
    }

    protected function joinedLabels(array $labels): string
    {
        return implode(app()->isLocale('ar') ? '، ' : ', ', $labels);
    }
}
