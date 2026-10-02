<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\AssessmentResult;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\MemorizationSession;
use App\Models\PointTransaction;
use App\Models\QuranFinalTest;
use App\Models\QuranJuz;
use App\Models\QuranPartialTest;
use App\Models\QuranTest;
use App\Models\QuranTestType;
use App\Models\Student;
use App\Models\StudentAttendanceRecord;
use App\Models\StudentNote;
use App\Models\StudentPageAchievement;
use App\Models\Teacher;
use App\Services\AccessScopeService;
use App\Services\CourseCompletionRuleService;
use App\Services\CourseEndService;
use App\Services\LearningProgressionService;
use App\Services\LessonLevelProgressionService;
use App\Services\PointLedgerService;
use App\Services\QuranProgressionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component
{
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;
    use WithFileUploads;
    use WithPagination;

    public ?Student $currentStudent = null;

    public int|string|null $selectedStudentId = null;

    public $progressPhotoUpload = null;

    public ?int $missingJuzId = null;

    public string $openDetails = '';

    public bool $showAwqafTestModal = false;

    public bool $showAwqafUnavailableModal = false;

    public ?int $awqafEnrollmentId = null;

    public ?int $awqafJuzId = null;

    public string $awqafTestedOn = '';

    public string $awqafScore = '';

    public string $awqafStatus = 'passed';

    public string $awqafNotes = '';

    public bool $showManualPromotionModal = false;

    public string $manualPromotionReason = '';

    public function mount(?Student $student = null): void
    {
        $this->authorizePermission('students.view');

        if ($student) {
            $this->setCurrentStudent($student->id);

            return;
        }

        $students = $this->studentOptionsQuery()->limit(2)->get();

        if ($students->count() === 1) {
            $this->setCurrentStudent((int) $students->first()->id);
        }
    }

    public function updatedSelectedStudentId(int|string|null $value): void
    {
        if (blank($value)) {
            $this->currentStudent = null;
            $this->selectedStudentId = null;
            $this->missingJuzId = null;
            $this->openDetails = '';
            $this->showAwqafUnavailableModal = false;
            $this->closeAwqafTest();

            return;
        }

        $this->setCurrentStudent((int) $value);
        $this->dispatch('student-progress-profile-loaded', selectId: 'student-progress-student');
    }

    public function updatedProgressPhotoUpload(): void
    {
        $this->authorizePermission('students.update');

        abort_unless($this->currentStudent, 404);
        $student = Student::query()->findOrFail($this->currentStudent->id);
        $this->authorizeScopedStudentAccess($student);

        $validated = $this->validate([
            'progressPhotoUpload' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('uploads.image_max_kb')],
        ]);
        $path = $validated['progressPhotoUpload']->store('students/photos/'.$student->id, 'public');

        if ($student->photo_path) {
            Storage::disk('public')->delete($student->photo_path);
        }

        $student->update(['photo_path' => $path]);
        $this->currentStudent = $student->fresh(['gradeLevel', 'parentProfile', 'quranCurrentJuz']);
        $this->reset('progressPhotoUpload');
        session()->flash('status', __('workflow.student_progress.messages.photo_updated'));
    }

    public function showDetails(string $section): void
    {
        if ($section === 'parent') {
            app(\App\Services\Landlord\CurrentModuleAccess::class)->ensure('parents');
        }
        if (in_array($section, ['parent', 'memorization', 'points', 'assessments', 'final-assessments', 'enrollments', 'notes'], true)) {
            $this->openDetails = $section;
            $this->resetPage('studentProgressDetailsPage');
        }
    }

    public function closeDetails(): void
    {
        $this->openDetails = '';
    }

    public function showMissingPages(int $juzId): void
    {
        $this->missingJuzId = $juzId;
    }

    public function closeMissingPages(): void
    {
        $this->missingJuzId = null;
    }

    public function openAwqafTest(int $juzId): void
    {
        $this->authorizeAnyPermission(['quran-awqaf-tests.record', 'quran-tests.record']);

        if (! $this->currentStudent) {
            $this->addError('awqaf', __('workflow.quran_tests.errors.no_active_enrollment'));

            return;
        }

        $enrollment = $this->scopeEnrollmentsQuery(
            Enrollment::query()->with(['group.teacher', 'student'])
                ->currentActiveForStudent((int) $this->currentStudent->id)
        )->first();

        if (! $enrollment) {
            $this->showAwqafTestModal = false;
            $this->showAwqafUnavailableModal = true;
            $this->resetValidation();

            return;
        }

        $this->authorizeTeacherEnrollmentAccess($enrollment);

        if (! QuranJuz::query()->whereKey($juzId)->exists()) {
            $this->addError('awqaf', __('crud.common.not_available'));

            return;
        }

        $this->awqafEnrollmentId = $enrollment->id;
        $this->awqafJuzId = $juzId;
        $this->awqafTestedOn = now()->toDateString();
        $this->awqafScore = '';
        $this->awqafStatus = 'passed';
        $this->awqafNotes = '';
        $this->showAwqafUnavailableModal = false;
        $this->showAwqafTestModal = true;
        $this->resetValidation();
    }

    public function closeAwqafTest(): void
    {
        $this->reset('showAwqafTestModal', 'awqafEnrollmentId', 'awqafJuzId', 'awqafTestedOn', 'awqafScore', 'awqafNotes');
        $this->awqafStatus = 'passed';
        $this->resetValidation();
    }

    public function closeAwqafUnavailable(): void
    {
        $this->showAwqafUnavailableModal = false;
    }

    public function saveAwqafTest(): void
    {
        $this->authorizeAnyPermission(['quran-awqaf-tests.record', 'quran-tests.record']);

        $validated = $this->validate([
            'awqafEnrollmentId' => ['required', 'exists:enrollments,id'],
            'awqafJuzId' => ['required', 'exists:quran_juzs,id'],
            'awqafTestedOn' => ['required', 'date'],
            'awqafScore' => ['required_if:awqafStatus,passed', 'nullable', 'numeric', 'between:0,100'],
            'awqafStatus' => ['required', 'in:passed,failed,cancelled'],
        ], [], [
            'awqafScore' => __('workflow.quran_tests.form.score'),
        ]);

        if (! $this->currentStudent) {
            $this->addError('awqafEnrollmentId', __('workflow.quran_tests.errors.no_active_enrollment'));

            return;
        }

        $enrollment = $this->scopeEnrollmentsQuery(
            Enrollment::query()
                ->with(['group.teacher', 'student'])
                ->whereKey((int) $validated['awqafEnrollmentId'])
                ->currentActiveForStudent((int) $this->currentStudent->id)
        )->first();

        if (! $enrollment) {
            $this->closeAwqafTest();
            $this->showAwqafUnavailableModal = true;

            return;
        }

        $this->authorizeTeacherEnrollmentAccess($enrollment);
        $teacherId = $this->currentTeacher()?->id ?: $enrollment->group?->teacher_id;

        if (! $teacherId) {
            $this->addError('awqafEnrollmentId', __('workflow.quran_tests.errors.no_teacher_available'));

            return;
        }

        $teacher = Teacher::query()->findOrFail($teacherId);
        $this->authorizeScopedTeacherAccess($teacher);

        $testType = QuranTestType::query()->where('code', 'awqaf')->where('is_active', true)->firstOrFail();
        $progression = app(QuranProgressionService::class)->validate($enrollment, (int) $validated['awqafJuzId'], $testType);

        if ($progression && ! $this->canAnyPermission(['quran-awqaf-tests.override-progression', 'quran-tests.override-progression'])) {
            $this->addError('awqafJuzId', $progression);

            return;
        }

        $test = QuranTest::query()->create([
            'enrollment_id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'teacher_id' => $teacherId,
            'juz_id' => (int) $validated['awqafJuzId'],
            'quran_test_type_id' => $testType->id,
            'tested_on' => $validated['awqafTestedOn'],
            'score' => $validated['awqafScore'] !== '' ? $validated['awqafScore'] : null,
            'status' => $validated['awqafStatus'],
            'attempt_no' => app(QuranProgressionService::class)->nextAttemptNumber($enrollment, (int) $validated['awqafJuzId'], $testType->id),
            'notes' => null,
        ]);

        app(PointLedgerService::class)->recordQuranTestPoints($test->fresh(['enrollment.student', 'student.gradeLevel', 'type']));

        $this->closeAwqafTest();
        session()->flash('status', __('workflow.quran_tests.messages.saved'));
    }

    public function assignLessonProgression(): void
    {
        $this->authorizePermission('learning-progression.manage');
        abort_unless($this->currentStudent, 404);

        $student = Student::query()->findOrFail($this->currentStudent->id);
        $this->authorizeScopedStudentAccess($student);
        app(LessonLevelProgressionService::class)->assign($student, auth()->user());
        session()->flash('status', __('learning_progression.lesson_summary.assigned'));
    }

    public function openManualPromotion(): void
    {
        $this->authorizePermission('learning-progression.manual-promote');
        abort_unless($this->currentStudent, 404);
        $this->manualPromotionReason = '';
        $this->showManualPromotionModal = true;
        $this->resetValidation('manualPromotionReason');
    }

    public function closeManualPromotion(): void
    {
        $this->showManualPromotionModal = false;
        $this->manualPromotionReason = '';
        $this->resetValidation('manualPromotionReason');
    }

    public function manuallyPromoteLessonLevel(): void
    {
        $this->authorizePermission('learning-progression.manual-promote');
        abort_unless($this->currentStudent, 404);
        $validated = $this->validate([
            'manualPromotionReason' => ['required', 'string', 'min:10', 'max:2000'],
        ], attributes: [
            'manualPromotionReason' => __('learning_progression.manual_promotion.reason'),
        ]);

        $student = Student::query()->findOrFail($this->currentStudent->id);
        $this->authorizeScopedStudentAccess($student);
        app(LessonLevelProgressionService::class)->manuallyPromote(
            $student,
            auth()->user(),
            $validated['manualPromotionReason'],
        );

        $this->closeManualPromotion();
        session()->flash('status', __('learning_progression.manual_promotion.saved'));
    }

    public function with(): array
    {
        $this->authorizePermission('students.view');
        if ($this->currentStudent) {
            abort_unless(app(AccessScopeService::class)->canAccessStudentProgress(auth()->user(), $this->currentStudent), 403);
        }

        $studentOptions = $this->studentOptionsQuery()
            ->get()
            ->map(fn (Student $student): object => (object) [
                'full_name' => $student->full_name,
                'id' => (int) $student->id,
                'search' => collect([$student->full_name, $student->student_number])->filter()->implode(' '),
                'student_number' => $student->student_number,
            ]);

        if (! $this->currentStudent) {
            return ['studentOptions' => $studentOptions];
        }

        $studentRecord = $this->currentStudent->fresh(['user', 'gradeLevel', 'parentProfile', 'quranCurrentJuz', 'externalMemorizedJuzs']);
        $enrollments = $this->scopeProgressDataQuery(
            'scopeEnrollmentsQuery',
            Enrollment::query()
                ->with(['group.course', 'group.teacher'])
                ->where('student_id', $studentRecord->id)
        )
            ->orderByRaw("case when status = 'active' then 0 else 1 end")
            ->orderByDesc('enrolled_at')
            ->orderByDesc('id')
            ->get();
        $visibleEnrollments = $enrollments
            ->whereIn('status', ['active', 'completed'])
            ->values();
        $enrollmentIds = $enrollments->pluck('id')->all();
        $activeEnrollment = $visibleEnrollments->firstWhere('status', 'active') ?: $visibleEnrollments->first();
        $defaultCourseId = Course::query()->where('is_default', true)->where('is_active', true)->value('id');
        $highlightEnrollmentIds = $defaultCourseId
            ? $enrollments->filter(fn (Enrollment $enrollment) => (int) $enrollment->group?->course_id === (int) $defaultCourseId)->pluck('id')->all()
            : [];
        $highlightEnrollments = $enrollments->whereIn('id', $highlightEnrollmentIds);

        $generalPages = StudentPageAchievement::query()
            ->where('student_id', $studentRecord->id)
            ->distinct()
            ->pluck('page_no')
            ->map(fn ($page) => (int) $page)
            ->unique()
            ->values();
        $highlightPages = $highlightEnrollmentIds === []
            ? collect()
            : StudentPageAchievement::query()
                ->where('student_id', $studentRecord->id)
                ->whereIn('first_enrollment_id', $highlightEnrollmentIds)
                ->distinct()
                ->pluck('page_no')
                ->map(fn ($page) => (int) $page)
                ->unique()
                ->values();

        $memorizationSessions = $this->canViewProgressSection('memorization.view')
            ? $this->scopeProgressDataQuery(
                'scopeMemorizationSessionsQuery',
                MemorizationSession::query()
                    ->with(['teacher', 'pages' => fn ($query) => $query->orderBy('page_no')])
                    ->where('student_id', $studentRecord->id)
                    ->when($enrollmentIds === [], fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('enrollment_id', $enrollmentIds))
            )->latest('recorded_on')->latest('id')->get()
            : collect();
        $memorizationRows = $memorizationSessions
            ->flatMap(function (MemorizationSession $session) {
                $pages = $session->pages->pluck('page_no')->map(fn ($page) => (int) $page)->filter()->values();

                if ($pages->isEmpty() && filled($session->from_page) && filled($session->to_page)) {
                    $pages = collect(range((int) min($session->from_page, $session->to_page), (int) max($session->from_page, $session->to_page)));
                }

                return $pages->map(fn (int $page): object => (object) [
                    'date' => $session->recorded_on,
                    'page' => $page,
                    'teacher' => $session->teacher ? trim($session->teacher->first_name.' '.$session->teacher->last_name) : null,
                ]);
            })
            ->values();

        $assessmentResults = $this->canViewProgressSection('assessment-results.view')
            ? $this->scopeProgressDataQuery(
                'scopeAssessmentResultsQuery',
                AssessmentResult::query()
                    ->with(['assessment.type', 'enrollment.group.course'])
                    ->where('student_id', $studentRecord->id)
                    ->when($enrollmentIds === [], fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('enrollment_id', $enrollmentIds))
            )->latest('id')->get()
            : collect();
        $finalAssessmentResults = $assessmentResults
            ->filter(function (AssessmentResult $result): bool {
                $assessment = $result->assessment;
                $code = Str::lower((string) $assessment?->type?->code);
                $name = Str::lower(Str::squish(($assessment?->type?->name ?? '').' '.($assessment?->title ?? '')));

                return in_array($code, ['final', 'final_exam', 'final-exam'], true)
                    || Str::contains($name, ['final exam', 'final assessment', 'نهائي']);
            })
            ->values();
        $nonFinalAssessmentResults = $assessmentResults
            ->reject(fn (AssessmentResult $result): bool => $finalAssessmentResults->contains('id', $result->id))
            ->values();

        $awqafTests = $this->canViewProgressSection('quran-awqaf-tests.view') || $this->canViewProgressSection('quran-tests.view')
            ? $this->scopeProgressDataQuery(
                'scopeQuranTestsQuery',
                QuranTest::query()
                    ->with(['juz'])
                    ->where('student_id', $studentRecord->id)
                    ->whereHas('type', fn ($query) => $query->where('code', 'awqaf'))
                    ->when($enrollmentIds === [], fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('enrollment_id', $enrollmentIds))
            )->orderByDesc('tested_on')->orderByDesc('id')->get()
            : collect();
        $passedAwqafTestsByJuz = $awqafTests->where('status', 'passed')->groupBy('juz_id');

        $partialTests = $this->canViewProgressSection('quran-partial-tests.view')
            ? $this->scopeProgressDataQuery(
                'scopeQuranPartialTestsQuery',
                QuranPartialTest::query()
                    ->with(['enrollment', 'juz', 'parts.attempts'])
                    ->where('student_id', $studentRecord->id)
                    ->when($enrollmentIds === [], fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('enrollment_id', $enrollmentIds))
            )->get()
            : collect();
        $finalTests = $this->canViewProgressSection('quran-final-tests.view')
            ? $this->scopeProgressDataQuery(
                'scopeQuranFinalTestsQuery',
                QuranFinalTest::query()
                    ->with(['attempts', 'juz', 'enrollment.group.course'])
                    ->where('student_id', $studentRecord->id)
                    ->when($enrollmentIds === [], fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('enrollment_id', $enrollmentIds))
            )->get()
            : collect();

        $pointTransactions = $this->canViewProgressSection('points.view')
            ? $this->scopeProgressDataQuery(
                'scopePointTransactionsQuery',
                PointTransaction::query()
                    ->with(['pointType'])
                    ->where('student_id', $studentRecord->id)
                    ->where(fn (Builder $query) => $query
                        ->whereNull('enrollment_id')
                        ->when($highlightEnrollmentIds !== [], fn (Builder $enrollmentQuery) => $enrollmentQuery->orWhereIn('enrollment_id', $highlightEnrollmentIds)))
            )->latest('entered_at')->latest('id')->get()->filter(fn (PointTransaction $transaction) => $transaction->isEffectivelyActive())->values()
            : collect();

        $parentVisibleNotes = $this->scopeProgressDataQuery(
            'scopeStudentNotesQuery',
            StudentNote::query()
                ->where('student_id', $studentRecord->id)
                ->where('visibility', 'visible_to_parent')
                ->when($enrollmentIds === [], fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('enrollment_id', $enrollmentIds))
        )->latest('noted_at')->latest('id')->get();

        $attendanceDays = $this->canViewProgressSection('attendance.student.view') && $highlightEnrollmentIds !== []
            ? $this->scopeProgressDataQuery(
                'scopeStudentAttendanceRecordsQuery',
                StudentAttendanceRecord::query()
                    ->whereHas('status', fn ($query) => $query->where('is_present', true))
                    ->whereIn('enrollment_id', $highlightEnrollmentIds)
            )->distinct('group_attendance_day_id')->count('group_attendance_day_id')
            : 0;

        $timelineAttendance = $this->canViewProgressSection('attendance.student.view')
            ? $this->scopeProgressDataQuery(
                'scopeStudentAttendanceRecordsQuery',
                StudentAttendanceRecord::query()->with(['status', 'attendanceDay'])
                    ->whereIn('enrollment_id', $enrollmentIds)
            )->get()
            : collect();
        $timelinePoints = $this->canViewProgressSection('points.view')
            ? $this->scopeProgressDataQuery(
                'scopePointTransactionsQuery',
                PointTransaction::query()->notVoided()
                    ->where('student_id', $studentRecord->id)->whereIn('enrollment_id', $enrollmentIds)
            )->get()
            : null;
        $timeline = app(\App\Services\StudentTimelineService::class)->build(
            $visibleEnrollments,
            $this->canViewProgressSection('memorization.view') ? $memorizationSessions : null,
            $this->canViewProgressSection('attendance.student.view') ? $timelineAttendance : null,
            $finalTests, $awqafTests, $finalAssessmentResults, $timelinePoints,
        );

        $pageSet = $generalPages->flip();
        $externalJuzIds = $studentRecord->externalMemorizedJuzs->pluck('id')->map(fn ($id) => (int) $id)->all();
        $learningProgression = app(LearningProgressionService::class)->settings();
        $lessonLevelSummary = $learningProgression['profile'] === LearningProgressionService::PROFILE_LESSON_LEVEL
            ? app(LessonLevelProgressionService::class)->summary($studentRecord)
            : null;
        $quranJuzProgress = QuranJuz::query()->orderBy('juz_number')->get()
            ->map(function (QuranJuz $juz) use ($pageSet, $partialTests, $finalTests, $enrollments, $passedAwqafTestsByJuz, $externalJuzIds, $learningProgression) {
                $memorizedExternally = in_array((int) $juz->id, $externalJuzIds, true);
                $pages = collect(range((int) $juz->from_page, (int) $juz->to_page));
                $missingPages = $pages->reject(fn (int $page) => $pageSet->has($page))->values();
                $juzPartialTests = $partialTests->where('juz_id', $juz->id);
                $passedParts = $juzPartialTests->flatMap->parts->where('status', 'passed')->pluck('part_number')->unique()->count();
                $partialPassed = $juzPartialTests->contains('status', 'passed') || $passedParts >= 4;
                $juzFinalTests = $finalTests->where('juz_id', $juz->id);
                $latestFinalAttempt = $juzFinalTests->flatMap->attempts
                    ->sortByDesc(fn ($attempt) => sprintf('%010d-%010d', $attempt->tested_on?->timestamp ?? 0, $attempt->id))
                    ->first();
                $latestAwqafTest = $passedAwqafTestsByJuz->get($juz->id, collect())->sortByDesc('tested_on')->first();
                $finalMade = $latestFinalAttempt !== null;
                $finalPassed = $juzFinalTests->contains('status', 'passed') || $juzFinalTests->flatMap->attempts->contains('status', 'passed');
                $memorizationComplete = $memorizedExternally || $missingPages->isEmpty();
                $pathComplete = $learningProgression['awqaf_test_enabled']
                    ? $latestAwqafTest !== null
                    : ($learningProgression['final_test_enabled']
                        ? $finalPassed
                        : ($learningProgression['partial_test_enabled'] && $partialPassed));
                $nextStage = match (true) {
                    $pathComplete => 'complete',
                    $learningProgression['awqaf_test_enabled'] && ($finalPassed || $memorizedExternally || (! $learningProgression['final_test_required_for_awqaf'] && $memorizationComplete)) => 'awqaf',
                    $learningProgression['final_test_enabled'] && (! $learningProgression['partial_test_required_for_final'] || $partialPassed) => 'final',
                    $memorizationComplete && $learningProgression['partial_test_enabled'] => 'partial',
                    ! $memorizationComplete => 'memorization',
                    $learningProgression['final_test_enabled'] => 'final',
                    $learningProgression['awqaf_test_enabled'] => 'awqaf',
                    default => 'complete',
                };
                $status = $pathComplete ? 'finished' : ($nextStage === 'memorization' ? 'missing' : 'awaiting');

                return (object) [
                    'juz' => $juz,
                    'memorized_externally' => $memorizedExternally,
                    'memorized_pages' => $pages->count() - $missingPages->count(),
                    'missing_pages' => $missingPages,
                    'passed_parts' => $passedParts,
                    'partial_passed' => $partialPassed,
                    'partial_test_created' => $juzPartialTests->isNotEmpty(),
                    'latest_final_score' => $latestFinalAttempt?->score,
                    'latest_final_date' => $latestFinalAttempt?->tested_on,
                    'latest_final_course' => $juzFinalTests->first()?->enrollment?->group?->course?->name,
                    'final_made' => $finalMade,
                    'final_passed' => $finalPassed,
                    'awqaf_passed' => $latestAwqafTest !== null,
                    'awqaf_passed_on' => $latestAwqafTest?->tested_on,
                    'next_stage' => $nextStage,
                    'path_complete' => $pathComplete,
                    'status' => $memorizedExternally ? 'memorized_before' : $status,
                    'enrollment' => $juzFinalTests->first()?->enrollment ?: $juzPartialTests->first()?->enrollment ?: $enrollments->first(),
                ];
            })
            ->filter(fn ($row) => $row->memorized_externally || $row->memorized_pages > 0 || $row->passed_parts > 0 || $row->latest_final_score !== null)
            ->values();
        $currentProgress = $studentRecord->quran_current_juz_id
            ? $quranJuzProgress->first(fn ($row) => (int) $row->juz->id === (int) $studentRecord->quran_current_juz_id)
            : null;
        $currentProgress ??= $quranJuzProgress->first(fn ($row) => $row->next_stage !== 'complete') ?: $quranJuzProgress->last();
        $configuredStages = collect(['memorization'])
            ->when($learningProgression['partial_test_enabled'], fn ($stages) => $stages->push('partial'))
            ->when($learningProgression['final_test_enabled'], fn ($stages) => $stages->push('final'))
            ->when($learningProgression['awqaf_test_enabled'], fn ($stages) => $stages->push('awqaf'))
            ->values();
        $quranProgressionSummary = [
            'configured' => $learningProgression['configured'],
            'stages' => $configuredStages,
            'current_stage' => $currentProgress?->next_stage,
            'current_juz_number' => $currentProgress?->juz?->juz_number,
            'completed_juz_count' => $quranJuzProgress->where('path_complete', true)->count(),
            'active_juz_count' => $quranJuzProgress->where('path_complete', false)->count(),
        ];
        $progressStats = collect([
            'attendance_days' => 'attendance_days',
            'memorized_pages' => 'memorized_pages',
        ])->when($learningProgression['partial_test_enabled'], fn ($items) => $items->put('quran_partial_tests', 'quran_partial_tests'))
            ->when($learningProgression['final_test_enabled'], fn ($items) => $items->put('quran_final_tests', 'quran_final_tests'))
            ->put('points', 'points');
        $selectedMissingJuz = $this->missingJuzId
            ? $quranJuzProgress->first(fn ($row) => (int) $row->juz->id === (int) $this->missingJuzId)
            : null;

        $detailsSource = match ($this->openDetails) {
            'memorization' => $memorizationRows,
            'points' => $pointTransactions,
            'assessments' => $nonFinalAssessmentResults,
            'final-assessments' => $finalAssessmentResults,
            'enrollments' => $visibleEnrollments,
            'notes' => $parentVisibleNotes,
            default => collect(),
        };
        $detailsPage = max(1, $this->getPage('studentProgressDetailsPage'));
        $paginatedDetails = new LengthAwarePaginator(
            $detailsSource->forPage($detailsPage, 10)->values(),
            $detailsSource->count(),
            10,
            $detailsPage,
            ['pageName' => 'studentProgressDetailsPage']
        );

        $completionSettings = app(CourseCompletionRuleService::class)->settings();
        $courseEnd = app(CourseEndService::class);
        $enrollmentTotalPoints = $this->canViewProgressSection('points.view') ? $visibleEnrollments->take(5)
            ->concat($this->openDetails === 'enrollments' ? $paginatedDetails->items() : [])
            ->unique('id')
            ->mapWithKeys(function (Enrollment $enrollment) use ($studentRecord, $courseEnd, $completionSettings): array {
                $enrollment->setRelation('student', $studentRecord);

                return [$enrollment->id => $courseEnd->enrollmentTotalPoints($enrollment, $completionSettings)];
            }) : collect();

        return [
            'studentOptions' => $studentOptions,
            'timeline' => $timeline,
            'timelineDefaultIndex' => app(\App\Services\StudentTimelineService::class)->defaultIndex($timeline, $defaultCourseId ? (int) $defaultCourseId : null),
            'studentRecord' => $studentRecord,
            'activeEnrollment' => $activeEnrollment,
            'enrollments' => $visibleEnrollments,
            'enrollmentTotalPoints' => $enrollmentTotalPoints,
            'memorizationRows' => $memorizationRows,
            'assessmentResults' => $nonFinalAssessmentResults,
            'finalAssessmentResults' => $finalAssessmentResults,
            'awqafTests' => $awqafTests,
            'pointTransactions' => $pointTransactions,
            'parentVisibleNotes' => $parentVisibleNotes,
            'quranJuzProgress' => $quranJuzProgress,
            'quranProgressionSettings' => $learningProgression,
            'quranProgressionSummary' => $quranProgressionSummary,
            'lessonLevelSummary' => $lessonLevelSummary,
            'progressStats' => $progressStats,
            'selectedMissingJuz' => $selectedMissingJuz,
            'paginatedDetails' => $paginatedDetails,
            'stats' => [
                'attendance_days' => $attendanceDays,
                'memorized_pages' => $highlightPages->count(),
                'quran_partial_tests' => $partialTests->whereIn('enrollment_id', $highlightEnrollmentIds)->count(),
                'quran_final_tests' => $finalTests->whereIn('enrollment_id', $highlightEnrollmentIds)->count(),
                'points' => (int) $highlightEnrollments->sum('final_points_cached')
                    + (int) $pointTransactions->whereNull('enrollment_id')->sum('points'),
            ],
        ];
    }

    protected function setCurrentStudent(int $studentId): void
    {
        $student = Student::query()->with(['gradeLevel', 'parentProfile', 'quranCurrentJuz'])->findOrFail($studentId);
        abort_unless(app(AccessScopeService::class)->canAccessStudentProgress(auth()->user(), $student), 403);
        $this->currentStudent = $student;
        $this->selectedStudentId = $student->id;
        $this->missingJuzId = null;
        $this->openDetails = '';
        $this->showAwqafUnavailableModal = false;
        $this->closeAwqafTest();
    }

    protected function studentOptionsQuery()
    {
        return app(AccessScopeService::class)->scopeStudentProgressStudents(
            Student::query()
                ->with('parentProfile:id,father_name')
                ->select(['id', 'parent_id', 'first_name', 'last_name', 'student_number'])
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->orderBy('id'),
            auth()->user(),
        );
    }

    protected function canViewFullProgress(): bool
    {
        return auth()->user()?->teacherProfile !== null
            || app(AccessScopeService::class)->canViewAllStudentProgress(auth()->user());
    }

    protected function canViewProgressSection(string $permission): bool
    {
        return app(\App\Services\Landlord\CurrentModuleAccess::class)->permissionAvailable($permission)
            && ($this->canViewFullProgress() || auth()->user()->can($permission));
    }

    protected function scopeProgressDataQuery(string $scopeMethod, Builder $query): Builder
    {
        return $this->canViewFullProgress() ? $query : $this->{$scopeMethod}($query);
    }

    protected function currentTeacher(): ?Teacher
    {
        return $this->linkedTeacherForPermission('quran-awqaf-tests.record-linked-teacher')
            ?: $this->linkedTeacherForPermission('quran-tests.record-linked-teacher');
    }

    protected function authorizeAnyPermission(array $permissions): void
    {
        abort_unless($this->canAnyPermission($permissions), 403);
    }

    protected function canAnyPermission(array $permissions): bool
    {
        return collect($permissions)->contains(fn (string $permission): bool => auth()->user()?->can($permission) ?? false);
    }
}; ?>

@php
    $statusClass = fn (string $status) => match ($status) {
        'passed', 'finished', 'active', 'completed' => 'status-chip--emerald',
        'failed', 'missing', 'withdrawn', 'cancelled' => 'status-chip--rose',
        'awaiting', 'in_progress', 'pending' => 'status-chip--amber',
        default => 'status-chip--slate',
    };
@endphp

<div class="page-stack">
    <section class="page-hero student-progress-hero p-6 lg:p-8">
        <h1 class="font-display text-4xl leading-none text-white md:text-5xl">{{ __('workflow.student_progress.title') }}</h1>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="student-progress-selection-card surface-panel surface-panel--soft p-5 lg:p-6">
        @if ($studentOptions->isEmpty())
            <div class="admin-empty-state">{{ __('workflow.student_progress.selection.no_students') }}</div>
        @else
            <div class="admin-filter-field" data-student-progress-student-selector>
                <label for="student-progress-student" class="sr-only">{{ __('workflow.student_progress.selection.search') }}</label>
                <select id="student-progress-student" wire:model.live="selectedStudentId" data-search-input="true" data-open-on-focus="true" data-hide-placeholder-option="true" data-scroll-to-selected="false" data-clear-search-after-select="true" data-defer-clear-after-select="true" data-search-placeholder="{{ __('workflow.student_progress.selection.search_placeholder') }}" class="w-full rounded-xl px-4 py-3 text-sm" data-record-label="person">
                    <option value="">{{ __('workflow.student_progress.selection.search_placeholder') }}</option>
                    @foreach ($studentOptions as $option)
                        <option value="{{ $option->id }}" data-search="{{ $option->search }}" data-option-name="{{ $option->full_name }}" data-option-number="{{ $option->student_number }}">{{ $option->full_name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
    </section>

    @if ($currentStudent)
        <section class="student-progress-profile surface-panel surface-panel--soft p-5 lg:p-6">
            @php($studentPhotoUrl = $studentRecord->photoUrl())
            <div class="student-progress-profile__grid">
                @if(auth()->user()->can('students.update') && app(AccessScopeService::class)->canAccessStudent(auth()->user(), $studentRecord))
                    <label class="student-progress-profile__photo group cursor-pointer overflow-hidden rounded-3xl border border-white/10 bg-white/5" data-student-progress-photo-upload title="{{ __('workflow.student_progress.actions.update_photo') }}">
                        <input wire:model="progressPhotoUpload" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only">
                        @if ($studentPhotoUrl)<x-avatar-image type="student" :src="$studentPhotoUrl" alt="{{ $studentRecord->full_name }}" class="student-progress-profile__photo-image" />@else<div class="student-progress-profile__photo-fallback">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($studentRecord->first_name ?: 'S', 0, 1)) }}</div>@endif
                        <span class="absolute inset-x-2 bottom-2 rounded-xl bg-black/65 px-2 py-1.5 text-center text-xs font-medium text-white opacity-0 transition group-hover:opacity-100 group-focus-within:opacity-100">{{ __('workflow.student_progress.actions.update_photo') }}</span>
                        <span wire:loading.flex wire:target="progressPhotoUpload" class="absolute inset-0 items-center justify-center bg-black/65"><span class="size-9 animate-spin rounded-full border-2 border-white/30 border-t-white" aria-hidden="true"></span></span>
                    </label>
                @else
                    <div class="student-progress-profile__photo overflow-hidden rounded-3xl border border-white/10 bg-white/5">
                        @if ($studentPhotoUrl)<x-avatar-image type="student" :src="$studentPhotoUrl" alt="{{ $studentRecord->full_name }}" class="student-progress-profile__photo-image" />@else<div class="student-progress-profile__photo-fallback">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($studentRecord->first_name ?: 'S', 0, 1)) }}</div>@endif
                    </div>
                @endif
                <div class="student-progress-profile__fields grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-2xl border border-white/8 bg-white/4 p-3"><div class="kpi-label">{{ __('workflow.student_progress.profile.student_no') }}</div><div class="mt-2 text-sm font-semibold text-white">{{ $studentRecord->student_number ?: __('crud.common.not_available') }}</div></div>
                    <div class="rounded-2xl border border-white/8 bg-white/4 p-3"><div class="kpi-label">{{ __('workflow.student_progress.profile.student_name') }}</div><div class="record-person-name mt-2 text-sm font-semibold text-white">{{ $studentRecord->full_name }}</div></div>
                    @if (app(\App\Services\Landlord\CurrentModuleAccess::class)->enabled('parents'))
                    <div class="relative rounded-2xl border border-white/8 bg-white/4 p-3"><div class="kpi-label pe-7">{{ __('workflow.student_progress.profile.father_name') }}</div>@if ($studentRecord->parentProfile)<button type="button" wire:click="showDetails('parent')" class="absolute end-2 top-2 inline-flex h-6 w-6 items-center justify-center rounded-lg border border-white/10 bg-white/5 text-neutral-300 transition hover:bg-white/10 hover:text-white" title="{{ __('workflow.student_progress.actions.details') }}" aria-label="{{ __('workflow.student_progress.actions.details') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-3.5 w-3.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12Z"/><circle cx="12" cy="12" r="2.25"/></svg></button>@endif<div class="record-person-name mt-2 text-sm font-semibold text-white">{{ $studentRecord->parentProfile?->father_name ?: __('crud.common.not_available') }}</div></div>
                    @endif
                    <div class="grid grid-cols-2 overflow-hidden rounded-2xl border border-white/8 bg-white/4"><div class="min-w-0 p-3"><div class="kpi-label">{{ __('workflow.student_progress.profile.grade') }}</div><div class="mt-2 truncate text-sm font-semibold text-white">{{ $studentRecord->gradeLevel?->name ?: __('crud.common.not_available') }}</div></div><div class="student-progress-profile__birth-year min-w-0 p-3"><div class="kpi-label">{{ __('workflow.student_progress.profile.birth_year') }}</div><div class="mt-2 truncate text-sm font-semibold text-white">{{ $studentRecord->birth_date?->format('Y') ?: __('crud.common.not_available') }}</div></div></div>
                    <div class="rounded-2xl border border-white/8 bg-white/4 p-3"><div class="kpi-label">{{ __('workflow.student_progress.profile.phone') }}</div><div class="mt-2 text-sm font-semibold text-white"><bdi dir="ltr" class="record-phone">{{ $studentRecord->user?->phone ?: __('crud.common.not_available') }}</bdi></div></div>
                    <div class="rounded-2xl border border-white/8 bg-white/4 p-3"><div class="kpi-label">{{ __('workflow.student_progress.profile.school') }}</div><div class="mt-2 text-sm font-semibold text-white">{{ $studentRecord->school_name ?: __('crud.common.not_available') }}</div></div>
                    <div class="rounded-2xl border border-white/8 bg-white/4 p-3"><div class="kpi-label">{{ __('workflow.student_progress.profile.group') }}</div><div class="mt-2 text-sm font-semibold text-white">{{ $activeEnrollment?->group?->name ?: __('crud.common.not_available') }}</div></div>
                    <div class="rounded-2xl border border-white/8 bg-white/4 p-3"><div class="kpi-label">{{ __('workflow.student_progress.profile.current_juz') }}</div><div class="mt-2 text-sm font-semibold text-white">{{ $studentRecord->quranCurrentJuz ? __('workflow.common.labels.juz_number', ['number' => $studentRecord->quranCurrentJuz->juz_number]) : __('crud.common.not_available') }}</div></div>
                </div>
            </div>
            @error('progressPhotoUpload')<div class="mt-3 text-sm text-red-400">{{ $message }}</div>@enderror
        </section>

        <section class="mobile-compact-highlights {{ $progressStats->count() === 4 ? 'mobile-compact-highlights--four' : ($progressStats->count() === 5 ? 'mobile-compact-highlights--five' : '') }} grid gap-4 md:grid-cols-2 {{ $progressStats->count() === 3 ? 'xl:grid-cols-3' : ($progressStats->count() === 4 ? 'xl:grid-cols-4' : 'xl:grid-cols-5') }}">
            @foreach ($progressStats as $key => $label)
                <article class="stat-card"><div class="kpi-label">{{ __('workflow.student_progress.stats.'.$label) }}</div><div class="metric-value mt-3">{{ number_format($stats[$key]) }}</div></article>
            @endforeach
        </section>

        @if ($quranProgressionSettings['profile'] === \App\Services\LearningProgressionService::PROFILE_LESSON_LEVEL)
            @php($lessonState = $lessonLevelSummary['progression'])
            @php($lessonEvidence = $lessonLevelSummary['evidence'])
            <section class="surface-panel overflow-hidden" data-lesson-level-progression-summary>
                <div class="border-b border-white/8 p-5 lg:p-6">
                    <div class="eyebrow">{{ __('learning_progression.lesson_summary.eyebrow') }}</div>
                    <div class="mt-2 flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h2 class="font-display text-2xl font-semibold text-white">{{ __('learning_progression.lesson_summary.title') }}</h2>
                            <p class="mt-2 text-sm leading-6 text-neutral-400">{{ __('learning_progression.lesson_summary.copy') }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if (! $lessonState && auth()->user()->can('learning-progression.manage'))
                                <button type="button" wire:click="assignLessonProgression" class="pill-link pill-link--accent">{{ __('learning_progression.lesson_summary.assign') }}</button>
                            @elseif ($lessonState?->status === 'active' && auth()->user()->can('learning-progression.manual-promote'))
                                <button type="button" wire:click="openManualPromotion" class="pill-link">{{ __('learning_progression.manual_promotion.action') }}</button>
                            @endif
                        </div>
                    </div>
                    @error('progression')<div class="mt-3 text-sm text-red-400">{{ $message }}</div>@enderror
                </div>

                @if (! $lessonState)
                    <div class="m-5 rounded-2xl border border-sky-300/20 bg-sky-300/10 px-4 py-4 text-sm leading-7 text-sky-100 lg:m-6">{{ __('learning_progression.lesson_summary.not_assigned') }}</div>
                @elseif ($lessonState->status === 'completed')
                    <div class="m-5 rounded-2xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-4 text-sm leading-7 text-emerald-100 lg:m-6">
                        <strong>{{ __('learning_progression.lesson_summary.completed') }}</strong>
                        <div>{{ __('learning_progression.lesson_summary.completed_copy', ['level' => $lessonState->currentLevel->name]) }}</div>
                    </div>
                @else
                    <div class="grid gap-px bg-white/8 md:grid-cols-3">
                        <div class="bg-neutral-950/60 p-5"><div class="kpi-label">{{ __('learning_progression.lesson_summary.current_level') }}</div><div class="mt-2 text-lg font-semibold text-white">{{ $lessonState->currentLevel->name }}</div><div class="mt-1 text-xs text-neutral-500">{{ __('learning_progression.lesson_summary.since', ['date' => \App\Support\DateDisplay::text($lessonState->level_started_at->format('d-m-Y'))]) }}</div></div>
                        <div class="bg-neutral-950/60 p-5"><div class="kpi-label">{{ __('learning_progression.lesson_summary.lessons') }}</div><div class="metric-value mt-2"><bdi dir="ltr">{{ number_format($lessonEvidence['delivered_lessons']) }}/{{ number_format($lessonEvidence['required_lessons']) }}</bdi></div><div class="mt-1 text-xs {{ $lessonEvidence['lessons_complete'] ? 'text-emerald-300' : 'text-amber-300' }}">{{ __('learning_progression.lesson_summary.'.($lessonEvidence['lessons_complete'] ? 'requirement_met' : 'requirement_pending')) }}</div></div>
                        <div class="bg-neutral-950/60 p-5"><div class="kpi-label">{{ __('learning_progression.lesson_summary.attendance') }}</div><div class="metric-value mt-2">{{ number_format($lessonEvidence['attendance_percentage'], 1) }}%</div><div class="mt-1 text-xs {{ $lessonEvidence['attendance_passed'] ? 'text-emerald-300' : 'text-amber-300' }}">{{ __('learning_progression.lesson_summary.required_percentage', ['percentage' => number_format($lessonEvidence['attendance_required'], 1)]) }}</div></div>
                    </div>
                    <div class="border-t border-white/8 p-5 lg:p-6">
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-white/8 bg-white/4 p-4">
                            <div><div class="text-sm font-semibold text-white">{{ __('learning_progression.lesson_summary.final_assessment') }}</div><div class="mt-1 text-sm text-neutral-400">{{ $lessonState->currentLevel->finalAssessment?->title }} · {{ __('learning_progression.lesson_summary.required_score', ['score' => number_format($lessonEvidence['assessment_required'], 2)]) }}</div></div>
                            <span class="status-chip {{ $lessonEvidence['assessment_passed'] ? 'border-emerald-300/25 bg-emerald-300/10 text-emerald-200' : 'border-amber-300/25 bg-amber-300/10 text-amber-200' }}">{{ $lessonEvidence['assessment_score'] !== null ? number_format($lessonEvidence['assessment_score'], 2) : __('learning_progression.lesson_summary.no_score') }}</span>
                        </div>
                    </div>
                @endif

                @if ($lessonState)
                    <div class="border-t border-white/8 p-5 lg:p-6" data-learning-progression-history>
                        <h3 class="text-base font-semibold text-white">{{ __('learning_progression.history.title') }}</h3>
                        <p class="mt-1 text-sm text-neutral-400">{{ __('learning_progression.history.copy') }}</p>
                        <div class="mt-4 space-y-3">
                            @foreach ($lessonState->history as $entry)
                                @php($snapshot = $entry->evidence ?? [])
                                <article class="rounded-2xl border border-white/8 bg-white/4 p-4" wire:key="learning-progression-history-{{ $entry->id }}">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <div class="font-semibold text-white">{{ __('learning_progression.history.events.'.$entry->event) }}</div>
                                            <div class="mt-1 text-sm text-neutral-400">
                                                @if ($entry->event === 'assigned')
                                                    {{ __('learning_progression.history.started_level', ['level' => $entry->toLevel?->name]) }}
                                                @elseif ($entry->event === 'assessment_attempted')
                                                    {{ __('learning_progression.history.assessment_for_level', ['level' => $entry->fromLevel?->name]) }}
                                                @else
                                                    {{ __('learning_progression.history.transition', ['from' => $entry->fromLevel?->name, 'to' => $entry->toLevel?->name ?? __('learning_progression.history.path_complete')]) }}
                                                @endif
                                            </div>
                                        </div>
                                        <div class="text-end text-xs text-neutral-500">
                                            <div>{{ \App\Support\DateDisplay::html($entry->occurred_at?->format('d-m-Y H:i')) }}</div>
                                            <div class="mt-1">{{ $entry->performer?->username ?: __('learning_progression.history.system') }}</div>
                                        </div>
                                    </div>

                                    @if ($snapshot !== [])
                                        <div class="mt-3 flex flex-wrap gap-2 text-xs">
                                            @if (array_key_exists('delivered_lessons', $snapshot))<span class="status-chip">{{ __('learning_progression.history.lesson_evidence', ['delivered' => number_format($snapshot['delivered_lessons']), 'required' => number_format($snapshot['required_lessons'] ?? 0)]) }}</span>@endif
                                            @if (array_key_exists('attendance_percentage', $snapshot))<span class="status-chip">{{ __('learning_progression.history.attendance_evidence', ['actual' => number_format((float) $snapshot['attendance_percentage'], 1), 'required' => number_format((float) ($snapshot['attendance_required'] ?? 0), 1)]) }}</span>@endif
                                            @if (array_key_exists('assessment_score', $snapshot))<span class="status-chip">{{ __('learning_progression.history.assessment_evidence', ['score' => $snapshot['assessment_score'] !== null ? number_format((float) $snapshot['assessment_score'], 2) : __('learning_progression.lesson_summary.no_score'), 'required' => number_format((float) ($snapshot['assessment_required'] ?? 0), 2)]) }}</span>@endif
                                        </div>
                                    @endif

                                    @if ($entry->reason)
                                        <div class="mt-3 rounded-xl border border-amber-300/15 bg-amber-300/10 px-3 py-2 text-sm leading-6 text-amber-100"><strong>{{ __('learning_progression.history.reason') }}</strong> {{ $entry->reason }}</div>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                    </div>
                @endif
            </section>
        @else
        <section class="surface-panel overflow-hidden" data-learning-progression-summary>
            <div class="border-b border-white/8 p-5 lg:p-6">
                <div class="eyebrow">{{ __('learning_progression.summary.eyebrow') }}</div>
                <div class="mt-2 flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="font-display text-2xl font-semibold text-white">{{ __('learning_progression.summary.title') }}</h2>
                        @if ($quranProgressionSummary['configured'])
                            <div class="mt-3 flex flex-wrap items-center gap-2" aria-label="{{ __('learning_progression.summary.path') }}">
                                @foreach ($quranProgressionSummary['stages'] as $stage)
                                    <span class="status-chip border-sky-300/20 bg-sky-300/10 text-sky-100">{{ __('learning_progression.stages.'.$stage) }}</span>
                                    @unless($loop->last)<span class="text-neutral-600" aria-hidden="true">→</span>@endunless
                                @endforeach
                            </div>
                        @endif
                    </div>
                    @if (! $quranProgressionSummary['configured'] && auth()->user()->can('learning-progression.manage'))
                        <a href="{{ route('settings.learning-progression') }}" wire:navigate class="pill-link pill-link--accent">{{ __('learning_progression.summary.configure') }}</a>
                    @endif
                </div>
            </div>

            @if (! $quranProgressionSummary['configured'])
                <div class="m-5 rounded-2xl border border-amber-300/20 bg-amber-300/10 px-4 py-4 text-sm leading-7 text-amber-100 lg:m-6">{{ __('learning_progression.summary.not_configured') }}</div>
            @else
                <div class="grid gap-px bg-white/8 sm:grid-cols-3">
                    <div class="bg-neutral-950/60 p-5">
                        <div class="kpi-label">{{ __('learning_progression.summary.current_stage') }}</div>
                        <div class="mt-2 text-base font-semibold text-white">
                            @if ($quranProgressionSummary['current_stage'])
                                {{ __('learning_progression.stages.'.$quranProgressionSummary['current_stage']) }}
                                @if ($quranProgressionSummary['current_juz_number'])
                                    <span class="text-neutral-400">· {{ __('workflow.common.labels.juz_number', ['number' => $quranProgressionSummary['current_juz_number']]) }}</span>
                                @endif
                            @else
                                {{ __('learning_progression.summary.no_progress') }}
                            @endif
                        </div>
                    </div>
                    <div class="bg-neutral-950/60 p-5"><div class="kpi-label">{{ __('learning_progression.summary.completed_juz') }}</div><div class="metric-value mt-2">{{ number_format($quranProgressionSummary['completed_juz_count']) }}</div></div>
                    <div class="bg-neutral-950/60 p-5"><div class="kpi-label">{{ __('learning_progression.summary.active_juz') }}</div><div class="metric-value mt-2">{{ number_format($quranProgressionSummary['active_juz_count']) }}</div></div>
                </div>
            @endif
        </section>

        <section class="surface-table student-juz-progress-table">
            <div class="admin-grid-meta"><div><div class="admin-grid-meta__title">{{ __('workflow.student_progress.juz_progress.title') }}</div><div class="admin-grid-meta__summary">{{ __('workflow.student_progress.juz_progress.summary', ['count' => number_format($quranJuzProgress->where('status', 'finished')->count())]) }}</div></div></div>
            @error('awqaf')<div class="flash-error mx-5 mb-4 px-4 py-3 text-sm">{{ $message }}</div>@enderror
            @if ($quranJuzProgress->isEmpty())<div class="admin-empty-state">{{ __('workflow.student_progress.juz_progress.empty') }}</div>@else
                <div class="table-scroll-region overflow-x-auto" data-table-scroll-region><table class="w-full text-sm" data-student-progress-juz-table><thead><tr>
                    <th class="px-5 py-4 text-left">{{ __('workflow.student_progress.juz_progress.headers.juz') }}</th>
                    <th class="px-5 py-4 text-left">{{ __('workflow.student_progress.juz_progress.headers.pages') }}</th>
                    @if ($quranProgressionSettings['partial_test_enabled'])<th class="px-5 py-4 text-left" data-progression-stage-column="partial">{{ __('workflow.student_progress.juz_progress.headers.partial_tests') }}</th>@endif
                    @if ($quranProgressionSettings['final_test_enabled'])<th class="px-5 py-4 text-left" data-progression-stage-column="final">{{ __('workflow.student_progress.juz_progress.headers.final_test') }}</th>@endif
                    <th class="px-5 py-4 text-center" data-juz-progress-status-heading>{{ __('workflow.student_progress.juz_progress.headers.status') }}</th>
                    <th class="admin-actions-column px-5 py-4 text-center" data-juz-progress-actions-heading>{{ __('workflow.student_progress.juz_progress.headers.actions') }}</th>
                </tr></thead><tbody class="divide-y divide-white/6">
                    @foreach ($quranJuzProgress as $row)<tr>
                        <td class="px-5 py-4 text-white">{{ __('workflow.common.labels.juz_number', ['number' => $row->juz->juz_number]) }}</td>
                        <td class="px-5 py-4">{{ $row->memorized_externally ? '' : number_format($row->memorized_pages) }}</td>
                        @if ($quranProgressionSettings['partial_test_enabled'])<td class="px-5 py-4">@if (! $row->memorized_externally && $row->partial_test_created)<bdi dir="ltr">{{ number_format($row->passed_parts) }}/4</bdi>@endif</td>@endif
                        @if ($quranProgressionSettings['final_test_enabled'])<td class="px-5 py-4" @if($row->latest_final_score !== null) title="{{ \App\Support\DateDisplay::text(trim(($row->latest_final_date?->format('d-m-Y') ?? '').' · '.($row->latest_final_course ?? ''))) }}" @endif>{{ ! $row->memorized_externally && $row->latest_final_score !== null ? \App\Support\PercentageFormatter::format($row->latest_final_score) : '' }}</td>@endif
                        <td class="px-5 py-4 text-center" data-juz-progress-status-cell><span class="status-chip {{ $row->memorized_externally ? 'border-emerald-300/25 bg-emerald-300/10 text-emerald-200' : $statusClass($row->status) }}" data-juz-progress-status>{{ $row->memorized_externally ? __('workflow.student_progress.juz_progress.statuses.memorized_before') : ($row->status === 'missing' ? __('workflow.student_progress.juz_progress.incomplete', ['count' => number_format($row->missing_pages->count())]) : __('workflow.student_progress.juz_progress.statuses.'.$row->status)) }}</span></td>
                        <td class="px-5 py-4 text-center" data-juz-progress-actions-cell>
                            @php($showMissingPagesAction = ! $row->memorized_externally && $row->next_stage === 'memorization' && $row->missing_pages->isNotEmpty())
                            @php($showAwqafAction = $quranProgressionSettings['awqaf_test_enabled'] && $row->enrollment && app(AccessScopeService::class)->canAccessEnrollment(auth()->user(), $row->enrollment) && $row->next_stage === 'awqaf' && ! $row->awqaf_passed && (auth()->user()->can('quran-awqaf-tests.record') || auth()->user()->can('quran-tests.record')))
                            @if ($row->awqaf_passed)
                                <span class="text-sm text-emerald-300">تم سبره بالأوقاف{{ \App\Support\DateDisplay::html($row->awqaf_passed_on ? ' · '.$row->awqaf_passed_on->format('d-m-Y') : '') }}</span>
                            @elseif ($showMissingPagesAction || $showAwqafAction)
                                <div class="flex flex-wrap justify-center gap-2">
                                    @if ($showMissingPagesAction)<button type="button" wire:click="showMissingPages({{ $row->juz->id }})" class="pill-link pill-link--compact" data-juz-progress-action>{{ __('workflow.student_progress.juz_progress.show_missing') }}</button>@endif
                                    @if ($showAwqafAction)<button type="button" wire:click="openAwqafTest({{ $row->juz->id }})" class="pill-link pill-link--compact" data-juz-progress-action>{{ __('workflow.student_progress.juz_progress.add_awqaf_test') }}</button>@endif
                                </div>
                            @else
                                <span class="block w-full text-center text-neutral-600" data-juz-progress-empty-action>-</span>
                            @endif
                        </td>
                    </tr>@endforeach
                </tbody></table></div>
            @endif
        </section>
        @endif

        <section class="grid gap-6 xl:grid-cols-2">
            @if($this->canViewProgressSection('memorization.view'))
                <x-student-progress-table :title="__('workflow.student_progress.memorization.latest_title')" :empty="$memorizationRows->isEmpty()" :empty-text="__('workflow.student_progress.memorization.empty')" view-all-action="memorization">
                    <x-slot:head><th data-table-number-column class="w-12 px-4 py-3 text-left" data-student-progress-number-column>#</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.memorization.headers.date') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.memorization.headers.page') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.memorization.headers.teacher') }}</th></x-slot:head>
                    @foreach ($memorizationRows->take(5) as $row)<tr><td class="px-4 py-3" data-student-progress-row-number>{{ $loop->iteration }}</td><td class="px-4 py-3">{{ \App\Support\DateDisplay::html($row->date?->format('d-m-Y')) }}</td><td class="px-4 py-3">{{ $row->page }}</td><td class="px-4 py-3">{{ $row->teacher ?: __('crud.common.not_available') }}</td></tr>@endforeach
                </x-student-progress-table>
            @endif
            @if($this->canViewProgressSection('points.view'))
                <x-student-progress-table :title="__('workflow.student_progress.points.latest_title')" :empty="$pointTransactions->isEmpty()" :empty-text="__('workflow.student_progress.points.empty')" view-all-action="points">
                    <x-slot:head><th data-table-number-column class="w-12 px-4 py-3 text-left" data-student-progress-number-column>#</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.points.headers.date') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.points.headers.type') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.points.headers.points') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.points.headers.notes') }}</th></x-slot:head>
                    @foreach ($pointTransactions->take(5) as $row)<tr><td class="px-4 py-3" data-student-progress-row-number>{{ $loop->iteration }}</td><td class="px-4 py-3">{{ \App\Support\DateDisplay::html($row->entered_at?->format('d-m-Y')) }}</td><td class="px-4 py-3">{{ $row->pointType?->name ?: __('crud.common.not_available') }}</td><td class="px-4 py-3">{{ number_format((int) $row->points) }}</td><td class="px-4 py-3">{{ $row->notes ?: __('crud.common.not_available') }}</td></tr>@endforeach
                </x-student-progress-table>
            @endif
        </section>

        <section class="grid gap-6 xl:grid-cols-2">
            @if($this->canViewProgressSection('assessment-results.view'))
                <x-student-progress-table :title="__('workflow.student_progress.assessments.title')" :empty="$assessmentResults->isEmpty()" :empty-text="__('workflow.student_progress.assessments.empty')" view-all-action="assessments">
                    <x-slot:head><th data-table-number-column class="w-12 px-4 py-3 text-left" data-student-progress-number-column>#</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.assessments.headers.assessment') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.assessments.headers.score') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.assessments.headers.status') }}</th></x-slot:head>
                    @foreach ($assessmentResults->take(5) as $row)<tr><td class="px-4 py-3" data-student-progress-row-number>{{ $loop->iteration }}</td><td class="px-4 py-3">{{ $row->assessment?->title ?: __('crud.common.not_available') }}</td><td class="px-4 py-3">{{ $row->score !== null ? number_format((float) $row->score, 2) : '' }}</td><td class="px-4 py-3"><span class="status-chip {{ $statusClass($row->status) }}">{{ __('workflow.common.result_status.'.$row->status) }}</span></td></tr>@endforeach
                </x-student-progress-table>
            @endif
            @if($this->canViewProgressSection('assessment-results.view'))
                <x-student-progress-table :title="__('workflow.student_progress.final_assessments.title')" :empty="$finalAssessmentResults->isEmpty()" :empty-text="__('workflow.student_progress.final_assessments.empty')" view-all-action="final-assessments">
                    <x-slot:head><th data-table-number-column class="w-12 px-4 py-3 text-left" data-student-progress-number-column>#</th><th class="w-1/2 px-4 py-3 text-left">{{ __('workflow.student_progress.assessments.headers.assessment') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.assessments.headers.score') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.assessments.headers.status') }}</th></x-slot:head>
                    @foreach ($finalAssessmentResults->take(5) as $row)<tr><td class="px-4 py-3" data-student-progress-row-number>{{ $loop->iteration }}</td><td class="px-4 py-3 font-medium">{{ $row->assessment?->title ?: __('crud.common.not_available') }}</td><td class="px-4 py-3">{{ $row->score !== null ? number_format((float) $row->score, 2) : '' }}</td><td class="px-4 py-3"><span class="status-chip {{ $statusClass($row->status) }}">{{ __('workflow.common.result_status.'.$row->status) }}</span></td></tr>@endforeach
                </x-student-progress-table>
            @endif
        </section>

        <section class="grid gap-6 xl:grid-cols-2">
            <x-student-progress-table :title="__('workflow.student_progress.enrollments.title')" :empty="$enrollments->isEmpty()" :empty-text="__('workflow.student_progress.enrollments.empty')" view-all-action="enrollments">
                <x-slot:head><th data-table-number-column class="w-12 px-4 py-3 text-left" data-student-progress-number-column>#</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.enrollments.headers.course') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.enrollments.headers.group') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.enrollments.headers.teacher') }}</th>@if($this->canViewProgressSection('points.view'))<th class="px-4 py-3 text-left">{{ __('workflow.student_progress.enrollments.headers.total_points') }}</th>@endif<th class="px-4 py-3 text-left">{{ __('workflow.student_progress.enrollments.headers.status') }}</th></x-slot:head>
                @foreach ($enrollments->take(5) as $row)<tr><td class="px-4 py-3" data-student-progress-row-number>{{ $loop->iteration }}</td><td class="px-4 py-3"><span class="record-course-name">{{ $row->group?->course?->name ?: __('crud.common.not_available') }}</span></td><td class="px-4 py-3">{{ $row->group?->name ?: __('crud.common.not_available') }}</td><td class="record-person-name px-4 py-3">{{ $row->group?->teacher ? trim($row->group->teacher->first_name.' '.$row->group->teacher->last_name) : __('crud.common.not_available') }}</td>@if($this->canViewProgressSection('points.view'))<td class="whitespace-nowrap px-4 py-3" data-enrollment-total-points="{{ $row->id }}">{{ number_format($enrollmentTotalPoints[$row->id]) }}</td>@endif<td class="px-4 py-3"><span class="status-chip {{ $statusClass($row->status) }}">{{ __('crud.common.status_options.'.$row->status) }}</span></td></tr>@endforeach
            </x-student-progress-table>
            <x-student-progress-table :title="__('workflow.student_progress.notes.title')" :empty="$parentVisibleNotes->isEmpty()" :empty-text="__('workflow.student_progress.notes.empty')" view-all-action="notes">
                <x-slot:head><th data-table-number-column class="w-12 px-4 py-3 text-left" data-student-progress-number-column>#</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.notes.headers.date') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.notes.headers.source') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.notes.headers.body') }}</th></x-slot:head>
                @foreach ($parentVisibleNotes->take(5) as $row)<tr><td class="px-4 py-3" data-student-progress-row-number>{{ $loop->iteration }}</td><td class="px-4 py-3">{{ \App\Support\DateDisplay::html($row->noted_at?->format('d-m-Y')) }}</td><td class="px-4 py-3">{{ $row->source }}</td><td class="px-4 py-3">{{ $row->body }}</td></tr>@endforeach
            </x-student-progress-table>
        </section>

        @include('livewire.students.partials.timeline')

        <x-admin.modal :show="$openDetails !== ''" :title="$openDetails === 'parent' ? __('workflow.student_progress.parent_details.title') : __('workflow.student_progress.actions.view_all')" close-method="closeDetails" max-width="fit" compact>
            @if (app(\App\Services\Landlord\CurrentModuleAccess::class)->enabled('parents') && $openDetails === 'parent' && $studentRecord->parentProfile)
                @php($parent = $studentRecord->parentProfile)
                <div class="space-y-4"><div class="student-parent-details__row grid gap-4 rounded-2xl border border-white/8 bg-white/4 p-4 md:grid-cols-3"><div><div class="kpi-label">{{ __('workflow.student_progress.profile.father_name') }}</div><div class="record-person-name mt-1 text-white">{{ $parent->father_name ?: '-' }}</div></div><div><div class="kpi-label">{{ __('workflow.student_progress.parent_details.father_work') }}</div><div class="mt-1 text-white">{{ $parent->father_work ?: '-' }}</div></div><div><div class="kpi-label">{{ __('workflow.student_progress.parent_details.father_phone') }}</div><div class="mt-1 text-white"><bdi dir="ltr" class="record-phone">{{ $parent->father_phone ?: '-' }}</bdi></div></div></div><div class="student-parent-details__row grid gap-4 rounded-2xl border border-white/8 bg-white/4 p-4 md:grid-cols-2"><div><div class="kpi-label">{{ __('workflow.student_progress.parent_details.mother_name') }}</div><div class="record-person-name mt-1 text-white">{{ $parent->mother_name ?: '-' }}</div></div><div><div class="kpi-label">{{ __('workflow.student_progress.parent_details.mother_phone') }}</div><div class="mt-1 text-white"><bdi dir="ltr" class="record-phone">{{ $parent->mother_phone ?: '-' }}</bdi></div></div></div><div class="student-parent-details__row grid gap-4 rounded-2xl border border-white/8 bg-white/4 p-4 md:grid-cols-2"><div><div class="kpi-label">{{ __('workflow.student_progress.parent_details.address') }}</div><div class="mt-1 text-white">{{ $parent->address ?: '-' }}</div></div><div><div class="kpi-label">{{ __('workflow.student_progress.parent_details.home_phone') }}</div><div class="mt-1 text-white"><bdi dir="ltr" class="record-phone">{{ $parent->home_phone ?: '-' }}</bdi></div></div></div></div>
            @elseif ($openDetails === 'memorization')
                <div class="surface-table" data-student-progress-generic-table><div class="overflow-x-auto"><table class="table-content text-sm"><thead><tr><th data-table-number-column class="w-12 px-4 py-3 text-left" data-student-progress-number-column>#</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.memorization.headers.date') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.memorization.headers.page') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.memorization.headers.teacher') }}</th></tr></thead><tbody>@foreach ($paginatedDetails as $row)<tr><td class="px-4 py-3" data-student-progress-row-number>{{ $paginatedDetails->firstItem() + $loop->index }}</td><td class="px-4 py-3">{{ \App\Support\DateDisplay::html($row->date?->format('d-m-Y')) }}</td><td class="px-4 py-3">{{ $row->page }}</td><td class="px-4 py-3">{{ $row->teacher ?: '-' }}</td></tr>@endforeach</tbody></table></div></div>
            @elseif ($openDetails === 'points')
                <div class="surface-table" data-student-progress-generic-table><div class="overflow-x-auto"><table class="table-content text-sm"><thead><tr><th data-table-number-column class="w-12 px-4 py-3 text-left" data-student-progress-number-column>#</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.points.headers.date') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.points.headers.type') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.points.headers.points') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.points.headers.notes') }}</th></tr></thead><tbody>@foreach ($paginatedDetails as $row)<tr><td class="px-4 py-3" data-student-progress-row-number>{{ $paginatedDetails->firstItem() + $loop->index }}</td><td class="px-4 py-3">{{ \App\Support\DateDisplay::html($row->entered_at?->format('d-m-Y')) }}</td><td class="px-4 py-3">{{ $row->pointType?->name ?: '-' }}</td><td class="px-4 py-3">{{ number_format((int) $row->points) }}</td><td class="px-4 py-3">{{ $row->notes ?: '-' }}</td></tr>@endforeach</tbody></table></div></div>
            @elseif ($openDetails === 'assessments')
                <div class="surface-table" data-student-progress-generic-table><div class="overflow-x-auto"><table class="table-content text-sm"><thead><tr><th data-table-number-column class="w-12 px-4 py-3 text-left" data-student-progress-number-column>#</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.assessments.headers.assessment') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.assessments.headers.score') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.assessments.headers.status') }}</th></tr></thead><tbody>@foreach ($paginatedDetails as $row)<tr><td class="px-4 py-3" data-student-progress-row-number>{{ $paginatedDetails->firstItem() + $loop->index }}</td><td class="px-4 py-3">{{ $row->assessment?->title ?: '-' }}</td><td class="px-4 py-3">{{ $row->score }}</td><td class="px-4 py-3">{{ __('workflow.common.result_status.'.$row->status) }}</td></tr>@endforeach</tbody></table></div></div>
            @elseif ($openDetails === 'final-assessments')
                <div class="surface-table" data-student-progress-generic-table><div class="overflow-x-auto"><table class="text-sm"><thead><tr><th data-table-number-column class="w-12 px-3 py-2 text-left" data-student-progress-number-column>#</th><th class="w-[65%] px-3 py-2 text-left">{{ __('workflow.student_progress.assessments.headers.assessment') }}</th><th class="w-28 min-w-28 px-3 py-2 text-left">{{ __('workflow.student_progress.assessments.headers.score') }}</th><th class="w-28 min-w-28 px-3 py-2 text-left">{{ __('workflow.student_progress.assessments.headers.status') }}</th></tr></thead><tbody>@foreach ($paginatedDetails as $row)<tr><td class="px-3 py-2" data-student-progress-row-number>{{ $paginatedDetails->firstItem() + $loop->index }}</td><td class="px-3 py-2 font-medium">{{ $row->assessment?->title ?: '-' }}</td><td class="px-3 py-2">{{ $row->score !== null ? number_format((float) $row->score, 2) : '-' }}</td><td class="px-3 py-2">{{ __('workflow.common.result_status.'.$row->status) }}</td></tr>@endforeach</tbody></table></div></div>
            @elseif ($openDetails === 'enrollments')
                <div class="surface-table" data-student-progress-generic-table><div class="overflow-x-auto"><table class="table-content text-sm"><thead><tr><th data-table-number-column class="w-12 px-4 py-3 text-left" data-student-progress-number-column>#</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.enrollments.headers.course') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.enrollments.headers.group') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.enrollments.headers.teacher') }}</th>@if($this->canViewProgressSection('points.view'))<th class="px-4 py-3 text-left">{{ __('workflow.student_progress.enrollments.headers.total_points') }}</th>@endif<th class="px-4 py-3 text-left">{{ __('workflow.student_progress.enrollments.headers.status') }}</th></tr></thead><tbody>@foreach ($paginatedDetails as $row)<tr><td class="px-4 py-3" data-student-progress-row-number>{{ $paginatedDetails->firstItem() + $loop->index }}</td><td class="px-4 py-3"><span class="record-course-name">{{ $row->group?->course?->name ?: '-' }}</span></td><td class="px-4 py-3">{{ $row->group?->name ?: '-' }}</td><td class="record-person-name px-4 py-3">{{ $row->group?->teacher ? trim($row->group->teacher->first_name.' '.$row->group->teacher->last_name) : '-' }}</td>@if($this->canViewProgressSection('points.view'))<td class="whitespace-nowrap px-4 py-3" data-enrollment-total-points="{{ $row->id }}">{{ number_format($enrollmentTotalPoints[$row->id]) }}</td>@endif<td class="px-4 py-3">{{ __('crud.common.status_options.'.$row->status) }}</td></tr>@endforeach</tbody></table></div></div>
            @elseif ($openDetails === 'notes')
                <div class="surface-table" data-student-progress-generic-table><div class="overflow-x-auto"><table class="table-content text-sm"><thead><tr><th data-table-number-column class="w-12 px-4 py-3 text-left" data-student-progress-number-column>#</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.notes.headers.date') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.notes.headers.source') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.notes.headers.body') }}</th></tr></thead><tbody>@foreach ($paginatedDetails as $row)<tr><td class="px-4 py-3" data-student-progress-row-number>{{ $paginatedDetails->firstItem() + $loop->index }}</td><td class="px-4 py-3">{{ \App\Support\DateDisplay::html($row->noted_at?->format('d-m-Y')) }}</td><td class="px-4 py-3">{{ $row->source }}</td><td class="px-4 py-3">{{ $row->body }}</td></tr>@endforeach</tbody></table></div></div>
            @endif
            @if ($openDetails !== 'parent' && $paginatedDetails->hasPages())<div class="mt-4">{{ $paginatedDetails->links() }}</div>@endif
        </x-admin.modal>

        <x-admin.modal :show="$selectedMissingJuz !== null" :title="$selectedMissingJuz ? __('workflow.student_progress.juz_progress.missing_title', ['juz' => $selectedMissingJuz->juz->juz_number]) : ''" :description="__('workflow.student_progress.juz_progress.missing_subtitle')" close-method="closeMissingPages" max-width="2xl">
            @if ($selectedMissingJuz)
                <div class="student-progress-missing-pages" data-student-progress-missing-pages>
                    <div class="overflow-x-auto">
                        <table class="student-progress-missing-pages__table" dir="rtl">
                            <tbody>
                                @foreach ($selectedMissingJuz->missing_pages->values()->chunk(5) as $missingPageRow)
                                    <tr>
                                        @foreach ($missingPageRow as $missingPage)
                                            <td>{{ number_format((int) $missingPage) }}</td>
                                        @endforeach
                                        @for ($emptyCell = $missingPageRow->count(); $emptyCell < 5; $emptyCell++)
                                            <td class="student-progress-missing-pages__empty" aria-hidden="true"></td>
                                        @endfor
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </x-admin.modal>

        <x-admin.modal :show="$showAwqafTestModal" :title="__('workflow.student_progress.juz_progress.add_awqaf_test')" close-method="closeAwqafTest" max-width="2xl">
            <form wire:submit="saveAwqafTest" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.tested_on') }}</label><input wire:model="awqafTestedOn" type="date" class="w-full rounded-xl px-4 py-3 text-sm">@error('awqafTestedOn')<div class="mt-1 text-sm text-red-400">{{ $message }}</div>@enderror</div>
                    <div><label class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.juz') }}</label><div class="rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white">{{ $awqafJuzId ? __('workflow.common.labels.juz_number', ['number' => $quranJuzProgress->first(fn ($row) => (int) $row->juz->id === (int) $awqafJuzId)?->juz->juz_number]) : '-' }}</div>@error('awqafJuzId')<div class="mt-1 text-sm text-red-400">{{ $message }}</div>@enderror</div>
                    <div><label class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.score') }}</label><input wire:model="awqafScore" type="number" min="0" max="100" step="0.01" @required($awqafStatus === 'passed') class="w-full rounded-xl px-4 py-3 text-sm">@error('awqafScore')<div class="mt-1 text-sm text-red-400">{{ $message }}</div>@enderror</div>
                    <div><label class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.result_status') }}</label><select wire:model.live="awqafStatus" class="w-full rounded-xl px-4 py-3 text-sm"><option value="passed">{{ __('workflow.common.result_status.passed') }}</option><option value="failed">{{ __('workflow.common.result_status.failed') }}</option><option value="cancelled">{{ __('workflow.common.result_status.cancelled') }}</option></select>@error('awqafStatus')<div class="mt-1 text-sm text-red-400">{{ $message }}</div>@enderror</div>
                </div>
                @error('awqafEnrollmentId')<div class="text-sm text-red-400">{{ $message }}</div>@enderror
                <div class="flex justify-end gap-3"><x-admin.save-button :label="__('workflow.common.actions.save_quran_test')" data-student-progress-awqaf-save-action /></div>
            </form>
        </x-admin.modal>

        <x-admin.modal :show="$showManualPromotionModal" :title="__('learning_progression.manual_promotion.title')" :description="__('learning_progression.manual_promotion.copy')" close-method="closeManualPromotion" max-width="xl">
            <form wire:submit="manuallyPromoteLessonLevel" class="space-y-4">
                <div class="rounded-xl border border-amber-300/20 bg-amber-300/10 px-4 py-3 text-sm leading-6 text-amber-100">{{ __('learning_progression.manual_promotion.warning') }}</div>
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('learning_progression.manual_promotion.reason') }}</label>
                    <textarea wire:model="manualPromotionReason" rows="4" maxlength="2000" class="w-full rounded-xl" placeholder="{{ __('learning_progression.manual_promotion.reason_placeholder') }}"></textarea>
                    @error('manualPromotionReason')<div class="mt-1 text-sm text-red-400">{{ $message }}</div>@enderror
                </div>
                <div class="flex justify-end"><x-admin.save-button :label="__('learning_progression.manual_promotion.confirm')" /></div>
            </form>
        </x-admin.modal>

        <x-admin.modal :show="$showAwqafUnavailableModal" :hide-header="true" max-width="md">
            <div class="awqaf-unavailable-warning" role="alert" data-awqaf-unavailable-warning>
                <div class="awqaf-unavailable-warning__octagon" aria-hidden="true">
                    <div class="awqaf-unavailable-warning__octagon-inner">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" d="M7 7l10 10M17 7 7 17" /></svg>
                    </div>
                </div>
                <p>{{ __('workflow.student_progress.juz_progress.awqaf_unavailable') }}</p>
                <button type="button" wire:click="closeAwqafUnavailable" class="pill-link awqaf-unavailable-warning__close" data-modal-action-icon-ignore>{{ __('crud.common.actions.close') }}</button>
            </div>
        </x-admin.modal>
    @else
        <section class="surface-panel p-6"><div class="admin-empty-state">{{ $studentOptions->isEmpty() ? __('workflow.student_progress.selection.no_students') : __('workflow.student_progress.selection.empty') }}</div></section>
    @endif
</div>
