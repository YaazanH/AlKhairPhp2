<?php

use App\Models\Enrollment;
use App\Models\QuranFinalTest;
use App\Models\QuranJuz;
use App\Models\QuranPartialTest;
use App\Models\Student;
use App\Services\AccessScopeService;
use App\Services\QuranFinalTestService;
use App\Services\QuranPartialTestService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;

new class extends Component
{
    public string $tab = 'partial';
    public ?int $partialStudentId = null;
    public ?int $partialTestId = null;
    public ?int $partialJuzId = null;
    public array $partialQuarters = [];
    public string $mistakeCount = '';
    public ?int $finalStudentId = null;
    public ?int $finalTestId = null;
    public ?int $finalJuzId = null;
    public string $finalMark = '';

    public function mount(): void
    {
        $user = auth()->user();
        abort_unless($user?->hasPermissionTo('quran-tests.quick-entry'), 403);
        abort_unless($user->teacherProfile, 403, __('quick-tests.teacher_required'));
    }

    public function with(): array
    {
        $students = $this->studentsQuery()
            ->with('parentProfile')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        $partialJuz = $this->partialJuzId ? QuranJuz::query()->find($this->partialJuzId) : null;
        $availableQuarters = $this->availablePartialQuarters();

        return [
            'students' => $students,
            'partialJuz' => $partialJuz,
            'availableQuarters' => $availableQuarters,
            'finalJuzs' => $this->availableFinalJuzs(),
        ];
    }

    public function updatedPartialStudentId(): void
    {
        $this->partialTestId = null;
        $this->partialJuzId = null;
        $this->partialQuarters = [];
        $this->mistakeCount = '';
        $this->resetValidation();

        if (! $this->partialStudentId) {
            return;
        }

        $student = $this->studentsQuery()->with('pageAchievements')->findOrFail($this->partialStudentId);
        $openTest = $this->partialTestsQuery()
            ->with(['parts', 'juz'])
            ->where('student_id', $student->id)
            ->where('status', 'in_progress')
            ->latest('id')
            ->first();

        $this->partialTestId = $openTest?->id;
        $this->partialJuzId = $openTest?->juz_id
            ?: app(QuranPartialTestService::class)->eligibleJuzIdsForStudent($student)->first();
    }

    public function updatedFinalStudentId(): void
    {
        $this->finalTestId = null;
        $this->finalJuzId = null;
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

    public function savePartial(): void
    {
        $validated = $this->validate([
            'partialStudentId' => ['required', 'integer'],
            'partialJuzId' => ['required', 'integer'],
            'partialQuarters' => ['required', 'array', 'min:1'],
            'partialQuarters.*' => ['integer', 'between:1,4'],
            'mistakeCount' => ['required', 'integer', 'min:0', 'max:999'],
        ]);

        $student = $this->studentsQuery()->with('pageAchievements')->findOrFail($validated['partialStudentId']);
        $enrollment = $this->enrollmentFor($student);
        $availableQuarterNumbers = $this->availablePartialQuarters()->all();
        abort_unless(collect($validated['partialQuarters'])->every(fn ($quarter) => in_array((int) $quarter, $availableQuarterNumbers, true)), 422);

        DB::transaction(function () use ($student, $enrollment, $validated): void {
            $test = $this->partialTestId
                ? $this->partialTestsQuery()->where('student_id', $student->id)->findOrFail($this->partialTestId)
                : app(QuranPartialTestService::class)->create($enrollment, QuranJuz::query()->findOrFail($validated['partialJuzId']));

            foreach (collect($validated['partialQuarters'])->map(fn ($quarter) => (int) $quarter)->sort() as $quarter) {
                $part = $test->parts()->where('part_number', $quarter)->where('status', 'pending')->firstOrFail();
                app(QuranPartialTestService::class)->recordAttempt($part, auth()->user()->teacherProfile, [
                    'mistake_count' => $validated['mistakeCount'],
                    'tested_on' => now()->toDateString(),
                ]);
            }
        });

        session()->flash('status', __('quick-tests.partial_saved'));
        $this->updatedPartialStudentId();
    }

    public function saveFinal(): void
    {
        $validated = $this->validate([
            'finalStudentId' => ['required', 'integer'],
            'finalJuzId' => ['required', 'integer'],
            'finalMark' => ['required', 'numeric', 'between:0,100'],
        ]);

        $student = $this->studentsQuery()->findOrFail($validated['finalStudentId']);
        $availableJuzIds = $this->availableFinalJuzs()->pluck('id')->map(fn ($id) => (int) $id);
        abort_unless($availableJuzIds->contains((int) $validated['finalJuzId']), 422);

        DB::transaction(function () use ($student, $validated): void {
            $test = $this->finalTestId
                ? $this->finalTestsQuery()->where('student_id', $student->id)->findOrFail($this->finalTestId)
                : app(QuranFinalTestService::class)->create(
                    $this->enrollmentFor($student),
                    QuranJuz::query()->findOrFail($validated['finalJuzId']),
                );

            app(QuranFinalTestService::class)->recordAttempt($test, auth()->user()->teacherProfile, [
                'score' => $validated['finalMark'],
                'tested_on' => now()->toDateString(),
            ]);
        });

        session()->flash('status', __('quick-tests.final_saved'));
        $this->updatedFinalStudentId();
    }

    protected function studentsQuery(): Builder
    {
        return app(AccessScopeService::class)->scopeStudents(Student::query(), auth()->user())
            ->where('status', 'active')
            ->whereHas('enrollments', fn (Builder $query) => $query
                ->where('status', 'active')
                ->whereHas('group.course', fn (Builder $course) => $course->where('is_active', true)));
    }

    protected function enrollmentFor(Student $student): Enrollment
    {
        return app(AccessScopeService::class)->scopeEnrollments(Enrollment::query(), auth()->user())
            ->with(['student', 'group.course'])
            ->where('student_id', $student->id)
            ->where('status', 'active')
            ->whereHas('group.course', fn (Builder $query) => $query->where('is_active', true))
            ->latest('enrolled_at')
            ->latest('id')
            ->firstOrFail();
    }

    protected function partialTestsQuery(): Builder
    {
        return app(AccessScopeService::class)->scopeQuranPartialTests(QuranPartialTest::query(), auth()->user());
    }

    protected function finalTestsQuery(): Builder
    {
        return app(AccessScopeService::class)->scopeQuranFinalTests(QuranFinalTest::query(), auth()->user());
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
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <h1 class="font-display text-4xl leading-none text-white md:text-5xl">{{ __('quick-tests.title') }}</h1>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="surface-panel p-5 lg:p-6">
        <div class="mb-6 flex gap-2" role="tablist">
            <button type="button" wire:click="$set('tab', 'partial')" class="pill-link {{ $tab === 'partial' ? 'pill-link--accent' : '' }}">{{ __('quick-tests.partial') }}</button>
            <button type="button" wire:click="$set('tab', 'final')" class="pill-link {{ $tab === 'final' ? 'pill-link--accent' : '' }}">{{ __('quick-tests.final') }}</button>
        </div>

        @if ($tab === 'partial')
            <form wire:submit="savePartial" class="grid gap-5 lg:grid-cols-2">
                <div class="lg:col-span-2">
                    <label class="mb-1 block text-sm font-medium">{{ __('quick-tests.student') }}</label>
                    <select wire:model.live="partialStudentId" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('quick-tests.select_student') }}</option>
                        @foreach ($students as $student)<option value="{{ $student->id }}">{{ $student->full_name }}</option>@endforeach
                    </select>
                    @error('partialStudentId') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>

                <div>
                    <div class="mb-1 text-sm font-medium">{{ __('quick-tests.juz') }}</div>
                    <div class="rounded-xl border border-white/10 px-4 py-3 text-sm text-white">
                        {{ $partialJuz ? __('workflow.common.labels.juz_number', ['number' => $partialJuz->juz_number]) : __('quick-tests.no_juz') }}
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('quick-tests.mistakes') }}</label>
                    <input wire:model="mistakeCount" type="number" min="0" max="999" step="1" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('mistakeCount') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>

                <fieldset class="lg:col-span-2">
                    <legend class="mb-2 text-sm font-medium">{{ __('quick-tests.quarters') }}</legend>
                    @if ($availableQuarters->isEmpty())
                        <div class="text-sm text-neutral-400">{{ __('quick-tests.no_quarters') }}</div>
                    @else
                        <div class="flex flex-wrap gap-3">
                            @foreach ($availableQuarters as $quarter)
                                <label class="flex items-center gap-2 rounded-xl border border-white/10 px-4 py-3 text-sm">
                                    <input wire:model="partialQuarters" type="checkbox" value="{{ $quarter }}">
                                    <span>{{ __('quick-tests.quarter', ['number' => $quarter]) }}</span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                    @error('partialQuarters') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </fieldset>

                <div class="lg:col-span-2">
                    <button type="submit" class="pill-link pill-link--accent" @disabled(! $partialJuz || $availableQuarters->isEmpty())>{{ __('quick-tests.save') }}</button>
                </div>
            </form>
        @else
            <form wire:submit="saveFinal" class="grid gap-5 lg:grid-cols-2">
                <div class="lg:col-span-2">
                    <label class="mb-1 block text-sm font-medium">{{ __('quick-tests.student') }}</label>
                    <select wire:model.live="finalStudentId" class="w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('quick-tests.select_student') }}</option>
                        @foreach ($students as $student)<option value="{{ $student->id }}">{{ $student->full_name }}</option>@endforeach
                    </select>
                    @error('finalStudentId') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('quick-tests.juz') }}</label>
                    <select wire:model.live="finalJuzId" class="w-full rounded-xl px-4 py-3 text-sm" @disabled($finalJuzs->isEmpty())>
                        @if ($finalJuzs->isEmpty())<option value="">{{ __('quick-tests.no_juz') }}</option>@endif
                        @foreach ($finalJuzs as $juz)<option value="{{ $juz->id }}">{{ __('workflow.common.labels.juz_number', ['number' => $juz->juz_number]) }}</option>@endforeach
                    </select>
                    @error('finalJuzId') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('quick-tests.mark') }}</label>
                    <input wire:model="finalMark" type="number" min="0" max="100" step="0.01" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('finalMark') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>

                <div class="lg:col-span-2">
                    <button type="submit" class="pill-link pill-link--accent" @disabled($finalJuzs->isEmpty())>{{ __('quick-tests.save') }}</button>
                </div>
            </form>
        @endif
    </section>
</div>
