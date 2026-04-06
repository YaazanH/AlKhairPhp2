<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\Enrollment;
use App\Models\QuranJuz;
use App\Models\QuranTest;
use App\Models\QuranTestType;
use App\Models\Teacher;
use App\Services\QuranProgressionService;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public Enrollment $currentEnrollment;
    public ?int $teacher_id = null;
    public ?int $juz_id = null;
    public ?int $quran_test_type_id = null;
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

        $this->teacher_id = $this->currentEnrollment->group?->teacher_id;
        $this->juz_id = $this->currentEnrollment->student?->quran_current_juz_id;
        $this->quran_test_type_id = QuranTestType::query()->where('code', 'partial')->value('id');
        $this->tested_on = now()->toDateString();
    }

    public function with(): array
    {
        return [
            'enrollmentRecord' => $this->currentEnrollment->fresh(['student.quranCurrentJuz', 'group.course', 'group.teacher']),
            'teachers' => $this->scopeTeachersQuery(
                Teacher::query()
                    ->whereIn('status', ['active', 'inactive'])
                    ->orderBy('first_name')
                    ->orderBy('last_name')
            )->get(),
            'juzs' => QuranJuz::query()->orderBy('juz_number')->get(),
            'testTypes' => QuranTestType::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'tests' => QuranTest::query()
                ->with(['juz', 'teacher', 'type'])
                ->where('enrollment_id', $this->currentEnrollment->id)
                ->latest('tested_on')
                ->latest('id')
                ->get(),
        ];
    }

    public function saveQuranTest(): void
    {
        $this->authorizePermission('quran-tests.record');

        $validated = $this->validate([
            'teacher_id' => ['required', 'exists:teachers,id'],
            'juz_id' => ['required', 'exists:quran_juzs,id'],
            'quran_test_type_id' => ['required', 'exists:quran_test_types,id'],
            'tested_on' => ['required', 'date'],
            'score' => ['nullable', 'numeric', 'between:0,100'],
            'status' => ['required', 'in:passed,failed,cancelled'],
            'notes' => ['nullable', 'string'],
        ]);

        $this->authorizeScopedTeacherAccess(Teacher::query()->findOrFail((int) $validated['teacher_id']));

        $testType = QuranTestType::query()->findOrFail($validated['quran_test_type_id']);
        $progression = app(QuranProgressionService::class)->validate($this->currentEnrollment, $validated['juz_id'], $testType);

        if ($progression && ! auth()->user()->can('quran-tests.override-progression')) {
            $this->addError('quran_test_type_id', $progression);

            return;
        }

        QuranTest::query()->create([
            'enrollment_id' => $this->currentEnrollment->id,
            'student_id' => $this->currentEnrollment->student_id,
            'teacher_id' => $validated['teacher_id'],
            'juz_id' => $validated['juz_id'],
            'quran_test_type_id' => $validated['quran_test_type_id'],
            'tested_on' => $validated['tested_on'],
            'score' => $validated['score'] !== '' ? $validated['score'] : null,
            'status' => $validated['status'],
            'attempt_no' => app(QuranProgressionService::class)->nextAttemptNumber(
                $this->currentEnrollment,
                $validated['juz_id'],
                $validated['quran_test_type_id'],
            ),
            'notes' => $validated['notes'] ?: null,
        ]);

        $this->score = '';
        $this->status = 'passed';
        $this->notes = '';
        $this->quran_test_type_id = QuranTestType::query()->where('code', 'partial')->value('id');

        session()->flash('status', __('workflow.quran_tests.messages.saved'));
    }
}; ?>

<div class="flex w-full flex-1 flex-col gap-6 p-6 lg:p-8">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <a href="{{ route('enrollments.index') }}" wire:navigate class="text-sm font-medium text-neutral-500 hover:text-neutral-900 dark:hover:text-white">{{ __('workflow.common.back_to_enrollments') }}</a>
            <flux:heading size="xl" class="mt-2">{{ __('workflow.quran_tests.title') }}</flux:heading>
            <flux:subheading>{{ __('workflow.quran_tests.subtitle') }}</flux:subheading>
        </div>

        <div class="rounded-2xl border border-neutral-200 bg-white px-5 py-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div class="text-sm font-medium">{{ $enrollmentRecord->student?->first_name }} {{ $enrollmentRecord->student?->last_name }}</div>
            <div class="mt-1 text-sm text-neutral-500">{{ $enrollmentRecord->group?->name ?: __('workflow.common.no_group') }} | {{ $enrollmentRecord->group?->course?->name ?: __('workflow.common.no_course') }}</div>
            <div class="mt-1 text-sm text-neutral-500">{{ __('workflow.common.labels.current_juz', ['number' => $enrollmentRecord->student?->quranCurrentJuz?->juz_number ?: __('workflow.common.not_available')]) }}</div>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
            {{ session('status') }}
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[28rem_minmax(0,1fr)]">
        <section class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            @if (auth()->user()->can('quran-tests.record'))
                <div class="mb-4">
                    <h2 class="text-lg font-semibold">{{ __('workflow.quran_tests.form.title') }}</h2>
                    <p class="text-sm text-neutral-500">{{ __('workflow.quran_tests.form.help') }}</p>
                </div>

                <form wire:submit="saveQuranTest" class="space-y-4">
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="quran-test-teacher" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.teacher') }}</label>
                            <select id="quran-test-teacher" wire:model="teacher_id" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                <option value="">{{ __('workflow.quran_tests.form.select_teacher') }}</option>
                                @foreach ($teachers as $teacher)
                                    <option value="{{ $teacher->id }}">{{ $teacher->first_name }} {{ $teacher->last_name }}</option>
                                @endforeach
                            </select>
                            @error('teacher_id')
                                <div class="mt-1 text-sm text-red-600">{{ $message }}</div>
                            @enderror
                        </div>

                        <div>
                            <label for="quran-test-date" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.tested_on') }}</label>
                            <input id="quran-test-date" wire:model="tested_on" type="date" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @error('tested_on')
                                <div class="mt-1 text-sm text-red-600">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="quran-test-juz" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.juz') }}</label>
                            <select id="quran-test-juz" wire:model="juz_id" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                <option value="">{{ __('workflow.quran_tests.form.select_juz') }}</option>
                                @foreach ($juzs as $juz)
                                    <option value="{{ $juz->id }}">{{ __('workflow.common.labels.juz_number', ['number' => $juz->juz_number]) }}</option>
                                @endforeach
                            </select>
                            @error('juz_id')
                                <div class="mt-1 text-sm text-red-600">{{ $message }}</div>
                            @enderror
                        </div>

                        <div>
                            <label for="quran-test-type" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.test_type') }}</label>
                            <select id="quran-test-type" wire:model="quran_test_type_id" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                <option value="">{{ __('workflow.quran_tests.form.select_type') }}</option>
                                @foreach ($testTypes as $type)
                                    <option value="{{ $type->id }}">{{ $type->name }}</option>
                                @endforeach
                            </select>
                            @error('quran_test_type_id')
                                <div class="mt-1 text-sm text-red-600">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="quran-test-score" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.score') }}</label>
                            <input id="quran-test-score" wire:model="score" type="number" min="0" max="100" step="0.01" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @error('score')
                                <div class="mt-1 text-sm text-red-600">{{ $message }}</div>
                            @enderror
                        </div>

                        <div>
                            <label for="quran-test-status" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.result_status') }}</label>
                            <select id="quran-test-status" wire:model="status" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                <option value="passed">{{ __('workflow.common.result_status.passed') }}</option>
                                <option value="failed">{{ __('workflow.common.result_status.failed') }}</option>
                                <option value="cancelled">{{ __('workflow.common.result_status.cancelled') }}</option>
                            </select>
                            @error('status')
                                <div class="mt-1 text-sm text-red-600">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div>
                        <label for="quran-test-notes" class="mb-1 block text-sm font-medium">{{ __('workflow.quran_tests.form.notes') }}</label>
                        <textarea id="quran-test-notes" wire:model="notes" rows="4" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"></textarea>
                    </div>

                    <button type="submit" class="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-neutral-900">
                        {{ __('workflow.common.actions.save_quran_test') }}
                    </button>
                </form>
            @else
                <div>
                    <h2 class="text-lg font-semibold">{{ __('workflow.quran_tests.read_only.title') }}</h2>
                    <p class="mt-2 text-sm text-neutral-500">{{ __('workflow.quran_tests.read_only.description') }}</p>
                </div>
            @endif
        </section>

        <section class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
            <div class="border-b border-neutral-200 px-5 py-4 text-sm font-medium dark:border-neutral-700">
                {{ __('workflow.quran_tests.table.title') }}
            </div>

            @if ($tests->isEmpty())
                <div class="px-5 py-10 text-sm text-neutral-500">{{ __('workflow.quran_tests.table.empty') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                        <thead class="bg-neutral-50 dark:bg-neutral-900/60">
                            <tr>
                                <th class="px-5 py-3 text-left font-medium">{{ __('workflow.quran_tests.table.headers.date') }}</th>
                                <th class="px-5 py-3 text-left font-medium">{{ __('workflow.quran_tests.table.headers.juz') }}</th>
                                <th class="px-5 py-3 text-left font-medium">{{ __('workflow.quran_tests.table.headers.type') }}</th>
                                <th class="px-5 py-3 text-left font-medium">{{ __('workflow.quran_tests.table.headers.attempt') }}</th>
                                <th class="px-5 py-3 text-left font-medium">{{ __('workflow.quran_tests.table.headers.score') }}</th>
                                <th class="px-5 py-3 text-left font-medium">{{ __('workflow.quran_tests.table.headers.status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                            @foreach ($tests as $test)
                                <tr>
                                    <td class="px-5 py-3">{{ $test->tested_on?->format('Y-m-d') }}</td>
                                    <td class="px-5 py-3">{{ __('workflow.common.labels.juz_number', ['number' => $test->juz?->juz_number]) }}</td>
                                    <td class="px-5 py-3">{{ $test->type?->name }}</td>
                                    <td class="px-5 py-3">{{ $test->attempt_no }}</td>
                                    <td class="px-5 py-3">{{ $test->score !== null ? $test->score : __('workflow.common.not_available') }}</td>
                                    <td class="px-5 py-3">{{ __('workflow.common.result_status.' . $test->status) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</div>
