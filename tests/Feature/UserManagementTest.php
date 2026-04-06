<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\RoleRegistry;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_users_and_role_permissions(): void
    {
        $this->seed(RoleSeeder::class);

        $admin = User::factory()->create([
            'username' => 'admin-user',
            'phone' => '0911111111',
        ]);
        $admin->assignRole('admin');

        $this->actingAs($admin);

        $this->get(route('users.index', absolute: false))->assertOk();
        $this->get(route('settings.access-control', absolute: false))->assertOk();

        Volt::test('users.index')
            ->set('name', 'Teacher Account')
            ->set('username', 'teacher.account')
            ->set('email', 'teacher.account@example.test')
            ->set('phone', '0922222222')
            ->set('password', 'Password123!')
            ->set('roles', ['teacher'])
            ->set('direct_permissions', ['points.create-manual'])
            ->call('save')
            ->assertHasNoErrors();

        $user = User::query()->where('username', 'teacher.account')->firstOrFail();

        $this->assertTrue($user->hasRole('teacher'));
        $this->assertTrue($user->hasDirectPermission('points.create-manual'));

        Volt::test('settings.access-control')
            ->set('selected_role', 'teacher')
            ->set('selected_permissions', [
                'dashboard.teacher.view',
                'attendance.student.view',
                'memorization.view',
                'points.view',
                'points.create-manual',
            ])
            ->call('save')
            ->assertHasNoErrors();

        $teacherRole = Role::findByName('teacher', 'web');

        $this->assertTrue($teacherRole->hasPermissionTo('points.create-manual'));
    }

    public function test_admin_can_manage_custom_roles_without_breaking_system_roles(): void
    {
        $this->seed(RoleSeeder::class);

        $admin = User::factory()->create([
            'username' => 'roles-admin',
            'phone' => '0944444444',
        ]);
        $admin->assignRole(RoleRegistry::ADMIN);

        $this->actingAs($admin);

        Volt::test('settings.access-control')
            ->call('openCreateRoleModal')
            ->set('role_name', 'Attendance Supervisor')
            ->set('clone_role', RoleRegistry::TEACHER)
            ->call('saveRole')
            ->assertHasNoErrors();

        $customRole = Role::findByName('attendance_supervisor', 'web');

        $this->assertTrue($customRole->hasPermissionTo('attendance.student.view'));
        $this->assertTrue($customRole->hasPermissionTo('memorization.record'));
        $this->assertFalse($customRole->hasPermissionTo('settings.manage'));

        Volt::test('settings.access-control')
            ->call('openEditRoleModal', 'attendance_supervisor')
            ->set('role_name', 'Assessment Coach')
            ->call('saveRole')
            ->assertHasNoErrors();

        $renamedRole = Role::findByName('assessment_coach', 'web');

        $this->assertTrue($renamedRole->hasPermissionTo('attendance.student.view'));

        Volt::test('users.index')
            ->set('name', 'Coach User')
            ->set('username', 'coach.user')
            ->set('email', 'coach.user@example.test')
            ->set('phone', '0955555555')
            ->set('password', 'Password123!')
            ->set('roles', ['assessment_coach'])
            ->call('save')
            ->assertHasNoErrors();

        $user = User::query()->where('username', 'coach.user')->firstOrFail();

        $this->assertTrue($user->hasRole('assessment_coach'));

        Volt::test('settings.access-control')
            ->call('deleteRole', 'assessment_coach')
            ->assertHasErrors(['role_delete']);

        $user->syncRoles([]);

        Volt::test('settings.access-control')
            ->call('deleteRole', 'assessment_coach')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('roles', ['name' => 'assessment_coach']);

        Volt::test('settings.access-control')
            ->call('openEditRoleModal', RoleRegistry::TEACHER)
            ->set('role_name', 'Teacher Override')
            ->call('saveRole')
            ->assertHasErrors(['role_name']);

        Volt::test('settings.access-control')
            ->call('deleteRole', RoleRegistry::TEACHER)
            ->assertHasErrors(['role_delete']);

        $this->assertDatabaseHas('roles', ['name' => RoleRegistry::TEACHER]);
    }

    public function test_manager_users_cannot_open_user_management_pages(): void
    {
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create([
            'username' => 'manager-user',
            'phone' => '0933333333',
        ]);
        $manager->assignRole('manager');

        $this->actingAs($manager);

        $this->get(route('users.index', absolute: false))->assertForbidden();
        $this->get(route('settings.access-control', absolute: false))->assertForbidden();
    }
}
