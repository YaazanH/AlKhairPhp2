<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
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

    public function test_quick_entry_requires_an_explicit_permission_and_linked_teacher(): void
    {
        $this->seed();

        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $this->actingAs($manager)
            ->get(route('quran-tests.quick-entry', absolute: false))
            ->assertForbidden();
        $this->assertFalse($this->sidebarHasQuickEntry($manager));

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
            ->get(route('quran-tests.quick-entry', absolute: false))
            ->assertForbidden();
        $this->assertFalse($this->sidebarHasQuickEntry($teacherUser));

        $teacherUser->givePermissionTo('quran-tests.quick-entry');

        $this->actingAs($teacherUser)
            ->get(route('quran-tests.quick-entry', absolute: false))
            ->assertOk();
        $this->assertTrue($this->sidebarHasQuickEntry($teacherUser->fresh()));
    }

    public function test_quick_entry_records_only_available_partial_quarters_and_final_attempts(): void
    {
        [$teacherUser, $teacher, $student, $enrollment, $juz] = $this->context();

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
            ->set('partialQuarters', [1, 3])
            ->set('mistakeCount', '1')
            ->call('savePartial')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('quran_partial_test_attempts', [
            'quran_partial_test_part_id' => $partialTest->parts()->where('part_number', 1)->value('id'),
            'teacher_id' => $teacher->id,
            'mistake_count' => 1,
        ]);
        $this->assertDatabaseHas('quran_partial_test_attempts', [
            'quran_partial_test_part_id' => $partialTest->parts()->where('part_number', 3)->value('id'),
            'teacher_id' => $teacher->id,
            'mistake_count' => 1,
        ]);

        $finalTest = QuranFinalTest::query()->create([
            'created_by' => $teacherUser->id,
            'enrollment_id' => $enrollment->id,
            'juz_id' => $juz->id,
            'status' => 'in_progress',
            'student_id' => $student->id,
        ]);

        Volt::test('quran-tests.quick-entry')
            ->set('tab', 'final')
            ->set('finalStudentId', $student->id)
            ->assertSet('finalJuzId', $juz->id)
            ->set('finalMark', '95')
            ->call('saveFinal')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('quran_final_test_attempts', [
            'quran_final_test_id' => $finalTest->id,
            'teacher_id' => $teacher->id,
            'score' => 95,
        ]);
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
        return collect(app(SidebarNavigationService::class)->sidebarFor($user))
            ->pluck('items')
            ->flatten(1)
            ->contains('key', 'quran_tests_quick_entry');
    }
}
