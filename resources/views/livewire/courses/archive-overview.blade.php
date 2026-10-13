<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\Assessment;
use App\Models\AssessmentResult;
use App\Models\AssessmentType;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\GroupAttendanceDay;
use App\Models\PointTransaction;
use App\Models\QuranFinalTest;
use App\Models\StudentAttendanceDay;
use App\Models\StudentAttendanceRecord;
use App\Models\TeacherAttendanceDay;
use App\Services\AccessScopeService;
use App\Services\CourseCompletionRuleService;
use App\Services\CourseEndService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use AuthorizesPermissions;
    use WithPagination;

    public Course $course;

    #[Url]
    public string $section = 'attendance';

    public string $attendanceAudience = 'students';

    public ?int $selectedStudentAttendanceDayId = null;

    public ?int $selectedGroupAttendanceDayId = null;

    public ?int $selectedTeacherAttendanceDayId = null;

    public ?int $selectedAssessmentId = null;

    public function mount(Course $course): void
    {
        $this->authorizePermission('courses.view');
        abort_if($course->is_active, 404);

        $this->course = $course->load('academicYear');
        if (! in_array($this->section, $this->sections(), true)) {
            $this->section = 'attendance';
        }
    }

    public function showSection(string $section): void
    {
        abort_unless(in_array($section, $this->sections(), true), 404);

        $this->section = $section;
        $this->resetPage();
    }

    public function showAttendanceAudience(string $audience): void
    {
        abort_unless(in_array($audience, ['students', 'teachers'], true), 404);

        $this->attendanceAudience = $audience;
        $this->resetPage($audience === 'students' ? 'studentAttendancePage' : 'teacherAttendancePage');
    }

    public function openStudentAttendanceDay(int $dayId): void
    {
        $day = $this->scopedStudentAttendanceDay($dayId);
        $this->selectedStudentAttendanceDayId = $day->id;
        $this->selectedGroupAttendanceDayId = null;
    }

    public function openGroupAttendanceDay(int $groupDayId): void
    {
        abort_unless($this->selectedStudentAttendanceDayId, 404);

        $groupDay = $this->accessScopes()->scopeGroupAttendanceDays(
            GroupAttendanceDay::query()
                ->where('student_attendance_day_id', $this->selectedStudentAttendanceDayId)
                ->whereHas('studentAttendanceDay', fn (Builder $query) => $query->where('course_id', $this->course->id)),
            auth()->user(),
        )->findOrFail($groupDayId);

        $this->selectedGroupAttendanceDayId = $groupDay->id;
    }

    public function backToAttendanceGroups(): void
    {
        $this->selectedGroupAttendanceDayId = null;
    }

    public function openTeacherAttendanceDay(int $dayId): void
    {
        $day = $this->scopedTeacherAttendanceDay($dayId);
        $this->selectedTeacherAttendanceDayId = $day->id;
    }

    public function openAssessmentResults(int $assessmentId): void
    {
        $assessment = $this->accessScopes()->scopeAssessments(
            Assessment::query()->where(fn (Builder $query) => $query
                ->whereHas('group', fn (Builder $group) => $group->where('course_id', $this->course->id))
                ->orWhereHas('groups', fn (Builder $group) => $group->where('course_id', $this->course->id))),
            auth()->user(),
        )->findOrFail($assessmentId);

        $this->selectedAssessmentId = $assessment->id;
    }

    public function closeRecordDetail(): void
    {
        $this->selectedStudentAttendanceDayId = null;
        $this->selectedGroupAttendanceDayId = null;
        $this->selectedTeacherAttendanceDayId = null;
        $this->selectedAssessmentId = null;
    }

    public function with(): array
    {
        return [
            'metrics' => $this->metrics(),
            'completionSettings' => $this->completionSettings(),
        ];
    }

    protected function studentAttendanceDays(): LengthAwarePaginator
    {
        return $this->accessScopes()
            ->scopeStudentAttendanceDays(
                StudentAttendanceDay::query()
                    ->with(['groupAttendanceDays.group', 'groupAttendanceDays.records.status'])
                    ->where('course_id', $this->course->id),
                auth()->user(),
            )
            ->latest('attendance_date')
            ->latest('id')
            ->paginate(15, pageName: 'studentAttendancePage');
    }

    protected function teacherAttendanceDays(): LengthAwarePaginator
    {
        return $this->accessScopes()
            ->scopeTeacherAttendanceDays(
                TeacherAttendanceDay::query()
                    ->with(['records' => fn ($query) => $query
                        ->with(['teacher', 'status'])
                        ->where(fn ($records) => $records
                            ->where('archived_course_id', $this->course->id)
                            ->orWhereNull('archived_course_id'))])
                    ->where(fn (Builder $days) => $days
                        ->where('course_id', $this->course->id)
                        ->orWhereHas('records', fn (Builder $records) => $records->where('archived_course_id', $this->course->id))),
                auth()->user(),
            )
            ->latest('attendance_date')
            ->latest('id')
            ->paginate(15, pageName: 'teacherAttendancePage');
    }

    protected function assessments(): LengthAwarePaginator
    {
        return $this->accessScopes()
            ->scopeAssessments(
                Assessment::query()
                    ->with(['group.course', 'groups.course', 'type'])
                    ->withCount('results')
                    ->withAvg('results', 'score')
                    ->where(fn (Builder $assessments) => $assessments
                        ->whereHas('group', fn (Builder $group) => $group->where('course_id', $this->course->id))
                        ->orWhereHas('groups', fn (Builder $group) => $group->where('course_id', $this->course->id))),
                auth()->user(),
            )
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->latest('due_at')
            ->latest('id')
            ->paginate(15, pageName: 'assessmentsPage');
    }

    protected function pointTransactions(): LengthAwarePaginator
    {
        return $this->accessScopes()
            ->scopePointTransactions(
                PointTransaction::query()
                    ->with(['enrollment.group', 'student', 'pointType', 'policy'])
                    ->whereHas('enrollment.group', fn (Builder $query) => $query->where('course_id', $this->course->id)),
                auth()->user(),
            )
            ->latest('entered_at')
            ->latest('id')
            ->paginate(15, pageName: 'pointsPage');
    }

    protected function endRows(): LengthAwarePaginator
    {
        $rows = app(CourseEndService::class)->studentRows($this->course);
        $page = $this->getPage('endPage');

        return new LengthAwarePaginator(
            $rows->forPage($page, 15)->values(),
            $rows->count(),
            15,
            $page,
            ['path' => request()->url(), 'pageName' => 'endPage'],
        );
    }

    protected function finalTests(): LengthAwarePaginator
    {
        $rows = app(CourseEndService::class)->finalTestRows($this->course);
        $page = $this->getPage('finalTestsPage');

        return new LengthAwarePaginator(
            $rows->forPage($page, 15)->values(),
            $rows->count(),
            15,
            $page,
            ['path' => request()->url(), 'pageName' => 'finalTestsPage'],
        );
    }

    protected function metrics(): array
    {
        $groupIds = Group::query()->where('course_id', $this->course->id)->pluck('id');
        $enrollments = Enrollment::query()->whereIn('group_id', $groupIds);
        $attendanceDays = GroupAttendanceDay::query()->whereIn('group_id', $groupIds)->withCount([
            'records',
            'records as present_records_count' => fn ($query) => $query->whereHas('status', fn ($status) => $status->where('is_present', true)),
        ])->get();
        $dailyPresentTotals = $attendanceDays
            ->groupBy('student_attendance_day_id')
            ->map(fn ($days) => (int) $days->sum('present_records_count'));
        $courseEndRows = app(CourseEndService::class)->studentRows($this->course);

        return [
            ['label' => __('dashboard.manager.stats.enrolled_students.label'), 'value' => (clone $enrollments)->distinct('student_id')->count('student_id')],
            ['label' => __('dashboard.manager.stats.active_groups.label'), 'value' => $groupIds->count()],
            ['label' => __('dashboard.manager.stats.average_daily_attendance.label'), 'value' => $dailyPresentTotals->isEmpty() ? 0 : (int) round((float) $dailyPresentTotals->average())],
            ['label' => __('dashboard.manager.stats.memorized_pages.label'), 'value' => (int) (clone $enrollments)->sum('memorized_pages_cached')],
            ['label' => __('dashboard.manager.stats.final_tested_juz.label'), 'value' => QuranFinalTest::query()->whereHas('enrollment', fn ($query) => $query->whereIn('group_id', $groupIds))->where('status', 'passed')->count()],
            ['label' => __('dashboard.manager.stats.total_points.label'), 'value' => (int) $courseEndRows->sum('points_before')],
            ['label' => __('course_end.highlights.points_after'), 'value' => (int) $courseEndRows->sum('points_after')],
        ];
    }

    protected function selectedStudentAttendanceDay(): ?StudentAttendanceDay
    {
        if (! $this->selectedStudentAttendanceDayId) {
            return null;
        }

        return $this->scopedStudentAttendanceDay($this->selectedStudentAttendanceDayId)
            ->load(['groupAttendanceDays' => fn ($query) => $this->accessScopes()
                ->scopeGroupAttendanceDays($query, auth()->user())
                ->with(['group.teacher'])
                ->withCount(['records', 'records as present_records_count' => fn ($records) => $records->whereHas('status', fn ($status) => $status->where('is_present', true))])]);
    }

    protected function selectedGroupAttendanceDay(): ?GroupAttendanceDay
    {
        if (! $this->selectedGroupAttendanceDayId) {
            return null;
        }

        $groupDay = $this->accessScopes()->scopeGroupAttendanceDays(
            GroupAttendanceDay::query()
                ->where('student_attendance_day_id', $this->selectedStudentAttendanceDayId)
                ->whereHas('studentAttendanceDay', fn (Builder $query) => $query->where('course_id', $this->course->id)),
            auth()->user(),
        )->with('group')
            ->findOrFail($this->selectedGroupAttendanceDayId);

        $records = $this->accessScopes()->scopeStudentAttendanceRecords(
            StudentAttendanceRecord::query()->where('group_attendance_day_id', $groupDay->id),
            auth()->user(),
        )->with(['enrollment.student', 'status'])->get();

        return $groupDay->setRelation('records', $records);
    }

    protected function selectedTeacherAttendanceDay(): ?TeacherAttendanceDay
    {
        if (! $this->selectedTeacherAttendanceDayId) {
            return null;
        }

        return $this->scopedTeacherAttendanceDay($this->selectedTeacherAttendanceDayId)
            ->load(['records' => fn ($query) => $this->accessScopes()
                ->scopeTeacherAttendanceRecords($query, auth()->user())
                ->where('archived_course_id', $this->course->id)
                ->with(['teacher', 'status'])]);
    }

    protected function selectedAssessment(): ?Assessment
    {
        if (! $this->selectedAssessmentId) {
            return null;
        }

        $assessment = $this->accessScopes()->scopeAssessments(
            Assessment::query()->with(['type', 'group', 'groups']),
            auth()->user(),
        )->findOrFail($this->selectedAssessmentId);

        abort_unless(
            ($assessment->group?->course_id === $this->course->id)
            || $assessment->groups->contains('course_id', $this->course->id),
            404,
        );

        $results = $this->accessScopes()->scopeAssessmentResults(
            AssessmentResult::query()->where('assessment_id', $assessment->id),
            auth()->user(),
        )
            ->with(['student', 'enrollment.group', 'teacher'])
            ->orderBy('student_id')
            ->get();

        $assessment->setRelation('results', $results);

        return $assessment;
    }

    protected function completionSettings(): array
    {
        $settings = app(CourseCompletionRuleService::class)->settingsForCourse($this->course);

        return [
            ...$settings,
            'grade_names' => collect($settings['grade_names'] ?? GradeLevel::query()->pluck('name', 'id')->all()),
            'assessment_type_names' => collect($settings['assessment_type_names'] ?? AssessmentType::query()->pluck('name', 'id')->all()),
        ];
    }

    protected function scopedStudentAttendanceDay(int $dayId): StudentAttendanceDay
    {
        return $this->accessScopes()->scopeStudentAttendanceDays(
            StudentAttendanceDay::query()->where('course_id', $this->course->id),
            auth()->user(),
        )->findOrFail($dayId);
    }

    protected function scopedTeacherAttendanceDay(int $dayId): TeacherAttendanceDay
    {
        return $this->accessScopes()->scopeTeacherAttendanceDays(
            TeacherAttendanceDay::query()->where(fn (Builder $days) => $days
                ->where('course_id', $this->course->id)
                ->orWhereHas('records', fn (Builder $records) => $records->where('archived_course_id', $this->course->id))),
            auth()->user(),
        )->findOrFail($dayId);
    }

    protected function accessScopes(): AccessScopeService
    {
        return app(AccessScopeService::class);
    }

    protected function sections(): array
    {
        return ['attendance', 'assessments', 'points', 'end'];
    }
}; ?>

<div class="page-stack" data-course-archive-overview data-read-only="true">
    <style>
        [data-course-archive-overview] .table-content :is(th, td) { padding: 1rem 1.25rem; text-align: start; vertical-align: middle; }
        [data-course-archive-overview] .course-manager-tabs { scrollbar-width: none; }
        [data-course-archive-overview] .course-manager-tabs::-webkit-scrollbar { display: none; }
        [data-course-archive-overview] .archive-course-hero__dates { display: grid; grid-template-columns: repeat(2, minmax(11rem, 1fr)); gap: .75rem; }
        [data-course-archive-overview] .archive-course-date-box { min-height: 5.5rem; display: flex; flex-direction: column; justify-content: center; border: 1px solid rgba(110, 231, 183, .2); border-radius: 1rem; background: rgba(52, 211, 153, .08); padding: .75rem 1.25rem; text-align: center; }
        [data-course-archive-overview] .archive-course-date-box bdi { margin-top: .25rem; color: rgb(209 250 229); font-size: 1.05rem; font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
        [data-course-archive-overview] .archive-course-date-range__values { direction: rtl; display: flex; align-items: center; justify-content: center; gap: .65rem; margin-top: .25rem; color: rgb(209 250 229); font-size: 1.05rem; font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
        [data-course-archive-overview] .archive-course-date-range__values bdi { margin-top: 0; }
        [data-course-archive-overview] .archive-course-date-range__arrow { display: inline-flex; align-items: center; align-self: center; line-height: 1; color: rgb(167 243 208 / .72); font-size: 1.2em; }
        [data-course-archive-overview] .archive-live-workspace [data-course-archive-real-component] > .page-stack { gap: 1rem; padding: 0; }
        [data-course-archive-overview] .archive-live-workspace [data-course-archive-real-component] .page-hero { display: none; }
        [data-course-archive-overview] .archive-live-workspace [data-course-archive-real-component] .surface-table { width: 100%; }
        [data-course-archive-overview] .archive-manager-highlights { grid-template-columns: repeat(7, minmax(0, 1fr)); }
        [data-course-archive-overview] .archive-manager-highlights .stat-card { min-width: 0; padding-inline: .72rem; }
        [data-course-archive-overview] .archive-manager-highlights .kpi-label { font-size: clamp(.7rem, .75vw, .88rem); }
        [data-course-archive-overview] .archive-manager-highlights .metric-value { font-size: clamp(1.45rem, 2vw, 2.1rem); }
        [data-course-archive-overview] .archive-settings { overflow: hidden; }
        [data-course-archive-overview] .archive-settings__header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .8rem 1.1rem; border-bottom: 1px solid rgba(255,255,255,.08); background: linear-gradient(90deg, rgba(180,140,90,.08), rgba(52,211,153,.06)); }
        [data-course-archive-overview] .archive-settings__body { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: .65rem; padding: .85rem; }
        [data-course-archive-overview] .archive-settings__group { display: grid; align-content: start; gap: .5rem; min-width: 0; }
        [data-course-archive-overview] .archive-settings__compact-rule { direction: ltr; display: grid; grid-template-columns: minmax(7rem, .7fr) minmax(0, 2fr); align-items: center; gap: .7rem; min-height: 3.8rem; border: 1px solid rgba(196,164,118,.16); border-radius: .9rem; background: linear-gradient(145deg, rgba(196,164,118,.055), rgba(255,255,255,.02)); padding: .65rem .8rem; }
        [data-course-archive-overview] .archive-settings__scope { direction: rtl; grid-column: 1; min-width: 0; overflow: hidden; color: var(--app-muted); font-size: .78rem; font-weight: 650; text-align: end; text-overflow: ellipsis; white-space: nowrap; }
        [data-course-archive-overview] .archive-settings__condition { direction: rtl; grid-column: 2; display: flex; min-width: 0; flex-wrap: wrap; align-items: center; justify-content: flex-start; gap: .35rem .65rem; color: var(--app-text); font-size: .82rem; }
        [data-course-archive-overview] .archive-settings__condition strong { color: rgb(209 250 229); font-size: 1rem; }
        [data-course-archive-overview] .archive-settings__operator { border: 1px solid rgba(196,164,118,.2); border-radius: 999px; padding: .15rem .45rem; color: rgb(253 230 195); font-size: .72rem; }
        [data-course-archive-overview] .archive-settings__summary { grid-column: 1 / -1; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .65rem; }
        [data-course-archive-overview] .archive-settings__summary article { display: flex; min-width: 0; min-height: 3.8rem; align-items: center; justify-content: space-between; gap: .65rem; border: 1px solid rgba(110,231,183,.16); border-radius: .8rem; background: rgba(52,211,153,.045); padding: .65rem .8rem; }
        [data-course-archive-overview] .archive-settings__summary strong { color: rgb(209 250 229); font-size: 1.05rem; }
        [data-course-archive-overview] .archive-attendance-switch { width: min(100%, 34rem); padding: 1rem 1.5rem 0; }
        [data-course-archive-overview] .archive-detail-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .75rem; }
        [data-course-archive-overview] .archive-detail-card { border: 1px solid rgba(255,255,255,.1); border-radius: 1rem; background: rgba(255,255,255,.035); padding: 1rem; }
        @media (max-width: 699px) {
            [data-course-archive-overview] .table-content :is(th, td) { padding: .8rem .9rem; }
            [data-course-archive-overview] .archive-course-hero { align-items: stretch; }
            [data-course-archive-overview] .archive-course-hero__dates { grid-template-columns: 1fr; }
            [data-course-archive-overview] .archive-manager-highlights { grid-template-columns: repeat(2, minmax(0,1fr)); }
            [data-course-archive-overview] .archive-settings__body { grid-template-columns: 1fr; }
            [data-course-archive-overview] .archive-settings__compact-rule { direction: rtl; grid-template-columns: 1fr; gap: .35rem; }
            [data-course-archive-overview] .archive-settings__compact-rule > * { grid-column: 1; }
            [data-course-archive-overview] .archive-settings__condition { justify-content: flex-start; }
            [data-course-archive-overview] .archive-settings__summary { grid-column: auto; }
            [data-course-archive-overview] .archive-detail-summary,
            [data-course-archive-overview] .archive-completion-rules { grid-template-columns: 1fr; }
        }
    </style>
    <section class="page-hero p-6 lg:p-8">
        <div class="archive-course-hero flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
                <x-back-link :href="route('courses.index')" navigate />
                <div class="eyebrow mt-4">{{ __('course_archive_overview.eyebrow') }}</div>
                <h1 class="record-course-name font-display mt-4 text-4xl text-white">{{ __('course_archive_overview.archive_name', ['course' => $course->name]) }}</h1>
                <p class="mt-3 text-neutral-200">{{ __('course_archive_overview.read_only') }}</p>
            </div>
            <div class="archive-course-hero__dates shrink-0">
                <div class="archive-course-date-box" data-course-archive-date-range>
                    <span class="text-xs text-neutral-300">{{ __('course_archive_overview.dates.course') }}</span>
                    <div class="archive-course-date-range__values">
                        <bdi dir="ltr" data-course-archive-start-date>{{ $course->starts_on?->format('d-m-Y') ?: '-' }}</bdi>
                        <span class="archive-course-date-range__arrow" aria-hidden="true">←</span>
                        <bdi dir="ltr" data-course-archive-end-date>{{ $course->ends_on?->format('d-m-Y') ?: '-' }}</bdi>
                    </div>
                </div>
                <div class="archive-course-date-box" data-course-archive-finished-date>
                    <span class="text-xs text-neutral-300">{{ __('course_archive_overview.dates.finished') }}</span>
                    <bdi dir="ltr">{{ $course->finished_at?->format('d-m-Y') ?: '-' }}</bdi>
                </div>
            </div>
        </div>
    </section>

    <div class="mobile-compact-highlights dashboard-manager-highlights archive-manager-highlights grid gap-3" data-course-archive-metrics>
        @foreach ($metrics as $metric)
            <article class="stat-card">
                <div class="kpi-label">{{ $metric['label'] }}</div>
                <div class="metric-value mt-3">{{ number_format($metric['value'], is_float($metric['value']) ? 1 : 0) }}</div>
            </article>
        @endforeach
    </div>

    <section class="archive-live-workspace" data-course-archive-live-workspace>
        <div class="course-manager-tabs surface-table overflow-x-auto px-4 py-4 sm:px-6" role="tablist" aria-label="{{ __('course_archive_overview.archive_name', ['course' => $course->name]) }}">
            @foreach (['attendance', 'assessments', 'points', 'end'] as $tab)
                <button type="button" role="tab" wire:click="showSection('{{ $tab }}')" aria-selected="{{ $section === $tab ? 'true' : 'false' }}" class="min-w-max" data-course-archive-section="{{ $tab }}">
                    {{ __('course_archive_overview.sections.'.$tab) }}
                </button>
            @endforeach
        </div>

        <div role="tabpanel" data-course-archive-real-component="{{ $section }}">
            @if ($section === 'attendance')
                <livewire:attendance.index :archive-course-id="$course->id" :archive-read-only="true" :key="'archive-attendance-'.$course->id" />
            @elseif ($section === 'assessments')
                <livewire:assessments.index :archive-course-id="$course->id" :archive-read-only="true" :key="'archive-assessments-'.$course->id" />
            @elseif ($section === 'points')
                <livewire:points.index :archive-course-id="$course->id" :archive-read-only="true" :key="'archive-points-'.$course->id" />
            @else
                <livewire:courses.end :course="$course" :archive-read-only="true" :key="'archive-end-'.$course->id" />
                @include('livewire.courses.partials.archive-completion-settings', ['completionSettings' => $completionSettings])
            @endif
        </div>
    </section>

    @if (false)
    <section class="surface-table overflow-hidden" hidden aria-hidden="true" data-course-archive-legacy-view>
        <div class="course-manager-tabs overflow-x-auto px-4 pt-4 sm:px-6" role="tablist" aria-label="{{ __('course_archive_overview.archive_name', ['course' => $course->name]) }}">
            @foreach (['attendance', 'assessments', 'points', 'notes', 'end'] as $tab)
                <button type="button" role="tab" wire:click="showSection('{{ $tab }}')" aria-selected="{{ $section === $tab ? 'true' : 'false' }}" class="min-w-max" data-course-archive-section="{{ $tab }}">
                    {{ __('course_archive_overview.sections.'.$tab) }}
                </button>
            @endforeach
        </div>

        <div role="tabpanel" class="mt-4 border-t border-white/8">
            @if ($section === 'attendance')
                <div class="archive-attendance-switch"><x-attendance-type-switch :selected="$attendanceAudience" action="showAttendanceAudience" /></div>
                @if ($attendanceAudience === 'students')
                    <div class="overflow-x-auto"><table class="attendance-days-table text-sm"><thead><tr><th data-table-number-column>#</th><th>{{ __('workflow.student_attendance.days.table.headers.date') }}</th><th>{{ __('workflow.student_attendance.days.table.headers.groups') }}</th><th>{{ __('workflow.student_attendance.days.table.headers.students') }}</th><th>{{ __('workflow.student_attendance.days.table.headers.attended') }}</th><th>{{ __('workflow.student_attendance.days.table.headers.status') }}</th><th class="admin-actions-column text-center">{{ __('workflow.student_attendance.days.table.headers.actions') }}</th></tr></thead><tbody class="divide-y divide-white/6">
                        @forelse ($studentAttendanceDays as $day) @php($records = $day->groupAttendanceDays->flatMap->records) <tr><td>{{ $studentAttendanceDays->firstItem() + $loop->index }}</td><td><div class="flex flex-col items-start font-semibold"><span class="text-xs font-medium text-neutral-400">{{ $day->attendance_date?->locale(app()->getLocale())->translatedFormat('l') }}</span><bdi dir="ltr" class="mt-1">{{ $day->attendance_date?->format('d-m-Y') ?: '-' }}</bdi></div></td><td>{{ number_format($day->groupAttendanceDays->count()) }}</td><td>{{ number_format($records->count()) }}</td><td>{{ number_format($records->filter(fn ($record) => (bool) $record->status?->is_present)->count()) }}</td><td><span class="status-chip {{ $day->status === 'closed' ? 'status-chip--emerald' : 'status-chip--slate' }}">{{ __('workflow.common.day_status.'.$day->status) }}</span></td><td><div class="flex justify-center"><x-open-action-button wire:click="openStudentAttendanceDay({{ $day->id }})" :label="__('workflow.student_attendance.days.table.view')" /></div></td></tr>@empty<tr><td colspan="7" class="admin-empty-state">{{ __('course_archive_overview.empty.student_attendance') }}</td></tr>@endforelse
                    </tbody></table></div>
                    @if ($studentAttendanceDays->hasPages())<div class="border-t border-white/8 px-5 py-4">{{ $studentAttendanceDays->links() }}</div>@endif
                @else
                    <div class="overflow-x-auto"><table class="attendance-days-table text-sm"><thead><tr><th data-table-number-column>#</th><th>{{ __('workflow.teacher_attendance.days.table.headers.date') }}</th><th>{{ __('workflow.teacher_attendance.days.table.headers.teachers') }}</th><th>{{ __('workflow.teacher_attendance.days.table.headers.marked') }}</th><th>{{ __('workflow.teacher_attendance.days.table.headers.status') }}</th><th class="admin-actions-column text-center">{{ __('workflow.teacher_attendance.days.table.headers.actions') }}</th></tr></thead><tbody class="divide-y divide-white/6">
                        @forelse ($teacherAttendanceDays as $day)<tr><td>{{ $teacherAttendanceDays->firstItem() + $loop->index }}</td><td><div class="flex flex-col items-start font-semibold"><span class="text-xs font-medium text-neutral-400">{{ $day->attendance_date?->locale(app()->getLocale())->translatedFormat('l') }}</span><bdi dir="ltr" class="mt-1">{{ $day->attendance_date?->format('d-m-Y') ?: '-' }}</bdi></div></td><td>{{ number_format($day->records->count()) }}</td><td>{{ number_format($day->records->filter(fn ($record) => (bool) $record->status?->is_present)->count()) }}</td><td><span class="status-chip {{ $day->status === 'closed' ? 'status-chip--emerald' : 'status-chip--slate' }}">{{ __('workflow.common.day_status.'.$day->status) }}</span></td><td><div class="flex justify-center"><x-open-action-button wire:click="openTeacherAttendanceDay({{ $day->id }})" :label="__('workflow.teacher_attendance.days.table.view')" /></div></td></tr>@empty<tr><td colspan="6" class="admin-empty-state">{{ __('course_archive_overview.empty.teacher_attendance') }}</td></tr>@endforelse
                    </tbody></table></div>
                    @if ($teacherAttendanceDays->hasPages())<div class="border-t border-white/8 px-5 py-4">{{ $teacherAttendanceDays->links() }}</div>@endif
                @endif
            @elseif ($section === 'assessments')
                <div class="overflow-x-auto"><table class="assessment-index-table w-full text-sm"><thead><tr><th data-table-number-column>#</th><th>{{ __('workflow.assessments.index.table.headers.assessment') }}</th><th>{{ __('workflow.assessments.index.form.groups') }}</th><th>{{ __('workflow.assessments.index.table.headers.schedule') }}</th><th>{{ __('workflow.assessments.index.table.headers.results') }}</th><th>{{ __('workflow.assessments.index.table.headers.average') }}</th><th>{{ __('workflow.assessments.index.table.headers.status') }}</th><th class="admin-actions-column text-center">{{ __('workflow.assessments.index.table.headers.actions') }}</th></tr></thead><tbody class="divide-y divide-white/6">
                    @forelse ($assessments as $assessment) @php($assessmentGroups = $assessment->groups->isNotEmpty() ? $assessment->groups : collect([$assessment->group])->filter()) <tr><td>{{ $assessments->firstItem() + $loop->index }}</td><td><div class="font-medium text-white">{{ $assessment->title }}</div><div class="text-xs text-neutral-500">{{ $assessment->type?->name ?: '-' }}</div></td><td><div class="max-w-80 truncate" title="{{ $assessmentGroups->pluck('name')->implode('، ') }}">{{ $assessmentGroups->pluck('name')->implode('، ') ?: '-' }}</div></td><td><bdi dir="ltr">{{ $assessment->due_at?->format('d-m-Y') ?: '-' }}</bdi></td><td>{{ number_format($assessment->results_count) }}</td><td>{{ $assessment->results_avg_score !== null ? number_format((float) $assessment->results_avg_score, 0).'%' : '-' }}</td><td><span class="status-chip {{ $assessment->is_active ? 'status-chip--emerald' : 'status-chip--blue' }}">{{ $assessment->is_active ? __('crud.common.status_options.active') : __('crud.common.status_options.finished') }}</span></td><td><div class="flex justify-center"><x-open-action-button wire:click="openAssessmentResults({{ $assessment->id }})" :label="__('crud.common.actions.open')" /></div></td></tr>@empty<tr><td colspan="8" class="admin-empty-state">{{ __('course_archive_overview.empty.assessments') }}</td></tr>@endforelse
                </tbody></table></div>
                @if ($assessments->hasPages())<div class="border-t border-white/8 px-5 py-4">{{ $assessments->links() }}</div>@endif
            @elseif ($section === 'points')
                <div class="overflow-x-auto"><table class="points-ledger-table table-content text-sm"><thead><tr><th>#</th><th>{{ __('workflow.points.workbench.table.headers.student') }}</th><th>{{ __('workflow.points.workbench.table.headers.type') }}</th><th>{{ __('workflow.points.workbench.table.headers.points') }}</th><th>{{ __('workflow.points.workbench.table.headers.source') }}</th><th>{{ __('workflow.points.workbench.table.headers.entered_at') }}</th><th>{{ __('course_archive_overview.fields.group') }}</th><th>{{ __('workflow.points.workbench.table.headers.state') }}</th><th>{{ __('workflow.points.workbench.table.headers.void_reason') }}</th></tr></thead><tbody class="divide-y divide-white/6">
                    @forelse ($pointTransactions as $transaction)
                        @php($sourceTranslationKey = 'workflow.common.source_type.'.$transaction->source_type)
                        @php($sourceLabel = trans()->has($sourceTranslationKey) ? __($sourceTranslationKey) : str($transaction->source_type)->headline())
                        @php($state = $transaction->effectiveState())
                        <tr @class(['opacity-60' => $state !== 'active', 'points-ledger-row--voided' => $state === 'voided'])>
                            <td>{{ $pointTransactions->firstItem() + $loop->index }}</td>
                            <td class="table-cell-name">@if ($transaction->student)<div class="student-inline"><x-student-avatar :student="$transaction->student" size="sm" /><div class="student-inline__body"><div class="record-person-name student-inline__name whitespace-nowrap">{{ $transaction->student->full_name }}</div></div></div>@else - @endif</td>
                            <td>{{ $transaction->pointType?->name ?: $transaction->policy?->name ?: '-' }}</td>
                            <td class="font-semibold text-white">{{ number_format($transaction->points) }}</td>
                            <td>{{ $sourceLabel }}</td>
                            <td><div class="flex flex-col items-start"><bdi dir="ltr">{{ $transaction->entered_at?->format('d-m-Y') ?: '-' }}</bdi><bdi dir="ltr" class="text-xs text-neutral-500">{{ $transaction->entered_at?->format('H:i') ?: '' }}</bdi></div></td>
                            <td>{{ $transaction->enrollment?->group?->name ?: '-' }}</td>
                            <td>{{ __('workflow.common.ledger_state.'.$state) }}</td>
                            <td class="max-w-72"><div class="truncate" title="{{ $transaction->void_reason }}">{{ $transaction->void_reason ?: '-' }}</div></td>
                        </tr>
                    @empty<tr><td colspan="9" class="admin-empty-state">{{ __('course_archive_overview.empty.points') }}</td></tr>@endforelse
                </tbody></table></div>
                @if ($pointTransactions->hasPages())<div class="border-t border-white/8 px-5 py-4">{{ $pointTransactions->links() }}</div>@endif
            @else
                <div class="admin-grid-meta"><div><div class="admin-grid-meta__title">{{ __('course_end.students_title') }}</div><div class="admin-grid-meta__summary">{{ __('course_end.student_records', ['count' => number_format($endRows->total())]) }}</div></div></div>
                <div class="overflow-x-auto"><table class="course-end-table table-content text-sm"><thead><tr><th>#</th><th>{{ __('course_archive_overview.fields.student') }}</th><th>{{ __('course_archive_overview.fields.group') }}</th><th>{{ __('course_end.table.points_after') }}</th><th>{{ __('course_end.table.days_attended') }}</th><th>{{ __('course_end.table.pages') }}</th><th>{{ __('course_end.table.final_tests') }}</th><th>{{ __('course_end.table.final_score') }}</th></tr></thead><tbody class="divide-y divide-white/6">
                    @forelse ($endRows as $row)<tr><td>{{ $endRows->firstItem() + $loop->index }}</td><td><span class="record-person-name">{{ $row['name'] }}</span></td><td>{{ $row['group'] ?: '-' }}</td><td>{{ number_format($row['points_after']) }}</td><td>{{ number_format($row['days_attended']) }}</td><td>{{ number_format($row['memorized_pages']) }}</td><td>{{ number_format($row['final_tests']) }}</td><td>{{ $row['final_score'] !== null ? number_format($row['final_score'], 2) : '-' }}</td></tr>@empty<tr><td colspan="8" class="admin-empty-state">{{ __('course_archive_overview.empty.end') }}</td></tr>@endforelse
                </tbody></table></div>
                @if ($endRows->hasPages())<div class="border-t border-white/8 px-5 py-4">{{ $endRows->links() }}</div>@endif
                <div class="admin-grid-meta border-t border-white/8"><div><div class="admin-grid-meta__title">{{ __('course_end.final_tests_title') }}</div><div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($finalTests->total())]) }}</div></div></div>
                <div class="overflow-x-auto"><table class="course-end-table table-content text-sm"><thead><tr><th>#</th><th>{{ __('course_end.table.name') }}</th><th>{{ __('course_end.table.juz') }}</th><th>{{ __('course_end.table.mark') }}</th><th>{{ __('course_end.table.grade') }}</th></tr></thead><tbody class="divide-y divide-white/6">
                    @forelse ($finalTests as $row)<tr><td>{{ $finalTests->firstItem() + $loop->index }}</td><td class="font-medium text-white"><span class="record-person-name">{{ $row['name'] }}</span></td><td>{{ $row['juz'] ?: '-' }}</td><td>{{ $row['marks'] ?? \App\Support\PercentageFormatter::format($row['mark']) }}</td><td class="font-medium text-emerald-100">{{ __('course_end.grades.'.$row['grade']) }}</td></tr>@empty<tr><td colspan="5" class="admin-empty-state">{{ __('course_archive_overview.empty.final_tests') }}</td></tr>@endforelse
                </tbody></table></div>
                @if ($finalTests->hasPages())<div class="border-t border-white/8 px-5 py-4">{{ $finalTests->links() }}</div>@endif
                <div class="admin-grid-meta border-t border-white/8"><div><div class="admin-grid-meta__title">{{ __('course_archive_overview.completion_settings.title') }}</div></div></div>
                <div class="space-y-4 p-5 lg:p-6" data-course-archive-completion-settings>
                    <div class="archive-completion-rules">
                        @foreach ($completionSettings['final_rule_rows'] as $rule)
                            @php($gradeNames = collect($rule['grade_ids'])->map(fn ($id) => $completionSettings['grade_names']->get($id))->filter()->implode('، '))
                            <article class="archive-detail-card">
                                <div class="kpi-label">{{ $gradeNames ?: __('settings.course_completion.labels.all_grades') }}</div>
                                <div class="mt-3 flex flex-wrap items-center gap-2 text-sm text-white">
                                    <span>{{ __('settings.course_completion.fields.required_passed_final_tests') }}: <strong>{{ number_format($rule['required_passed_final_tests']) }}</strong></span>
                                    <span class="status-chip status-chip--slate">{{ __('settings.course_completion.options.'.$rule['final_rule_operator']) }}</span>
                                    <span>{{ __('settings.course_completion.fields.required_memorized_pages') }}: <strong>{{ number_format($rule['required_memorized_pages']) }}</strong></span>
                                </div>
                            </article>
                        @endforeach
                    </div>
                    <div class="overflow-x-auto rounded-2xl border border-white/10"><table class="table-content text-sm"><thead><tr><th>{{ __('settings.course_completion.table.assessment_type') }}</th><th>{{ __('settings.course_completion.table.required_passed_results') }}</th><th>{{ __('settings.course_completion.table.grades') }}</th></tr></thead><tbody class="divide-y divide-white/6">
                        @forelse ($completionSettings['assessment_type_requirements'] as $typeId => $required)
                            @php($assessmentGradeNames = collect($completionSettings['assessment_rule_grade_ids'][$typeId] ?? [])->map(fn ($id) => $completionSettings['grade_names']->get($id))->filter()->implode('، '))
                            <tr><td>{{ $completionSettings['assessment_type_names']->get($typeId) ?: __('settings.course_completion.labels.unknown_assessment_type') }}</td><td>{{ number_format($required) }}</td><td>{{ $assessmentGradeNames ?: __('settings.course_completion.labels.all_grades') }}</td></tr>
                        @empty
                            <tr><td colspan="3" class="admin-empty-state">{{ __('settings.course_completion.labels.no_assessment_types') }}</td></tr>
                        @endforelse
                    </tbody></table></div>
                    <div class="archive-detail-summary">
                        <article class="archive-detail-card"><div class="kpi-label">{{ __('settings.course_completion.fields.retain_percentage') }}</div><div class="metric-value mt-2">{{ number_format($completionSettings['retain_percentage']) }}%</div></article>
                        <article class="archive-detail-card"><div class="kpi-label">{{ __('settings.course_completion.fields.minimum_points') }}</div><div class="metric-value mt-2">{{ number_format($completionSettings['minimum_points']) }}</div></article>
                    </div>
                </div>
            @endif
        </div>
    </section>

    <x-admin.modal :show="$selectedStudentAttendanceDay !== null" :title="$selectedGroupAttendanceDay ? ($selectedGroupAttendanceDay->group?->name ?? '-') : __('course_archive_overview.details.student_attendance')" close-method="closeRecordDetail" max-width="6xl">
        @if ($selectedStudentAttendanceDay)
            @if ($selectedGroupAttendanceDay)
                <div class="mb-4 flex items-center justify-between gap-3"><button type="button" wire:click="backToAttendanceGroups" class="pill-link">{{ __('crud.common.actions.back') }}</button><bdi dir="ltr" class="text-sm text-neutral-300">{{ $selectedStudentAttendanceDay->attendance_date?->format('d-m-Y') }}</bdi></div>
                <div class="overflow-x-auto"><table class="table-content text-sm"><thead><tr><th>#</th><th>{{ __('course_archive_overview.fields.student') }}</th><th>{{ __('course_archive_overview.fields.status') }}</th><th>{{ __('course_archive_overview.fields.notes') }}</th></tr></thead><tbody class="divide-y divide-white/6">@forelse ($selectedGroupAttendanceDay->records as $record)<tr><td>{{ $loop->iteration }}</td><td class="record-person-name font-semibold">{{ $record->enrollment?->student?->full_name ?: '-' }}</td><td><span @class(['status-chip', 'status-chip--emerald' => $record->status?->is_present, 'status-chip--rose' => $record->status && ! $record->status->is_present, 'status-chip--slate' => ! $record->status])>{{ $record->status?->name ?: '-' }}</span></td><td>{{ $record->notes ?: '-' }}</td></tr>@empty<tr><td colspan="4" class="admin-empty-state">{{ __('course_archive_overview.empty.attendance_records') }}</td></tr>@endforelse</tbody></table></div>
            @else
                <div class="mb-4 archive-detail-summary"><article class="archive-detail-card"><div class="kpi-label">{{ __('workflow.student_attendance.days.table.headers.date') }}</div><bdi dir="ltr" class="mt-2 block font-semibold text-white">{{ $selectedStudentAttendanceDay->attendance_date?->format('d-m-Y') }}</bdi></article><article class="archive-detail-card"><div class="kpi-label">{{ __('workflow.student_attendance.days.table.headers.groups') }}</div><div class="metric-value mt-2">{{ number_format($selectedStudentAttendanceDay->groupAttendanceDays->count()) }}</div></article></div>
                <div class="overflow-x-auto"><table class="attendance-day-groups-table text-sm"><thead><tr><th>#</th><th>{{ __('workflow.student_attendance.day_details.table.headers.group') }}</th><th>{{ __('workflow.student_attendance.day_details.table.headers.teacher') }}</th><th>{{ __('workflow.student_attendance.day_details.table.headers.students') }}</th><th>{{ __('workflow.student_attendance.day_details.table.headers.present') }}</th><th class="admin-actions-column text-center">{{ __('workflow.student_attendance.day_details.table.headers.actions') }}</th></tr></thead><tbody class="divide-y divide-white/6">@forelse ($selectedStudentAttendanceDay->groupAttendanceDays as $groupDay)<tr><td>{{ $loop->iteration }}</td><td class="font-semibold text-white">{{ $groupDay->group?->name ?: '-' }}</td><td class="record-person-name">{{ $groupDay->group?->teacher ? trim($groupDay->group->teacher->first_name.' '.$groupDay->group->teacher->last_name) : '-' }}</td><td>{{ number_format($groupDay->records_count) }}</td><td>{{ number_format($groupDay->present_records_count) }}</td><td><div class="flex justify-center"><x-open-action-button wire:click="openGroupAttendanceDay({{ $groupDay->id }})" :label="__('workflow.student_attendance.day_details.table.open')" /></div></td></tr>@empty<tr><td colspan="6" class="admin-empty-state">{{ __('course_archive_overview.empty.attendance_groups') }}</td></tr>@endforelse</tbody></table></div>
            @endif
        @endif
    </x-admin.modal>

    <x-admin.modal :show="$selectedTeacherAttendanceDay !== null" :title="__('course_archive_overview.details.teacher_attendance')" close-method="closeRecordDetail" max-width="6xl">
        @if ($selectedTeacherAttendanceDay)
            <div class="mb-4 archive-detail-summary"><article class="archive-detail-card"><div class="kpi-label">{{ __('workflow.teacher_attendance.days.table.headers.date') }}</div><bdi dir="ltr" class="mt-2 block font-semibold text-white">{{ $selectedTeacherAttendanceDay->attendance_date?->format('d-m-Y') }}</bdi></article><article class="archive-detail-card"><div class="kpi-label">{{ __('workflow.teacher_attendance.days.table.headers.teachers') }}</div><div class="metric-value mt-2">{{ number_format($selectedTeacherAttendanceDay->records->count()) }}</div></article></div>
            <div class="overflow-x-auto"><table class="table-content text-sm"><thead><tr><th>#</th><th>{{ __('course_archive_overview.fields.teacher') }}</th><th>{{ __('course_archive_overview.fields.status') }}</th><th>{{ __('course_archive_overview.fields.notes') }}</th></tr></thead><tbody class="divide-y divide-white/6">@forelse ($selectedTeacherAttendanceDay->records as $record)<tr><td>{{ $loop->iteration }}</td><td class="record-person-name font-semibold">{{ $record->teacher ? trim($record->teacher->first_name.' '.$record->teacher->last_name) : '-' }}</td><td><span @class(['status-chip', 'status-chip--emerald' => $record->status?->is_present, 'status-chip--rose' => $record->status && ! $record->status->is_present, 'status-chip--slate' => ! $record->status])>{{ $record->status?->name ?: '-' }}</span></td><td>{{ $record->notes ?: '-' }}</td></tr>@empty<tr><td colspan="4" class="admin-empty-state">{{ __('course_archive_overview.empty.attendance_records') }}</td></tr>@endforelse</tbody></table></div>
        @endif
    </x-admin.modal>

    <x-admin.modal :show="$selectedAssessment !== null" :title="$selectedAssessment?->title ?? __('course_archive_overview.details.assessment')" close-method="closeRecordDetail" max-width="6xl">
        @if ($selectedAssessment)
            <div class="mb-4 archive-detail-summary"><article class="archive-detail-card"><div class="kpi-label">{{ __('course_archive_overview.fields.type') }}</div><div class="mt-2 font-semibold text-white">{{ $selectedAssessment->type?->name ?: '-' }}</div></article><article class="archive-detail-card"><div class="kpi-label">{{ __('workflow.assessments.index.table.headers.schedule') }}</div><bdi dir="ltr" class="mt-2 block font-semibold text-white">{{ $selectedAssessment->due_at?->format('d-m-Y') ?: '-' }}</bdi></article><article class="archive-detail-card"><div class="kpi-label">{{ __('workflow.assessments.index.table.headers.results') }}</div><div class="metric-value mt-2">{{ number_format($selectedAssessment->results->count()) }}</div></article></div>
            <div class="overflow-x-auto"><table class="table-content text-sm"><thead><tr><th>#</th><th>{{ __('course_archive_overview.fields.student') }}</th><th>{{ __('course_archive_overview.fields.group') }}</th><th>{{ __('course_archive_overview.fields.score') }}</th><th>{{ __('course_archive_overview.fields.status') }}</th><th>{{ __('course_archive_overview.fields.notes') }}</th></tr></thead><tbody class="divide-y divide-white/6">@forelse ($selectedAssessment->results as $result)<tr><td>{{ $loop->iteration }}</td><td class="record-person-name font-semibold">{{ $result->student?->full_name ?: '-' }}</td><td>{{ $result->enrollment?->group?->name ?: '-' }}</td><td>{{ $result->score !== null ? number_format((float) $result->score, 0).'%' : '-' }}</td><td>{{ __('workflow.common.result_status.'.$result->status) }}</td><td>{{ $result->notes ?: '-' }}</td></tr>@empty<tr><td colspan="6" class="admin-empty-state">{{ __('course_archive_overview.empty.assessment_results') }}</td></tr>@endforelse</tbody></table></div>
        @endif
    </x-admin.modal>
    @endif
</div>
