<?php

namespace App\Services;

use App\Models\ReportDefinition;
use App\Models\User;
use App\Support\RoleRegistry;
use Illuminate\Support\Collection;

class ReportDashboardService
{
    public function __construct(
        protected ReportDefinitionAccess $access,
        protected ReportDesignerCatalog $catalog,
    ) {}

    public function landingRouteNameFor(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        if ($user->can('reports.view')) {
            return 'reports.index';
        }

        return $this->reportsFor($user)->isNotEmpty() ? 'reports.custom' : null;
    }

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
                ->whereIn('roles.id', $user->roles()->pluck('roles.id'))])
            ->get()
            ->map(function (ReportDefinition $report): ReportDefinition {
                $placementRole = RoleRegistry::sortCollection($report->dashboardRoles)->first();

                $report->setAttribute('dashboard_position', (int) ($placementRole?->pivot->position ?? PHP_INT_MAX));
                $report->setAttribute('dashboard_size', (string) ($placementRole?->pivot->size ?: 'medium'));

                return $report;
            })
            ->sortBy(fn (ReportDefinition $report) => [
                $report->dashboard_position,
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
                'size' => $report->dashboard_size,
                'preview' => $preview,
            ];
        });
    }
}
