<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CorePeopleAcademicsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_manager_can_create_read_update_and_delete_a_parent_without_password_disclosure(): void
    {
        $this->actingAsManager();

        $created = $this->postJson('/api/v1/parents', [
            'father_name' => 'Mobile Parent',
            'father_phone' => '0999 111 222',
            'is_active' => true,
            'mother_name' => 'Mobile Mother',
        ])->assertCreated()
            ->assertJsonPath('data.father_name', 'Mobile Parent')
            ->assertJsonMissingPath('data.password');

        $parent = ParentProfile::query()->findOrFail($created->json('data.id'));
        $this->assertNotNull($parent->user_id);
        $this->assertTrue($parent->user->hasRole('parent'));

        $this->getJson('/api/v1/parents/'.$parent->id)
            ->assertOk()
            ->assertJsonPath('data.mother_name', 'Mobile Mother');

        $this->patchJson('/api/v1/parents/'.$parent->id, [
            'father_name' => 'Updated Parent',
            'father_phone' => '0999111222',
            'is_active' => true,
            'mother_name' => 'Mobile Mother',
        ])->assertOk()->assertJsonPath('data.father_name', 'Updated Parent');

        $userId = $parent->user_id;
        $this->deleteJson('/api/v1/parents/'.$parent->id)->assertNoContent();
        $this->assertSoftDeleted('parents', ['id' => $parent->id]);
        $this->assertDatabaseMissing('users', ['id' => $userId]);
    }

    public function test_manager_can_manage_teacher_and_course_lifecycle(): void
    {
        $this->actingAsManager();
        $year = $this->createAcademicYear();

        $courseResponse = $this->postJson('/api/v1/courses', [
            'academic_year_id' => $year->id,
            'awards_points' => true,
            'ends_on' => '2027-06-30',
            'is_default' => true,
            'name' => 'Mobile Course',
            'starts_on' => '2026-09-01',
        ])->assertCreated()->assertJsonPath('data.is_active', true);
        $course = Course::query()->findOrFail($courseResponse->json('data.id'));

        $teacherResponse = $this->postJson('/api/v1/teachers', [
            'account_password' => 'MobilePass123',
            'account_username' => 'mobile-teacher',
            'course_id' => $course->id,
            'first_name' => 'Mobile',
            'is_helping' => false,
            'last_name' => 'Teacher',
            'phone' => '0988 111 222',
        ])->assertCreated()
            ->assertJsonPath('data.course.id', $course->id)
            ->assertJsonMissingPath('data.password');

        $teacher = Teacher::query()->findOrFail($teacherResponse->json('data.id'));
        $this->assertTrue($teacher->user->hasRole('teacher'));

        $this->getJson('/api/v1/teachers?search=Mobile')
            ->assertOk()->assertJsonPath('data.0.id', $teacher->id);

        $this->postJson('/api/v1/courses/'.$course->id.'/finish')
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->patchJson('/api/v1/courses/'.$course->id, [
            'academic_year_id' => $year->id,
            'ends_on' => '2027-06-30',
            'name' => 'Blocked Update',
            'starts_on' => '2026-09-01',
        ])->assertUnprocessable()->assertJsonValidationErrors(['course']);
        $this->postJson('/api/v1/courses/'.$course->id.'/reactivate')
            ->assertOk()->assertJsonPath('data.is_active', true);

        $this->deleteJson('/api/v1/teachers/'.$teacher->id)->assertNoContent();
        $this->deleteJson('/api/v1/courses/'.$course->id)->assertNoContent();
    }

    public function test_detail_endpoints_return_mobile_ready_student_group_and_enrollment_records(): void
    {
        $this->actingAsManager();
        [$parent, $teacher, $course, $group, $student, $enrollment] = $this->createLearningStructure();

        $this->getJson('/api/v1/students/'.$student->id)
            ->assertOk()
            ->assertJsonPath('data.parent.id', $parent->id)
            ->assertJsonPath('data.enrollments_count', 1);

        $this->getJson('/api/v1/groups/'.$group->id)
            ->assertOk()
            ->assertJsonPath('data.course.id', $course->id)
            ->assertJsonPath('data.teacher.id', $teacher->id);

        $this->getJson('/api/v1/enrollments/'.$enrollment->id)
            ->assertOk()
            ->assertJsonPath('data.student.id', $student->id)
            ->assertJsonPath('data.group.id', $group->id);
    }

    public function test_linked_records_block_parent_teacher_and_course_deletion(): void
    {
        $this->actingAsManager();
        [$parent, $teacher, $course] = $this->createLearningStructure();

        $this->deleteJson('/api/v1/parents/'.$parent->id)->assertUnprocessable();
        $this->deleteJson('/api/v1/teachers/'.$teacher->id)->assertUnprocessable();
        $this->deleteJson('/api/v1/courses/'.$course->id)->assertUnprocessable();
    }

    public function test_scoped_teacher_cannot_view_or_mutate_records_outside_their_scope(): void
    {
        [$ownParent, $teacher, , $ownGroup, $ownStudent] = $this->createLearningStructure();
        [$otherParent, , , $otherGroup, $otherStudent, $otherEnrollment] = $this->createLearningStructure('Other');

        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('teacher');
        $teacherUser->givePermissionTo(['students.view', 'students.update', 'groups.view', 'enrollments.view', 'parents.view']);
        $teacher->user()->associate($teacherUser);
        $teacher->save();
        Sanctum::actingAs($teacherUser);

        $this->getJson('/api/v1/students/'.$ownStudent->id)->assertOk();
        $this->getJson('/api/v1/groups/'.$ownGroup->id)->assertOk();
        $this->getJson('/api/v1/parents/'.$ownParent->id)->assertOk();

        $this->getJson('/api/v1/students/'.$otherStudent->id)->assertForbidden();
        $this->getJson('/api/v1/groups/'.$otherGroup->id)->assertForbidden();
        $this->getJson('/api/v1/enrollments/'.$otherEnrollment->id)->assertForbidden();
        $this->getJson('/api/v1/parents/'.$otherParent->id)->assertForbidden();

        $this->patchJson('/api/v1/students/'.$ownStudent->id, [
            'birth_date' => '2014-01-01',
            'first_name' => 'Own',
            'last_name' => 'Student',
            'parent_id' => $otherParent->id,
            'status' => 'active',
        ])->assertForbidden();

        $this->patchJson('/api/v1/students/'.$otherStudent->id, [
            'birth_date' => '2014-01-01',
            'first_name' => 'Forbidden',
            'last_name' => 'Student',
            'parent_id' => $otherParent->id,
            'status' => 'active',
        ])->assertForbidden();
    }

    protected function actingAsManager(): User
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        Sanctum::actingAs($manager);

        return $manager;
    }

    protected function createAcademicYear(string $suffix = ''): AcademicYear
    {
        return AcademicYear::query()->create([
            'ends_on' => '2027-06-30',
            'is_active' => true,
            'is_current' => true,
            'name' => '2026-2027'.$suffix,
            'starts_on' => '2026-09-01',
        ]);
    }

    protected function createLearningStructure(string $suffix = ''): array
    {
        $year = $this->createAcademicYear($suffix);
        $parent = ParentProfile::query()->create(['father_name' => 'Parent '.$suffix, 'is_active' => true]);
        $course = Course::query()->create([
            'academic_year_id' => $year->id,
            'awards_points' => false,
            'ends_on' => '2027-06-30',
            'is_active' => true,
            'is_default' => $suffix === '',
            'name' => 'Course '.$suffix,
            'starts_on' => '2026-09-01',
        ]);
        $teacher = Teacher::query()->create([
            'first_name' => 'Teacher',
            'is_helping' => false,
            'last_name' => $suffix ?: 'Primary',
            'phone' => '099'.($suffix === '' ? '1000000' : '2000000'),
            'status' => 'active',
        ]);
        $group = Group::query()->create([
            'academic_year_id' => $year->id,
            'capacity' => 20,
            'course_id' => $course->id,
            'is_active' => true,
            'name' => 'Group '.$suffix,
            'teacher_id' => $teacher->id,
        ]);
        $student = Student::query()->create([
            'birth_date' => '2014-01-01',
            'first_name' => 'Student',
            'last_name' => $suffix ?: 'Primary',
            'parent_id' => $parent->id,
            'status' => 'active',
        ]);
        $enrollment = Enrollment::query()->create([
            'enrolled_at' => '2026-09-01',
            'group_id' => $group->id,
            'status' => 'active',
            'student_id' => $student->id,
        ]);

        return [$parent, $teacher, $course, $group, $student, $enrollment];
    }
}
