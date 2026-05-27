<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\AssessmentResult;
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
    public string $studentSearch = '';
    public string $courseFilter = 'all';
    public ?int $missingJuzId = null;

    public function mount(?Student $student = null): void
    {
        $this->authorizePermission('students.view');

        if ($student) {
            $this->setCurrentStudent($student->id);

            return;
        }

        $singleAccessibleStudent = $this->studentOptionsQuery()
            ->limit(2)
            ->get();

        if ($singleAccessibleStudent->count() === 1) {
            $this->setCurrentStudent((int) $singleAccessibleStudent->first()->id);
        }
    }

    public function updatedSelectedStudentId(int|string|null $value): void
    {
        if (blank($value)) {
            $this->currentStudent = null;
            $this->selectedStudentId = null;
            $this->studentSearch = '';
            $this->courseFilter = 'all';
            $this->missingJuzId = null;

            return;
        }

        $this->setCurrentStudent((int) $value);
    }

    public function showMissingPages(int $juzId): void
    {
        $this->missingJuzId = $juzId;
    }

    public function closeMissingPages(): void
    {
        $this->missingJuzId = null;
    }

    public function selectStudent(int $studentId): void
    {
        $this->setCurrentStudent($studentId);
    }

    public function clearStudentSelection(): void
    {
        $this->currentStudent = null;
        $this->selectedStudentId = null;
        $this->studentSearch = '';
        $this->courseFilter = 'all';
        $this->missingJuzId = null;
    }

    public function with(): array
    {
        $studentOptions = $this->studentOptionsQuery()
            ->limit(filled(trim($this->studentSearch)) ? 12 : 8)
            ->get()
            ->map(fn (Student $student): object => (object) [
                'full_name' => $student->full_name,
                'id' => (int) $student->id,
                'student_number' => $student->student_number,
            ]);

        if (! $this->currentStudent) {
            return [
                'studentOptions' => $studentOptions,
            ];
        }

        $studentRecord = $this->currentStudent->fresh([
            'gradeLevel',
            'parentProfile',
            'quranCurrentJuz',
        ]);

        $enrollments = $this->scopeEnrollmentsQuery(
            Enrollment::query()
                ->with(['group.course', 'group.teacher'])
                ->where('student_id', $this->currentStudent->id)
        )
            ->when(
                $this->courseFilter !== 'all' && filled($this->courseFilter),
                fn ($query) => $query->whereHas('group', fn ($groupQuery) => $groupQuery->where('course_id', (int) $this->courseFilter))
            )
            ->orderByRaw("case when status = 'active' then 0 else 1 end")
            ->orderByDesc('enrolled_at')
            ->orderByDesc('id')
            ->get();

        $enrollmentIds = $enrollments->pluck('id')->all();
        $activeEnrollment = $enrollments->firstWhere('status', 'active') ?: $enrollments->first();
        $pageAchievementQuery = StudentPageAchievement::query()
            ->where('student_id', $this->currentStudent->id)
            ->when(
                $this->courseFilter !== 'all' && filled($this->courseFilter),
                fn ($query) => $enrollmentIds === []
                    ? $query->whereRaw('1 = 0')
                    : $query->whereIn('first_enrollment_id', $enrollmentIds),
            );

        $memorizedPages = (clone $pageAchievementQuery)
            ->distinct()
            ->pluck('page_no')
            ->map(fn ($page) => (int) $page)
            ->unique()
            ->values();

        $memorizedPageSet = $memorizedPages->flip();
        $courseOptions = $this->scopeEnrollmentsQuery(
            Enrollment::query()
                ->with('group.course')
                ->where('student_id', $this->currentStudent->id)
        )
            ->get()
            ->pluck('group.course')
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values();

        $assessmentResults = auth()->user()->can('assessment-results.view')
            ? $this->scopeAssessmentResultsQuery(
                AssessmentResult::query()
                    ->with(['assessment.type', 'assessment.group.course', 'teacher', 'enrollment.group'])
                    ->where('student_id', $this->currentStudent->id)
                    ->when(
                        $enrollmentIds === [],
                        fn ($query) => $query->whereRaw('1 = 0'),
                        fn ($query) => $query->whereIn('enrollment_id', $enrollmentIds),
                    )
            )
                ->latest('id')
                ->get()
            : collect();

        $memorizationSessions = auth()->user()->can('memorization.view')
            ? $this->scopeMemorizationSessionsQuery(
                MemorizationSession::query()
                    ->with([
                        'enrollment.group',
                        'teacher',
                        'pages' => fn ($query) => $query->orderBy('page_no'),
                    ])
                    ->where('student_id', $this->currentStudent->id)
            )
                ->latest('recorded_on')
                ->latest('id')
                ->get()
            : collect();

        $awqafTests = auth()->user()->can('quran-awqaf-tests.view') || auth()->user()->can('quran-tests.view')
            ? $this->scopeQuranTestsQuery(
                QuranTest::query()
                    ->with(['enrollment.group', 'juz', 'teacher', 'type'])
                    ->where('student_id', $this->currentStudent->id)
                    ->when(
                        $enrollmentIds === [],
                        fn ($query) => $query->whereRaw('1 = 0'),
                        fn ($query) => $query->whereIn('enrollment_id', $enrollmentIds),
                    )
            )
                ->get()
                ->map(fn (QuranTest $test) => (object) [
                    'enrollment' => $test->enrollment,
                    'juz' => $test->juz,
                    'score' => $test->score,
                    'sort_key' => sprintf('%010d-%010d', $test->tested_on?->timestamp ?? 0, $test->id),
                    'status' => $test->status,
                    'tested_on' => $test->tested_on,
                    'type_label' => $test->type?->name ?: __('crud.common.not_available'),
                ])
                ->sortByDesc('sort_key')
                ->values()
            : collect();

        $quranFinalTests = auth()->user()->can('quran-final-tests.view')
            ? $this->scopeQuranFinalTestsQuery(
                QuranFinalTest::query()
                    ->with(['attempts.teacher', 'enrollment.group', 'juz'])
                    ->where('student_id', $this->currentStudent->id)
                    ->when(
                        $enrollmentIds === [],
                        fn ($query) => $query->whereRaw('1 = 0'),
                        fn ($query) => $query->whereIn('enrollment_id', $enrollmentIds),
                    )
            )
                ->get()
                ->flatMap(function (QuranFinalTest $finalTest) {
                    return $finalTest->attempts->map(fn ($attempt) => (object) [
                        'enrollment' => $finalTest->enrollment,
                        'juz' => $finalTest->juz,
                        'score' => $attempt->score,
                        'sort_key' => sprintf('%010d-%010d', $attempt->tested_on?->timestamp ?? 0, $attempt->id),
                        'status' => $attempt->status,
                        'tested_on' => $attempt->tested_on,
                    ]);
                })
                ->sortByDesc('sort_key')
                ->values()
            : collect();

        $quranPartialTests = auth()->user()->can('quran-partial-tests.view')
            ? $this->scopeQuranPartialTestsQuery(
                QuranPartialTest::query()
                    ->with(['enrollment.group', 'juz', 'parts.attempts.teacher'])
                    ->where('student_id', $this->currentStudent->id)
                    ->when(
                        $enrollmentIds === [],
                        fn ($query) => $query->whereRaw('1 = 0'),
                        fn ($query) => $query->whereIn('enrollment_id', $enrollmentIds),
                    )
            )
                ->latest('id')
                ->get()
            : collect();

        $pointTransactions = auth()->user()->can('points.view')
            ? $this->scopePointTransactionsQuery(
                PointTransaction::query()
                    ->with(['enrollment.group.course', 'pointType'])
                    ->where('student_id', $this->currentStudent->id)
                    ->when(
                        $enrollmentIds === [],
                        fn ($query) => $query->whereRaw('1 = 0'),
                        fn ($query) => $query->whereIn('enrollment_id', $enrollmentIds),
                    )
            )
                ->latest('entered_at')
                ->latest('id')
                ->get()
            : collect()
        ;

        $parentVisibleNotes = $this->scopeStudentNotesQuery(
            StudentNote::query()
                ->with(['author', 'enrollment.group'])
                ->where('student_id', $this->currentStudent->id)
                ->where('visibility', 'visible_to_parent')
                ->when(
                    $enrollmentIds === [],
                    fn ($query) => $query->whereRaw('1 = 0'),
                    fn ($query) => $query->whereIn('enrollment_id', $enrollmentIds),
                )
        )
            ->latest('noted_at')
            ->latest('id')
            ->get();

        $attendanceDays = auth()->user()->can('attendance.student.view')
            ? $this->scopeStudentAttendanceRecordsQuery(
                StudentAttendanceRecord::query()
                    ->with('status')
                    ->whereHas('status', fn ($query) => $query->where('is_present', true))
                    ->when(
                        $enrollmentIds === [],
                        fn ($query) => $query->whereRaw('1 = 0'),
                        fn ($query) => $query->whereIn('enrollment_id', $enrollmentIds),
                    )
            )
                ->distinct('group_attendance_day_id')
                ->count('group_attendance_day_id')
            : 0;

        $quranJuzProgress = QuranJuz::query()
            ->orderBy('juz_number')
            ->get()
            ->map(function (QuranJuz $juz) use ($memorizedPageSet) {
                $pages = range((int) $juz->from_page, (int) $juz->to_page);
                $missingPages = collect($pages)
                    ->reject(fn (int $page) => $memorizedPageSet->has($page))
                    ->values();

                return (object) [
                    'juz' => $juz,
                    'total_pages' => count($pages),
                    'memorized_pages' => count($pages) - $missingPages->count(),
                    'missing_pages' => $missingPages,
                    'is_complete' => $missingPages->isEmpty(),
                ];
            })
            ->filter(fn ($row) => $row->memorized_pages > 0)
            ->values();

        $selectedMissingJuz = $this->missingJuzId
            ? $quranJuzProgress->first(fn ($row) => (int) $row->juz->id === (int) $this->missingJuzId)
            : null;

        $pointTypeSummary = $pointTransactions
            ->whereNull('voided_at')
            ->groupBy(fn (PointTransaction $transaction) => $transaction->pointType?->id ?: 'none')
            ->map(function ($transactions) {
                $first = $transactions->first();

                return (object) [
                    'entries_count' => $transactions->count(),
                    'label' => $first?->pointType?->name ?: __('crud.common.not_available'),
                    'points_total' => (int) $transactions->sum('points'),
                ];
            })
            ->sortBy('label')
            ->values();

        return [
            'studentRecord' => $studentRecord,
            'studentOptions' => $studentOptions,
            'courseOptions' => $courseOptions,
            'enrollments' => $enrollments,
            'assessmentResults' => $assessmentResults,
            'memorizationSessions' => $memorizationSessions,
            'activeEnrollment' => $activeEnrollment,
            'awqafTests' => $awqafTests,
            'quranFinalTests' => $quranFinalTests,
            'quranPartialTests' => $quranPartialTests,
            'quranJuzProgress' => $quranJuzProgress,
            'selectedMissingJuz' => $selectedMissingJuz,
            'pointTransactions' => $pointTransactions,
            'latestPointTransactions' => $pointTransactions->take(10),
            'pointTypeSummary' => $pointTypeSummary,
            'parentVisibleNotes' => $parentVisibleNotes,
            'stats' => [
                'attendance_days' => $attendanceDays,
                'memorized_pages' => $memorizedPages->count(),
                'quran_partial_tests' => $quranPartialTests->count(),
                'quran_final_tests' => $quranFinalTests->count(),
                'points' => (int) $pointTransactions->whereNull('voided_at')->sum('points'),
            ],
        ];
    }

    protected function setCurrentStudent(int $studentId): void
    {
        $student = Student::query()
            ->with(['gradeLevel', 'parentProfile', 'quranCurrentJuz'])
            ->findOrFail($studentId);

        $this->authorizeScopedStudentAccess($student);

        $this->currentStudent = $student;
        $this->selectedStudentId = (int) $student->id;
        $this->studentSearch = $student->full_name;
        $this->courseFilter = 'all';
        $this->missingJuzId = null;
    }

    protected function studentOptionsQuery()
    {
        $searchTokens = collect(preg_split('/\s+/u', trim($this->studentSearch)) ?: [])
            ->filter(fn (mixed $token): bool => is_string($token) && filled($token))
            ->values();

        return $this->scopeStudentsQuery(
            Student::query()
                ->select(['id', 'first_name', 'last_name', 'student_number'])
                ->when($searchTokens->isNotEmpty(), function ($query) use ($searchTokens) {
                    $searchTokens->each(function (string $token) use ($query): void {
                        $query->where(function ($studentQuery) use ($token): void {
                            $studentQuery
                                ->where('first_name', 'like', '%'.$token.'%')
                                ->orWhere('last_name', 'like', '%'.$token.'%')
                                ->orWhere('student_number', 'like', '%'.$token.'%');
                        });
                    });
                })
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->orderBy('id')
        );
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="dashboard-split grid gap-6 xl:grid-cols-[minmax(0,1.15fr)_24rem] xl:items-start">
            <div>
                <a href="{{ route('students.index') }}" wire:navigate class="text-sm font-medium text-neutral-200/80 hover:text-white">{{ __('ui.nav.students') }}</a>
                <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.student_progress.title') }}</h1>
                <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('workflow.student_progress.subtitle') }}</p>

                <div class="surface-panel surface-panel--soft mt-6 max-w-4xl p-5 lg:p-6">
                    <div class="admin-toolbar">
                        <div>
                            <div class="admin-toolbar__title">{{ __('workflow.student_progress.selection.title') }}</div>
                            <p class="admin-toolbar__subtitle">{{ __('workflow.student_progress.selection.copy') }}</p>
                        </div>

                        @if ($currentStudent)
                            <div class="admin-toolbar__actions">
                                <button type="button" wire:click="clearStudentSelection" class="pill-link">
                                    {{ __('workflow.student_progress.selection.change_student') }}
                                </button>
                            </div>
                        @endif
                    </div>

                    <div class="mt-4 grid gap-3">
                        <label for="student-progress-search" class="text-sm font-medium text-white">{{ __('workflow.student_progress.selection.search') }}</label>
                        <input
                            id="student-progress-search"
                            wire:model.live.debounce.300ms="studentSearch"
                            type="search"
                            class="rounded-xl px-4 py-3 text-sm"
                            placeholder="{{ __('workflow.student_progress.selection.search_placeholder') }}"
                            autocomplete="off"
                        >
                    </div>

                    <div class="mt-4 overflow-hidden rounded-3xl border border-white/10 bg-white/4">
                        @forelse ($studentOptions as $studentOption)
                            <button
                                type="button"
                                wire:click="selectStudent({{ $studentOption->id }})"
                                class="flex w-full items-center justify-between gap-4 px-4 py-3 text-left transition hover:bg-white/8 {{ (int) $selectedStudentId === $studentOption->id ? 'bg-white/10' : '' }}"
                            >
                                <div>
                                    <div class="text-sm font-semibold text-white">{{ $studentOption->full_name }}</div>
                                    <div class="mt-1 text-xs text-neutral-300">{{ $studentOption->student_number ?: __('crud.common.not_available') }}</div>
                                </div>
                                <span class="badge-soft {{ (int) $selectedStudentId === $studentOption->id ? 'badge-soft--emerald' : '' }}">
                                    {{ (int) $selectedStudentId === $studentOption->id ? __('workflow.student_progress.selection.selected') : __('workflow.student_progress.selection.open') }}
                                </span>
                            </button>
                        @empty
                            <div class="px-4 py-5 text-sm text-neutral-300">
                                {{ __('workflow.student_progress.selection.no_students') }}
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>

            @if ($currentStudent)
            <aside class="surface-panel surface-panel--soft p-5 lg:p-6">
                @php
                    $studentPhotoUrl = $studentRecord->photo_path ? asset('storage/'.ltrim($studentRecord->photo_path, '/')) : null;
                    $profileRows = [
                        ['label' => __('workflow.student_progress.profile.student_no'), 'value' => $studentRecord->student_number ?: __('crud.common.not_available')],
                        ['label' => __('workflow.student_progress.profile.student_name'), 'value' => $studentRecord->full_name],
                        ['label' => __('workflow.student_progress.profile.father_name'), 'value' => $studentRecord->parentProfile?->father_name ?: __('crud.common.not_available')],
                        ['label' => __('workflow.student_progress.profile.birth_year'), 'value' => $studentRecord->birth_date?->format('Y') ?: __('crud.common.not_available')],
                        ['label' => __('workflow.student_progress.profile.grade'), 'value' => $studentRecord->gradeLevel?->name ?: __('crud.common.not_available')],
                        ['label' => __('workflow.student_progress.profile.school'), 'value' => $studentRecord->school_name ?: __('crud.common.not_available')],
                        ['label' => __('workflow.student_progress.profile.group'), 'value' => $activeEnrollment?->group?->name ?: __('crud.common.not_available')],
                    ];
                @endphp
                <div class="grid gap-5 lg:grid-cols-[7rem_minmax(0,1fr)] lg:items-start">
                    <div class="h-28 w-28 overflow-hidden rounded-3xl border border-white/10 bg-white/5 shadow-2xl shadow-black/10">
                        @if ($studentPhotoUrl)
                            <img src="{{ $studentPhotoUrl }}" alt="{{ $studentRecord->full_name }}" class="h-full w-full object-cover">
                        @else
                            <div class="flex h-full w-full items-center justify-center text-4xl font-semibold text-white">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($studentRecord->first_name ?: 'S', 0, 1)) }}</div>
                        @endif
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($profileRows as $row)
                            <div class="rounded-2xl border border-white/8 bg-white/4 p-3">
                                <div class="kpi-label">{{ $row['label'] }}</div>
                                <div class="mt-2 text-sm font-semibold text-white">{{ $row['value'] }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </aside>
            @endif
        </div>
    </section>

    @if ($currentStudent)
    <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
        <article class="stat-card">
            <div class="kpi-label">{{ __('workflow.student_progress.stats.attendance_days') }}</div>
            <div class="metric-value mt-3">{{ number_format($stats['attendance_days']) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('workflow.student_progress.stats.memorized_pages') }}</div>
            <div class="metric-value mt-3">{{ number_format($stats['memorized_pages']) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('workflow.student_progress.stats.quran_partial_tests') }}</div>
            <div class="metric-value mt-3">{{ number_format($stats['quran_partial_tests']) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('workflow.student_progress.stats.quran_final_tests') }}</div>
            <div class="metric-value mt-3">{{ number_format($stats['quran_final_tests']) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('workflow.student_progress.stats.points') }}</div>
            <div class="metric-value mt-3">{{ number_format($stats['points']) }}</div>
        </article>
    </section>

    <section class="surface-panel p-5 lg:p-6">
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">{{ __('workflow.student_progress.filters.title') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('workflow.student_progress.filters.copy') }}</p>
            </div>

            <div class="admin-toolbar__controls">
                <div class="admin-filter-field">
                    <label for="student-progress-course-filter">{{ __('workflow.student_progress.filters.course') }}</label>
                    <select id="student-progress-course-filter" wire:model.live="courseFilter">
                        <option value="all">{{ __('workflow.student_progress.filters.all_courses') }}</option>
                        @foreach ($courseOptions as $courseOption)
                            <option value="{{ $courseOption->id }}">{{ $courseOption->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
    </section>

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('workflow.student_progress.juz_progress.title') }}</div>
                <div class="admin-grid-meta__summary">{{ __('workflow.student_progress.juz_progress.summary', ['count' => number_format($quranJuzProgress->where('is_complete', true)->count())]) }}</div>
            </div>
        </div>

        @if ($quranJuzProgress->isEmpty())
            <div class="admin-empty-state">{{ __('workflow.student_progress.juz_progress.empty') }}</div>
        @else
        <div class="overflow-x-auto">
            <table class="text-sm">
                <thead>
                    <tr>
                        <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.juz_progress.headers.juz') }}</th>
                        <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.juz_progress.headers.pages') }}</th>
                        <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.juz_progress.headers.status') }}</th>
                        <th class="px-5 py-4 text-right lg:px-6">{{ __('workflow.student_progress.juz_progress.headers.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/6">
                    @foreach ($quranJuzProgress as $row)
                        <tr>
                            <td class="px-5 py-4 text-white lg:px-6">{{ __('workflow.common.labels.juz_number', ['number' => $row->juz->juz_number]) }}</td>
                            <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format($row->memorized_pages) }} / {{ number_format($row->total_pages) }}</td>
                            <td class="px-5 py-4 lg:px-6">
                                <span class="status-chip {{ $row->is_complete ? 'status-chip--emerald' : 'status-chip--slate' }}">
                                    {{ $row->is_complete ? __('workflow.student_progress.juz_progress.complete') : __('workflow.student_progress.juz_progress.incomplete', ['count' => number_format($row->missing_pages->count())]) }}
                                </span>
                            </td>
                            <td class="px-5 py-4 text-right lg:px-6">
                                <button type="button" wire:click="showMissingPages({{ $row->juz->id }})" class="pill-link pill-link--compact">
                                    {{ __('workflow.student_progress.juz_progress.show_missing') }}
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </section>

    @can('memorization.view')
        <section class="surface-table">
            <div class="admin-grid-meta">
                <div>
                    <div class="admin-grid-meta__title">{{ __('workflow.student_progress.memorization.title') }}</div>
                    <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($memorizationSessions->count())]) }}</div>
                </div>
            </div>

            @if ($memorizationSessions->isEmpty())
                <div class="admin-empty-state">{{ __('workflow.student_progress.memorization.empty') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="text-sm">
                        <thead>
                            <tr>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.memorization.headers.date') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.memorization.headers.group') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.memorization.headers.type') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.memorization.headers.pages') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.memorization.headers.teacher') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/6">
                            @foreach ($memorizationSessions as $session)
                                @php
                                    $sessionPages = $session->pages
                                        ->pluck('page_no')
                                        ->map(fn ($page) => (int) $page)
                                        ->values();
                                @endphp
                                <tr>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $session->recorded_on?->format('Y-m-d') ?: __('crud.common.not_available') }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $session->enrollment?->group?->name ?: __('crud.common.not_available') }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ __('workflow.common.entry_type.'.$session->entry_type) }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">
                                        {{ $sessionPages->isNotEmpty()
                                            ? $sessionPages->implode(', ')
                                            : __('workflow.memorization.table.page_range', ['from' => $session->from_page, 'to' => $session->to_page, 'count' => $session->pages_count]) }}
                                    </td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $session->teacher ? trim($session->teacher->first_name.' '.$session->teacher->last_name) : __('crud.common.not_available') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endcan

    @can('quran-partial-tests.view')
        <section class="surface-table">
            <div class="admin-grid-meta">
                <div>
                    <div class="admin-grid-meta__title">{{ __('workflow.student_progress.partial_tests.title') }}</div>
                    <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($quranPartialTests->count())]) }}</div>
                </div>
            </div>

            @if ($quranPartialTests->isEmpty())
                <div class="admin-empty-state">{{ __('workflow.student_progress.partial_tests.empty') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="text-sm">
                        <thead>
                            <tr>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.partial_tests.headers.juz') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.partial_tests.headers.group') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.partial_tests.headers.parts') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.partial_tests.headers.status') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.partial_tests.headers.passed_on') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/6">
                            @foreach ($quranPartialTests as $partialTest)
                                @php
                                    $passedParts = $partialTest->parts->where('status', 'passed')->count();
                                @endphp
                                <tr>
                                    <td class="px-5 py-4 text-white lg:px-6">{{ $partialTest->juz ? __('workflow.common.labels.juz_number', ['number' => $partialTest->juz->juz_number]) : __('crud.common.not_available') }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $partialTest->enrollment?->group?->name ?: __('crud.common.not_available') }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format($passedParts) }} / {{ number_format($partialTest->parts->count()) }}</td>
                                    <td class="px-5 py-4 lg:px-6"><span class="status-chip {{ $partialTest->status === 'passed' ? 'status-chip--emerald' : 'status-chip--slate' }}">{{ __('workflow.quran_partial_tests.statuses.'.$partialTest->status) }}</span></td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $partialTest->passed_on?->format('Y-m-d') ?: __('crud.common.not_available') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endcan
    @can('quran-final-tests.view')
        <section class="surface-table">
            <div class="admin-grid-meta">
                <div>
                    <div class="admin-grid-meta__title">{{ __('workflow.student_progress.final_tests.title') }}</div>
                    <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($quranFinalTests->count())]) }}</div>
                </div>
            </div>

            @if ($quranFinalTests->isEmpty())
                <div class="admin-empty-state">{{ __('workflow.student_progress.final_tests.empty') }}</div>
                @else
                <div class="overflow-x-auto">
                    <table class="text-sm">
                        <thead>
                            <tr>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.final_tests.headers.date') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.final_tests.headers.group') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.final_tests.headers.juz') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.final_tests.headers.score') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.final_tests.headers.status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/6">
                            @foreach ($quranFinalTests as $test)
                                <tr>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $test->tested_on?->format('Y-m-d') ?: __('crud.common.not_available') }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $test->enrollment?->group?->name ?: __('crud.common.not_available') }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $test->juz ? __('workflow.common.labels.juz_number', ['number' => $test->juz->juz_number]) : __('crud.common.not_available') }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $test->score !== null ? number_format((float) $test->score, 2) : __('workflow.common.not_available') }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ __('workflow.common.result_status.'.$test->status) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endcan

    @canany(['quran-awqaf-tests.view', 'quran-tests.view'])
        <section class="surface-table">
            <div class="admin-grid-meta">
                <div>
                    <div class="admin-grid-meta__title">{{ __('workflow.student_progress.awqaf_tests.title') }}</div>
                    <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($awqafTests->count())]) }}</div>
                </div>
            </div>

            @if ($awqafTests->isEmpty())
                <div class="admin-empty-state">{{ __('workflow.student_progress.awqaf_tests.empty') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="text-sm">
                        <thead>
                            <tr>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.awqaf_tests.headers.date') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.awqaf_tests.headers.group') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.awqaf_tests.headers.juz') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.awqaf_tests.headers.type') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.awqaf_tests.headers.score') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.awqaf_tests.headers.status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/6">
                            @foreach ($awqafTests as $test)
                                <tr>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $test->tested_on?->format('Y-m-d') ?: __('crud.common.not_available') }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $test->enrollment?->group?->name ?: __('crud.common.not_available') }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $test->juz ? __('workflow.common.labels.juz_number', ['number' => $test->juz->juz_number]) : __('crud.common.not_available') }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $test->type_label }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $test->score !== null ? number_format((float) $test->score, 2) : __('workflow.common.not_available') }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ __('workflow.common.result_status.'.$test->status) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endcanany

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('workflow.student_progress.enrollments.title') }}</div>
                <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($enrollments->count())]) }}</div>
            </div>
        </div>

        @if ($enrollments->isEmpty())
            <div class="admin-empty-state">{{ __('workflow.student_progress.enrollments.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.enrollments.headers.group') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.enrollments.headers.course') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.enrollments.headers.teacher') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.enrollments.headers.status') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.enrollments.headers.points') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.enrollments.headers.pages') }}</th>
                            <th class="px-5 py-4 text-right lg:px-6">{{ __('workflow.student_progress.enrollments.headers.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($enrollments as $enrollment)
                            @php
                                $enrollmentStatusClass = match ($enrollment->status) {
                                    'active' => 'status-chip status-chip--emerald',
                                    'completed' => 'status-chip status-chip--gold',
                                    default => 'status-chip status-chip--slate',
                                };
                            @endphp
                            <tr>
                                <td class="px-5 py-4 text-white lg:px-6">{{ $enrollment->group?->name ?: __('crud.common.not_available') }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $enrollment->group?->course?->name ?: __('crud.common.not_available') }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">
                                    {{ $enrollment->group?->teacher ? trim($enrollment->group->teacher->first_name.' '.$enrollment->group->teacher->last_name) : __('crud.common.not_available') }}
                                </td>
                                <td class="px-5 py-4 lg:px-6"><span class="{{ $enrollmentStatusClass }}">{{ __('crud.common.status_options.'.$enrollment->status) }}</span></td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format((int) $enrollment->final_points_cached) }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format((int) $enrollment->memorized_pages_cached) }}</td>
                                <td class="px-5 py-4 lg:px-6">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        @can('memorization.view')
                                            <a href="{{ route('enrollments.memorization', $enrollment) }}" wire:navigate class="pill-link pill-link--compact">{{ __('crud.common.actions.memorization') }}</a>
                                        @endcan
                                        @canany(['quran-awqaf-tests.view', 'quran-tests.view'])
                                            <a href="{{ route('enrollments.quran-tests', $enrollment) }}" wire:navigate class="pill-link pill-link--compact">{{ __('crud.common.actions.tests') }}</a>
                                        @endcanany
                                        @can('points.view')
                                            <a href="{{ route('enrollments.points', $enrollment) }}" wire:navigate class="pill-link pill-link--compact">{{ __('crud.common.actions.points') }}</a>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @can('assessment-results.view')
        <section class="surface-table">
            <div class="admin-grid-meta">
                <div>
                    <div class="admin-grid-meta__title">{{ __('workflow.student_progress.assessments.title') }}</div>
                    <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($assessmentResults->count())]) }}</div>
                </div>
            </div>

            @if ($assessmentResults->isEmpty())
                <div class="admin-empty-state">{{ __('workflow.student_progress.assessments.empty') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="text-sm">
                        <thead>
                            <tr>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.assessments.headers.assessment') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.assessments.headers.group') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.assessments.headers.score') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.assessments.headers.status') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.assessments.headers.attempt') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.assessments.headers.teacher') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/6">
                            @foreach ($assessmentResults as $result)
                                <tr>
                                    <td class="px-5 py-4 text-white lg:px-6">
                                        <div>{{ $result->assessment?->title ?: __('crud.common.not_available') }}</div>
                                        <div class="mt-1 text-xs text-neutral-500">{{ $result->assessment?->type?->name ?: __('crud.common.not_available') }}</div>
                                    </td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $result->assessment?->group?->name ?: $result->enrollment?->group?->name ?: __('crud.common.not_available') }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $result->score !== null ? number_format((float) $result->score, 2) : __('workflow.common.not_available') }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ __('workflow.common.result_status.'.$result->status) }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format((int) $result->attempt_no) }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $result->teacher ? trim($result->teacher->first_name.' '.$result->teacher->last_name) : __('crud.common.not_available') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endcan

    @can('points.view')
        <section class="surface-panel p-5 lg:p-6">
            <div class="admin-grid-meta">
                <div>
                    <div class="admin-grid-meta__title">{{ __('workflow.student_progress.point_type_summary.title') }}</div>
                    <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($pointTypeSummary->count())]) }}</div>
                </div>
            </div>

            @if ($pointTypeSummary->isEmpty())
                <div class="admin-empty-state">{{ __('workflow.student_progress.point_type_summary.empty') }}</div>
            @else
                <div class="admin-kpi-grid mt-5">
                    @foreach ($pointTypeSummary as $summary)
                        <article class="stat-card">
                            <div class="kpi-label">{{ $summary->label }}</div>
                            <div class="metric-value mt-3">{{ number_format($summary->points_total) }}</div>
                            <div class="mt-3 text-sm text-neutral-400">
                                {{ __('workflow.student_progress.point_type_summary.entries', ['count' => number_format($summary->entries_count)]) }}
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="surface-table">
            <div class="admin-grid-meta">
                <div>
                    <div class="admin-grid-meta__title">{{ __('workflow.student_progress.points.latest_title') }}</div>
                    <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($latestPointTransactions->count())]) }}</div>
                </div>
            </div>

            @if ($latestPointTransactions->isEmpty())
                <div class="admin-empty-state">{{ __('workflow.student_progress.points.empty') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="text-sm">
                        <thead>
                            <tr>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.points.headers.date') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.points.headers.group') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.points.headers.type') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.points.headers.points') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.points.headers.state') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.points.headers.notes') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/6">
                            @foreach ($latestPointTransactions as $transaction)
                                @php
                                    $state = $transaction->voided_at ? 'voided' : 'active';
                                @endphp
                                <tr>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $transaction->entered_at?->format('Y-m-d H:i') ?: __('crud.common.not_available') }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $transaction->enrollment?->group?->name ?: __('crud.common.not_available') }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $transaction->pointType?->name ?: __('crud.common.not_available') }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format((int) $transaction->points) }}</td>
                                    <td class="px-5 py-4 lg:px-6"><span class="status-chip status-chip--slate">{{ __('workflow.common.ledger_state.'.$state) }}</span></td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $transaction->notes ?: __('crud.common.not_available') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endcan

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('workflow.student_progress.notes.title') }}</div>
                <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($parentVisibleNotes->count())]) }}</div>
            </div>
        </div>

        @if ($parentVisibleNotes->isEmpty())
            <div class="admin-empty-state">{{ __('workflow.student_progress.notes.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.notes.headers.date') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.notes.headers.author') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.notes.headers.body') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($parentVisibleNotes as $note)
                            <tr>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $note->noted_at?->format('Y-m-d H:i') ?: __('crud.common.not_available') }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $note->author?->name ?: __('crud.common.not_available') }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $note->body }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <x-admin.modal :show="$selectedMissingJuz !== null" :title="$selectedMissingJuz ? __('workflow.student_progress.juz_progress.missing_title', ['juz' => $selectedMissingJuz->juz->juz_number]) : ''" :description="__('workflow.student_progress.juz_progress.missing_subtitle')" close-method="closeMissingPages" max-width="2xl">
        @if ($selectedMissingJuz)
            @if ($selectedMissingJuz->missing_pages->isEmpty())
                <div class="rounded-2xl border border-emerald-400/20 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">
                    {{ __('workflow.student_progress.juz_progress.no_missing_pages') }}
                </div>
            @else
                <div class="flex flex-wrap gap-2">
                    @foreach ($selectedMissingJuz->missing_pages as $page)
                        <span class="badge-soft">{{ $page }}</span>
                    @endforeach
                </div>
            @endif

            <div class="mt-5 flex justify-end">
                <button type="button" wire:click="closeMissingPages" class="pill-link">{{ __('crud.common.actions.close') }}</button>
            </div>
        @endif
    </x-admin.modal>
    @else
    <section class="surface-panel p-6">
        <div class="admin-empty-state">
            {{ $studentOptions->isEmpty() ? __('workflow.student_progress.selection.no_students') : __('workflow.student_progress.selection.empty') }}
        </div>
    </section>
    @endif
</div>
