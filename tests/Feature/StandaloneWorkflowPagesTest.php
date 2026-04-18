<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\ParentProfile;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\QuranJuz;
use App\Models\QuranTestType;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class StandaloneWorkflowPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_quran_test_workbench_uses_the_logged_in_teacher(): void
    {
        [$teacher, $enrollment] = $this->teacherContext();
        $partial = QuranTestType::query()->where('code', 'partial')->firstOrFail();
        $juz = QuranJuz::query()->where('juz_number', 1)->firstOrFail();

        Volt::test('quran-tests.index')
            ->set('selectedStudentId', $enrollment->student_id)
            ->set('juz_id', $juz->id)
            ->set('quran_test_type_id', $partial->id)
            ->set('tested_on', '2026-09-10')
            ->set('score', '95')
            ->set('status', 'passed')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('quran_tests', [
            'enrollment_id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'teacher_id' => $teacher->id,
            'juz_id' => $juz->id,
            'quran_test_type_id' => $partial->id,
            'status' => 'passed',
        ]);
    }

    public function test_manager_point_ledger_workbench_creates_and_updates_manual_entries(): void
    {
        $enrollment = $this->managerContext();
        $bonus = PointType::query()->create([
            'name' => 'Workbench Reward',
            'code' => 'workbench-reward',
            'category' => 'manual',
            'default_points' => 5,
            'allow_manual_entry' => true,
            'allow_negative' => false,
            'is_active' => true,
        ]);

        Volt::test('points.index')
            ->set('selectedStudentId', $enrollment->student_id)
            ->set('manual_point_type_id', $bonus->id)
            ->call('saveManual')
            ->assertHasNoErrors();

        $transaction = PointTransaction::query()->where('source_type', 'manual')->firstOrFail();

        Volt::test('points.index')
            ->call('editManual', $transaction->id)
            ->call('saveManual')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('point_transactions', [
            'id' => $transaction->id,
            'points' => 5,
            'notes' => null,
        ]);
    }

    private function teacherContext(): array
    {
        $this->seed();

        $teacherUser = User::factory()->create([
            'username' => 'workbench-teacher',
            'phone' => '0998333000',
        ]);
        $teacherUser->assignRole('teacher');

        $teacher = Teacher::create([
            'user_id' => $teacherUser->id,
            'first_name' => 'Workbench',
            'last_name' => 'Teacher',
            'phone' => '0998333001',
            'status' => 'active',
        ]);

        $enrollment = $this->makeEnrollment($teacher->id, 'Workbench Teacher Group');

        $this->actingAs($teacherUser);

        return [$teacher, $enrollment];
    }

    private function managerContext(): Enrollment
    {
        $this->seed();

        $manager = User::factory()->create([
            'username' => 'workbench-manager',
            'phone' => '0998444000',
        ]);
        $manager->assignRole('manager');

        $teacher = Teacher::create([
            'first_name' => 'Manager',
            'last_name' => 'Teacher',
            'phone' => '0998444001',
            'status' => 'active',
        ]);

        $enrollment = $this->makeEnrollment($teacher->id, 'Workbench Manager Group');

        $this->actingAs($manager);

        return $enrollment;
    }

    private function makeEnrollment(int $teacherId, string $groupName): Enrollment
    {
        $parent = ParentProfile::create([
            'father_name' => $groupName.' Parent',
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => $groupName,
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => $groupName.' Course',
            'is_active' => true,
        ]);

        $yearId = \App\Models\AcademicYear::query()->where('is_current', true)->value('id');

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $yearId,
            'teacher_id' => $teacherId,
            'name' => $groupName,
            'capacity' => 12,
            'is_active' => true,
        ]);

        return Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);
    }
}
