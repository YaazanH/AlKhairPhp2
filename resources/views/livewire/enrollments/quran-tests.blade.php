<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\Enrollment;
use App\Models\QuranTest;
use App\Models\QuranTestType;
use App\Models\Teacher;
use App\Services\PointLedgerService;
use App\Services\QuranProgressionService;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public Enrollment $currentEnrollment;
    public ?int $juz_id = null;
    public string $tested_on = '';
    public string $score = '';
    public string $status = 'passed';
    public string $notes = '';

    public function mount(Enrollment $enrollment): void
    {
        $this->authorizePermission('quran-tests.view');

        $this->currentEnrollment = Enrollment::query()
            ->with(['student.quranCurrentJuz', 'group.course', 'group.teacher'])
            ->findOrFail($enrollment->id);

        $this->authorizeTeacherEnrollmentAccess($this->currentEnrollment);

        $this->juz_id = $this->eligibleJuzs()->first()?->id;
        $this->tested_on = now()->toDateString();
    }

    public function with(): array
    {
        return [
            'enrollmentRecord' => $this->currentEnrollment->fresh(['student.quranCurrentJuz', 'group.course', 'group.teacher']),
            'eligibleJuzs' => $this->eligibleJuzs(),
            'tests' => QuranTest::query()
                ->with(['juz', 'teacher', 'type'])
                ->where('enrollment_id', $this->currentEnrollment->id)
                ->whereHas('type', fn ($query) => $query->where('code', 'awqaf'))
                ->latest('tested_on')
                ->latest('id')
                ->get(),
        ];
    }

    public function saveQuranTest(): void
    {
        $this->authorizePermission('quran-tests.record');

        $validated = $this->validate([
            'juz_id' => ['required', 'exists:quran_juzs,id'],
            'tested_on' => ['required', 'date'],
            'score' => ['nullable', 'numeric', 'between:0,100'],
            'status' => ['required', 'in:passed,failed,cancelled'],
            'notes' => ['nullable', 'string'],
        ]);

        $teacherId = $this->resolvedTeacherId();

        if (! $teacherId) {
            $this->addError('juz_id', __('workflow.quran_tests.errors.no_teacher_available'));

            return;
        }

        $teacher = Teacher::query()->findOrFail($teacherId);
        $this->authorizeScopedTeacherAccess($teacher);

        $testType = QuranTestType::query()->where('code', 'awqaf')->where('is_active', true)->firstOrFail();
        $progression = app(QuranProgressionService::class)->validate($this->currentEnrollment, (int) $validated['juz_id'], $testType);

        if ($progression && ! auth()->user()->can('quran-tests.override-progression')) {
            $this->addError('juz_id', $progression);

            return;
        }

        $test = QuranTest::query()->create([
            'enrollment_id' => $this->currentEnrollment->id,
            'student_id' => $this->currentEnrollment->student_id,
            'teacher_id' => $teacherId,
            'juz_id' => (int) $validated['juz_id'],
            'quran_test_type_id' => $testType->id,
            'tested_on' => $validated['tested_on'],
            'score' => $validated['score'] !== '' ? $validated['score'] : null,
            'status' => $validated['status'],
            'attempt_no' => app(QuranProgressionService::class)->nextAttemptNumber(
                $this->currentEnrollment,
                (int) $validated['juz_id'],
                $testType->id,
            ),
            'notes' => $validated['notes'] ?: null,
        ]);

        app(PointLedgerService::class)->recordQuranTestPoints($test->fresh(['enrollment.student', 'student.gradeLevel', 'type']));

        $this->score = '';
        $this->status = 'passed';
        $this->notes = '';
        $this->juz_id = $this->eligibleJuzs()->first()?->id;

        session()->flash('status', __('workflow.quran_tests.messages.saved'));
    }

    protected function eligibleJuzs()
    {
        $studentId = $this->currentEnrollment->student_id;

        $eligibleJuzIds = app(QuranProgressionService::class)->eligibleAwqafJuzIdsForStudent($studentId);

        if ($eligibleJuzIds->isEmpty()) {
            return collect();
        }

        return \App\Models\QuranJuz::query()
            ->whereIn('id', $eligibleJuzIds)
            ->orderBy('juz_number')
            ->get();
    }

    protected function resolvedTeacherId(): ?int
    {
        return $this->currentTeacher()?->id ?: $this->currentEnrollment->group?->teacher_id;
    }

    protected function currentTeacher(): ?Teacher
    {
        return $this->linkedTeacherForPermission('quran-tests.record-linked-teacher');
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div>
            <a href="{{ route('enrollments.index') }}" wire:navigate class="text-sm font-medium text-neutral-200/80 hover:text-white">{{ __('workflow.common.back_to_enrollments') }}</a>
            <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.quran_tests.title') }}</h1>
            <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('workflow.quran_tests.subtitle') }}</p>
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[28rem_minmax(0,1fr)]">
        <section class="surface-panel p-5 lg:p-6">
            @if (auth()->user()->can('quran-tests.record'))
                <div class="mb-4">
                    <h2 class="text-lg font-semibold">{{ __('workflow.quran_tests.form.title') }}</h2>
                    <p class="text-sm text-neutral-400">{{ __('workflow.quran_tests.form.help') }}</p>
                </div>

                <form wire:submit="saveQuranTest" class="space-y-4">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="quran-test-date" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.tested_on') }}</label>
                            <input id="quran-test-date" wire:model="tested_on" type="date" class="w-full rounded-xl px-4 py-3 text-sm">
                            @error('tested_on') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                        </div>

                        <div>
                            <label for="quran-test-juz" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.juz') }}</label>
                            <select id="quran-test-juz" wire:model="juz_id" class="w-full rounded-xl px-4 py-3 text-sm">
                                <option value="">{{ __('workflow.quran_tests.form.select_juz') }}</option>
                                @foreach ($eligibleJuzs as $juz)
                                    <option value="{{ $juz->id }}">{{ __('workflow.common.labels.juz_number', ['number' => $juz->juz_number]) }}</option>
                                @endforeach
                            </select>
                            @if ($eligibleJuzs->isEmpty())
                                <div class="mt-1 text-xs text-neutral-500">{{ __('workflow.quran_tests.form.no_eligible_juzs') }}</div>
                            @endif
                            @error('juz_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="quran-test-score" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.score') }}</label>
                            <input id="quran-test-score" wire:model="score" type="number" min="0" max="100" step="0.01" class="w-full rounded-xl px-4 py-3 text-sm">
                            @error('score') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                        </div>

                        <div>
                            <label for="quran-test-status" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.result_status') }}</label>
                            <select id="quran-test-status" wire:model="status" class="w-full rounded-xl px-4 py-3 text-sm">
                                <option value="passed">{{ __('workflow.common.result_status.passed') }}</option>
                                <option value="failed">{{ __('workflow.common.result_status.failed') }}</option>
                                <option value="cancelled">{{ __('workflow.common.result_status.cancelled') }}</option>
                            </select>
                            @error('status') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div>
                        <label for="quran-test-notes" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.notes') }}</label>
                        <textarea id="quran-test-notes" wire:model="notes" rows="4" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>
                        @error('notes') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>

                    <button type="submit" class="pill-link pill-link--accent">
                        {{ __('workflow.common.actions.save_quran_test') }}
                    </button>
                </form>
            @else
                <div>
                    <h2 class="text-lg font-semibold">{{ __('workflow.quran_tests.read_only.title') }}</h2>
                    <p class="mt-2 text-sm text-neutral-400">{{ __('workflow.quran_tests.read_only.description') }}</p>
                </div>
            @endif
        </section>

        <section class="surface-table">
            <div class="admin-grid-meta">
                <div>
                    <div class="admin-grid-meta__title">{{ __('workflow.quran_tests.table.title') }}</div>
                    <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($tests->count())]) }}</div>
                </div>
            </div>

            @if ($tests->isEmpty())
                <div class="admin-empty-state">{{ __('workflow.quran_tests.table.empty') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="text-sm">
                        <thead>
                            <tr>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_tests.table.headers.date') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_tests.table.headers.juz') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_tests.table.headers.attempt') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_tests.table.headers.score') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('workflow.quran_tests.table.headers.status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/6">
                            @foreach ($tests as $test)
                                <tr>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $test->tested_on?->format('Y-m-d') }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ __('workflow.common.labels.juz_number', ['number' => $test->juz?->juz_number]) }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $test->attempt_no }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ $test->score !== null ? $test->score : __('workflow.common.not_available') }}</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ __('workflow.common.result_status.'.$test->status) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</div>
