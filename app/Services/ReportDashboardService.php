<?php

namespace App\Services;

use App\Models\ReportDefinition;
use App\Models\User;
use Illuminate\Support\Collection;

class ReportDashboardService
{
    public function __construct(
        protected ReportDefinitionAccess $access,
        protected ReportDesignerCatalog $catalog,
    ) {}

    public function reportsFor(?User $user): Collection
    {
        if (! $user) {
            return collect();
        }

        $sourceKeys = array_keys($this->catalog->sources($user));

        return $this->access->scopePlacedFor(
            ReportDefinition::query()->whereIn('data_source', $sourceKeys),
            $user,
        )
            ->with(['dashboardRoles' => fn ($query) => $query
                ->whereIn('roles.id', $user->roles()->pluck('roles.id'))
                ->orderBy('report_dashboard_placements.position')])
            ->get()
            ->sortBy(fn (ReportDefinition $report) => [
                (int) ($report->dashboardRoles->min('pivot.position') ?? PHP_INT_MAX),
                mb_strtolower($report->name),
            ])
            ->values();
    }

    public function widgetsFor(?User $user): Collection
    {
        return $this->reportsFor($user)->map(function (ReportDefinition $report) use ($user): array {
            $preview = app(ReportDesignerQueryService::class)->preview([
                'data_source' => $report->data_source,
                'selected_fields' => $report->selected_fields,
                'calculations' => $report->calculations ?? [],
                'group_by' => $report->group_by,
                'filters' => $report->filters ?? [],
                'sort_field' => $report->sort_field,
                'sort_direction' => $report->sort_direction,
            ], $user);

            return [
                'report' => $report,
                'size' => $report->dashboardRoles->pluck('pivot.size')->first() ?: 'medium',
                'preview' => $preview,
            ];
        });
    }
}
