<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AppSetting;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\AssessmentType;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\MemorizationSession;
use App\Models\MemorizationSessionPage;
use App\Models\ParentProfile;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\QuranFinalTest;
use App\Models\QuranFinalTestAttempt;
use App\Models\QuranJuz;
use App\Models\QuranTest;
use App\Models\QuranTestType;
use App\Models\Student;
use App\Models\StudentNote;
use App\Models\Teacher;
use App\Models\User;
use App\Support\AvatarDefaults;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class StudentProgressPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_can_view_only_their_child_progress(): void
    {
        $this->seed(RoleSeeder::class);

        [$parentUser, $ownStudent, $otherStudent] = $this->makeScopedProgressData();

        $this->actingAs($parentUser);

        $this->get(route('students.progress', $ownStudent, absolute: false))
            ->assertOk()
            ->assertSee('student-progress-profile__photo', false)
            ->assertSeeText('Parent Student')
            ->assertSeeText('Parent Group')
            ->assertSeeText('Weekly Quiz')
            ->assertSeeText('88.00')
            ->assertSeeText('581')
            ->assertSeeText('582')
            ->assertSeeText('583')
            ->assertSeeText('Teacher Shared Note')
            ->assertDontSeeText('581, 582, 583')
            ->assertDontSeeText('Other Student')
            ->assertDontSeeText('Other Group')
            ->assertDontSeeText('Hidden Quiz')
            ->assertDontSeeText('Other Shared Note');

        $this->get(route('students.progress', $otherStudent, absolute: false))
            ->assertForbidden();
    }

    public function test_progress_photo_uses_the_student_default_and_stays_inside_a_fixed_square_frame(): void
    {
        $this->seed(RoleSeeder::class);

        [$parentUser, $student] = $this->makeScopedProgressData();
        $this->actingAs($parentUser);

        AppSetting::storeValue('media', 'default_student_avatar_path', 'defaults/student.png');
        AvatarDefaults::forget();

        try {
            $this->get(route('students.progress', $student, absolute: false))
                ->assertOk()
                ->assertSee('src="/storage/defaults/student.png"', false)
                ->assertSee('student-progress-profile__photo-image', false)
                ->assertDontSee('data-student-progress-photo-upload', false)
                ->assertDontSee('student-progress-profile__photo-fallback', false);

            $student->update(['photo_path' => 'students/photos/wide-landscape-photo.jpg']);

            $this->get(route('students.progress', $student, absolute: false))
                ->assertOk()
                ->assertSee('student-progress-profile__photo-image', false)
                ->assertDontSee('student-progress-profile__photo-fallback', false);
        } finally {
            AvatarDefaults::forget();
        }

        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('grid-template-columns: 9.5rem minmax(0, 1fr);', $css);
        $this->assertStringContainsString('height: 9.5rem;', $css);
        $this->assertStringContainsString('aspect-ratio: 1;', $css);
        $this->assertStringContainsString('align-self: start;', $css);
        $this->assertStringContainsString('object-fit: cover;', $css);
        $this->assertStringContainsString('contain: paint;', $css);
    }

    public function test_manager_can_replace_a_student_photo_from_the_progress_profile(): void
    {
        $this->seed(RoleSeeder::class);
        Storage::fake('public');

        [, $student] = $this->makeScopedProgressData();
        $manager = User::factory()->create([
            'username' => 'progress-photo-manager',
            'phone' => '8111004',
        ]);
        $manager->assignRole('manager');
        $this->actingAs($manager);

        Volt::test('students.progress', ['student' => $student])
            ->assertSee('data-student-progress-photo-upload', false)
            ->set('progressPhotoUpload', UploadedFile::fake()->image('new-student-photo.jpg', 640, 640))
            ->assertHasNoErrors()
            ->assertSeeText(__('workflow.student_progress.messages.photo_updated'));

        $photoPath = $student->fresh()->photo_path;

        $this->assertNotNull($photoPath);
        Storage::disk('public')->assertExists($photoPath);
    }

    public function test_student_can_view_only_their_own_progress(): void
    {
        $this->seed(RoleSeeder::class);

        [$studentUser, $ownStudent, $otherStudent] = $this->makeStudentScopedProgressData();

        $this->actingAs($studentUser);

        $this->get(route('students.progress', $ownStudent, absolute: false))
            ->assertOk()
            ->assertSeeText('Scoped Student')
            ->assertSeeText('Student Scope Group')
            ->assertSeeText('Monthly Quiz')
            ->assertSeeText('91.00')
            ->assertDontSeeText('Other Student')
            ->assertDontSeeText('Other Scope Group');

        $this->get(route('students.progress', $otherStudent, absolute: false))
            ->assertForbidden();
    }

    public function test_progress_labels_external_memorization_and_shows_student_phone(): void
    {
        $this->seed(RoleSeeder::class);
        [$parentUser, $student] = $this->makeScopedProgressData();
        $juz = QuranJuz::query()->firstOrFail();
        $student->externalMemorizedJuzs()->sync([$juz->id]);
        $studentUser = User::factory()->create(['phone' => '0999888777']);
        $student->update(['user_id' => $studentUser->id]);
        $this->actingAs($parentUser);

        app()->setLocale('en');
        $this->get(route('students.progress', $student, absolute: false))
            ->assertOk()
            ->assertSeeText('Old memorisation')
            ->assertSeeText('Student phone')
            ->assertSeeText('+963 999 888 777');

        app()->setLocale('ar');
        $this->get(route('students.progress', $student, absolute: false))
            ->assertOk()
            ->assertSeeText('حفظ قديم');
    }

    public function test_assessments_box_excludes_final_exam_results(): void
    {
        $this->seed(RoleSeeder::class);
        [$parentUser, $student] = $this->makeScopedProgressData();
        $enrollment = Enrollment::query()->where('student_id', $student->id)->firstOrFail();
        $teacher = $enrollment->group->teacher;
        $finalType = AssessmentType::create([
            'name' => 'Final Exam',
            'code' => 'final_exam',
            'is_scored' => true,
            'is_active' => true,
        ]);
        $finalAssessment = Assessment::create([
            'group_id' => $enrollment->group_id,
            'assessment_type_id' => $finalType->id,
            'title' => 'Course Final Exam',
            'total_mark' => 100,
            'pass_mark' => 60,
            'is_active' => true,
        ]);
        $finalResult = AssessmentResult::create([
            'assessment_id' => $finalAssessment->id,
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'score' => 86,
            'status' => 'passed',
            'attempt_no' => 1,
        ]);

        $this->actingAs($parentUser);

        $component = Volt::test('students.progress', ['student' => $student])
            ->assertViewHas('assessmentResults', fn ($results) => $results->doesntContain('id', $finalResult->id))
            ->assertViewHas('finalAssessmentResults', fn ($results) => $results->contains('id', $finalResult->id))
            ->call('showDetails', 'final-assessments')
            ->assertSee('data-student-progress-generic-table', false)
            ->assertSee('w-[65%]', false)
            ->assertSee('w-28 min-w-28', false)
            ->assertSeeText('Course Final Exam')
            ->assertDontSeeText('Course Final Exam · Quran Track');

        $component
            ->call('showDetails', 'assessments')
            ->assertSeeText('Weekly Quiz')
            ->assertDontSeeText('Weekly Quiz · Quran Track');
    }

    public function test_student_progress_limits_highlights_to_default_course_but_keeps_history_general(): void
    {
        $this->seed(RoleSeeder::class);

        [$parentUser, $ownStudent] = $this->makeScopedProgressData();

        $this->actingAs($parentUser);

        $secondaryTeacher = Teacher::create([
            'first_name' => 'Huda',
            'last_name' => 'Teacher',
            'phone' => '0999000003',
            'status' => 'active',
        ]);

        $secondaryCourse = Course::create([
            'name' => 'Revision Track',
            'is_active' => true,
        ]);

        $academicYear = AcademicYear::query()->where('is_current', true)->firstOrFail();

        $secondaryGroup = Group::create([
            'course_id' => $secondaryCourse->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $secondaryTeacher->id,
            'name' => 'Parent Secondary Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $secondaryEnrollment = Enrollment::create([
            'student_id' => $ownStudent->id,
            'group_id' => $secondaryGroup->id,
            'enrolled_at' => '2026-10-01',
            'status' => 'active',
            'final_points_cached' => 20,
            'memorized_pages_cached' => 9,
        ]);

        $secondaryQuizType = AssessmentType::create([
            'name' => 'Review Quiz',
            'code' => 'review_quiz',
            'is_scored' => true,
            'is_active' => true,
        ]);

        $secondaryAssessment = Assessment::create([
            'group_id' => $secondaryGroup->id,
            'assessment_type_id' => $secondaryQuizType->id,
            'title' => 'Course Filter Quiz',
            'total_mark' => 100,
            'pass_mark' => 50,
            'is_active' => true,
        ]);

        AssessmentResult::create([
            'assessment_id' => $secondaryAssessment->id,
            'enrollment_id' => $secondaryEnrollment->id,
            'student_id' => $ownStudent->id,
            'teacher_id' => $secondaryTeacher->id,
            'score' => 73,
            'status' => 'passed',
            'attempt_no' => 1,
        ]);

        $primaryPointType = PointType::query()->where('code', 'quiz_reward')->firstOrFail();
        PointTransaction::create([
            'student_id' => $ownStudent->id,
            'enrollment_id' => Enrollment::query()->where('student_id', $ownStudent->id)->whereHas('group', fn ($query) => $query->where('name', 'Parent Group'))->firstOrFail()->id,
            'point_type_id' => $primaryPointType->id,
            'source_type' => 'manual',
            'points' => 4,
            'entered_by' => $parentUser->id,
            'entered_at' => now()->addMinute(),
            'notes' => 'Second primary points',
        ]);

        $secondaryPointType = PointType::create([
            'name' => 'Secondary Bonus',
            'code' => 'secondary_bonus',
            'category' => 'bonus',
            'default_points' => 3,
            'allow_manual_entry' => true,
            'allow_negative' => false,
            'is_active' => true,
        ]);

        PointTransaction::create([
            'student_id' => $ownStudent->id,
            'enrollment_id' => $secondaryEnrollment->id,
            'point_type_id' => $secondaryPointType->id,
            'source_type' => 'manual',
            'points' => 11,
            'entered_by' => $parentUser->id,
            'entered_at' => now()->addMinutes(2),
            'notes' => 'Secondary course points',
        ]);

        StudentNote::create([
            'student_id' => $ownStudent->id,
            'enrollment_id' => $secondaryEnrollment->id,
            'author_id' => $parentUser->id,
            'source' => 'teacher',
            'visibility' => 'visible_to_parent',
            'body' => 'Second Course Note',
            'noted_at' => now(),
        ]);

        Volt::test('students.progress', ['student' => $ownStudent])
            ->assertViewHas('stats', fn (array $stats) => $stats['points'] === 12)
            ->assertSeeText('Parent Group')
            ->assertSeeText('Quiz Reward')
            ->assertSeeText('Parent Secondary Group')
            ->assertSeeText('Course Filter Quiz')
            ->assertDontSeeText('Secondary Bonus')
            ->assertSeeText('Second Course Note');

        $parentDetails = Volt::test('students.progress', ['student' => $ownStudent])
            ->assertDontSeeText(__('workflow.student_progress.selection.change_student'))
            ->call('showDetails', 'parent')
            ->assertSeeText('+963 999 555 111')
            ->assertSeeText('Scoped Mother')
            ->assertSeeText('Scoped Address');

        $this->assertSame(3, substr_count($parentDetails->html(), 'student-parent-details__row'));
    }

    public function test_progress_page_without_route_student_shows_selector_for_manager_scope(): void
    {
        $this->seed(RoleSeeder::class);

        $this->makeScopedProgressData();

        $manager = User::factory()->create([
            'username' => 'student-progress-manager',
            'phone' => '8111003',
        ]);
        $manager->assignRole('manager');

        $this->actingAs($manager);

        $this->get(route('students.progress', absolute: false))
            ->assertOk()
            ->assertDontSeeText(__('workflow.student_progress.selection.title'))
            ->assertDontSeeText(__('workflow.student_progress.selection.select_student'))
            ->assertSeeText(__('workflow.student_progress.selection.search_placeholder'))
            ->assertSee('data-search-input="true"', false)
            ->assertSee('data-open-on-focus="true"', false)
            ->assertSee('data-hide-placeholder-option="true"', false)
            ->assertSeeText('Parent Student')
            ->assertSeeText('Other Student')
            ->assertSee('data-option-name="Parent Student"', false)
            ->assertSeeText('اختر طالباً من الأعلى لعرض صفحة التقدم الكاملة.');

        $searchableSelectScript = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("const searchInputMode = select.dataset.searchInput !== 'false'", $searchableSelectScript);
        $this->assertStringContainsString("const openOnFocus = searchInputMode && select.dataset.openOnFocus !== 'false'", $searchableSelectScript);
        $this->assertStringContainsString("const clearable = searchInputMode && select.dataset.clearable !== 'false'", $searchableSelectScript);
        $this->assertStringContainsString("'searchPlaceholder' in select.dataset", $searchableSelectScript);
        $this->assertStringContainsString("select.classList.contains('finance-amount-input__currency')", $searchableSelectScript);
        $this->assertStringContainsString("searchable-select__chevron--input", $searchableSelectScript);
        $this->assertStringContainsString('function createSearchableSelectChevron(inputMode = false)', $searchableSelectScript);
        $this->assertStringContainsString('stroke-linecap="round" stroke-linejoin="round"', $searchableSelectScript);
        $this->assertStringContainsString('function searchableSelectPlaceholderOption(select)', $searchableSelectScript);
        $this->assertStringContainsString("const SEARCHABLE_SELECT_BINDING_VERSION = '8'", $searchableSelectScript);
        $this->assertStringContainsString("options.find((option) => option.value === 'all')", $searchableSelectScript);
        $this->assertStringContainsString("searchableSelectBinding(select).includes('filter')", $searchableSelectScript);
        $this->assertStringContainsString("hasSelectedValue || search.value.trim() !== ''", $searchableSelectScript);
        $this->assertStringContainsString('select.value = searchableSelectPlaceholderValue(select)', $searchableSelectScript);
        $this->assertStringContainsString("'searchable-select--placeholder'", $searchableSelectScript);
        $this->assertStringContainsString("clear.className = 'searchable-select__clear'", $searchableSelectScript);
        $this->assertStringContainsString('function restoreSearchableSelectClear(clear)', $searchableSelectScript);
        $this->assertStringContainsString("clear.dataset.modalActionIconIgnore = 'true'", $searchableSelectScript);
        $this->assertStringContainsString("clear.addEventListener('click'", $searchableSelectScript);
        $this->assertStringContainsString("clear.addEventListener('pointerdown'", $searchableSelectScript);
        $this->assertStringContainsString('suppressSearchableSelectOpen();', $searchableSelectScript);
        $this->assertStringContainsString('searchableSelectOpenIsSuppressed()', $searchableSelectScript);
        $this->assertStringContainsString('clear.blur();', $searchableSelectScript);
        $this->assertStringContainsString("search.addEventListener('focus'", $searchableSelectScript);
        $this->assertStringContainsString("buildSearchableSelectOptions(select, list, '')", $searchableSelectScript);

        $searchableSelectCss = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('.searchable-select--input.searchable-select--clearable.searchable-select--selected .searchable-select__chevron--input {', $searchableSelectCss);
        $this->assertStringContainsString('.searchable-select--input.searchable-select--clearable.searchable-select--selected .searchable-select__clear {', $searchableSelectCss);
        $this->assertStringContainsString('.searchable-select--input.searchable-select--placeholder .searchable-select__clear {', $searchableSelectCss);
        $this->assertStringContainsString('.searchable-select--input.searchable-select--clearable:has(.searchable-select__search--trigger:not(:placeholder-shown)) .searchable-select__clear {', $searchableSelectCss);
        $this->assertStringContainsString('.searchable-select__chevron--input,', $searchableSelectCss);
        $this->assertStringContainsString('.searchable-select__chevron svg {', $searchableSelectCss);
        $this->assertStringContainsString('align-items: center;', $searchableSelectCss);
        $this->assertStringContainsString('display: none;', $searchableSelectCss);
    }

    public function test_progress_enrollments_only_show_active_and_completed_rows(): void
    {
        $this->seed(RoleSeeder::class);

        [$parentUser, $student] = $this->makeScopedProgressData();
        $activeEnrollment = Enrollment::query()->where('student_id', $student->id)->firstOrFail();
        $teacher = $activeEnrollment->group->teacher;
        $academicYear = $activeEnrollment->group->academicYear;
        $course = $activeEnrollment->group->course;

        $completedGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Completed Progress Group',
            'capacity' => 12,
            'is_active' => false,
        ]);
        $cancelledGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Cancelled Progress Group',
            'capacity' => 12,
            'is_active' => false,
        ]);

        Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $completedGroup->id,
            'enrolled_at' => '2026-08-01',
            'left_at' => '2026-08-31',
            'status' => 'completed',
        ]);
        Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $cancelledGroup->id,
            'enrolled_at' => '2026-07-01',
            'left_at' => '2026-07-02',
            'status' => 'cancelled',
        ]);

        $this->actingAs($parentUser);

        Volt::test('students.progress', ['student' => $student])
            ->assertViewHas('enrollments', fn ($enrollments) => $enrollments->pluck('status')->sort()->values()->all() === ['active', 'completed'])
            ->assertSeeText('Completed Progress Group')
            ->assertDontSeeText('Cancelled Progress Group')
            ->call('showDetails', 'enrollments')
            ->assertSeeText('Completed Progress Group')
            ->assertDontSeeText('Cancelled Progress Group');
    }

    public function test_juz_is_only_finished_after_the_final_test_is_passed(): void
    {
        $this->seed(RoleSeeder::class);
        [, $student] = $this->makeScopedProgressData();

        $manager = User::factory()->create(['username' => 'progress-final-manager', 'phone' => '8111004']);
        $manager->assignRole('manager');
        $this->actingAs($manager);

        $enrollment = Enrollment::query()->where('student_id', $student->id)->firstOrFail();
        $juz = QuranJuz::query()->firstOrFail();
        $teacher = Teacher::query()->firstOrFail();
        $finalTest = QuranFinalTest::create([
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'juz_id' => $juz->id,
            'status' => 'in_progress',
            'created_by' => $manager->id,
        ]);
        QuranFinalTestAttempt::create([
            'quran_final_test_id' => $finalTest->id,
            'teacher_id' => $teacher->id,
            'tested_on' => '2026-09-15',
            'score' => 50,
            'status' => 'failed',
            'attempt_no' => 1,
        ]);

        $component = Volt::test('students.progress', ['student' => $student])
            ->assertViewHas('quranJuzProgress', fn ($rows) => $rows->first()?->status === 'missing')
            ->assertSeeText(__('workflow.student_progress.juz_progress.show_missing'))
            ->assertDontSee('wire:click="openAwqafTest(', false);

        $finalTest->update(['status' => 'passed', 'passed_on' => '2026-09-16']);
        $finalTest->attempts()->firstOrFail()->update(['status' => 'passed']);
        $enrollment->update(['status' => 'completed', 'left_at' => '2026-09-15']);

        $component
            ->call('$refresh')
            ->assertSee('wire:click="openAwqafTest('.$juz->id.')" class="pill-link pill-link--compact"', false)
            ->call('openAwqafTest', $juz->id)
            ->assertHasNoErrors()
            ->assertSet('showAwqafTestModal', false)
            ->assertSet('showAwqafUnavailableModal', true)
            ->assertSee('data-awqaf-unavailable-warning', false)
            ->assertSeeText(__('workflow.student_progress.juz_progress.awqaf_unavailable'))
            ->assertDontSee('admin-modal__header', false)
            ->assertSee('wire:click="closeAwqafUnavailable"', false)
            ->call('closeAwqafUnavailable')
            ->assertSet('showAwqafUnavailableModal', false);

        $currentCourse = Course::create([
            'name' => 'Current Awqaf Course',
            'is_active' => true,
        ]);
        $currentGroup = Group::create([
            'course_id' => $currentCourse->id,
            'academic_year_id' => $enrollment->group->academic_year_id,
            'teacher_id' => $teacher->id,
            'name' => 'Current Awqaf Group',
            'capacity' => 12,
            'is_active' => true,
        ]);
        $currentEnrollment = Enrollment::create([
            'student_id' => $student->id,
            'group_id' => $currentGroup->id,
            'enrolled_at' => '2026-09-16',
            'status' => 'active',
        ]);

        $component
            ->call('$refresh')
            ->assertViewHas('quranJuzProgress', fn ($rows) => $rows->first()?->status === 'finished')
            ->assertDontSeeText(__('workflow.student_progress.juz_progress.show_missing'))
            ->assertSee('wire:click="openAwqafTest('.$juz->id.')" class="pill-link pill-link--compact"', false)
            ->call('openAwqafTest', $juz->id)
            ->assertSet('showAwqafTestModal', true)
            ->assertDontSee('wire:click="closeAwqafTest" class="pill-link"', false)
            ->call('saveAwqafTest')
            ->assertHasErrors(['awqafScore' => 'required_if'])
            ->set('awqafTestedOn', '2026-09-16')
            ->set('awqafScore', '60')
            ->set('awqafStatus', 'failed')
            ->call('saveAwqafTest')
            ->assertHasNoErrors()
            ->assertSet('showAwqafTestModal', false)
            ->assertSee('wire:click="openAwqafTest('.$juz->id.')" class="pill-link pill-link--compact"', false)
            ->call('openAwqafTest', $juz->id)
            ->set('awqafTestedOn', '2026-09-17')
            ->set('awqafScore', '88')
            ->set('awqafStatus', 'passed')
            ->call('saveAwqafTest')
            ->assertHasNoErrors()
            ->assertDontSee('wire:click="openAwqafTest(', false);

        $this->assertDatabaseHas('quran_tests', [
            'enrollment_id' => $currentEnrollment->id,
            'juz_id' => $juz->id,
            'score' => 88,
            'status' => 'passed',
            'attempt_no' => 2,
        ]);
    }

    private function makeScopedProgressData(): array
    {
        $parentUser = User::factory()->create([
            'username' => 'parent-progress',
            'phone' => '8111001',
        ]);
        $parentUser->assignRole('parent');

        $parent = ParentProfile::create([
            'user_id' => $parentUser->id,
            'father_name' => 'Scoped Parent',
            'father_phone' => '0999555111',
            'father_work' => 'Engineer',
            'mother_name' => 'Scoped Mother',
            'mother_phone' => '0999555222',
            'address' => 'Scoped Address',
            'home_phone' => '0115555555',
            'is_active' => true,
        ]);

        $otherParent = ParentProfile::create([
            'father_name' => 'Other Parent',
            'is_active' => true,
        ]);

        $teacher = Teacher::create([
            'first_name' => 'Salim',
            'last_name' => 'Teacher',
            'phone' => '0999000001',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Quran Track',
            'is_active' => true,
            'is_default' => true,
        ]);

        $academicYear = AcademicYear::create([
            'name' => '2026/2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);

        $ownGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Parent Group',
            'capacity' => 12,
            'is_active' => true,
        ]);

        $otherGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Other Group',
            'capacity' => 12,
            'is_active' => true,
        ]);

        $juz = QuranJuz::create([
            'juz_number' => 1,
            'from_page' => 1,
            'to_page' => 20,
        ]);

        $quizType = AssessmentType::create([
            'name' => 'Quiz',
            'code' => 'quiz',
            'is_scored' => true,
            'is_active' => true,
        ]);

        $partialType = QuranTestType::create([
            'name' => 'Partial',
            'code' => 'partial',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        QuranTestType::create([
            'name' => 'Awqaf',
            'code' => 'awqaf',
            'sort_order' => 3,
            'is_active' => true,
        ]);

        $pointType = PointType::create([
            'name' => 'Quiz Reward',
            'code' => 'quiz_reward',
            'category' => 'bonus',
            'default_points' => 5,
            'allow_manual_entry' => true,
            'allow_negative' => false,
            'is_active' => true,
        ]);

        $ownStudent = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Parent',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'quran_current_juz_id' => $juz->id,
            'status' => 'active',
        ]);

        $otherStudent = Student::create([
            'parent_id' => $otherParent->id,
            'first_name' => 'Other',
            'last_name' => 'Student',
            'birth_date' => '2014-05-13',
            'quran_current_juz_id' => $juz->id,
            'status' => 'active',
        ]);

        $ownEnrollment = Enrollment::create([
            'student_id' => $ownStudent->id,
            'group_id' => $ownGroup->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
            'final_points_cached' => 12,
            'memorized_pages_cached' => 6,
        ]);

        $otherEnrollment = Enrollment::create([
            'student_id' => $otherStudent->id,
            'group_id' => $otherGroup->id,
            'enrolled_at' => '2026-09-02',
            'status' => 'active',
            'final_points_cached' => 9,
            'memorized_pages_cached' => 4,
        ]);

        $ownAssessment = Assessment::create([
            'group_id' => $ownGroup->id,
            'assessment_type_id' => $quizType->id,
            'title' => 'Weekly Quiz',
            'total_mark' => 100,
            'pass_mark' => 50,
            'is_active' => true,
        ]);

        $otherAssessment = Assessment::create([
            'group_id' => $otherGroup->id,
            'assessment_type_id' => $quizType->id,
            'title' => 'Hidden Quiz',
            'total_mark' => 100,
            'pass_mark' => 50,
            'is_active' => true,
        ]);

        AssessmentResult::create([
            'assessment_id' => $ownAssessment->id,
            'enrollment_id' => $ownEnrollment->id,
            'student_id' => $ownStudent->id,
            'teacher_id' => $teacher->id,
            'score' => 88,
            'status' => 'passed',
            'attempt_no' => 1,
        ]);

        AssessmentResult::create([
            'assessment_id' => $otherAssessment->id,
            'enrollment_id' => $otherEnrollment->id,
            'student_id' => $otherStudent->id,
            'teacher_id' => $teacher->id,
            'score' => 77,
            'status' => 'passed',
            'attempt_no' => 1,
        ]);

        $ownMemorizationSession = MemorizationSession::create([
            'enrollment_id' => $ownEnrollment->id,
            'student_id' => $ownStudent->id,
            'teacher_id' => $teacher->id,
            'recorded_on' => '2026-09-10',
            'entry_type' => 'new',
            'from_page' => 581,
            'to_page' => 583,
            'pages_count' => 3,
        ]);

        MemorizationSessionPage::insert([
            ['memorization_session_id' => $ownMemorizationSession->id, 'page_no' => 581],
            ['memorization_session_id' => $ownMemorizationSession->id, 'page_no' => 582],
            ['memorization_session_id' => $ownMemorizationSession->id, 'page_no' => 583],
        ]);

        $otherMemorizationSession = MemorizationSession::create([
            'enrollment_id' => $otherEnrollment->id,
            'student_id' => $otherStudent->id,
            'teacher_id' => $teacher->id,
            'recorded_on' => '2026-09-11',
            'entry_type' => 'new',
            'from_page' => 584,
            'to_page' => 585,
            'pages_count' => 2,
        ]);

        MemorizationSessionPage::insert([
            ['memorization_session_id' => $otherMemorizationSession->id, 'page_no' => 584],
            ['memorization_session_id' => $otherMemorizationSession->id, 'page_no' => 585],
        ]);

        QuranTest::create([
            'enrollment_id' => $ownEnrollment->id,
            'student_id' => $ownStudent->id,
            'teacher_id' => $teacher->id,
            'juz_id' => $juz->id,
            'quran_test_type_id' => $partialType->id,
            'tested_on' => '2026-09-12',
            'score' => 92,
            'status' => 'passed',
            'attempt_no' => 1,
        ]);

        QuranTest::create([
            'enrollment_id' => $otherEnrollment->id,
            'student_id' => $otherStudent->id,
            'teacher_id' => $teacher->id,
            'juz_id' => $juz->id,
            'quran_test_type_id' => $partialType->id,
            'tested_on' => '2026-09-13',
            'score' => 80,
            'status' => 'passed',
            'attempt_no' => 1,
        ]);

        PointTransaction::create([
            'student_id' => $ownStudent->id,
            'enrollment_id' => $ownEnrollment->id,
            'point_type_id' => $pointType->id,
            'source_type' => 'manual',
            'points' => 6,
            'entered_by' => $parentUser->id,
            'entered_at' => now(),
            'notes' => 'Parent visible points',
        ]);

        PointTransaction::create([
            'student_id' => $otherStudent->id,
            'enrollment_id' => $otherEnrollment->id,
            'point_type_id' => $pointType->id,
            'source_type' => 'manual',
            'points' => 4,
            'entered_by' => $parentUser->id,
            'entered_at' => now(),
            'notes' => 'Hidden points',
        ]);

        StudentNote::create([
            'student_id' => $ownStudent->id,
            'enrollment_id' => $ownEnrollment->id,
            'author_id' => $parentUser->id,
            'source' => 'teacher',
            'visibility' => 'visible_to_parent',
            'body' => 'Teacher Shared Note',
            'noted_at' => now(),
        ]);

        StudentNote::create([
            'student_id' => $otherStudent->id,
            'enrollment_id' => $otherEnrollment->id,
            'author_id' => $parentUser->id,
            'source' => 'teacher',
            'visibility' => 'visible_to_parent',
            'body' => 'Other Shared Note',
            'noted_at' => now(),
        ]);

        return [$parentUser, $ownStudent, $otherStudent];
    }

    private function makeStudentScopedProgressData(): array
    {
        $studentUser = User::factory()->create([
            'username' => 'student-progress',
            'phone' => '8111002',
        ]);
        $studentUser->assignRole('student');

        $parent = ParentProfile::create([
            'father_name' => 'Student Parent',
            'is_active' => true,
        ]);

        $teacher = Teacher::create([
            'first_name' => 'Alaa',
            'last_name' => 'Teacher',
            'phone' => '0999000002',
            'status' => 'active',
        ]);

        $course = Course::create([
            'name' => 'Revision Track',
            'is_active' => true,
            'is_default' => true,
        ]);

        $academicYear = AcademicYear::create([
            'name' => '2026/2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
            'is_active' => true,
        ]);

        $ownGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Student Scope Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $otherGroup = Group::create([
            'course_id' => $course->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Other Scope Group',
            'capacity' => 10,
            'is_active' => true,
        ]);

        $juz = QuranJuz::create([
            'juz_number' => 2,
            'from_page' => 21,
            'to_page' => 40,
        ]);

        $quizType = AssessmentType::create([
            'name' => 'Monthly Quiz',
            'code' => 'monthly_quiz',
            'is_scored' => true,
            'is_active' => true,
        ]);

        $ownStudent = Student::create([
            'user_id' => $studentUser->id,
            'parent_id' => $parent->id,
            'first_name' => 'Scoped',
            'last_name' => 'Student',
            'birth_date' => '2014-05-12',
            'quran_current_juz_id' => $juz->id,
            'status' => 'active',
        ]);

        $otherStudent = Student::create([
            'parent_id' => $parent->id,
            'first_name' => 'Other',
            'last_name' => 'Student',
            'birth_date' => '2014-05-13',
            'quran_current_juz_id' => $juz->id,
            'status' => 'active',
        ]);

        $ownEnrollment = Enrollment::create([
            'student_id' => $ownStudent->id,
            'group_id' => $ownGroup->id,
            'enrolled_at' => '2026-09-01',
            'status' => 'active',
            'final_points_cached' => 15,
            'memorized_pages_cached' => 7,
        ]);

        $otherEnrollment = Enrollment::create([
            'student_id' => $otherStudent->id,
            'group_id' => $otherGroup->id,
            'enrolled_at' => '2026-09-02',
            'status' => 'active',
        ]);

        $assessment = Assessment::create([
            'group_id' => $ownGroup->id,
            'assessment_type_id' => $quizType->id,
            'title' => 'Monthly Quiz',
            'total_mark' => 100,
            'pass_mark' => 60,
            'is_active' => true,
        ]);

        AssessmentResult::create([
            'assessment_id' => $assessment->id,
            'enrollment_id' => $ownEnrollment->id,
            'student_id' => $ownStudent->id,
            'teacher_id' => $teacher->id,
            'score' => 91,
            'status' => 'passed',
            'attempt_no' => 1,
        ]);

        return [$studentUser, $ownStudent, $otherStudent];
    }
}
