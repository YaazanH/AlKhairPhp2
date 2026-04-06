<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\ParentProfile;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WriteApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_token_endpoint_issues_and_revokes_token_for_active_api_user(): void
    {
        $this->seed();

        $manager = User::factory()->create([
            'name' => 'Token Manager',
            'username' => 'token-manager',
            'phone' => '0666000500',
            'password' => 'P@ssw0rd',
        ]);
        $manager->assignRole('manager');

        $response = $this->postJson('/api/v1/auth/token', [
            'device_name' => 'integration-suite',
            'login' => 'token-manager',
            'password' => 'P@ssw0rd',
        ]);

        $response->assertCreated();
        $token = $response->json('token');

        $this->assertNotEmpty($token);
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withToken($token)->deleteJson('/api/v1/auth/token')->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_manager_can_create_update_and_delete_students_groups_and_enrollments_via_api(): void
    {
        [$token, $parent, $teacher, $course, $academicYear, $gradeLevel] = $this->managerApiContext();

        $studentResponse = $this->withToken($token)->postJson('/api/v1/students', [
            'birth_date' => '2014-05-12',
            'first_name' => 'Api',
            'gender' => 'male',
            'grade_level_id' => $gradeLevel->id,
            'joined_at' => '2026-09-01',
            'last_name' => 'Student',
            'notes' => 'Created from API.',
            'parent_id' => $parent->id,
            'school_name' => 'Alkhair School',
            'status' => 'active',
        ]);

        $studentResponse->assertCreated();
        $studentId = $studentResponse->json('id');
        $studentResponse->assertJsonPath('first_name', 'Api');
        $studentResponse->assertJsonPath('parent_id', $parent->id);

        $this->withToken($token)->patchJson('/api/v1/students/'.$studentId, [
            'birth_date' => '2014-05-12',
            'first_name' => 'Api',
            'gender' => 'male',
            'grade_level_id' => $gradeLevel->id,
            'joined_at' => '2026-09-01',
            'last_name' => 'Student',
            'notes' => 'Updated from API.',
            'parent_id' => $parent->id,
            'school_name' => 'Updated School',
            'status' => 'graduated',
        ])->assertOk()
            ->assertJsonPath('status', 'graduated')
            ->assertJsonPath('school_name', 'Updated School');

        $groupResponse = $this->withToken($token)->postJson('/api/v1/groups', [
            'academic_year_id' => $academicYear->id,
            'capacity' => 20,
            'course_id' => $course->id,
            'grade_level_id' => $gradeLevel->id,
            'is_active' => true,
            'monthly_fee' => 25,
            'name' => 'API Group',
            'starts_on' => '2026-09-01',
            'teacher_id' => $teacher->id,
        ]);

        $groupResponse->assertCreated();
        $groupId = $groupResponse->json('id');
        $groupResponse->assertJsonPath('name', 'API Group');
        $groupResponse->assertJsonPath('teacher_id', $teacher->id);

        $this->withToken($token)->patchJson('/api/v1/groups/'.$groupId, [
            'academic_year_id' => $academicYear->id,
            'capacity' => 24,
            'course_id' => $course->id,
            'grade_level_id' => $gradeLevel->id,
            'is_active' => false,
            'monthly_fee' => 30,
            'name' => 'API Group',
            'starts_on' => '2026-09-01',
            'teacher_id' => $teacher->id,
        ])->assertOk()
            ->assertJsonPath('capacity', 24)
            ->assertJsonPath('is_active', false)
            ->assertJsonPath('monthly_fee', 30);

        $enrollmentResponse = $this->withToken($token)->postJson('/api/v1/enrollments', [
            'enrolled_at' => '2026-09-01',
            'group_id' => $groupId,
            'notes' => 'Initial API enrollment.',
            'status' => 'active',
            'student_id' => $studentId,
        ]);

        $enrollmentResponse->assertCreated();
        $enrollmentId = $enrollmentResponse->json('id');
        $enrollmentResponse->assertJsonPath('status', 'active');
        $enrollmentResponse->assertJsonPath('student.id', $studentId);

        $this->withToken($token)->deleteJson('/api/v1/groups/'.$groupId)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This group cannot be deleted while enrollments or schedules still exist.');

        $this->withToken($token)->deleteJson('/api/v1/students/'.$studentId)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This student cannot be deleted while enrollments still exist.');

        $this->withToken($token)->patchJson('/api/v1/enrollments/'.$enrollmentId, [
            'enrolled_at' => '2026-09-01',
            'group_id' => $groupId,
            'left_at' => '2027-05-01',
            'notes' => 'Completed from API.',
            'status' => 'completed',
            'student_id' => $studentId,
        ])->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('left_at', '2027-05-01');

        $this->withToken($token)->deleteJson('/api/v1/enrollments/'.$enrollmentId)->assertNoContent();
        $this->withToken($token)->deleteJson('/api/v1/groups/'.$groupId)->assertNoContent();
        $this->withToken($token)->deleteJson('/api/v1/students/'.$studentId)->assertNoContent();

        $this->assertSoftDeleted('enrollments', ['id' => $enrollmentId]);
        $this->assertSoftDeleted('groups', ['id' => $groupId]);
        $this->assertSoftDeleted('students', ['id' => $studentId]);
    }

    public function test_teacher_tokens_cannot_use_management_write_endpoints(): void
    {
        $this->seed();

        $teacherUser = User::factory()->create([
            'name' => 'Teacher Token User',
            'username' => 'teacher-token-user',
            'phone' => '0666000501',
            'password' => 'P@ssw0rd',
        ]);
        $teacherUser->assignRole('teacher');

        $response = $this->postJson('/api/v1/auth/token', [
            'device_name' => 'teacher-device',
            'login' => 'teacher-token-user',
            'password' => 'P@ssw0rd',
        ]);

        $response->assertCreated();

        $parent = ParentProfile::create([
            'father_name' => 'Teacher API Parent',
        ]);

        $this->withToken($response->json('token'))->postJson('/api/v1/students', [
            'birth_date' => '2014-05-12',
            'first_name' => 'Blocked',
            'last_name' => 'Student',
            'parent_id' => $parent->id,
            'status' => 'active',
        ])->assertForbidden();
    }

    private function managerApiContext(): array
    {
        $this->seed();

        $manager = User::factory()->create([
            'name' => 'Api Manager',
            'username' => 'api-manager',
            'phone' => '0666000502',
            'password' => 'P@ssw0rd',
        ]);
        $manager->assignRole('manager');

        $tokenResponse = $this->postJson('/api/v1/auth/token', [
            'device_name' => 'manager-device',
            'login' => 'api-manager',
            'password' => 'P@ssw0rd',
        ]);

        $tokenResponse->assertCreated();

        $parent = ParentProfile::create([
            'father_name' => 'API Parent',
            'father_phone' => '0944000500',
        ]);

        $teacher = Teacher::create([
            'first_name' => 'API',
            'last_name' => 'Teacher',
            'phone' => '0944000501',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'API Course',
            'is_active' => true,
        ]);

        $academicYear = AcademicYear::query()->where('is_current', true)->firstOrFail();
        $gradeLevel = GradeLevel::query()->where('is_active', true)->orderBy('sort_order')->firstOrFail();

        return [$tokenResponse->json('token'), $parent, $teacher, $course, $academicYear, $gradeLevel];
    }
}
