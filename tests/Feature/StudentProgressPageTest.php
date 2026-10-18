<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AppSetting;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\AssessmentType;
use App\Models\AttendanceStatus;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\MemorizationSession;
use App\Models\MemorizationSessionPage;
use App\Models\ParentProfile;
use App\Models\PointTransaction;
use App\Models\PointType;
use App\Models\QuranFinalTest;
use App\Models\QuranFinalTestAttempt;
use App\Models\QuranJuz;
use App\Models\QuranPartialTest;
use App\Models\QuranTest;
use App\Models\QuranTestType;
use App\Models\Student;
use App\Models\StudentAttendanceRecord;
use App\Models\StudentNote;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AccessScopeService;
use App\Services\CourseCompletionRuleService;
use App\Services\CourseEndService;
use App\Services\CourseLifecycleService;
use App\Services\QuranPartialTestService;
use App\Support\AvatarDefaults;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class StudentProgressPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_tables_have_number_columns_and_quran_progress_has_a_number_width_spacer(): void
    {
        $source = file_get_contents(resource_path('views/livewire/students/progress.blade.php'));

        $this->assertSame(12, substr_count($source, 'data-student-progress-number-column'));
        $this->assertSame(12, substr_count($source, 'data-student-progress-row-number'));
        $this->assertMatchesRegularExpression(
            '/data-student-progress-juz-table><thead><tr>\s*<th[^>]*data-juz-progress-spacer[^>]*><\/th>\s*<th[^>]*>\{\{ __\(\'workflow\.student_progress\.juz_progress\.headers\.juz\'\) \}\}<\/th>/',
            $source,
        );

        $styles = file_get_contents(resource_path('css/app.css'));
        $this->assertMatchesRegularExpression('/\[data-juz-progress-spacer\]\s*\{[^}]*width:\s*0\.81rem;[^}]*min-width:\s*0\.81rem;[^}]*max-width:\s*0\.81rem;/s', $styles);

        $script = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("cell.matches('[data-table-number-column], [data-juz-progress-spacer]')", $script);
    }

    public function test_quran_progress_uses_ten_rows_and_header_only_arrow_pagination(): void
    {
        $this->seed(RoleSeeder::class);
        [$parentUser, $student] = $this->makeScopedProgressData();
        foreach (range(2, 12) as $juzNumber) {
            QuranJuz::create([
                'juz_number' => $juzNumber,
                'from_page' => (($juzNumber - 1) * 20) + 1,
                'to_page' => $juzNumber * 20,
            ]);
        }
        $student->externalMemorizedJuzs()->syncWithoutDetaching(
            QuranJuz::query()->orderBy('juz_number')->limit(12)->pluck('id')->all()
        );
        $this->actingAs($parentUser);

        $component = Volt::test('students.progress', ['student' => $student])
            ->assertViewHas('quranJuzProgress', fn ($rows) => $rows instanceof LengthAwarePaginator
                && $rows->perPage() === 10
                && $rows->count() === 10
                && $rows->total() >= 12
                && $rows->currentPage() === 1)
            ->assertSee('data-student-juz-header-pagination', false)
            ->assertSee("wire:click=\"previousPage('studentProgressJuzPage')\"", false)
            ->assertSee("wire:click=\"nextPage('studentProgressJuzPage')\"", false)
            ->assertDontSee('app-pagination-container', false);

        $component
            ->call('nextPage', 'studentProgressJuzPage')
            ->assertViewHas('quranJuzProgress', fn ($rows) => $rows->currentPage() === 2
                && $rows->count() <= 10
                && $rows->firstItem() === 11);
    }

    public function test_latest_points_type_and_notes_stay_on_one_line_and_ellipsis_overflow(): void
    {
        $source = file_get_contents(resource_path('views/livewire/students/progress.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('student-progress-points-type-cell', $source);
        $this->assertStringContainsString('class="student-progress-points-type" title=', $source);
        $this->assertMatchesRegularExpression('/\.student-progress-points-type\s*\{[^}]*overflow:\s*hidden;[^}]*white-space:\s*nowrap;[^}]*text-overflow:\s*ellipsis;/s', $styles);
        $this->assertStringContainsString('student-progress-latest-points-note-cell', $source);
        $this->assertStringContainsString('class="student-progress-latest-points-note" title=', $source);
        $this->assertMatchesRegularExpression('/\.student-progress-latest-points-note\s*\{[^}]*overflow:\s*hidden;[^}]*white-space:\s*nowrap;[^}]*text-overflow:\s*ellipsis;/s', $styles);
    }

    public function test_ipad_mini_portrait_keeps_progress_highlights_in_one_wrapping_row(): void
    {
        $source = file_get_contents(resource_path('views/livewire/students/progress.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('student-progress-highlights mobile-compact-highlights', $source);
        $this->assertMatchesRegularExpression('/@media \(min-width: 700px\) and \(max-width: 899px\) and \(orientation: portrait\)\s*\{[^}]*\.student-progress-highlights\s*\{[^}]*display:\s*flex !important;[^}]*flex-wrap:\s*wrap;/s', $styles);
        $this->assertMatchesRegularExpression('/\.student-progress-highlights > \.stat-card\s*\{[^}]*min-width:\s*max-content;[^}]*flex:\s*1 1 7rem;/s', $styles);
    }

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

    public function test_progress_search_only_lists_active_student_profiles(): void
    {
        $this->seed(RoleSeeder::class);

        [, $activeStudent, $inactiveStudent] = $this->makeScopedProgressData();
        $inactiveStudent->update(['status' => 'inactive']);

        $manager = User::factory()->create(['username' => 'active-progress-search-manager']);
        $manager->assignRole('manager');
        $this->actingAs($manager);

        Volt::test('students.progress', ['student' => null])
            ->assertViewHas('studentOptions', fn ($students) => $students->pluck('id')->all() === [$activeStudent->id])
            ->assertSeeText($activeStudent->full_name)
            ->assertDontSeeText($inactiveStudent->full_name);
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
        $this->assertStringNotContainsString(".student-progress-data-table {\n    align-self: start;", $css);
        $this->assertStringContainsString('object-fit: cover;', $css);
        $this->assertStringContainsString('contain: paint;', $css);
    }

    public function test_quran_progress_summary_uses_the_tenant_configured_stages(): void
    {
        $this->seed(RoleSeeder::class);
        [, $student] = $this->makeScopedProgressData();

        AppSetting::storeValue('learning_progression', 'configured', true, 'boolean');
        AppSetting::storeValue('learning_progression', 'partial_test_enabled', false, 'boolean');
        AppSetting::storeValue('learning_progression', 'partial_test_required_for_final', false, 'boolean');
        AppSetting::storeValue('learning_progression', 'final_test_enabled', true, 'boolean');
        AppSetting::storeValue('learning_progression', 'final_test_required_for_awqaf', false, 'boolean');
        AppSetting::storeValue('learning_progression', 'awqaf_test_enabled', false, 'boolean');
        $student->externalMemorizedJuzs()->syncWithoutDetaching([QuranJuz::query()->firstOrFail()->id]);

        $manager = User::factory()->create(['username' => 'progress-summary-manager']);
        $manager->assignRole('manager');
        $this->actingAs($manager);

        Volt::test('students.progress', ['student' => $student])
            ->assertSee('data-learning-progression-summary', false)
            ->assertViewHas('quranProgressionSummary', fn (array $summary): bool => $summary['configured']
                && $summary['stages']->all() === ['memorization', 'final'])
            ->assertDontSee('data-progression-stage-column="partial"', false)
            ->assertSee('data-progression-stage-column="final"', false);
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
            ->assertSee('data-student-progress-photo-picker', false)
            ->assertSee('data-student-progress-photo-files', false)
            ->assertSee('data-student-progress-photo-android-menu', false)
            ->assertSee('data-student-progress-photo-camera', false)
            ->assertSee('data-student-progress-photo-upload-file', false)
            ->assertSee('/Android/i.test(navigator.userAgent)', false)
            ->assertSee('android ? open = ! open : $refs.files.click()', false)
            ->assertSee('accept="image/*" capture="environment" class="sr-only" data-max-size-kb="10240" data-student-progress-photo-camera', false)
            ->set('progressPhotoUpload', UploadedFile::fake()->image('new-student-photo.jpg', 640, 640))
            ->assertHasNoErrors()
            ->assertSeeText(__('workflow.student_progress.messages.photo_updated'));

        $photoPath = $student->fresh()->photo_path;

        $this->assertNotNull($photoPath);
        Storage::disk('public')->assertExists($photoPath);

        Volt::test('students.progress', ['student' => $student])
            ->set('progressPhotoUpload', UploadedFile::fake()->create('oversized-student-photo.jpg', 10241, 'image/jpeg'))
            ->assertHasErrors(['progressPhotoUpload' => 'max']);

        $source = file_get_contents(resource_path('views/livewire/students/progress.blade.php'));
        $this->assertStringContainsString("'max:10240'", $source);
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
        $response = $this->get(route('students.progress', $student, absolute: false))
            ->assertOk()
            ->assertSeeText('حفظ قديم');

        $this->assertSame(3, substr_count($response->getContent(), 'data-old-memorization-placeholder'));
        $response->assertSee('<bdi dir="ltr" data-old-memorization-placeholder>-</bdi>', false);
    }

    public function test_old_memorization_can_start_partial_saber_and_become_available_for_quick_partial_then_final_entry(): void
    {
        $this->seed(RoleSeeder::class);
        [, $student] = $this->makeScopedProgressData();
        $juz = QuranJuz::query()->firstOrFail();
        $student->externalMemorizedJuzs()->sync([$juz->id]);

        $manager = User::factory()->create(['username' => 'old-memorization-manager', 'phone' => '8111099']);
        $manager->assignRole('manager');
        $manager->givePermissionTo('quran-tests.quick-entry');
        $this->actingAs($manager);

        $component = Volt::test('students.progress', ['student' => $student])
            ->assertSee('wire:click="openOldMemorizationActions('.$juz->id.')"', false)
            ->call('openOldMemorizationActions', $juz->id)
            ->assertSet('oldMemorizationJuzId', $juz->id)
            ->assertSee('data-old-memorization-actions', false)
            ->assertSeeText(__('workflow.student_progress.juz_progress.old_memorization.prompt', ['juz' => $juz->juz_number]))
            ->assertSeeText(__('workflow.student_progress.juz_progress.old_memorization.start_partial'))
            ->assertDontSee('class="admin-modal__close"', false)
            ->assertSee('data-start-old-memorization-partial', false)
            ->assertSee('data-enter-old-memorization-awqaf', false)
            ->call('enterAwqafSaberForOldMemorization')
            ->assertSet('oldMemorizationJuzId', null)
            ->assertSet('showAwqafTestModal', true)
            ->call('closeAwqafTest')
            ->call('openOldMemorizationActions', $juz->id)
            ->call('startPartialSaberForOldMemorization')
            ->assertHasNoErrors()
            ->assertSet('oldMemorizationJuzId', null);

        $partialTest = QuranPartialTest::query()
            ->where('student_id', $student->id)
            ->where('juz_id', $juz->id)
            ->firstOrFail();
        $this->assertSame('in_progress', $partialTest->status);
        $this->assertTrue($partialTest->from_external_memorization);
        $this->assertCount(4, $partialTest->parts);

        $student->externalMemorizedJuzs()->detach($juz->id);

        Volt::test('students.progress', ['student' => $student])
            ->assertSeeText(__('workflow.student_progress.juz_progress.statuses.awaiting'))
            ->assertDontSeeText(__('workflow.student_progress.juz_progress.statuses.memorized_before'))
            ->assertDontSee('data-old-memorization-final-placeholder', false)
            ->assertDontSee('data-old-memorization-action', false);

        $student->externalMemorizedJuzs()->attach($juz->id);
        app(QuranPartialTestService::class)->deleteTest($partialTest);

        Volt::test('students.progress', ['student' => $student])
            ->assertSeeText(__('workflow.student_progress.juz_progress.statuses.memorized_before'))
            ->assertSee('data-old-memorization-action', false)
            ->call('openOldMemorizationActions', $juz->id)
            ->call('startPartialSaberForOldMemorization')
            ->assertHasNoErrors();

        $partialTest = QuranPartialTest::query()
            ->where('student_id', $student->id)
            ->where('juz_id', $juz->id)
            ->firstOrFail();

        Volt::test('quran-tests.quick-entry')
            ->set('partialStudentId', $student->id)
            ->assertViewHas('partialJuzs', fn ($juzs) => $juzs->pluck('id')->contains($juz->id))
            ->assertSet('partialTestId', $partialTest->id);

        $partialTest->parts()->update(['status' => 'passed', 'passed_on' => '2026-10-13']);
        $partialTest->update(['status' => 'passed', 'passed_on' => '2026-10-13']);

        Volt::test('quran-tests.quick-entry')
            ->set('finalStudentId', $student->id)
            ->assertViewHas('finalJuzs', fn ($juzs) => $juzs->pluck('id')->contains($juz->id));
    }

    public function test_old_memorization_awqaf_origin_survives_removing_the_student_setting(): void
    {
        $this->seed(RoleSeeder::class);
        [, $student] = $this->makeScopedProgressData();
        $juz = QuranJuz::query()->firstOrFail();
        $student->externalMemorizedJuzs()->sync([$juz->id]);

        $manager = User::factory()->create(['username' => 'old-awqaf-manager', 'phone' => '8111100']);
        $manager->assignRole('manager');
        $this->actingAs($manager);

        Volt::test('students.progress', ['student' => $student])
            ->call('openOldMemorizationActions', $juz->id)
            ->call('enterAwqafSaberForOldMemorization')
            ->assertSet('awqafFromExternalMemorization', true)
            ->set('awqafScore', '91')
            ->call('saveAwqafTest')
            ->assertHasNoErrors();

        $test = QuranTest::query()
            ->where('student_id', $student->id)
            ->where('juz_id', $juz->id)
            ->where('from_external_memorization', true)
            ->latest('id')
            ->firstOrFail();
        $this->assertTrue($test->from_external_memorization);

        $student->externalMemorizedJuzs()->detach($juz->id);

        Volt::test('students.progress', ['student' => $student])
            ->assertSeeText(__('workflow.student_progress.juz_progress.statuses.completed_with_awqaf'))
            ->assertSee('data-old-memorization-final-placeholder', false)
            ->assertDontSee('data-old-memorization-action', false);
    }

    public function test_failed_old_memorization_awqaf_attempt_does_not_change_the_juz_status(): void
    {
        $this->seed(RoleSeeder::class);
        [, $student] = $this->makeScopedProgressData();
        $juz = QuranJuz::query()->firstOrFail();
        $student->externalMemorizedJuzs()->sync([$juz->id]);

        $manager = User::factory()->create(['username' => 'failed-old-awqaf-manager', 'phone' => '8111101']);
        $manager->assignRole('manager');
        $this->actingAs($manager);

        Volt::test('students.progress', ['student' => $student])
            ->call('openOldMemorizationActions', $juz->id)
            ->call('enterAwqafSaberForOldMemorization')
            ->set('awqafStatus', 'failed')
            ->call('saveAwqafTest')
            ->assertHasNoErrors();

        $student->externalMemorizedJuzs()->detach($juz->id);

        Volt::test('students.progress', ['student' => $student])
            ->assertSeeText(__('workflow.student_progress.juz_progress.statuses.memorized_before'))
            ->assertDontSeeText(__('workflow.student_progress.juz_progress.statuses.awaiting'))
            ->assertSee('data-old-memorization-action', false);
    }

    public function test_juz_action_pills_swap_their_dot_for_the_requested_hover_icon(): void
    {
        $source = file_get_contents(resource_path('views/livewire/students/progress.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('wire:click="showMissingPages({{ $row->juz->id }})" class="status-chip student-juz-status-action student-juz-status-action--hover-icon', $source);
        $this->assertStringContainsString('<x-admin-action-icon name="eye" class="student-juz-status-action__hover-icon" />', $source);
        $this->assertStringContainsString('wire:click="openOldMemorizationActions({{ $row->juz->id }})" class="status-chip student-juz-status-action student-juz-status-action--hover-icon', $source);
        $this->assertStringContainsString('<x-admin-action-icon name="add" class="student-juz-status-action__hover-icon" />', $source);
        $this->assertStringContainsString('.student-juz-status-action--hover-icon:is(:hover, :focus-visible)::before', $styles);
        $this->assertMatchesRegularExpression('/\.student-juz-status-action__hover-icon\s*\{[^}]*inset-inline-start:\s*\.525rem;[^}]*width:\s*1rem;[^}]*height:\s*1rem;/s', $styles);
        $this->assertMatchesRegularExpression('/\.student-juz-status-action__plus\s*\{[^}]*inset-inline-start:\s*\.525rem;[^}]*width:\s*1rem;[^}]*height:\s*1rem;/s', $styles);
        $this->assertMatchesRegularExpression('/\.status-chip::before\s*\{[^}]*width:\s*0\.45rem;[^}]*height:\s*0\.45rem;[^}]*aspect-ratio:\s*1;[^}]*border-radius:\s*50%;[^}]*clip-path:\s*circle\(50%\);/s', $styles);
        $this->assertStringNotContainsString('cursor: help;', $styles);
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
            ->assertSee('data-student-progress-assessments', false)
            ->assertSee('table-content text-sm', false)
            ->assertDontSee('w-[65%]', false)
            ->assertDontSee('w-28 min-w-28', false)
            ->assertSeeText('Course Final Exam')
            ->assertDontSeeText('Course Final Exam · Quran Track');

        $component
            ->call('showDetails', 'assessments')
            ->assertSee('data-student-progress-assessments', false)
            ->assertSeeText('Weekly Quiz')
            ->assertDontSeeText('Weekly Quiz · Quran Track');
    }

    public function test_student_progress_limits_regular_assessments_to_default_course_but_keeps_final_exam_history(): void
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
            'is_active' => false,
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

        $secondaryFinalType = AssessmentType::create([
            'name' => 'Secondary Final Exam',
            'code' => 'secondary_final_exam',
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

        $secondaryFinalAssessment = Assessment::create([
            'group_id' => $secondaryGroup->id,
            'assessment_type_id' => $secondaryFinalType->id,
            'title' => 'Secondary Course Final Exam',
            'total_mark' => 100,
            'pass_mark' => 50,
            'is_active' => true,
        ]);

        AssessmentResult::create([
            'assessment_id' => $secondaryFinalAssessment->id,
            'enrollment_id' => $secondaryEnrollment->id,
            'student_id' => $ownStudent->id,
            'teacher_id' => $secondaryTeacher->id,
            'score' => 81,
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

        $component = Volt::test('students.progress', ['student' => $ownStudent])
            ->assertViewHas('stats', fn (array $stats) => $stats['points'] === 12)
            ->assertViewHas('assessmentResults', fn ($results) => $results->pluck('assessment.title')->all() === ['Weekly Quiz'])
            ->assertViewHas('finalAssessmentResults', fn ($results) => $results->pluck('assessment.title')->all() === ['Secondary Course Final Exam'])
            ->assertSeeText('Parent Group')
            ->assertSeeText('Quiz Reward')
            ->assertSeeText('Parent Secondary Group')
            ->assertDontSeeText('Course Filter Quiz')
            ->assertSeeText('Secondary Course Final Exam')
            ->assertDontSeeText('Secondary Bonus')
            ->assertSeeText('Second Course Note');

        $component
            ->call('showDetails', 'assessments')
            ->assertSeeText('Weekly Quiz')
            ->assertDontSeeText('Course Filter Quiz')
            ->call('showDetails', 'final-assessments')
            ->assertSeeText('Secondary Course Final Exam');

        $parentDetails = Volt::test('students.progress', ['student' => $ownStudent])
            ->assertDontSeeText(__('workflow.student_progress.selection.change_student'))
            ->assertSee('student-progress-parent-details-card-action', false)
            ->assertSee('student-progress-parent-details-card-action__eye', false)
            ->call('showDetails', 'parent')
            ->assertSeeText('+963 999 555 111')
            ->assertSeeText('Scoped Mother')
            ->assertSeeText('Scoped Address');

        $this->assertSame(3, substr_count($parentDetails->html(), 'student-parent-details__row'));
        $this->assertSame(3, substr_count($parentDetails->html(), 'data-student-parent-phone-call='));
        $this->assertStringContainsString('href="tel:+963999555111"', $parentDetails->html());
        $this->assertStringContainsString('href="tel:+963999555222"', $parentDetails->html());
        $this->assertStringContainsString('href="tel:+963115555555"', $parentDetails->html());

        $styles = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString(".student-progress-parent-details-card-action {\n    position: absolute;\n    z-index: 1;\n    inset: 0;", $styles);
        $this->assertStringContainsString('.student-progress-parent-details-card-action:is(:hover, :focus-visible) .student-progress-parent-details-card-action__eye', $styles);
        $this->assertMatchesRegularExpression('/@media \(max-width: 699px\) \{.*?\.student-progress-parent-details-card-action \{\s*inset: \.5rem \.5rem auto auto;.*?width: 1\.5rem;.*?height: 1\.5rem;.*?border: 1px solid/s', $styles);
        $this->assertMatchesRegularExpression('/\.student-parent-details__phone-call \{\s*display: none;.*?@media \(max-width: 699px\) \{\s*\.student-parent-details__phone-call \{\s*display: inline-block;\s*\}\s*\.student-parent-details__phone-static \{\s*display: none;/s', $styles);
    }

    public function test_progress_page_without_route_student_shows_selector_for_manager_scope(): void
    {
        $this->seed(RoleSeeder::class);

        [, $selectedStudent] = $this->makeScopedProgressData();

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
            ->assertSee('data-student-progress-student-selector', false)
            ->assertSee('data-search-input="true"', false)
            ->assertSee('data-open-on-focus="true"', false)
            ->assertSee('data-hide-placeholder-option="true"', false)
            ->assertSee('data-scroll-to-selected="false"', false)
            ->assertSee('data-clear-search-after-select="true"', false)
            ->assertSee('data-defer-clear-after-select="true"', false)
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
        $this->assertStringContainsString('searchable-select__chevron--input', $searchableSelectScript);
        $this->assertStringContainsString('function createSearchableSelectChevron(inputMode = false)', $searchableSelectScript);
        $this->assertStringContainsString('stroke-linecap="round" stroke-linejoin="round"', $searchableSelectScript);
        $this->assertStringContainsString('function searchableSelectPlaceholderOption(select)', $searchableSelectScript);
        $this->assertStringContainsString("const SEARCHABLE_SELECT_BINDING_VERSION = '16'", $searchableSelectScript);
        $this->assertStringContainsString("select.dataset.scrollToSelected === 'false'", $searchableSelectScript);
        $this->assertStringContainsString("const clearSearchAfterSelect = searchInputMode && select.dataset.clearSearchAfterSelect === 'true'", $searchableSelectScript);
        $this->assertStringContainsString("const deferClearAfterSelect = clearSearchAfterSelect && select.dataset.deferClearAfterSelect === 'true'", $searchableSelectScript);
        $this->assertStringContainsString('const deferredClearIsPending = deferClearAfterSelect && (', $searchableSelectScript);
        $this->assertStringContainsString('const displaySelectedValue = hasSelectedValue && (!clearSearchAfterSelect || deferredClearIsPending);', $searchableSelectScript);
        $this->assertStringContainsString('scrollSearchableSelectToSelected(select, list, search);', $searchableSelectScript);
        $this->assertStringContainsString("options.find((option) => option.value === 'all')", $searchableSelectScript);
        $this->assertStringContainsString("searchableSelectBinding(select).includes('filter')", $searchableSelectScript);
        $this->assertStringContainsString("displaySelectedValue || search.value.trim() !== ''", $searchableSelectScript);
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
        $this->assertStringContainsString("['ArrowDown', 'ArrowUp'].includes(event.key)", $searchableSelectScript);
        $this->assertStringContainsString('highlightSearchableSelectOption(list, search, nextIndex)', $searchableSelectScript);
        $this->assertStringContainsString("list.querySelector('.searchable-select__option--highlighted')", $searchableSelectScript);
        $this->assertStringContainsString("search.addEventListener('keydown', handleSearchableSelectKeydown)", $searchableSelectScript);
        $this->assertStringContainsString("button.addEventListener('keydown'", $searchableSelectScript);
        $this->assertStringContainsString('focusNextInteractiveControl(searchInputMode ? search : button)', $searchableSelectScript);
        $this->assertStringContainsString('const STUDENT_PROGRESS_SELECTION_MINIMUM_VISIBLE_MS = 650;', $searchableSelectScript);
        $this->assertStringContainsString('const deferredSearchableSelectSelections = new Map();', $searchableSelectScript);
        $this->assertStringContainsString('deferredSearchableSelectSelections.set(select.id, {', $searchableSelectScript);
        $this->assertStringContainsString('|| deferredSelection?.value === select.value', $searchableSelectScript);
        $this->assertStringContainsString('STUDENT_PROGRESS_SELECTION_MINIMUM_VISIBLE_MS - (Date.now() - pendingSelection.selectedAt)', $searchableSelectScript);
        $this->assertStringContainsString('window.setTimeout(() => {', $searchableSelectScript);
        $this->assertStringContainsString('currentPendingSelection !== pendingSelection', $searchableSelectScript);
        $this->assertStringContainsString("window.addEventListener('student-progress-profile-loaded', clearStudentProgressSearchAfterProfileLoaded);", $searchableSelectScript);

        Volt::test('students.progress', ['student' => null])
            ->set('selectedStudentId', $selectedStudent->id)
            ->assertSet('currentStudent.id', $selectedStudent->id)
            ->assertDispatched('student-progress-profile-loaded', selectId: 'student-progress-student');

        $searchableSelectCss = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString("[data-student-progress-student-selector] .searchable-select__search--trigger {\n        font-size: 1rem;", $searchableSelectCss);
        $this->assertStringContainsString('.searchable-select--input.searchable-select--clearable.searchable-select--selected .searchable-select__chevron--input {', $searchableSelectCss);
        $this->assertStringContainsString('.searchable-select--input.searchable-select--clearable.searchable-select--selected .searchable-select__clear {', $searchableSelectCss);
        $this->assertStringContainsString('.searchable-select--input.searchable-select--placeholder .searchable-select__clear {', $searchableSelectCss);
        $this->assertStringContainsString('.searchable-select--input.searchable-select--clearable:has(.searchable-select__search--trigger:not(:placeholder-shown)) .searchable-select__clear {', $searchableSelectCss);
        $this->assertStringContainsString('.searchable-select__chevron--input,', $searchableSelectCss);
        $this->assertStringContainsString('.searchable-select__chevron svg {', $searchableSelectCss);
        $this->assertStringContainsString('align-items: center;', $searchableSelectCss);
        $this->assertStringContainsString('display: none;', $searchableSelectCss);
        $this->assertStringContainsString('.searchable-select__option--highlighted,', $searchableSelectCss);
    }

    public function test_enrollment_total_points_reuse_course_end_rules_in_overview_and_details(): void
    {
        $this->seed(RoleSeeder::class);
        [$parentUser, $student] = $this->makeScopedProgressData();
        $this->actingAs($parentUser);
        $enrollment = $student->enrollments()->firstOrFail();
        $transaction = PointTransaction::query()->where('enrollment_id', $enrollment->id)->firstOrFail();
        $transaction->update(['points' => 650]);
        foreach (['required_passed_final_tests' => 0, 'required_memorized_pages' => 100, 'required_passed_quizzes' => 0, 'retain_percentage' => 50, 'minimum_points' => 0] as $key => $value) {
            AppSetting::storeValue('course_completion', $key, $value, 'integer');
        }
        $adjustment = $transaction->replicate();
        $adjustment->fill(['source_type' => CourseCompletionRuleService::ADJUSTMENT_SOURCE_TYPE, 'points' => -300])->save();
        $voided = $transaction->replicate();
        $voided->fill(['points' => 9000, 'voided_at' => now()])->save();
        $countBefore = PointTransaction::count();

        Volt::test('students.progress', ['student' => $student])
            ->assertViewHas('enrollmentTotalPoints', fn ($points) => $points[$enrollment->id] === 400)
            ->assertDontSee(__('workflow.student_progress.enrollments.headers.total_points'))
            ->call('showDetails', 'enrollments')
            ->assertSee(__('workflow.student_progress.enrollments.headers.total_points'))
            ->assertViewHas('enrollmentTotalPoints', fn ($points) => $points[$enrollment->id] === 400);

        AppSetting::storeValue('course_completion', 'required_memorized_pages', 0, 'integer');
        Volt::test('students.progress', ['student' => $student])
            ->assertViewHas('enrollmentTotalPoints', fn ($points) => $points[$enrollment->id] === 700);

        $enrollment->group->course->update(['is_active' => false]);
        $enrollment->update(['status' => 'completed']);
        Volt::test('students.progress', ['student' => $student])
            ->assertViewHas('enrollmentTotalPoints', fn ($points) => $points[$enrollment->id] === 700);

        foreach ([0 => 200, 100 => 200, 200 => 200, 201 => 300, 600 => 600] as $base => $expected) {
            $transaction->update(['points' => $base]);
            $this->assertSame($expected, app(CourseEndService::class)->enrollmentTotalPoints($enrollment->fresh()));
        }
        $this->assertSame($countBefore, PointTransaction::count());
        $this->assertNull($adjustment->fresh()->voided_at);
    }

    public function test_finished_course_points_keep_the_rules_snapshotted_when_the_course_ended(): void
    {
        $this->seed(RoleSeeder::class);
        [$parentUser, $student] = $this->makeScopedProgressData();
        $enrollment = $student->enrollments()->with('group.course')->firstOrFail();
        PointTransaction::query()->where('enrollment_id', $enrollment->id)->firstOrFail()->update(['points' => 650]);

        foreach (['required_passed_final_tests' => 0, 'required_memorized_pages' => 100, 'required_passed_quizzes' => 0, 'retain_percentage' => 50, 'minimum_points' => 0] as $key => $value) {
            AppSetting::storeValue('course_completion', $key, $value, 'integer');
        }

        $course = $enrollment->group->course;
        app(CourseLifecycleService::class)->finish($course);
        AppSetting::storeValue('course_completion', 'required_memorized_pages', 0, 'integer');
        $this->actingAs($parentUser);

        Volt::test('students.progress', ['student' => $student])
            ->call('showDetails', 'enrollments')
            ->assertViewHas('enrollmentTotalPoints', fn ($points) => $points[$enrollment->id] === 400);

        $this->assertSame(100, $course->fresh()->completion_settings_snapshot['required_memorized_pages']);
        $this->assertSame(400, app(CourseEndService::class)->enrollmentTotalPoints($enrollment->fresh()));
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
            'course_id' => Course::create(['name' => 'Completed Progress Course', 'is_active' => false])->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Completed Progress Group',
            'capacity' => 12,
            'is_active' => false,
        ]);
        $cancelledGroup = Group::create([
            'course_id' => Course::create(['name' => 'Cancelled Progress Course', 'is_active' => true])->id,
            'academic_year_id' => $academicYear->id,
            'teacher_id' => $teacher->id,
            'name' => 'Cancelled Progress Group',
            'capacity' => 12,
            'is_active' => false,
        ]);

        $completedEnrollment = Enrollment::create([
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
            ->assertSee('data-student-progress-enrollment-row="'.$activeEnrollment->id.'"', false)
            ->assertSee('data-student-progress-enrollment-row="'.$completedEnrollment->id.'"', false)
            ->assertSee('data-enrollment-status="active"', false)
            ->assertSeeText(__('crud.common.status_options.active'))
            ->assertSeeText('Completed Progress Group')
            ->assertDontSeeText('Cancelled Progress Group')
            ->call('showDetails', 'enrollments')
            ->assertSee('data-student-progress-enrollment-row="'.$activeEnrollment->id.'"', false)
            ->assertSee('data-enrollment-status="active"', false)
            ->assertSeeText(__('crud.common.status_options.active'))
            ->assertSeeText('Completed Progress Group')
            ->assertDontSeeText('Cancelled Progress Group');
    }

    public function test_progress_group_box_warns_when_the_student_has_no_active_course_enrollment(): void
    {
        $this->seed(RoleSeeder::class);

        [$parentUser, $student] = $this->makeScopedProgressData();
        $enrollment = Enrollment::query()->where('student_id', $student->id)->firstOrFail();

        $this->actingAs($parentUser);

        Volt::test('students.progress', ['student' => $student])
            ->assertSee('data-student-progress-current-group', false)
            ->assertDontSee('data-student-progress-no-active-enrollment', false)
            ->assertSeeText($enrollment->group->name)
            ->assertDontSeeText(__('workflow.student_progress.profile.not_enrolled_active_course'));

        $enrollment->group->course->update(['is_active' => false]);

        Volt::test('students.progress', ['student' => $student])
            ->assertSee('data-student-progress-no-active-enrollment', false)
            ->assertSee('border-red-500/25 bg-red-500/10', false)
            ->assertSee('student-progress-current-group__warning-label', false)
            ->assertDontSee('data-student-progress-quick-enrollment-action', false)
            ->assertSeeText(__('workflow.student_progress.profile.not_enrolled_active_course'));
    }

    public function test_manager_can_quickly_enroll_a_student_from_the_progress_group_warning(): void
    {
        $this->seed(RoleSeeder::class);

        [, $student] = $this->makeScopedProgressData();
        $previousEnrollment = Enrollment::query()->where('student_id', $student->id)->firstOrFail();
        $previousEnrollment->group->course->update(['is_active' => false]);

        $youngGrade = GradeLevel::create(['name' => 'Young Grade', 'sort_order' => 1, 'is_active' => true]);
        $studentGrade = GradeLevel::create(['name' => 'Student Grade', 'sort_order' => 2, 'is_active' => true]);
        $olderGrade = GradeLevel::create(['name' => 'Older Grade', 'sort_order' => 3, 'is_active' => true]);
        $student->update(['grade_level_id' => $studentGrade->id]);
        $previousEnrollment->group->update(['grade_level_id' => $studentGrade->id]);

        $activeCourse = Course::create(['name' => 'Quick Active Course', 'is_active' => true]);
        $youngGroup = Group::create([
            'course_id' => $activeCourse->id,
            'academic_year_id' => $previousEnrollment->group->academic_year_id,
            'teacher_id' => $previousEnrollment->group->teacher_id,
            'grade_level_id' => $youngGrade->id,
            'name' => 'Young Grade Group',
            'capacity' => 20,
            'is_active' => true,
        ]);
        $sameAgeGroup = Group::create([
            'course_id' => $activeCourse->id,
            'academic_year_id' => $previousEnrollment->group->academic_year_id,
            'teacher_id' => $previousEnrollment->group->teacher_id,
            'grade_level_id' => $studentGrade->id,
            'name' => 'Same Age Group',
            'capacity' => 20,
            'is_active' => true,
        ]);
        $availableGroup = Group::create([
            'course_id' => $activeCourse->id,
            'academic_year_id' => $previousEnrollment->group->academic_year_id,
            'teacher_id' => $previousEnrollment->group->teacher_id,
            'grade_level_id' => $olderGrade->id,
            'name' => $previousEnrollment->group->name,
            'capacity' => 20,
            'is_active' => true,
        ]);
        $inactiveGroup = Group::create([
            'course_id' => $activeCourse->id,
            'academic_year_id' => $previousEnrollment->group->academic_year_id,
            'teacher_id' => $previousEnrollment->group->teacher_id,
            'name' => 'Quick Inactive Group',
            'capacity' => 20,
            'is_active' => false,
        ]);
        $inactiveCourse = Course::create(['name' => 'Quick Inactive Course', 'is_active' => false]);
        $inactiveCourseGroup = Group::create([
            'course_id' => $inactiveCourse->id,
            'academic_year_id' => $previousEnrollment->group->academic_year_id,
            'teacher_id' => $previousEnrollment->group->teacher_id,
            'name' => 'Inactive Course Group',
            'capacity' => 20,
            'is_active' => true,
        ]);

        $manager = User::factory()->create(['username' => 'progress-quick-enrollment-manager']);
        $manager->assignRole('manager');
        $this->actingAs($manager);

        Volt::test('students.progress', ['student' => $student])
            ->assertSee('data-student-progress-quick-enrollment-action', false)
            ->assertSee('student-progress-quick-enrollment-card-action', false)
            ->assertSee('student-progress-quick-enrollment-card-action__plus', false)
            ->call('openQuickEnrollment')
            ->assertSet('showQuickEnrollmentModal', true)
            ->assertSet('quickEnrollmentGroupId', $availableGroup->id)
            ->assertSee('admin-modal__dialog--xl', false)
            ->assertViewHas('quickEnrollmentGroups', fn ($groups) => $groups->pluck('id')->all() === [
                $youngGroup->id,
                $sameAgeGroup->id,
                $availableGroup->id,
            ])
            ->assertSee('data-group-option-columns', false)
            ->assertSee('data-equal-option-spacing="true"', false)
            ->assertSee('data-option-columns', false)
            ->assertSeeText(__('workflow.student_progress.quick_enrollment.placeholder'))
            ->assertSeeText($availableGroup->name)
            ->assertSeeText('Quick Active Course')
            ->assertDontSeeText($inactiveGroup->name)
            ->assertDontSeeText($inactiveCourseGroup->name)
            ->call('saveQuickEnrollment')
            ->assertHasNoErrors()
            ->assertSet('showQuickEnrollmentModal', false)
            ->assertDontSee('data-student-progress-no-active-enrollment', false)
            ->assertSeeText($availableGroup->name);

        $quickEnrollmentStyles = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString(".student-progress-quick-enrollment-card-action {\n    position: absolute;\n    z-index: 1;\n    inset: 0;", $quickEnrollmentStyles);
        $this->assertStringNotContainsString(".student-progress-data-table {\n    align-self: start;", $quickEnrollmentStyles);
        $this->assertStringContainsString('.student-progress-quick-enrollment-card-action:is(:hover, :focus-visible) .student-progress-quick-enrollment-card-action__plus', $quickEnrollmentStyles);
        $this->assertStringContainsString("@media (hover: none) {\n    .student-progress-quick-enrollment-card-action__plus {\n        opacity: 1;", $quickEnrollmentStyles);
        $this->assertStringContainsString("select[data-equal-option-spacing] + .searchable-select .searchable-select__option--group {\n    grid-template-columns: var(--group-option-grid-template, repeat(var(--group-option-columns), minmax(0, 1fr)));\n    justify-content: space-evenly;\n    gap: 0;\n    padding-block: .39rem;\n    padding-inline: 0;", $quickEnrollmentStyles);
        $this->assertStringContainsString("select[data-group-option-columns] + .searchable-select .searchable-select__selected-columns {\n    position: absolute;", $quickEnrollmentStyles);
        $this->assertStringContainsString('grid-template-columns: var(--group-option-grid-template, repeat(2, minmax(0, 1fr)));', $quickEnrollmentStyles);
        $this->assertStringContainsString('inset-inline: calc(.35rem + 1px);', $quickEnrollmentStyles);
        $this->assertStringContainsString('padding-inline: 0;', $quickEnrollmentStyles);
        $this->assertStringContainsString(".searchable-select__search--trigger::selection {\n    color: transparent;\n    background: transparent;", $quickEnrollmentStyles);
        $quickEnrollmentScript = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("select?.dataset.equalOptionSpacing === 'true'", $quickEnrollmentScript);
        $this->assertStringContainsString("rows.forEach(row => row.style.setProperty('--group-option-grid-template', gridTemplate));", $quickEnrollmentScript);
        $this->assertStringContainsString('const allColumnValues = Array.from(select.options)', $quickEnrollmentScript);
        $this->assertStringContainsString('.map(option => groupOptionColumns(option.dataset.optionColumns, false));', $quickEnrollmentScript);
        $this->assertStringContainsString("selectedColumns.className = 'searchable-select__selected-columns';", $quickEnrollmentScript);
        $this->assertStringContainsString("cell.className = 'searchable-select__selected-column searchable-select__group-cell';", $quickEnrollmentScript);
        $this->assertStringContainsString("wrapper.querySelectorAll('.searchable-select__selected-column > span')", $quickEnrollmentScript);
        $this->assertStringContainsString('stableGroupOptionRowWidth(wrapper.clientWidth, listInlinePadding, panelInlineBorder)', $quickEnrollmentScript);
        $this->assertStringContainsString("wrapper.classList.toggle('searchable-select--column-selection', showSelectedColumns);", $quickEnrollmentScript);

        $this->assertDatabaseHas('enrollments', [
            'student_id' => $student->id,
            'group_id' => $availableGroup->id,
            'status' => 'active',
            'enrolled_at' => now()->startOfDay()->toDateTimeString(),
        ]);
    }

    public function test_quick_enrollment_defaults_to_the_least_populated_group_for_the_students_grade(): void
    {
        $this->seed(RoleSeeder::class);

        [, $student] = $this->makeScopedProgressData();
        $previousEnrollment = Enrollment::query()->where('student_id', $student->id)->firstOrFail();
        $previousEnrollment->group->course->update(['is_active' => false]);

        $youngGrade = GradeLevel::create(['name' => 'Fallback Young Grade', 'sort_order' => 1, 'is_active' => true]);
        $studentGrade = GradeLevel::create(['name' => 'Fallback Student Grade', 'sort_order' => 2, 'is_active' => true]);
        $olderGrade = GradeLevel::create(['name' => 'Fallback Older Grade', 'sort_order' => 3, 'is_active' => true]);
        $student->update(['grade_level_id' => $studentGrade->id]);
        $previousEnrollment->group->update([
            'grade_level_id' => $studentGrade->id,
            'name' => 'Previous Unmatched Group',
        ]);

        $course = Course::create(['name' => 'Fallback Active Course', 'is_active' => true]);
        $groupAttributes = [
            'course_id' => $course->id,
            'academic_year_id' => $previousEnrollment->group->academic_year_id,
            'teacher_id' => $previousEnrollment->group->teacher_id,
            'capacity' => 20,
            'is_active' => true,
        ];
        $youngGroup = Group::create($groupAttributes + ['grade_level_id' => $youngGrade->id, 'name' => 'Fallback Young Group']);
        $busySameAgeGroup = Group::create($groupAttributes + ['grade_level_id' => $studentGrade->id, 'name' => 'A Busy Same Age Group']);
        $lightSameAgeGroup = Group::create($groupAttributes + ['grade_level_id' => $studentGrade->id, 'name' => 'Z Light Same Age Group']);
        $olderGroup = Group::create($groupAttributes + ['grade_level_id' => $olderGrade->id, 'name' => 'Fallback Older Group']);

        $occupant = Student::create([
            'first_name' => 'Occupied',
            'last_name' => 'Student',
            'birth_date' => '2014-01-01',
            'grade_level_id' => $studentGrade->id,
            'status' => 'active',
        ]);
        Enrollment::create([
            'student_id' => $occupant->id,
            'group_id' => $busySameAgeGroup->id,
            'enrolled_at' => '2026-10-01',
            'status' => 'active',
        ]);

        $manager = User::factory()->create(['username' => 'progress-grade-default-manager']);
        $manager->assignRole('manager');
        $this->actingAs($manager);

        Volt::test('students.progress', ['student' => $student])
            ->call('openQuickEnrollment')
            ->assertSet('quickEnrollmentGroupId', $lightSameAgeGroup->id)
            ->assertViewHas('quickEnrollmentGroups', fn ($groups) => $groups->pluck('id')->all() === [
                $youngGroup->id,
                $lightSameAgeGroup->id,
                $busySameAgeGroup->id,
                $olderGroup->id,
            ])
            ->assertViewHas('quickEnrollmentGroups', fn ($groups) => $groups->pluck('active_enrollments_count', 'id')->all() === [
                $youngGroup->id => 0,
                $lightSameAgeGroup->id => 0,
                $busySameAgeGroup->id => 1,
                $olderGroup->id => 0,
            ]);
    }

    public function test_juz_follows_the_configured_final_and_awqaf_progression(): void
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
            ->assertSee('wire:click="showMissingPages('.$juz->id.')"', false)
            ->assertDontSee('data-juz-progress-actions-heading', false)
            ->call('showMissingPages', $juz->id)
            ->assertSee('data-student-progress-missing-pages', false)
            ->call('closeMissingPages')
            ->assertDontSee('wire:click="openAwqafTest(', false);

        $finalTest->update(['status' => 'passed', 'passed_on' => '2026-09-16']);
        $finalTest->attempts()->firstOrFail()->update(['status' => 'passed']);
        $enrollment->update(['status' => 'completed', 'left_at' => '2026-09-15']);

        $component
            ->call('$refresh')
            ->assertSee('wire:click="openAwqafTest('.$juz->id.')" class="status-chip student-juz-status-action student-juz-status-action--awqaf', false)
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
            ->assertViewHas('quranJuzProgress', fn ($rows) => $rows->first()?->status === 'awaiting'
                && $rows->first()?->next_stage === 'awqaf'
                && $rows->first()?->path_complete === false)
            ->assertDontSeeText(__('workflow.student_progress.juz_progress.show_missing'))
            ->assertSee('wire:click="openAwqafTest('.$juz->id.')" class="status-chip student-juz-status-action student-juz-status-action--awqaf', false)
            ->call('openAwqafTest', $juz->id)
            ->assertSet('showAwqafTestModal', true)
            ->assertDontSee('wire:click="closeAwqafTest" class="pill-link"', false)
            ->assertSee('wire:model.live="awqafStatus" required data-clearable="false"', false)
            ->call('saveAwqafTest')
            ->assertHasErrors(['awqafScore' => 'required'])
            ->set('awqafTestedOn', '2026-09-16')
            ->set('awqafScore', '60')
            ->set('awqafStatus', 'failed')
            ->assertDontSee('wire:model="awqafScore"', false)
            ->assertDontSee('<option value="cancelled">', false)
            ->call('saveAwqafTest')
            ->assertHasNoErrors()
            ->assertSet('showAwqafTestModal', false)
            ->assertSee('wire:click="openAwqafTest('.$juz->id.')" class="status-chip student-juz-status-action student-juz-status-action--awqaf', false)
            ->call('openAwqafTest', $juz->id)
            ->set('awqafTestedOn', '2026-09-17')
            ->set('awqafScore', '80')
            ->set('awqafStatus', 'passed')
            ->call('saveAwqafTest')
            ->assertHasNoErrors()
            ->assertViewHas('quranJuzProgress', fn ($rows) => $rows->first()?->path_complete === true)
            ->assertDontSee('wire:click="openAwqafTest(', false);

        $failedAwqafTest = QuranTest::query()
            ->where('enrollment_id', $currentEnrollment->id)
            ->where('juz_id', $juz->id)
            ->where('status', 'failed')
            ->firstOrFail();

        $this->assertNull($failedAwqafTest->score);

        $passedAwqafTest = QuranTest::query()
            ->where('enrollment_id', $currentEnrollment->id)
            ->where('juz_id', $juz->id)
            ->where('status', 'passed')
            ->firstOrFail();

        $component
            ->assertSee('data-juz-awqaf-status', false)
            ->assertSeeText(__('workflow.student_progress.juz_progress.statuses.completed_with_awqaf'))
            ->assertSee('class="student-juz-awqaf-tooltip"', false)
            ->assertSee('17-09-2026', false)
            ->assertSeeText('80%')
            ->assertDontSee('wire:click="showAwqafDetails(', false)
            ->assertDontSee('data-student-progress-awqaf-details', false);

        $this->assertDatabaseHas('quran_tests', [
            'enrollment_id' => $currentEnrollment->id,
            'juz_id' => $juz->id,
            'score' => 80,
            'status' => 'passed',
            'attempt_no' => 2,
        ]);

        $progressStyles = file_get_contents(resource_path('css/app.css'));
        $this->assertMatchesRegularExpression('/\.student-juz-awqaf-status:is\(:hover, :focus-visible\) \.student-juz-awqaf-tooltip\s*\{[^}]*opacity:\s*1;/s', $progressStyles);
    }

    public function test_progress_page_omits_timeline_and_preserves_student_scope(): void
    {
        $this->seed(RoleSeeder::class);
        [$parent, $student, $otherStudent] = $this->makeScopedProgressData();
        $this->actingAs($parent);
        $component = Volt::test('students.progress', ['student' => $student])
            ->assertDontSee('data-student-timeline', false)
            ->assertDontSeeText('Hidden Quiz');
        $component->set('selectedStudentId', $otherStudent->id)->assertForbidden();
    }

    public function test_teacher_can_read_complete_progress_history_without_separate_module_permissions(): void
    {
        $this->seed(RoleSeeder::class);
        [, $student, $otherStudent] = $this->makeScopedProgressData();
        $oldEnrollment = $student->enrollments()->firstOrFail();
        $user = User::factory()->create();
        $user->givePermissionTo('students.view');
        $teacher = Teacher::create([
            'user_id' => $user->id, 'first_name' => 'Current', 'last_name' => 'Teacher',
            'phone' => '0999333000', 'status' => 'active',
        ]);
        $group = $oldEnrollment->group->replicate();
        $group->fill([
            'teacher_id' => $teacher->id, 'name' => 'Current Teacher Group',
            'course_id' => Course::create(['name' => 'New Teacher Course', 'is_active' => true])->id,
        ])->save();
        Enrollment::create([
            'student_id' => $student->id, 'group_id' => $group->id,
            'enrolled_at' => '2026-09-20', 'status' => 'active',
        ]);
        $juz = QuranJuz::firstOrFail();
        $partial = QuranPartialTest::create([
            'student_id' => $student->id, 'enrollment_id' => $oldEnrollment->id,
            'juz_id' => $juz->id, 'status' => 'in_progress',
        ]);
        $partial->parts()->create(['part_number' => 1, 'status' => 'passed']);
        $final = QuranFinalTest::create([
            'student_id' => $student->id, 'enrollment_id' => $oldEnrollment->id,
            'juz_id' => $juz->id, 'status' => 'passed', 'passed_on' => '2026-09-15',
        ]);
        $final->attempts()->create([
            'teacher_id' => $oldEnrollment->group->teacher_id, 'attempt_no' => 1,
            'tested_on' => '2026-09-15', 'score' => 95, 'status' => 'passed',
        ]);
        $awqaf = QuranTest::where('student_id', $student->id)->firstOrFail()->replicate();
        $awqaf->quran_test_type_id = QuranTestType::where('code', 'awqaf')->value('id');
        $awqaf->save();
        $status = AttendanceStatus::create([
            'name' => 'Progress present', 'code' => 'progress-present', 'scope' => 'student',
            'is_present' => true, 'is_active' => true,
        ]);
        $day = GroupAttendanceDay::create([
            'group_id' => $oldEnrollment->group_id, 'attendance_date' => '2026-09-15', 'status' => 'closed',
        ]);
        StudentAttendanceRecord::create([
            'group_attendance_day_id' => $day->id, 'enrollment_id' => $oldEnrollment->id,
            'attendance_status_id' => $status->id,
        ]);

        $this->actingAs($user);
        $this->assertFalse($user->can('memorization.view'));
        $this->assertFalse(app(AccessScopeService::class)->canAccessEnrollment($user, $oldEnrollment));
        Volt::test('students.progress', ['student' => $student])
            ->assertSee('Weekly Quiz')->assertSee('Teacher Shared Note')->assertSee('Parent visible points')
            ->assertDontSee('Hidden Quiz')->assertDontSee('Other Shared Note')
            ->assertViewHas('enrollments', fn ($rows) => $rows->count() === 2)
            ->assertViewHas('memorizationRows', fn ($rows) => $rows->pluck('page')->all() === [581, 582, 583])
            ->assertViewHas('lastRecitedPage', 583)
            ->assertSee('data-student-progress-last-recitation', false)
            ->assertViewHas('stats', fn ($stats) => $stats['attendance_days'] === 1 && $stats['quran_partial_tests'] === 1 && $stats['quran_final_tests'] === 1)
            ->assertViewHas('quranJuzProgress', fn ($rows) => $rows->first()->passed_parts === 1 && $rows->first()->final_passed && $rows->first()->awqaf_passed)
            ->call('showDetails', 'memorization')
            ->assertViewHas('paginatedDetails', fn ($rows) => $rows->total() === 3)
            ->call('showDetails', 'enrollments')
            ->assertViewHas('paginatedDetails', fn ($rows) => $rows->total() === 2);

        $this->get(route('students.progress', $otherStudent, absolute: false))->assertForbidden();
    }

    public function test_all_student_progress_scope_is_read_only_and_does_not_expand_other_pages(): void
    {
        $this->seed(RoleSeeder::class);
        [, $student, $otherStudent] = $this->makeScopedProgressData();
        $user = User::factory()->create();
        $user->givePermissionTo(['students.view', 'students.update', 'quran-tests.record']);
        Teacher::create([
            'user_id' => $user->id, 'first_name' => 'Progress', 'last_name' => 'Viewer',
            'phone' => '0999333001', 'status' => 'active',
        ]);
        $scopes = app(AccessScopeService::class);
        $scopes->syncUserOverrides($user, [AccessScopeService::ALL_STUDENT_PROGRESS => [1]]);
        $this->actingAs($user);

        Volt::test('students.progress', ['student' => null])
            ->assertViewHas('studentOptions', fn ($students) => $students->count() === 2)
            ->set('selectedStudentId', $otherStudent->id)
            ->assertSee('Hidden Quiz')->assertSee('Other Shared Note')
            ->assertDontSee('data-student-progress-photo-upload', false);
        $this->get(route('students.progress', $student, absolute: false))->assertOk();
        $this->get(route('students.index', absolute: false))->assertOk()->assertDontSee('Parent Student')->assertDontSee('Other Student');
        $this->get(route('students.files', $student, absolute: false))->assertForbidden();
        $this->assertFalse($scopes->canAccessStudent($user, $student));
        $this->assertSame([], $scopes->accessibleEnrollmentIds($user));
        $this->assertSame([], $scopes->accessibleGroupIds($user));
        Volt::test('students.progress', ['student' => $student])
            ->set('progressPhotoUpload', UploadedFile::fake()->image('forbidden.jpg'))
            ->assertForbidden();
        Volt::test('students.progress', ['student' => $student])
            ->call('openAwqafTest', QuranJuz::firstOrFail()->id)
            ->assertSet('showAwqafTestModal', false);

        $scopes->syncUserOverrides($user, []);
        $this->get(route('students.progress', $student, absolute: false))->assertForbidden();
        Volt::test('students.progress', ['student' => null])->set('selectedStudentId', $otherStudent->id)->assertForbidden();
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
