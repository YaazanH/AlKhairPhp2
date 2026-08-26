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

    /**
     * Roles whose positions define the fixed outer boundary of the hierarchy.
     *
     * @return array<int, string>
     */
    public static function fixedBoundaryRoles(): array
    {
        return [self::SUPER_ADMIN, self::PARENT, self::STUDENT];
    }

    /**
     * Higher numbers represent higher display precedence.
     *
     * @return array<string, int>
     */
    public static function defaultLevels(): array
    {
        return [
            self::SUPER_ADMIN => 600,
            self::ADMIN => 500,
            self::MANAGER => 400,
            self::TEACHER => 300,
            self::PARENT => 200,
            self::STUDENT => 100,
        ];
    }

    public static function defaultLevel(?string $roleName): int
    {
        return self::defaultLevels()[$roleName ?? ''] ?? 0;
    }

    public static function levelFor(mixed $role): int
    {
        if (is_object($role) && isset($role->level)) {
            return (int) $role->level;
        }

        return self::defaultLevel(is_object($role) ? ($role->name ?? null) : null);
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
        $sorted = $roles
            ->sort(function ($left, $right): int {
                $byLevel = self::levelFor($right) <=> self::levelFor($left);

                return $byLevel !== 0
                    ? $byLevel
                    : strcmp((string) ($left->name ?? ''), (string) ($right->name ?? ''));
            })
            ->values();

        return self::pinFixedRolePositions($sorted);
    }

    /**
     * Keep the supplied middle-role order while pinning the hierarchy boundaries.
     */
    public static function pinFixedRolePositions(Collection $roles): Collection
    {
        $roles = $roles->values();
        $superAdmin = $roles->firstWhere('name', self::SUPER_ADMIN);
        $parent = $roles->firstWhere('name', self::PARENT);
        $student = $roles->firstWhere('name', self::STUDENT);
        $middleRoles = $roles
            ->reject(fn ($role): bool => in_array($role->name ?? null, self::fixedBoundaryRoles(), true))
            ->values();

        return collect([$superAdmin])
            ->filter()
            ->concat($middleRoles)
            ->when($parent, fn (Collection $ordered) => $ordered->push($parent))
            ->when($student, fn (Collection $ordered) => $ordered->push($student))
            ->values();
    }
}
