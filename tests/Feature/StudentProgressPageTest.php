<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\AssessmentType;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\MemorizationSession;
use App\Models\ParentProfile;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\QuranJuz;
use App\Models\QuranTest;
use App\Models\QuranTestType;
use App\Models\Student;
use App\Models\StudentNote;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class StudentProgressPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_can_view_only_their_child_progress(): void
    {
        $this->seed(RoleSeeder::class);

        [$parentUser, $ownStudent, $otherStudent] = $this->makeScopedProgressData();

        $this->actingAs($parentUser);

        $this->get(route('students.progress', $ownStudent, absolute: false))
            ->assertOk()
            ->assertSeeText('Parent Student')
            ->assertSeeText('Parent Group')
            ->assertSeeText('Weekly Quiz')
            ->assertSeeText('88.00')
            ->assertSeeText('Teacher Shared Note')
            ->assertDontSeeText('Other Student')
            ->assertDontSeeText('Other Group')
            ->assertDontSeeText('Hidden Quiz')
            ->assertDontSeeText('Other Shared Note');

        $this->get(route('students.progress', $otherStudent, absolute: false))
            ->assertForbidden();
    }

    public function test_student_can_view_only_their_own_progress(): void
    {
        $this->seed(RoleSeeder::class);

        [$studentUser, $ownStudent, $otherStudent] = $this->makeStudentScopedProgressData();

        $this->actingAs($studentUser);

        $this->get(route('students.progress', $ownStudent, absolute: false))
            ->assertOk()
            ->assertSeeText('Scoped Student')
            ->assertSeeText('Student Scope Group')
            ->assertSeeText('Monthly Quiz')
            ->assertSeeText('91.00')
            ->assertDontSeeText('Other Student')
            ->assertDontSeeText('Other Scope Group');

        $this->get(route('students.progress', $otherStudent, absolute: false))
            ->assertForbidden();
    }

    public function test_student_progress_can_filter_by_course_and_summarize_points_by_type(): void
    {
        $this->seed(RoleSeeder::class);

        [$parentUser, $ownStudent] = $this->makeScopedProgressData();

        $this->actingAs($parentUser);

        $secondaryTeacher = Teacher::create([
            'first_name' => 'Huda',
            'last_name' => 'Teacher',
            'phone' => '0999000003',
            'status' => 'active',
        ]);

        $secondaryCourse = Course::create([
            'name' => 'Revision Track',
            'is_active' => true,
        ]);

        $academicYear = AcademicYear::query()->where('is_current', true)->firstOrFail();

        $secondaryGroup = Group::create([
            'course_id' => $secondaryCourse->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $secondaryTeacher->id,
            'name' => 'Parent Secondary Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $secondaryEnrollment = Enrollment::create([
            'student_id' => $ownStudent->id,
            'group_id' => $secondaryGroup->id,
            'enrolled_at' => '2026-10-01',
            'status' => 'active',
            'final_points_cached' => 20,
            'memorized_pages_cached' => 9,
        ]);

        $secondaryQuizType = AssessmentType::create([
            'name' => 'Review Quiz',
            'code' => 'review_quiz',
            'is_scored' => true,
            'is_active' => true,
        ]);

        $secondaryAssessment = Assessment::create([
            'group_id' => $secondaryGroup->id,
            'assessment_type_id' => $secondaryQuizType->id,
            'title' => 'Course Filter Quiz',
            'total_mark' => 100,
            'pass_mark' => 50,
            'is_active' => true,
        ]);

        AssessmentResult::create([
            'assessment_id' => $secondaryAssessment->id,
            'enrollment_id' => $secondaryEnrollment->id,
            'student_id' => $ownStudent->id,
            'teacher_id' => $secondaryTeacher->id,
            'score' => 73,
            'status' => 'passed',
            'attempt_no' => 1,
        ]);

        $primaryPointType = PointType::query()->where('code', 'quiz_reward')->firstOrFail();
        PointTransaction::create([
            'student_id' => $ownStudent->id,
            'enrollment_id' => Enrollment::query()->where('student_id', $ownStudent->id)->whereHas('group', fn ($query) => $query->where('name', 'Parent Group'))->firstOrFail()->id,
            'point_type_id' => $primaryPointType->id,
            'source_type' => 'manual',
            'points' => 4,
            'entered_by' => $parentUser->id,
            'entered_at' => now()->addMinute(),
            'notes' => 'Second primary points',
        ]);

        $secondaryPointType = PointType::create([
            'name' => 'Secondary Bonus',
            'code' => 'secondary_bonus',
            'category' => 'bonus',
            'default_points' => 3,
            'allow_manual_entry' => true,
            'allow_negative' => false,
            'is_active' => true,
        ]);

        PointTransaction::create([
            'student_id' => $ownStudent->id,
            'enrollment_id' => $secondaryEnrollment->id,
            'point_type_id' => $secondaryPointType->id,
            'source_type' => 'manual',
            'points' => 11,
            'entered_by' => $parentUser->id,
            'entered_at' => now()->addMinutes(2),
            'notes' => 'Secondary course points',
        ]);

        StudentNote::create([
            'student_id' => $ownStudent->id,
            'enrollment_id' => $secondaryEnrollment->id,
            'author_id' => $parentUser->id,
            'source' => 'teacher',
            'visibility' => 'visible_to_parent',
            'body' => 'Second Course Note',
            'noted_at' => now(),
        ]);

        Volt::test('students.progress', ['student' => $ownStudent])
            ->set('courseFilter', (string) Course::query()->where('name', 'Quran Track')->firstOrFail()->id)
            ->assertSeeText('Parent Group')
            ->assertSeeText('Quiz Reward')
            ->assertSeeText('2 حركة')
            ->assertDontSeeText('Parent Secondary Group')
            ->assertDontSeeText('Course Filter Quiz')
            ->assertDontSeeText('Secondary Bonus')
            ->assertDontSeeText('Second Course Note');
    }

    private function makeScopedProgressData(): array
    {
        $parentUser = User::factory()->create([
            'username' => 'parent-progress',
            'phone' => '8111001',
        ]);
        $parentUser->assignRole('parent');

        $parent = ParentProfile::create([
            'user_id' => $parentUser->id,
            'father_name' => 'Scoped Parent',
            'is_active' => true,
        ]);

        $otherParent = ParentProfile::create([
            'father_name' => 'Other Parent',
            'is_active' => true,
        ]);

        $teacher = Teacher::create([
            'first_name' => 'Salim',
            'last_name' => 'Teacher',
            'phone' => '0999000001',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Quran Track',
            'is_active' => true,
        ]);

        $academicYear = AcademicYear::create([
            'name' => '2026/2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);

        $ownGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Parent Group',
            'capacity' => 12,
            'is_active' => true,
        ]);

        $otherGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Other Group',
            'capacity' => 12,
            'is_active' => true,
        ]);

        $juz = QuranJuz::create([
            'juz_number' => 1,
            'from_page' => 1,
            'to_page' => 20,
        ]);

        $quizType = AssessmentType::create([
            'name' => 'Quiz',
            'code' => 'quiz',
            'is_scored' => true,
            'is_active' => true,
        ]);

        $partialType = QuranTestType::create([
            'name' => 'Partial',
            'code' => 'partial',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $pointType = PointType::create([
            'name' => 'Quiz Reward',
            'code' => 'quiz_reward',
            'category' => 'bonus',
            'default_points' => 5,
            'allow_manual_entry' => true,
            'allow_negative' => false,
            'is_active' => true,
        ]);

        $ownStudent = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Parent',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'quran_current_juz_id' => $juz->id,
            'status' => 'active',
        ]);

        $otherStudent = Student::create([
            'parent_id' => $otherParent->id,
            'first_name' => 'Other',
            'last_name' => 'Student',
            'birth_date' => '2014-05-13',
            'quran_current_juz_id' => $juz->id,
            'status' => 'active',
        ]);

        $ownEnrollment = Enrollment::create([
            'student_id' => $ownStudent->id,
            'group_id' => $ownGroup->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
            'final_points_cached' => 12,
            'memorized_pages_cached' => 6,
        ]);

        $otherEnrollment = Enrollment::create([
            'student_id' => $otherStudent->id,
            'group_id' => $otherGroup->id,
            'enrolled_at' => '2026-09-02',
            'status' => 'active',
            'final_points_cached' => 9,
            'memorized_pages_cached' => 4,
        ]);

        $ownAssessment = Assessment::create([
            'group_id' => $ownGroup->id,
            'assessment_type_id' => $quizType->id,
            'title' => 'Weekly Quiz',
            'total_mark' => 100,
            'pass_mark' => 50,
            'is_active' => true,
        ]);

        $otherAssessment = Assessment::create([
            'group_id' => $otherGroup->id,
            'assessment_type_id' => $quizType->id,
            'title' => 'Hidden Quiz',
            'total_mark' => 100,
            'pass_mark' => 50,
            'is_active' => true,
        ]);

        AssessmentResult::create([
            'assessment_id' => $ownAssessment->id,
            'enrollment_id' => $ownEnrollment->id,
            'student_id' => $ownStudent->id,
            'teacher_id' => $teacher->id,
            'score' => 88,
            'status' => 'passed',
            'attempt_no' => 1,
        ]);

        AssessmentResult::create([
            'assessment_id' => $otherAssessment->id,
            'enrollment_id' => $otherEnrollment->id,
            'student_id' => $otherStudent->id,
            'teacher_id' => $teacher->id,
            'score' => 77,
            'status' => 'passed',
            'attempt_no' => 1,
        ]);

        MemorizationSession::create([
            'enrollment_id' => $ownEnrollment->id,
            'student_id' => $ownStudent->id,
            'teacher_id' => $teacher->id,
            'recorded_on' => '2026-09-10',
            'entry_type' => 'new',
            'from_page' => 1,
            'to_page' => 3,
            'pages_count' => 3,
        ]);

        MemorizationSession::create([
            'enrollment_id' => $otherEnrollment->id,
            'student_id' => $otherStudent->id,
            'teacher_id' => $teacher->id,
            'recorded_on' => '2026-09-11',
            'entry_type' => 'new',
            'from_page' => 4,
            'to_page' => 5,
            'pages_count' => 2,
        ]);

        QuranTest::create([
            'enrollment_id' => $ownEnrollment->id,
            'student_id' => $ownStudent->id,
            'teacher_id' => $teacher->id,
            'juz_id' => $juz->id,
            'quran_test_type_id' => $partialType->id,
            'tested_on' => '2026-09-12',
            'score' => 92,
            'status' => 'passed',
            'attempt_no' => 1,
        ]);

        QuranTest::create([
            'enrollment_id' => $otherEnrollment->id,
            'student_id' => $otherStudent->id,
            'teacher_id' => $teacher->id,
            'juz_id' => $juz->id,
            'quran_test_type_id' => $partialType->id,
            'tested_on' => '2026-09-13',
            'score' => 80,
            'status' => 'passed',
            'attempt_no' => 1,
        ]);

        PointTransaction::create([
            'student_id' => $ownStudent->id,
            'enrollment_id' => $ownEnrollment->id,
            'point_type_id' => $pointType->id,
            'source_type' => 'manual',
            'points' => 6,
            'entered_by' => $parentUser->id,
            'entered_at' => now(),
            'notes' => 'Parent visible points',
        ]);

        PointTransaction::create([
            'student_id' => $otherStudent->id,
            'enrollment_id' => $otherEnrollment->id,
            'point_type_id' => $pointType->id,
            'source_type' => 'manual',
            'points' => 4,
            'entered_by' => $parentUser->id,
            'entered_at' => now(),
            'notes' => 'Hidden points',
        ]);

        StudentNote::create([
            'student_id' => $ownStudent->id,
            'enrollment_id' => $ownEnrollment->id,
            'author_id' => $parentUser->id,
            'source' => 'teacher',
            'visibility' => 'visible_to_parent',
            'body' => 'Teacher Shared Note',
            'noted_at' => now(),
        ]);

        StudentNote::create([
            'student_id' => $otherStudent->id,
            'enrollment_id' => $otherEnrollment->id,
            'author_id' => $parentUser->id,
            'source' => 'teacher',
            'visibility' => 'visible_to_parent',
            'body' => 'Other Shared Note',
            'noted_at' => now(),
        ]);

        return [$parentUser, $ownStudent, $otherStudent];
    }

    private function makeStudentScopedProgressData(): array
    {
        $studentUser = User::factory()->create([
            'username' => 'student-progress',
            'phone' => '8111002',
        ]);
        $studentUser->assignRole('student');

        $parent = ParentProfile::create([
            'father_name' => 'Student Parent',
            'is_active' => true,
        ]);

        $teacher = Teacher::create([
            'first_name' => 'Alaa',
            'last_name' => 'Teacher',
            'phone' => '0999000002',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Revision Track',
            'is_active' => true,
        ]);

        $academicYear = AcademicYear::create([
            'name' => '2026/2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);

        $ownGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Student Scope Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $otherGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Other Scope Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $juz = QuranJuz::create([
            'juz_number' => 2,
            'from_page' => 21,
            'to_page' => 40,
        ]);

        $quizType = AssessmentType::create([
            'name' => 'Monthly Quiz',
            'code' => 'monthly_quiz',
            'is_scored' => true,
            'is_active' => true,
        ]);

        $ownStudent = Student::create([
            'user_id' => $studentUser->id,
            'parent_id' => $parent->id,
            'first_name' => 'Scoped',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'quran_current_juz_id' => $juz->id,
            'status' => 'active',
        ]);

        $otherStudent = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Other',
            'last_name' => 'Student',
            'birth_date' => '2014-05-13',
            'quran_current_juz_id' => $juz->id,
            'status' => 'active',
        ]);

        $ownEnrollment = Enrollment::create([
            'student_id' => $ownStudent->id,
            'group_id' => $ownGroup->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
            'final_points_cached' => 15,
            'memorized_pages_cached' => 7,
        ]);

        $otherEnrollment = Enrollment::create([
            'student_id' => $otherStudent->id,
            'group_id' => $otherGroup->id,
            'enrolled_at' => '2026-09-02',
            'status' => 'active',
        ]);

        $assessment = Assessment::create([
            'group_id' => $ownGroup->id,
            'assessment_type_id' => $quizType->id,
            'title' => 'Monthly Quiz',
            'total_mark' => 100,
            'pass_mark' => 60,
            'is_active' => true,
        ]);

        AssessmentResult::create([
            'assessment_id' => $assessment->id,
            'enrollment_id' => $ownEnrollment->id,
            'student_id' => $ownStudent->id,
            'teacher_id' => $teacher->id,
            'score' => 91,
            'status' => 'passed',
            'attempt_no' => 1,
        ]);

        return [$studentUser, $ownStudent, $otherStudent];
    }
}
