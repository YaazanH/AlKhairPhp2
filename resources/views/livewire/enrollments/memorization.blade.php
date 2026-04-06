<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\Enrollment;
use App\Models\MemorizationSession;
use App\Models\StudentPageAchievement;
use App\Models\Teacher;
use App\Services\MemorizationService;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public Enrollment $currentEnrollment;
    public ?int $editingSessionId = null;
    public ?int $teacher_id = null;
    public string $recorded_on = '';
    public string $entry_type = 'new';
    public string $from_page = '';
    public string $to_page = '';
    public string $notes = '';

    public function mount(Enrollment $enrollment): void
    {
        $this->authorizePermission('memorization.view');

        $this->currentEnrollment = Enrollment::query()
            ->with(['student.gradeLevel', 'group.course', 'group.teacher'])
            ->findOrFail($enrollment->id);

        $this->authorizeTeacherEnrollmentAccess($this->currentEnrollment);

        $this->resetForm();
    }

    public function with(): array
    {
        $enrollmentRecord = $this->currentEnrollment->fresh(['student.gradeLevel', 'student.quranCurrentJuz', 'group.course', 'group.teacher']);
        $sessions = MemorizationSession::query()
            ->with(['teacher', 'pages'])
            ->where('enrollment_id', $this->currentEnrollment->id)
            ->latest('recorded_on')
            ->latest('id')
            ->get();

        return [
            'enrollmentRecord' => $enrollmentRecord,
            'teachers' => $this->scopeTeachersQuery(
                Teacher::query()
                    ->whereIn('status', ['active', 'inactive'])
                    ->orderBy('first_name')
                    ->orderBy('last_name')
            )->get(),
            'sessions' => $sessions,
            'achievementCount' => StudentPageAchievement::query()
                ->where('student_id', $this->currentEnrollment->student_id)
                ->count(),
            'sessionCount' => $sessions->count(),
        ];
    }

    public function saveMemorization(): void
    {
        $this->authorizePermission('memorization.record');

        $validated = $this->validate([
            'teacher_id' => ['required', 'exists:teachers,id'],
            'recorded_on' => ['required', 'date'],
            'entry_type' => ['required', 'in:new,review,correction'],
            'from_page' => ['required', 'integer', 'between:1,604'],
            'to_page' => ['required', 'integer', 'between:1,604', 'gte:from_page'],
            'notes' => ['nullable', 'string'],
        ]);

        $this->authorizeScopedTeacherAccess(Teacher::query()->findOrFail((int) $validated['teacher_id']));

        $pageNumbers = range((int) $validated['from_page'], (int) $validated['to_page']);
        $existingPages = StudentPageAchievement::query()
            ->where('student_id', $this->currentEnrollment->student_id)
            ->whereIn('page_no', $pageNumbers)
            ->when($this->editingSessionId, fn ($query) => $query->where('first_session_id', '!=', $this->editingSessionId))
            ->pluck('page_no')
            ->all();

        if ($validated['entry_type'] !== 'review' && $existingPages && ! auth()->user()->can('memorization.override-duplicate-page')) {
            $this->addError('from_page', __('workflow.memorization.errors.duplicate_pages', ['pages' => implode(', ', $existingPages)]));

            return;
        }

        $session = $this->editingSessionId
            ? MemorizationSession::query()
                ->where('enrollment_id', $this->currentEnrollment->id)
                ->findOrFail($this->editingSessionId)
            : null;

        app(MemorizationService::class)->saveSession($this->currentEnrollment, $validated, $session);

        session()->flash(
            'status',
            $this->editingSessionId
                ? __('workflow.memorization.messages.updated')
                : __('workflow.memorization.messages.saved'),
        );

        $this->resetForm();
    }

    public function editSession(int $sessionId): void
    {
        $this->authorizePermission('memorization.record');

        $session = MemorizationSession::query()
            ->where('enrollment_id', $this->currentEnrollment->id)
            ->findOrFail($sessionId);

        $this->editingSessionId = $session->id;
        $this->teacher_id = $session->teacher_id;
        $this->recorded_on = $session->recorded_on?->format('Y-m-d') ?? now()->toDateString();
        $this->entry_type = $session->entry_type;
        $this->from_page = (string) $session->from_page;
        $this->to_page = (string) $session->to_page;
        $this->notes = $session->notes ?? '';

        $this->resetValidation();
    }

    public function resetForm(): void
    {
        $this->editingSessionId = null;
        $this->teacher_id = $this->currentEnrollment->group?->teacher_id;
        $this->recorded_on = now()->toDateString();
        $this->entry_type = 'new';
        $this->from_page = '';
        $this->to_page = '';
        $this->notes = '';

        $this->resetValidation();
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('workflow.common.back_to_enrollments') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.memorization.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('workflow.memorization.subtitle') }}</p>
        <div class="mt-6 flex flex-wrap gap-3">
            <span class="badge-soft">{{ $enrollmentRecord->student?->first_name }} {{ $enrollmentRecord->student?->last_name }}</span>
            <span class="badge-soft badge-soft--emerald">{{ $enrollmentRecord->group?->name ?: __('workflow.common.no_group') }}</span>
            <span class="badge-soft">{{ $enrollmentRecord->group?->course?->name ?: __('workflow.common.no_course') }}</span>
        </div>
    </section>

    <div>
        <a href="{{ route('enrollments.index') }}" wire:navigate class="pill-link pill-link--compact">{{ __('workflow.common.back_to_enrollments') }}</a>
    </div>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <div class="grid gap-4 md:grid-cols-3">
        <article class="stat-card">
            <div class="kpi-label">{{ __('workflow.common.labels.lifetime_pages', ['count' => number_format($achievementCount)]) }}</div>
            <div class="metric-value mt-6">{{ number_format($achievementCount) }}</div>
        </article>

        <article class="stat-card">
            <div class="kpi-label">{{ __('workflow.memorization.stats.enrollment_pages') }}</div>
            <div class="metric-value mt-6">{{ number_format($enrollmentRecord->memorized_pages_cached) }}</div>
        </article>

        <article class="stat-card">
            <div class="kpi-label">{{ __('workflow.memorization.stats.sessions') }}</div>
            <div class="metric-value mt-6">{{ number_format($sessionCount) }}</div>
        </article>
    </div>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.15fr)_24rem]">
        <section class="surface-panel p-5 lg:p-6">
            @if (auth()->user()->can('memorization.record'))
                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                    <div>
                        <div class="admin-toolbar__title">
                            {{ $editingSessionId ? __('workflow.memorization.form.edit_title') : __('workflow.memorization.form.title') }}
                        </div>
                        <p class="admin-toolbar__subtitle">{{ __('workflow.memorization.form.help') }}</p>
                    </div>

                    @if ($editingSessionId)
                        <button type="button" wire:click="resetForm" class="pill-link">{{ __('workflow.common.actions.reset') }}</button>
                    @endif
                </div>

                <form wire:submit="saveMemorization" class="mt-6 space-y-5">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="memorization-teacher" class="mb-1 block text-sm font-medium">{{ __('workflow.memorization.form.teacher') }}</label>
                            <select id="memorization-teacher" wire:model="teacher_id" class="w-full rounded-xl px-4 py-3 text-sm">
                                <option value="">{{ __('workflow.memorization.form.select_teacher') }}</option>
                                @foreach ($teachers as $teacher)
                                    <option value="{{ $teacher->id }}">{{ $teacher->first_name }} {{ $teacher->last_name }}</option>
                                @endforeach
                            </select>
                            @error('teacher_id')
                                <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                            @enderror
                        </div>

                        <div>
                            <label for="memorization-recorded-on" class="mb-1 block text-sm font-medium">{{ __('workflow.memorization.form.recorded_on') }}</label>
                            <input id="memorization-recorded-on" wire:model="recorded_on" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
                            @error('recorded_on')
                                <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-[12rem_minmax(0,1fr)_minmax(0,1fr)]">
                        <div>
                            <label for="memorization-entry-type" class="mb-1 block text-sm font-medium">{{ __('workflow.memorization.form.entry_type') }}</label>
                            <select id="memorization-entry-type" wire:model="entry_type" class="w-full rounded-xl px-4 py-3 text-sm">
                                <option value="new">{{ __('workflow.common.entry_type.new') }}</option>
                                <option value="review">{{ __('workflow.common.entry_type.review') }}</option>
                                <option value="correction">{{ __('workflow.common.entry_type.correction') }}</option>
                            </select>
                            @error('entry_type')
                                <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                            @enderror
                        </div>

                        <div>
                            <label for="memorization-from-page" class="mb-1 block text-sm font-medium">{{ __('workflow.memorization.form.from_page') }}</label>
                            <input id="memorization-from-page" wire:model="from_page" type="number" min="1" max="604" class="w-full rounded-xl px-4 py-3 text-sm">
                            @error('from_page')
                                <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                            @enderror
                        </div>

                        <div>
                            <label for="memorization-to-page" class="mb-1 block text-sm font-medium">{{ __('workflow.memorization.form.to_page') }}</label>
                            <input id="memorization-to-page" wire:model="to_page" type="number" min="1" max="604" class="w-full rounded-xl px-4 py-3 text-sm">
                            @error('to_page')
                                <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div>
                        <label for="memorization-notes" class="mb-1 block text-sm font-medium">{{ __('workflow.memorization.form.notes') }}</label>
                        <textarea id="memorization-notes" wire:model="notes" rows="5" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>
                        @error('notes')
                            <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <button type="submit" class="pill-link pill-link--accent">
                            {{ $editingSessionId ? __('workflow.common.actions.update_memorization') : __('workflow.common.actions.save_memorization') }}
                        </button>
                        @if ($editingSessionId)
                            <button type="button" wire:click="resetForm" class="pill-link">{{ __('workflow.common.actions.cancel_edit') }}</button>
                        @endif
                    </div>
                </form>
            @else
                <div class="soft-callout px-4 py-4 text-sm leading-6">
                    <div class="font-semibold text-white">{{ __('workflow.memorization.read_only.title') }}</div>
                    <div class="mt-2 text-neutral-300">{{ __('workflow.memorization.read_only.description') }}</div>
                </div>
            @endif
        </section>

        <aside class="space-y-6">
            <section class="surface-panel p-5">
                <div class="admin-toolbar__title">{{ __('workflow.memorization.context.title') }}</div>
                <div class="mt-4 space-y-3 text-sm text-neutral-300">
                    <div>
                        <div class="text-xs uppercase tracking-[0.18em] text-neutral-500">{{ __('workflow.memorization.context.student') }}</div>
                        <div class="mt-1 text-white">{{ $enrollmentRecord->student?->first_name }} {{ $enrollmentRecord->student?->last_name }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-[0.18em] text-neutral-500">{{ __('workflow.memorization.context.group') }}</div>
                        <div class="mt-1 text-white">{{ $enrollmentRecord->group?->name ?: __('workflow.common.no_group') }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-[0.18em] text-neutral-500">{{ __('workflow.memorization.context.course') }}</div>
                        <div class="mt-1 text-white">{{ $enrollmentRecord->group?->course?->name ?: __('workflow.common.no_course') }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-[0.18em] text-neutral-500">{{ __('workflow.memorization.context.current_juz') }}</div>
                        <div class="mt-1 text-white">
                            {{ $enrollmentRecord->student?->quranCurrentJuz ? __('workflow.common.labels.current_juz', ['number' => $enrollmentRecord->student->quranCurrentJuz->juz_number]) : __('workflow.common.not_available') }}
                        </div>
                    </div>
                </div>
            </section>

            <section class="surface-panel p-5">
                <div class="admin-toolbar__title">{{ __('workflow.memorization.context.guidance_title') }}</div>
                <div class="mt-4 space-y-3 text-sm leading-6 text-neutral-300">
                    <p>{{ __('workflow.memorization.context.guidance_intro') }}</p>
                    <ul class="space-y-2">
                        <li>{{ __('workflow.memorization.context.guidance_new') }}</li>
                        <li>{{ __('workflow.memorization.context.guidance_review') }}</li>
                        <li>{{ __('workflow.memorization.context.guidance_correction') }}</li>
                    </ul>
                </div>
            </section>
        </aside>
    </div>

    <section class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('workflow.memorization.table.title') }}</div>
                <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($sessionCount)]) }}</div>
            </div>
        </div>

        @if ($sessions->isEmpty())
            <div class="admin-empty-state">{{ __('workflow.memorization.table.empty') }}</div>
        @else
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead>
                        <tr>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.memorization.table.headers.date') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.memorization.table.headers.type') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.memorization.table.headers.pages') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.memorization.table.headers.teacher') }}</th>
                            <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.memorization.table.headers.notes') }}</th>
                            @can('memorization.record')
                                <th class="px-5 py-4 text-right lg:px-6">{{ __('workflow.memorization.table.headers.actions') }}</th>
                            @endcan
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($sessions as $session)
                            <tr>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $session->recorded_on?->format('Y-m-d') }}</td>
                                <td class="px-5 py-4 lg:px-6">
                                    <span class="status-chip status-chip--slate">{{ __('workflow.common.entry_type.' . $session->entry_type) }}</span>
                                </td>
                                <td class="px-5 py-4 text-white lg:px-6">{{ __('workflow.memorization.table.page_range', ['from' => $session->from_page, 'to' => $session->to_page, 'count' => $session->pages_count]) }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $session->teacher?->first_name }} {{ $session->teacher?->last_name }}</td>
                                <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $session->notes ?: __('workflow.common.not_available') }}</td>
                                @can('memorization.record')
                                    <td class="px-5 py-4 lg:px-6">
                                        <div class="flex justify-end">
                                            <button type="button" wire:click="editSession({{ $session->id }})" class="pill-link pill-link--compact">{{ __('workflow.common.actions.edit') }}</button>
                                        </div>
                                    </td>
                                @endcan
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
