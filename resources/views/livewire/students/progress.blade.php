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
use App\Services\PointLedgerService;
use App\Services\QuranProgressionService;
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

    public function with(): array
    {
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
        $enrollments = $this->scopeEnrollmentsQuery(
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

        $memorizationSessions = auth()->user()->can('memorization.view')
            ? $this->scopeMemorizationSessionsQuery(
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

        $assessmentResults = auth()->user()->can('assessment-results.view')
            ? $this->scopeAssessmentResultsQuery(
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

        $awqafTests = auth()->user()->can('quran-awqaf-tests.view') || auth()->user()->can('quran-tests.view')
            ? $this->scopeQuranTestsQuery(
                QuranTest::query()
                    ->with(['juz'])
                    ->where('student_id', $studentRecord->id)
                    ->whereHas('type', fn ($query) => $query->where('code', 'awqaf'))
                    ->when($enrollmentIds === [], fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('enrollment_id', $enrollmentIds))
            )->orderByDesc('tested_on')->orderByDesc('id')->get()
            : collect();
        $passedAwqafTestsByJuz = $awqafTests->where('status', 'passed')->groupBy('juz_id');

        $partialTests = auth()->user()->can('quran-partial-tests.view')
            ? $this->scopeQuranPartialTestsQuery(
                QuranPartialTest::query()
                    ->with(['enrollment', 'parts'])
                    ->where('student_id', $studentRecord->id)
                    ->when($enrollmentIds === [], fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('enrollment_id', $enrollmentIds))
            )->get()
            : collect();
        $finalTests = auth()->user()->can('quran-final-tests.view')
            ? $this->scopeQuranFinalTestsQuery(
                QuranFinalTest::query()
                    ->with(['attempts', 'enrollment.group.course'])
                    ->where('student_id', $studentRecord->id)
                    ->when($enrollmentIds === [], fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('enrollment_id', $enrollmentIds))
            )->get()
            : collect();

        $pointTransactions = auth()->user()->can('points.view') && $highlightEnrollmentIds !== []
            ? $this->scopePointTransactionsQuery(
                PointTransaction::query()
                    ->with(['pointType'])
                    ->where('student_id', $studentRecord->id)
                    ->whereIn('enrollment_id', $highlightEnrollmentIds)
            )->latest('entered_at')->latest('id')->get()->filter(fn (PointTransaction $transaction) => $transaction->isEffectivelyActive())->values()
            : collect();

        $parentVisibleNotes = $this->scopeStudentNotesQuery(
            StudentNote::query()
                ->where('student_id', $studentRecord->id)
                ->where('visibility', 'visible_to_parent')
                ->when($enrollmentIds === [], fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('enrollment_id', $enrollmentIds))
        )->latest('noted_at')->latest('id')->get();

        $attendanceDays = auth()->user()->can('attendance.student.view') && $highlightEnrollmentIds !== []
            ? $this->scopeStudentAttendanceRecordsQuery(
                StudentAttendanceRecord::query()
                    ->whereHas('status', fn ($query) => $query->where('is_present', true))
                    ->whereIn('enrollment_id', $highlightEnrollmentIds)
            )->distinct('group_attendance_day_id')->count('group_attendance_day_id')
            : 0;

        $pageSet = $generalPages->flip();
        $externalJuzIds = $studentRecord->externalMemorizedJuzs->pluck('id')->map(fn ($id) => (int) $id)->all();
        $quranJuzProgress = QuranJuz::query()->orderBy('juz_number')->get()
            ->map(function (QuranJuz $juz) use ($pageSet, $partialTests, $finalTests, $enrollments, $passedAwqafTestsByJuz, $externalJuzIds) {
                $memorizedExternally = in_array((int) $juz->id, $externalJuzIds, true);
                $pages = collect(range((int) $juz->from_page, (int) $juz->to_page));
                $missingPages = $pages->reject(fn (int $page) => $pageSet->has($page))->values();
                $juzPartialTests = $partialTests->where('juz_id', $juz->id);
                $passedParts = $juzPartialTests->flatMap->parts->where('status', 'passed')->pluck('part_number')->unique()->count();
                $juzFinalTests = $finalTests->where('juz_id', $juz->id);
                $latestFinalAttempt = $juzFinalTests->flatMap->attempts
                    ->sortByDesc(fn ($attempt) => sprintf('%010d-%010d', $attempt->tested_on?->timestamp ?? 0, $attempt->id))
                    ->first();
                $latestAwqafTest = $passedAwqafTestsByJuz->get($juz->id, collect())->sortByDesc('tested_on')->first();
                $finalMade = $latestFinalAttempt !== null;
                $finalPassed = $juzFinalTests->contains('status', 'passed') || $juzFinalTests->flatMap->attempts->contains('status', 'passed');
                $status = $finalPassed ? 'finished' : ($missingPages->isNotEmpty() ? 'missing' : 'awaiting');

                return (object) [
                    'juz' => $juz,
                    'memorized_externally' => $memorizedExternally,
                    'memorized_pages' => $pages->count() - $missingPages->count(),
                    'missing_pages' => $missingPages,
                    'passed_parts' => $passedParts,
                    'partial_test_created' => $juzPartialTests->isNotEmpty(),
                    'latest_final_score' => $latestFinalAttempt?->score,
                    'latest_final_date' => $latestFinalAttempt?->tested_on,
                    'latest_final_course' => $juzFinalTests->first()?->enrollment?->group?->course?->name,
                    'final_made' => $finalMade,
                    'final_passed' => $finalPassed,
                    'awqaf_passed' => $latestAwqafTest !== null,
                    'awqaf_passed_on' => $latestAwqafTest?->tested_on,
                    'status' => $memorizedExternally ? 'memorized_before' : $status,
                    'enrollment' => $juzFinalTests->first()?->enrollment ?: $juzPartialTests->first()?->enrollment ?: $enrollments->first(),
                ];
            })
            ->filter(fn ($row) => $row->memorized_externally || $row->memorized_pages > 0 || $row->passed_parts > 0 || $row->latest_final_score !== null)
            ->values();
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

        return [
            'studentOptions' => $studentOptions,
            'studentRecord' => $studentRecord,
            'activeEnrollment' => $activeEnrollment,
            'enrollments' => $visibleEnrollments,
            'memorizationRows' => $memorizationRows,
            'assessmentResults' => $nonFinalAssessmentResults,
            'finalAssessmentResults' => $finalAssessmentResults,
            'awqafTests' => $awqafTests,
            'pointTransactions' => $pointTransactions,
            'parentVisibleNotes' => $parentVisibleNotes,
            'quranJuzProgress' => $quranJuzProgress,
            'selectedMissingJuz' => $selectedMissingJuz,
            'paginatedDetails' => $paginatedDetails,
            'stats' => [
                'attendance_days' => $attendanceDays,
                'memorized_pages' => $highlightPages->count(),
                'quran_partial_tests' => $partialTests->whereIn('enrollment_id', $highlightEnrollmentIds)->count(),
                'quran_final_tests' => $finalTests->whereIn('enrollment_id', $highlightEnrollmentIds)->count(),
                'points' => (int) $highlightEnrollments->sum('final_points_cached'),
            ],
        ];
    }

    protected function setCurrentStudent(int $studentId): void
    {
        $student = Student::query()->with(['gradeLevel', 'parentProfile', 'quranCurrentJuz'])->findOrFail($studentId);
        $this->authorizeScopedStudentAccess($student);
        $this->currentStudent = $student;
        $this->selectedStudentId = $student->id;
        $this->missingJuzId = null;
        $this->openDetails = '';
        $this->showAwqafUnavailableModal = false;
        $this->closeAwqafTest();
    }

    protected function studentOptionsQuery()
    {
        return $this->scopeStudentsQuery(
            Student::query()
                ->with('parentProfile:id,father_name')
                ->select(['id', 'parent_id', 'first_name', 'last_name', 'student_number'])
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->orderBy('id')
        );
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
                <select id="student-progress-student" wire:model.live="selectedStudentId" data-search-input="true" data-open-on-focus="true" data-hide-placeholder-option="true" data-scroll-to-selected="false" data-clear-search-after-select="true" data-defer-clear-after-select="true" data-search-placeholder="{{ __('workflow.student_progress.selection.search_placeholder') }}" class="w-full rounded-xl px-4 py-3 text-sm">
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
                @can('students.update')
                    <label class="student-progress-profile__photo group cursor-pointer overflow-hidden rounded-3xl border border-white/10 bg-white/5" data-student-progress-photo-upload title="{{ __('workflow.student_progress.actions.update_photo') }}">
                        <input wire:model="progressPhotoUpload" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only">
                        @if ($studentPhotoUrl)<img src="{{ $studentPhotoUrl }}" alt="{{ $studentRecord->full_name }}" class="student-progress-profile__photo-image">@else<div class="student-progress-profile__photo-fallback">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($studentRecord->first_name ?: 'S', 0, 1)) }}</div>@endif
                        <span class="absolute inset-x-2 bottom-2 rounded-xl bg-black/65 px-2 py-1.5 text-center text-xs font-medium text-white opacity-0 transition group-hover:opacity-100 group-focus-within:opacity-100">{{ __('workflow.student_progress.actions.update_photo') }}</span>
                        <span wire:loading.flex wire:target="progressPhotoUpload" class="absolute inset-0 items-center justify-center bg-black/65"><span class="size-9 animate-spin rounded-full border-2 border-white/30 border-t-white" aria-hidden="true"></span></span>
                    </label>
                @else
                    <div class="student-progress-profile__photo overflow-hidden rounded-3xl border border-white/10 bg-white/5">
                        @if ($studentPhotoUrl)<img src="{{ $studentPhotoUrl }}" alt="{{ $studentRecord->full_name }}" class="student-progress-profile__photo-image">@else<div class="student-progress-profile__photo-fallback">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($studentRecord->first_name ?: 'S', 0, 1)) }}</div>@endif
                    </div>
                @endcan
                <div class="student-progress-profile__fields grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-2xl border border-white/8 bg-white/4 p-3"><div class="kpi-label">{{ __('workflow.student_progress.profile.student_no') }}</div><div class="mt-2 text-sm font-semibold text-white">{{ $studentRecord->student_number ?: __('crud.common.not_available') }}</div></div>
                    <div class="rounded-2xl border border-white/8 bg-white/4 p-3"><div class="kpi-label">{{ __('workflow.student_progress.profile.student_name') }}</div><div class="mt-2 text-sm font-semibold text-white">{{ $studentRecord->full_name }}</div></div>
                    <div class="relative rounded-2xl border border-white/8 bg-white/4 p-3"><div class="kpi-label pe-7">{{ __('workflow.student_progress.profile.father_name') }}</div>@if ($studentRecord->parentProfile)<button type="button" wire:click="showDetails('parent')" class="absolute end-2 top-2 inline-flex h-6 w-6 items-center justify-center rounded-lg border border-white/10 bg-white/5 text-neutral-300 transition hover:bg-white/10 hover:text-white" title="{{ __('workflow.student_progress.actions.details') }}" aria-label="{{ __('workflow.student_progress.actions.details') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-3.5 w-3.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12Z"/><circle cx="12" cy="12" r="2.25"/></svg></button>@endif<div class="mt-2 text-sm font-semibold text-white">{{ $studentRecord->parentProfile?->father_name ?: __('crud.common.not_available') }}</div></div>
                    <div class="grid grid-cols-2 overflow-hidden rounded-2xl border border-white/8 bg-white/4"><div class="min-w-0 p-3"><div class="kpi-label">{{ __('workflow.student_progress.profile.grade') }}</div><div class="mt-2 truncate text-sm font-semibold text-white">{{ $studentRecord->gradeLevel?->name ?: __('crud.common.not_available') }}</div></div><div class="student-progress-profile__birth-year min-w-0 p-3"><div class="kpi-label">{{ __('workflow.student_progress.profile.birth_year') }}</div><div class="mt-2 truncate text-sm font-semibold text-white">{{ $studentRecord->birth_date?->format('Y') ?: __('crud.common.not_available') }}</div></div></div>
                    <div class="rounded-2xl border border-white/8 bg-white/4 p-3"><div class="kpi-label">{{ __('workflow.student_progress.profile.phone') }}</div><div class="mt-2 text-sm font-semibold text-white"><bdi dir="ltr">{{ $studentRecord->user?->phone ?: __('crud.common.not_available') }}</bdi></div></div>
                    <div class="rounded-2xl border border-white/8 bg-white/4 p-3"><div class="kpi-label">{{ __('workflow.student_progress.profile.school') }}</div><div class="mt-2 text-sm font-semibold text-white">{{ $studentRecord->school_name ?: __('crud.common.not_available') }}</div></div>
                    <div class="rounded-2xl border border-white/8 bg-white/4 p-3"><div class="kpi-label">{{ __('workflow.student_progress.profile.group') }}</div><div class="mt-2 text-sm font-semibold text-white">{{ $activeEnrollment?->group?->name ?: __('crud.common.not_available') }}</div></div>
                    <div class="rounded-2xl border border-white/8 bg-white/4 p-3"><div class="kpi-label">{{ __('workflow.student_progress.profile.current_juz') }}</div><div class="mt-2 text-sm font-semibold text-white">{{ $studentRecord->quranCurrentJuz ? __('workflow.common.labels.juz_number', ['number' => $studentRecord->quranCurrentJuz->juz_number]) : __('crud.common.not_available') }}</div></div>
                </div>
            </div>
            @error('progressPhotoUpload')<div class="mt-3 text-sm text-red-400">{{ $message }}</div>@enderror
        </section>

        <section class="mobile-compact-highlights mobile-compact-highlights--five grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            @foreach ([
                'attendance_days' => 'attendance_days',
                'memorized_pages' => 'memorized_pages',
                'quran_partial_tests' => 'quran_partial_tests',
                'quran_final_tests' => 'quran_final_tests',
                'points' => 'points',
            ] as $key => $label)
                <article class="stat-card"><div class="kpi-label">{{ __('workflow.student_progress.stats.'.$label) }}</div><div class="metric-value mt-3">{{ number_format($stats[$key]) }}</div></article>
            @endforeach
        </section>

        <section class="surface-table student-juz-progress-table">
            <div class="admin-grid-meta"><div><div class="admin-grid-meta__title">{{ __('workflow.student_progress.juz_progress.title') }}</div><div class="admin-grid-meta__summary">{{ __('workflow.student_progress.juz_progress.summary', ['count' => number_format($quranJuzProgress->where('status', 'finished')->count())]) }}</div></div></div>
            @error('awqaf')<div class="flash-error mx-5 mb-4 px-4 py-3 text-sm">{{ $message }}</div>@enderror
            @if ($quranJuzProgress->isEmpty())<div class="admin-empty-state">{{ __('workflow.student_progress.juz_progress.empty') }}</div>@else
                <div class="responsive-records-mobile">
                    @foreach ($quranJuzProgress as $row)
                        @php($showMissingPagesAction = ! $row->memorized_externally && $row->status !== 'finished' && $row->missing_pages->isNotEmpty())
                        @php($showAwqafAction = $row->enrollment && ($row->final_passed || $row->memorized_externally) && ! $row->awqaf_passed && (auth()->user()->can('quran-awqaf-tests.record') || auth()->user()->can('quran-tests.record')))
                        <article class="mobile-record-card">
                            <div class="mobile-record-card__header">
                                <div class="mobile-record-card__title">{{ __('workflow.common.labels.juz_number', ['number' => $row->juz->juz_number]) }}</div>
                                <span class="status-chip {{ $row->memorized_externally ? 'border-emerald-300/25 bg-emerald-300/10 text-emerald-200' : $statusClass($row->status) }}" data-juz-progress-status>{{ $row->memorized_externally ? __('workflow.student_progress.juz_progress.statuses.memorized_before') : ($row->status === 'missing' ? __('workflow.student_progress.juz_progress.incomplete', ['count' => number_format($row->missing_pages->count())]) : __('workflow.student_progress.juz_progress.statuses.'.$row->status)) }}</span>
                            </div>

                            <dl class="mobile-record-card__details mobile-record-card__details--three">
                                <div>
                                    <dt>{{ __('workflow.student_progress.juz_progress.headers.pages') }}</dt>
                                    <dd>{{ $row->memorized_externally ? '—' : number_format($row->memorized_pages) }}</dd>
                                </div>
                                <div>
                                    <dt>{{ __('workflow.student_progress.juz_progress.headers.partial_tests') }}</dt>
                                    <dd>@if (! $row->memorized_externally && $row->partial_test_created)<bdi dir="ltr">{{ number_format($row->passed_parts) }}/4</bdi>@else — @endif</dd>
                                </div>
                                <div>
                                    <dt>{{ __('workflow.student_progress.juz_progress.headers.final_test') }}</dt>
                                    <dd>{{ ! $row->memorized_externally && $row->latest_final_score !== null ? \App\Support\PercentageFormatter::format($row->latest_final_score) : '—' }}</dd>
                                </div>
                            </dl>

                            @if ($row->awqaf_passed || $showMissingPagesAction || $showAwqafAction)
                                <div class="mobile-record-card__actions">
                                    @if ($row->awqaf_passed)
                                        <span class="text-sm text-emerald-300">تم سبره بالأوقاف{{ $row->awqaf_passed_on ? ' · '.$row->awqaf_passed_on->format('d-m-Y') : '' }}</span>
                                    @else
                                        @if ($showMissingPagesAction)<button type="button" wire:click="showMissingPages({{ $row->juz->id }})" class="pill-link pill-link--compact" data-juz-progress-action>{{ __('workflow.student_progress.juz_progress.show_missing') }}</button>@endif
                                        @if ($showAwqafAction)<button type="button" wire:click="openAwqafTest({{ $row->juz->id }})" class="pill-link pill-link--compact" data-juz-progress-action>{{ __('workflow.student_progress.juz_progress.add_awqaf_test') }}</button>@endif
                                    @endif
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>

                <div class="responsive-records-desktop table-scroll-region overflow-x-auto" data-table-scroll-region><table class="w-full table-fixed text-sm" data-student-progress-juz-table><thead><tr>
                    <th class="px-5 py-4 text-left">{{ __('workflow.student_progress.juz_progress.headers.juz') }}</th>
                    <th class="px-5 py-4 text-left">{{ __('workflow.student_progress.juz_progress.headers.pages') }}</th>
                    <th class="px-5 py-4 text-left">{{ __('workflow.student_progress.juz_progress.headers.partial_tests') }}</th>
                    <th class="px-5 py-4 text-left">{{ __('workflow.student_progress.juz_progress.headers.final_test') }}</th>
                    <th class="px-5 py-4 text-center" data-juz-progress-status-heading>{{ __('workflow.student_progress.juz_progress.headers.status') }}</th>
                    <th class="admin-actions-column px-5 py-4 text-center" data-juz-progress-actions-heading>{{ __('workflow.student_progress.juz_progress.headers.actions') }}</th>
                </tr></thead><tbody class="divide-y divide-white/6">
                    @foreach ($quranJuzProgress as $row)<tr>
                        <td class="px-5 py-4 text-white">{{ __('workflow.common.labels.juz_number', ['number' => $row->juz->juz_number]) }}</td>
                        <td class="px-5 py-4">{{ $row->memorized_externally ? '' : number_format($row->memorized_pages) }}</td>
                        <td class="px-5 py-4">@if (! $row->memorized_externally && $row->partial_test_created)<bdi dir="ltr">{{ number_format($row->passed_parts) }}/4</bdi>@endif</td>
                        <td class="px-5 py-4" @if($row->latest_final_score !== null) title="{{ trim(($row->latest_final_date?->format('d-m-Y') ?? '').' · '.($row->latest_final_course ?? '')) }}" @endif>{{ ! $row->memorized_externally && $row->latest_final_score !== null ? \App\Support\PercentageFormatter::format($row->latest_final_score) : '' }}</td>
                        <td class="px-5 py-4 text-center" data-juz-progress-status-cell><span class="status-chip {{ $row->memorized_externally ? 'border-emerald-300/25 bg-emerald-300/10 text-emerald-200' : $statusClass($row->status) }}" data-juz-progress-status>{{ $row->memorized_externally ? __('workflow.student_progress.juz_progress.statuses.memorized_before') : ($row->status === 'missing' ? __('workflow.student_progress.juz_progress.incomplete', ['count' => number_format($row->missing_pages->count())]) : __('workflow.student_progress.juz_progress.statuses.'.$row->status)) }}</span></td>
                        <td class="px-5 py-4 text-center" data-juz-progress-actions-cell>
                            @php($showMissingPagesAction = ! $row->memorized_externally && $row->status !== 'finished' && $row->missing_pages->isNotEmpty())
                            @php($showAwqafAction = $row->enrollment && ($row->final_passed || $row->memorized_externally) && ! $row->awqaf_passed && (auth()->user()->can('quran-awqaf-tests.record') || auth()->user()->can('quran-tests.record')))
                            @if ($row->awqaf_passed)
                                <span class="text-sm text-emerald-300">تم سبره بالأوقاف{{ $row->awqaf_passed_on ? ' · '.$row->awqaf_passed_on->format('d-m-Y') : '' }}</span>
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

        <section class="grid gap-6 xl:grid-cols-2">
            @can('memorization.view')
                <x-student-progress-table :title="__('workflow.student_progress.memorization.latest_title')" :empty="$memorizationRows->isEmpty()" :empty-text="__('workflow.student_progress.memorization.empty')" view-all-action="memorization">
                    <x-slot:head><th class="w-12 px-4 py-3 text-left" data-student-progress-number-column>#</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.memorization.headers.date') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.memorization.headers.page') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.memorization.headers.teacher') }}</th></x-slot:head>
                    @foreach ($memorizationRows->take(5) as $row)<tr><td class="px-4 py-3" data-student-progress-row-number>{{ $loop->iteration }}</td><td class="px-4 py-3">{{ $row->date?->format('d-m-Y') }}</td><td class="px-4 py-3">{{ $row->page }}</td><td class="px-4 py-3">{{ $row->teacher ?: __('crud.common.not_available') }}</td></tr>@endforeach
                </x-student-progress-table>
            @endcan
            @can('points.view')
                <x-student-progress-table :title="__('workflow.student_progress.points.latest_title')" :empty="$pointTransactions->isEmpty()" :empty-text="__('workflow.student_progress.points.empty')" view-all-action="points">
                    <x-slot:head><th class="w-12 px-4 py-3 text-left" data-student-progress-number-column>#</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.points.headers.date') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.points.headers.type') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.points.headers.points') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.points.headers.notes') }}</th></x-slot:head>
                    @foreach ($pointTransactions->take(5) as $row)<tr><td class="px-4 py-3" data-student-progress-row-number>{{ $loop->iteration }}</td><td class="px-4 py-3">{{ $row->entered_at?->format('d-m-Y') }}</td><td class="px-4 py-3">{{ $row->pointType?->name ?: __('crud.common.not_available') }}</td><td class="px-4 py-3">{{ number_format((int) $row->points) }}</td><td class="px-4 py-3">{{ $row->notes ?: __('crud.common.not_available') }}</td></tr>@endforeach
                </x-student-progress-table>
            @endcan
        </section>

        <section class="grid gap-6 xl:grid-cols-2">
            @can('assessment-results.view')
                <x-student-progress-table :title="__('workflow.student_progress.assessments.title')" :empty="$assessmentResults->isEmpty()" :empty-text="__('workflow.student_progress.assessments.empty')" view-all-action="assessments">
                    <x-slot:head><th class="w-12 px-4 py-3 text-left" data-student-progress-number-column>#</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.assessments.headers.assessment') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.assessments.headers.score') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.assessments.headers.status') }}</th></x-slot:head>
                    @foreach ($assessmentResults->take(5) as $row)<tr><td class="px-4 py-3" data-student-progress-row-number>{{ $loop->iteration }}</td><td class="px-4 py-3">{{ $row->assessment?->title ?: __('crud.common.not_available') }}</td><td class="px-4 py-3">{{ $row->score !== null ? number_format((float) $row->score, 2) : '' }}</td><td class="px-4 py-3"><span class="status-chip {{ $statusClass($row->status) }}">{{ __('workflow.common.result_status.'.$row->status) }}</span></td></tr>@endforeach
                </x-student-progress-table>
            @endcan
            @can('assessment-results.view')
                <x-student-progress-table :title="__('workflow.student_progress.final_assessments.title')" :empty="$finalAssessmentResults->isEmpty()" :empty-text="__('workflow.student_progress.final_assessments.empty')" view-all-action="final-assessments">
                    <x-slot:head><th class="w-12 px-4 py-3 text-left" data-student-progress-number-column>#</th><th class="w-1/2 px-4 py-3 text-left">{{ __('workflow.student_progress.assessments.headers.assessment') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.assessments.headers.score') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.assessments.headers.status') }}</th></x-slot:head>
                    @foreach ($finalAssessmentResults->take(5) as $row)<tr><td class="px-4 py-3" data-student-progress-row-number>{{ $loop->iteration }}</td><td class="px-4 py-3 font-medium">{{ $row->assessment?->title ?: __('crud.common.not_available') }}</td><td class="px-4 py-3">{{ $row->score !== null ? number_format((float) $row->score, 2) : '' }}</td><td class="px-4 py-3"><span class="status-chip {{ $statusClass($row->status) }}">{{ __('workflow.common.result_status.'.$row->status) }}</span></td></tr>@endforeach
                </x-student-progress-table>
            @endcan
        </section>

        <section class="grid gap-6 xl:grid-cols-2">
            <x-student-progress-table :title="__('workflow.student_progress.enrollments.title')" :empty="$enrollments->isEmpty()" :empty-text="__('workflow.student_progress.enrollments.empty')" view-all-action="enrollments">
                <x-slot:head><th class="w-12 px-4 py-3 text-left" data-student-progress-number-column>#</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.enrollments.headers.course') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.enrollments.headers.group') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.enrollments.headers.teacher') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.enrollments.headers.status') }}</th></x-slot:head>
                @foreach ($enrollments->take(5) as $row)<tr><td class="px-4 py-3" data-student-progress-row-number>{{ $loop->iteration }}</td><td class="px-4 py-3">{{ $row->group?->course?->name ?: __('crud.common.not_available') }}</td><td class="px-4 py-3">{{ $row->group?->name ?: __('crud.common.not_available') }}</td><td class="px-4 py-3">{{ $row->group?->teacher ? trim($row->group->teacher->first_name.' '.$row->group->teacher->last_name) : __('crud.common.not_available') }}</td><td class="px-4 py-3"><span class="status-chip {{ $statusClass($row->status) }}">{{ __('crud.common.status_options.'.$row->status) }}</span></td></tr>@endforeach
            </x-student-progress-table>
            <x-student-progress-table :title="__('workflow.student_progress.notes.title')" :empty="$parentVisibleNotes->isEmpty()" :empty-text="__('workflow.student_progress.notes.empty')" view-all-action="notes">
                <x-slot:head><th class="w-12 px-4 py-3 text-left" data-student-progress-number-column>#</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.notes.headers.date') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.notes.headers.source') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.notes.headers.body') }}</th></x-slot:head>
                @foreach ($parentVisibleNotes->take(5) as $row)<tr><td class="px-4 py-3" data-student-progress-row-number>{{ $loop->iteration }}</td><td class="px-4 py-3">{{ $row->noted_at?->format('d-m-Y') }}</td><td class="px-4 py-3">{{ $row->source }}</td><td class="px-4 py-3">{{ $row->body }}</td></tr>@endforeach
            </x-student-progress-table>
        </section>

        <x-admin.modal :show="$openDetails !== ''" :title="$openDetails === 'parent' ? __('workflow.student_progress.parent_details.title') : __('workflow.student_progress.actions.view_all')" close-method="closeDetails" max-width="fit" compact>
            @if ($openDetails === 'parent' && $studentRecord->parentProfile)
                @php($parent = $studentRecord->parentProfile)
                <div class="space-y-4"><div class="student-parent-details__row grid gap-4 rounded-2xl border border-white/8 bg-white/4 p-4 md:grid-cols-3"><div><div class="kpi-label">{{ __('workflow.student_progress.profile.father_name') }}</div><div class="mt-1 text-white">{{ $parent->father_name ?: '-' }}</div></div><div><div class="kpi-label">{{ __('workflow.student_progress.parent_details.father_work') }}</div><div class="mt-1 text-white">{{ $parent->father_work ?: '-' }}</div></div><div><div class="kpi-label">{{ __('workflow.student_progress.parent_details.father_phone') }}</div><div class="mt-1 text-white"><bdi dir="ltr">{{ $parent->father_phone ?: '-' }}</bdi></div></div></div><div class="student-parent-details__row grid gap-4 rounded-2xl border border-white/8 bg-white/4 p-4 md:grid-cols-2"><div><div class="kpi-label">{{ __('workflow.student_progress.parent_details.mother_name') }}</div><div class="mt-1 text-white">{{ $parent->mother_name ?: '-' }}</div></div><div><div class="kpi-label">{{ __('workflow.student_progress.parent_details.mother_phone') }}</div><div class="mt-1 text-white"><bdi dir="ltr">{{ $parent->mother_phone ?: '-' }}</bdi></div></div></div><div class="student-parent-details__row grid gap-4 rounded-2xl border border-white/8 bg-white/4 p-4 md:grid-cols-2"><div><div class="kpi-label">{{ __('workflow.student_progress.parent_details.address') }}</div><div class="mt-1 text-white">{{ $parent->address ?: '-' }}</div></div><div><div class="kpi-label">{{ __('workflow.student_progress.parent_details.home_phone') }}</div><div class="mt-1 text-white"><bdi dir="ltr">{{ $parent->home_phone ?: '-' }}</bdi></div></div></div></div>
            @elseif ($openDetails === 'memorization')
                <div class="surface-table" data-student-progress-generic-table><div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="w-12 px-4 py-3 text-left" data-student-progress-number-column>#</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.memorization.headers.date') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.memorization.headers.page') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.memorization.headers.teacher') }}</th></tr></thead><tbody>@foreach ($paginatedDetails as $row)<tr><td class="px-4 py-3" data-student-progress-row-number>{{ $paginatedDetails->firstItem() + $loop->index }}</td><td class="px-4 py-3">{{ $row->date?->format('d-m-Y') }}</td><td class="px-4 py-3">{{ $row->page }}</td><td class="px-4 py-3">{{ $row->teacher ?: '-' }}</td></tr>@endforeach</tbody></table></div></div>
            @elseif ($openDetails === 'points')
                <div class="surface-table" data-student-progress-generic-table><div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="w-12 px-4 py-3 text-left" data-student-progress-number-column>#</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.points.headers.date') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.points.headers.type') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.points.headers.points') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.points.headers.notes') }}</th></tr></thead><tbody>@foreach ($paginatedDetails as $row)<tr><td class="px-4 py-3" data-student-progress-row-number>{{ $paginatedDetails->firstItem() + $loop->index }}</td><td class="px-4 py-3">{{ $row->entered_at?->format('d-m-Y') }}</td><td class="px-4 py-3">{{ $row->pointType?->name ?: '-' }}</td><td class="px-4 py-3">{{ number_format((int) $row->points) }}</td><td class="px-4 py-3">{{ $row->notes ?: '-' }}</td></tr>@endforeach</tbody></table></div></div>
            @elseif ($openDetails === 'assessments')
                <div class="surface-table" data-student-progress-generic-table><div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="w-12 px-4 py-3 text-left" data-student-progress-number-column>#</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.assessments.headers.assessment') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.assessments.headers.score') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.assessments.headers.status') }}</th></tr></thead><tbody>@foreach ($paginatedDetails as $row)<tr><td class="px-4 py-3" data-student-progress-row-number>{{ $paginatedDetails->firstItem() + $loop->index }}</td><td class="px-4 py-3">{{ $row->assessment?->title ?: '-' }}</td><td class="px-4 py-3">{{ $row->score }}</td><td class="px-4 py-3">{{ __('workflow.common.result_status.'.$row->status) }}</td></tr>@endforeach</tbody></table></div></div>
            @elseif ($openDetails === 'final-assessments')
                <div class="surface-table" data-student-progress-generic-table><div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="w-12 px-3 py-2 text-left" data-student-progress-number-column>#</th><th class="w-[65%] px-3 py-2 text-left">{{ __('workflow.student_progress.assessments.headers.assessment') }}</th><th class="w-28 min-w-28 px-3 py-2 text-left">{{ __('workflow.student_progress.assessments.headers.score') }}</th><th class="w-28 min-w-28 px-3 py-2 text-left">{{ __('workflow.student_progress.assessments.headers.status') }}</th></tr></thead><tbody>@foreach ($paginatedDetails as $row)<tr><td class="px-3 py-2" data-student-progress-row-number>{{ $paginatedDetails->firstItem() + $loop->index }}</td><td class="px-3 py-2 font-medium">{{ $row->assessment?->title ?: '-' }}</td><td class="px-3 py-2">{{ $row->score !== null ? number_format((float) $row->score, 2) : '-' }}</td><td class="px-3 py-2">{{ __('workflow.common.result_status.'.$row->status) }}</td></tr>@endforeach</tbody></table></div></div>
            @elseif ($openDetails === 'enrollments')
                <div class="surface-table" data-student-progress-generic-table><div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="w-12 px-4 py-3 text-left" data-student-progress-number-column>#</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.enrollments.headers.course') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.enrollments.headers.group') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.enrollments.headers.teacher') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.enrollments.headers.status') }}</th></tr></thead><tbody>@foreach ($paginatedDetails as $row)<tr><td class="px-4 py-3" data-student-progress-row-number>{{ $paginatedDetails->firstItem() + $loop->index }}</td><td class="px-4 py-3">{{ $row->group?->course?->name ?: '-' }}</td><td class="px-4 py-3">{{ $row->group?->name ?: '-' }}</td><td class="px-4 py-3">{{ $row->group?->teacher ? trim($row->group->teacher->first_name.' '.$row->group->teacher->last_name) : '-' }}</td><td class="px-4 py-3">{{ __('crud.common.status_options.'.$row->status) }}</td></tr>@endforeach</tbody></table></div></div>
            @elseif ($openDetails === 'notes')
                <div class="surface-table" data-student-progress-generic-table><div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="w-12 px-4 py-3 text-left" data-student-progress-number-column>#</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.notes.headers.date') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.notes.headers.source') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.notes.headers.body') }}</th></tr></thead><tbody>@foreach ($paginatedDetails as $row)<tr><td class="px-4 py-3" data-student-progress-row-number>{{ $paginatedDetails->firstItem() + $loop->index }}</td><td class="px-4 py-3">{{ $row->noted_at?->format('d-m-Y') }}</td><td class="px-4 py-3">{{ $row->source }}</td><td class="px-4 py-3">{{ $row->body }}</td></tr>@endforeach</tbody></table></div></div>
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
