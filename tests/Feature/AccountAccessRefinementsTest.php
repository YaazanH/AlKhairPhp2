<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Group;
use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AccessScopeService;
use App\Services\ParentNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AccountAccessRefinementsTest extends TestCase
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

    public function test_student_account_form_cannot_change_the_username_even_with_a_forged_value(): void
    {
        $user = User::factory()->create(['username' => 'student.locked']);
        $student = Student::create(['user_id' => $user->id, 'first_name' => 'Locked', 'last_name' => 'Student', 'birth_date' => '2010-01-01', 'status' => 'active']);
        Volt::test('students.index')->call('openAccountModal', $student->id)
            ->assertDontSee(__('access.profile_accounts.help.password'))
            ->set('account_username', 'forged.username')->call('saveAccount')->assertHasNoErrors();
        $this->assertSame('student.locked', $user->fresh()->username);
    }

    public function test_students_and_parents_cannot_rename_their_login_in_personal_settings(): void
    {
        foreach (['student', 'parent'] as $role) {
            $user = User::factory()->create(['username' => $role.'.locked']);
            $user->assignRole($role);
            $this->actingAs($user);
            Volt::test('settings.profile')->set('username', 'new.'.$role)
                ->call('updateUsername')->assertHasErrors('username');
            $this->assertSame($role.'.locked', $user->fresh()->username);
        }
    }

    public function test_profile_usernames_are_locked_at_the_model_even_without_roles(): void
    {
        foreach (['student', 'parent'] as $type) {
            $user = User::factory()->create(['username' => $type.'.original']);
            $profile = $type === 'student'
                ? Student::create(['user_id' => $user->id, 'first_name' => 'Locked', 'last_name' => 'Student', 'birth_date' => '2010-01-01'])
                : ParentProfile::create(['user_id' => $user->id, 'father_name' => 'Locked Parent']);
            $profile->delete();
            try {
                $user->update(['username' => 'new.'.$type]);
                $this->fail('The username update should have been rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('username', $exception->errors());
            }
            $this->assertSame($type.'.original', $user->fresh()->username);
        }
    }

    public function test_parent_number_sync_preserves_the_existing_login(): void
    {
        $user = User::factory()->create(['username' => 'parent.original']);
        $parent = ParentProfile::create(['user_id' => $user->id, 'father_name' => 'Parent']);
        app(ParentNumberService::class)->syncParent($parent);
        $this->assertSame('parent.original', $user->fresh()->username);
    }

    public function test_parent_password_editor_replaces_the_account_modal_and_only_changes_the_password(): void
    {
        $user = User::factory()->create(['username' => 'parent.password', 'password' => 'CurrentPass123!', 'issued_password' => 'CurrentPass123!']);
        $parent = ParentProfile::create(['user_id' => $user->id, 'father_name' => 'Parent']);
        $identity = $user->fresh()->only(['name', 'username', 'email', 'phone', 'is_active']);
        $editor = Volt::test('parents.index')->call('viewAccount', $parent->id)
            ->assertSee('admin-modal__dialog--md', false)
            ->assertDontSee('admin-modal__dialog--2xl', false)
            ->assertSee('data-parent-password-edit-action', false)->call('openPasswordModal')
            ->assertSet('account_password', 'CurrentPass123!')->assertSet('showAccountViewModal', false)
            ->assertSet('showAccountModal', false)->assertSet('showFormModal', false)
            ->assertSet('showPasswordModal', true)->assertSee('data-parent-password-save-action', false)
            ->assertDontSee('class="admin-modal__close"', false);
        $this->assertSame(1, substr_count($editor->html(), 'class="admin-modal '));
        $this->assertSame(1, substr_count($editor->html(), 'id="parent-account-password"'));
        $editor->set('account_username', 'forged')->set('account_email', 'forged@example.test')
            ->set('account_is_active', false)->set('account_password', 'UpdatedPass123!')->call('savePassword')
            ->assertHasNoErrors()->assertSet('showPasswordModal', false)->assertSet('showAccountViewModal', true);
        $this->assertTrue(Hash::check('UpdatedPass123!', $user->fresh()->password));
        $this->assertSame('UpdatedPass123!', $user->fresh()->currentIssuedPassword());
        $this->assertSame($identity, $user->fresh()->only(array_keys($identity)));
    }

    public function test_parent_account_editor_uses_the_compact_modal_layout(): void
    {
        $user = User::factory()->create(['username' => 'parent.compact']);
        $parent = ParentProfile::create(['user_id' => $user->id, 'father_name' => 'Compact Parent']);

        Volt::test('parents.index')->call('openAccountModal', $parent->id)
            ->assertSee('admin-modal__dialog--md', false)
            ->assertDontSee('admin-modal__dialog--4xl', false)
            ->assertSee('class="mt-4 grid gap-3"', false)
            ->assertDontSee('class="mt-4 grid gap-4 md:grid-cols-2"', false);
    }

    public function test_stale_issued_password_is_never_prefilled(): void
    {
        $user = User::factory()->create(['password' => 'ActualPass123!', 'issued_password' => 'StalePass123!']);
        $parent = ParentProfile::create(['user_id' => $user->id, 'father_name' => 'Parent']);
        Volt::test('parents.index')->call('viewAccount', $parent->id)->assertSet('issued_password', null)
            ->call('openPasswordModal')->assertSet('account_password', '')
            ->call('savePassword')->assertHasNoErrors()->assertSet('showPasswordModal', false);
        $this->assertTrue(Hash::check('ActualPass123!', $user->fresh()->password));
    }

    public function test_users_preview_is_read_only_and_forged_edits_are_forbidden_for_all_profile_types(): void
    {
        foreach (['student', 'parent', 'teacher'] as $role) {
            $user = User::factory()->create(['password' => 'PrivatePass123!', 'issued_password' => 'PrivatePass123!']);
            $user->assignRole($role);
            Volt::test('users.index')->call('viewLinkedAccount', $user->id)
                ->assertSee('data-user-readonly-account="'.$user->id.'"', false)
                ->assertDontSee('PrivatePass123!')->assertSet('showFormModal', false)->assertSet('editingId', null);
            Volt::test('users.index')->call('edit', $user->id)->assertForbidden();
            Volt::test('users.index')->set('editingId', $user->id)->set('name', 'Forged')->call('save')->assertForbidden();
            $this->assertNotSame('Forged', $user->fresh()->name);
        }
    }

    public function test_teacher_password_is_prefilled_and_editable_without_rehashing_an_unchanged_password(): void
    {
        $user = User::factory()->create(['password' => 'TeacherPass123!', 'issued_password' => 'TeacherPass123!']);
        $teacher = Teacher::create(['user_id' => $user->id, 'first_name' => 'Test', 'last_name' => 'Teacher', 'phone' => '0999333555', 'status' => 'active']);
        $hash = $user->password;
        $editor = Volt::test('teachers.index')->call('edit', $teacher->id)
            ->assertSet('account_password', 'TeacherPass123!')->call('save')->assertHasNoErrors();
        $this->assertSame($hash, $user->fresh()->password);
        $editor->call('edit', $teacher->id)->set('account_password', 'NewTeacherPass123!')->call('save')->assertHasNoErrors();
        $this->assertTrue(Hash::check('NewTeacherPass123!', $user->fresh()->password));
        $this->assertSame('NewTeacherPass123!', $user->fresh()->currentIssuedPassword());
    }

    public function test_shared_teacher_account_cannot_change_a_parent_username(): void
    {
        $user = User::factory()->create(['username' => 'shared.parent']);
        $user->assignRole('parent');
        $teacher = Teacher::create(['user_id' => $user->id, 'first_name' => 'Shared', 'last_name' => 'Teacher', 'phone' => '0999333666', 'status' => 'active']);
        Volt::test('teachers.index')->call('edit', $teacher->id)->assertViewHas('accountUsernameLocked', true)
            ->set('account_username', 'renamed.parent')->call('save')->assertHasNoErrors();
        $this->assertSame('shared.parent', $user->fresh()->username);
    }

    public function test_shared_teacher_password_is_not_disclosed_to_an_editor_without_account_permission(): void
    {
        $user = User::factory()->create(['password' => 'SharedPrivate123!', 'issued_password' => 'SharedPrivate123!']);
        ParentProfile::create(['user_id' => $user->id, 'father_name' => 'Shared Parent']);
        $teacher = Teacher::create(['user_id' => $user->id, 'first_name' => 'Shared', 'last_name' => 'Teacher', 'phone' => '0999333999', 'status' => 'active']);
        $editor = User::factory()->create();
        $editor->givePermissionTo(['teachers.view', 'teachers.update']);
        app(AccessScopeService::class)->syncUserOverrides($editor, ['teacher' => [$teacher->id]]);
        $this->actingAs($editor);
        Volt::test('teachers.index')->call('edit', $teacher->id)->assertSet('account_password', '')
            ->assertViewHas('canManageAccountLogin', false)->assertDontSee('SharedPrivate123!')
            ->set('account_password', 'ForgedChange123!')->call('save')->assertForbidden();
        $this->assertTrue(Hash::check('SharedPrivate123!', $user->fresh()->password));
    }

    public function test_teacher_delete_button_is_hidden_when_assigned_or_assisting_a_group(): void
    {
        $teacher = Teacher::create(['first_name' => 'Assigned', 'last_name' => 'Teacher', 'phone' => '0999333777', 'status' => 'active']);
        Volt::test('teachers.index')->call('edit', $teacher->id)->assertSee('data-teacher-form-delete-action', false);
        $year = AcademicYear::create(['name' => 'Test Year', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'is_active' => true]);
        $course = Course::create(['academic_year_id' => $year->id, 'name' => 'Test Course', 'is_active' => true]);
        $group = Group::create(['course_id' => $course->id, 'academic_year_id' => $year->id, 'name' => 'Protected Group', 'teacher_id' => $teacher->id, 'is_active' => true]);
        Volt::test('teachers.index')->call('edit', $teacher->id)->assertDontSee('data-teacher-form-delete-action', false)
            ->call('deleteEditingTeacher')->assertHasErrors('delete');
        $otherTeacher = Teacher::create(['first_name' => 'Other', 'last_name' => 'Teacher', 'phone' => '0999333888', 'status' => 'active']);
        $group->update(['teacher_id' => $otherTeacher->id, 'assistant_teacher_id' => $teacher->id]);
        Volt::test('teachers.index')->call('edit', $teacher->id)->assertDontSee('data-teacher-form-delete-action', false);
    }

    public function test_unknown_teacher_password_is_explained_and_is_preserved_until_replaced(): void
    {
        $user = User::factory()->create(['password' => 'UnknownExisting123!', 'issued_password' => null]);
        $teacher = Teacher::create(['user_id' => $user->id, 'first_name' => 'Existing', 'last_name' => 'Teacher', 'phone' => '0999333788', 'status' => 'active']);
        $hash = $user->password;
        $editor = Volt::test('teachers.index')->call('edit', $teacher->id)
            ->assertSet('account_password', '')->assertSee('data-teacher-password-unavailable', false)
            ->call('save')->assertHasNoErrors();
        $this->assertSame($hash, $user->fresh()->password);
        $editor->call('edit', $teacher->id)->set('account_password', 'ReplacementPass123!')->call('save')->assertHasNoErrors()
            ->call('edit', $teacher->id)->assertSet('account_password', 'ReplacementPass123!')
            ->assertDontSee('data-teacher-password-unavailable', false);
        $this->assertTrue(Hash::check('ReplacementPass123!', $user->fresh()->password));
    }

    public function test_users_readonly_phone_preserves_left_to_right_order_in_arabic(): void
    {
        app()->setLocale('ar');
        $user = User::factory()->create(['phone' => '+963999123456']);
        ParentProfile::create(['user_id' => $user->id, 'father_name' => 'Parent']);
        Volt::test('users.index')->call('viewLinkedAccount', $user->id)
            ->assertSee('<bdi dir="ltr">'.e($user->fresh()->phone).'</bdi>', false);
    }
}
