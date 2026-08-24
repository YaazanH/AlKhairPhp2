<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\Course;
use App\Services\CourseEndService;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use AuthorizesPermissions;
    use WithPagination;

    public Course $course;

    public function mount(Course $course): void
    {
        $this->authorizePermission('courses.view');
        $this->course = $course;
    }

    public function with(): array
    {
        $service = app(CourseEndService::class);
        $allRows = $service->studentRows($this->course);
        $studentResultRows = $allRows
            ->filter(fn (array $row) => (int) ($row['attendance_records_count'] ?? 0) > 0)
            ->values();
        $page = $this->getPage();
        $students = new LengthAwarePaginator($studentResultRows->forPage($page, 10), $studentResultRows->count(), 10, $page, [
            'path' => request()->url(), 'pageName' => 'page',
        ]);
        $allFinalTests = $service->finalTestRows($this->course);
        $finalTestsPage = $this->getPage('finalTestsPage');
        $finalTests = new LengthAwarePaginator($allFinalTests->forPage($finalTestsPage, 10), $allFinalTests->count(), 10, $finalTestsPage, [
            'path' => request()->url(), 'pageName' => 'finalTestsPage',
        ]);

        return [
            'summary' => [
                'students' => $allRows->count(),
                'points_before' => $allRows->sum('points_before'),
                'points_after' => $allRows->sum('points_after'),
                'memorized_pages' => $allRows->sum('memorized_pages'),
                'final_tests' => $allFinalTests->count(),
            ],
            'students' => $students,
            'studentResultCount' => $studentResultRows->count(),
            'finalTests' => $finalTests,
        ];
    }
}; ?>

<div class="page-stack">
    <style>
        .course-end-table { table-layout: fixed; width: 100%; }
        .course-end-table th:first-child, .course-end-table td:first-child { width: 5rem; }
        .course-end-table th:not(:first-child), .course-end-table td:not(:first-child) { width: auto; min-width: 0; overflow-wrap: anywhere; }
        .course-end-students-mobile { display: none; }
        .course-end-final-tests-dual { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1px; overflow: hidden; background: rgba(255, 255, 255, .07); }
        .course-end-final-tests-single { display: none; }
        .course-end-final-tests-table-wrap { min-width: 0; overflow-x: auto; background: var(--app-panel); }
        .course-end-final-tests-table :is(th, td) { overflow: hidden; text-align: center; text-overflow: ellipsis; white-space: nowrap; }
        .course-end-final-tests-table th:first-child, .course-end-final-tests-table td:first-child { width: 10%; }
        .course-end-final-tests-table .course-end-final-tests-spacer { width: 8%; padding: 0; }
        .course-end-final-tests-table th:nth-child(3), .course-end-final-tests-table td:nth-child(3) { width: 20%; text-align: start; }
        .course-end-final-tests-table th:nth-child(4), .course-end-final-tests-table td:nth-child(4) { width: 20%; }
        .course-end-final-tests-table th:nth-child(5), .course-end-final-tests-table td:nth-child(5) { width: 19%; }
        .course-end-final-tests-table th:nth-child(6), .course-end-final-tests-table td:nth-child(6) { width: 23%; }
        @media (max-width: 767px) {
            .surface-table .course-end-students-desktop { display: none !important; }
            .course-end-students-mobile { display: grid; gap: .75rem; padding: 0 1rem 1rem; }
            .course-end-final-tests-dual { display: none; }
            .course-end-final-tests-single { display: block; overflow-x: auto; }
            .course-end-final-tests-mobile-table { width: 100%; min-width: 0 !important; table-layout: fixed; font-size: .72rem; }
            .course-end-final-tests-mobile-table :is(th, td) { min-width: 0; padding: .55rem .3rem !important; overflow-wrap: anywhere; text-align: center; white-space: normal; }
            .course-end-final-tests-mobile-table :is(th, td):first-child { width: 9%; }
            .course-end-final-tests-mobile-table :is(th, td):nth-child(2) { width: 29%; text-align: start; }
            .course-end-final-tests-mobile-table :is(th, td):nth-child(3) { width: 16%; }
            .course-end-final-tests-mobile-table :is(th, td):nth-child(4) { width: 19%; }
            .course-end-final-tests-mobile-table :is(th, td):nth-child(5) { width: 27%; }
        }
    </style>
    <section class="page-hero p-6 lg:p-8"><div class="flex flex-wrap items-start justify-between gap-4"><div><div class="eyebrow">{{ __('course_end.eyebrow') }}</div><h1 class="font-display mt-4 text-4xl text-white">{{ __('course_end.title') }}</h1><p class="mt-3 text-neutral-200">{{ $course->name }} — {{ __('course_end.preview_notice') }}</p></div><a href="{{ route('courses.index') }}" wire:navigate class="pill-link">{{ __('course_end.back') }}</a></div></section>
    <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
        @foreach(['students','points_before','points_after','memorized_pages','final_tests'] as $key)<article class="stat-card"><div class="kpi-label">{{ __('course_end.highlights.'.$key) }}</div><div class="metric-value mt-3">{{ number_format($summary[$key]) }}</div></article>@endforeach
    </section>
    <section class="surface-table"><div class="admin-grid-meta"><div><div class="admin-grid-meta__title">{{ __('course_end.students_title') }}</div><div class="admin-grid-meta__summary">{{ __('course_end.student_records', ['count' => number_format($studentResultCount)]) }}</div></div><a href="{{ route('courses.end.students.xlsx', $course) }}" class="pill-link pill-link--accent">XLSX</a></div>
        <div class="course-end-students-mobile">
            @forelse($students as $row)
                <article class="rounded-2xl border border-white/10 bg-white/4 p-4">
                    <div class="flex items-start gap-3"><span class="shrink-0 text-xs text-neutral-500">#{{ $students->firstItem() + $loop->index }}</span><div class="min-w-0"><div class="font-semibold text-white">{{ $row['name'] }}</div><div class="mt-1 text-xs text-neutral-400">{{ $row['group'] }}</div></div></div>
                    <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 border-t border-white/8 pt-4 text-sm">
                        <div><dt class="kpi-label">{{ __('course_end.table.points_after') }}</dt><dd class="mt-1 font-semibold text-white">{{ number_format($row['points_after']) }}</dd></div>
                        <div><dt class="kpi-label">{{ __('course_end.table.days_attended') }}</dt><dd class="mt-1 font-semibold text-white">{{ number_format($row['days_attended']) }}</dd></div>
                        <div><dt class="kpi-label">{{ __('course_end.table.pages') }}</dt><dd class="mt-1 text-neutral-200">{{ number_format($row['memorized_pages']) }}</dd></div>
                        <div><dt class="kpi-label">{{ __('course_end.table.final_tests') }}</dt><dd class="mt-1 text-neutral-200">{{ number_format($row['final_tests']) }}</dd></div>
                        <div class="col-span-2"><dt class="kpi-label">{{ __('course_end.table.final_score') }}</dt><dd class="mt-1 text-neutral-200">{{ $row['final_score'] !== null ? number_format($row['final_score'], 2) : '-' }}</dd></div>
                    </dl>
                </article>
            @empty
                <div class="admin-empty-state">{{ __('course_end.empty') }}</div>
            @endforelse
        </div>
        <div class="course-end-students-desktop overflow-x-auto"><table class="course-end-table text-sm"><thead><tr>@foreach(['#','name','group','points_after','days_attended','pages','final_tests','final_score'] as $header)<th class="px-4 py-3 text-left">{{ $header === '#' ? '#' : __('course_end.table.'.$header) }}</th>@endforeach</tr></thead><tbody class="divide-y divide-white/6">
        @forelse($students as $row)<tr><td class="px-4 py-3">{{ $students->firstItem() + $loop->index }}</td><td class="px-4 py-3 text-white">{{ $row['name'] }}</td><td class="px-4 py-3">{{ $row['group'] }}</td><td class="px-4 py-3">{{ number_format($row['points_after']) }}</td><td class="px-4 py-3">{{ number_format($row['days_attended']) }}</td><td class="px-4 py-3">{{ number_format($row['memorized_pages']) }}</td><td class="px-4 py-3">{{ number_format($row['final_tests']) }}</td><td class="px-4 py-3">{{ $row['final_score'] !== null ? number_format($row['final_score'], 2) : '-' }}</td></tr>@empty<tr><td colspan="8" class="admin-empty-state">{{ __('course_end.empty') }}</td></tr>@endforelse
        </tbody></table></div>@if($students->hasPages())<div class="border-t border-white/8 px-5 py-4">{{ $students->links() }}</div>@endif
    </section>
    <section class="surface-table">
        <div class="admin-grid-meta"><div><div class="admin-grid-meta__title">{{ __('course_end.final_tests_title') }}</div><div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($finalTests->total())]) }}</div></div><a href="{{ route('courses.end.final-tests.pdf', $course) }}" target="_blank" class="pill-link pill-link--accent">PDF</a></div>
        @php($finalTestPageRows = $finalTests->getCollection()->values())
        @php($finalTestColumns = $finalTestPageRows->isEmpty() ? collect([collect()]) : $finalTestPageRows->chunk((int) ceil($finalTestPageRows->count() / 2)))
        <div class="course-end-final-tests-single">
            <table class="course-end-final-tests-mobile-table text-sm">
                <thead><tr><th>#</th><th>{{ __('course_end.table.name') }}</th><th>{{ __('course_end.table.juz') }}</th><th>{{ __('course_end.table.mark') }}</th><th>{{ __('course_end.table.grade') }}</th></tr></thead>
                <tbody class="divide-y divide-white/6">
                    @forelse($finalTestPageRows as $rowIndex => $row)
                        <tr><td class="text-neutral-400">{{ $finalTests->firstItem() + $rowIndex }}</td><td class="font-medium text-white">{{ $row['name'] }}</td><td>{{ $row['juz'] }}</td><td>{{ \App\Support\PercentageFormatter::format($row['mark']) }}</td><td class="font-medium text-emerald-100">{{ __('course_end.grades.'.$row['grade']) }}</td></tr>
                    @empty
                        <tr><td colspan="5" class="admin-empty-state">{{ __('course_end.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="course-end-final-tests-dual">
            @foreach($finalTestColumns as $columnRows)
                <div class="course-end-final-tests-table-wrap">
                    <table class="course-end-table course-end-final-tests-table text-sm">
                        <thead><tr><th class="px-3 py-3">#</th><th class="course-end-final-tests-spacer" aria-hidden="true"></th><th class="px-3 py-3">{{ __('course_end.table.name') }}</th><th class="px-3 py-3">{{ __('course_end.table.juz') }}</th><th class="px-3 py-3">{{ __('course_end.table.mark') }}</th><th class="px-3 py-3">{{ __('course_end.table.grade') }}</th></tr></thead>
                        <tbody class="divide-y divide-white/6">
                            @forelse($columnRows as $rowIndex => $row)
                                <tr><td class="px-3 py-3 text-neutral-400">{{ $finalTests->firstItem() + $rowIndex }}</td><td class="course-end-final-tests-spacer" aria-hidden="true"></td><td class="px-3 py-3 font-medium text-white" title="{{ $row['name'] }}">{{ $row['name'] }}</td><td class="px-3 py-3">{{ $row['juz'] }}</td><td class="px-3 py-3">{{ \App\Support\PercentageFormatter::format($row['mark']) }}</td><td class="px-3 py-3 font-medium text-emerald-100">{{ __('course_end.grades.'.$row['grade']) }}</td></tr>
                            @empty
                                <tr><td colspan="6" class="admin-empty-state">{{ __('course_end.empty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endforeach
        </div>
        @if($finalTests->hasPages())<div class="border-t border-white/8 px-5 py-4">{{ $finalTests->links() }}</div>@endif
    </section>
    <section class="surface-panel p-5 lg:p-6"><div class="admin-toolbar"><div class="admin-toolbar__title">{{ __('course_end.report_cards') }}</div><div class="admin-toolbar__actions"><a href="{{ route('courses.end.report-cards.create', $course) }}" class="pill-link pill-link--accent">{{ __('course_end.print_cards') }}</a></div></div></section>
</div>
