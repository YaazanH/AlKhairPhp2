<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\AuthorizesTeacherAssignments;
use App\Models\Enrollment;
use App\Models\Student;
use App\Services\MemorizationService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use AuthorizesTeacherAssignments;

    public ?int $selectedStudentId = null;
    public string $from_page = '';
    public string $to_page = '';

    public function mount(): void
    {
        $this->authorizePermission('memorization.record');

        if (! $this->currentTeacher()) {
            session()->flash('error', __('workflow.memorization.quick_entry.errors.no_teacher'));
        }
    }

    public function with(): array
    {
        $studentOptions = $this->scopeStudentsQuery(
            Student::query()
                ->with(['parentProfile'])
                ->whereHas('enrollments', function (Builder $query) {
                    $this->scopeEnrollmentsQuery($query)->where('status', 'active');
                })
        )
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        return [
            'studentOptions' => $studentOptions,
            'currentTeacher' => $this->currentTeacher(),
        ];
    }

    public function save(): void
    {
        $this->authorizePermission('memorization.record');

        $teacher = $this->currentTeacher();

        if (! $teacher) {
            $this->addError('selectedStudentId', __('workflow.memorization.quick_entry.errors.no_teacher'));

            return;
        }

        $validated = $this->validate([
            'selectedStudentId' => ['required', 'exists:students,id'],
            'from_page' => ['required', 'integer', 'between:1,604'],
            'to_page' => ['required', 'integer', 'between:1,604', 'gte:from_page'],
        ], [], [
            'selectedStudentId' => __('workflow.memorization.quick_entry.form.student'),
            'from_page' => __('workflow.memorization.form.from_page'),
            'to_page' => __('workflow.memorization.form.to_page'),
        ]);

        $student = $this->scopeStudentsQuery(Student::query())->findOrFail((int) $validated['selectedStudentId']);
        $this->authorizeScopedStudentAccess($student);

        $enrollment = $this->scopeEnrollmentsQuery(
            Enrollment::query()
                ->with(['student', 'group.teacher'])
                ->where('student_id', $student->id)
                ->where('status', 'active')
                ->latest('enrolled_at')
                ->latest('id')
        )->first();

        if (! $enrollment) {
            $this->addError('selectedStudentId', __('workflow.memorization.errors.no_active_enrollment'));

            return;
        }

        $this->authorizeScopedEnrollmentAccess($enrollment);

        app(MemorizationService::class)->saveSession($enrollment, [
            'teacher_id' => $teacher->id,
            'recorded_on' => now()->toDateString(),
            'entry_type' => 'new',
            'from_page' => $validated['from_page'],
            'to_page' => $validated['to_page'],
            'notes' => null,
        ]);

        session()->flash('status', __('workflow.memorization.quick_entry.messages.saved'));

        $this->reset(['selectedStudentId', 'from_page', 'to_page']);
        $this->resetValidation();
    }

    protected function currentTeacher(): ?\App\Models\Teacher
    {
        return auth()->user()?->teacherProfile;
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.tracking') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('workflow.memorization.quick_entry.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('workflow.memorization.quick_entry.subtitle') }}</p>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    @if (session('error'))
        <div class="rounded-3xl border border-red-400/25 bg-red-500/12 px-4 py-3 text-sm text-red-100">{{ session('error') }}</div>
    @endif

    <section class="surface-panel mx-auto w-full max-w-3xl p-6 lg:p-8">
        <div class="mb-6 text-center">
            <div class="eyebrow">{{ __('workflow.memorization.quick_entry.card_eyebrow') }}</div>
            <h2 class="font-display mt-3 text-3xl text-white">{{ __('workflow.memorization.quick_entry.card_title') }}</h2>
            @if ($currentTeacher)
                <p class="mt-3 text-sm leading-7 text-neutral-300">
                    {{ __('workflow.memorization.quick_entry.teacher_context', ['name' => trim($currentTeacher->first_name.' '.$currentTeacher->last_name)]) }}
                </p>
            @endif
        </div>

        <form wire:submit="save" class="space-y-5">
            <div class="admin-form-field">
                <label for="quick-memorization-student">{{ __('workflow.memorization.quick_entry.form.student') }}</label>
                <select id="quick-memorization-student" wire:model="selectedStudentId">
                    <option value="">{{ __('workflow.memorization.workbench.form.select_student') }}</option>
                    @foreach ($studentOptions as $student)
                        <option value="{{ $student->id }}">
                            {{ $student->first_name }} {{ $student->last_name }}
                            @if ($student->student_number)
                                - #{{ $student->student_number }}
                            @endif
                            @if ($student->parentProfile?->father_name)
                                - {{ $student->parentProfile->father_name }}
                            @endif
                        </option>
                    @endforeach
                </select>
                @error('selectedStudentId')
                    <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                @enderror
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="admin-form-field">
                    <label for="quick-memorization-from">{{ __('workflow.memorization.form.from_page') }}</label>
                    <input id="quick-memorization-from" wire:model="from_page" type="number" min="1" max="604" inputmode="numeric">
                    @error('from_page')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>

                <div class="admin-form-field">
                    <label for="quick-memorization-to">{{ __('workflow.memorization.form.to_page') }}</label>
                    <input id="quick-memorization-to" wire:model="to_page" type="number" min="1" max="604" inputmode="numeric">
                    @error('to_page')
                        <div class="mt-1 text-sm text-red-400">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="soft-callout px-4 py-4 text-sm leading-6">
                {{ __('workflow.memorization.quick_entry.auto_context') }}
            </div>

            <div class="admin-action-cluster admin-action-cluster--end">
                <button type="submit" class="pill-link pill-link--accent">{{ __('workflow.memorization.quick_entry.form.save') }}</button>
            </div>
        </form>
    </section>
</div>
