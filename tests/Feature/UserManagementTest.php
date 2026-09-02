<?php

namespace Tests\Feature;

use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\ManagedUserService;
use App\Support\RoleRegistry;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_usernames_use_readable_and_stable_arabic_name_spellings(): void
    {
        $service = app(ManagedUserService::class);

        $this->assertSame('mohammad.alkhair', $service->uniqueUsername('', 'محمد الخير'));
        $this->assertSame('ahmad.darwish', $service->uniqueUsername('', 'أحمد درويش'));
        $this->assertSame('abdulrahman.hamwi', $service->uniqueUsername('', 'عبد الرحمن حموي'));
        $this->assertSame('yazan.alhomsi', $service->uniqueUsername('يزن الحمصي', 'Fallback Name'));

        User::factory()->create(['username' => 'mohammad.alkhair']);

        $this->assertSame('mohammad.alkhair2', $service->uniqueUsername('', 'محمد الخير'));
    }

    public function test_admin_can_manage_users_and_role_permissions(): void
    {
        $this->seed(RoleSeeder::class);

        $admin = User::factory()->create([
            'username' => 'admin-user',
            'phone' => '0911111111',
        ]);
        $admin->assignRole('admin');

        $this->actingAs($admin);
        Storage::fake('public');

        $this->get(route('users.index', absolute: false))->assertOk();
        $this->get(route('settings.access-control', absolute: false))->assertOk();

        Volt::test('users.index')
            ->assertSee('data-user-profile-filter', false)
            ->assertSee('admin-toolbar__controls--compact', false)
            ->assertSeeInOrder([
                '<option value="student">',
                '<option value="parent">',
                '<option value="teacher">',
            ], false)
            ->assertDontSee('wire:click="openCreateModal"', false);

        Volt::test('users.index')
            ->call('edit', $admin->id)
            ->assertSee(__('access.users.fields.finance_signature'))
            ->assertSeeHtml('data-user-form')
            ->assertSeeHtml('data-user-identity-grid')
            ->assertSeeHtml('data-user-active-toggle')
            ->assertSeeInOrder([
                'data-user-role-box',
                'data-user-media',
                'data-user-access-overrides-box',
                'data-user-direct-permissions',
                'data-user-scope-overrides',
            ], false)
            ->assertSeeHtml('data-user-direct-permissions')
            ->assertSeeHtml('data-user-scope-overrides')
            ->assertSee('wire:click="deleteEditingUser"', false)
            ->assertDontSee('wire:model="email"', false)
            ->assertDontSee('wire:click="cancel" class="pill-link"', false)
            ->assertDontSee(__('access.users.subtitle'))
            ->assertDontSee(__('access.users.sections.identity'))
            ->assertDontSee(__('access.users.sections.access'))
            ->assertDontSee(__('access.users.help.password'))
            ->assertDontSee(__('access.users.help.roles'))
            ->assertDontSee(__('access.users.help.permissions'))
            ->assertDontSee(__('access.users.help.scope'))
            ->assertDontSee(__('access.users.help.profile_photo'))
            ->assertDontSee(__('access.users.help.finance_signature'))
            ->set('finance_signature_upload', UploadedFile::fake()->image('signature.png', 600, 180))
            ->call('save')
            ->assertHasNoErrors();

        $admin->refresh();
        Storage::disk('public')->assertExists($admin->finance_signature_path);

        $userFormCss = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString("html[dir='rtl'] [data-user-form] .admin-collapsible__summary::before", $userFormCss);
        $this->assertStringContainsString("html[dir='rtl'] [data-user-form] .admin-collapsible[open] > .admin-collapsible__summary::before", $userFormCss);

        Volt::test('users.index')
            ->set('name', 'Teacher Account')
            ->set('username', 'teacher.account')
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
            ->call('openPermissionsModal', 'teacher')
            ->assertSee(__('ui.roles.teacher'))
            ->assertDontSee(__('access.roles.editor.title'))
            ->assertSee('data-permissions-save-icon', false)
            ->assertSee('data-permissions-edit-action', false)
            ->assertSee('wire:click="save" class="admin-modal__close"', false)
            ->assertDontSee('wire:click="save" class="pill-link pill-link--accent"', false)
            ->assertSee('role-permission-group', false)
            ->assertSee('data-permission-group-rows="3"', false)
            ->assertSee('role-permission-group__arrow" aria-hidden="true">‹</span>', false)
            ->assertDontSee('admin-collapsible__count', false)
            ->assertDontSee('wire:click="closePermissionsModal" class="pill-link"', false)
            ->assertViewHas('permissionGroups', function ($groups): bool {
                $titles = $groups->keys()->values()->all();
                $sortedTitles = $titles;
                $collator = new \Collator(app()->getLocale());
                usort($sortedTitles, fn (string $left, string $right): int => $collator->compare($left, $right) ?: strcmp($left, $right));

                return $titles === $sortedTitles;
            })
            ->call('closePermissionsModal')
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

        Volt::test('settings.access-control')
            ->call('openPermissionsModal', 'teacher')
            ->assertSet('showPermissionsModal', true)
            ->call('openEditRoleModal', 'teacher')
            ->assertSet('showPermissionsModal', false)
            ->assertSet('showRoleModal', true);

        $accessControlCss = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('grid-template-rows: repeat(3, auto)', $accessControlCss);
        $this->assertStringContainsString("html[dir='rtl'] .role-permission-group__arrow", $accessControlCss);
        $this->assertStringContainsString('transform: scaleX(-1);', $accessControlCss);
    }

    public function test_admin_can_create_user_with_generated_login_credentials(): void
    {
        $this->seed(RoleSeeder::class);

        $admin = User::factory()->create([
            'username' => 'generated-admin',
            'phone' => '0966666666',
        ]);
        $admin->assignRole(RoleRegistry::ADMIN);

        $this->actingAs($admin);

        Volt::test('users.index')
            ->call('openCreateModal')
            ->assertDontSee('data-user-active-toggle', false)
            ->set('name', 'Generated Account')
            ->set('username', '')
            ->set('password', '')
            ->set('roles', ['parent'])
            ->call('save')
            ->assertHasNoErrors();

        $user = User::query()->where('name', 'Generated Account')->firstOrFail();

        $this->assertNotEmpty($user->username);
        $this->assertNotEmpty($user->email);
        $this->assertStringEndsWith('@alkhair.local', $user->email);
        $this->assertNotEmpty($user->issued_password);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check($user->issued_password, $user->password));
    }

    public function test_user_profile_filter_uses_only_student_parent_and_teacher_profiles(): void
    {
        $this->seed(RoleSeeder::class);

        $admin = User::factory()->create(['name' => 'Profile Filter Admin']);
        $admin->assignRole(RoleRegistry::ADMIN);
        $studentUser = User::factory()->create(['name' => 'Student Profile Account']);
        $parentUser = User::factory()->create(['name' => 'Parent Profile Account']);
        $teacherUser = User::factory()->create(['name' => 'Teacher Profile Account']);

        Student::query()->create([
            'user_id' => $studentUser->id,
            'first_name' => 'Student',
            'last_name' => 'Profile',
            'birth_date' => '2014-01-01',
            'status' => 'active',
        ]);
        ParentProfile::query()->create([
            'user_id' => $parentUser->id,
            'father_name' => 'Parent Profile',
            'is_active' => true,
        ]);
        Teacher::query()->create([
            'user_id' => $teacherUser->id,
            'first_name' => 'Teacher',
            'last_name' => 'Profile',
            'phone' => '0999555010',
            'status' => 'active',
        ]);

        $studentUser->refresh();
        $parentUser->refresh();
        $teacherUser->refresh();

        $this->actingAs($admin);

        Volt::test('users.index')
            ->set('profileFilter', 'student')
            ->assertSee($studentUser->name)
            ->assertDontSee($parentUser->name)
            ->assertDontSee($teacherUser->name)
            ->set('profileFilter', 'parent')
            ->assertSee($parentUser->name)
            ->assertDontSee($studentUser->name)
            ->assertDontSee($teacherUser->name)
            ->set('profileFilter', 'teacher')
            ->assertSee($teacherUser->name)
            ->assertDontSee($studentUser->name)
            ->assertDontSee($parentUser->name);
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

        $this->assertGreaterThan(
            (int) Role::findByName(RoleRegistry::PARENT)->level,
            (int) $customRole->level,
        );
        $this->assertTrue($customRole->hasPermissionTo('attendance.student.view'));
        $this->assertTrue($customRole->hasPermissionTo('memorization.record'));
        $this->assertFalse($customRole->hasPermissionTo('settings.manage'));

        Volt::test('settings.access-control')
            ->call('openEditRoleModal', 'attendance_supervisor')
            ->assertSee('wire:click="deleteEditingRole"', false)
            ->assertDontSee('wire:click="deleteRole(\'attendance_supervisor\')"', false)
            ->set('role_name', 'Assessment Coach')
            ->call('saveRole')
            ->assertHasNoErrors();

        $renamedRole = Role::findByName('assessment_coach', 'web');

        $this->assertTrue($renamedRole->hasPermissionTo('attendance.student.view'));

        Volt::test('users.index')
            ->set('name', 'Coach User')
            ->set('username', 'coach.user')
            ->set('phone', '0955555555')
            ->set('password', 'Password123!')
            ->set('roles', ['assessment_coach'])
            ->call('save')
            ->assertHasNoErrors();

        $user = User::query()->where('username', 'coach.user')->firstOrFail();

        $this->assertTrue($user->hasRole('assessment_coach'));

        Volt::test('settings.access-control')
            ->call('openEditRoleModal', 'assessment_coach')
            ->call('deleteEditingRole')
            ->assertSet('showRoleModal', true)
            ->assertHasErrors(['role_delete']);

        $user->syncRoles([]);

        Volt::test('settings.access-control')
            ->call('openEditRoleModal', 'assessment_coach')
            ->call('deleteEditingRole')
            ->assertSet('showRoleModal', false)
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

    public function test_dragged_role_priority_determines_the_primary_role_for_users_with_multiple_roles(): void
    {
        $this->seed(RoleSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole(RoleRegistry::ADMIN);
        $this->actingAs($admin);

        $this->assertTrue(Schema::hasColumn('roles', 'level'));
        $this->assertSame(600, (int) Role::findByName(RoleRegistry::SUPER_ADMIN)->level);
        $this->assertSame(400, (int) Role::findByName(RoleRegistry::MANAGER)->level);
        $this->assertSame(300, (int) Role::findByName(RoleRegistry::TEACHER)->level);

        $component = Volt::test('settings.access-control')
            ->assertSee('draggable="true"', false)
            ->assertSee('role-sort-row--dragging', false)
            ->assertSee('role-sort-row--drop-target', false)
            ->assertSee('role-sort-handle--locked', false)
            ->assertDontSee('data-role-edit-action', false)
            ->assertDontSee('data-role-level', false)
            ->call('openPermissionsModal', RoleRegistry::TEACHER)
            ->assertSee('data-role-edit-action', false)
            ->call('openEditRoleModal', RoleRegistry::TEACHER)
            ->assertDontSee('id="role-level"', false)
            ->call('closeRoleModal')
            ->call('moveRole', RoleRegistry::TEACHER, RoleRegistry::MANAGER)
            ->assertHasNoErrors();

        $roleSortCss = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('.role-sort-row--drop-target > td', $roleSortCss);
        $this->assertStringContainsString('padding-top: 1.8rem;', $roleSortCss);

        $this->assertGreaterThan(
            (int) Role::findByName(RoleRegistry::MANAGER)->level,
            (int) Role::findByName(RoleRegistry::TEACHER)->level,
        );

        $levelsAfterValidMove = Role::query()->pluck('level', 'name')->all();

        $component
            ->call('moveRole', RoleRegistry::SUPER_ADMIN, RoleRegistry::ADMIN)
            ->call('moveRole', RoleRegistry::PARENT, RoleRegistry::MANAGER)
            ->call('moveRole', RoleRegistry::STUDENT, RoleRegistry::ADMIN)
            ->call('moveRole', RoleRegistry::MANAGER, RoleRegistry::SUPER_ADMIN)
            ->call('moveRole', RoleRegistry::MANAGER, RoleRegistry::STUDENT)
            ->assertHasNoErrors();

        $this->assertSame($levelsAfterValidMove, Role::query()->pluck('level', 'name')->all());

        $orderedRoleNames = RoleRegistry::sortCollection(Role::query()->get())->pluck('name')->all();
        $this->assertSame(RoleRegistry::SUPER_ADMIN, $orderedRoleNames[0]);
        $this->assertSame([RoleRegistry::PARENT, RoleRegistry::STUDENT], array_slice($orderedRoleNames, -2));

        $multiRoleUser = User::factory()->create();
        $multiRoleUser->assignRole([RoleRegistry::MANAGER, RoleRegistry::TEACHER]);

        $this->assertSame(RoleRegistry::TEACHER, $multiRoleUser->fresh()->primaryRoleName());

        $fixedBoundaryUser = User::factory()->create();
        $fixedBoundaryUser->assignRole([RoleRegistry::SUPER_ADMIN, RoleRegistry::PARENT, RoleRegistry::STUDENT]);
        $this->assertSame(RoleRegistry::SUPER_ADMIN, $fixedBoundaryUser->fresh()->primaryRoleName());

        $parentStudentUser = User::factory()->create();
        $parentStudentUser->assignRole([RoleRegistry::PARENT, RoleRegistry::STUDENT]);
        $this->assertSame(RoleRegistry::PARENT, $parentStudentUser->fresh()->primaryRoleName());
        $this->actingAs($multiRoleUser)
            ->get(route('dashboard', absolute: false))
            ->assertOk()
            ->assertSee('data-primary-role="teacher"', false);
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
