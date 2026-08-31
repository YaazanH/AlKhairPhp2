<?php

use App\Models\Enrollment;
use App\Models\QuranFinalTest;
use App\Models\QuranJuz;
use App\Models\QuranPartialTest;
use App\Models\QuranTest;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\QuranFinalTestService;
use App\Services\QuranPartialTestService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    public string $tab = 'partial';
    public ?int $partialStudentId = null;
    public ?int $partialTestId = null;
    public ?int $partialJuzId = null;
    public ?int $partialQuarter = null;
    public string $mistakeCount = '';
    public ?int $finalStudentId = null;
    public ?int $finalTestId = null;
    public ?int $finalJuzId = null;
    public string $finalTestedOn = '';
    public string $finalMark = '';
    public bool $canRecordPartial = false;
    public bool $canRecordFinal = false;
    public bool $showCurrentJuzModal = false;
    public ?int $passedFinalTestId = null;
    public string $newCurrentJuzNumber = '';

    public function mount(): void
    {
        $user = auth()->user();
        abort_unless($user?->can('quran-tests.quick-entry'), 403);
        $this->canRecordPartial = $user->can('quran-partial-tests.record');
        $this->canRecordFinal = $user->can('quran-final-tests.record');
        abort_unless($this->canRecordPartial || $this->canRecordFinal, 403);

        $this->tab = $this->canRecordPartial ? 'partial' : 'final';
        $this->finalTestedOn = now()->toDateString();
    }

    public function with(): array
    {
        $students = $this->studentsQuery()
            ->with('parentProfile')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        return [
            'entriesEnabled' => \App\Support\OperationalFeatureSettings::memorizationAndSabersEnabled(),
            'students' => $students,
            'partialJuzs' => $this->availablePartialJuzs(),
            'availableQuarters' => $this->availablePartialQuarters(),
            'finalJuzs' => $this->availableFinalJuzs(),
            'passedFinalTest' => $this->passedFinalTest(),
            'availableCurrentJuzs' => $this->availableCurrentJuzs(),
        ];
    }

    public function updatedPartialStudentId(): void
    {
        $this->partialTestId = null;
        $this->partialJuzId = null;
        $this->partialQuarter = null;
        $this->mistakeCount = '';
        $this->resetValidation();

        if (! $this->partialStudentId) {
            return;
        }

        $this->partialJuzId = $this->availablePartialJuzs()->first()?->id;
        $this->syncPartialTest();
    }

    public function updatedPartialJuzId(): void
    {
        $this->partialQuarter = null;
        $this->syncPartialTest();
    }

    public function updatedFinalStudentId(): void
    {
        $this->finalTestId = null;
        $this->finalJuzId = null;
        $this->finalTestedOn = now()->toDateString();
        $this->finalMark = '';
        $this->resetValidation();

        if (! $this->finalStudentId) {
            return;
        }

        $student = $this->studentsQuery()->findOrFail($this->finalStudentId);
        $openTest = $this->finalTestsQuery()
            ->where('student_id', $student->id)
            ->where('status', 'in_progress')
            ->latest('id')
            ->first();

        $this->finalTestId = $openTest?->id;
        $this->finalJuzId = $openTest?->juz_id
            ?: app(QuranFinalTestService::class)->eligibleJuzIdsForStudent($student)->first();
    }

    public function updatedFinalJuzId(): void
    {
        if (! $this->finalStudentId || ! $this->finalJuzId) {
            $this->finalTestId = null;
            return;
        }

        $this->finalTestId = $this->finalTestsQuery()
            ->where('student_id', $this->finalStudentId)
            ->where('juz_id', $this->finalJuzId)
            ->where('status', 'in_progress')
            ->value('id');
    }

    public function switchTab(string $tab): void
    {
        abort_unless(in_array($tab, ['partial', 'final'], true), 404);
        abort_if($tab === 'partial' && ! $this->canRecordPartial, 403);
        abort_if($tab === 'final' && ! $this->canRecordFinal, 403);

        $this->clearPartialEntry();
        $this->clearFinalEntry();
        $this->tab = $tab;
    }

    public function savePartial(): void
    {
        abort_unless(auth()->user()?->can('quran-partial-tests.record'), 403);
        \App\Support\OperationalFeatureSettings::ensureMemorizationAndSabersEnabled();

        $validated = $this->validate([
            'partialStudentId' => ['required', 'integer'],
            'partialJuzId' => ['required', 'integer'],
            'partialQuarter' => ['required', 'integer', 'between:1,4'],
            'mistakeCount' => ['required', 'integer', 'min:0', 'max:999'],
        ]);

        $student = $this->studentsQuery()->with('pageAchievements')->findOrFail($validated['partialStudentId']);
        $enrollment = $this->enrollmentFor($student);
        $recordingTeacher = $this->recordingTeacher();
        $availableQuarterNumbers = $this->availablePartialQuarters()->all();
        abort_unless(in_array((int) $validated['partialQuarter'], $availableQuarterNumbers, true), 422);

        DB::transaction(function () use ($student, $enrollment, $recordingTeacher, $validated): void {
            $test = $this->partialTestId
                ? $this->partialTestsQuery()->where('student_id', $student->id)->findOrFail($this->partialTestId)
                : app(QuranPartialTestService::class)->create($enrollment, QuranJuz::query()->findOrFail($validated['partialJuzId']));

            $part = $test->parts()->where('part_number', (int) $validated['partialQuarter'])->where('status', 'pending')->firstOrFail();
            app(QuranPartialTestService::class)->recordAttempt($part, $recordingTeacher, [
                'mistake_count' => $validated['mistakeCount'],
                'tested_on' => now()->toDateString(),
            ]);
        });

        session()->flash('status', __('quick-tests.partial_saved'));
        $this->clearPartialEntry();
    }

    public function saveFinal(): void
    {
        abort_unless(auth()->user()?->can('quran-final-tests.record'), 403);
        \App\Support\OperationalFeatureSettings::ensureMemorizationAndSabersEnabled();

        $validated = $this->validate([
            'finalStudentId' => ['required', 'integer'],
            'finalJuzId' => ['required', 'integer'],
            'finalTestedOn' => ['required', 'date'],
            'finalMark' => ['required', 'numeric', 'between:0,100'],
        ]);

        $student = $this->studentsQuery()->findOrFail($validated['finalStudentId']);
        $enrollment = $this->enrollmentFor($student);
        $recordingTeacher = $this->recordingTeacher();
        $availableJuzIds = $this->availableFinalJuzs()->pluck('id')->map(fn ($id) => (int) $id);
        abort_unless($availableJuzIds->contains((int) $validated['finalJuzId']), 422);

        $attempt = DB::transaction(function () use ($student, $enrollment, $recordingTeacher, $validated) {
            $test = $this->finalTestId
                ? $this->finalTestsQuery()->where('student_id', $student->id)->findOrFail($this->finalTestId)
                : app(QuranFinalTestService::class)->create(
                    $enrollment,
                    QuranJuz::query()->findOrFail($validated['finalJuzId']),
                );

            return app(QuranFinalTestService::class)->recordAttempt($test, $recordingTeacher, [
                'score' => $validated['finalMark'],
                'tested_on' => $validated['finalTestedOn'],
            ]);
        });

        session()->flash('status', __('quick-tests.final_saved'));
        if ($attempt->status === 'passed') {
            $this->passedFinalTestId = (int) $attempt->quran_final_test_id;
            $this->showCurrentJuzModal = true;
        }
        $this->clearFinalEntry();
    }

    public function saveCurrentJuz(): void
    {
        abort_unless(auth()->user()?->can('quran-final-tests.record'), 403);
        $test = $this->passedFinalTest();
        abort_unless($test, 404);

        $availableJuzs = $this->availableCurrentJuzs();
        $validated = $this->validate([
            'newCurrentJuzNumber' => ['required', 'integer', Rule::in($availableJuzs->pluck('juz_number')->map(fn ($number) => (string) $number)->all())],
        ]);
        $newCurrentJuz = $availableJuzs->firstWhere('juz_number', (int) $validated['newCurrentJuzNumber']);
        abort_unless($newCurrentJuz, 422);

        $test->student()->update(['quran_current_juz_id' => $newCurrentJuz->id]);
        $this->closeCurrentJuzModal();
        session()->flash('status', __('workflow.quran_final_tests.current_juz.updated'));
    }

    public function closeCurrentJuzModal(): void
    {
        $this->showCurrentJuzModal = false;
        $this->passedFinalTestId = null;
        $this->newCurrentJuzNumber = '';
        $this->resetValidation('newCurrentJuzNumber');
    }

    protected function studentsQuery(): Builder
    {
        return Student::query()->where('status', 'active')
            ->whereHas('enrollments', fn (Builder $query) => $query
                ->where('status', 'active')
                ->whereHas('group.course', fn (Builder $course) => $course->where('is_active', true)));
    }

    protected function enrollmentFor(Student $student): Enrollment
    {
        return Enrollment::query()
            ->with(['student', 'group.course'])
            ->where('student_id', $student->id)
            ->where('status', 'active')
            ->whereHas('group.course', fn (Builder $query) => $query->where('is_active', true))
            ->latest('enrolled_at')
            ->latest('id')
            ->firstOrFail();
    }

    protected function recordingTeacher(): Teacher
    {
        $teacher = auth()->user()?->teacherProfile;

        abort_unless($teacher, 422, __('quick-tests.teacher_required'));

        return $teacher;
    }

    protected function clearPartialEntry(): void
    {
        $this->partialStudentId = null;
        $this->partialTestId = null;
        $this->partialJuzId = null;
        $this->partialQuarter = null;
        $this->mistakeCount = '';
        $this->resetValidation();
    }

    protected function clearFinalEntry(): void
    {
        $this->finalStudentId = null;
        $this->finalTestId = null;
        $this->finalJuzId = null;
        $this->finalTestedOn = now()->toDateString();
        $this->finalMark = '';
        $this->resetValidation();
    }

    protected function partialTestsQuery(): Builder
    {
        return QuranPartialTest::query();
    }

    protected function finalTestsQuery(): Builder
    {
        return QuranFinalTest::query();
    }

    protected function availablePartialQuarters()
    {
        if (! $this->partialJuzId) {
            return collect();
        }

        if (! $this->partialTestId) {
            return collect(range(1, 4));
        }

        return $this->partialTestsQuery()->with('parts')->findOrFail($this->partialTestId)
            ->parts->where('status', 'pending')->pluck('part_number')->map(fn ($number) => (int) $number)->values();
    }

    protected function availablePartialJuzs()
    {
        if (! $this->partialStudentId) {
            return collect();
        }

        $student = $this->studentsQuery()->with('pageAchievements')->findOrFail($this->partialStudentId);
        $openJuzIds = $this->partialTestsQuery()
            ->where('student_id', $student->id)
            ->where('status', 'in_progress')
            ->pluck('juz_id')
            ->map(fn ($id) => (int) $id);
        $juzIds = $openJuzIds->isNotEmpty()
            ? $openJuzIds
            : app(QuranPartialTestService::class)->eligibleJuzIdsForStudent($student);

        return QuranJuz::query()->whereIn('id', $juzIds)->orderBy('juz_number')->get();
    }

    protected function syncPartialTest(): void
    {
        if (! $this->partialStudentId || ! $this->partialJuzId) {
            $this->partialTestId = null;
            return;
        }

        $this->partialTestId = $this->partialTestsQuery()
            ->where('student_id', $this->partialStudentId)
            ->where('juz_id', $this->partialJuzId)
            ->where('status', 'in_progress')
            ->value('id');
        $this->partialQuarter = $this->availablePartialQuarters()->min();
    }

    protected function availableFinalJuzs()
    {
        if (! $this->finalStudentId) {
            return collect();
        }

        $student = $this->studentsQuery()->findOrFail($this->finalStudentId);
        $openTest = $this->finalTestsQuery()
            ->where('student_id', $student->id)
            ->where('status', 'in_progress')
            ->first();
        $ids = $openTest
            ? collect([(int) $openTest->juz_id])
            : app(QuranFinalTestService::class)->eligibleJuzIdsForStudent($student);

        return QuranJuz::query()->whereIn('id', $ids)->orderBy('juz_number')->get();
    }

    protected function passedFinalTest(): ?QuranFinalTest
    {
        if (! $this->passedFinalTestId) {
            return null;
        }

        return QuranFinalTest::query()->with(['juz', 'student.externalMemorizedJuzs'])->find($this->passedFinalTestId);
    }

    protected function availableCurrentJuzs()
    {
        $test = $this->passedFinalTest();
        if (! $test) {
            return collect();
        }

        $blockedJuzIds = collect([$test->juz_id])
            ->merge($test->student?->externalMemorizedJuzs->pluck('id') ?? collect())
            ->merge(QuranFinalTest::query()->where('student_id', $test->student_id)->where('status', 'passed')->pluck('juz_id'))
            ->merge(QuranTest::query()->where('student_id', $test->student_id)->where('status', 'passed')->whereHas('type', fn ($query) => $query->where('code', 'final'))->pluck('juz_id'))
            ->filter()->unique()->all();

        return QuranJuz::query()->whereNotIn('id', $blockedJuzIds)->orderBy('juz_number')->get();
    }
}; ?>

<div class="page-stack">
    <section class="page-hero quick-saber-hero p-6 lg:p-8">
        <div class="quick-saber-hero__layout">
            <h1 class="font-display text-4xl leading-none text-white md:text-5xl">{{ __('quick-tests.title') }}</h1>
            @if ($entriesEnabled && $canRecordPartial && $canRecordFinal)
                <div class="quick-saber-type-switch" role="tablist" aria-label="{{ __('quick-tests.saber_type') }}">
                    <button type="button" role="tab" aria-selected="{{ $tab === 'partial' ? 'true' : 'false' }}" wire:click="switchTab('partial')" @class(['quick-saber-type-switch__option', 'is-active' => $tab === 'partial'])>
                        {{ __('quick-tests.partial') }}
                    </button>
                    <button type="button" role="tab" aria-selected="{{ $tab === 'final' ? 'true' : 'false' }}" wire:click="switchTab('final')" @class(['quick-saber-type-switch__option', 'is-active' => $tab === 'final'])>
                        {{ __('quick-tests.final') }}
                    </button>
                </div>
            @endif
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="surface-panel p-5 lg:p-6">
        @if (! $entriesEnabled)
            <x-quick-entry-disabled :message="__('quick-tests.saber_disabled_warning')" />
        @elseif ($tab === 'partial')
            <form wire:submit="savePartial" class="quick-saber-form">
                <div class="quick-saber-form__row">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('quick-tests.student') }}</label>
                        <select wire:model.live="partialStudentId" data-search-input="true" data-open-on-focus="true" data-hide-placeholder-option="true" data-search-placeholder="{{ __('workflow.common.student_name_placeholder') }}" class="quick-saber-control w-full rounded-xl px-4 text-sm">
                            <option value="">{{ __('quick-tests.select_student') }}</option>
                            @foreach ($students as $student)<option value="{{ $student->id }}">{{ $student->full_name }}</option>@endforeach
                        </select>
                        @error('partialStudentId') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium" for="quick-partial-juz">{{ __('quick-tests.juz') }}</label>
                        @if ($partialJuzs->count() > 1)
                            <select id="quick-partial-juz" wire:model.live="partialJuzId" class="quick-saber-control w-full rounded-xl px-4 text-sm">
                                @foreach ($partialJuzs as $juz)
                                    <option value="{{ $juz->id }}">{{ __('workflow.common.labels.juz_number', ['number' => $juz->juz_number]) }}</option>
                                @endforeach
                            </select>
                        @else
                            <div id="quick-partial-juz" class="quick-saber-readonly">
                                {{ $partialJuzs->isNotEmpty() ? __('workflow.common.labels.juz_number', ['number' => $partialJuzs->first()->juz_number]) : __('quick-tests.no_juz') }}
                            </div>
                        @endif
                        @error('partialJuzId') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="quick-saber-form__row">
                    <fieldset>
                        <legend class="mb-1 text-sm font-medium">{{ __('quick-tests.quarters') }}</legend>
                        @php($quarterOptions = $availableQuarters->isEmpty() ? collect(range(1, 4)) : $availableQuarters)
                        <div class="quick-saber-quarter-switch" style="--quick-saber-quarter-count: {{ max(1, $quarterOptions->count()) }};">
                            @foreach ($quarterOptions as $quarter)
                                <button type="button" wire:click="$set('partialQuarter', {{ $quarter }})" @disabled($availableQuarters->isEmpty()) @class(['quick-saber-quarter-switch__option', 'is-active' => $partialQuarter === (int) $quarter])>
                                    {{ __('quick-tests.quarter', ['number' => $quarter]) }}
                                </button>
                            @endforeach
                        </div>
                        @error('partialQuarter') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </fieldset>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('quick-tests.mistakes') }}</label>
                        <div class="quick-saber-affixed-input">
                            <input wire:model="mistakeCount" type="number" min="0" max="999" step="1" class="quick-saber-control w-full rounded-xl px-4 text-sm">
                            <span class="quick-saber-affixed-input__suffix" aria-hidden="true">{{ __('quick-tests.mistakes_suffix') }}</span>
                        </div>
                        @error('mistakeCount') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div>
                    <button type="submit" class="admin-icon-button admin-icon-button--accent quick-saber-save-action quick-entry-save-action" title="{{ __('quick-tests.save') }}" aria-label="{{ __('quick-tests.save') }}" data-quick-saber-partial-save-action @disabled(! $partialJuzId || ! $partialQuarter || $availableQuarters->isEmpty())><x-admin-action-icon name="save" /></button>
                </div>
            </form>
        @else
            <form wire:submit="saveFinal" class="quick-saber-form">
                <div class="quick-saber-form__row">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('quick-tests.student') }}</label>
                        <select wire:model.live="finalStudentId" data-search-input="true" data-open-on-focus="true" data-hide-placeholder-option="true" data-search-placeholder="{{ __('workflow.common.student_name_placeholder') }}" class="quick-saber-control w-full rounded-xl px-4 text-sm">
                            <option value="">{{ __('quick-tests.select_student') }}</option>
                            @foreach ($students as $student)<option value="{{ $student->id }}">{{ $student->full_name }}</option>@endforeach
                        </select>
                        @error('finalStudentId') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium" for="quick-final-juz">{{ __('quick-tests.juz') }}</label>
                        @if ($finalJuzs->count() > 1)
                            <select id="quick-final-juz" wire:model.live="finalJuzId" class="quick-saber-control w-full rounded-xl px-4 text-sm">
                                @foreach ($finalJuzs as $juz)
                                    <option value="{{ $juz->id }}">{{ __('workflow.common.labels.juz_number', ['number' => $juz->juz_number]) }}</option>
                                @endforeach
                            </select>
                        @else
                            <div id="quick-final-juz" class="quick-saber-readonly">
                                {{ $finalJuzs->isNotEmpty() ? __('workflow.common.labels.juz_number', ['number' => $finalJuzs->first()->juz_number]) : __('quick-tests.no_juz') }}
                            </div>
                        @endif
                        @error('finalJuzId') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="quick-saber-form__row">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('quick-tests.date') }}</label>
                        <input wire:model="finalTestedOn" value="{{ $finalTestedOn }}" type="date" class="quick-saber-control w-full rounded-xl px-4 text-sm">
                        @error('finalTestedOn') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('quick-tests.mark') }}</label>
                        <div class="quick-saber-affixed-input">
                            <input wire:model="finalMark" type="number" min="0" max="100" step="0.01" class="quick-saber-control w-full rounded-xl px-4 text-sm">
                            <span class="quick-saber-affixed-input__suffix" aria-hidden="true">%</span>
                        </div>
                        @error('finalMark') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div>
                    <button type="submit" class="admin-icon-button admin-icon-button--accent quick-saber-save-action quick-entry-save-action" title="{{ __('quick-tests.save') }}" aria-label="{{ __('quick-tests.save') }}" data-quick-saber-final-save-action @disabled($finalJuzs->isEmpty())><x-admin-action-icon name="save" /></button>
                </div>
            </form>
        @endif
    </section>

    <x-admin.modal :show="$showCurrentJuzModal" :title="__('workflow.quran_final_tests.current_juz.title')" :description="$passedFinalTest ? __('workflow.quran_final_tests.current_juz.tested', ['juz' => $passedFinalTest->juz?->juz_number]) : ''" close-method="closeCurrentJuzModal" max-width="xl">
        <form wire:submit="saveCurrentJuz" class="space-y-3">
            <div>
                <label for="quick-new-current-juz" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_final_tests.current_juz.enter') }}</label>
                <input id="quick-new-current-juz" wire:model="newCurrentJuzNumber" type="number" min="1" max="30" step="1" inputmode="numeric" class="w-full rounded-xl px-4 py-3 text-sm">
                @error('newCurrentJuzNumber') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
            </div>
            <div class="flex justify-end"><button type="submit" class="admin-icon-button admin-icon-button--accent admin-modal-action-button" title="{{ __('crud.common.actions.save') }}" aria-label="{{ __('crud.common.actions.save') }}" data-quick-saber-current-juz-save-action><x-admin-action-icon name="save" class="admin-modal-action__icon" /></button></div>
        </form>
    </x-admin.modal>
</div>
