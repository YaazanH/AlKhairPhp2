<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\Activity;
use App\Models\ActivityExpense;
use App\Models\ActivityPayment;
use App\Models\ActivityRegistration;
use App\Models\Enrollment;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Student;
use App\Services\ActivityAudienceService;
use App\Services\FinanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;

    public Activity $currentActivity;
    public ?int $editingRegistrationId = null;
    public ?int $registration_student_id = null;
    public ?int $registration_enrollment_id = null;
    public string $registration_fee_amount = '';
    public string $registration_status = 'registered';
    public string $registration_notes = '';
    public ?int $payment_registration_id = null;
    public ?int $payment_method_id = null;
    public string $payment_paid_at = '';
    public string $payment_amount = '';
    public string $payment_reference_no = '';
    public string $payment_notes = '';
    public ?int $editingExpenseId = null;
    public ?int $expense_category_id = null;
    public string $expense_amount = '';
    public string $expense_spent_on = '';
    public string $expense_description = '';

    public function mount(Activity $activity): void
    {
        $this->authorizePermission('activities.finance.view');
        $this->currentActivity = Activity::query()->with(['group.course', 'targetGroups.course'])->findOrFail($activity->id);
        $this->resetRegistrationForm();
        $this->payment_paid_at = now()->toDateString();
        $this->expense_spent_on = now()->toDateString();
    }

    public function with(): array
    {
        $audience = app(ActivityAudienceService::class);
        $activityRecord = $this->currentActivity->fresh(['group.course', 'targetGroups.course']);
        $eligibleStudentIds = $audience->eligibleStudentIds($activityRecord);

        return [
            'activityRecord' => $activityRecord,
            'students' => Student::query()->where('status', 'active')
                ->when($eligibleStudentIds !== [], fn ($query) => $query->whereIn('id', $eligibleStudentIds), fn ($query) => $query->whereRaw('1 = 0'))
                ->orderBy('first_name')->orderBy('last_name')->get(),
            'enrollments' => $audience->eligibleEnrollmentQuery($activityRecord)
                ->when($this->registration_student_id, fn ($query) => $query->where('student_id', $this->registration_student_id))
                ->latest('enrolled_at')->get(),
            'registrations' => ActivityRegistration::query()->with(['student', 'enrollment.group'])
                ->withSum(['payments as active_paid_total' => fn ($query) => $query->whereNull('voided_at')], 'amount')
                ->where('activity_id', $this->currentActivity->id)->latest('id')->get(),
            'paymentRegistrations' => ActivityRegistration::query()->with('student')
                ->where('activity_id', $this->currentActivity->id)->whereNotIn('status', ['cancelled', 'declined'])->orderBy('student_id')->get(),
            'payments' => ActivityPayment::query()->with(['registration.student', 'paymentMethod'])
                ->whereHas('registration', fn ($query) => $query->where('activity_id', $this->currentActivity->id))
                ->latest('paid_at')->latest('id')->get(),
            'expenses' => ActivityExpense::query()->with('category')->where('activity_id', $this->currentActivity->id)->latest('spent_on')->latest('id')->get(),
            'paymentMethods' => PaymentMethod::query()->where('is_active', true)->orderBy('name')->get(),
            'expenseCategories' => ExpenseCategory::query()->where('is_active', true)->orderBy('name')->get(),
        ];
    }

    public function updatedRegistrationStudentId($value): void
    {
        $this->registration_enrollment_id = app(ActivityAudienceService::class)
            ->resolveEnrollmentForStudent($this->currentActivity, (int) $value)?->id;
    }

    public function saveRegistration(): void
    {
        $this->authorizePermission('activities.registrations.manage');
        $audience = app(ActivityAudienceService::class);

        $validated = $this->validate([
            'registration_student_id' => ['required', 'exists:students,id', Rule::unique('activity_registrations', 'student_id')->where(fn ($query) => $query->where('activity_id', $this->currentActivity->id)->whereNull('deleted_at'))->ignore($this->editingRegistrationId)],
            'registration_enrollment_id' => ['nullable', 'exists:enrollments,id'],
            'registration_fee_amount' => ['required', 'numeric', 'min:0'],
            'registration_status' => ['required', 'in:registered,declined,cancelled,attended'],
            'registration_notes' => ['nullable', 'string'],
        ]);

        if ($validated['registration_enrollment_id']) {
            $enrollment = Enrollment::query()->findOrFail($validated['registration_enrollment_id']);
            if ($enrollment->student_id !== (int) $validated['registration_student_id']) {
                $this->addError('registration_enrollment_id', __('activities.finance.registrations.errors.wrong_student'));
                return;
            }
            if (! $audience->enrollmentMatches($this->currentActivity, $enrollment)) {
                $this->addError('registration_enrollment_id', __('activities.finance.registrations.errors.wrong_group'));
                return;
            }
        }

        ActivityRegistration::query()->updateOrCreate(['id' => $this->editingRegistrationId], [
            'activity_id' => $this->currentActivity->id,
            'student_id' => $validated['registration_student_id'],
            'enrollment_id' => $validated['registration_enrollment_id'] ?: null,
            'fee_amount' => $validated['registration_fee_amount'],
            'status' => $validated['registration_status'],
            'notes' => $validated['registration_notes'] ?: null,
        ]);

        app(FinanceService::class)->syncActivityTotals($this->currentActivity->fresh());
        session()->flash('status', $this->editingRegistrationId ? __('activities.finance.registrations.messages.updated') : __('activities.finance.registrations.messages.created'));
        $this->resetRegistrationForm();
    }

    public function editRegistration(int $registrationId): void
    {
        $this->authorizePermission('activities.registrations.manage');
        $registration = ActivityRegistration::query()->where('activity_id', $this->currentActivity->id)->findOrFail($registrationId);
        $this->editingRegistrationId = $registration->id;
        $this->registration_student_id = $registration->student_id;
        $this->registration_enrollment_id = $registration->enrollment_id;
        $this->registration_fee_amount = number_format((float) $registration->fee_amount, 2, '.', '');
        $this->registration_status = $registration->status;
        $this->registration_notes = $registration->notes ?? '';
        $this->resetErrorBag();
    }

    public function deleteRegistration(int $registrationId): void
    {
        $this->authorizePermission('activities.registrations.manage');
        $registration = ActivityRegistration::query()->where('activity_id', $this->currentActivity->id)->findOrFail($registrationId);
        if ($registration->payments()->whereNull('voided_at')->exists()) {
            $this->addError('registration_delete', __('activities.finance.registrations.errors.delete_linked'));
            return;
        }
        $registration->delete();
        if ($this->editingRegistrationId === $registrationId) {
            $this->resetRegistrationForm();
        }
        app(FinanceService::class)->syncActivityTotals($this->currentActivity->fresh());
        session()->flash('status', __('activities.finance.registrations.messages.deleted'));
    }

    public function savePayment(): void
    {
        $this->authorizePermission('activities.payments.manage');
        $validated = $this->validate([
            'payment_registration_id' => ['required', 'exists:activity_registrations,id'],
            'payment_method_id' => ['required', 'exists:payment_methods,id'],
            'payment_paid_at' => ['required', 'date'],
            'payment_amount' => ['required', 'numeric', 'gt:0'],
            'payment_reference_no' => ['nullable', 'string', 'max:255'],
            'payment_notes' => ['nullable', 'string'],
        ]);
        $registration = ActivityRegistration::query()->where('activity_id', $this->currentActivity->id)->findOrFail($validated['payment_registration_id']);
        $payment = ActivityPayment::query()->create([
            'activity_registration_id' => $registration->id,
            'payment_method_id' => $validated['payment_method_id'],
            'paid_at' => $validated['payment_paid_at'],
            'amount' => $validated['payment_amount'],
            'reference_no' => $validated['payment_reference_no'] ?: null,
            'entered_by' => auth()->id(),
            'notes' => $validated['payment_notes'] ?: null,
        ]);
        app(FinanceService::class)->recordActivityPayment($payment);
        app(FinanceService::class)->syncActivityTotals($this->currentActivity->fresh());
        $this->payment_registration_id = null;
        $this->payment_method_id = null;
        $this->payment_paid_at = now()->toDateString();
        $this->payment_amount = '';
        $this->payment_reference_no = '';
        $this->payment_notes = '';
        session()->flash('status', __('activities.finance.payments.messages.created'));
    }

    public function voidPayment(int $paymentId): void
    {
        $this->authorizePermission('activities.payments.manage');
        $payment = ActivityPayment::query()->whereHas('registration', fn ($query) => $query->where('activity_id', $this->currentActivity->id))->findOrFail($paymentId);
        if ($payment->voided_at) {
            return;
        }

        DB::transaction(function () use ($payment): void {
            app(FinanceService::class)->reverseSourceTransactions(ActivityPayment::class, $payment->id, auth()->user(), __('activities.finance.payments.void_reason'));
            $payment->update(['voided_at' => now(), 'voided_by' => auth()->id(), 'void_reason' => __('activities.finance.payments.void_reason')]);
            app(FinanceService::class)->syncActivityTotals($this->currentActivity->fresh());
        });

        session()->flash('status', __('activities.finance.payments.messages.voided'));
    }

    public function saveExpense(): void
    {
        $this->authorizePermission('activities.expenses.manage');
        $validated = $this->validate([
            'expense_category_id' => ['required', 'exists:expense_categories,id'],
            'expense_amount' => ['required', 'numeric', 'gt:0'],
            'expense_spent_on' => ['required', 'date'],
            'expense_description' => ['required', 'string', 'max:255'],
        ]);
        $previousAmount = $this->editingExpenseId
            ? (float) ActivityExpense::query()->where('activity_id', $this->currentActivity->id)->whereKey($this->editingExpenseId)->value('amount')
            : null;

        $expense = ActivityExpense::query()->updateOrCreate(['id' => $this->editingExpenseId], [
            'activity_id' => $this->currentActivity->id,
            'expense_category_id' => $validated['expense_category_id'],
            'amount' => $validated['expense_amount'],
            'spent_on' => $validated['expense_spent_on'],
            'description' => $validated['expense_description'],
            'entered_by' => auth()->id(),
        ]);
        app(FinanceService::class)->recordActivityExpense($expense, $previousAmount);
        app(FinanceService::class)->syncActivityTotals($this->currentActivity->fresh());
        session()->flash('status', $this->editingExpenseId ? __('activities.finance.expenses.messages.updated') : __('activities.finance.expenses.messages.created'));
        $this->resetExpenseForm();
    }

    public function editExpense(int $expenseId): void
    {
        $this->authorizePermission('activities.expenses.manage');
        $expense = ActivityExpense::query()->where('activity_id', $this->currentActivity->id)->findOrFail($expenseId);
        $this->editingExpenseId = $expense->id;
        $this->expense_category_id = $expense->expense_category_id;
        $this->expense_amount = number_format((float) $expense->amount, 2, '.', '');
        $this->expense_spent_on = $expense->spent_on?->format('Y-m-d') ?? '';
        $this->expense_description = $expense->description;
        $this->resetErrorBag();
    }

    public function deleteExpense(int $expenseId): void
    {
        $this->authorizePermission('activities.expenses.manage');
        $expense = ActivityExpense::query()->where('activity_id', $this->currentActivity->id)->findOrFail($expenseId);
        app(FinanceService::class)->reverseSourceTransactions(ActivityExpense::class, $expense->id, auth()->user(), __('activities.finance.expenses.messages.deleted'));
        $expense->delete();
        if ($this->editingExpenseId === $expenseId) {
            $this->resetExpenseForm();
        }
        app(FinanceService::class)->syncActivityTotals($this->currentActivity->fresh());
        session()->flash('status', __('activities.finance.expenses.messages.deleted'));
    }

    public function cancelRegistration(): void { $this->resetRegistrationForm(); }
    public function cancelExpense(): void { $this->resetExpenseForm(); }

    protected function defaultFee(): string
    {
        return $this->currentActivity->fee_amount !== null ? number_format((float) $this->currentActivity->fee_amount, 2, '.', '') : '';
    }

    protected function resetExpenseForm(): void
    {
        $this->editingExpenseId = null;
        $this->expense_category_id = null;
        $this->expense_amount = '';
        $this->expense_spent_on = now()->toDateString();
        $this->expense_description = '';
        $this->resetValidation();
    }

    protected function resetRegistrationForm(): void
    {
        $this->editingRegistrationId = null;
        $this->registration_student_id = null;
        $this->registration_enrollment_id = null;
        $this->registration_fee_amount = $this->defaultFee();
        $this->registration_status = 'registered';
        $this->registration_notes = '';
        $this->resetValidation();
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <a href="{{ route('activities.index') }}" wire:navigate class="text-sm font-medium text-neutral-200/80 hover:text-white">{{ __('activities.finance.back') }}</a>
                <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('activities.finance.heading') }}</h1>
                <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('activities.finance.subheading') }}</p>
            </div>
            <div class="surface-panel px-5 py-4">
                <div class="text-sm font-semibold text-white">{{ $activityRecord->title }}</div>
                <div class="mt-1 text-sm text-neutral-400">{{ $activityRecord->activity_date?->format('Y-m-d') }}</div>
                <div class="mt-1 text-xs text-neutral-500">
                    {{ __('activities.common.audience.'.$activityRecord->audience_scope) }}
                    @if ($activityRecord->audience_scope === 'multiple_groups')
                        | {{ $activityRecord->targetGroups->pluck('name')->implode(', ') }}
                    @elseif ($activityRecord->audience_scope === 'single_group')
                        | {{ $activityRecord->group?->name ?: ($activityRecord->targetGroups->first()?->name ?: __('activities.common.audience.unassigned')) }}
                    @endif
                </div>
            </div>
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="admin-kpi-grid">
        <article class="stat-card"><div class="kpi-label">{{ __('activities.finance.summary.expected') }}</div><div class="metric-value mt-3">{{ number_format((float) $activityRecord->expected_revenue_cached, 2) }}</div></article>
        <article class="stat-card"><div class="kpi-label">{{ __('activities.finance.summary.collected') }}</div><div class="metric-value mt-3">{{ number_format((float) $activityRecord->collected_revenue_cached, 2) }}</div></article>
        <article class="stat-card"><div class="kpi-label">{{ __('activities.finance.summary.expenses') }}</div><div class="metric-value mt-3">{{ number_format((float) $activityRecord->expense_total_cached, 2) }}</div></article>
        <article class="stat-card"><div class="kpi-label">{{ __('activities.finance.summary.net') }}</div><div class="metric-value mt-3">{{ number_format((float) $activityRecord->collected_revenue_cached - (float) $activityRecord->expense_total_cached, 2) }}</div></article>
    </section>

    <div class="grid gap-6 xl:grid-cols-[23rem_23rem_minmax(0,1fr)]">
        <section class="space-y-6">
            <div class="surface-panel p-5 lg:p-6">
                <div class="mb-4 text-lg font-semibold text-white">{{ $editingRegistrationId ? __('activities.finance.registrations.edit_title') : __('activities.finance.registrations.create_title') }}</div>
                <form wire:submit="saveRegistration" class="space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('activities.finance.registrations.fields.student') }}</label>
                        <select wire:model.live="registration_student_id" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            <option value="">{{ __('activities.finance.registrations.placeholders.student') }}</option>
                            @foreach ($students as $student)
                                <option value="{{ $student->id }}">{{ $student->first_name }} {{ $student->last_name }}</option>
                            @endforeach
                        </select>
                        @error('registration_student_id') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('activities.finance.registrations.fields.enrollment') }}</label>
                        <select wire:model="registration_enrollment_id" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            <option value="">{{ __('activities.finance.registrations.placeholders.enrollment') }}</option>
                            @foreach ($enrollments as $enrollment)
                                <option value="{{ $enrollment->id }}">{{ $enrollment->student?->first_name }} {{ $enrollment->student?->last_name }} | {{ $enrollment->group?->name }}</option>
                            @endforeach
                        </select>
                        @error('registration_enrollment_id') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('activities.finance.registrations.fields.fee') }}</label>
                            <input wire:model="registration_fee_amount" type="number" min="0" step="0.01" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @error('registration_fee_amount') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('activities.finance.registrations.fields.status') }}</label>
                            <select wire:model="registration_status" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                <option value="registered">{{ __('activities.common.states.registered') }}</option>
                                <option value="declined">{{ __('activities.common.states.declined') }}</option>
                                <option value="attended">{{ __('activities.common.states.attended') }}</option>
                                <option value="cancelled">{{ __('activities.common.states.cancelled') }}</option>
                            </select>
                            @error('registration_status') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('activities.finance.registrations.fields.notes') }}</label>
                        <textarea wire:model="registration_notes" rows="3" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"></textarea>
                    </div>

                    @error('registration_delete') <div class="rounded-2xl border border-red-500/25 bg-red-500/10 px-3 py-2 text-sm text-red-200">{{ $message }}</div> @enderror

                    <div class="flex gap-3">
                        <button type="submit" class="pill-link pill-link--accent">{{ $editingRegistrationId ? __('activities.common.actions.update') : __('activities.common.actions.save') }}</button>
                        @if ($editingRegistrationId)
                            <button type="button" wire:click="cancelRegistration" class="pill-link">{{ __('activities.common.actions.cancel') }}</button>
                        @endif
                    </div>
                </form>
            </div>

            <div class="surface-panel p-5 lg:p-6">
                <div class="mb-4 text-lg font-semibold text-white">{{ __('activities.finance.payments.title') }}</div>
                <form wire:submit="savePayment" class="space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('activities.finance.payments.fields.registration') }}</label>
                        <select wire:model="payment_registration_id" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            <option value="">{{ __('activities.finance.payments.placeholders.registration') }}</option>
                            @foreach ($paymentRegistrations as $registration)
                                <option value="{{ $registration->id }}">{{ $registration->student?->first_name }} {{ $registration->student?->last_name }} | {{ number_format((float) $registration->fee_amount, 2) }}</option>
                            @endforeach
                        </select>
                        @error('payment_registration_id') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('activities.finance.payments.fields.method') }}</label>
                            <select wire:model="payment_method_id" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                                <option value="">{{ __('activities.finance.payments.placeholders.method') }}</option>
                                @foreach ($paymentMethods as $method)
                                    <option value="{{ $method->id }}">{{ $method->name }}</option>
                                @endforeach
                            </select>
                            @error('payment_method_id') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('activities.finance.payments.fields.date') }}</label>
                            <input wire:model="payment_paid_at" type="date" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @error('payment_paid_at') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('activities.finance.payments.fields.amount') }}</label>
                            <input wire:model="payment_amount" type="number" min="0" step="0.01" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @error('payment_amount') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('activities.finance.payments.fields.reference') }}</label>
                            <input wire:model="payment_reference_no" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @error('payment_reference_no') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('activities.finance.payments.fields.notes') }}</label>
                        <textarea wire:model="payment_notes" rows="3" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"></textarea>
                    </div>
                    <button type="submit" class="pill-link pill-link--accent">{{ __('activities.finance.payments.save') }}</button>
                </form>
            </div>

            <div class="surface-panel p-5 lg:p-6">
                <div class="mb-4 text-lg font-semibold text-white">{{ $editingExpenseId ? __('activities.finance.expenses.edit_title') : __('activities.finance.expenses.create_title') }}</div>
                <form wire:submit="saveExpense" class="space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('activities.finance.expenses.fields.category') }}</label>
                        <select wire:model="expense_category_id" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            <option value="">{{ __('activities.finance.expenses.placeholders.category') }}</option>
                            @foreach ($expenseCategories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                        @error('expense_category_id') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('activities.finance.expenses.fields.amount') }}</label>
                            <input wire:model="expense_amount" type="number" min="0" step="0.01" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @error('expense_amount') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ __('activities.finance.expenses.fields.spent_on') }}</label>
                            <input wire:model="expense_spent_on" type="date" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            @error('expense_spent_on') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('activities.finance.expenses.fields.description') }}</label>
                        <input wire:model="expense_description" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                        @error('expense_description') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
                    </div>
                    <div class="flex gap-3">
                        <button type="submit" class="pill-link pill-link--accent">{{ $editingExpenseId ? __('activities.common.actions.update') : __('activities.common.actions.save') }}</button>
                        @if ($editingExpenseId)
                            <button type="button" wire:click="cancelExpense" class="pill-link">{{ __('activities.common.actions.cancel') }}</button>
                        @endif
                    </div>
                </form>
            </div>
        </section>

        <section class="space-y-6 xl:col-span-2">
            <div class="surface-table">
                <div class="admin-grid-meta">
                    <div>
                        <div class="admin-grid-meta__title">{{ __('activities.finance.registrations.table_title') }}</div>
                        <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($registrations->count())]) }}</div>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="text-sm">
                        <thead><tr><th class="px-5 py-3 text-left font-medium">{{ __('activities.finance.registrations.headers.student') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('activities.finance.registrations.headers.enrollment') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('activities.finance.registrations.headers.fee') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('activities.finance.registrations.headers.paid') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('activities.finance.registrations.headers.status') }}</th><th class="px-5 py-3 text-right font-medium">{{ __('activities.finance.registrations.headers.actions') }}</th></tr></thead>
                        <tbody class="divide-y divide-white/6">
                            @forelse ($registrations as $registration)
                                <tr>
                                    <td class="px-5 py-3">
                                        <div class="student-inline">
                                            <x-student-avatar :student="$registration->student" size="sm" />
                                            <div class="student-inline__body">
                                                <div class="student-inline__name">{{ $registration->student?->first_name }} {{ $registration->student?->last_name }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-3">{{ $registration->enrollment?->group?->name ?: '-' }}</td>
                                    <td class="px-5 py-3">{{ number_format((float) $registration->fee_amount, 2) }}</td>
                                    <td class="px-5 py-3">{{ number_format((float) ($registration->active_paid_total ?? 0), 2) }}</td>
                                    <td class="px-5 py-3"><span class="status-chip status-chip--slate">{{ __('activities.common.states.'.$registration->status) }}</span></td>
                                    <td class="px-5 py-3"><div class="admin-action-cluster admin-action-cluster--end"><button type="button" wire:click="editRegistration({{ $registration->id }})" class="pill-link pill-link--compact">{{ __('activities.common.actions.edit') }}</button><button type="button" wire:click="deleteRegistration({{ $registration->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">{{ __('activities.common.actions.delete') }}</button></div></td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-5 py-10 text-center text-sm text-neutral-500">{{ __('activities.finance.registrations.empty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="surface-table">
                <div class="admin-grid-meta">
                    <div>
                        <div class="admin-grid-meta__title">{{ __('activities.finance.payments.table_title') }}</div>
                        <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($payments->count())]) }}</div>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="text-sm">
                        <thead><tr><th class="px-5 py-3 text-left font-medium">{{ __('activities.finance.payments.headers.date') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('activities.finance.payments.headers.student') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('activities.finance.payments.headers.method') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('activities.finance.payments.headers.amount') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('activities.finance.payments.headers.state') }}</th><th class="px-5 py-3 text-right font-medium">{{ __('activities.finance.payments.headers.actions') }}</th></tr></thead>
                        <tbody class="divide-y divide-white/6">
                            @forelse ($payments as $payment)
                                <tr class="{{ $payment->voided_at ? 'opacity-60' : '' }}">
                                    <td class="px-5 py-3">{{ $payment->paid_at?->format('Y-m-d') }}</td>
                                    <td class="px-5 py-3">
                                        <div class="student-inline">
                                            <x-student-avatar :student="$payment->registration?->student" size="sm" />
                                            <div class="student-inline__body">
                                                <div class="student-inline__name">{{ $payment->registration?->student?->first_name }} {{ $payment->registration?->student?->last_name }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-3">{{ $payment->paymentMethod?->name ?: '-' }}</td>
                                    <td class="px-5 py-3">{{ number_format((float) $payment->amount, 2) }}</td>
                                    <td class="px-5 py-3"><span class="status-chip {{ $payment->voided_at ? 'status-chip--rose' : 'status-chip--emerald' }}">{{ __('activities.common.states.'.($payment->voided_at ? 'voided' : 'active')) }}</span></td>
                                    <td class="px-5 py-3"><div class="admin-action-cluster admin-action-cluster--end">@if (! $payment->voided_at)<button type="button" wire:click="voidPayment({{ $payment->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">{{ __('activities.common.actions.void') }}</button>@endif</div></td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-5 py-10 text-center text-sm text-neutral-500">{{ __('activities.finance.payments.empty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="surface-table">
                <div class="admin-grid-meta">
                    <div>
                        <div class="admin-grid-meta__title">{{ __('activities.finance.expenses.table_title') }}</div>
                        <div class="admin-grid-meta__summary">{{ __('crud.common.badges.in_view', ['count' => number_format($expenses->count())]) }}</div>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="text-sm">
                        <thead><tr><th class="px-5 py-3 text-left font-medium">{{ __('activities.finance.expenses.headers.date') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('activities.finance.expenses.headers.category') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('activities.finance.expenses.headers.description') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('activities.finance.expenses.headers.amount') }}</th><th class="px-5 py-3 text-right font-medium">{{ __('activities.finance.expenses.headers.actions') }}</th></tr></thead>
                        <tbody class="divide-y divide-white/6">
                            @forelse ($expenses as $expense)
                                <tr>
                                    <td class="px-5 py-3">{{ $expense->spent_on?->format('Y-m-d') }}</td>
                                    <td class="px-5 py-3">{{ $expense->category?->name ?: '-' }}</td>
                                    <td class="px-5 py-3">{{ $expense->description }}</td>
                                    <td class="px-5 py-3">{{ number_format((float) $expense->amount, 2) }}</td>
                                    <td class="px-5 py-3"><div class="admin-action-cluster admin-action-cluster--end"><button type="button" wire:click="editExpense({{ $expense->id }})" class="pill-link pill-link--compact">{{ __('activities.common.actions.edit') }}</button><button type="button" wire:click="deleteExpense({{ $expense->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">{{ __('activities.common.actions.delete') }}</button></div></td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-5 py-10 text-center text-sm text-neutral-500">{{ __('activities.finance.expenses.empty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</div>
