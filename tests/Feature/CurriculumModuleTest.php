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
use App\Models\GroupCurriculumLessonProgress;
use App\Models\Teacher;
use App\Models\User;
use App\Services\CurriculumProgressService;
use App\Services\SidebarNavigationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CurriculumModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_lesson_counts_use_the_requested_arabic_grammar(): void
    {
        $this->assertSame('0 درس', trans_choice('curricula.counts.lessons', 0, ['count' => 0], 'ar'));
        $this->assertSame('1 درس', trans_choice('curricula.counts.lessons', 1, ['count' => 1], 'ar'));
        $this->assertSame('درسين', trans_choice('curricula.counts.lessons', 2, ['count' => 2], 'ar'));
        $this->assertSame('3 دروس', trans_choice('curricula.counts.lessons', 3, ['count' => 3], 'ar'));
        $this->assertSame('10 دروس', trans_choice('curricula.counts.lessons', 10, ['count' => 10], 'ar'));
        $this->assertSame('11 درس', trans_choice('curricula.counts.lessons', 11, ['count' => 11], 'ar'));
        $this->assertSame('25 درس', trans_choice('curricula.counts.lessons', 25, ['count' => 25], 'ar'));
    }

    public function test_curriculum_visibility_is_limited_to_management_and_group_supervisors(): void
    {
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $this->actingAs($manager)->get(route('curricula.index', absolute: false))
            ->assertOk()
            ->assertSee('data-curricula-table-toolbar', false)
            ->assertSee('data-curricula-add-icon', false);
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
        $this->assertSame('books-leaning', collect($platform['items'])->firstWhere('key', 'curricula')['icon']);
        $this->assertFileExists(resource_path('views/flux/icon/books-leaning.blade.php'));
        $icon = File::get(resource_path('views/flux/icon/books-leaning.blade.php'));
        $this->assertStringContainsString('data-curricula-icon="three-standing-books-one-leaning"', $icon);
        $this->assertStringNotContainsString('three-stacked-bookmarked-books', $icon);

        $this->assertSame(['dashboard', 'reports', 'curricula'], array_column($platform['items'], 'key'));
        $this->assertSame(__('ui.nav.my_curriculum'), $platform['items'][2]['label']);
    }

    public function test_downloadable_books_use_the_standard_icon_button(): void
    {
        $source = File::get(resource_path('views/livewire/curricula/index.blade.php'));
        $button = File::get(resource_path('views/components/download-action-button.blade.php'));
        $icon = File::get(resource_path('views/components/admin-action-icon.blade.php'));

        $this->assertStringContainsString('<x-download-action-button :href="route(\'curriculum-resources.download\', $resource)" class="admin-icon-button--accent"', $source);
        $this->assertStringContainsString('data-curriculum-resource-download', $source);
        $this->assertStringContainsString("\$attributes->class('admin-icon-button')", $button);
        $this->assertStringContainsString('data-download-action', $button);
        $this->assertStringContainsString('<x-admin-action-icon name="download" />', $button);
        $this->assertStringNotContainsString('>⬇</a>', $source);
        $this->assertStringContainsString("@case('download')", $icon);
    }

    public function test_curriculum_progress_pies_are_hidden_on_mobile_and_use_five_desktop_columns(): void
    {
        $source = File::get(resource_path('views/livewire/curricula/index.blade.php'));
        $styles = File::get(resource_path('css/app.css'));

        $this->assertStringContainsString('data-curricula-progress-card', $source);
        $this->assertStringContainsString('data-curricula-progress-grid', $source);
        $this->assertStringNotContainsString("<h2 class=\"font-display text-2xl text-white\">{{ __('curricula.progress.title') }}</h2>", $source);
        $this->assertStringContainsString('wire:model.live="courseId" required aria-required="true" data-clearable="false" data-search-selection-required="true" data-hide-placeholder-option="true"', $source);
        $this->assertStringContainsString('synchronizeCurriculaCourseFilterWidths', File::get(resource_path('js/app.js')));
        $this->assertStringContainsString(
            '[data-curricula-index-hero]:has(.curricula-index-course-filter .searchable-select--open)',
            $styles,
        );
        $this->assertMatchesRegularExpression(
            '/\[data-curricula-index-hero\]:has\(\.curricula-index-course-filter \.searchable-select--open\)\s*\{[^}]*z-index:\s*80;[^}]*overflow:\s*visible;/s',
            $styles,
        );
        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 1024px\)\s*\{\s*\[data-curricula-progress-grid\]\s*\{\s*grid-template-columns: repeat\(5, minmax\(0, 1fr\)\);/s',
            $styles,
        );
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 767px\).*?\[data-curricula-progress-card\]\s*\{\s*display: none !important;/s',
            $styles,
        );
        $this->assertStringNotContainsString('lg:grid-cols-4 xl:grid-cols-6', $source);
    }

    public function test_manager_curricula_course_filter_cannot_be_cleared(): void
    {
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $this->actingAs($manager);

        $defaultCourse = Course::create(['name' => 'Default course', 'is_default' => true, 'is_active' => true]);
        Course::create(['name' => 'Another active course', 'is_default' => false, 'is_active' => true]);

        Volt::test('curricula.index')
            ->assertSet('courseId', (string) $defaultCourse->id)
            ->set('courseId', '')
            ->assertSet('courseId', (string) $defaultCourse->id)
            ->assertSee('required aria-required="true" data-clearable="false" data-search-selection-required="true" data-hide-placeholder-option="true"', false);
    }

    public function test_subject_resource_tables_share_column_widths_and_show_book_upload_status(): void
    {
        $this->seed(RoleSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $this->actingAs($manager);

        $definition = CurriculumSubjectDefinition::query()->create([
            'name' => 'Arabic',
            'is_active' => true,
        ]);

        CurriculumResource::query()->create([
            'subject_definition_id' => $definition->id,
            'book_name' => 'Uploaded book',
            'pdf_path' => 'curriculum/books/uploaded.pdf',
            'is_active' => true,
        ]);

        CurriculumResource::query()->create([
            'subject_definition_id' => $definition->id,
            'book_name' => 'Resource without book',
            'pdf_path' => null,
            'is_active' => true,
        ]);

        Volt::test('settings.curriculum-subjects')
            ->assertSee('data-curriculum-settings-table', false)
            ->assertSee('data-settings-mobile-title-action-row', false)
            ->assertSee('data-curriculum-subject-resource-grid', false)
            ->assertSee('data-curriculum-resource-column="name"', false)
            ->assertSee('curriculum-subject-resource-name', false)
            ->assertSee('data-curriculum-resource-edit-icon', false)
            ->assertSee(__('curricula.fields.book'))
            ->assertSee('curriculum-resource-book-status--available', false)
            ->assertSee('>✓</span>', false)
            ->assertSee('>--</span>', false);

        $styles = file_get_contents(resource_path('css/app.css'));
        $settingsSource = file_get_contents(resource_path('views/livewire/settings/curriculum-subjects.blade.php'));

        $this->assertStringContainsString('class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700" data-settings-table data-curriculum-settings-table', $settingsSource);
        $this->assertStringContainsString('class="admin-grid-meta admin-grid-meta--controls" data-mobile-title-action-row data-settings-mobile-title-action-row', $settingsSource);
        $this->assertStringNotContainsString('<section class="surface-panel p-5 lg:p-6"><div class="admin-toolbar"><div class="admin-toolbar__title">{{ __(\'curricula.settings.title\') }}', $settingsSource);

        $this->assertStringContainsString('.curriculum-subject-resource-grid {', $styles);
        $this->assertStringContainsString('table-layout: fixed;', $styles);
        $this->assertStringContainsString('.curriculum-subject-resource-name {', $styles);
        $this->assertStringContainsString('padding-inline-end: 0.625rem !important;', $styles);
        $this->assertStringContainsString('text-overflow: ellipsis;', $styles);
        $this->assertStringContainsString('white-space: nowrap !important;', $styles);

        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString('synchronizeCurriculumSubjectResourceColumns', $script);
        $this->assertStringContainsString('name: { min: 212, max: 372 }', $script);
        $this->assertStringContainsString('year: { min: 52, max: 110 }', $script);
        $this->assertStringContainsString('curriculumResourceColumnGrowWeights', $script);
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
            ->assertHasNoErrors()
            ->assertSee('curriculum-subject-table', false)
            ->assertSee('data-curriculum-resource-index', false)
            ->assertSee('curriculum-resource-index', false)
            ->assertDontSee('0 '.__('curricula.fields.resources'));

        $definition = CurriculumSubjectDefinition::query()->firstOrFail();
        Volt::test('settings.curriculum-subjects')
            ->assertSee('data-curriculum-subject-edit-action', false)
            ->assertDontSee('wire:click="deleteSubject('.$definition->id.')"', false)
            ->call('editSubject', $definition->id)
            ->assertSet('showSubjectModal', true)
            ->assertSee(__('curricula.form.edit_subject_title'))
            ->assertSee('wire:click="deleteSubject('.$definition->id.')"', false);

        $settingsSource = file_get_contents(resource_path('views/livewire/settings/curriculum-subjects.blade.php'));
        $this->assertStringContainsString("\$editingResourceId ? __('curricula.form.edit_resource_title')", $settingsSource);

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
            ->set("newLessonDrafts.{$subject->id}.0.importance", 3)
            ->call('saveInlineLesson', $subject->id, 0)
            ->assertHasNoErrors()
            ->assertSee('data-curriculum-header-actions', false)
            ->assertSee('data-curriculum-lessons-table', false)
            ->assertSee('data-curriculum-add-lesson-row', false)
            ->assertSee('data-importance-bars', false)
            ->assertSee('data-add-lesson-icon', false)
            ->assertSee('data-edit-lesson-icon', false)
            ->assertSee('data-curriculum-topics-toggle', false)
            ->assertSee('data-curriculum-topics-column', false)
            ->assertSee('data-collapsed-direction="left"', false)
            ->assertSee('data-collapsed-topic-count', false)
            ->assertSee('data-topics-default-collapsed', false)
            ->assertSee('data-importance-cell', false)
            ->assertSee('data-curriculum-add-topic-row', false)
            ->assertDontSee(__('curricula.fields.page_count'));

        $lesson = CurriculumLesson::query()->where('name', 'First lesson')->firstOrFail();
        CurriculumLessonTopic::create(['curriculum_lesson_id' => $lesson->id, 'name' => 'Nested topic', 'sort_order' => 10]);

        Volt::test('curricula.show', ['curriculum' => $curriculum])
            ->assertSee('Nested topic')
            ->assertSee('data-curriculum-topic-row', false)
            ->assertSee('data-curriculum-topic-number', false)
            ->assertDontSee('wire:click="deleteLesson('.$lesson->id.')"', false)
            ->call('openLesson', $subject->id, $lesson->id)
            ->assertSet('editingLessonId', $lesson->id)
            ->assertSee('data-curriculum-lesson-editing', false)
            ->assertSee('data-inline-lesson-chapter', false)
            ->assertSee('data-inline-lesson-name', false)
            ->assertDontSee('data-compact-lesson-modal', false)
            ->assertSee('data-curriculum-lesson-save-action', false)
            ->assertSee('data-icon-name="save"', false)
            ->assertSee('data-delete-lesson-in-edit', false)
            ->assertSee('wire:click="deleteLesson('.$lesson->id.')"', false)
            ->set('chapterNumber', '2')
            ->set('lessonName', 'Updated inline lesson')
            ->set('importance', 2)
            ->call('saveLesson')
            ->assertHasNoErrors()
            ->assertSet('editingLessonId', null);

        $this->assertDatabaseHas('curriculum_lessons', [
            'id' => $lesson->id,
            'chapter_number' => '2',
            'name' => 'Updated inline lesson',
            'importance' => 2,
        ]);

        Volt::test('curricula.show', ['curriculum' => $curriculum])
            ->set("newLessonDrafts.{$subject->id}.0.name", 'Auto-added lesson')
            ->call('selectInlineLessonImportance', $subject->id, 0, 3)
            ->assertHasNoErrors()
            ->assertSet("newLessonDrafts.{$subject->id}.0", null);

        $this->assertDatabaseHas('curriculum_lessons', [
            'curriculum_subject_id' => $subject->id,
            'name' => 'Auto-added lesson',
            'importance' => 3,
        ]);

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
        $this->assertDatabaseHas('curriculum_lessons', ['name' => 'Updated inline lesson', 'page_count' => 0, 'importance' => 2]);

        $group = Group::query()->where('name', 'Curriculum Group')->firstOrFail();
        $teacher->update(['status' => 'inactive', 'is_helping' => false]);

        Volt::test('groups.show', ['group' => $group])
            ->call('openEdit')
            ->assertSee('Grade curriculum')
            ->assertSet('curriculum_id', (string) $curriculum->id)
            ->set('capacity', '21')
            ->call('saveGroup')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('groups', [
            'id' => $group->id,
            'teacher_id' => $teacher->id,
            'curriculum_id' => $curriculum->id,
            'capacity' => 21,
        ]);
    }

    public function test_curriculum_detail_uses_shared_square_edit_add_and_delete_icons(): void
    {
        $indexSource = file_get_contents(resource_path('views/livewire/curricula/index.blade.php'));
        $source = file_get_contents(resource_path('views/livewire/curricula/show.blade.php'));

        $this->assertStringContainsString('class="curricula-table-add-action" data-curricula-add-icon', $indexSource);
        $this->assertMatchesRegularExpression(
            '/\[data-curricula-index-table\].*?\.curricula-table-add-action\s*\{[^}]*width:\s*2\.5rem;[^}]*height:\s*2\.5rem;/s',
            file_get_contents(resource_path('css/app.css')),
        );

        foreach ([$indexSource, $source] as $curriculumModalSource) {
            $this->assertStringContainsString('wire:submit="saveCurriculum" class="w-[min(28rem,calc(100vw-3rem))] space-y-4"', $curriculumModalSource);
            $this->assertStringContainsString('data-curriculum-save-action', $curriculumModalSource);
            $this->assertStringContainsString('<x-admin-action-icon name="save" class="admin-modal-action__icon" />', $curriculumModalSource);
        }

        $this->assertStringContainsString('class="flex flex-wrap items-center justify-between gap-4" data-curriculum-detail-hero-content', $source);
        $this->assertStringContainsString('data-curriculum-title-edit-action', $source);
        $this->assertStringContainsString('<x-edit-action-button wire:click="$set(\'showCurriculumModal\', true)"', $source);
        $this->assertStringContainsString('class="admin-action-cluster admin-action-cluster--end" data-curriculum-modal-actions', $source);
        $this->assertStringContainsString('data-curriculum-delete-action', $source);
        $this->assertStringContainsString('<x-delete-action-button wire:click="deleteCurriculum" wire:confirm=', $source);
        $this->assertStringContainsString('class="admin-modal-action-button" data-curriculum-delete-action', $source);
        $this->assertStringContainsString('data-curriculum-subject-edit-action', $source);
        $this->assertStringContainsString('<x-edit-action-button wire:click="openSubject(', $source);
        $this->assertStringContainsString('data-curriculum-subject-delete-action', $source);
        $this->assertStringContainsString('wire:click="deleteSubject({{ $editingSubjectId }})"', $source);
        $this->assertStringContainsString('max-width="fit" compact', $source);
        $this->assertStringContainsString('data-compact-subject-modal', $source);
        $this->assertStringContainsString('data-curriculum-subject-name-disabled', $source);
        $this->assertStringContainsString('data-curriculum-subject-save-action', $source);
        $this->assertStringContainsString('data-curriculum-subject-panel', $source);
        $this->assertStringContainsString('data-curriculum-resource-panel', $source);
        $this->assertStringContainsString('data-table-scroll-region', $source);
        $this->assertStringContainsString('<x-admin-action-icon :name="$editingSubjectId ? \'save\' : \'add\'"', $source);
        $this->assertStringContainsString('data-edit-lesson-icon', $source);
        $this->assertStringContainsString('<x-edit-action-button wire:click="openLesson(', $source);
        $this->assertStringContainsString('data-curriculum-lesson-editing', $source);
        $this->assertStringContainsString('data-inline-lesson-name', $source);
        $this->assertStringNotContainsString('data-compact-lesson-modal', $source);
        $this->assertStringContainsString('data-add-lesson-icon', $source);
        $this->assertStringContainsString('<x-add-action-button wire:click="saveInlineLesson(', $source);
        $this->assertStringContainsString('wire:click="selectInlineLessonImportance(', $source);
        $this->assertStringContainsString('data-curriculum-topic-delete-action', $source);
        $this->assertStringContainsString('data-delete-lesson-in-edit', $source);
        $this->assertStringNotContainsString('data-edit-lesson-icon><svg', $source);
        $this->assertStringNotContainsString('data-add-lesson-icon><svg', $source);
    }

    public function test_curriculum_subject_books_are_edited_in_the_compact_popup(): void
    {
        $this->seed(RoleSeeder::class);
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $this->actingAs($manager);

        [, , $grade] = $this->learningStructure(false);
        $curriculum = Curriculum::create(['grade_level_id' => $grade->id, 'name' => 'Editable curriculum', 'is_active' => true]);
        $definition = CurriculumSubjectDefinition::create(['name' => 'Editable subject', 'is_active' => true]);
        $firstBook = CurriculumResource::create(['subject_definition_id' => $definition->id, 'book_name' => 'First book', 'is_active' => true]);
        $secondBook = CurriculumResource::create(['subject_definition_id' => $definition->id, 'book_name' => 'Second book', 'is_active' => true]);
        $subject = CurriculumSubject::create(['curriculum_id' => $curriculum->id, 'subject_definition_id' => $definition->id, 'sort_order' => 10]);
        $subject->resources()->sync([$firstBook->id]);

        Volt::test('curricula.show', ['curriculum' => $curriculum])
            ->assertSee('data-curriculum-subject-edit-action', false)
            ->assertDontSee('wire:click="deleteSubject('.$subject->id.')"', false)
            ->call('openSubject', $subject->id)
            ->assertSet('editingSubjectId', $subject->id)
            ->assertSet('subjectDefinitionId', (string) $definition->id)
            ->assertSet('resourceIds', [$firstBook->id])
            ->assertSee('data-compact-subject-modal', false)
            ->assertSee('data-curriculum-subject-name-disabled', false)
            ->assertSee('wire:click="deleteSubject('.$subject->id.')"', false)
            ->set('resourceIds', [$secondBook->id])
            ->call('saveSubject')
            ->assertHasNoErrors()
            ->assertSet('showSubjectModal', false);

        $this->assertDatabaseMissing('curriculum_subject_resources', ['curriculum_subject_id' => $subject->id, 'curriculum_resource_id' => $firstBook->id]);
        $this->assertDatabaseHas('curriculum_subject_resources', ['curriculum_subject_id' => $subject->id, 'curriculum_resource_id' => $secondBook->id]);
    }

    public function test_curricula_table_is_numbered_and_sorted_by_grade(): void
    {
        $this->seed(RoleSeeder::class);
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $this->actingAs($manager);

        $laterGrade = GradeLevel::create(['name' => 'Later grade', 'sort_order' => 20, 'is_active' => true]);
        $earlierGrade = GradeLevel::create(['name' => 'Earlier grade', 'sort_order' => 10, 'is_active' => true]);
        $laterCurriculum = Curriculum::create(['grade_level_id' => $laterGrade->id, 'name' => 'A later curriculum', 'is_active' => true]);
        $ungradedCurriculum = Curriculum::create(['grade_level_id' => null, 'name' => 'An ungraded curriculum', 'is_active' => true]);
        $earlierCurriculum = Curriculum::create(['grade_level_id' => $earlierGrade->id, 'name' => 'Z earlier curriculum', 'is_active' => true]);

        Volt::test('curricula.index')
            ->assertViewHas('curricula', fn ($curricula) => $curricula->pluck('id')->all() === [
                $earlierCurriculum->id,
                $laterCurriculum->id,
                $ungradedCurriculum->id,
            ])
            ->assertSee('data-curricula-index-number', false)
            ->assertSee('data-curricula-index-name-table', false)
            ->assertSee('data-curricula-index-name', false)
            ->assertSeeInOrder(['>1</td>', '>2</td>', '>3</td>'], false);

        $css = file_get_contents(resource_path('css/app.css'));
        $javascript = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('text-align-last: justify;', $css);
        $this->assertStringContainsString('synchronizeCurriculaIndexNameWidths', $javascript);
    }

    public function test_groups_and_their_curriculum_picker_are_sorted_by_course_and_grade(): void
    {
        $this->seed(RoleSeeder::class);
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $this->actingAs($manager);

        $year = AcademicYear::create(['name' => '2027/2028', 'starts_on' => '2027-08-01', 'ends_on' => '2028-07-31', 'is_current' => true, 'is_active' => true]);
        $teacher = Teacher::create(['first_name' => 'Groups', 'last_name' => 'Teacher', 'phone' => '0944007010', 'status' => 'active', 'is_helping' => true]);
        $laterGrade = GradeLevel::create(['name' => 'Later grade', 'sort_order' => 20, 'is_active' => true]);
        $earlierGrade = GradeLevel::create(['name' => 'Earlier grade', 'sort_order' => 10, 'is_active' => true]);
        $alphaCourse = Course::create(['name' => 'Alpha course', 'is_active' => true]);
        $zuluCourse = Course::create(['name' => 'Zulu course', 'is_active' => true]);

        $laterCurriculum = Curriculum::create(['course_id' => $alphaCourse->id, 'grade_level_id' => $laterGrade->id, 'name' => 'A later curriculum', 'is_active' => true]);
        $ungradedCurriculum = Curriculum::create(['course_id' => null, 'grade_level_id' => null, 'name' => 'An ungraded curriculum', 'is_active' => true]);
        $earlierCurriculum = Curriculum::create(['course_id' => $alphaCourse->id, 'grade_level_id' => $earlierGrade->id, 'name' => 'Z earlier curriculum', 'is_active' => true]);

        $alphaLaterGroup = Group::create(['course_id' => $alphaCourse->id, 'academic_year_id' => $year->id, 'teacher_id' => $teacher->id, 'grade_level_id' => $laterGrade->id, 'name' => 'A alpha later', 'capacity' => 20, 'is_active' => true]);
        $zuluEarlierGroup = Group::create(['course_id' => $zuluCourse->id, 'academic_year_id' => $year->id, 'teacher_id' => $teacher->id, 'grade_level_id' => $earlierGrade->id, 'name' => 'A zulu earlier', 'capacity' => 20, 'is_active' => true]);
        $alphaEarlierGroup = Group::create(['course_id' => $alphaCourse->id, 'academic_year_id' => $year->id, 'teacher_id' => $teacher->id, 'grade_level_id' => $earlierGrade->id, 'curriculum_id' => $earlierCurriculum->id, 'name' => 'Z alpha earlier', 'capacity' => 20, 'is_active' => true]);

        $component = Volt::test('groups.index')
            ->assertViewHas('groups', fn ($groups) => $groups->pluck('id')->all() === [
                $alphaEarlierGroup->id,
                $alphaLaterGroup->id,
                $zuluEarlierGroup->id,
            ])
            ->assertViewHas('curricula', fn ($curricula) => $curricula->pluck('id')->all() === [
                $earlierCurriculum->id,
                $laterCurriculum->id,
                $ungradedCurriculum->id,
            ])
            ->assertSee('data-groups-curriculum-column="8"', false)
            ->assertSee('data-group-curriculum-status', false)
            ->assertSee('title="'.$earlierCurriculum->name.'"', false)
            ->assertSee(__('curricula.fields.curriculum'));

        $component
            ->set('courseFilter', (string) $alphaCourse->id)
            ->assertViewHas('groups', fn ($groups) => $groups->pluck('id')->all() === [
                $alphaEarlierGroup->id,
                $alphaLaterGroup->id,
            ]);

        Volt::test('groups.show', ['group' => $alphaEarlierGroup])
            ->call('openEdit')
            ->assertViewHas('curricula', fn ($curricula) => $curricula->pluck('id')->all() === [
                $earlierCurriculum->id,
                $laterCurriculum->id,
                $ungradedCurriculum->id,
            ]);

        $styles = file_get_contents(resource_path('css/app.css'));
        $groupsView = file_get_contents(resource_path('views/livewire/groups/index.blade.php'));
        $this->assertLessThan(
            strpos($groupsView, "{{ __('curricula.fields.curriculum') }}"),
            strpos($groupsView, "{{ __('crud.groups.table.headers.students') }}"),
        );
        $this->assertLessThan(
            strpos($groupsView, '<td class="px-5 py-4 text-center lg:px-6">'),
            strpos($groupsView, '<td class="px-5 py-4 text-white lg:px-6">{{ $group->enrollments_count }}</td>'),
        );
        $this->assertStringContainsString('margin-inline-end: 0;', $styles);
        $this->assertStringContainsString('.group-curriculum-status {', $styles);
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
            ->assertSee('curriculum-resource-table', false)
            ->call('openStandaloneResourceForm')
            ->assertSet('showStandaloneResourceForm', true)
            ->assertDontSee('wire:click="closeStandaloneResourceForm"', false)
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

        Volt::test('settings.curriculum-subjects')
            ->call('openStandaloneResources')
            ->assertDontSee('wire:click="deleteResource('.$resource->id.')"', false)
            ->call('editStandaloneResource', $resource->id)
            ->assertSet('showStandaloneResourceForm', true)
            ->assertSee('wire:click="deleteResource('.$resource->id.')"', false)
            ->call('deleteResource', $resource->id)
            ->assertSet('showStandaloneResourceForm', false);

        $this->assertSoftDeleted('curriculum_resources', ['id' => $resource->id]);
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
            ->call('openCustom')
            ->assertSet('showCustomModal', true)
            ->assertDontSee('wire:model="customPageCount"', false)
            ->set('customSubjectName', 'Community')
            ->set('customLessonName', 'Helping neighbours')
            ->set('customImportance', 1)
            ->set('customDate', '2026-08-11')
            ->set('customStatus', 'taught')
            ->call('saveCustom')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('group_custom_curriculum_lessons', ['group_id' => $group->id, 'subject_name' => 'Community', 'name' => 'Helping neighbours', 'page_count' => 0, 'status' => 'taught']);
    }

    public function test_teacher_book_rows_hide_and_restore_taught_lessons_in_their_original_places(): void
    {
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('teacher');
        [$course, $year, $grade] = $this->learningStructure(false);
        $teacher = Teacher::create(['user_id' => $user->id, 'first_name' => 'Ahmad', 'last_name' => 'Teacher', 'phone' => '0944007015', 'job_title' => 'مشرف حلقة', 'status' => 'active', 'is_helping' => true]);
        $curriculum = Curriculum::create(['course_id' => $course->id, 'grade_level_id' => $grade->id, 'name' => 'Teacher curriculum', 'is_active' => true]);
        $definition = CurriculumSubjectDefinition::create(['name' => 'Arabic', 'is_active' => true]);
        $resource = CurriculumResource::create(['subject_definition_id' => $definition->id, 'book_name' => 'Arabic book', 'is_active' => true]);
        $subject = CurriculumSubject::create(['curriculum_id' => $curriculum->id, 'subject_definition_id' => $definition->id]);
        $subject->resources()->attach($resource);
        $first = CurriculumLesson::create(['curriculum_subject_id' => $subject->id, 'curriculum_resource_id' => null, 'chapter_number' => '1', 'name' => 'First lesson', 'importance' => 1, 'sort_order' => 10]);
        $taught = CurriculumLesson::create(['curriculum_subject_id' => $subject->id, 'curriculum_resource_id' => $resource->id, 'chapter_number' => '2', 'name' => 'Middle taught lesson', 'importance' => 2, 'sort_order' => 20]);
        $third = CurriculumLesson::create(['curriculum_subject_id' => $subject->id, 'curriculum_resource_id' => $resource->id, 'chapter_number' => '3', 'name' => 'Third lesson', 'importance' => 3, 'sort_order' => 30]);
        $group = Group::create(['course_id' => $course->id, 'academic_year_id' => $year->id, 'teacher_id' => $teacher->id, 'grade_level_id' => $grade->id, 'curriculum_id' => $curriculum->id, 'name' => 'Arabic Group', 'capacity' => 20, 'is_active' => true]);
        GroupCurriculumLessonProgress::create(['group_id' => $group->id, 'curriculum_lesson_id' => $taught->id, 'teacher_id' => $teacher->id, 'status' => 'taught', 'taught_on' => '2026-08-14']);

        $this->actingAs($user);
        $lessonGroupKey = md5($subject->id.'|'.$resource->id);
        $component = Volt::test('curricula.index')
            ->set('selectedGroupId', (string) $group->id)
            ->assertSee('data-teacher-curriculum-hero-actions', false)
            ->assertSee('wire:click="openCustom"', false)
            ->assertSee('Arabic book')
            ->assertSeeInOrder(['data-teacher-curriculum-completion-column', 'data-teacher-curriculum-chapter-column'], false)
            ->assertSee('data-teacher-curriculum-table', false)
            ->assertSee('teacher-curriculum-table-scroll', false)
            ->assertSee(__('curricula.fields.chapter_number'))
            ->assertSee(__('curricula.fields.lesson'))
            ->assertSee(__('curricula.fields.importance'))
            ->assertSee('First lesson')
            ->assertSee('Third lesson')
            ->assertDontSee('Middle taught lesson')
            ->assertSee('data-teacher-taught-toggle', false)
            ->assertSee('data-icon-name="eye"', false);

        $component->call('toggleTaughtLessons', $lessonGroupKey)
            ->assertSet("showTaughtLessons.{$lessonGroupKey}", true)
            ->assertSeeInOrder(['First lesson', 'Middle taught lesson', 'Third lesson'])
            ->assertSee('14-08-2026')
            ->assertSee('Ahmad Teacher')
            ->assertSee('teacher-curriculum-lesson-title line-through', false)
            ->assertSee('data-icon-name="eye-off"', false);

        $component->call('toggleTaughtLessons', $lessonGroupKey)
            ->assertSet("showTaughtLessons.{$lessonGroupKey}", false)
            ->assertDontSee('Middle taught lesson')
            ->assertSee('data-icon-name="eye"', false);

        $this->assertSame('1', app(CurriculumProgressService::class)->subjects($group->fresh())->first()['lessons']->firstWhere('id', $first->id)['chapter_number']);
        $this->assertSame('3', app(CurriculumProgressService::class)->subjects($group->fresh())->first()['lessons']->firstWhere('id', $third->id)['chapter_number']);
        $this->assertSame('الفصل', __('curricula.fields.chapter_number', [], 'ar'));

        $css = file_get_contents(resource_path('css/app.css'));
        preg_match('/\.teacher-curriculum-lesson-title\s*\{(?<rules>[^}]*)\}/s', $css, $titleRules);
        $this->assertStringContainsString('white-space: nowrap;', $titleRules['rules'] ?? '');
        $this->assertStringNotContainsString('overflow: hidden;', $titleRules['rules'] ?? '');
        $this->assertStringNotContainsString('text-overflow: ellipsis;', $titleRules['rules'] ?? '');
    }

    public function test_lesson_topics_are_collapsible_and_support_parent_or_individual_completion(): void
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
        $component = Volt::test('curricula.index')
            ->set('selectedGroupId', (string) $group->id)
            ->assertSee('Parent lesson')
            ->assertSee('data-teacher-lesson-topics-checkbox', false)
            ->assertSee('data-teacher-curriculum-completion-column', false)
            ->assertSee('data-teacher-curriculum-no-chapter', false)
            ->assertDontSee('data-teacher-curriculum-chapter-column', false)
            ->assertSee('data-teacher-topic-toggle', false)
            ->assertSee('aria-expanded="false"', false)
            ->assertDontSee('data-teacher-topic-list', false)
            ->assertDontSee('First topic')
            ->call('toggleTopicLesson', $lesson->id)
            ->assertSet("expandedTopicLessons.{$lesson->id}", true)
            ->assertSee('aria-expanded="true"', false)
            ->assertSee('data-teacher-topic-list', false)
            ->assertSee('First topic')
            ->assertSee('Second topic');

        $lessonGroupKey = md5($subject->id.'|general');
        $component->set("showTaughtLessons.{$lessonGroupKey}", true)
            ->call('toggleLessonTopics', $lesson->id)
            ->assertHasNoErrors();
        $this->assertDatabaseHas('group_curriculum_topic_progresses', ['group_id' => $group->id, 'curriculum_lesson_topic_id' => $firstTopic->id]);
        $this->assertDatabaseHas('group_curriculum_topic_progresses', ['group_id' => $group->id, 'curriculum_lesson_topic_id' => $secondTopic->id]);
        $this->assertDatabaseHas('group_curriculum_lesson_progresses', ['group_id' => $group->id, 'curriculum_lesson_id' => $lesson->id, 'status' => 'taught']);
        $this->assertSame(100.0, app(CurriculumProgressService::class)->summary($group->fresh())['percentage']);
        $this->assertMatchesRegularExpression('/<input(?=[^>]*data-teacher-lesson-topics-checkbox)(?=[^>]*\bchecked\b)[^>]*>/', $component->html());
        $this->assertSame(2, preg_match_all('/<input(?=[^>]*data-teacher-topic-checkbox)(?=[^>]*\bchecked\b)[^>]*>/', $component->html()));

        $component->call('toggleLessonTopics', $lesson->id)->assertHasNoErrors();
        $this->assertDatabaseMissing('group_curriculum_topic_progresses', ['group_id' => $group->id, 'curriculum_lesson_topic_id' => $firstTopic->id]);
        $this->assertDatabaseMissing('group_curriculum_topic_progresses', ['group_id' => $group->id, 'curriculum_lesson_topic_id' => $secondTopic->id]);
        $this->assertDatabaseMissing('group_curriculum_lesson_progresses', ['group_id' => $group->id, 'curriculum_lesson_id' => $lesson->id]);

        $component->call('toggleTopic', $firstTopic->id)->assertHasNoErrors();
        $this->assertSame(0.0, app(CurriculumProgressService::class)->summary($group->fresh())['percentage']);
        $this->assertDoesNotMatchRegularExpression('/<input(?=[^>]*data-teacher-lesson-topics-checkbox)(?=[^>]*\bchecked\b)[^>]*>/', $component->html());

        $component->call('toggleTopic', $secondTopic->id)->assertHasNoErrors();
        $this->assertSame(100.0, app(CurriculumProgressService::class)->summary($group->fresh())['percentage']);
        $this->assertDatabaseHas('group_curriculum_lesson_progresses', ['group_id' => $group->id, 'curriculum_lesson_id' => $lesson->id, 'status' => 'taught']);
        $this->assertMatchesRegularExpression('/<input(?=[^>]*data-teacher-lesson-topics-checkbox)(?=[^>]*\bchecked\b)[^>]*>/', $component->html());
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
