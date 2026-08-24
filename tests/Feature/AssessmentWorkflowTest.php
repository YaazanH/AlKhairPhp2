<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\AssessmentResult;
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
use Mpdf\Mpdf;
use setasign\Fpdi\PdfParser\StreamReader;
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
            route('assessments.results.pdf', $assessment, absolute: false),
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

    public function test_result_roster_defaults_attempt_to_one_and_saves_a_single_student_inline(): void
    {
        [$assessment, $enrollment] = $this->assessmentContext();

        Volt::test('assessments.results', ['assessment' => $assessment])
            ->assertSet('selectedGroupId', $enrollment->group_id)
            ->assertSee('assessment-results-single', false)
            ->assertSee('assessment-results-dual', false)
            ->assertDontSee('assessment-student-attempt')
            ->assertDontSee('assessment-student-notes')
            ->set('result_scores.'.$enrollment->id, '85')
            ->call('saveEnrollmentResult', $enrollment->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('assessment_results', [
            'assessment_id' => $assessment->id,
            'enrollment_id' => $enrollment->id,
            'score' => 85,
            'status' => 'passed',
            'attempt_no' => 1,
        ]);
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
        $teacher = Teacher::query()->where('first_name', 'Assessment')->where('last_name', 'Teacher')->firstOrFail();
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

        $otherCourse = Course::create([
            'name' => 'Other Assessment Course',
            'is_active' => true,
        ]);
        Group::create([
            'course_id' => $otherCourse->id,
            'academic_year_id' => $yearId,
            'teacher_id' => $teacher->id,
            'name' => 'Hidden Other Course Group',
            'capacity' => 12,
            'is_active' => true,
        ]);

        $existingAssessment->delete();

        Volt::test('assessments.index')
            ->call('create')
            ->set('group_scope', 'multiple')
            ->set('groupCourseFilter', (string) $course->id)
            ->call('openGroupPicker')
            ->assertSee('Second Assessment Group')
            ->assertDontSee('Hidden Other Course Group')
            ->call('toggleGroup', $firstEnrollment->group_id)
            ->call('toggleGroup', $secondGroup->id)
            ->assertSet('group_ids', [
                (string) min($firstEnrollment->group_id, $secondGroup->id),
                (string) max($firstEnrollment->group_id, $secondGroup->id),
            ])
            ->set('assessment_type_id', $quizType->id)
            ->assertSet('total_mark', '100')
            ->assertSet('pass_mark', '60')
            ->set('title', 'Shared Quiz')
            ->assertSee(__('workflow.assessments.index.form.due_at'))
            ->assertDontSee(__('workflow.assessments.index.form.scheduled_at'))
            ->set('due_at', '2026-10-01')
            ->call('save')
            ->assertHasNoErrors();

        $assessment = Assessment::query()->where('title', 'Shared Quiz')->firstOrFail();

        $this->assertSame(1, Assessment::query()->count());
        $this->assertSame('100.00', $assessment->total_mark);
        $this->assertSame('60.00', $assessment->pass_mark);
        $this->assertSame('multiple', $assessment->group_scope);
        $this->assertSame('2026-10-01', $assessment->due_at?->format('Y-m-d'));
        $this->assertNull($assessment->scheduled_at);
        $this->assertDatabaseHas('assessment_groups', [
            'assessment_id' => $assessment->id,
            'group_id' => $firstEnrollment->group_id,
        ]);
        $this->assertDatabaseHas('assessment_groups', [
            'assessment_id' => $assessment->id,
            'group_id' => $secondGroup->id,
        ]);

        Volt::test('assessments.index')
            ->call('edit', $assessment->id)
            ->set('returnToResults', true)
            ->assertSet('editingId', $assessment->id)
            ->assertSet('returnToResults', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('assessments.results', $assessment));

        $resultsComponent = Volt::test('assessments.results', ['assessment' => $assessment])
            ->assertSeeInOrder([$assessment->title, __('workflow.common.back_to_assessments')])
            ->assertSee('assessment-results-title', false)
            ->assertSee('assessment-results-back', false)
            ->assertSee('Assessment Group')
            ->assertSee('Second Assessment Group')
            ->assertSee(__('workflow.assessments.results.student_entry.title'))
            ->assertDontSee('<div class="admin-toolbar__title">'.__('workflow.assessments.results.groups.choose_title').'</div>', false)
            ->assertSee('assessment-group-selector', false)
            ->assertDontSee(__('workflow.assessments.results.groups.scores_entered', ['count' => 0]))
            ->assertSee(__('workflow.assessments.results.pdf_export'))
            ->assertSee(__('workflow.assessments.results.pdf.average_mark'))
            ->assertSet('selectedGroupId', null)
            ->set('quick_enrollment_id', (string) $firstEnrollment->id)
            ->set('quick_score', '80')
            ->call('saveQuickResult')
            ->assertDontSee(__('workflow.assessments.results.groups.scores_entered', ['count' => 1]))
            ->assertViewHas('assessmentAverage', fn ($average) => (float) $average === 80.0)
            ->assertHasNoErrors();

        $resultsComponent
            ->call('selectGroup', $secondGroup->id)
            ->assertDontSee($course->name)
            ->assertDontSee($teacher->first_name.' '.$teacher->last_name)
            ->assertDontSee('wire:model.live.debounce.300ms="result_scores.', false)
            ->set('result_scores.'.$secondEnrollment->id, '40')
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

        $pdfResponse = $this->get(route('assessments.results.pdf', $assessment, absolute: false))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdfResponse->getContent());

        $pdfInspector = new Mpdf(['tempDir' => storage_path('app/mpdf')]);
        $this->assertSame(2, $pdfInspector->setSourceFile(StreamReader::createByString($pdfResponse->getContent())));

        $groupPdfResponse = $this->get(route('assessments.results.pdf', [
            'assessment' => $assessment,
            'group_id' => $secondGroup->id,
        ], absolute: false))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertSame(1, $pdfInspector->setSourceFile(StreamReader::createByString($groupPdfResponse->getContent())));
    }

    public function test_assessments_are_sorted_by_date_descending_with_undated_records_last(): void
    {
        [$undatedAssessment, $enrollment] = $this->assessmentContext();

        foreach ([
            ['title' => 'Earlier Due Assessment', 'due_at' => '2026-10-10'],
            ['title' => 'Later Due Assessment', 'due_at' => '2026-10-20'],
        ] as $data) {
            Assessment::create([
                'group_id' => $enrollment->group_id,
                'assessment_type_id' => $undatedAssessment->assessment_type_id,
                'title' => $data['title'],
                'due_at' => $data['due_at'],
                'total_mark' => 100,
                'pass_mark' => 60,
                'is_active' => true,
                'created_by' => auth()->id(),
            ]);
        }

        Volt::test('assessments.index')
            ->set('courseFilter', 'all')
            ->assertSee('assessment-index-mobile', false)
            ->assertSee('assessment-index-desktop', false)
            ->assertSeeInOrder(['Later Due Assessment', 'Earlier Due Assessment', $undatedAssessment->title]);
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

    public function test_quick_result_modal_keeps_group_separate_and_zero_removes_the_result(): void
    {
        [$assessment, $enrollment] = $this->assessmentContext();

        $component = Volt::test('assessments.results', ['assessment' => $assessment])
            ->assertSet('showQuickResultModal', false)
            ->call('openQuickResultModal')
            ->assertSet('showQuickResultModal', true)
            ->assertSee('assessment-selected-group', false)
            ->set('quick_enrollment_id', (string) $enrollment->id)
            ->assertSee($enrollment->group->name)
            ->set('quick_score', '75')
            ->call('saveQuickResult')
            ->assertHasNoErrors()
            ->assertSet('showQuickResultModal', true)
            ->assertSet('quick_enrollment_id', '')
            ->assertSet('quick_score', '');

        $result = AssessmentResult::query()
            ->where('assessment_id', $assessment->id)
            ->where('enrollment_id', $enrollment->id)
            ->firstOrFail();

        $component
            ->set('quick_enrollment_id', (string) $enrollment->id)
            ->set('quick_score', '0')
            ->call('saveQuickResult')
            ->assertHasNoErrors()
            ->assertSet('showQuickResultModal', true)
            ->assertSet('quick_enrollment_id', '')
            ->assertSet('quick_score', '');

        $this->assertDatabaseMissing('assessment_results', ['id' => $result->id]);
        $this->assertDatabaseMissing('assessment_results', [
            'assessment_id' => $assessment->id,
            'enrollment_id' => $enrollment->id,
        ]);
    }

    public function test_assessment_delete_is_only_available_in_edit_and_is_blocked_when_results_exist(): void
    {
        [$assessment, $enrollment] = $this->assessmentContext();

        Volt::test('assessments.results', ['assessment' => $assessment])
            ->set('quick_enrollment_id', (string) $enrollment->id)
            ->set('quick_score', '80')
            ->call('saveQuickResult')
            ->assertDontSee('wire:click="deleteAssessment"', false);

        Volt::test('assessments.index')
            ->call('edit', $assessment->id)
            ->assertSee('w-[19%]', false)
            ->assertSee('w-[17%]', false)
            ->assertSee('wire:click="delete('.$assessment->id.')"', false)
            ->assertSee('disabled', false)
            ->call('delete', $assessment->id)
            ->assertHasErrors(['delete']);

        $this->assertDatabaseHas('assessments', ['id' => $assessment->id]);
    }

    public function test_assessment_form_always_saves_assessments_as_active(): void
    {
        [$assessment] = $this->assessmentContext();
        $assessment->update(['is_active' => false]);

        Volt::test('assessments.index')
            ->call('edit', $assessment->id)
            ->assertDontSee('wire:model="is_active"', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($assessment->fresh()->is_active);
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
