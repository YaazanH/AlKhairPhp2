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
use App\Models\Student;
use App\Models\StudentAttendanceRecord;
use App\Models\StudentNote;
use App\Models\StudentPageAchievement;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public ?Student $currentStudent = null;
    public int|string|null $selectedStudentId = null;
    public ?int $missingJuzId = null;
    public string $openDetails = '';

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

            return;
        }

        $this->setCurrentStudent((int) $value);
    }

    public function showDetails(string $section): void
    {
        if (in_array($section, ['parent', 'memorization', 'points', 'assessments', 'awqaf', 'enrollments', 'notes'], true)) {
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

    public function with(): array
    {
        $studentOptions = $this->studentOptionsQuery()
            ->get()
            ->map(fn (Student $student): object => (object) [
                'full_name' => $student->full_name,
                'id' => (int) $student->id,
                'search' => collect([$student->full_name, $student->student_number, $student->parentProfile?->father_name])->filter()->implode(' '),
                'student_number' => $student->student_number,
            ]);

        if (! $this->currentStudent) {
            return ['studentOptions' => $studentOptions];
        }

        $studentRecord = $this->currentStudent->fresh(['gradeLevel', 'parentProfile', 'quranCurrentJuz']);
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

        $awqafTests = auth()->user()->can('quran-awqaf-tests.view') || auth()->user()->can('quran-tests.view')
            ? $this->scopeQuranTestsQuery(
                QuranTest::query()
                    ->with(['juz'])
                    ->where('student_id', $studentRecord->id)
                    ->when($enrollmentIds === [], fn ($query) => $query->whereRaw('1 = 0'), fn ($query) => $query->whereIn('enrollment_id', $enrollmentIds))
            )->orderByDesc('tested_on')->orderByDesc('id')->get()
            : collect();

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
                    ->with(['attempts', 'enrollment'])
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
        $quranJuzProgress = QuranJuz::query()->orderBy('juz_number')->get()
            ->map(function (QuranJuz $juz) use ($pageSet, $partialTests, $finalTests, $enrollments) {
                $pages = collect(range((int) $juz->from_page, (int) $juz->to_page));
                $missingPages = $pages->reject(fn (int $page) => $pageSet->has($page))->values();
                $juzPartialTests = $partialTests->where('juz_id', $juz->id);
                $passedParts = $juzPartialTests->flatMap->parts->where('status', 'passed')->pluck('part_number')->unique()->count();
                $juzFinalTests = $finalTests->where('juz_id', $juz->id);
                $latestFinalAttempt = $juzFinalTests->flatMap->attempts
                    ->sortByDesc(fn ($attempt) => sprintf('%010d-%010d', $attempt->tested_on?->timestamp ?? 0, $attempt->id))
                    ->first();
                $finalPassed = $juzFinalTests->contains(fn (QuranFinalTest $test) => $test->status === 'passed');
                $status = $missingPages->isNotEmpty() ? 'missing' : ($finalPassed ? 'finished' : 'awaiting');

                return (object) [
                    'juz' => $juz,
                    'memorized_pages' => $pages->count() - $missingPages->count(),
                    'missing_pages' => $missingPages,
                    'passed_parts' => $passedParts,
                    'latest_final_score' => $latestFinalAttempt?->score,
                    'status' => $status,
                    'enrollment' => $juzFinalTests->first()?->enrollment ?: $juzPartialTests->first()?->enrollment ?: $enrollments->first(),
                ];
            })
            ->filter(fn ($row) => $row->memorized_pages > 0 || $row->passed_parts > 0 || $row->latest_final_score !== null)
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
            'assessmentResults' => $assessmentResults,
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
                    <div class="rounded-2xl border border-white/8 bg-white/4 p-3"><div class="kpi-label">{{ __('workflow.student_progress.profile.father_name') }}</div><div class="mt-2 flex items-center gap-2 text-sm font-semibold text-white"><span>{{ $studentRecord->parentProfile?->father_name ?: __('crud.common.not_available') }}</span>@if ($studentRecord->parentProfile)<button type="button" wire:click="showDetails('parent')" class="pill-link pill-link--compact">{{ __('workflow.student_progress.actions.details') }}</button>@endif</div></div>
                    <div class="rounded-2xl border border-white/8 bg-white/4 p-3"><div class="kpi-label">{{ __('workflow.student_progress.profile.birth_year') }}</div><div class="mt-2 text-sm font-semibold text-white">{{ $studentRecord->birth_date?->format('Y') ?: __('crud.common.not_available') }}</div></div>
                    <div class="rounded-2xl border border-white/8 bg-white/4 p-3"><div class="kpi-label">{{ __('workflow.student_progress.profile.grade') }}</div><div class="mt-2 text-sm font-semibold text-white">{{ $studentRecord->gradeLevel?->name ?: __('crud.common.not_available') }}</div></div>
                    <div class="rounded-2xl border border-white/8 bg-white/4 p-3"><div class="kpi-label">{{ __('workflow.student_progress.profile.school') }}</div><div class="mt-2 text-sm font-semibold text-white">{{ $studentRecord->school_name ?: __('crud.common.not_available') }}</div></div>
                    <div class="rounded-2xl border border-white/8 bg-white/4 p-3"><div class="kpi-label">{{ __('workflow.student_progress.profile.group') }}</div><div class="mt-2 text-sm font-semibold text-white">{{ $activeEnrollment?->group?->name ?: __('crud.common.not_available') }}</div></div>
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
                <div class="overflow-x-auto"><table class="text-sm"><thead><tr>
                    <th class="px-5 py-4 text-left">{{ __('workflow.student_progress.juz_progress.headers.juz') }}</th>
                    <th class="px-5 py-4 text-left">{{ __('workflow.student_progress.juz_progress.headers.pages') }}</th>
                    <th class="px-5 py-4 text-left">{{ __('workflow.student_progress.juz_progress.headers.partial_tests') }}</th>
                    <th class="px-5 py-4 text-left">{{ __('workflow.student_progress.juz_progress.headers.final_test') }}</th>
                    <th class="px-5 py-4 text-left">{{ __('workflow.student_progress.juz_progress.headers.status') }}</th>
                    <th class="px-5 py-4 text-right">{{ __('workflow.student_progress.juz_progress.headers.actions') }}</th>
                </tr></thead><tbody class="divide-y divide-white/6">
                    @foreach ($quranJuzProgress as $row)<tr>
                        <td class="px-5 py-4 text-white">{{ __('workflow.common.labels.juz_number', ['number' => $row->juz->juz_number]) }}</td>
                        <td class="px-5 py-4">{{ number_format($row->memorized_pages) }}</td>
                        <td class="px-5 py-4">{{ $row->passed_parts > 0 ? number_format($row->passed_parts) : '' }}</td>
                        <td class="px-5 py-4">{{ $row->latest_final_score !== null ? number_format((float) $row->latest_final_score, 2) : '' }}</td>
                        <td class="px-5 py-4"><span class="status-chip {{ $statusClass($row->status) }}">{{ __('workflow.student_progress.juz_progress.statuses.'.$row->status) }}</span></td>
                        <td class="px-5 py-4"><div class="flex justify-end gap-2">@if ($row->missing_pages->isNotEmpty())<button type="button" wire:click="showMissingPages({{ $row->juz->id }})" class="pill-link pill-link--compact">{{ __('workflow.student_progress.juz_progress.show_missing') }}</button>@endif @if ($row->enrollment)@canany(['quran-awqaf-tests.view', 'quran-tests.view'])<a href="{{ route('enrollments.quran-tests', $row->enrollment) }}" wire:navigate class="pill-link pill-link--compact">{{ __('crud.common.actions.tests') }}</a>@endcanany @endif</div></td>
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
            @canany(['quran-awqaf-tests.view', 'quran-tests.view'])
                <x-student-progress-table :title="__('workflow.student_progress.awqaf_tests.title')" :empty="$awqafTests->isEmpty()" :empty-text="__('workflow.student_progress.awqaf_tests.empty')" view-all-action="awqaf">
                    <x-slot:head><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.awqaf_tests.headers.date') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.awqaf_tests.headers.juz') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.awqaf_tests.headers.score') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.awqaf_tests.headers.status') }}</th></x-slot:head>
                    @foreach ($awqafTests->take(5) as $row)<tr><td class="px-4 py-3">{{ $row->tested_on?->format('d-m-Y') }}</td><td class="px-4 py-3">{{ $row->juz?->juz_number ?: '' }}</td><td class="px-4 py-3">{{ $row->score !== null ? number_format((float) $row->score, 2) : '' }}</td><td class="px-4 py-3"><span class="status-chip {{ $statusClass($row->status) }}">{{ __('workflow.common.result_status.'.$row->status) }}</span></td></tr>@endforeach
                </x-student-progress-table>
            @endcanany
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

        <x-admin.modal :show="$openDetails !== ''" :title="$openDetails === 'parent' ? __('workflow.student_progress.parent_details.title') : __('workflow.student_progress.actions.view_all')" close-method="closeDetails" max-width="6xl">
            @if ($openDetails === 'parent' && $studentRecord->parentProfile)
                @php($parent = $studentRecord->parentProfile)
                <div class="grid gap-4 md:grid-cols-2"><div><div class="kpi-label">{{ __('workflow.student_progress.profile.father_name') }}</div><div class="mt-1 text-white">{{ $parent->father_name ?: '-' }}</div></div><div><div class="kpi-label">{{ __('workflow.student_progress.parent_details.father_phone') }}</div><div class="mt-1 text-white"><bdi dir="ltr">{{ $parent->father_phone ?: '-' }}</bdi></div></div><div><div class="kpi-label">{{ __('workflow.student_progress.parent_details.father_work') }}</div><div class="mt-1 text-white">{{ $parent->father_work ?: '-' }}</div></div><div><div class="kpi-label">{{ __('workflow.student_progress.parent_details.mother_name') }}</div><div class="mt-1 text-white">{{ $parent->mother_name ?: '-' }}</div></div><div><div class="kpi-label">{{ __('workflow.student_progress.parent_details.mother_phone') }}</div><div class="mt-1 text-white"><bdi dir="ltr">{{ $parent->mother_phone ?: '-' }}</bdi></div></div><div><div class="kpi-label">{{ __('workflow.student_progress.parent_details.home_phone') }}</div><div class="mt-1 text-white"><bdi dir="ltr">{{ $parent->home_phone ?: '-' }}</bdi></div></div><div class="md:col-span-2"><div class="kpi-label">{{ __('workflow.student_progress.parent_details.address') }}</div><div class="mt-1 text-white">{{ $parent->address ?: '-' }}</div></div></div>
            @elseif ($openDetails === 'memorization')
                <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.memorization.headers.date') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.memorization.headers.page') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.memorization.headers.teacher') }}</th></tr></thead><tbody>@foreach ($memorizationRows as $row)<tr><td class="px-4 py-3">{{ $row->date?->format('d-m-Y') }}</td><td class="px-4 py-3">{{ $row->page }}</td><td class="px-4 py-3">{{ $row->teacher ?: '-' }}</td></tr>@endforeach</tbody></table></div>
            @elseif ($openDetails === 'points')
                <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.points.headers.date') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.points.headers.type') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.points.headers.points') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.points.headers.notes') }}</th></tr></thead><tbody>@foreach ($pointTransactions as $row)<tr><td class="px-4 py-3">{{ $row->entered_at?->format('d-m-Y') }}</td><td class="px-4 py-3">{{ $row->pointType?->name ?: '-' }}</td><td class="px-4 py-3">{{ number_format((int) $row->points) }}</td><td class="px-4 py-3">{{ $row->notes ?: '-' }}</td></tr>@endforeach</tbody></table></div>
            @elseif ($openDetails === 'assessments')
                <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.assessments.headers.assessment') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.assessments.headers.score') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.assessments.headers.status') }}</th></tr></thead><tbody>@foreach ($assessmentResults as $row)<tr><td class="px-4 py-3">{{ $row->assessment?->title ?: '-' }}</td><td class="px-4 py-3">{{ $row->score }}</td><td class="px-4 py-3">{{ __('workflow.common.result_status.'.$row->status) }}</td></tr>@endforeach</tbody></table></div>
            @elseif ($openDetails === 'awqaf')
                <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.awqaf_tests.headers.date') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.awqaf_tests.headers.juz') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.awqaf_tests.headers.score') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.awqaf_tests.headers.status') }}</th></tr></thead><tbody>@foreach ($awqafTests as $row)<tr><td class="px-4 py-3">{{ $row->tested_on?->format('d-m-Y') }}</td><td class="px-4 py-3">{{ $row->juz?->juz_number }}</td><td class="px-4 py-3">{{ $row->score }}</td><td class="px-4 py-3">{{ __('workflow.common.result_status.'.$row->status) }}</td></tr>@endforeach</tbody></table></div>
            @elseif ($openDetails === 'enrollments')
                <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.enrollments.headers.course') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.enrollments.headers.group') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.enrollments.headers.teacher') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.enrollments.headers.status') }}</th></tr></thead><tbody>@foreach ($enrollments as $row)<tr><td class="px-4 py-3">{{ $row->group?->course?->name ?: '-' }}</td><td class="px-4 py-3">{{ $row->group?->name ?: '-' }}</td><td class="px-4 py-3">{{ $row->group?->teacher ? trim($row->group->teacher->first_name.' '.$row->group->teacher->last_name) : '-' }}</td><td class="px-4 py-3">{{ __('crud.common.status_options.'.$row->status) }}</td></tr>@endforeach</tbody></table></div>
            @elseif ($openDetails === 'notes')
                <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.notes.headers.date') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.notes.headers.source') }}</th><th class="px-4 py-3 text-left">{{ __('workflow.student_progress.notes.headers.body') }}</th></tr></thead><tbody>@foreach ($parentVisibleNotes as $row)<tr><td class="px-4 py-3">{{ $row->noted_at?->format('d-m-Y') }}</td><td class="px-4 py-3">{{ $row->source }}</td><td class="px-4 py-3">{{ $row->body }}</td></tr>@endforeach</tbody></table></div>
            @endif
        </x-admin.modal>

        <x-admin.modal :show="$selectedMissingJuz !== null" :title="$selectedMissingJuz ? __('workflow.student_progress.juz_progress.missing_title', ['juz' => $selectedMissingJuz->juz->juz_number]) : ''" :description="__('workflow.student_progress.juz_progress.missing_subtitle')" close-method="closeMissingPages" max-width="2xl">
            @if ($selectedMissingJuz)<div class="flex flex-wrap gap-2">@foreach ($selectedMissingJuz->missing_pages as $page)<span class="badge-soft">{{ $page }}</span>@endforeach</div>@endif
        </x-admin.modal>
    @else
        <section class="surface-panel p-6"><div class="admin-empty-state">{{ $studentOptions->isEmpty() ? __('workflow.student_progress.selection.no_students') : __('workflow.student_progress.selection.empty') }}</div></section>
    @endif
</div>
