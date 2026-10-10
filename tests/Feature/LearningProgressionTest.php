<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AppSetting;
use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\CurriculumLesson;
use App\Models\CurriculumSubject;
use App\Models\CurriculumSubjectDefinition;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Landlord\Tenant;
use App\Models\LearningProgressionLevel;
use App\Models\ParentProfile;
use App\Models\QuranJuz;
use App\Models\QuranPartialTest;
use App\Models\Student;
use App\Models\User;
use App\Services\Landlord\TenantContext;
use App\Services\LearningProgressionService;
use App\Services\MemorizationService;
use App\Services\QuranFinalTestService;
use App\Services\QuranPartialTestService;
use App\Services\SidebarNavigationService;
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

    public function test_learning_progression_remains_in_settings_without_a_duplicate_sidebar_item(): void
    {
        $this->seed();

        $user = User::factory()->create(['username' => 'progression-navigation-editor']);
        $user->givePermissionTo('learning-progression.manage');

        $item = collect(app(SidebarNavigationService::class)->sidebarFor($user))
            ->pluck('items')
            ->flatten(1)
            ->firstWhere('key', 'learning_progression_settings');

        $this->assertNull($item);

        $this->actingAs($user)
            ->get(route('settings.learning-progression', absolute: false))
            ->assertOk()
            ->assertSee(__('learning_progression.navigation'));
    }

    public function test_authorized_tenant_user_can_build_an_ordered_lesson_level_progression(): void
    {
        $this->useLearningPath(Tenant::LEARNING_PATH_LESSON_LEVEL);
        $this->signInAsAdministrator();
        [$group, $lesson, $assessment] = $this->lessonLevelRecords('Foundation');

        Volt::test('settings.learning-progression')
            ->assertSet('profile', LearningProgressionService::PROFILE_LESSON_LEVEL)
            ->assertDontSee('name="profile"', false)
            ->call('createLevel')
            ->set('levelName', 'Foundation')
            ->set('levelDescription', 'The first learning stage')
            ->set('attendanceThreshold', '85')
            ->set('finalAssessmentId', $assessment->id)
            ->set('passingScore', '60')
            ->set('groupIds', [(string) $group->id])
            ->set('lessonIds', [(string) $lesson->id])
            ->call('saveLevel')
            ->assertHasNoErrors()
            ->assertSet('configured', true)
            ->assertSet('profile', LearningProgressionService::PROFILE_LESSON_LEVEL);

        $level = LearningProgressionLevel::query()->firstOrFail();
        $this->assertSame('Foundation', $level->name);
        $this->assertSame(1, $level->sort_order);
        $this->assertTrue($level->groups()->whereKey($group->id)->exists());
        $this->assertTrue($level->lessons()->whereKey($lesson->id)->exists());
    }

    public function test_lesson_level_rejects_lessons_from_an_unselected_group_curriculum(): void
    {
        $this->useLearningPath(Tenant::LEARNING_PATH_LESSON_LEVEL);
        $this->seed();
        [$group, , $assessment] = $this->lessonLevelRecords('Selected');
        [, $otherLesson] = $this->lessonLevelRecords('Other');

        $this->expectException(ValidationException::class);

        app(LearningProgressionService::class)->storeLevel([
            'name' => 'Invalid level',
            'description' => null,
            'attendance_threshold' => 80,
            'final_assessment_id' => $assessment->id,
            'passing_score' => 60,
            'group_ids' => [$group->id],
            'lesson_ids' => [$otherLesson->id],
        ]);
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

    public function test_tenant_learning_path_controls_available_progression_configuration(): void
    {
        $this->useLearningPath(Tenant::LEARNING_PATH_LESSON_LEVEL);

        $this->assertSame(
            LearningProgressionService::PROFILE_LESSON_LEVEL,
            app(LearningProgressionService::class)->settings()['profile'],
        );

        $this->expectException(ValidationException::class);

        app(LearningProgressionService::class)->storeQuranSettings([
            'partial_test_enabled' => true,
            'partial_test_required_for_final' => true,
            'final_test_enabled' => true,
            'final_test_required_for_awqaf' => true,
            'awqaf_test_enabled' => true,
        ]);
    }

    public function test_saas_tenant_must_configure_progression_before_recording_quran_progress(): void
    {
        $context = app(TenantContext::class);
        $context->set(new Tenant([
            'uuid' => 'progression-gate-tenant',
            'name' => 'Progression Gate Tenant',
            'slug' => 'progression-gate',
            'database_name' => 'progression_gate',
            'status' => Tenant::STATUS_ACTIVE,
        ]));

        $service = app(LearningProgressionService::class);

        $this->assertTrue($service->configurationRequired());

        try {
            $service->ensureConfigured();
            $this->fail('The progression setup gate did not reject an unconfigured SaaS tenant.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('learning_progression.errors.not_configured'),
                $exception->errors()['learning_progression'][0],
            );
        }

        $service->storeQuranSettings([
            'partial_test_enabled' => true,
            'partial_test_required_for_final' => true,
            'final_test_enabled' => true,
            'final_test_required_for_awqaf' => true,
            'awqaf_test_enabled' => true,
        ]);

        $this->assertFalse($service->configurationRequired());
        $service->ensureConfigured();
        $context->clear();
    }

    public function test_existing_quran_records_are_backfilled_with_the_legacy_progression(): void
    {
        [$student, $enrollment] = $this->studentEnrollment();
        $juz = QuranJuz::query()->where('juz_number', 1)->firstOrFail();

        QuranPartialTest::query()->create([
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'juz_id' => $juz->id,
            'status' => 'in_progress',
        ]);

        $migration = require database_path('migrations/2026_10_02_130000_backfill_existing_quran_progression_configuration.php');
        $migration->up();

        $settings = app(LearningProgressionService::class)->settings();
        $this->assertTrue($settings['configured']);
        $this->assertTrue($settings['partial_test_enabled']);
        $this->assertTrue($settings['final_test_enabled']);
        $this->assertTrue($settings['awqaf_test_enabled']);
    }

    public function test_quran_recording_services_apply_the_saas_setup_gate(): void
    {
        [, $enrollment] = $this->studentEnrollment();
        $juz = QuranJuz::query()->where('juz_number', 1)->firstOrFail();
        app(TenantContext::class)->set(new Tenant([
            'uuid' => 'recording-gate-tenant',
            'name' => 'Recording Gate Tenant',
            'slug' => 'recording-gate',
            'database_name' => 'recording_gate',
            'status' => Tenant::STATUS_ACTIVE,
        ]));

        try {
            app(MemorizationService::class)->saveSession($enrollment, []);
            $this->fail('Memorization started before progression setup.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('learning_progression.errors.not_configured'),
                $exception->errors()['from_page'][0],
            );
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(__('learning_progression.errors.not_configured'));

        app(QuranPartialTestService::class)->create($enrollment->fresh('student'), $juz);
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

    private function useLearningPath(string $learningPath): void
    {
        app(TenantContext::class)->set(new Tenant([
            'uuid' => 'learning-path-test-tenant',
            'name' => 'Learning Path Test Tenant',
            'slug' => 'learning-path-test',
            'database_name' => 'learning_path_test',
            'status' => Tenant::STATUS_ACTIVE,
            'learning_path_type' => $learningPath,
        ]));
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

    private function lessonLevelRecords(string $suffix): array
    {
        $course = Course::query()->create(['name' => "Lesson Course {$suffix}", 'is_active' => true]);
        $curriculum = Curriculum::query()->create(['course_id' => $course->id, 'name' => "Curriculum {$suffix}", 'is_active' => true]);
        $definition = CurriculumSubjectDefinition::query()->create(['name' => "Subject {$suffix}", 'is_active' => true]);
        $subject = CurriculumSubject::query()->create([
            'curriculum_id' => $curriculum->id,
            'subject_definition_id' => $definition->id,
            'sort_order' => 1,
        ]);
        $lesson = CurriculumLesson::query()->create([
            'curriculum_subject_id' => $subject->id,
            'name' => "Lesson {$suffix}",
            'page_count' => 1,
            'importance' => 1,
            'sort_order' => 1,
        ]);
        $group = Group::query()->create([
            'course_id' => $course->id,
            'academic_year_id' => AcademicYear::query()->where('is_current', true)->value('id'),
            'curriculum_id' => $curriculum->id,
            'name' => "Level Group {$suffix}",
            'capacity' => 20,
            'is_active' => true,
        ]);
        $assessment = Assessment::query()->create([
            'group_id' => $group->id,
            'assessment_type_id' => AssessmentType::query()->value('id'),
            'title' => "Final Assessment {$suffix}",
            'total_mark' => 100,
            'pass_mark' => 60,
            'is_active' => true,
        ]);
        $assessment->groups()->sync([$group->id]);

        return [$group, $lesson, $assessment];
    }
}
