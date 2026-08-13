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
        $page = $this->getPage();
        $students = new LengthAwarePaginator($allRows->forPage($page, 10), $allRows->count(), 10, $page, [
            'path' => request()->url(), 'pageName' => 'page',
        ]);

        return [
            'summary' => [
                'students' => $allRows->count(),
                'points_before' => $allRows->sum('points_before'),
                'points_after' => $allRows->sum('points_after'),
                'memorized_pages' => $allRows->sum('memorized_pages'),
                'final_tests' => $service->finalTestRows($this->course)->count(),
            ],
            'students' => $students,
            'finalTests' => $service->finalTestRows($this->course),
        ];
    }
}; ?>

<div class="page-stack">
    <style>.page-stack > section.surface-table table { table-layout: fixed; width: 100%; }</style>
    <section class="page-hero p-6 lg:p-8"><div class="eyebrow">{{ __('course_end.eyebrow') }}</div><h1 class="font-display mt-4 text-4xl text-white">{{ __('course_end.title') }}</h1><p class="mt-3 text-neutral-200">{{ $course->name }} — {{ __('course_end.preview_notice') }}</p></section>
    <div><a href="{{ route('courses.index') }}" wire:navigate class="pill-link pill-link--compact">{{ __('course_end.back') }}</a></div>
    <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
        @foreach(['students','points_before','points_after','memorized_pages','final_tests'] as $key)<article class="stat-card"><div class="kpi-label">{{ __('course_end.highlights.'.$key) }}</div><div class="metric-value mt-3">{{ number_format($summary[$key]) }}</div></article>@endforeach
    </section>
    <section class="surface-table"><div class="admin-grid-meta"><div><div class="admin-grid-meta__title">{{ __('course_end.students_title') }}</div><div class="admin-grid-meta__summary">{{ __('course_end.sorted_notice') }}</div></div><a href="{{ route('courses.end.students.xlsx', $course) }}" class="pill-link pill-link--accent">XLSX</a></div>
        <div class="overflow-x-auto"><table class="text-sm"><thead><tr>@foreach(['#','name','group','points_after','days_attended','pages','final_tests','final_score'] as $header)<th class="px-4 py-3 text-left">{{ $header === '#' ? '#' : __('course_end.table.'.$header) }}</th>@endforeach</tr></thead><tbody class="divide-y divide-white/6">
        @forelse($students as $row)<tr><td class="px-4 py-3">{{ $students->firstItem() + $loop->index }}</td><td class="px-4 py-3 text-white">{{ $row['name'] }}</td><td class="px-4 py-3">{{ $row['group'] }}</td><td class="px-4 py-3">{{ number_format($row['points_after']) }}</td><td class="px-4 py-3">{{ number_format($row['days_attended']) }}</td><td class="px-4 py-3">{{ number_format($row['memorized_pages']) }}</td><td class="px-4 py-3">{{ number_format($row['final_tests']) }}</td><td class="px-4 py-3">{{ $row['final_score'] !== null ? number_format($row['final_score'], 2) : '-' }}</td></tr>@empty<tr><td colspan="8" class="admin-empty-state">{{ __('course_end.empty') }}</td></tr>@endforelse
        </tbody></table></div>@if($students->hasPages())<div class="border-t border-white/8 px-5 py-4">{{ $students->links() }}</div>@endif
    </section>
    <section class="surface-table"><div class="admin-grid-meta"><div class="admin-grid-meta__title">{{ __('course_end.final_tests_title') }}</div><a href="{{ route('courses.end.final-tests.pdf', $course) }}" target="_blank" class="pill-link pill-link--accent">PDF</a></div><div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-4 py-3">#</th><th class="px-4 py-3">{{ __('course_end.table.name') }}</th><th class="px-4 py-3">{{ __('course_end.table.juz') }}</th><th class="px-4 py-3">{{ __('course_end.table.mark') }}</th></tr></thead><tbody>@foreach($finalTests as $row)<tr><td class="px-4 py-3">{{ $loop->iteration }}</td><td class="px-4 py-3">{{ $row['name'] }}</td><td class="px-4 py-3">{{ $row['juz'] }}</td><td class="px-4 py-3">{{ \App\Support\PercentageFormatter::format($row['mark']) }}</td></tr>@endforeach</tbody></table></div></section>
    <section class="surface-panel p-5 lg:p-6"><div class="admin-toolbar"><div><div class="admin-toolbar__title">{{ __('course_end.report_cards') }}</div><p class="admin-toolbar__subtitle">{{ __('course_end.report_cards_help') }}</p></div><div class="admin-toolbar__actions"><a href="{{ route('print-templates.print.create', ['course_id' => $course->id]) }}" class="pill-link pill-link--accent">{{ __('course_end.print_cards') }}</a></div></div></section>
</div>
