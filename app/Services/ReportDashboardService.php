<?php

namespace App\Services;

use App\Exceptions\ReportQueryTimeoutException;
use App\Models\ReportDefinition;
use App\Models\User;
use App\Services\Landlord\TenantContext;
use App\Support\RoleRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ReportDashboardService
{
    protected array $userContextFingerprints = [];

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
            try {
                $preview = Cache::remember(
                    $this->widgetCacheKey($report, $user),
                    now()->addSeconds(max(1, (int) config('performance.report_cache_ttl_seconds', 30))),
                    fn (): array => app(ReportDesignerQueryService::class)->preview(
                        $this->queryDefinition($report),
                        $user,
                    ),
                );
            } catch (ReportQueryTimeoutException $exception) {
                $preview = [
                    'columns' => [],
                    'rows' => [],
                    'total' => 0,
                    'calculations' => [],
                    'grouping' => null,
                    'error' => $exception->userMessage(),
                ];
            }

            return [
                'report' => $report,
                'size' => $report->dashboard_size,
                'preview' => $preview,
            ];
        });
    }

    protected function queryDefinition(ReportDefinition $report): array
    {
        return [
            'data_source' => $report->data_source,
            'selected_fields' => $report->selected_fields,
            'calculations' => $report->calculations ?? [],
            'group_by' => $report->group_by,
            'filters' => $report->filters ?? [],
            'sort_field' => $report->sort_field,
            'sort_direction' => $report->sort_direction,
        ];
    }

    protected function widgetCacheKey(ReportDefinition $report, User $user): string
    {
        $reportFingerprint = hash('sha256', json_encode([
            'id' => $report->id,
            'updated_at' => $report->updated_at?->format('Y-m-d H:i:s.u'),
            'definition' => $this->queryDefinition($report),
        ], JSON_THROW_ON_ERROR));

        return implode(':', [
            'report-dashboard-widget-v1',
            $this->tenantFingerprint(),
            $user->getKey(),
            $this->userContextFingerprint($user),
            $reportFingerprint,
        ]);
    }

    protected function tenantFingerprint(): string
    {
        $context = app(TenantContext::class);
        $identity = $context->hasTenant()
            ? $context->tenant()->uuid
            : DB::getDefaultConnection().'|'.(string) DB::connection()->getDatabaseName();

        return hash('sha256', (string) $identity);
    }

    protected function userContextFingerprint(User $user): string
    {
        return $this->userContextFingerprints[$user->getKey()] ??= hash('sha256', json_encode([
            'roles' => $user->roles()->orderBy('roles.id')->pluck('roles.id')->map(fn ($id): int => (int) $id)->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->sort()->values()->all(),
            'groups' => app(AccessScopeService::class)->accessibleGroupIds($user),
            'students' => app(AccessScopeService::class)->accessibleStudentIds($user),
            'enrollments' => app(AccessScopeService::class)->accessibleEnrollmentIds($user),
            'teachers' => app(AccessScopeService::class)->accessibleTeacherIds($user),
            'parents' => app(AccessScopeService::class)->accessibleParentIds($user),
        ], JSON_THROW_ON_ERROR));
    }
}
