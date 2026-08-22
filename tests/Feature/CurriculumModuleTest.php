<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Course;
use App\Models\Curriculum;
use App\Models\CurriculumLesson;
use App\Models\CurriculumLessonTopic;
use App\Models\CurriculumResource;
use App\Models\CurriculumSubject;
use App\Models\CurriculumSubjectDefinition;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\Teacher;
use App\Models\User;
use App\Services\CurriculumProgressService;
use App\Services\SidebarNavigationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CurriculumModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_curriculum_visibility_is_limited_to_management_and_group_supervisors(): void
    {
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $this->actingAs($manager)->get(route('curricula.index', absolute: false))->assertOk();
        $this->actingAs($manager)->get(route('settings.curriculum-subjects', absolute: false))->assertOk();

        $regularTeacherUser = User::factory()->create();
        $regularTeacherUser->assignRole('teacher');
        Teacher::create(['user_id' => $regularTeacherUser->id, 'first_name' => 'Regular', 'last_name' => 'Teacher', 'phone' => '0944007001', 'job_title' => 'Quran Teacher', 'status' => 'active', 'is_helping' => true]);
        $this->actingAs($regularTeacherUser)->get(route('curricula.index', absolute: false))->assertForbidden();

        $supervisorUser = User::factory()->create();
        $supervisorUser->assignRole('teacher');
        Teacher::create(['user_id' => $supervisorUser->id, 'first_name' => 'Group', 'last_name' => 'Supervisor', 'phone' => '0944007002', 'job_title' => 'مشرف حلقة', 'status' => 'active', 'is_helping' => true]);
        $this->actingAs($supervisorUser)->get(route('curricula.index', absolute: false))->assertOk();
    }

    public function test_group_teacher_curriculum_is_shown_immediately_after_reports_in_sidebar(): void
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('teacher');
        $user->givePermissionTo(['dashboard.group-teacher.view', 'reports.view']);
        Teacher::create([
            'user_id' => $user->id,
            'first_name' => 'Group',
            'last_name' => 'Teacher',
            'phone' => '0944007099',
            'job_title' => 'Teacher',
            'status' => 'active',
            'is_helping' => true,
        ]);

        $platform = collect(app(SidebarNavigationService::class)->sidebarFor($user))->firstWhere('key', 'platform');

        $this->assertSame(['dashboard', 'reports', 'curricula'], array_column($platform['items'], 'key'));
        $this->assertSame(__('ui.nav.my_curriculum'), $platform['items'][2]['label']);
    }

    public function test_manager_can_build_a_curriculum_and_assign_it_to_a_group(): void
    {
        $this->seed(RoleSeeder::class);
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $this->actingAs($manager);

        [$course, $year, $grade, $teacher] = $this->learningStructure();

        Volt::test('settings.curriculum-subjects')
            ->set('subjectName', 'Faith')
            ->call('saveSubject')
            ->assertHasNoErrors();

        $definition = CurriculumSubjectDefinition::query()->firstOrFail();
        Volt::test('curricula.index')
            ->call('openCurriculum')
            ->set('curriculumName', 'Grade curriculum')
            ->set('curriculumGradeId', (string) $grade->id)
            ->call('saveCurriculum')
            ->assertHasNoErrors();

        $curriculum = Curriculum::query()->where('name', 'Grade curriculum')->firstOrFail();
        $this->assertNull($curriculum->course_id);

        Volt::test('curricula.show', ['curriculum' => $curriculum])
            ->set('subjectDefinitionId', (string) $definition->id)
            ->call('saveSubject')
            ->assertHasNoErrors();

        $subject = CurriculumSubject::query()->firstOrFail();
        Volt::test('curricula.show', ['curriculum' => $curriculum])
            ->set("newLessonDrafts.{$subject->id}.0.name", 'First lesson')
            ->set("newLessonDrafts.{$subject->id}.0.page_count", '8')
            ->set("newLessonDrafts.{$subject->id}.0.importance", 3)
            ->call('saveInlineLesson', $subject->id, 0)
            ->assertHasNoErrors();

        Volt::test('groups.index')
            ->call('openCreateModal')
            ->set('course_id', $course->id)
            ->set('academic_year_id', $year->id)
            ->set('teacher_id', $teacher->id)
            ->set('grade_level_id', $grade->id)
            ->set('curriculum_id', $curriculum->id)
            ->set('name', 'Curriculum Group')
            ->set('capacity', '20')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('groups', ['name' => 'Curriculum Group', 'curriculum_id' => $curriculum->id]);
        $this->assertDatabaseHas('curriculum_lessons', ['name' => 'First lesson', 'page_count' => 8, 'importance' => 3]);
    }

    public function test_standalone_books_are_managed_inline_inside_their_popup(): void
    {
        $this->seed(RoleSeeder::class);
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $this->actingAs($manager);

        Volt::test('settings.curriculum-subjects')
            ->assertSet('showStandaloneResourcesModal', false)
            ->call('openStandaloneResources')
            ->assertSet('showStandaloneResourcesModal', true)
            ->call('openStandaloneResourceForm')
            ->assertSet('showStandaloneResourceForm', true)
            ->assertSet('showResourceModal', false)
            ->set('bookName', 'Standalone handbook')
            ->set('editionNumber', '2')
            ->set('publishedOn', '2026')
            ->call('saveResource')
            ->assertHasNoErrors()
            ->assertSet('showStandaloneResourcesModal', true)
            ->assertSet('showStandaloneResourceForm', false);

        $resource = CurriculumResource::query()->where('book_name', 'Standalone handbook')->firstOrFail();
        $this->assertNull($resource->subject_definition_id);
        $this->assertSame('الطبعة 2', $resource->edition_number);
    }

    public function test_supervisor_can_record_partial_progress_and_custom_lessons(): void
    {
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('teacher');
        [$course, $year, $grade] = $this->learningStructure(false);
        $teacher = Teacher::create(['user_id' => $user->id, 'first_name' => 'Halaqa', 'last_name' => 'Supervisor', 'phone' => '0944007003', 'job_title' => 'مساعد مشرف حلقة', 'status' => 'active', 'is_helping' => true]);
        $curriculum = Curriculum::create(['course_id' => $course->id, 'grade_level_id' => $grade->id, 'name' => 'Teacher curriculum', 'is_active' => true]);
        $definition = CurriculumSubjectDefinition::create(['name' => 'Manners', 'is_active' => true]);
        $subject = CurriculumSubject::create(['curriculum_id' => $curriculum->id, 'subject_definition_id' => $definition->id]);
        $first = CurriculumLesson::create(['curriculum_subject_id' => $subject->id, 'name' => 'Respect', 'page_count' => 4, 'importance' => 2]);
        CurriculumLesson::create(['curriculum_subject_id' => $subject->id, 'name' => 'Honesty', 'page_count' => 5, 'importance' => 3]);
        $group = Group::create(['course_id' => $course->id, 'academic_year_id' => $year->id, 'teacher_id' => $teacher->id, 'grade_level_id' => $grade->id, 'curriculum_id' => $curriculum->id, 'name' => 'Supervisor Group', 'capacity' => 20, 'is_active' => true]);

        $this->actingAs($user);
        Volt::test('curricula.index')
            ->set('selectedGroupId', (string) $group->id)
            ->set('progressLessonId', $first->id)
            ->set('progressDate', '2026-08-11')
            ->set('progressStatus', 'partial')
            ->call('saveProgress')
            ->assertHasNoErrors();

        $summary = app(CurriculumProgressService::class)->summary($group->fresh());
        $this->assertSame(25.0, $summary['percentage']);

        Volt::test('curricula.index')
            ->set('selectedGroupId', (string) $group->id)
            ->set('customSubjectName', 'Community')
            ->set('customLessonName', 'Helping neighbours')
            ->set('customPageCount', '2')
            ->set('customImportance', 1)
            ->set('customDate', '2026-08-11')
            ->set('customStatus', 'taught')
            ->call('saveCustom')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('group_custom_curriculum_lessons', ['group_id' => $group->id, 'subject_name' => 'Community', 'name' => 'Helping neighbours', 'status' => 'taught']);
    }

    public function test_lesson_topics_roll_up_completion_and_remove_the_parent_checkbox(): void
    {
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('teacher');
        [$course, $year, $grade] = $this->learningStructure(false);
        $teacher = Teacher::create(['user_id' => $user->id, 'first_name' => 'Topic', 'last_name' => 'Supervisor', 'phone' => '0944007004', 'job_title' => 'مشرف حلقة', 'status' => 'active', 'is_helping' => true]);
        $curriculum = Curriculum::create(['course_id' => $course->id, 'grade_level_id' => $grade->id, 'name' => 'Topic curriculum', 'is_active' => true]);
        $definition = CurriculumSubjectDefinition::create(['name' => 'Topic subject', 'is_active' => true]);
        $subject = CurriculumSubject::create(['curriculum_id' => $curriculum->id, 'subject_definition_id' => $definition->id]);
        $lesson = CurriculumLesson::create(['curriculum_subject_id' => $subject->id, 'name' => 'Parent lesson', 'page_count' => 4, 'importance' => 2]);
        $firstTopic = CurriculumLessonTopic::create(['curriculum_lesson_id' => $lesson->id, 'name' => 'First topic', 'sort_order' => 10]);
        $secondTopic = CurriculumLessonTopic::create(['curriculum_lesson_id' => $lesson->id, 'name' => 'Second topic', 'sort_order' => 20]);
        $group = Group::create(['course_id' => $course->id, 'academic_year_id' => $year->id, 'teacher_id' => $teacher->id, 'grade_level_id' => $grade->id, 'curriculum_id' => $curriculum->id, 'name' => 'Topic Group', 'capacity' => 20, 'is_active' => true]);

        $this->actingAs($user);
        Volt::test('curricula.index')->set('selectedGroupId', (string) $group->id)->assertSee('First topic')->call('toggleTopic', $firstTopic->id)->assertHasNoErrors();
        $this->assertSame(0.0, app(CurriculumProgressService::class)->summary($group->fresh())['percentage']);

        Volt::test('curricula.index')->set('selectedGroupId', (string) $group->id)->call('toggleTopic', $secondTopic->id)->assertHasNoErrors();
        $this->assertSame(100.0, app(CurriculumProgressService::class)->summary($group->fresh())['percentage']);
        $this->assertDatabaseHas('group_curriculum_lesson_progresses', ['group_id' => $group->id, 'curriculum_lesson_id' => $lesson->id, 'status' => 'taught']);
    }

    private function learningStructure(bool $withTeacher = true): array
    {
        $course = Course::create(['name' => 'Default curriculum course', 'is_active' => true, 'is_default' => true]);
        $year = AcademicYear::create(['name' => '2026/2027', 'starts_on' => '2026-08-01', 'ends_on' => '2027-07-31', 'is_current' => true, 'is_active' => true]);
        $grade = GradeLevel::create(['name' => 'Grade curriculum', 'sort_order' => 1, 'is_active' => true]);
        $teacher = $withTeacher ? Teacher::create(['first_name' => 'Curriculum', 'last_name' => 'Teacher', 'phone' => '0944007004', 'status' => 'active', 'is_helping' => true]) : null;

        return [$course, $year, $grade, $teacher];
    }
}
