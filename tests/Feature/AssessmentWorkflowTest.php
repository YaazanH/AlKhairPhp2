<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentScoreBand;
use App\Models\AssessmentType;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\ParentProfile;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AssessmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_assessment_pages_require_authentication(): void
    {
        [$assessment] = $this->assessmentContext(false);

        foreach ([
            route('assessments.index', absolute: false),
            route('assessments.bands', absolute: false),
            route('assessments.results', $assessment, absolute: false),
        ] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
    }

    public function test_manager_can_configure_bands_create_assessments_and_award_points(): void
    {
        [$assessment, $enrollment] = $this->assessmentContext();
        $quizType = AssessmentType::query()->where('code', 'quiz')->firstOrFail();
        $quizPointType = PointType::query()->where('code', 'quiz-score')->firstOrFail();

        $excellentBand = AssessmentScoreBand::query()
            ->where('assessment_type_id', $quizType->id)
            ->where('name', 'Quiz Excellent')
            ->firstOrFail();

        Volt::test('assessments.bands')
            ->call('edit', $excellentBand->id)
            ->set('points', '8')
            ->call('save')
            ->assertHasNoErrors();

        Volt::test('assessments.results', ['assessment' => $assessment])
            ->set('result_scores.'.$enrollment->id, '100')
            ->set('result_statuses.'.$enrollment->id, 'passed')
            ->set('result_attempts.'.$enrollment->id, '1')
            ->call('saveResults')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('assessment_results', [
            'assessment_id' => $assessment->id,
            'enrollment_id' => $enrollment->id,
            'status' => 'passed',
            'attempt_no' => 1,
        ]);

        $perfectTransaction = PointTransaction::query()
            ->where('source_type', 'assessment_result')
            ->where('points', 8)
            ->firstOrFail();

        $this->assertSame(8, $enrollment->fresh()->final_points_cached);

        Volt::test('assessments.results', ['assessment' => $assessment])
            ->set('result_scores.'.$enrollment->id, '70')
            ->set('result_statuses.'.$enrollment->id, 'passed')
            ->set('result_attempts.'.$enrollment->id, '2')
            ->call('saveResults')
            ->assertHasNoErrors();

        $this->assertNotNull($perfectTransaction->fresh()->voided_at);
        $this->assertDatabaseHas('point_transactions', [
            'source_type' => 'assessment_result',
            'points' => 2,
            'voided_at' => null,
        ]);
        $this->assertSame(2, $enrollment->fresh()->final_points_cached);
    }

    public function test_score_bands_cannot_overlap_for_the_same_assessment_type(): void
    {
        $this->assessmentContext();

        $type = AssessmentType::create([
            'name' => 'Placement',
            'code' => 'placement',
            'is_scored' => true,
            'is_active' => true,
        ]);

        Volt::test('assessments.bands')
            ->set('assessment_type_id', $type->id)
            ->set('name', 'Placement Lower')
            ->set('from_mark', '0')
            ->set('to_mark', '49.99')
            ->call('save')
            ->assertHasNoErrors();

        Volt::test('assessments.bands')
            ->set('assessment_type_id', $type->id)
            ->set('name', 'Placement Upper')
            ->set('from_mark', '50')
            ->set('to_mark', '100')
            ->call('save')
            ->assertHasNoErrors();

        Volt::test('assessments.bands')
            ->set('assessment_type_id', $type->id)
            ->set('name', 'Placement Overlap')
            ->set('from_mark', '40')
            ->set('to_mark', '60')
            ->call('save')
            ->assertHasErrors(['from_mark']);

        $this->assertDatabaseMissing('assessment_score_bands', [
            'assessment_type_id' => $type->id,
            'name' => 'Placement Overlap',
        ]);
    }

    public function test_manager_can_create_one_assessment_for_multiple_groups_and_record_results(): void
    {
        [$existingAssessment, $firstEnrollment] = $this->assessmentContext();
        $quizType = AssessmentType::query()->where('code', 'quiz')->firstOrFail();
        $course = Course::query()->where('name', 'Assessment Course')->firstOrFail();
        $teacher = Teacher::query()->where('phone', '0944000201')->firstOrFail();
        $yearId = AcademicYear::query()->where('is_current', true)->value('id');

        $secondParent = ParentProfile::create([
            'father_name' => 'Second Assessment Parent',
            'father_phone' => '0944000202',
        ]);

        $secondStudent = Student::create([
            'parent_id' => $secondParent->id,
            'first_name' => 'Second',
            'last_name' => 'Student',
            'birth_date' => '2015-05-12',
            'status' => 'active',
        ]);

        $secondGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $yearId,
            'teacher_id' => $teacher->id,
            'name' => 'Second Assessment Group',
            'capacity' => 12,
            'is_active' => true,
        ]);

        $secondEnrollment = Enrollment::create([
            'student_id' => $secondStudent->id,
            'group_id' => $secondGroup->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        $existingAssessment->delete();

        Volt::test('assessments.index')
            ->call('create')
            ->set('group_scope', 'multiple')
            ->set('group_ids', [$firstEnrollment->group_id, $secondGroup->id])
            ->set('assessment_type_id', $quizType->id)
            ->set('title', 'Shared Quiz')
            ->set('scheduled_at', '2026-10-01T10:00')
            ->assertSet('due_at', '2026-10-08T10:00')
            ->set('total_mark', '100')
            ->set('pass_mark', '60')
            ->call('save')
            ->assertHasNoErrors();

        $assessment = Assessment::query()->where('title', 'Shared Quiz')->firstOrFail();

        $this->assertSame(1, Assessment::query()->count());
        $this->assertDatabaseHas('assessment_groups', [
            'assessment_id' => $assessment->id,
            'group_id' => $firstEnrollment->group_id,
        ]);
        $this->assertDatabaseHas('assessment_groups', [
            'assessment_id' => $assessment->id,
            'group_id' => $secondGroup->id,
        ]);

        $resultsComponent = Volt::test('assessments.results', ['assessment' => $assessment])
            ->assertSee('Assessment Group')
            ->assertSee('Second Assessment Group')
            ->call('selectGroup', $firstEnrollment->group_id)
            ->set('result_scores.'.$firstEnrollment->id, '80')
            ->set('result_attempts.'.$firstEnrollment->id, '1')
            ->call('saveResults')
            ->assertHasNoErrors();

        $resultsComponent
            ->call('selectGroup', $secondGroup->id)
            ->set('result_scores.'.$secondEnrollment->id, '40')
            ->set('result_attempts.'.$secondEnrollment->id, '1')
            ->call('saveResults')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('assessment_results', [
            'assessment_id' => $assessment->id,
            'enrollment_id' => $firstEnrollment->id,
            'status' => 'passed',
        ]);
        $this->assertDatabaseHas('assessment_results', [
            'assessment_id' => $assessment->id,
            'enrollment_id' => $secondEnrollment->id,
            'status' => 'failed',
        ]);
    }

    public function test_teacher_assessment_access_is_restricted_to_assigned_groups(): void
    {
        $this->seed();

        $teacherUser = User::factory()->create([
            'username' => 'assessment-teacher',
            'phone' => '0777000200',
        ]);
        $teacherUser->assignRole('teacher');

        $assignedTeacher = Teacher::create([
            'user_id' => $teacherUser->id,
            'first_name' => 'Assigned',
            'last_name' => 'Teacher',
            'phone' => '0991000201',
            'status' => 'active',
        ]);

        $otherTeacher = Teacher::create([
            'first_name' => 'Other',
            'last_name' => 'Teacher',
            'phone' => '0991000202',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Assessment Access Course',
            'is_active' => true,
        ]);

        $parent = ParentProfile::create([
            'father_name' => 'Assessment Access Parent',
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Access',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $yearId = AcademicYear::query()->where('is_current', true)->value('id');
        $quizTypeId = AssessmentType::query()->where('code', 'quiz')->value('id');

        $assignedGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $yearId,
            'teacher_id' => $assignedTeacher->id,
            'name' => 'Assigned Assessment Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $otherGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $yearId,
            'teacher_id' => $otherTeacher->id,
            'name' => 'Other Assessment Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $assignedAssessment = Assessment::create([
            'group_id' => $assignedGroup->id,
            'assessment_type_id' => $quizTypeId,
            'title' => 'Assigned Quiz',
            'is_active' => true,
        ]);

        $otherAssessment = Assessment::create([
            'group_id' => $otherGroup->id,
            'assessment_type_id' => $quizTypeId,
            'title' => 'Other Quiz',
            'is_active' => true,
        ]);

        $this->actingAs($teacherUser);

        $this->get(route('assessments.index', absolute: false))->assertOk();
        $this->get(route('assessments.results', $assignedAssessment, absolute: false))->assertOk();
        $this->get(route('assessments.results', $otherAssessment, absolute: false))->assertForbidden();
    }

    private function assessmentContext(bool $authenticate = true): array
    {
        $this->seed();

        if ($authenticate) {
            $manager = User::factory()->create([
                'username' => 'assessment-manager',
                'phone' => '0666000200',
            ]);
            $manager->assignRole('manager');
            $this->actingAs($manager);
        }

        $parent = ParentProfile::create([
            'father_name' => 'Assessment Parent',
            'father_phone' => '0944000200',
        ]);

        $teacher = Teacher::create([
            'first_name' => 'Assessment',
            'last_name' => 'Teacher',
            'phone' => '0944000201',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Assessment Course',
            'is_active' => true,
        ]);

        $student = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Assessment',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);

        $yearId = AcademicYear::query()->where('is_current', true)->value('id');
        $quizTypeId = AssessmentType::query()->where('code', 'quiz')->value('id');

        $group = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $yearId,
            'teacher_id' => $teacher->id,
            'name' => 'Assessment Group',
            'capacity' => 12,
            'is_active' => true,
        ]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
        ]);

        $assessment = Assessment::create([
            'group_id' => $group->id,
            'assessment_type_id' => $quizTypeId,
            'title' => 'Weekly Quiz',
            'total_mark' => 100,
            'pass_mark' => 60,
            'is_active' => true,
            'created_by' => $authenticate ? auth()->id() : null,
        ]);

        return [$assessment, $enrollment];
    }
}
