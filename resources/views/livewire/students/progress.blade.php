<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\AssessmentResult;
use App\Models\Enrollment;
use App\Models\MemorizationSession;
use App\Models\PointTransaction;
use App\Models\QuranFinalTest;
use App\Models\QuranTest;
use App\Models\Student;
use App\Models\StudentNote;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public Student $currentStudent;
    public string $courseFilter = 'all';

    public function mount(Student $student): void
    {
        $this->authorizePermission('students.view');

        $this->currentStudent = Student::query()
            ->with(['gradeLevel', 'parentProfile', 'quranCurrentJuz'])
            ->findOrFail($student->id);

        $this->authorizeScopedStudentAccess($this->currentStudent);
    }

    public function with(): array
    {
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
                    ->with(['enrollment.group', 'teacher'])
                    ->where('student_id', $this->currentStudent->id)
            )
                ->latest('recorded_on')
                ->latest('id')
                ->get()
            : collect();

        $quranTests = auth()->user()->can('quran-tests.view')
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
                ->concat(
                    $this->scopeQuranFinalTestsQuery(
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
                                'type_label' => __('ui.nav.quran_final_tests'),
                            ]);
                        })
                )
                ->sortByDesc('sort_key')
                ->values()
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
            'courseOptions' => $courseOptions,
            'enrollments' => $enrollments,
            'assessmentResults' => $assessmentResults,
            'memorizationSessions' => $memorizationSessions,
            'quranTests' => $quranTests,
            'pointTransactions' => $pointTransactions,
            'pointTypeSummary' => $pointTypeSummary,
            'parentVisibleNotes' => $parentVisibleNotes,
            'stats' => [
                'active_enrollments' => $enrollments->where('status', 'active')->count(),
                'memorized_pages' => (int) $enrollments->sum('memorized_pages_cached'),
                'assessment_results' => $assessmentResults->count(),
                'points' => (int) $enrollments->sum('final_points_cached'),
            ],
        ];
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="dashboard-split grid gap-6 xl:grid-cols-[minmax(0,1.35fr)_22rem] xl:items-start">
            <div>
                <a href="{{ route('students.index') }}" wire:navigate class="text-sm font-medium text-neutral-200/80 hover:text-white">{{ __('ui.nav.students') }}</a>
                <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.student_progress.title') }}</h1>
                <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('workflow.student_progress.subtitle') }}</p>
            </div>

            <aside class="surface-panel surface-panel--soft p-5 lg:p-6">
                <div class="student-inline">
                    <x-student-avatar :student="$studentRecord" size="md" />
                    <div class="student-inline__body">
                        <div class="student-inline__name">{{ $studentRecord->first_name }} {{ $studentRecord->last_name }}</div>
                        <div class="student-inline__meta">{{ $studentRecord->gradeLevel?->name ?: __('crud.common.not_available') }}</div>
                    </div>
                </div>

                <div class="mt-6 space-y-4 text-sm">
                    <div>
                        <div class="kpi-label">{{ __('workflow.student_progress.profile.parent') }}</div>
                        <div class="mt-2 text-white">{{ $studentRecord->parentProfile?->father_name ?: __('crud.common.not_available') }}</div>
                    </div>
                    <div>
                        <div class="kpi-label">{{ __('workflow.student_progress.profile.school') }}</div>
                        <div class="mt-2 text-white">{{ $studentRecord->school_name ?: __('crud.common.not_available') }}</div>
                    </div>
                    <div>
                        <div class="kpi-label">{{ __('workflow.student_progress.profile.current_juz') }}</div>
                        <div class="mt-2 text-white">{{ $studentRecord->quranCurrentJuz ? __('workflow.common.labels.juz_number', ['number' => $studentRecord->quranCurrentJuz->juz_number]) : __('crud.common.not_available') }}</div>
                    </div>
                    <div>
                        <div class="kpi-label">{{ __('workflow.student_progress.profile.joined_at') }}</div>
                        <div class="mt-2 text-white">{{ $studentRecord->joined_at?->format('Y-m-d') ?: __('crud.common.not_available') }}</div>
                    </div>
                </div>
            </aside>
        </div>
    </section>

    <section class="admin-kpi-grid">
        <article class="stat-card">
            <div class="kpi-label">{{ __('workflow.student_progress.stats.active_enrollments') }}</div>
            <div class="metric-value mt-3">{{ number_format($stats['active_enrollments']) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('workflow.student_progress.stats.memorized_pages') }}</div>
            <div class="metric-value mt-3">{{ number_format($stats['memorized_pages']) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('workflow.student_progress.stats.assessment_results') }}</div>
            <div class="metric-value mt-3">{{ number_format($stats['assessment_results']) }}</div>
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
                                        @can('quran-tests.view')
                                            <a href="{{ route('enrollments.quran-tests', $enrollment) }}" wire:navigate class="pill-link pill-link--compact">{{ __('crud.common.actions.tests') }}</a>
                                        @endcan
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
                                <tr>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $session->recorded_on?->format('Y-m-d') ?: __('crud.common.not_available') }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $session->enrollment?->group?->name ?: __('crud.common.not_available') }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ __('workflow.common.entry_type.'.$session->entry_type) }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ __('workflow.memorization.table.page_range', ['from' => $session->from_page, 'to' => $session->to_page, 'count' => $session->pages_count]) }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $session->teacher ? trim($session->teacher->first_name.' '.$session->teacher->last_name) : __('crud.common.not_available') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endcan

    @can('quran-tests.view')
        <section class="surface-table">
            <div class="admin-grid-meta">
                <div>
                    <div class="admin-grid-meta__title">{{ __('workflow.student_progress.quran_tests.title') }}</div>
                    <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($quranTests->count())]) }}</div>
                </div>
            </div>

            @if ($quranTests->isEmpty())
                <div class="admin-empty-state">{{ __('workflow.student_progress.quran_tests.empty') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="text-sm">
                        <thead>
                            <tr>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.quran_tests.headers.date') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.quran_tests.headers.group') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.quran_tests.headers.juz') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.quran_tests.headers.type') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.quran_tests.headers.score') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.student_progress.quran_tests.headers.status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/6">
                            @foreach ($quranTests as $test)
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
                    <div class="admin-grid-meta__title">{{ __('workflow.student_progress.points.title') }}</div>
                    <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($pointTransactions->count())]) }}</div>
                </div>
            </div>

            @if ($pointTransactions->isEmpty())
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
                            @foreach ($pointTransactions as $transaction)
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
</div>
