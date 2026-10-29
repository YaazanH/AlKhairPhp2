<?php

namespace Tests\Feature;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MemorizationCreatePermissionMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_permission_is_seeded_and_migrated_without_granting_it_to_quick_entry_roles(): void
    {
        $this->seed(RoleSeeder::class);
        foreach (['super_admin', 'admin', 'manager'] as $name) {
            $this->assertTrue(Role::findByName($name)->hasPermissionTo('memorization.create'));
        }
        $teacher = Role::findByName('teacher');
        $this->assertFalse($teacher->hasPermissionTo('memorization.create'));
        $this->assertTrue($teacher->hasPermissionTo('memorization.record'));
        $custom = Role::create(['name' => 'quick-only-recorder', 'guard_name' => 'web']);
        $custom->givePermissionTo('memorization.record');

        $migration = require database_path('migrations/2026_10_29_120000_add_memorization_create_permission.php');
        $migration->down();
        $this->assertDatabaseMissing('permissions', ['name' => 'memorization.create', 'guard_name' => 'web']);
        $migration->up();
        $migration->up();

        $this->assertSame(1, Permission::where('name', 'memorization.create')->where('guard_name', 'web')->count());
        foreach (['super_admin', 'admin', 'manager'] as $name) {
            $this->assertTrue(Role::findByName($name)->hasPermissionTo('memorization.create'));
        }
        foreach (['teacher', $custom->name] as $name) {
            $role = Role::findByName($name);
            $this->assertFalse($role->hasPermissionTo('memorization.create'));
            $this->assertTrue($role->hasPermissionTo('memorization.record'));
        }
    }
}
