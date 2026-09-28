<?php

namespace Tests\Feature;

use App\Models\Teacher;
use App\Models\User;
use App\Services\AccessScopeService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentProgressScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $this->actingAs($admin);
    }

    public function test_teacher_scope_option_can_be_saved_reloaded_and_revoked(): void
    {
        $user = User::factory()->create();
        $user->assignRole('teacher');
        $teacher = Teacher::create([
            'user_id' => $user->id, 'first_name' => 'Progress', 'last_name' => 'Teacher',
            'phone' => '0999666001', 'status' => 'active',
        ]);
        $editor = Volt::test('teachers.index')->call('edit', $teacher->id)
            ->assertSee('data-student-progress-all-scope', false)
            ->assertSet('scope_student_progress_all', false)
            ->set('scope_student_progress_all', true)->call('save')->assertHasNoErrors()
            ->assertSet('scope_student_progress_all', false);
        $this->assertTrue(app(AccessScopeService::class)->canViewAllStudentProgress($user));

        $editor->call('edit', $teacher->id)->assertSet('scope_student_progress_all', true)
            ->call('save')->assertHasNoErrors();
        $this->assertTrue(app(AccessScopeService::class)->canViewAllStudentProgress($user));

        $editor->call('edit', $teacher->id)->set('scope_student_progress_all', false)
            ->call('save')->assertHasNoErrors();
        $this->assertFalse(app(AccessScopeService::class)->canViewAllStudentProgress($user));
    }

    public function test_standalone_user_scope_option_can_be_saved_reloaded_and_revoked(): void
    {
        $role = Role::create(['name' => 'progress-viewer', 'guard_name' => 'web']);
        $role->givePermissionTo('students.view');
        $user = User::factory()->create();
        $user->assignRole($role);
        $editor = Volt::test('users.index')->call('edit', $user->id)
            ->assertSee('data-student-progress-all-scope', false)
            ->set('scope_student_progress_all', true)->call('save')->assertHasNoErrors();
        $this->assertTrue(app(AccessScopeService::class)->canViewAllStudentProgress($user));
        $editor->call('edit', $user->id)->assertSet('scope_student_progress_all', true)
            ->set('scope_student_progress_all', false)->call('save')->assertHasNoErrors();
        $this->assertFalse(app(AccessScopeService::class)->canViewAllStudentProgress($user));
    }

    public function test_linking_an_existing_account_preserves_and_resets_the_progress_scope_selection(): void
    {
        $user = User::factory()->create();
        app(AccessScopeService::class)->syncUserOverrides($user, [AccessScopeService::ALL_STUDENT_PROGRESS => [1]]);
        Volt::test('teachers.index')->call('openCreateModal')
            ->set('existingAccountId', $user->id)->assertSet('scope_student_progress_all', true)
            ->set('existingAccountId', null)->assertSet('scope_student_progress_all', false)
            ->set('existingAccountId', $user->id)->assertSet('scope_student_progress_all', true)
            ->set('first_name', 'Linked')->set('last_name', 'Teacher')->set('phone', '0999666002')
            ->call('save')->assertHasNoErrors();
        $this->assertTrue(app(AccessScopeService::class)->canViewAllStudentProgress($user));
        $this->assertDatabaseHas('teachers', ['user_id' => $user->id, 'first_name' => 'Linked']);
    }
}
