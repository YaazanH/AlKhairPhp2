<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AppSetting;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\ParentProfile;
use App\Models\QuranFinalTest;
use App\Models\QuranJuz;
use App\Models\QuranPartialTest;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\SidebarNavigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class QuickQuranTestEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_quick_saber_entry_shows_input_suffixes_and_replaces_the_form_when_entries_are_disabled(): void
    {
        $this->context();

        Volt::test('quran-tests.quick-entry')
            ->assertSee('quick-saber-affixed-input', false)
            ->assertSee('data-quick-saber-partial-save-action', false)
            ->assertSee('data-icon-name="save"', false)
            ->assertSee(__('quick-tests.mistakes_suffix'))
            ->assertSee(__('workflow.common.student_name_placeholder'));

        Volt::test('quran-tests.quick-entry')
            ->set('tab', 'final')
            ->assertSee('quick-saber-affixed-input', false)
            ->assertSee('data-quick-saber-final-save-action', false)
            ->assertSee('>%</span>', false);

        $source = file_get_contents(resource_path('views/livewire/quran-tests/quick-entry.blade.php'));
        $this->assertStringContainsString('data-quick-saber-current-juz-save-action', $source);
        $this->assertSame(2, substr_count($source, 'quick-saber-save-action quick-entry-save-action'));

        $memorizationSource = file_get_contents(resource_path('views/livewire/memorization/quick-entry.blade.php'));
        $this->assertStringContainsString('admin-icon-button admin-icon-button--accent quick-entry-save-action', $memorizationSource);

        $styles = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('.quick-entry-save-action {', $styles);
        $this->assertStringContainsString('flex: 1 1 100% !important;', $styles);

        AppSetting::storeValue('general', 'memorization_saber_entries_enabled', false, 'boolean');

        Volt::test('quran-tests.quick-entry')
            ->assertSee('data-quick-entry-disabled', false)
            ->assertSee(__('quick-tests.saber_disabled_warning'))
            ->assertSee(__('quick-tests.disabled_help'))
            ->assertDontSee('data-quick-entry-help', false)
            ->assertDontSee('wire:submit="savePartial"', false)
            ->assertDontSee('wire:submit="saveFinal"', false);
    }

    public function test_quick_entry_requires_permission_and_is_visible_to_super_admins_without_teacher_profiles(): void
    {
        $this->seed();

        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $this->actingAs($manager)
            ->get(route('saber-entry.index', absolute: false))
            ->assertForbidden();
        $this->assertFalse($this->sidebarHasQuickEntry($manager));

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $this->actingAs($superAdmin)
            ->get(route('saber-entry.index', absolute: false))
            ->assertOk();
        $this->assertTrue($this->sidebarHasQuickEntry($superAdmin));
        $this->assertSame('book-open-pencil', $this->quickEntrySidebarItem($superAdmin)['icon']);

        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('teacher');
        Teacher::query()->create([
            'user_id' => $teacherUser->id,
            'first_name' => 'Quick',
            'last_name' => 'Recorder',
            'phone' => '0944000201',
            'status' => 'active',
        ]);

        $this->actingAs($teacherUser)
            ->get(route('saber-entry.index', absolute: false))
            ->assertForbidden();
        $this->assertFalse($this->sidebarHasQuickEntry($teacherUser));

        $teacherUser->givePermissionTo('quran-tests.quick-entry');

        $this->actingAs($teacherUser)
            ->get(route('saber-entry.index', absolute: false))
            ->assertOk();
        $this->assertTrue($this->sidebarHasQuickEntry($teacherUser->fresh()));
    }

    public function test_quick_entry_records_only_available_partial_quarters_and_final_attempts(): void
    {
        [$teacherUser, $teacher, $student, $enrollment, $juz] = $this->context();
        $nextJuz = QuranJuz::query()->firstOrCreate(
            ['juz_number' => 2],
            ['name' => 'Juz 2', 'from_page' => 22, 'to_page' => 41],
        );

        $partialTest = QuranPartialTest::query()->create([
            'created_by' => $teacherUser->id,
            'enrollment_id' => $enrollment->id,
            'juz_id' => $juz->id,
            'status' => 'in_progress',
            'student_id' => $student->id,
        ]);
        foreach (range(1, 4) as $partNumber) {
            $partialTest->parts()->create(['part_number' => $partNumber, 'status' => 'pending']);
        }

        Volt::test('quran-tests.quick-entry')
            ->set('partialStudentId', $student->id)
            ->assertSet('partialJuzId', $juz->id)
            ->assertSet('partialQuarter', 1)
            ->set('partialQuarter', 1)
            ->set('mistakeCount', '1')
            ->call('savePartial')
            ->assertHasNoErrors()
            ->assertSet('partialStudentId', null)
            ->assertSet('partialJuzId', null)
            ->assertSet('partialQuarter', null)
            ->assertSet('mistakeCount', '');

        $this->assertDatabaseHas('quran_partial_test_attempts', [
            'quran_partial_test_part_id' => $partialTest->parts()->where('part_number', 1)->value('id'),
            'teacher_id' => $teacher->id,
            'mistake_count' => 1,
        ]);
        $this->assertDatabaseMissing('quran_partial_test_attempts', [
            'quran_partial_test_part_id' => $partialTest->parts()->where('part_number', 3)->value('id'),
        ]);

        $partialTest->parts()->whereIn('part_number', [2, 3])->update(['status' => 'passed']);

        Volt::test('quran-tests.quick-entry')
            ->set('partialStudentId', $student->id)
            ->assertSet('partialQuarter', 4);

        $partialAttempt = $partialTest->parts()->where('part_number', 1)->firstOrFail()->attempts()->firstOrFail();
        Volt::test('quran-partial-tests.show', ['partialTest' => $partialTest])
            ->call('openEditAttempt', $partialAttempt->id)
            ->assertSet('showAttemptModal', true)
            ->assertSee('quick-saber-affixed-input__suffix', false)
            ->assertSee('أخطاء')
            ->assertSet('teacher_id', $teacher->id)
            ->assertSet('tested_on', now()->toDateString())
            ->assertSet('mistake_count', '1')
            ->set('mistake_count', '2')
            ->call('saveAttempt')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('quran_partial_test_attempts', [
            'id' => $partialAttempt->id,
            'mistake_count' => 2,
        ]);

        $finalTest = QuranFinalTest::query()->create([
            'created_by' => $teacherUser->id,
            'enrollment_id' => $enrollment->id,
            'juz_id' => $juz->id,
            'status' => 'in_progress',
            'student_id' => $student->id,
        ]);

        $finalEntry = Volt::test('quran-tests.quick-entry')
            ->set('tab', 'final')
            ->set('finalStudentId', $student->id)
            ->assertSet('finalJuzId', $juz->id)
            ->assertSet('finalTestedOn', now()->toDateString())
            ->set('finalTestedOn', '2026-08-21')
            ->set('finalMark', '95')
            ->call('saveFinal')
            ->assertHasNoErrors()
            ->assertSet('showCurrentJuzModal', true)
            ->assertSee('wire:model="newCurrentJuzNumber" type="number"', false)
            ->assertSet('passedFinalTestId', $finalTest->id)
            ->assertSet('finalStudentId', null)
            ->assertSet('finalJuzId', null)
            ->assertSet('finalTestedOn', now()->toDateString())
            ->assertSet('finalMark', '');

        $finalEntry
            ->set('newCurrentJuzNumber', (string) $nextJuz->juz_number)
            ->call('saveCurrentJuz')
            ->assertHasNoErrors()
            ->assertSet('showCurrentJuzModal', false);

        $this->assertSame($nextJuz->id, $student->fresh()->quran_current_juz_id);

        $this->assertDatabaseHas('quran_final_test_attempts', [
            'quran_final_test_id' => $finalTest->id,
            'teacher_id' => $teacher->id,
            'score' => 95,
            'tested_on' => '2026-08-21 00:00:00',
        ]);

        $finalAttempt = $finalTest->attempts()->firstOrFail();
        Volt::test('quran-final-tests.show', ['finalTest' => $finalTest])
            ->call('openEditAttempt', $finalAttempt->id)
            ->assertSet('showAttemptModal', true)
            ->assertSee('quick-saber-affixed-input__suffix', false)
            ->assertSee('>%</span>', false)
            ->assertSet('teacher_id', $teacher->id)
            ->assertSet('tested_on', '2026-08-21')
            ->assertSet('score', '95')
            ->set('score', '94')
            ->call('saveAttempt')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('quran_final_test_attempts', [
            'id' => $finalAttempt->id,
            'score' => 94,
        ]);

        foreach (['quran-partial-tests.index', 'quran-final-tests.index'] as $component) {
            Volt::test($component)
                ->assertSee('Quick Entry Course')
                ->assertDontSee('Quick Entry Group')
                ->assertDontSee('Quick Parent');
        }
    }

    public function test_quick_entry_hides_the_type_selector_when_only_one_saber_type_is_allowed(): void
    {
        $this->seed();

        $partialOnly = User::factory()->create();
        $partialOnly->givePermissionTo(['quran-tests.quick-entry', 'quran-partial-tests.record']);

        $this->actingAs($partialOnly);
        Volt::test('quran-tests.quick-entry')
            ->assertSet('tab', 'partial')
            ->assertSet('canRecordPartial', true)
            ->assertSet('canRecordFinal', false)
            ->assertDontSee('quick-saber-type-switch', false);

        $finalOnly = User::factory()->create();
        $finalOnly->givePermissionTo(['quran-tests.quick-entry', 'quran-final-tests.record']);

        $this->actingAs($finalOnly);
        Volt::test('quran-tests.quick-entry')
            ->assertSet('tab', 'final')
            ->assertSet('canRecordPartial', false)
            ->assertSet('canRecordFinal', true)
            ->assertSet('finalTestedOn', now()->toDateString())
            ->assertSet('finalMark', '')
            ->assertDontSee('quick-saber-type-switch', false);
    }

    public function test_switching_saber_type_clears_both_entry_forms(): void
    {
        [, , $student] = $this->context();

        $component = Volt::test('quran-tests.quick-entry')
            ->set('partialStudentId', $student->id)
            ->set('partialQuarter', 2)
            ->set('mistakeCount', '3')
            ->call('switchTab', 'final')
            ->assertSet('tab', 'final')
            ->assertSet('partialStudentId', null)
            ->assertSet('partialJuzId', null)
            ->assertSet('partialQuarter', null)
            ->assertSet('mistakeCount', '');

        $component
            ->set('finalStudentId', $student->id)
            ->set('finalMark', '92')
            ->call('switchTab', 'partial')
            ->assertSet('tab', 'partial')
            ->assertSet('finalStudentId', null)
            ->assertSet('finalJuzId', null)
            ->assertSet('finalTestedOn', now()->toDateString())
            ->assertSet('finalMark', '');
    }

    public function test_quick_entry_records_under_the_teacher_linked_to_the_logged_in_account(): void
    {
        [$teacherUser, $accountTeacher, $student, $enrollment, $juz] = $this->context();
        $teacherUser->syncRoles('manager');

        $groupTeacherUser = User::factory()->create();
        $groupTeacher = Teacher::query()->create([
            'user_id' => $groupTeacherUser->id,
            'first_name' => 'Group',
            'last_name' => 'Teacher',
            'phone' => '0944000203',
            'status' => 'active',
        ]);
        $enrollment->group()->update(['teacher_id' => $groupTeacher->id]);

        $partialTest = QuranPartialTest::query()->create([
            'created_by' => $teacherUser->id,
            'enrollment_id' => $enrollment->id,
            'juz_id' => $juz->id,
            'status' => 'in_progress',
            'student_id' => $student->id,
        ]);
        foreach (range(1, 4) as $partNumber) {
            $partialTest->parts()->create(['part_number' => $partNumber, 'status' => 'pending']);
        }

        $this->actingAs($teacherUser->fresh());
        Volt::test('quran-tests.quick-entry')
            ->set('partialStudentId', $student->id)
            ->set('partialQuarter', 1)
            ->set('mistakeCount', '0')
            ->call('savePartial')
            ->assertHasNoErrors();

        $attempt = $partialTest->parts()->where('part_number', 1)->firstOrFail()->attempts()->firstOrFail();
        $this->assertSame($accountTeacher->id, $attempt->teacher_id);
        $this->assertNotSame($groupTeacher->id, $attempt->teacher_id);
    }

    public function test_teacher_with_quick_entry_permission_can_select_students_outside_their_groups(): void
    {
        [$teacherUser] = $this->context();

        $otherTeacher = Teacher::query()->create([
            'first_name' => 'Other',
            'last_name' => 'Teacher',
            'phone' => '0944000204',
            'status' => 'active',
        ]);
        $otherCourse = Course::query()->create(['name' => 'Other Active Course', 'is_active' => true]);
        $otherGroup = Group::query()->create([
            'course_id' => $otherCourse->id,
            'academic_year_id' => AcademicYear::query()->where('is_current', true)->value('id'),
            'teacher_id' => $otherTeacher->id,
            'name' => 'Other Teacher Group',
            'capacity' => 20,
            'is_active' => true,
        ]);
        $otherStudent = Student::query()->create([
            'first_name' => 'Outside',
            'last_name' => 'Student',
            'birth_date' => '2014-02-02',
            'status' => 'active',
        ]);
        Enrollment::query()->create([
            'student_id' => $otherStudent->id,
            'group_id' => $otherGroup->id,
            'enrolled_at' => now()->toDateString(),
            'status' => 'active',
        ]);

        $this->actingAs($teacherUser->fresh());

        Volt::test('quran-tests.quick-entry')
            ->assertSee('Outside Student');
    }

    private function context(): array
    {
        $this->seed();

        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('teacher');
        $teacherUser->givePermissionTo('quran-tests.quick-entry');
        $teacher = Teacher::query()->create([
            'user_id' => $teacherUser->id,
            'first_name' => 'Quick',
            'last_name' => 'Recorder',
            'phone' => '0944000202',
            'status' => 'active',
        ]);
        $course = Course::query()->create(['name' => 'Quick Entry Course', 'is_active' => true]);
        $group = Group::query()->create([
            'course_id' => $course->id,
            'academic_year_id' => AcademicYear::query()->where('is_current', true)->value('id'),
            'teacher_id' => $teacher->id,
            'name' => 'Quick Entry Group',
            'capacity' => 20,
            'is_active' => true,
        ]);
        $parent = ParentProfile::query()->create(['father_name' => 'Quick Parent']);
        $student = Student::query()->create([
            'parent_id' => $parent->id,
            'first_name' => 'Quick',
            'last_name' => 'Student',
            'birth_date' => '2014-01-01',
            'status' => 'active',
        ]);
        $enrollment = Enrollment::query()->create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => now()->toDateString(),
            'status' => 'active',
        ]);
        $juz = QuranJuz::query()->firstOrCreate(
            ['juz_number' => 1],
            ['name' => 'Juz 1', 'from_page' => 1, 'to_page' => 21],
        );

        $this->actingAs($teacherUser);

        return [$teacherUser, $teacher, $student, $enrollment, $juz];
    }

    private function sidebarHasQuickEntry(User $user): bool
    {
        return $this->quickEntrySidebarItem($user) !== null;
    }

    private function quickEntrySidebarItem(User $user): ?array
    {
        return collect(app(SidebarNavigationService::class)->sidebarFor($user))
            ->pluck('items')
            ->flatten(1)
            ->firstWhere('key', 'quran_tests_quick_entry');
    }
}
