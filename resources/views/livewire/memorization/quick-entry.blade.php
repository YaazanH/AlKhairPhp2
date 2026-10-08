<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\Enrollment;
use App\Models\MemorizationSessionPage;
use App\Models\QuranJuz;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\MemorizationService;
use App\Support\OperationalFeatureSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component
{
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public ?int $selectedStudentId = null;

    public ?int $selectedEnrollmentId = null;

    public ?int $selectedJuzNumber = null;

    public array $selectedPages = [];

    public ?int $teacher_id = null;

    public bool $showDuplicateModal = false;

    public array $duplicatePages = [];

    public array $uniquePages = [];

    #[Locked]
    public array $pendingMemorizationPayload = [];

    #[Locked]
    public ?int $pendingEnrollmentId = null;

    public function mount(): void
    {
        $this->authorizePermission('memorization.record');
    }

    public function with(): array
    {
        $studentOptions = $this->quickEntryStudentsQuery()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();
        $availableEnrollments = $this->selectedStudentId
            ? $this->availableQuickEntryEnrollmentsForStudent((int) $this->selectedStudentId)
            : collect();
        $selectedEnrollment = $this->selectedQuickEntryEnrollment($availableEnrollments);

        return [
            'unfinishedJuzs' => $this->unfinishedJuzs,
            'displayedJuz' => $this->unfinishedJuzs->firstWhere('number', $this->selectedJuzNumber),
            'entriesEnabled' => OperationalFeatureSettings::memorizationAndSabersEnabled(),
            'studentOptions' => $studentOptions,
            'availableEnrollments' => $availableEnrollments,
            'currentTeacher' => $this->currentTeacher(),
            'currentUser' => auth()->user(),
            'selectedEnrollment' => $selectedEnrollment,
            'canChooseRecordingTeacher' => $this->canChooseRecordingTeacher(),
            'teacherOptions' => $this->canChooseRecordingTeacher()
                ? $this->availableRecordingTeachers()
                : collect([$selectedEnrollment ? $this->resolveQuickEntryTeacher($selectedEnrollment) : $this->currentTeacher()])->filter(),
        ];
    }

    public function updatedSelectedStudentId($value): void
    {
        $this->selectedEnrollmentId = null;
        $this->teacher_id = null;
        $this->selectedPages = [];
        $this->selectedJuzNumber = null;
        $this->closeDuplicateModal();
        unset($this->unfinishedJuzs);
        $this->resetValidation();

        if (! filled($value)) {
            return;
        }

        $student = $this->findQuickEntryStudent((int) $value);
        $current = $student->quranCurrentJuz?->juz_number ?? 30;
        $numbers = $this->unfinishedJuzs->pluck('number');
        $this->selectedJuzNumber = $numbers->contains($current) ? $current : ($current >= 26
            ? ($numbers->filter(fn ($number) => $number < $current)->last() ?? $numbers->first())
            : ($numbers->first(fn ($number) => $number > $current) ?? $numbers->last()));

        $availableEnrollments = $this->availableQuickEntryEnrollmentsForStudent((int) $value);

        if ($availableEnrollments->count() === 1) {
            $this->selectedEnrollmentId = $availableEnrollments->first()->id;
        }

        $this->syncSelectedTeacher();
    }

    public function updatedSelectedEnrollmentId(): void
    {
        $this->syncSelectedTeacher();
    }

    protected function syncSelectedTeacher(): void
    {
        $enrollment = $this->selectedStudentId
            ? $this->selectedQuickEntryEnrollment($this->availableQuickEntryEnrollmentsForStudent($this->selectedStudentId))
            : null;
        $teacher = $enrollment ? $this->resolveQuickEntryTeacher($enrollment) : null;
        $this->teacher_id = $this->canChooseRecordingTeacher()
            ? $this->availableRecordingTeachers()->firstWhere('id', $teacher?->id)?->id
            : $teacher?->id;
        $this->resetValidation('teacher_id');
    }

    protected function excludedJuzIds(Student $student): Collection
    {
        return $student->externalMemorizedJuzs()->pluck('quran_juzs.id')
            ->merge($student->quranPartialTests()->pluck('juz_id'))
            ->merge($student->quranFinalTests()->pluck('juz_id'))
            ->merge($student->quranTests()
                ->whereHas('type', fn (Builder $query) => $query->whereIn('code', ['partial', 'final', 'awqaf']))
                ->pluck('juz_id'))
            ->filter()->unique()->values();
    }

    #[Computed]
    public function unfinishedJuzs(): Collection
    {
        if (! $this->selectedStudentId) {
            return collect();
        }

        $student = $this->findQuickEntryStudent($this->selectedStudentId);
        $recordedPages = MemorizationSessionPage::query()
            ->whereHas('session', fn (Builder $query) => $query->where('student_id', $student->id)->where('entry_type', '!=', 'review'))
            ->pluck('page_no')->map(fn ($page) => (int) $page)->all();
        $excludedIds = $this->excludedJuzIds($student);

        return QuranJuz::query()->whereNotIn('id', $excludedIds)->orderBy('juz_number')->get()
            ->map(fn (QuranJuz $juz) => [
                'number' => $juz->juz_number,
                'from' => $juz->from_page,
                'to' => $juz->to_page,
                'missing' => array_values(array_diff(range($juz->from_page, $juz->to_page), $recordedPages)),
            ])->filter(fn ($juz) => $juz['missing'] !== [])->values();
    }

    public function navigateJuz(int $direction): void
    {
        $numbers = $this->unfinishedJuzs->pluck('number');
        $next = $direction < 0
            ? $numbers->filter(fn ($number) => $number < $this->selectedJuzNumber)->last()
            : $numbers->first(fn ($number) => $number > $this->selectedJuzNumber);

        if ($next !== null) {
            $this->selectJuz($next);
        }
    }

    public function selectJuz(int $number): void
    {
        $this->authorizePermission('memorization.record');
        OperationalFeatureSettings::ensureMemorizationAndSabersEnabled();
        abort_unless($this->selectedStudentId, 404);
        $this->findQuickEntryStudent($this->selectedStudentId);
        unset($this->unfinishedJuzs);
        if (! $this->unfinishedJuzs->contains('number', $number)) {
            $this->addError('selectedJuzNumber', __('workflow.memorization.quick_entry.picker.unavailable_selection'));

            return;
        }

        if ($this->selectedJuzNumber !== $number) {
            $this->selectedPages = [];
            $this->closeDuplicateModal();
        }
        $this->selectedJuzNumber = $number;
        $this->resetValidation(['selectedJuzNumber', 'selectedPages', 'selectedPages.*']);
    }

    public function save(): void
    {
        $this->authorizePermission('memorization.record');
        OperationalFeatureSettings::ensureMemorizationAndSabersEnabled();

        $validated = $this->validate([
            'selectedStudentId' => ['required', 'exists:students,id'],
            'selectedEnrollmentId' => ['nullable', 'exists:enrollments,id'],
            'selectedJuzNumber' => ['required', 'integer', 'between:1,30'],
            'selectedPages' => ['required', 'array', 'min:1', 'max:23'],
            'selectedPages.*' => ['required', 'integer', 'between:1,604', 'distinct'],
            'teacher_id' => $this->canChooseRecordingTeacher() ? ['nullable', 'exists:teachers,id'] : ['exclude'],
        ], [], [
            'selectedStudentId' => __('workflow.memorization.quick_entry.form.student'),
            'selectedEnrollmentId' => __('workflow.memorization.workbench.form.group'),
            'selectedPages' => __('workflow.memorization.quick_entry.picker.pages'),
        ]);

        $student = $this->findQuickEntryStudent((int) $validated['selectedStudentId']);
        $enrollment = $this->findQuickEntryEnrollmentForStudent(
            $student,
            filled($validated['selectedEnrollmentId'] ?? null) ? (int) $validated['selectedEnrollmentId'] : null,
        );

        if (! $enrollment) {
            if (! $this->getErrorBag()->has('selectedEnrollmentId')) {
                $this->addError('selectedStudentId', __('workflow.memorization.errors.no_active_enrollment'));
            }

            return;
        }

        $pages = array_map('intval', $validated['selectedPages']);
        sort($pages);
        $juz = QuranJuz::query()->where('juz_number', $validated['selectedJuzNumber'])->firstOrFail();
        if ($this->excludedJuzIds($student)->contains($juz->id)) {
            $this->addError('selectedPages', __('workflow.memorization.quick_entry.picker.unavailable_juz'));

            return;
        }

        if (array_diff($pages, range($juz->from_page, $juz->to_page)) !== []) {
            $this->addError('selectedPages', __('workflow.memorization.quick_entry.picker.invalid_pages'));

            return;
        }

        $teacher = $this->canChooseRecordingTeacher()
            ? $this->availableRecordingTeachers()->firstWhere('id', (int) ($validated['teacher_id'] ?? 0))
            : $this->resolveQuickEntryTeacher($enrollment);

        if (! $teacher) {
            $this->addError($this->canChooseRecordingTeacher() ? 'teacher_id' : 'selectedEnrollmentId', __('workflow.memorization.quick_entry.errors.no_assigned_teacher'));

            return;
        }

        $payload = [
            'teacher_id' => $teacher->id,
            'recorded_on' => now()->toDateString(),
            'entry_type' => 'new',
            'from_page' => min($pages),
            'to_page' => max($pages),
            'page_numbers' => $pages,
            'notes' => null,
        ];

        $service = app(MemorizationService::class);
        $duplicatePages = $service->findDuplicatePages(
            $enrollment,
            $pages,
            'new',
        );

        if ($duplicatePages !== []) {
            $this->openDuplicateModal($enrollment, $payload, $duplicatePages);

            return;
        }

        $this->saveQuickEntrySession($enrollment, $payload);

        session()->flash('status', __('workflow.memorization.quick_entry.messages.saved'));

        $this->reset(['selectedStudentId', 'selectedEnrollmentId', 'selectedJuzNumber', 'selectedPages', 'teacher_id']);
        unset($this->unfinishedJuzs);
        $this->resetValidation();
    }

    public function confirmDuplicateSave(): void
    {
        $this->authorizePermission('memorization.record');
        OperationalFeatureSettings::ensureMemorizationAndSabersEnabled();

        if ($this->pendingMemorizationPayload === [] || ! $this->pendingEnrollmentId) {
            return;
        }

        if ($this->uniquePages === []) {
            $this->closeDuplicateModal();

            return;
        }

        $enrollment = $this->quickEntryEnrollmentsQuery()->where('status', 'active')
            ->with(['student', 'group.teacher'])
            ->findOrFail($this->pendingEnrollmentId);

        $student = $this->findQuickEntryStudent($enrollment->student_id);
        $juzId = QuranJuz::query()
            ->where('from_page', '<=', $this->pendingMemorizationPayload['from_page'])
            ->where('to_page', '>=', $this->pendingMemorizationPayload['from_page'])
            ->value('id');
        if ($this->excludedJuzIds($student)->contains($juzId)) {
            $this->closeDuplicateModal();
            $this->addError('selectedPages', __('workflow.memorization.quick_entry.picker.unavailable_juz'));

            return;
        }

        $this->saveQuickEntrySession(
            $enrollment,
            $this->pendingMemorizationPayload,
            true,
        );

        session()->flash(
            'status',
            __('workflow.memorization.quick_entry.messages.saved_partial', ['pages' => implode(', ', $this->duplicatePages)]),
        );

        $this->closeDuplicateModal();
        $this->reset(['selectedStudentId', 'selectedEnrollmentId', 'selectedJuzNumber', 'selectedPages', 'teacher_id']);
        unset($this->unfinishedJuzs);
        $this->resetValidation();
    }

    protected function saveQuickEntrySession(Enrollment $enrollment, array $payload, bool $skipDuplicates = false): void
    {
        DB::transaction(function () use ($enrollment, $payload, $skipDuplicates): void {
            $session = app(MemorizationService::class)->saveSession($enrollment, $payload, null, $skipDuplicates);
            $page = $session->pages->first()?->page_no;
            if ($page !== null) {
                $juz = QuranJuz::where('from_page', '<=', $page)->where('to_page', '>=', $page)->firstOrFail();
                $enrollment->student()->firstOrFail()->update(['quran_current_juz_id' => $juz->id]);
            }
        });
    }

    protected function canChooseRecordingTeacher(): bool
    {
        return auth()->user()?->hasAnyRole(['manager', 'admin', 'super_admin']) ?? false;
    }

    protected function currentTeacher(): ?Teacher
    {
        if ($this->canChooseRecordingTeacher()) {
            return null;
        }

        return auth()->user()?->teacherProfile;
    }

    protected function availableRecordingTeachers()
    {
        if (! $this->canChooseRecordingTeacher()) {
            return collect();
        }

        return Teacher::query()->where('status', 'active')->orderBy('first_name')->orderBy('last_name')->get();
    }

    protected function quickEntryStudentsQuery(): Builder
    {
        return Student::query()
            ->with(['parentProfile'])
            ->where('status', 'active')
            ->whereHas('enrollments', fn (Builder $builder) => $this->quickEntryEnrollmentsQuery($builder)->where('status', 'active'));
    }

    protected function quickEntryEnrollmentsQuery(?Builder $query = null): Builder
    {
        $query ??= Enrollment::query();

        return $query->whereHas('group.course', fn (Builder $courseQuery) => $courseQuery->where('is_active', true));
    }

    protected function findQuickEntryStudent(int $studentId): Student
    {
        return $this->quickEntryStudentsQuery()->findOrFail($studentId);
    }

    protected function findQuickEntryEnrollmentForStudent(Student $student, ?int $selectedEnrollmentId = null): ?Enrollment
    {
        $enrollments = $this->availableQuickEntryEnrollmentsForStudent($student->id);

        if ($enrollments->isEmpty()) {
            return null;
        }

        if ($selectedEnrollmentId !== null) {
            abort_unless($enrollments->contains('id', $selectedEnrollmentId), 403);

            return $enrollments->firstWhere('id', $selectedEnrollmentId);
        }

        if ($enrollments->count() > 1) {
            $this->addError('selectedEnrollmentId', __('workflow.memorization.errors.select_group'));

            return null;
        }

        return $enrollments->first();
    }

    protected function availableQuickEntryEnrollmentsForStudent(int $studentId)
    {
        return $this->quickEntryEnrollmentsQuery(
            Enrollment::query()
                ->with(['student', 'group.course', 'group.teacher', 'group.assistantTeacher'])
                ->where('student_id', $studentId)
                ->where('status', 'active')
                ->latest('enrolled_at')
                ->latest('id')
        )->get();
    }

    protected function selectedQuickEntryEnrollment($availableEnrollments): ?Enrollment
    {
        if ($availableEnrollments->isEmpty()) {
            return null;
        }

        if ($this->selectedEnrollmentId) {
            return $availableEnrollments->firstWhere('id', $this->selectedEnrollmentId);
        }

        return $availableEnrollments->count() === 1
            ? $availableEnrollments->first()
            : null;
    }

    protected function resolveQuickEntryTeacher(Enrollment $enrollment): ?Teacher
    {
        return $this->canChooseRecordingTeacher()
            ? ($enrollment->group?->teacher ?: $enrollment->group?->assistantTeacher)
            : $this->currentTeacher();
    }

    public function closeDuplicateModal(): void
    {
        $this->showDuplicateModal = false;
        $this->duplicatePages = [];
        $this->uniquePages = [];
        $this->pendingMemorizationPayload = [];
        $this->pendingEnrollmentId = null;
    }

    protected function openDuplicateModal(Enrollment $enrollment, array $payload, array $duplicatePages): void
    {
        $pageNumbers = $payload['page_numbers'];

        $this->duplicatePages = $duplicatePages;
        $this->uniquePages = array_values(array_diff($pageNumbers, $duplicatePages));
        $this->pendingMemorizationPayload = $payload;
        $this->pendingEnrollmentId = $enrollment->id;
        $this->showDuplicateModal = true;
        $this->resetValidation();
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 text-center lg:p-8">
        <h1 class="font-display text-4xl leading-none text-white md:text-5xl">{{ __('workflow.memorization.quick_entry.title') }}</h1>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    @if (session('error'))
        <div class="rounded-3xl border border-red-400/25 bg-red-500/12 px-4 py-3 text-sm text-red-100">{{ session('error') }}</div>
    @endif

    <section class="surface-panel w-full p-6 lg:p-8">
        @if (! $entriesEnabled)
            <x-quick-entry-disabled :message="__('quick-tests.memorization_disabled_warning')" />
        @else
        <form wire:submit="save" class="space-y-5">
            <div class="admin-form-field">
                <label for="quick-memorization-student">{{ __('workflow.memorization.quick_entry.form.student') }}</label>
                <select id="quick-memorization-student" wire:model.live="selectedStudentId" data-search-input="true" data-open-on-focus="true" data-hide-placeholder-option="true" data-search-placeholder="{{ __('workflow.common.student_name_placeholder') }}" data-record-label="person">
                    <option value="">{{ __('workflow.memorization.workbench.form.select_student') }}</option>
                    @foreach ($studentOptions as $student)
                        <option value="{{ $student->id }}">{{ $student->full_name }}</option>
                    @endforeach
                </select>
                @error('selectedStudentId')
                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                @enderror
            </div>

            @if ($selectedStudentId)
                <section class="memorization-page-picker" wire:key="page-picker-{{ $selectedStudentId }}" data-memorization-page-picker>
                    @if ($displayedJuz)
                        <div class="memorization-page-picker__heading">
                            <button type="button" wire:click="navigateJuz(1)" class="memorization-page-picker__nav" @disabled(! $unfinishedJuzs->contains(fn ($juz) => $juz['number'] > $selectedJuzNumber)) title="{{ __('workflow.memorization.quick_entry.picker.next') }}" aria-label="{{ __('workflow.memorization.quick_entry.picker.next') }}" wire:loading.attr="disabled">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m9 5 7 7-7 7" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            </button>
                            <div class="memorization-page-picker__title memorization-juz-switcher" x-data="{
                                open: false, top: 0, left: 0, maxHeight: 384,
                                toggle() {
                                    this.open = !this.open;
                                    if (!this.open) return;
                                    const rect = this.$refs.trigger.getBoundingClientRect();
                                    const width = Math.min(336, window.innerWidth - 24);
                                    const below = window.innerHeight - rect.bottom - 20;
                                    const above = rect.top - 20;
                                    const placeBelow = below >= Math.min(384, above);
                                    this.maxHeight = Math.max(0, Math.min(384, placeBelow ? below : above));
                                    this.left = Math.max(12, Math.min(rect.left + rect.width / 2 - width / 2, window.innerWidth - width - 12));
                                    this.top = placeBelow ? rect.bottom + 8 : Math.max(12, rect.top - this.maxHeight - 8);
                                    this.$nextTick(() => {
                                        if (!placeBelow) this.top = Math.max(12, rect.top - this.$refs.choices.offsetHeight - 8);
                                        this.$refs.choices.querySelector('[aria-pressed=true]')?.focus({ preventScroll: true });
                                    });
                                }
                            }" x-on:resize.window="open = false" x-on:scroll.window="if ($event.target === document) open = false" x-on:keydown.escape.stop="open = false; $refs.trigger.focus({ preventScroll: true })">
                                <h2>
                                    <button type="button" x-ref="trigger" x-on:click="toggle()" class="memorization-juz-switcher__trigger" :aria-expanded="open" aria-haspopup="dialog" title="{{ __('workflow.memorization.quick_entry.picker.choose_juz') }}" data-memorization-juz-trigger>
                                        <span>{{ __('workflow.common.labels.juz_number', ['number' => $displayedJuz['number']]) }}</span>
                                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="m6 8 4 4 4-4" /></svg>
                                    </button>
                                </h2>
                                <template x-teleport="body">
                                    <div x-show="open" x-cloak x-ref="choices" x-on:keydown.escape.prevent.stop="open = false; $refs.trigger.focus({ preventScroll: true })" x-on:click.outside="if (!$refs.trigger.contains($event.target)) open = false" :style="{ top: top + 'px', left: left + 'px', maxHeight: maxHeight + 'px' }" class="memorization-juz-switcher__panel" role="dialog" aria-label="{{ __('workflow.memorization.quick_entry.picker.choose_juz') }}">
                                        <p>{{ __('workflow.memorization.quick_entry.picker.choose_juz') }}</p>
                                        <div class="memorization-juz-switcher__grid">
                                            @foreach ($unfinishedJuzs as $juz)
                                                <button type="button" wire:key="juz-choice-{{ $selectedStudentId }}-{{ $juz['number'] }}" wire:click="selectJuz({{ $juz['number'] }})" x-on:click="open = false; $refs.trigger.focus({ preventScroll: true })" aria-pressed="{{ $selectedJuzNumber === $juz['number'] ? 'true' : 'false' }}" aria-label="{{ __('workflow.common.labels.juz_number', ['number' => $juz['number']]) }}" wire:loading.attr="disabled" data-juz-choice="{{ $juz['number'] }}">{{ $juz['number'] }}</button>
                                            @endforeach
                                        </div>
                                    </div>
                                </template>
                            </div>
                            <button type="button" wire:click="navigateJuz(-1)" class="memorization-page-picker__nav" @disabled(! $unfinishedJuzs->contains(fn ($juz) => $juz['number'] < $selectedJuzNumber)) title="{{ __('workflow.memorization.quick_entry.picker.previous') }}" aria-label="{{ __('workflow.memorization.quick_entry.picker.previous') }}" wire:loading.attr="disabled">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m15 5-7 7 7 7" stroke-linecap="round" stroke-linejoin="round" /></svg>
                            </button>
                        </div>
                        <div x-data="{ pages: $wire.entangle('selectedPages') }">
                            <div class="memorization-page-picker__grid" style="--memorization-page-columns: {{ min(count($displayedJuz['missing']), max(5, (int) ceil(count($displayedJuz['missing']) / 4))) }}" role="group" aria-label="{{ __('workflow.memorization.quick_entry.picker.pages') }}" dir="rtl">
                                @foreach ($displayedJuz['missing'] as $page)
                                    <button type="button" class="memorization-page-picker__page" wire:key="memorization-page-{{ $selectedStudentId }}-{{ $page }}" data-memorization-page="{{ $page }}" x-bind:aria-pressed="pages.includes({{ $page }})" x-on:click="pages = pages.includes({{ $page }}) ? pages.filter(page => page !== {{ $page }}) : [...pages, {{ $page }}]" aria-label="{{ __('workflow.memorization.quick_entry.picker.page', ['number' => $page]) }}">
                                        <span>{{ $page }}</span>
                                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m3 8 3 3 7-7" stroke-linecap="round" stroke-linejoin="round" /></svg>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <div class="memorization-page-picker__complete">{{ __('workflow.memorization.quick_entry.picker.complete') }}</div>
                    @endif
                    @error('selectedJuzNumber') <div class="mt-3 text-sm text-red-400">{{ $message }}</div> @enderror
                    @error('selectedPages') <div class="mt-3 text-sm text-red-400">{{ $message }}</div> @enderror
                    @error('selectedPages.*') <div class="mt-3 text-sm text-red-400">{{ $message }}</div> @enderror
                </section>
            @endif

            @if ($selectedStudentId && $availableEnrollments->count() > 1)
                <div class="admin-form-field">
                    <label for="quick-memorization-enrollment">{{ __('workflow.memorization.workbench.form.group') }}</label>
                    <select id="quick-memorization-enrollment" wire:model.live="selectedEnrollmentId" data-record-label="course">
                        <option value="">{{ __('workflow.memorization.workbench.form.select_group') }}</option>
                        @foreach ($availableEnrollments as $enrollment)
                            <option value="{{ $enrollment->id }}">
                                {{ $enrollment->group?->name ?: __('crud.common.not_available') }}
                                @if ($enrollment->group?->course?->name)
                                    - {{ $enrollment->group->course->name }}
                                @endif
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif
            @error('selectedEnrollmentId')
                <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
            @enderror

            @if ($canChooseRecordingTeacher)
            <div class="admin-form-field">
                <label for="quick-memorization-teacher">{{ __('workflow.quran_tests.form.teacher') }}</label>
                <select id="quick-memorization-teacher" wire:model="teacher_id" class="w-full rounded-xl px-4 py-3 text-sm" data-record-label="person">
                    <option value="">{{ __('workflow.quran_tests.form.select_teacher') }}</option>
                    @foreach ($teacherOptions as $teacher)
                        <option value="{{ $teacher->id }}">{{ $teacher->first_name }} {{ $teacher->last_name }}</option>
                    @endforeach
                </select>
                @error('teacher_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
            </div>
            @endif

            <div class="admin-action-cluster admin-action-cluster--end quick-memorization-save-actions">
                <button type="submit" class="admin-icon-button admin-icon-button--accent quick-entry-save-action" title="{{ __('workflow.memorization.quick_entry.form.save') }}" aria-label="{{ __('workflow.memorization.quick_entry.form.save') }}" data-quick-memorization-save-action wire:loading.attr="disabled" wire:target="save,confirmDuplicateSave" @disabled($selectedStudentId && ! $displayedJuz)><x-admin-action-icon name="save" /></button>
            </div>
        </form>
        @endif
    </section>

    <x-admin.modal
        :show="$showDuplicateModal"
        :title="__('workflow.memorization.duplicates.title')"
        :description="__('workflow.memorization.duplicates.description')"
        close-method="closeDuplicateModal"
        max-width="3xl"
    >
        <div class="space-y-4 text-sm text-neutral-300">
            <div class="rounded-2xl border border-amber-300/25 bg-amber-500/10 px-4 py-3 text-amber-100">
                {{ __('workflow.memorization.errors.duplicate_pages', ['pages' => implode(', ', $duplicatePages)]) }}
            </div>

            @if ($uniquePages !== [])
                <div class="rounded-2xl border border-emerald-300/20 bg-emerald-500/10 px-4 py-3 text-emerald-100">
                    {{ __('workflow.memorization.duplicates.unique_pages', ['pages' => implode(', ', $uniquePages)]) }}
                </div>
            @else
                <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-neutral-200">
                    {{ __('workflow.memorization.duplicates.no_unique_pages') }}
                </div>
            @endif
        </div>

        <div class="mt-6 flex flex-wrap items-center gap-3">
            @if ($uniquePages !== [])
                <button type="button" wire:click="confirmDuplicateSave" class="pill-link pill-link--accent">
                    {{ __('workflow.memorization.duplicates.save_unique') }}
                </button>
            @endif

            <button type="button" wire:click="closeDuplicateModal" class="pill-link">
                {{ __('crud.common.actions.cancel') }}
            </button>
        </div>
    </x-admin.modal>
</div>
