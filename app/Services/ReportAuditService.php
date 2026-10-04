<?php

namespace App\Services;

use App\Models\ReportDefinition;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ReportAuditService
{
    public const EVENT_DASHBOARD_UPDATED = 'report_dashboard_updated';

    public const EVENT_EXPORTED = 'report_exported';

    public function dashboardSnapshot(ReportDefinition $definition): array
    {
        return DB::table('report_dashboard_placements')
            ->join('roles', 'roles.id', '=', 'report_dashboard_placements.role_id')
            ->where('report_dashboard_placements.report_definition_id', $definition->getKey())
            ->orderBy('roles.name')
            ->get([
                'roles.id as role_id',
                'roles.name as role_name',
                'report_dashboard_placements.position',
                'report_dashboard_placements.size',
            ])
            ->map(fn (object $placement): array => [
                'role_id' => (int) $placement->role_id,
                'role_name' => (string) $placement->role_name,
                'position' => (int) $placement->position,
                'size' => (string) $placement->size,
            ])
            ->all();
    }

    public function dashboardUpdated(ReportDefinition $definition, array $before, array $after): void
    {
        if ($before === $after) {
            return;
        }

        $this->record($definition, self::EVENT_DASHBOARD_UPDATED, [
            'dashboard_placements' => $before,
        ], [
            'dashboard_placements' => $after,
        ]);
    }

    public function exported(ReportDefinition $definition, string $format, array $result): void
    {
        $this->record($definition, self::EVENT_EXPORTED, [], [
            'export_format' => $format,
            'exported_rows' => count($result['rows'] ?? []),
            'matching_records' => (int) ($result['total'] ?? 0),
        ]);
    }

    protected function record(ReportDefinition $definition, string $event, array $before, array $after): void
    {
        if (! Auth::check()) {
            return;
        }

        try {
            if (! Schema::hasTable('activity_log')) {
                return;
            }

            activity('data-audit')
                ->causedBy(Auth::user())
                ->performedOn($definition)
                ->event($event)
                ->withProperties([
                    'before' => $before,
                    'after' => $after,
                    'entries' => [],
                    'subject_label' => $definition->name,
                    'subject_type_label' => class_basename($definition),
                    'route' => request()->route()?->getName(),
                    'ip_address' => request()->ip(),
                ])
                ->log($event.' '.class_basename($definition));
        } catch (Throwable $exception) {
            try {
                Log::error('Report audit logging failed; the report action was preserved.', [
                    'report_definition_id' => $definition->getKey(),
                    'event' => $event,
                    'exception' => $exception->getMessage(),
                ]);
            } catch (Throwable) {
                // Audit reporting must never prevent the report action.
            }
        }
    }
}
