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
use Illuminate\Support\Str;
use Livewire\Volt\Component;

new class extends Component
{
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public ?Student $currentStudent = null;

    public int|string|null $selectedStudentId = null;

    public ?int $missingJuzId = null;

    public string $openDetails = '';

    public bool $showAwqafTestModal = false;

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
            $this->closeAwqafTest();

            return;
        }

        $this->setCurrentStudent((int) $value);
    }

    public function showDetails(string $section): void
    {
        if (in_array($section, ['parent', 'memorization', 'points', 'assessments', 'final-assessments', 'enrollments', 'notes'], true)) {
            $this->openDetails = $section;
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

        abort_unless($this->currentStudent, 404);

        $enrollment = $this->scopeEnrollmentsQuery(
            Enrollment::query()->with(['group.teacher', 'student'])
                ->currentActiveForStudent((int) $this->currentStudent->id)
        )->firstOrFail();
        $this->authorizeTeacherEnrollmentAccess($enrollment);
        QuranJuz::query()->findOrFail($juzId);

        $this->awqafEnrollmentId = $enrollment->id;
        $this->awqafJuzId = $juzId;
        $this->awqafTestedOn = now()->toDateString();
        $this->awqafScore = '';
        $this->awqafStatus = 'passed';
        $this->awqafNotes = '';
        $this->showAwqafTestModal = true;
        $this->resetValidation();
    }

    public function closeAwqafTest(): void
    {
        $this->reset('showAwqafTestModal', 'awqafEnrollmentId', 'awqafJuzId', 'awqafTestedOn', 'awqafScore', 'awqafNotes');
        $this->awqafStatus = 'passed';
        $this->resetValidation();
    }

    public function saveAwqafTest(): void
    {
        $this->authorizeAnyPermission(['quran-awqaf-tests.record', 'quran-tests.record']);

        $validated = $this->validate([
            'awqafEnrollmentId' => ['required', 'exists:enrollments,id'],
            'awqafJuzId' => ['required', 'exists:quran_juzs,id'],
            'awqafTestedOn' => ['required', 'date'],
            'awqafScore' => ['nullable', 'numeric', 'between:0,100'],
            'awqafStatus' => ['required', 'in:passed,failed,cancelled'],
            'awqafNotes' => ['nullable', 'string'],
        ]);

        abort_unless($this->currentStudent, 404);

        $enrollment = $this->scopeEnrollmentsQuery(
            Enrollment::query()->with(['group.teacher', 'student'])
                ->currentActiveForStudent((int) $this->currentStudent->id)
        )->firstOrFail();
        $this->authorizeTeacherEnrollmentAccess($enrollment);
        $this->awqafEnrollmentId = $enrollment->id;

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
            'notes' => $validated['awqafNotes'] ?: null,
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
        $enrollmentIds = $enrollments->pluck('id')->all();
        $activeEnrollment = $enrollments->firstWhere('status', 'active') ?: $enrollments->first();
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
                    ->with(['assessment.type'])
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

        return [
            'studentOptions' => $studentOptions,
            'studentRecord' => $studentRecord,
            'activeEnrollment' => $activeEnrollment,
            'enrollments' => $enrollments,
            'memorizationRows' => $memorizationRows,
            'assessmentResults' => $nonFinalAssessmentResults,
            'finalAssessmentResults' => $finalAssessmentResults,
            'awqafTests' => $awqafTests,
            'pointTransactions' => $pointTransactions,
            'parentVisibleNotes' => $parentVisibleNotes,
            'quranJuzProgress' => $quranJuzProgress,
            'selectedMissingJuz' => $selectedMissingJuz,
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
        <a href="{{ route('students.index') }}" wire:navigate class="text-sm font-medium text-neutral-200/80 hover:text-white">{{ __('ui.nav.students') }}</a>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.student_progress.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('workflow.student_progress.subtitle') }}</p>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="surface-panel surface-panel--soft p-5 lg:p-6">
        <div class="admin-toolbar__title">{{ __('workflow.student_progress.selection.title') }}</div>
        @if ($studentOptions->isEmpty())
            <div class="admin-empty-state mt-4">{{ __('workflow.student_progress.selection.no_students') }}</div>
        @else
            <div class="admin-filter-field mt-4">
                <label for="student-progress-student">{{ __('workflow.student_progress.selection.student') }}</label>
                <select id="student-progress-student" wire:model.live="selectedStudentId" data-search-placeholder="{{ __('workflow.student_progress.selection.search_placeholder') }}" class="w-full rounded-xl px-4 py-3 text-sm">
                    <option value="">{{ __('workflow.student_progress.selection.select_student') }}</option>
                    @foreach ($studentOptions as $option)
                        <option value="{{ $option->id }}" data-search="{{ $option->search }}">{{ $option->full_name }}{{ $option->student_number ? ' - '.$option->student_number : '' }}</option>
                    @endforeach
                </select>
            </div>
        @endif
    </section>

    @if ($currentStudent)
        <section class="surface-panel surface-panel--soft p-5 lg:p-6">
            @php($studentPhotoUrl = $studentRecord->photo_path ? asset('storage/'.ltrim($studentRecord->photo_path, '/')) : null)
            <div class="grid gap-5 lg:grid-cols-[7rem_minmax(0,1fr)] lg:items-start">
                <div class="h-28 w-28 overflow-hidden rounded-3xl border border-white/10 bg-white/5">
                    @if ($studentPhotoUrl)<img src="{{ $studentPhotoUrl }}" alt="{{ $studentRecord->full_name }}" class="h-full w-full object-cover">@else<div class="flex h-full w-full items-center justify-center text-4xl font-semibold text-white">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($studentRecord->first_name ?: 'S', 0, 1)) }}</div>@endif
                </div>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-2xl border border-white/8 bg-white/4 p-3"><div class="kpi-label">{{ __('workflow.student_progress.profile.student_no') }}</div><div class="mt-2 text-sm font-semibold text-white">{{ $studentRecord->student_number ?: __('crud.common.not_available') }}</div></div>
                    <div class="rounded-2xl border border-white/8 bg-white/4 p-3"><div class="kpi-label">{{ __('workflow.student_progress.profile.student_name') }}</div><div class="mt-2 text-sm font-semibold text-white">{{ $studentRecord->full_name }}</div></div>
                    <div class="relative rounded-2xl border border-white/8 bg-white/4 p-3"><div class="kpi-label pe-7">{{ __('workflow.student_progress.profile.father_name') }}</div>@if ($studentRecord->parentProfile)<button type="button" wire:click="showDetails('parent')" class="absolute end-2 top-2 inline-flex h-6 w-6 items-center justify-center rounded-lg border border-white/10 bg-white/5 text-neutral-300 transition hover:bg-white/10 hover:text-white" title="{{ __('workflow.student_progress.actions.details') }}" aria-label="{{ __('workflow.student_progress.actions.details') }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-3.5 w-3.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12Z"/><circle cx="12" cy="12" r="2.25"/></svg></button>@endif<div class="mt-2 text-sm font-semibold text-white">{{ $studentRecord->parentProfile?->father_name ?: __('crud.common.not_available') }}</div></div>
                    <div class="grid grid-cols-2 overflow-hidden rounded-2xl border border-white/8 bg-white/4"><div class="min-w-0 p-3"><div class="kpi-label">{{ __('workflow.student_progress.profile.birth_year') }}</div><div class="mt-2 truncate text-sm font-semibold text-white">{{ $studentRecord->birth_date?->format('Y') ?: __('crud.common.not_available') }}</div></div><div class="min-w-0 border-s border-white/8 p-3"><div class="kpi-label">{{ __('workflow.student_progress.profile.grade') }}</div><div class="mt-2 truncate text-sm font-semibold text-white">{{ $studentRecord->gradeLevel?->name ?: __('crud.common.not_available') }}</div></div></div>
                    <div class="rounded-2xl border border-white/8 bg-white/4 p-3"><div class="kpi-label">{{ __('workflow.student_progress.profile.phone') }}</div><div class="mt-2 text-sm font-semibold text-white"><bdi dir="ltr">{{ $studentRecord->user?->phone ?: __('crud.common.not_available') }}</bdi></div></div>
                    <div class="rounded-2xl border border-white/8 bg-white/4 p-3"><div class="kpi-label">{{ __('workflow.student_progress.profile.school') }}</div><div class="mt-2 text-sm font-semibold text-white">{{ $studentRecord->school_name ?: __('crud.common.not_available') }}</div></div>
                    <div class="rounded-2xl border border-white/8 bg-white/4 p-3"><div class="kpi-label">{{ __('workflow.student_progress.profile.group') }}</div><div class="mt-2 text-sm font-semibold text-white">{{ $activeEnrollment?->group?->name ?: __('crud.common.not_available') }}</div></div>
                    <div class="rounded-2xl border border-white/8 bg-white/4 p-3"><div class="kpi-label">{{ __('workflow.student_progress.profile.current_juz') }}</div><div class="mt-2 text-sm font-semibold text-white">{{ $studentRecord->quranCurrentJuz ? __('workflow.common.labels.juz_number', ['number' => $studentRecord->quranCurrentJuz->juz_number]) : __('crud.common.not_available') }}</div></div>
                </div>
            </div>
        </section>

        <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
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

        <section class="surface-table">
            <div class="admin-grid-meta"><div><div class="admin-grid-meta__title">{{ __('workflow.student_progress.juz_progress.title') }}</div><div class="admin-grid-meta__summary">{{ __('workflow.student_progress.juz_progress.summary', ['count' => number_format($quranJuzProgress->where('status', 'finished')->count())]) }}</div></div></div>
            @if ($quranJuzProgress->isEmpty())<div class="admin-empty-state">{{ __('workflow.student_progress.juz_progress.empty') }}</div>@else
                <div class="overflow-x-auto"><table class="w-full table-fixed text-sm"><thead><tr>
                    <th class="px-5 py-4 text-left">{{ __('workflow.student_progress.juz_progress.headers.juz') }}</th>
                    <th class="px-5 py-4 text-left">{{ __('workflow.student_progress.juz_progress.headers.pages') }}</th>
                    <th class="px-5 py-4 text-left">{{ __('workflow.student_progress.juz_progress.headers.partial_tests') }}</th>
                    <th class="px-5 py-4 text-left">{{ __('workflow.student_progress.juz_progress.headers.final_test') }}</th>
                    <th class="px-5 py-4 text-left">{{ __('workflow.student_progress.juz_progress.headers.status') }}</th>
                    <th class="px-5 py-4 text-right">{{ __('workflow.student_progress.juz_progress.headers.actions') }}</th>
                </tr></thead><tbody class="divide-y divide-white/6">
                    @foreach ($quranJuzProgress as $row)<tr>
                        <td class="px-5 py-4 text-white">{{ __('workflow.common.labels.juz_number', ['number' => $row->juz->juz_number]) }}</td>
                        <td class="px-5 py-4">{{ $row->memorized_externally ? '' : number_format($row->memorized_pages) }}</td>
                        <td class="px-5 py-4">@if (! $row->memorized_externally && $row->partial_test_created)<bdi dir="ltr">{{ number_format($row->passed_parts) }}/4</bdi>@endif</td>
                        <td class="px-5 py-4" @if($row->latest_final_score !== null) title="{{ trim(($row->latest_final_date?->format('d-m-Y') ?? '').' · '.($row->latest_final_course ?? '')) }}" @endif>{{ ! $row->memorized_externally && $row->latest_final_score !== null ? \App\Support\PercentageFormatter::format($row->latest_final_score) : '' }}</td>
                        <td class="px-5 py-4"><span class="status-chip {{ $row->memorized_externally ? 'border-emerald-300/25 bg-emerald-300/10 text-emerald-200' : $statusClass($row->status) }}">{{ $row->memorized_externally ? __('workflow.student_progress.juz_progress.statuses.memorized_before') : ($row->status === 'missing' ? __('workflow.student_progress.juz_progress.incomplete', ['count' => number_format($row->missing_pages->count())]) : __('workflow.student_progress.juz_progress.statuses.'.$row->status)) }}</span></td>
                        <td class="px-5 py-4 text-right">
                            @php($showMissingPagesAction = ! $row->memorized_externally && $row->status !== 'finished' && $row->missing_pages->isNotEmpty())
                            @php($showAwqafAction = $row->enrollment && ($row->final_passed || $row->memorized_externally) && ! $row->awqaf_passed && (auth()->user()->can('quran-awqaf-tests.record') || auth()->user()->can('quran-tests.record')))
                            @if ($row->awqaf_passed)
                                <span class="text-sm text-emerald-300">تم سبره بالأوقاف{{ $row->awqaf_passed_on ? ' · '.$row->awqaf_passed_on->format('d-m-Y') : '' }}</span>
                            @elseif ($showMissingPagesAction || $showAwqafAction)
                                <div class="flex flex-wrap justify-end gap-2">
                                    @if ($showMissingPagesAction)<button type="button" wire:click="showMissingPages({{ $row->juz->id }})" class="pill-link pill-link--compact">{{ __('workflow.student_progress.juz_progress.show_missing') }}</button>@endif
                                    @if ($showAwqafAction)<button type="button" wire:click="openAwqafTest({{ $row->juz->id }})" class="pill-link pill-link--compact">{{ __('workflow.student_progress.juz_progress.add_awqaf_test') }}</button>@endif
                                </div>
                            @else
                                <span class="text-neutral-600">-</span>
                            @endif
                        </td>
                    </tr>@endforeach
                </tbody></table></div>
            @endif
        </section>

        <section class="grid gap-6 xl:grid-cols-2">
            @can('memorization.view')
                <x-student-progress-table :title="__('workflow.student_progress.memorization.latest_title')" :empty="$memorizationRows->isEmpty()" :empty-text="__('workflow.student_progress.memorization.empty')" view-all-action="memorization">
                    <x-slot:head><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.memorization.headers.date') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.memorization.headers.page') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.memorization.headers.teacher') }}</th></x-slot:head>
                    @foreach ($memorizationRows->take(5) as $row)<tr><td class="px-4 py-3">{{ $row->date?->format('d-m-Y') }}</td><td class="px-4 py-3">{{ $row->page }}</td><td class="px-4 py-3">{{ $row->teacher ?: __('crud.common.not_available') }}</td></tr>@endforeach
                </x-student-progress-table>
            @endcan
            @can('points.view')
                <x-student-progress-table :title="__('workflow.student_progress.points.latest_title')" :empty="$pointTransactions->isEmpty()" :empty-text="__('workflow.student_progress.points.empty')" view-all-action="points">
                    <x-slot:head><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.points.headers.date') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.points.headers.type') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.points.headers.points') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.points.headers.notes') }}</th></x-slot:head>
                    @foreach ($pointTransactions->take(5) as $row)<tr><td class="px-4 py-3">{{ $row->entered_at?->format('d-m-Y') }}</td><td class="px-4 py-3">{{ $row->pointType?->name ?: __('crud.common.not_available') }}</td><td class="px-4 py-3">{{ number_format((int) $row->points) }}</td><td class="px-4 py-3">{{ $row->notes ?: __('crud.common.not_available') }}</td></tr>@endforeach
                </x-student-progress-table>
            @endcan
        </section>

        <section class="grid gap-6 xl:grid-cols-2">
            @can('assessment-results.view')
                <x-student-progress-table :title="__('workflow.student_progress.assessments.title')" :empty="$assessmentResults->isEmpty()" :empty-text="__('workflow.student_progress.assessments.empty')" view-all-action="assessments">
                    <x-slot:head><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.assessments.headers.assessment') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.assessments.headers.score') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.assessments.headers.status') }}</th></x-slot:head>
                    @foreach ($assessmentResults->take(5) as $row)<tr><td class="px-4 py-3">{{ $row->assessment?->title ?: __('crud.common.not_available') }}</td><td class="px-4 py-3">{{ $row->score !== null ? number_format((float) $row->score, 2) : '' }}</td><td class="px-4 py-3"><span class="status-chip {{ $statusClass($row->status) }}">{{ __('workflow.common.result_status.'.$row->status) }}</span></td></tr>@endforeach
                </x-student-progress-table>
            @endcan
            @can('assessment-results.view')
                <x-student-progress-table :title="__('workflow.student_progress.final_assessments.title')" :empty="$finalAssessmentResults->isEmpty()" :empty-text="__('workflow.student_progress.final_assessments.empty')" view-all-action="final-assessments">
                    <x-slot:head><th class="w-12 px-4 py-3 text-left">#</th><th class="w-1/2 px-4 py-3 text-left">{{ __('workflow.student_progress.assessments.headers.assessment') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.assessments.headers.score') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.assessments.headers.status') }}</th></x-slot:head>
                    @foreach ($finalAssessmentResults->take(5) as $row)<tr><td class="px-4 py-3">{{ $loop->iteration }}</td><td class="px-4 py-3 font-medium">{{ $row->assessment?->title ?: __('crud.common.not_available') }}</td><td class="px-4 py-3">{{ $row->score !== null ? number_format((float) $row->score, 2) : '' }}</td><td class="px-4 py-3"><span class="status-chip {{ $statusClass($row->status) }}">{{ __('workflow.common.result_status.'.$row->status) }}</span></td></tr>@endforeach
                </x-student-progress-table>
            @endcan
        </section>

        <section class="grid gap-6 xl:grid-cols-2">
            <x-student-progress-table :title="__('workflow.student_progress.enrollments.title')" :empty="$enrollments->isEmpty()" :empty-text="__('workflow.student_progress.enrollments.empty')" view-all-action="enrollments">
                <x-slot:head><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.enrollments.headers.course') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.enrollments.headers.group') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.enrollments.headers.teacher') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.enrollments.headers.status') }}</th></x-slot:head>
                @foreach ($enrollments->take(5) as $row)<tr><td class="px-4 py-3">{{ $row->group?->course?->name ?: __('crud.common.not_available') }}</td><td class="px-4 py-3">{{ $row->group?->name ?: __('crud.common.not_available') }}</td><td class="px-4 py-3">{{ $row->group?->teacher ? trim($row->group->teacher->first_name.' '.$row->group->teacher->last_name) : __('crud.common.not_available') }}</td><td class="px-4 py-3"><span class="status-chip {{ $statusClass($row->status) }}">{{ __('crud.common.status_options.'.$row->status) }}</span></td></tr>@endforeach
            </x-student-progress-table>
            <x-student-progress-table :title="__('workflow.student_progress.notes.title')" :empty="$parentVisibleNotes->isEmpty()" :empty-text="__('workflow.student_progress.notes.empty')" view-all-action="notes">
                <x-slot:head><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.notes.headers.date') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.notes.headers.source') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.notes.headers.body') }}</th></x-slot:head>
                @foreach ($parentVisibleNotes->take(5) as $row)<tr><td class="px-4 py-3">{{ $row->noted_at?->format('d-m-Y') }}</td><td class="px-4 py-3">{{ $row->source }}</td><td class="px-4 py-3">{{ $row->body }}</td></tr>@endforeach
            </x-student-progress-table>
        </section>

        <x-admin.modal :show="$openDetails !== ''" :title="$openDetails === 'parent' ? __('workflow.student_progress.parent_details.title') : __('workflow.student_progress.actions.view_all')" close-method="closeDetails" max-width="5xl" compact>
            @if ($openDetails === 'parent' && $studentRecord->parentProfile)
                @php($parent = $studentRecord->parentProfile)
                <div class="space-y-4"><div class="student-parent-details__row grid gap-4 rounded-2xl border border-white/8 bg-white/4 p-4 md:grid-cols-3"><div><div class="kpi-label">{{ __('workflow.student_progress.profile.father_name') }}</div><div class="mt-1 text-white">{{ $parent->father_name ?: '-' }}</div></div><div><div class="kpi-label">{{ __('workflow.student_progress.parent_details.father_work') }}</div><div class="mt-1 text-white">{{ $parent->father_work ?: '-' }}</div></div><div><div class="kpi-label">{{ __('workflow.student_progress.parent_details.father_phone') }}</div><div class="mt-1 text-white"><bdi dir="ltr">{{ $parent->father_phone ?: '-' }}</bdi></div></div></div><div class="student-parent-details__row grid gap-4 rounded-2xl border border-white/8 bg-white/4 p-4 md:grid-cols-2"><div><div class="kpi-label">{{ __('workflow.student_progress.parent_details.mother_name') }}</div><div class="mt-1 text-white">{{ $parent->mother_name ?: '-' }}</div></div><div><div class="kpi-label">{{ __('workflow.student_progress.parent_details.mother_phone') }}</div><div class="mt-1 text-white"><bdi dir="ltr">{{ $parent->mother_phone ?: '-' }}</bdi></div></div></div><div class="student-parent-details__row grid gap-4 rounded-2xl border border-white/8 bg-white/4 p-4 md:grid-cols-2"><div><div class="kpi-label">{{ __('workflow.student_progress.parent_details.address') }}</div><div class="mt-1 text-white">{{ $parent->address ?: '-' }}</div></div><div><div class="kpi-label">{{ __('workflow.student_progress.parent_details.home_phone') }}</div><div class="mt-1 text-white"><bdi dir="ltr">{{ $parent->home_phone ?: '-' }}</bdi></div></div></div></div>
            @elseif ($openDetails === 'memorization')
                <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.memorization.headers.date') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.memorization.headers.page') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.memorization.headers.teacher') }}</th></tr></thead><tbody>@foreach ($memorizationRows as $row)<tr><td class="px-4 py-3">{{ $row->date?->format('d-m-Y') }}</td><td class="px-4 py-3">{{ $row->page }}</td><td class="px-4 py-3">{{ $row->teacher ?: '-' }}</td></tr>@endforeach</tbody></table></div>
            @elseif ($openDetails === 'points')
                <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.points.headers.date') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.points.headers.type') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.points.headers.points') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.points.headers.notes') }}</th></tr></thead><tbody>@foreach ($pointTransactions as $row)<tr><td class="px-4 py-3">{{ $row->entered_at?->format('d-m-Y') }}</td><td class="px-4 py-3">{{ $row->pointType?->name ?: '-' }}</td><td class="px-4 py-3">{{ number_format((int) $row->points) }}</td><td class="px-4 py-3">{{ $row->notes ?: '-' }}</td></tr>@endforeach</tbody></table></div>
            @elseif ($openDetails === 'assessments')
                <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.assessments.headers.assessment') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.assessments.headers.score') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.assessments.headers.status') }}</th></tr></thead><tbody>@foreach ($assessmentResults as $row)<tr><td class="px-4 py-3">{{ $row->assessment?->title ?: '-' }}</td><td class="px-4 py-3">{{ $row->score }}</td><td class="px-4 py-3">{{ __('workflow.common.result_status.'.$row->status) }}</td></tr>@endforeach</tbody></table></div>
            @elseif ($openDetails === 'final-assessments')
                <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="w-12 px-3 py-2 text-left">#</th><th class="w-1/2 px-3 py-2 text-left">{{ __('workflow.student_progress.assessments.headers.assessment') }}</th><th class="px-3 py-2 text-left">{{ __('workflow.student_progress.assessments.headers.score') }}</th><th class="px-3 py-2 text-left">{{ __('workflow.student_progress.assessments.headers.status') }}</th></tr></thead><tbody>@foreach ($finalAssessmentResults as $row)<tr><td class="px-3 py-2">{{ $loop->iteration }}</td><td class="px-3 py-2 font-medium">{{ $row->assessment?->title ?: '-' }}</td><td class="px-3 py-2">{{ $row->score !== null ? number_format((float) $row->score, 2) : '-' }}</td><td class="px-3 py-2">{{ __('workflow.common.result_status.'.$row->status) }}</td></tr>@endforeach</tbody></table></div>
            @elseif ($openDetails === 'enrollments')
                <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.enrollments.headers.course') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.enrollments.headers.group') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.enrollments.headers.teacher') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.enrollments.headers.status') }}</th></tr></thead><tbody>@foreach ($enrollments as $row)<tr><td class="px-4 py-3">{{ $row->group?->course?->name ?: '-' }}</td><td class="px-4 py-3">{{ $row->group?->name ?: '-' }}</td><td class="px-4 py-3">{{ $row->group?->teacher ? trim($row->group->teacher->first_name.' '.$row->group->teacher->last_name) : '-' }}</td><td class="px-4 py-3">{{ __('crud.common.status_options.'.$row->status) }}</td></tr>@endforeach</tbody></table></div>
            @elseif ($openDetails === 'notes')
                <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.notes.headers.date') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.notes.headers.source') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.notes.headers.body') }}</th></tr></thead><tbody>@foreach ($parentVisibleNotes as $row)<tr><td class="px-4 py-3">{{ $row->noted_at?->format('d-m-Y') }}</td><td class="px-4 py-3">{{ $row->source }}</td><td class="px-4 py-3">{{ $row->body }}</td></tr>@endforeach</tbody></table></div>
            @endif
        </x-admin.modal>

        <x-admin.modal :show="$selectedMissingJuz !== null" :title="$selectedMissingJuz ? __('workflow.student_progress.juz_progress.missing_title', ['juz' => $selectedMissingJuz->juz->juz_number]) : ''" :description="__('workflow.student_progress.juz_progress.missing_subtitle')" close-method="closeMissingPages" max-width="2xl">
            @if ($selectedMissingJuz)<div class="flex flex-wrap gap-2">@foreach ($selectedMissingJuz->missing_pages as $page)<span class="badge-soft">{{ $page }}</span>@endforeach</div>@endif
        </x-admin.modal>

        <x-admin.modal :show="$showAwqafTestModal" :title="__('workflow.student_progress.juz_progress.add_awqaf_test')" close-method="closeAwqafTest" max-width="2xl">
            <form wire:submit="saveAwqafTest" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div><label class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.tested_on') }}</label><input wire:model="awqafTestedOn" type="date" class="w-full rounded-xl px-4 py-3 text-sm">@error('awqafTestedOn')<div class="mt-1 text-sm text-red-400">{{ $message }}</div>@enderror</div>
                    <div><label class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.juz') }}</label><div class="rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white">{{ $awqafJuzId ? __('workflow.common.labels.juz_number', ['number' => $quranJuzProgress->first(fn ($row) => (int) $row->juz->id === (int) $awqafJuzId)?->juz->juz_number]) : '-' }}</div>@error('awqafJuzId')<div class="mt-1 text-sm text-red-400">{{ $message }}</div>@enderror</div>
                    <div><label class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.score') }}</label><input wire:model="awqafScore" type="number" min="0" max="100" step="0.01" class="w-full rounded-xl px-4 py-3 text-sm">@error('awqafScore')<div class="mt-1 text-sm text-red-400">{{ $message }}</div>@enderror</div>
                    <div><label class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.result_status') }}</label><select wire:model="awqafStatus" class="w-full rounded-xl px-4 py-3 text-sm"><option value="passed">{{ __('workflow.common.result_status.passed') }}</option><option value="failed">{{ __('workflow.common.result_status.failed') }}</option><option value="cancelled">{{ __('workflow.common.result_status.cancelled') }}</option></select>@error('awqafStatus')<div class="mt-1 text-sm text-red-400">{{ $message }}</div>@enderror</div>
                </div>
                <div><label class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.notes') }}</label><textarea wire:model="awqafNotes" rows="3" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>@error('awqafNotes')<div class="mt-1 text-sm text-red-400">{{ $message }}</div>@enderror</div>
                @error('awqafEnrollmentId')<div class="text-sm text-red-400">{{ $message }}</div>@enderror
                <div class="flex justify-end gap-3"><button type="button" wire:click="closeAwqafTest" class="pill-link">{{ __('crud.common.actions.cancel') }}</button><button class="pill-link pill-link--accent">{{ __('workflow.common.actions.save_quran_test') }}</button></div>
            </form>
        </x-admin.modal>
    @else
        <section class="surface-panel p-6"><div class="admin-empty-state">{{ $studentOptions->isEmpty() ? __('workflow.student_progress.selection.no_students') : __('workflow.student_progress.selection.empty') }}</div></section>
    @endif
</div>
