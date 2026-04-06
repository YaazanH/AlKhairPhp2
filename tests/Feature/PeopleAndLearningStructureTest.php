<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\GroupSchedule;
use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PeopleAndLearningStructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_people_and_learning_structure_records_can_be_created(): void
    {
        $parentUser = User::factory()->create([
            'name' => 'Parent User',
            'username' => 'parent-user',
            'phone' => '11111',
        ]);

        $teacherUser = User::factory()->create([
            'name' => 'Teacher User',
            'username' => 'teacher-user',
            'phone' => '22222',
        ]);

        $studentUser = User::factory()->create([
            'name' => 'Student User',
            'username' => 'student-user',
            'phone' => '33333',
        ]);

        $parent = ParentProfile::create([
            'user_id' => $parentUser->id,
            'father_name' => 'Ahmad Ali',
            'father_phone' => '0944000000',
            'mother_name' => 'Mona Ali',
            'mother_phone' => '0944000001',
        ]);

        $teacher = Teacher::create([
            'user_id' => $teacherUser->id,
            'first_name' => 'Yousef',
            'last_name' => 'Teacher',
            'phone' => '0944000002',
        ]);

        $academicYear = AcademicYear::create([
            'name' => '2026/2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
        ]);

        $gradeLevel = GradeLevel::create([
            'name' => 'Grade 6 Test',
            'sort_order' => 16,
        ]);

        $course = Course::create([
            'name' => 'Quran Foundations',
        ]);

        $student = Student::create([
            'user_id' => $studentUser->id,
            'parent_id' => $parent->id,
            'first_name' => 'Omar',
            'last_name' => 'Ali',
            'birth_date' => '2014-05-12',
            'grade_level_id' => $gradeLevel->id,
            'joined_at' => '2026-09-01',
        ]);

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'grade_level_id' => $gradeLevel->id,
            'name' => 'Boys A',
            'capacity' => 20,
            'starts_on' => '2026-09-01',
        ]);

        $schedule = GroupSchedule::create([
            'group_id' => $group->id,
            'day_of_week' => 6,
            'starts_at' => '15:00:00',
            'ends_at' => '17:00:00',
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
        ]);

        $this->assertTrue($parent->user->is($parentUser));
        $this->assertTrue($student->parentProfile->is($parent));
        $this->assertTrue($group->teacher->is($teacher));
        $this->assertTrue($group->course->is($course));
        $this->assertTrue($schedule->group->is($group));
        $this->assertTrue($enrollment->student->is($student));
        $this->assertTrue($enrollment->group->is($group));
    }
}
