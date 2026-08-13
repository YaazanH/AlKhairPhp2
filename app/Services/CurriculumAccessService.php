<?php

namespace App\Services;

use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class CurriculumAccessService
{
    public function canManage(?User $user): bool
    {
        return (bool) $user?->can('curricula.manage');
    }

    public function canView(?User $user): bool
    {
        return $this->canManage($user) || $this->isGroupSupervisor($user);
    }

    public function isGroupSupervisor(?User $user): bool
    {
        $teacher = $user?->teacherProfile?->loadMissing(['accessRole', 'jobTitle']);
        if (! $teacher || ! $user?->can('curricula.record')) {
            return false;
        }

        $names = collect([$teacher->accessRole?->name, $teacher->job_title, $teacher->jobTitle?->name])
            ->filter()
            ->map(fn (string $name) => Str::lower(Str::squish($name)));

        return $names->contains(fn (string $name) => in_array($name, [
            'مشرف حلقة', 'مساعد مشرف حلقة',
            'group supervisor', 'assistant group supervisor',
            'group_supervisor', 'assistant_group_supervisor',
            'halaqa supervisor', 'assistant halaqa supervisor',
            'halaqa_supervisor', 'assistant_halaqa_supervisor',
        ], true));
    }

    public function groupsQuery(User $user): Builder
    {
        if ($this->canManage($user)) {
            return Group::query();
        }

        return app(AccessScopeService::class)->scopeGroups(Group::query(), $user);
    }
}
