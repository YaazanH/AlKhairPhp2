<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\CurriculumLesson;
use App\Models\CurriculumSubject;
use App\Models\CurriculumSubjectDefinition;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\GroupSchedule;
use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\StudentNote;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AcademicSupportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_manager_can_use_schedule_notes_and_curriculum_workflows(): void
    {
        $context = $this->createContext();
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        Sanctum::actingAs($manager);

        $createdSchedule = $this->postJson('/api/v1/groups/'.$context['group']->id.'/schedules', [
            'day_of_week' => 2,
            'time_slot' => 'morning',
        ])->assertCreated()->assertJsonPath('data.starts_at', '08:00');
        $scheduleId = $createdSchedule->json('data.id');
        $this->getJson('/api/v1/groups/'.$context['group']->id.'/schedules')
            ->assertOk()->assertJsonCount(2, 'data');
        $this->patchJson('/api/v1/groups/'.$context['group']->id.'/schedules/'.$scheduleId, [
            'day_of_week' => 3,
            'time_slot' => 'after_night',
        ])->assertOk()->assertJsonPath('data.ends_at', '22:00');
        $this->deleteJson('/api/v1/groups/'.$context['group']->id.'/schedules/'.$scheduleId)->assertNoContent();

        $createdNote = $this->postJson('/api/v1/student-notes', [
            'body' => 'Mobile note',
            'enrollment_id' => $context['enrollment']->id,
            'noted_at' => '2026-10-12',
            'source' => 'management',
            'student_id' => $context['student']->id,
            'visibility' => 'shared_internal',
        ])->assertCreated()->assertJsonPath('data.student.id', $context['student']->id);
        $noteId = $createdNote->json('data.id');
        $this->getJson('/api/v1/student-notes?student_id='.$context['student']->id)
            ->assertOk()->assertJsonPath('data.0.id', $noteId);
        $this->getJson('/api/v1/student-notes/'.$noteId)->assertOk();
        $this->patchJson('/api/v1/student-notes/'.$noteId, [
            'body' => 'Updated mobile note',
            'noted_at' => '2026-10-13',
            'source' => 'management',
            'visibility' => 'visible_to_parent',
        ])->assertOk()->assertJsonPath('data.body', 'Updated mobile note');
        $this->deleteJson('/api/v1/student-notes/'.$noteId)->assertNoContent();

        $this->getJson('/api/v1/curricula')->assertOk()->assertJsonPath('data.0.id', $context['curriculum']->id);
        $this->getJson('/api/v1/curricula/'.$context['curriculum']->id)
            ->assertOk()->assertJsonPath('data.subjects.0.lessons.0.name', 'Opening lesson');
        $this->getJson('/api/v1/groups/'.$context['group']->id.'/curriculum')
            ->assertOk()->assertJsonPath('data.summary.total', 1);
    }

    public function test_scoped_teacher_only_sees_assigned_academic_support_records(): void
    {
        $context = $this->createContext();
        $other = $this->createContext('Other');
        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('teacher');
        $teacherUser->givePermissionTo([
            'curricula.record',
            'dashboard.group-teacher.view',
            'group-schedules.manage',
            'groups.view',
            'student-notes.create',
            'student-notes.delete',
            'student-notes.update',
            'student-notes.view',
        ]);
        $context['teacher']->user()->associate($teacherUser);
        $context['teacher']->save();

        StudentNote::query()->create([
            'author_id' => User::factory()->create()->id,
            'body' => 'Management only',
            'enrollment_id' => $context['enrollment']->id,
            'noted_at' => '2026-10-12',
            'source' => 'management',
            'student_id' => $context['student']->id,
            'visibility' => 'private_management',
        ]);
        $otherNote = StudentNote::query()->create([
            'author_id' => $teacherUser->id,
            'body' => 'Other group',
            'enrollment_id' => $other['enrollment']->id,
            'noted_at' => '2026-10-12',
            'source' => 'teacher',
            'student_id' => $other['student']->id,
            'visibility' => 'shared_internal',
        ]);
        Sanctum::actingAs($teacherUser);

        $this->getJson('/api/v1/groups/'.$context['group']->id.'/schedules')->assertOk();
        $this->getJson('/api/v1/groups/'.$other['group']->id.'/schedules')->assertForbidden();
        $this->getJson('/api/v1/student-notes')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/student-notes/'.$otherNote->id)->assertForbidden();
        $this->getJson('/api/v1/curricula')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $context['curriculum']->id);
        $this->getJson('/api/v1/curricula/'.$other['curriculum']->id)->assertForbidden();
        $this->getJson('/api/v1/groups/'.$other['group']->id.'/curriculum')->assertForbidden();

        $this->postJson('/api/v1/student-notes', [
            'body' => 'Invalid teacher note',
            'enrollment_id' => $context['enrollment']->id,
            'noted_at' => '2026-10-12',
            'source' => 'management',
            'student_id' => $context['student']->id,
            'visibility' => 'private_management',
        ])->assertUnprocessable();
    }

    public function test_academic_support_endpoints_require_matching_permissions(): void
    {
        $context = $this->createContext();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/groups/'.$context['group']->id.'/schedules')->assertForbidden();
        $this->getJson('/api/v1/student-notes')->assertForbidden();
        $this->getJson('/api/v1/curricula')->assertForbidden();
    }

    protected function createContext(string $suffix = ''): array
    {
        $year = AcademicYear::query()->where('is_current', true)->firstOrFail();
        $course = Course::query()->create(['academic_year_id' => $year->id, 'is_active' => true, 'name' => 'Support Course '.$suffix]);
        $definition = CurriculumSubjectDefinition::query()->create(['is_active' => true, 'name' => 'Subject '.$suffix]);
        $curriculum = Curriculum::query()->create(['course_id' => $course->id, 'is_active' => true, 'name' => 'Curriculum '.$suffix]);
        $subject = CurriculumSubject::query()->create(['curriculum_id' => $curriculum->id, 'sort_order' => 1, 'subject_definition_id' => $definition->id]);
        CurriculumLesson::query()->create(['curriculum_subject_id' => $subject->id, 'importance' => 1, 'name' => 'Opening lesson', 'page_count' => 4, 'sort_order' => 1]);
        $teacher = Teacher::query()->create(['first_name' => 'Support', 'last_name' => 'Teacher '.$suffix, 'phone' => '099'.random_int(1000000, 9999999), 'status' => 'active']);
        $group = Group::query()->create(['academic_year_id' => $year->id, 'capacity' => 20, 'course_id' => $course->id, 'curriculum_id' => $curriculum->id, 'is_active' => true, 'name' => 'Support Group '.$suffix, 'teacher_id' => $teacher->id]);
        GroupSchedule::query()->create(['day_of_week' => 1, 'ends_at' => '12:00', 'group_id' => $group->id, 'is_active' => true, 'starts_at' => '10:30', 'time_slot' => 'before_noon']);
        $parent = ParentProfile::query()->create(['father_name' => 'Support Parent '.$suffix]);
        $student = Student::query()->create(['birth_date' => '2014-01-01', 'first_name' => 'Support', 'last_name' => 'Student '.$suffix, 'parent_id' => $parent->id, 'status' => 'active']);
        $enrollment = Enrollment::query()->create(['enrolled_at' => '2026-09-01', 'group_id' => $group->id, 'status' => 'active', 'student_id' => $student->id]);

        return compact('course', 'curriculum', 'enrollment', 'group', 'student', 'teacher');
    }
}
