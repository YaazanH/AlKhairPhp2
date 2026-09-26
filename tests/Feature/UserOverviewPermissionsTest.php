<?php

namespace Tests\Feature;

use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class UserOverviewPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $this->actingAs($admin);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('profileTypes')]
    public function test_overview_editor_changes_only_access_and_preserves_profile_roles(string $type): void
    {
        $user = User::factory()->create(['username' => $type.'.permissions', 'password' => 'OriginalPass123!', 'issued_password' => 'OriginalPass123!']);
        $user->assignRole($type);
        if ($type === 'parent') {
            ParentProfile::create(['user_id' => $user->id, 'father_name' => 'Existing Parent']);
        } else {
            Student::create(['user_id' => $user->id, 'first_name' => 'Existing', 'last_name' => 'Student', 'birth_date' => '2010-01-01', 'status' => 'active']);
        }
        $identity = $user->fresh()->getAttributes();
        $scopeStudent = Student::create(['first_name' => 'Scoped', 'last_name' => 'Student', 'birth_date' => '2010-01-01', 'status' => 'active']);
        $editor = Volt::test('users.index')->call('viewLinkedAccount', $user->id)
            ->assertSee('data-user-account-permissions-action', false)->call('openAccountPermissions')
            ->assertSet('showPermissionsModal', true)->assertDontSee('data-user-readonly-account', false)
            ->assertDontSee('wire:model="username"', false)->assertDontSee('wire:model="password"', false)
            ->set('name', 'Forged identity')->set('username', 'forged.login')->set('password', 'ForgedPass123!')
            ->set('is_active', false)->set('roles', [])->set('direct_permissions', ['memorization.record'])
            ->set('scope_students', [$scopeStudent->id])->call('saveAccountPermissions')->assertHasNoErrors()
            ->assertSet('showPermissionsModal', false)->assertSee('data-user-readonly-account', false);
        $this->assertSame($identity, $user->fresh()->getAttributes());
        $this->assertTrue($user->fresh()->hasRole($type));
        $this->assertTrue($user->fresh()->hasDirectPermission('memorization.record'));
        $this->assertDatabaseMissing('teachers', ['user_id' => $user->id]);
        $this->assertDatabaseHas('user_scope_overrides', ['user_id' => $user->id, 'scope_type' => 'student', 'scope_id' => $scopeStudent->id]);
        $editor->call('openAccountPermissions')->assertSet('scope_students', [$scopeStudent->id])
            ->assertSet('direct_permissions', ['memorization.record'])
            ->set('direct_permissions', [])->set('scope_students', [])->call('saveAccountPermissions')->assertHasNoErrors();
        $this->assertFalse($user->fresh()->hasDirectPermission('memorization.record'));
        $this->assertTrue($user->fresh()->hasRole($type));
    }

    public static function profileTypes(): array
    {
        return [['parent'], ['student']];
    }

    public function test_permissions_editor_is_not_available_to_readonly_users(): void
    {
        $user = User::factory()->create();
        ParentProfile::create(['user_id' => $user->id, 'father_name' => 'Parent']);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('users.view');
        $this->actingAs($viewer);
        Volt::test('users.index')->call('viewLinkedAccount', $user->id)
            ->assertDontSee('data-user-account-permissions-action', false)->call('openAccountPermissions')->assertForbidden();
        Volt::test('users.index')->call('viewLinkedAccount', $user->id)->set('showPermissionsModal', true)
            ->set('direct_permissions', ['users.update'])->call('saveAccountPermissions')->assertForbidden();
        $this->assertSame(0, $user->permissions()->count());
    }

    public function test_invalid_permissions_and_profile_roles_are_rejected_without_partial_changes(): void
    {
        $user = User::factory()->create();
        $user->assignRole('parent');
        ParentProfile::create(['user_id' => $user->id, 'father_name' => 'Parent']);
        $editor = Volt::test('users.index')->call('viewLinkedAccount', $user->id)->call('openAccountPermissions')
            ->set('roles', ['manager'])->set('direct_permissions', ['missing.permission'])->call('saveAccountPermissions')
            ->assertHasErrors('direct_permissions.0');
        $this->assertFalse($user->fresh()->hasRole('manager'));
        $editor->set('direct_permissions', [])->set('roles', ['student'])->call('saveAccountPermissions')->assertHasErrors('roles.0');
        $this->assertTrue($user->fresh()->hasRole('parent'));
        $this->assertFalse($user->fresh()->hasRole('student'));
        $editor->call('closeAccountPermissions')->call('saveAccountPermissions')->assertNotFound();
    }
}
