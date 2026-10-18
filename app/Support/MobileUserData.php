<?php

namespace App\Support;

use App\Models\User;
use App\Services\Landlord\CurrentModuleAccess;

class MobileUserData
{
    public static function from(User $user, ?array $permissions = null): array
    {
        $user->loadMissing(['roles', 'parentProfile', 'studentProfile', 'teacherProfile']);

        $photoUrl = $user->profilePhotoUrl();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            'is_active' => (bool) $user->is_active,
            'primary_role' => $user->primaryRoleName(),
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => collect($permissions ?? $user->getAllPermissions()->pluck('name')->all())
                ->filter(fn (string $permission): bool => app(CurrentModuleAccess::class)->permissionAvailable($permission))
                ->sort()->values()->all(),
            'profile_photo_url' => $photoUrl ? url($photoUrl) : null,
            'username_is_editable' => ! $user->hasImmutableUsername(),
            'parent_id' => $user->parentProfile?->id,
            'student_id' => $user->studentProfile?->id,
            'teacher_id' => $user->teacherProfile?->id,
        ];
    }
}
