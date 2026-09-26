<?php

namespace Tests\Feature;

use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\ManagedUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ProfileAccountOwnershipTest extends TestCase
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

    public function test_users_only_creates_administrative_accounts_and_shows_linked_accounts_read_only(): void
    {
        $user = User::factory()->create(['username' => 'shared.person']);
        $teacher = Teacher::create(['user_id' => $user->id, 'first_name' => 'Shared', 'last_name' => 'Person', 'phone' => '0999333111', 'status' => 'active']);
        $parent = ParentProfile::create(['user_id' => $user->id, 'father_name' => 'Shared Parent', 'is_active' => true]);
        $student = Student::create(['user_id' => $user->id, 'first_name' => 'Shared', 'last_name' => 'Student', 'birth_date' => '2010-01-01', 'status' => 'active']);
        $user->assignRole(['teacher', 'parent', 'student']);
        $users = Volt::test('users.index')
            ->assertSee('data-user-profile-view="'.$user->id.'"', false)
            ->assertDontSee('data-user-profile-link', false)
            ->assertDontSee('data-user-edit-action="'.$user->id.'"', false)
            ->assertViewHas('availableRoles', fn ($roles) => ! $roles->pluck('name')->intersect(['parent', 'student', 'teacher'])->count());
        foreach (['student', 'parent', 'teacher'] as $role) {
            $users->set('name', 'Misplaced profile')->set('roles', [$role])->call('save')->assertHasErrors('roles.0');
        }
        $this->assertDatabaseMissing('users', ['name' => 'Misplaced profile']);
        foreach (['teachers' => $teacher, 'parents' => $parent, 'students' => $student] as $section => $profile) {
            $response = $this->get(route($section.'.index', ['edit' => $profile->id]))->assertOk();
            $this->assertStringContainsString('"editingId":'.$profile->id, html_entity_decode($response->getContent(), ENT_QUOTES));
        }
        $users->set('profileFilter', 'standalone')->assertDontSee('data-user-profile-view="'.$user->id.'"', false);
    }

    public function test_profile_links_and_edit_urls_respect_permissions_and_scope(): void
    {
        $user = User::factory()->create();
        $parent = ParentProfile::create(['user_id' => $user->id, 'father_name' => 'Protected Parent']);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['users.view', 'parents.view', 'parents.update']);
        $this->actingAs($viewer);
        Volt::test('users.index')->call('viewLinkedAccount', $user->id)
            ->assertSee('data-user-readonly-account="'.$user->id.'"', false)
            ->assertSet('showFormModal', false);
        $this->get(route('parents.index', ['edit' => $parent->id]))->assertForbidden();
    }

    public function test_teacher_can_share_the_existing_parent_login_without_losing_roles_or_credentials(): void
    {
        $user = User::factory()->create(['name' => 'Existing Person', 'username' => 'one.login', 'password' => Hash::make('OriginalPass123!')]);
        $user->assignRole('parent');
        $parent = ParentProfile::create(['user_id' => $user->id, 'father_name' => 'Existing Person', 'is_active' => true]);
        $user->refresh();
        $original = $user->only(['username', 'password', 'email', 'is_active']);
        $userCount = User::count();
        Volt::test('teachers.index')->call('openCreateModal')
            ->set('existingAccountId', $user->id)
            ->set('first_name', 'Existing')->set('last_name', 'Person')->set('phone', '0999444222')
            ->set('access_roles', ['manager'])->call('save')->assertHasNoErrors();
        $teacher = Teacher::where('user_id', $user->id)->firstOrFail();
        $this->assertSame($userCount, User::count());
        $this->assertSame($original, $user->fresh()->only(array_keys($original)));
        $this->assertTrue($user->fresh()->hasAllRoles(['manager', 'parent']));

        Volt::test('teachers.index')->call('edit', $teacher->id)->set('first_name', 'Updated')->call('save')->assertHasNoErrors();
        $this->assertTrue($user->fresh()->hasAllRoles(['manager', 'parent']));
        Volt::test('parents.index')->call('edit', $parent->id)->set('father_name', 'Profile Name')->set('is_active', false)->call('save')->assertHasNoErrors();
        $this->assertSame($original, $user->fresh()->only(array_keys($original)));
        $identity = $user->fresh()->only(['name', 'phone']);
        Volt::test('parents.index')->call('openAccountModal', $parent->id)->set('account_password', 'SharedPass123!')->call('saveAccount')->assertHasNoErrors();
        $this->assertTrue(Hash::check('SharedPass123!', $teacher->fresh()->user->password));
        $this->assertSame($identity, $user->fresh()->only(['name', 'phone']));
        Volt::test('parents.index')->call('delete', $parent->id)->assertHasNoErrors();
        $this->assertNotNull($user->fresh());
        $this->assertTrue($user->fresh()->hasRole('manager'));
        $this->assertFalse($user->fresh()->hasRole('parent'));
    }

    public function test_student_and_parent_creation_can_reuse_a_login_and_reject_an_already_linked_login(): void
    {
        $user = User::factory()->create(['username' => 'family.shared']);
        $user->assignRole('manager');
        $count = User::count();
        Volt::test('parents.index')->call('openCreateModal')->set('existingAccountId', $user->id)
            ->set('father_name', 'Shared Adult')->call('save')->assertHasNoErrors();
        Volt::test('students.index')->call('openCreateModal')->set('existingAccountId', $user->id)
            ->set('first_name', 'Shared')->set('last_name', 'Learner')->set('birth_date', '2010')
            ->call('save')->assertHasNoErrors();
        $this->assertSame($count, User::count());
        $this->assertTrue($user->fresh()->hasAllRoles(['manager', 'parent', 'student']));
        $this->assertSame('family.shared', $user->fresh()->username);
        Volt::test('parents.index')->set('existingAccountId', $user->id)
            ->set('father_name', 'Duplicate')->call('save')->assertHasErrors('existingAccountId');
        $this->assertSame(1, ParentProfile::where('user_id', $user->id)->count());
        $this->assertFalse(app(ManagedUserService::class)->exclusiveAccountsQuery('student')->whereKey($user->id)->exists());
        $student = Student::where('user_id', $user->id)->firstOrFail();
        Volt::test('parents.index')->call('openBulkStatusModal')->call('applyBulkStatus')->assertHasNoErrors();
        Volt::test('students.index')->call('openBulkStatusModal')->call('applyBulkStatus')->assertHasNoErrors();
        $this->assertTrue($user->fresh()->is_active);
        Volt::test('students.index')->call('delete', $student->id)->assertHasNoErrors();
        $this->assertNotNull($user->fresh());
        $this->assertTrue($user->fresh()->hasRole('parent'));
    }

    public function test_profile_edit_permission_cannot_attach_or_reset_someone_elses_shared_login(): void
    {
        $user = User::factory()->create(['password' => Hash::make('PrivatePass123!'), 'issued_password' => 'PrivatePass123!']);
        $user->assignRole('manager');
        $parent = ParentProfile::create(['user_id' => $user->id, 'father_name' => 'Shared Staff Parent']);
        $editor = User::factory()->create();
        $editor->assignRole('manager');
        $editor->givePermissionTo(['parents.create', 'parents.update']);
        $this->actingAs($editor);
        Volt::test('parents.index')->call('openCreateModal')->assertDontSee('data-existing-profile-account', false)
            ->set('existingAccountId', $user->id)->assertForbidden();
        Volt::test('parents.index')->call('openAccountModal', $parent->id)->assertForbidden();
        Volt::test('parents.index')->call('viewAccount', $parent->id)->assertForbidden();
        Volt::test('parents.index')->set('accountParentId', $parent->id)->set('account_password', 'ChangedPass123!')->call('saveAccount')->assertForbidden();
        Volt::test('parents.index')->set('accountParentId', $parent->id)->call('openPasswordModal')->assertForbidden();
        Volt::test('parents.index')->set('accountParentId', $parent->id)->set('account_password', 'ChangedPass123!')->call('savePassword')->assertForbidden();
        $this->assertTrue(Hash::check('PrivatePass123!', $user->fresh()->password));
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('existingLearnerProfileTypes')]
    public function test_teacher_permissions_can_use_an_existing_student_or_parent_login(string $type): void
    {
        $user = User::factory()->create(['username' => $type.'.also.teacher', 'password' => 'ExistingLogin123!', 'issued_password' => 'ExistingLogin123!']);
        $user->assignRole($type);
        if ($type === 'parent') {
            ParentProfile::create(['user_id' => $user->id, 'father_name' => 'Existing Parent']);
        } else {
            Student::create(['user_id' => $user->id, 'first_name' => 'Existing', 'last_name' => 'Student', 'birth_date' => '2008-01-01', 'status' => 'active']);
        }
        $role = \Spatie\Permission\Models\Role::create(['name' => 'limited-recorder', 'guard_name' => 'web']);
        $role->givePermissionTo('memorization.record');
        $count = User::count();
        $hash = $user->password;
        Volt::test('teachers.index')->call('openCreateModal')->set('existingAccountId', $user->id)
            ->set('first_name', 'Existing')->set('last_name', 'Teacher')->set('phone', '0999333559')
            ->set('access_roles', ['limited-recorder'])->call('save')->assertHasNoErrors();
        $this->assertSame($count, User::count());
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame($type.'.also.teacher', $user->fresh()->username);
        $this->assertTrue($user->fresh()->hasAllRoles([$type, 'limited-recorder']));
        $this->assertTrue($user->fresh()->can('memorization.record'));
        $this->assertFalse($user->fresh()->can('teachers.delete'));
        $this->assertSame($user->id, Teacher::where('user_id', $user->id)->sole()->user_id);
    }

    public static function existingLearnerProfileTypes(): array
    {
        return [['parent'], ['student']];
    }

}
