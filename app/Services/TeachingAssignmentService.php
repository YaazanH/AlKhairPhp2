<?php

namespace App\Services;

use App\Models\Group;
use App\Models\User;

class TeachingAssignmentService
{
    public function ensureAssigned(Group $group): void
    {
        abort_unless(
            $group->teacher_id || $group->assistant_teacher_id,
            422,
            __('modules.errors.teacher_required'),
        );
    }

    public function attributedTeacherId(Group $group, ?User $actor): int
    {
        $this->ensureAssigned($group);
        $actorTeacherId = $actor?->teacherProfile?->id;

        if ($actorTeacherId && in_array($actorTeacherId, [$group->teacher_id, $group->assistant_teacher_id], true)) {
            return $actorTeacherId;
        }

        return (int) ($group->teacher_id ?: $group->assistant_teacher_id);
    }
}
