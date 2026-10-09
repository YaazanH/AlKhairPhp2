<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AppSetting;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\AssessmentType;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\CurriculumLesson;
use App\Models\CurriculumSubject;
use App\Models\CurriculumSubjectDefinition;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\GroupCurriculumLessonProgress;
use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\StudentAttendanceRecord;
use App\Models\StudentLearningProgression;
use App\Models\StudentLearningProgressionHistory;
use App\Models\User;
use App\Services\LearningProgressionService;
use App\Services\LessonLevelProgressionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Spatie\Activitylog\Models\Activity as AuditActivity;
use Tests\TestCase;

class LessonLevelProgressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_starts_at_first_level_and_profile_configuration_locks(): void
    {
        $records = $this->progressionRecords();
        $progression = app(LessonLevelProgressionService::class)->assign($records['student'], $records['user']);

        $this->assertSame($records['first_level']->id, $progression->current_level_id);
        $this->assertSame('active', $progression->status);
        $this->assertDatabaseHas('student_learning_progression_history', [
            'student_id' => $records['student']->id,
            'event' => 'assigned',
            'to_level_id' => $records['first_level']->id,
        ]);
        $this->assertTrue(app(LearningProgressionService::class)->isLocked());

        $records['user']->assignRole('admin');
        $this->actingAs($records['user'])
            ->get(route('students.progress', $records['student'], absolute: false))
            ->assertOk()
            ->assertSee(__('learning_progression.lesson_summary.title'))
            ->assertSee($records['first_level']->name)
            ->assertSee(__('learning_progression.history.title'))
            ->assertSee(__('learning_progression.history.events.assigned'))
            ->assertSee(__('learning_progression.manual_promotion.action'));

        $this->expectException(LogicException::class);
        app(LearningProgressionService::class)->moveLevel($records['first_level'], 'down');
    }

    public function test_student_is_automatically_promoted_once_all_level_rules_pass(): void
    {
        $records = $this->progressionRecords();
        app(LessonLevelProgressionService::class)->assign($records['student'], $records['user']);

        AssessmentResult::query()->create([
            'assessment_id' => $records['assessment']->id,
            'enrollment_id' => $records['enrollment']->id,
            'student_id' => $records['student']->id,
            'score' => 90,
            'status' => 'passed',
            'attempt_no' => 1,
        ]);
        $this->assertSame($records['first_level']->id, $this->currentProgression()->current_level_id);

        $day = GroupAttendanceDay::query()->create([
            'group_id' => $records['group']->id,
            'attendance_date' => now()->toDateString(),
            'status' => 'closed',
            'created_by' => $records['user']->id,
        ]);
        StudentAttendanceRecord::query()->create([
            'group_attendance_day_id' => $day->id,
            'enrollment_id' => $records['enrollment']->id,
            'student_id' => $records['student']->id,
            'attendance_status_id' => AttendanceStatus::query()->where('code', 'present')->value('id'),
        ]);

        GroupCurriculumLessonProgress::query()->create([
            'group_id' => $records['group']->id,
            'curriculum_lesson_id' => $records['lessons'][0]->id,
            'status' => 'taught',
            'taught_on' => now()->toDateString(),
        ]);
        $this->assertSame($records['first_level']->id, $this->currentProgression()->current_level_id);

        GroupCurriculumLessonProgress::query()->create([
            'group_id' => $records['group']->id,
            'curriculum_lesson_id' => $records['lessons'][1]->id,
            'status' => 'taught',
            'taught_on' => now()->toDateString(),
        ]);

        $progression = $this->currentProgression();
        $this->assertSame($records['second_level']->id, $progression->current_level_id);
        $this->assertSame('active', $progression->status);
        $history = StudentLearningProgressionHistory::query()->where('event', 'automatically_promoted')->firstOrFail();
        $this->assertSame($records['first_level']->id, $history->from_level_id);
        $this->assertSame($records['second_level']->id, $history->to_level_id);
        $this->assertTrue($history->evidence['eligible']);

        app(LessonLevelProgressionService::class)->evaluateStudent($records['student']->id);
        $this->assertSame($records['second_level']->id, $this->currentProgression()->current_level_id);
        $this->assertSame(1, StudentLearningProgressionHistory::query()->where('event', 'automatically_promoted')->count());
    }

    public function test_authorized_manual_promotion_moves_exactly_one_level_and_records_the_reason(): void
    {
        $records = $this->progressionRecords();
        $records['user']->assignRole('admin');
        $this->actingAs($records['user']);
        $service = app(LessonLevelProgressionService::class);
        $service->assign($records['student'], $records['user']);

        try {
            $service->manuallyPromote($records['student'], $records['user'], 'short');
            $this->fail('A short manual promotion reason was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('manualPromotionReason', $exception->errors());
        }

        $progression = $service->manuallyPromote(
            $records['student'],
            $records['user'],
            'The student completed equivalent work before joining this level.',
        );

        $this->assertSame($records['second_level']->id, $progression->current_level_id);
        $this->assertSame('active', $progression->status);
        $history = StudentLearningProgressionHistory::query()->where('event', 'manually_promoted')->sole();
        $this->assertSame($records['user']->id, $history->performed_by);
        $this->assertSame('The student completed equivalent work before joining this level.', $history->reason);
        $this->assertSame($records['first_level']->id, $history->from_level_id);
        $this->assertSame($records['second_level']->id, $history->to_level_id);
        $this->assertTrue(AuditActivity::query()
            ->where('log_name', 'data-audit')
            ->where('subject_type', StudentLearningProgressionHistory::class)
            ->where('subject_id', $history->id)
            ->where('causer_id', $records['user']->id)
            ->exists());

        $completed = $service->manuallyPromote(
            $records['student'],
            $records['user'],
            'The final level requirements were verified outside the system.',
        );
        $this->assertSame('completed', $completed->status);
        $this->assertSame(2, StudentLearningProgressionHistory::query()->where('event', 'manually_promoted')->count());
    }

    public function test_assessment_updates_keep_immutable_attempt_snapshots(): void
    {
        $records = $this->progressionRecords();
        $records['user']->assignRole('admin');
        $this->actingAs($records['user']);
        app(LessonLevelProgressionService::class)->assign($records['student'], $records['user']);

        $result = AssessmentResult::query()->create([
            'assessment_id' => $records['assessment']->id,
            'enrollment_id' => $records['enrollment']->id,
            'student_id' => $records['student']->id,
            'score' => 45,
            'status' => 'failed',
            'attempt_no' => 1,
        ]);
        $result->update(['score' => 90, 'status' => 'passed', 'attempt_no' => 2]);

        $attempts = StudentLearningProgressionHistory::query()
            ->where('event', 'assessment_attempted')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $attempts);
        $this->assertEquals(45.0, $attempts[0]->evidence['assessment_score']);
        $this->assertSame('failed', $attempts[0]->evidence['assessment_status']);
        $this->assertEquals(90.0, $attempts[1]->evidence['assessment_score']);
        $this->assertSame(2, $attempts[1]->evidence['attempt_number']);
        $this->assertSame($records['user']->id, $attempts[1]->performed_by);

        $this->get(route('students.progress', $records['student'], absolute: false))
            ->assertOk()
            ->assertSee(__('learning_progression.history.events.assessment_attempted'))
            ->assertSee('45.00')
            ->assertSee('90.00');
    }

    public function test_manual_promotion_requires_the_dedicated_permission(): void
    {
        $records = $this->progressionRecords();
        app(LessonLevelProgressionService::class)->assign($records['student'], $records['user']);
        $unauthorized = User::factory()->create(['username' => 'unauthorized-promoter']);

        $this->expectException(AuthorizationException::class);
        app(LessonLevelProgressionService::class)->manuallyPromote(
            $records['student'],
            $unauthorized,
            'This reason is long enough but the user lacks permission.',
        );
    }

    private function progressionRecords(): array
    {
        $this->seed();
        AppSetting::storeValue('learning_progression', 'profile', LearningProgressionService::PROFILE_LESSON_LEVEL);
        $user = User::factory()->create(['username' => 'level-manager']);
        $course = Course::query()->create(['name' => 'Level Course', 'is_active' => true]);
        $curriculum = Curriculum::query()->create(['course_id' => $course->id, 'name' => 'Level Curriculum', 'is_active' => true]);
        $definition = CurriculumSubjectDefinition::query()->create(['name' => 'Level Subject', 'is_active' => true]);
        $subject = CurriculumSubject::query()->create(['curriculum_id' => $curriculum->id, 'subject_definition_id' => $definition->id, 'sort_order' => 1]);
        $lessons = collect(['First lesson', 'Second lesson'])->map(fn (string $name, int $index) => CurriculumLesson::query()->create([
            'curriculum_subject_id' => $subject->id,
            'name' => $name,
            'page_count' => 1,
            'importance' => 1,
            'sort_order' => $index + 1,
        ]));
        $group = Group::query()->create([
            'course_id' => $course->id,
            'academic_year_id' => AcademicYear::query()->where('is_current', true)->value('id'),
            'curriculum_id' => $curriculum->id,
            'name' => 'Level Delivery Group',
            'capacity' => 20,
            'is_active' => true,
        ]);
        $assessment = Assessment::query()->create([
            'group_id' => $group->id,
            'assessment_type_id' => AssessmentType::query()->value('id'),
            'title' => 'Level Final Assessment',
            'total_mark' => 100,
            'pass_mark' => 60,
            'is_active' => true,
        ]);
        $assessment->groups()->sync([$group->id]);
        $secondAssessment = Assessment::query()->create([
            'group_id' => $group->id,
            'assessment_type_id' => AssessmentType::query()->value('id'),
            'title' => 'Second Level Final Assessment',
            'total_mark' => 100,
            'pass_mark' => 60,
            'is_active' => true,
        ]);
        $secondAssessment->groups()->sync([$group->id]);

        $levelService = app(LearningProgressionService::class);
        $levelData = [
            'description' => null,
            'attendance_threshold' => 100,
            'final_assessment_id' => $assessment->id,
            'passing_score' => 80,
            'group_ids' => [$group->id],
            'lesson_ids' => $lessons->pluck('id')->all(),
        ];
        $firstLevel = $levelService->storeLevel(['name' => 'Level One', ...$levelData]);
        $secondLevel = $levelService->storeLevel([
            'name' => 'Level Two',
            ...$levelData,
            'final_assessment_id' => $secondAssessment->id,
        ]);

        $parent = ParentProfile::query()->create(['father_name' => 'Level Parent']);
        $student = Student::query()->create([
            'parent_id' => $parent->id,
            'first_name' => 'Level',
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

        return compact('user', 'group', 'assessment', 'lessons', 'student', 'enrollment') + [
            'first_level' => $firstLevel,
            'second_level' => $secondLevel,
        ];
    }

    private function currentProgression(): StudentLearningProgression
    {
        return StudentLearningProgression::query()->firstOrFail()->fresh();
    }
}
