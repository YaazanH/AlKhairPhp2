<?php

namespace App\Services;

use App\Models\ReportDefinition;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ReportDefinitionAccess
{
    public function __construct(protected ReportDefinitionCompatibility $compatibility) {}

    public function canManageAll(?User $user): bool
    {
        return (bool) $user?->can('report-designer.update')
            || (bool) $user?->can('report-dashboard-layout.manage');
    }

    public function canView(?User $user, ReportDefinition $definition): bool
    {
        if (! $user || ! $this->compatibility->isCompatible($definition, $user)) {
            return false;
        }

        if ($definition->created_by === $user->id || $this->canManageAll($user)) {
            return true;
        }

        return $definition->status === ReportDefinition::STATUS_PUBLISHED
            && $definition->dashboardRoles()->whereIn('roles.id', $user->roles()->pluck('roles.id'))->exists();
    }

    public function scopeManageable(Builder $query, ?User $user): Builder
    {
        return $query->when(! $this->canManageAll($user), fn (Builder $builder) => $builder->where('created_by', $user?->id));
    }

    public function scopePlacedFor(Builder $query, ?User $user): Builder
    {
        $roleIds = $user?->roles()->pluck('roles.id') ?? collect();

        return $query
            ->where('status', ReportDefinition::STATUS_PUBLISHED)
            ->whereHas('dashboardRoles', fn (Builder $roles) => $roles->whereIn('roles.id', $roleIds));
    }

    public function sourceIsAvailable(User $user, ReportDefinition $definition): bool
    {
        return array_key_exists($definition->data_source, app(ReportDesignerCatalog::class)->sources($user));
    }
}
