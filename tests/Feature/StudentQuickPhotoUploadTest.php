<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use App\Services\AccessScopeService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class StudentQuickPhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('public');
    }

    public function test_completed_uploads_save_immediately_to_their_own_students_and_replace_only_the_target_photo(): void
    {
        $this->signInManager();
        $first = $this->student('First');
        $second = $this->student('Second');
        $firstPhoto = UploadedFile::fake()->image('first.png', 100, 100);
        $secondPhoto = UploadedFile::fake()->image('second.png', 120, 120);
        $replacement = UploadedFile::fake()->image('replacement.png', 140, 140);

        $component = Volt::test('students.index')
            ->set('quick_photo_uploads.'.$first->id, $firstPhoto)
            ->assertHasNoErrors()
            ->assertSet('quick_photo_uploads.'.$first->id, null);

        $firstPath = $first->fresh()->photo_path;
        $this->assertPhotoMatches($first, $firstPhoto);
        $this->assertNull($second->fresh()->photo_path);

        $component
            ->set('quick_photo_uploads.'.$second->id, $secondPhoto)
            ->assertHasNoErrors()
            ->assertSet('quick_photo_uploads.'.$second->id, null);

        $this->assertPhotoMatches($first, $firstPhoto);
        $this->assertPhotoMatches($second, $secondPhoto);

        $component
            ->set('quick_photo_uploads.'.$first->id, $replacement)
            ->assertHasNoErrors();

        $this->assertPhotoMatches($first, $replacement);
        $this->assertPhotoMatches($second, $secondPhoto);
        Storage::disk('public')->assertMissing($firstPath);
    }

    public function test_invalid_uploads_show_a_row_error_and_preserve_the_existing_photo(): void
    {
        $this->signInManager();
        $student = $this->student('Validation');
        $original = UploadedFile::fake()->image('original.png');
        $field = 'quick_photo_uploads.'.$student->id;

        $component = Volt::test('students.index')
            ->set($field, $original)
            ->assertHasNoErrors();

        $originalPath = $student->fresh()->photo_path;

        $component
            ->set($field, UploadedFile::fake()->create('document.pdf', 1, 'application/pdf'))
            ->assertHasErrors([$field => 'mimes'])
            ->assertSee('role="alert"', false);

        $this->assertSame($originalPath, $student->fresh()->photo_path);
        $this->assertPhotoMatches($student, $original);

        config(['uploads.image_max_kb' => 1]);
        $component
            ->set($field, UploadedFile::fake()->image('oversized.png')->size(2))
            ->assertHasErrors([$field => 'max']);

        $this->assertSame($originalPath, $student->fresh()->photo_path);
        $this->assertPhotoMatches($student, $original);

        config(['uploads.image_max_kb' => 20480]);
        $component
            ->set($field, UploadedFile::fake()->image('valid.png'))
            ->assertHasNoErrors();
    }

    public function test_view_permission_does_not_allow_photo_uploads(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('students.view');
        $this->actingAs($user);
        $student = $this->student('Denied');

        Volt::test('students.index')
            ->set('quick_photo_uploads.'.$student->id, UploadedFile::fake()->image('denied.png'))
            ->assertForbidden();

        $this->assertNull($student->fresh()->photo_path);
        $this->assertSame([], Storage::disk('public')->allFiles('students/photos'));
    }

    public function test_photo_permission_cannot_target_a_student_outside_the_users_scope(): void
    {
        $user = User::factory()->create();
        $user->assignRole('teacher');
        $user->givePermissionTo(['students.view', 'students.photo.update']);
        $allowed = $this->student('Allowed');
        $denied = $this->student('Outside');
        app(AccessScopeService::class)->syncUserOverrides($user, ['student' => [$allowed->id]]);
        $this->actingAs($user);

        Volt::test('students.index')
            ->set('quick_photo_uploads.'.$allowed->id, UploadedFile::fake()->image('allowed.png'))
            ->assertHasNoErrors()
            ->set('quick_photo_uploads.'.$denied->id, UploadedFile::fake()->image('outside.png'))
            ->assertForbidden();

        $this->assertNotNull($allowed->fresh()->photo_path);
        $this->assertNull($denied->fresh()->photo_path);
        $this->assertSame([], Storage::disk('public')->allFiles('students/photos/'.$denied->id));
    }

    private function signInManager(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $this->actingAs($manager);
    }

    private function student(string $name): Student
    {
        return Student::create([
            'first_name' => $name,
            'last_name' => 'Photo',
            'birth_date' => '2014-05-12',
            'status' => 'active',
        ]);
    }

    private function assertPhotoMatches(Student $student, UploadedFile $photo): void
    {
        $path = $student->fresh()->photo_path;
        $this->assertNotNull($path);
        $this->assertSame(hash_file('sha256', $photo->getRealPath()), hash('sha256', Storage::disk('public')->get($path)));
    }
}
