<?php

namespace Tests\Feature;

use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\User;
use App\Services\ProfileDuplicateMatcher;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ProfileDuplicateWarningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('manager');
        $this->actingAs($user);
    }

    public function test_names_ignore_birth_year_and_review_and_continue_preserve_the_draft(): void
    {
        $student = Student::create(['first_name' => 'أحمد', 'last_name' => 'الرحمن', 'birth_date' => '2010-01-01', 'status' => 'active']);
        $component = Volt::test('students.index')->call('openCreateModal')
            ->set('first_name', 'احمد')->set('last_name', 'الرحمن')->set('birth_date', '2015')
            ->set('notes', 'Unsaved draft')->assertSee('data-duplicate-warning="first_name"', false)
            ->call('reviewProfileDuplicate', 'first_name')
            ->assertSet('reviewedDuplicate.id', $student->id)->assertSee('2010')
            ->call('continueDuplicateDraft')->assertSet('reviewedDuplicate', null)
            ->assertSet('notes', 'Unsaved draft')->assertSet('birth_date', '2015');

        $this->assertSame(1, Student::count());
        $component->set('birth_date', '2010')->call('save')->assertHasNoErrors();
        $this->assertSame(2, Student::count());
        $this->assertNull($student->fresh()->notes);
    }

    public function test_selecting_existing_student_discards_draft_and_opens_its_edit_modal_without_saving(): void
    {
        $student = Student::create(['first_name' => 'أحمد', 'last_name' => 'الرحمن', 'birth_date' => '2010-01-01', 'status' => 'active']);
        Volt::test('students.index')->call('openCreateModal')
            ->set('first_name', 'أحمد')->set('last_name', 'الرحمن')->set('notes', 'Discard this')
            ->call('reviewProfileDuplicate', 'last_name')->call('useReviewedDuplicate')
            ->assertSet('notes', '')->assertSet('showFormModal', true)
            ->assertSet('editingId', $student->id)
            ->assertSet('first_name', $student->first_name)
            ->assertSet('last_name', $student->last_name)
            ->assertSet('birth_date', '2010')
            ->assertSet('reviewedDuplicate', null)
            ->assertSet('showDuplicateStudentModal', false)
            ->assertSet('acceptedDuplicateNames', [])
            ->assertNoRedirect();
        $this->assertSame(1, Student::count());
        $this->assertNull($student->fresh()->notes);
    }

    public function test_parent_review_can_keep_draft_or_link_existing_parent_without_creating_or_overwriting_it(): void
    {
        $parent = ParentProfile::create(['father_name' => 'محمد الأحمد', 'father_phone' => '+963944555123', 'address' => 'Original']);
        $component = Volt::test('students.index')->call('openCreateModal')->set('first_name', 'Draft student')
            ->call('openQuickParentForm')->set('quick_parent_father_name', 'محمد الاحمد')
            ->set('quick_parent_address', 'Draft address')
            ->call('reviewProfileDuplicate', 'quick_parent_father_name')
            ->assertSet('reviewedDuplicate.id', $parent->id)->assertSee('Original')
            ->call('continueDuplicateDraft')->assertSet('quick_parent_address', 'Draft address')
            ->assertSet('showQuickParentForm', true);
        $component->call('reviewProfileDuplicate', 'quick_parent_father_name')->call('useReviewedDuplicate')
            ->assertSet('parent_id', $parent->id)->assertSet('showQuickParentForm', false)
            ->assertSet('first_name', 'Draft student');
        $this->assertSame(1, ParentProfile::count());
        $this->assertSame('Original', $parent->fresh()->address);
        $this->assertSame(0, Student::count());
    }

    public function test_phone_warnings_normalize_local_and_international_formats_and_check_all_parent_phones(): void
    {
        $user = User::factory()->create(['phone' => '+963944555123']);
        $student = Student::create(['first_name' => 'Phone', 'last_name' => 'Owner', 'user_id' => $user->id, 'birth_date' => '2010-01-01', 'status' => 'active']);
        $parent = ParentProfile::create(['father_name' => 'Existing Parent', 'mother_phone' => '+963944555456']);
        Volt::test('students.index')->call('openCreateModal')->set('student_phone', '0944 555 123')
            ->call('reviewProfileDuplicate', 'student_phone')->assertSet('reviewedDuplicate.id', $student->id)
            ->assertSet('reviewedDuplicate.reason', 'phone')->call('continueDuplicateDraft')
            ->set('first_name', 'Different')->set('last_name', 'Name')->call('save')->assertHasErrors('student_phone')
            ->call('openQuickParentForm')->set('quick_parent_home_phone', '0944555456')
            ->call('reviewProfileDuplicate', 'quick_parent_home_phone')->assertSet('reviewedDuplicate.id', $parent->id)
            ->call('useReviewedDuplicate')->assertSet('parent_id', $parent->id);
    }

    public function test_stale_match_cannot_be_used_after_draft_changes_and_cancel_clears_acknowledgement(): void
    {
        Student::create(['first_name' => 'Ahmad', 'last_name' => 'Rahman', 'birth_date' => '2010-01-01', 'status' => 'active']);
        Volt::test('students.index')->call('openCreateModal')->set('first_name', 'Ahmad')->set('last_name', 'Rahman')
            ->call('reviewProfileDuplicate', 'first_name')->set('last_name', 'Different')
            ->call('useReviewedDuplicate')->assertStatus(404);
        Volt::test('students.index')->call('openCreateModal')->set('first_name', 'Ahmad')->set('last_name', 'Rahman')
            ->call('reviewProfileDuplicate', 'first_name')->call('continueDuplicateDraft')
            ->call('cancel')->assertSet('acceptedDuplicateNames', []);
    }

    public function test_parent_can_continue_with_the_same_names_for_a_different_family_but_not_a_used_phone(): void
    {
        ParentProfile::create(['father_name' => 'محمد الأحمد', 'mother_name' => 'فاطمة الأحمد', 'father_phone' => '0944555123']);
        $component = Volt::test('students.index')->call('openCreateModal')->call('openQuickParentForm')
            ->set('quick_parent_father_name', 'محمد الأحمد')->set('quick_parent_mother_name', 'فاطمة الأحمد')
            ->call('reviewProfileDuplicate', 'quick_parent_father_name')->call('continueDuplicateDraft')
            ->set('quick_parent_father_phone', '+963944555123')->call('saveQuickParent')
            ->assertHasErrors('quick_parent_father_name');
        $this->assertSame(1, ParentProfile::count());
        $component->set('quick_parent_father_phone', '')->call('saveQuickParent')->assertHasNoErrors();
        $this->assertSame(2, ParentProfile::count());
    }

    public function test_warnings_do_not_expose_profiles_outside_the_users_access_scope(): void
    {
        $user = User::factory()->create();
        $user->assignRole('teacher');
        $user->givePermissionTo(['students.view', 'students.create', 'parents.create']);
        $this->actingAs($user);
        Student::create(['first_name' => 'Hidden', 'last_name' => 'Student', 'birth_date' => '2010-01-01', 'status' => 'active']);
        ParentProfile::create(['father_name' => 'Hidden Parent']);
        Volt::test('students.index')->call('openCreateModal')->set('first_name', 'Hidden')->set('last_name', 'Student')
            ->call('openQuickParentForm')->set('quick_parent_father_name', 'Hidden Parent')
            ->assertDontSee('data-duplicate-warning=', false)
            ->call('reviewProfileDuplicate', 'first_name')->assertStatus(404);
    }

    public function test_matcher_handles_arabic_spelling_variants_and_rejects_short_or_unrelated_names(): void
    {
        $matcher = app(ProfileDuplicateMatcher::class);
        $this->assertTrue($matcher->similarName('أحمد الرحمن', 'احمد رحمن'));
        $this->assertTrue($matcher->similarName('محمد الاحمد', 'محمد الاحمدد'));
        $this->assertFalse($matcher->similarName('محمد الاحمد', 'محمود الخطيب'));
        $this->assertFalse($matcher->similarName('أح', 'أحمد'));
        $this->assertFalse($matcher->samePhone('', ''));
    }
}
