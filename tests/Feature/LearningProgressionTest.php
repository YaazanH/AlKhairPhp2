<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AppSetting;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\ParentProfile;
use App\Models\QuranJuz;
use App\Models\QuranPartialTest;
use App\Models\Student;
use App\Models\User;
use App\Services\LearningProgressionService;
use App\Services\QuranFinalTestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use LogicException;
use Tests\TestCase;

class LearningProgressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_tenant_user_can_configure_the_quran_progression(): void
    {
        $this->signInAsAdministrator();

        Volt::test('settings.learning-progression')
            ->set('partial_test_enabled', false)
            ->assertSet('partial_test_required_for_final', false)
            ->set('final_test_enabled', true)
            ->set('final_test_required_for_awqaf', false)
            ->set('awqaf_test_enabled', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('configured', true);

        $settings = app(LearningProgressionService::class)->settings();

        $this->assertFalse($settings['partial_test_enabled']);
        $this->assertFalse($settings['partial_test_required_for_final']);
        $this->assertTrue($settings['final_test_enabled']);
        $this->assertFalse($settings['final_test_required_for_awqaf']);
        $this->assertTrue($settings['awqaf_test_enabled']);
    }

    public function test_dedicated_permission_can_open_progression_settings_without_general_settings_access(): void
    {
        $this->seed();

        $user = User::factory()->create(['username' => 'progression-editor']);
        $user->givePermissionTo('learning-progression.manage');

        $this->actingAs($user)
            ->get(route('settings.learning-progression', absolute: false))
            ->assertOk()
            ->assertSee(__('learning_progression.title'))
            ->assertDontSee(__('settings.navigation.organization.title'));
    }

    public function test_invalid_prerequisite_combinations_are_rejected(): void
    {
        $service = app(LearningProgressionService::class);

        $this->expectException(ValidationException::class);

        $service->storeQuranSettings([
            'partial_test_enabled' => false,
            'partial_test_required_for_final' => true,
            'final_test_enabled' => true,
            'final_test_required_for_awqaf' => true,
            'awqaf_test_enabled' => true,
        ]);
    }

    public function test_progression_locks_after_a_quran_test_cycle_exists(): void
    {
        [$student, $enrollment] = $this->studentEnrollment();
        $juz = QuranJuz::query()->where('juz_number', 1)->firstOrFail();

        QuranPartialTest::query()->create([
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'juz_id' => $juz->id,
            'status' => 'in_progress',
        ]);

        $this->assertTrue(app(LearningProgressionService::class)->isLocked());

        $this->expectException(LogicException::class);

        app(LearningProgressionService::class)->storeQuranSettings([
            'partial_test_enabled' => true,
            'partial_test_required_for_final' => false,
            'final_test_enabled' => true,
            'final_test_required_for_awqaf' => false,
            'awqaf_test_enabled' => true,
        ]);
    }

    public function test_optional_partial_test_allows_memorized_juz_into_final_test_eligibility(): void
    {
        [$student] = $this->studentEnrollment();
        $juz = QuranJuz::query()->where('juz_number', 1)->firstOrFail();

        AppSetting::storeValue('quran_tests', 'require_memorization_progress', false, 'boolean');
        app(LearningProgressionService::class)->storeQuranSettings([
            'partial_test_enabled' => true,
            'partial_test_required_for_final' => false,
            'final_test_enabled' => true,
            'final_test_required_for_awqaf' => true,
            'awqaf_test_enabled' => true,
        ]);

        $this->assertContains($juz->id, app(QuranFinalTestService::class)->eligibleJuzIdsForStudent($student)->all());
    }

    public function test_disabled_test_stage_cannot_be_started(): void
    {
        app(LearningProgressionService::class)->storeQuranSettings([
            'partial_test_enabled' => false,
            'partial_test_required_for_final' => false,
            'final_test_enabled' => true,
            'final_test_required_for_awqaf' => true,
            'awqaf_test_enabled' => true,
        ]);

        $this->expectException(LogicException::class);
        app(LearningProgressionService::class)->ensureTestEnabled('partial');
    }

    private function signInAsAdministrator(): User
    {
        $this->seed();

        $user = User::factory()->create(['username' => 'progression-admin']);
        $user->assignRole('admin');
        $this->actingAs($user);

        return $user;
    }

    private function studentEnrollment(): array
    {
        $this->seed();

        $parent = ParentProfile::query()->create(['father_name' => 'Progress Parent']);
        $student = Student::query()->create([
            'parent_id' => $parent->id,
            'first_name' => 'Progress',
            'last_name' => 'Student',
            'birth_date' => '2014-01-01',
            'status' => 'active',
        ]);
        $course = Course::query()->create(['name' => 'Progress Course', 'is_active' => true]);
        $group = Group::query()->create([
            'course_id' => $course->id,
            'academic_year_id' => AcademicYear::query()->where('is_current', true)->value('id'),
            'name' => 'Progress Group',
            'capacity' => 20,
            'is_active' => true,
        ]);
        $enrollment = Enrollment::query()->create([
            'student_id' => $student->id,
            'group_id' => $group->id,
            'enrolled_at' => '2026-10-01',
            'status' => 'active',
        ]);

        return [$student, $enrollment];
    }
}
