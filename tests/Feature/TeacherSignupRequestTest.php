<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TeacherSignupRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_teacher_signup_page_is_available_and_creates_pending_request(): void
    {
        Storage::fake('public');
        $this->seed(RoleSeeder::class);

        $this->get('/teacher-signup')->assertOk();
        $this->get('/teacherSingup')->assertNotFound();

        Volt::test('public.teacher-signup')
            ->set('first_name', 'Ahmad')
            ->set('last_name', 'Darwish')
            ->set('phone', '0944000100')
            ->set('username', 'ahmad-darwish')
            ->set('password', 'Secret123')
            ->set('photo_upload', UploadedFile::fake()->create('teacher-photo.jpg', 128, 'image/jpeg'))
            ->call('submit')
            ->assertHasNoErrors();

        $teacher = Teacher::query()->with('user')->firstOrFail();

        $this->assertSame('Ahmad', $teacher->first_name);
        $this->assertSame('Darwish', $teacher->last_name);
        $this->assertSame('pending', $teacher->status);
        $this->assertSame('0944000100', $teacher->phone);
        $this->assertFalse($teacher->user->is_active);
        $this->assertTrue($teacher->user->hasRole('teacher'));
        $this->assertSame('ahmad-darwish', $teacher->user->username);
        $this->assertNotNull($teacher->photo_path);
        $this->assertSame($teacher->photo_path, $teacher->user->profile_photo_path);
        Storage::disk('public')->assertExists($teacher->photo_path);
    }

    public function test_public_teacher_signup_photo_is_optional(): void
    {
        Storage::fake('public');
        $this->seed(RoleSeeder::class);

        Volt::test('public.teacher-signup')
            ->set('first_name', 'No')
            ->set('last_name', 'Photo')
            ->set('phone', '0944000101')
            ->set('username', 'teacher-no-photo')
            ->set('password', 'Secret123')
            ->call('submit')
            ->assertHasNoErrors();

        $teacher = Teacher::query()->with('user')->firstOrFail();

        $this->assertSame('pending', $teacher->status);
        $this->assertNull($teacher->photo_path);
        $this->assertNull($teacher->user->profile_photo_path);
    }

    public function test_public_teacher_signup_page_can_be_disabled(): void
    {
        AppSetting::storeValue('website', 'teacher_signup_enabled', false, 'boolean');

        $this->get('/teacher-signup')->assertNotFound();
        $this->get('/teacherSingup')->assertNotFound();
        $this->get('/teacherSignup')->assertNotFound();
    }

    public function test_manager_can_approve_pending_teacher_signup_request(): void
    {
        $this->signInManager();

        $accessRole = Role::query()->create([
            'name' => 'lead-teacher-review',
            'guard_name' => 'web',
        ]);

        $user = User::factory()->create([
            'name' => 'Pending Teacher',
            'username' => 'pending-teacher',
            'is_active' => false,
        ]);
        $user->assignRole('teacher');

        $teacher = Teacher::query()->create([
            'user_id' => $user->id,
            'first_name' => 'Pending',
            'last_name' => 'Teacher',
            'phone' => '',
            'status' => 'pending',
            'is_helping' => false,
        ]);

        Volt::test('teachers.index')
            ->call('openReviewModal', $teacher->id)
            ->set('first_name', 'Approved')
            ->set('last_name', 'Teacher')
            ->set('phone', '0944000200')
            ->set('access_role_id', (string) $accessRole->id)
            ->set('hired_at', '2026-05-03')
            ->set('is_helping', true)
            ->set('notes', 'Approved from public request')
            ->call('approveSignupRequest')
            ->assertHasNoErrors();

        $teacher->refresh();
        $teacher->load('user');

        $this->assertSame('Approved', $teacher->first_name);
        $this->assertSame('active', $teacher->status);
        $this->assertSame('0944000200', $teacher->phone);
        $this->assertSame('2026-05-03', $teacher->hired_at?->format('Y-m-d'));
        $this->assertTrue($teacher->is_helping);
        $this->assertSame($accessRole->id, $teacher->access_role_id);
        $this->assertTrue($teacher->user->is_active);
        $this->assertFalse($teacher->user->hasRole('teacher'));
        $this->assertTrue($teacher->user->hasRole($accessRole->name));
    }

    public function test_manager_can_decline_pending_teacher_signup_request(): void
    {
        $this->signInManager();

        $user = User::factory()->create([
            'name' => 'Decline Teacher',
            'username' => 'decline-teacher',
            'is_active' => false,
        ]);
        $user->assignRole('teacher');

        $teacher = Teacher::query()->create([
            'user_id' => $user->id,
            'first_name' => 'Decline',
            'last_name' => 'Teacher',
            'phone' => '',
            'status' => 'pending',
            'is_helping' => false,
        ]);

        Volt::test('teachers.index')
            ->call('openReviewModal', $teacher->id)
            ->set('notes', 'Missing management approval')
            ->call('declineSignupRequest')
            ->assertHasNoErrors();

        $teacher->refresh();
        $teacher->load('user');

        $this->assertSame('declined', $teacher->status);
        $this->assertFalse($teacher->user->is_active);
    }

    private function signInManager(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create([
            'name' => 'Manager User',
            'username' => 'manager-review-user',
            'phone' => '0999999998',
        ]);

        $user->assignRole('manager');

        $this->actingAs($user);
    }
}
