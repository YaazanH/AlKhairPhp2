<?php

namespace App\Support;

use Illuminate\Support\Collection;

class RoleRegistry
{
    public const SUPER_ADMIN = 'super_admin';
    public const ADMIN = 'admin';
    public const MANAGER = 'manager';
    public const TEACHER = 'teacher';
    public const PARENT = 'parent';
    public const STUDENT = 'student';

    /**
     * @return array<int, string>
     */
    public static function unrestrictedRoles(): array
    {
        return [
            self::SUPER_ADMIN,
            self::ADMIN,
            self::MANAGER,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function actorRoles(): array
    {
        return [
            self::TEACHER,
            self::PARENT,
            self::STUDENT,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function systemRoles(): array
    {
        return [
            ...self::unrestrictedRoles(),
            ...self::actorRoles(),
        ];
    }

    public static function isSystemRole(?string $roleName): bool
    {
        return in_array($roleName, self::systemRoles(), true);
    }

    public static function sortKey(?string $roleName): string
    {
        $roleName ??= '';

        $weights = [
            self::SUPER_ADMIN => 0,
            self::ADMIN => 1,
            self::MANAGER => 2,
            self::TEACHER => 3,
            self::PARENT => 4,
            self::STUDENT => 5,
        ];

        $weight = $weights[$roleName] ?? 99;

        return str_pad((string) $weight, 2, '0', STR_PAD_LEFT).'-'.$roleName;
    }

    public static function sortCollection(Collection $roles): Collection
    {
        return $roles
            ->sortBy(fn ($role) => self::sortKey($role->name ?? null))
            ->values();
    }
}
