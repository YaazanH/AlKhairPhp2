<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Throwable;

class ReportDesignerGuidance
{
    public function __construct(protected ReportDesignerCatalog $catalog) {}

    /**
     * @return array{summary: string, facts: array<int, array{label: string, value: string}>, warnings: string[]}
     */
    public function build(array $definition, ?User $user): array
    {
        $source = (string) ($definition['data_source'] ?? '');
        $sourceDetails = $this->catalog->sources($user)[$source] ?? null;
        if (! $sourceDetails) {
            return ['summary' => '', 'facts' => [], 'warnings' => []];
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

        $facts = [
            [
                'label' => __('report_designer.guidance.facts.fields'),
                'value' => $fieldLabels === []
                    ? __('report_designer.guidance.values.no_fields')
                    : $this->joinedLabels($fieldLabels),
            ],
            ['label' => __('report_designer.guidance.facts.filters'), 'value' => $this->filterSummary($source, $status, $search, $dateFrom, $dateTo)],
            [
                'label' => __('report_designer.guidance.facts.grouping'),
                'value' => $groupBy !== null
                    ? (string) data_get($this->catalog->groupableFields($source), $groupBy.'.label', $groupBy)
                    : __('report_designer.guidance.values.no_grouping'),
            ],
            [
                'label' => __('report_designer.guidance.facts.presentation'),
                'value' => (string) ($this->catalog->libraryPresentationTypes()[$presentation] ?? $presentation),
            ],
            [
                'label' => __('report_designer.guidance.facts.calculations'),
                'value' => trans_choice('report_designer.guidance.values.calculation_count', $calculations->count(), [
                    'count' => $calculations->count(),
                ]),
            ],
        ];

        return [
            'summary' => __('report_designer.guidance.summary', [
                'source' => $sourceDetails['label'],
                'count' => trans_choice('report_designer.guidance.values.field_count', $selectedFields->count(), [
                    'count' => $selectedFields->count(),
                ]),
            ]),
            'facts' => $facts,
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
            ),
        ];
    }

    protected function filterSummary(string $source, string $status, string $search, string $dateFrom, string $dateTo): string
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
        } elseif ($status === 'all' && $search === '' && $dateFrom === '' && $dateTo === '') {
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
