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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentProgressFilesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        Storage::fake('public');
    }

    public function test_manager_can_view_progress_and_manage_student_files_and_photo(): void
    {
        $context = $this->createContext();
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/students/'.$context['student']->id.'/progress')
            ->assertOk()
            ->assertJsonPath('data.enrollments.0.course.id', $context['course']->id)
            ->assertJsonPath('data.enrollments.0.group.id', $context['group']->id)
            ->assertJsonPath('data.stats.memorized_pages', 0);

        $created = $this->post('/api/v1/students/'.$context['student']->id.'/files', [
            'file' => UploadedFile::fake()->create('identity.pdf', 120, 'application/pdf'),
            'file_type' => 'identity',
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.original_name', 'identity.pdf');
        $fileId = $created->json('data.id');
        $file = $context['student']->files()->findOrFail($fileId);
        Storage::disk('public')->assertExists($file->file_path);
        $this->getJson('/api/v1/students/'.$context['student']->id.'/files')->assertOk()->assertJsonPath('data.0.id', $fileId);
        $this->get('/api/v1/students/'.$context['student']->id.'/files/'.$fileId.'/download', ['Accept' => 'application/json'])->assertOk();

        $this->post('/api/v1/students/'.$context['student']->id.'/photo', [
            'photo' => UploadedFile::fake()->image('student.jpg'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.photo_endpoint', '/api/v1/students/'.$context['student']->id.'/photo');
        $photoPath = $context['student']->fresh()->photo_path;
        Storage::disk('public')->assertExists($photoPath);
        $this->get('/api/v1/students/'.$context['student']->id.'/photo', ['Accept' => 'application/json'])->assertOk();
        $this->deleteJson('/api/v1/students/'.$context['student']->id.'/photo')->assertNoContent();
        Storage::disk('public')->assertMissing($photoPath);

        $this->deleteJson('/api/v1/students/'.$context['student']->id.'/files/'.$fileId)->assertNoContent();
        Storage::disk('public')->assertMissing($file->file_path);
        $this->assertSoftDeleted('student_files', ['id' => $fileId]);
    }

    public function test_scoped_teacher_cannot_access_another_students_progress_or_files(): void
    {
        $context = $this->createContext();
        $other = $this->createContext('Other');
        $teacherUser = User::factory()->create();
        $teacherUser->assignRole('teacher');
        $teacherUser->givePermissionTo(['students.files.manage', 'students.photo.update', 'students.view']);
        $context['teacher']->user()->associate($teacherUser);
        $context['teacher']->save();
        Sanctum::actingAs($teacherUser);

        $this->getJson('/api/v1/students/'.$context['student']->id.'/progress')->assertOk();
        $this->getJson('/api/v1/students/'.$other['student']->id.'/progress')->assertForbidden();
        $this->getJson('/api/v1/students/'.$other['student']->id.'/files')->assertForbidden();
        $this->post('/api/v1/students/'.$other['student']->id.'/files', [
            'file' => UploadedFile::fake()->create('forbidden.pdf', 10, 'application/pdf'),
            'file_type' => 'identity',
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_student_progress_and_files_require_permissions(): void
    {
        $context = $this->createContext();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/students/'.$context['student']->id.'/progress')->assertForbidden();
        $this->getJson('/api/v1/students/'.$context['student']->id.'/files')->assertForbidden();
        $this->post('/api/v1/students/'.$context['student']->id.'/photo', [
            'photo' => UploadedFile::fake()->image('student.jpg'),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    protected function createContext(string $suffix = ''): array
    {
        $year = AcademicYear::query()->where('is_current', true)->firstOrFail();
        $course = Course::query()->create(['academic_year_id' => $year->id, 'is_active' => true, 'name' => 'Progress API Course '.$suffix]);
        $teacher = Teacher::query()->create(['first_name' => 'Progress', 'last_name' => 'Teacher '.$suffix, 'phone' => '097'.random_int(1000000, 9999999), 'status' => 'active']);
        $group = Group::query()->create(['academic_year_id' => $year->id, 'capacity' => 20, 'course_id' => $course->id, 'is_active' => true, 'name' => 'Progress API Group '.$suffix, 'teacher_id' => $teacher->id]);
        $parent = ParentProfile::query()->create(['father_name' => 'Progress Parent '.$suffix]);
        $student = Student::query()->create(['birth_date' => '2014-01-01', 'first_name' => 'Progress', 'last_name' => 'Student '.$suffix, 'parent_id' => $parent->id, 'status' => 'active']);
        $enrollment = Enrollment::query()->create(['enrolled_at' => '2026-09-01', 'group_id' => $group->id, 'status' => 'active', 'student_id' => $student->id]);

        return compact('course', 'enrollment', 'group', 'student', 'teacher');
    }
}
