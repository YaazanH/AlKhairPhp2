<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\SupportsCreateAndNew;
use App\Models\ActivityExpense;
use App\Models\ActivityPayment;
use App\Models\AppSetting;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\PaymentMethod;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use SupportsCreateAndNew;

    public string $invoice_prefix = '';
    public bool $showFinanceSettingsModal = false;

    public ?int $payment_method_editing_id = null;
    public string $payment_method_name = '';
    public string $payment_method_code = '';
    public bool $payment_method_is_active = true;
    public bool $showPaymentMethodModal = false;

    public ?int $expense_category_editing_id = null;
    public string $expense_category_name = '';
    public string $expense_category_code = '';
    public bool $expense_category_is_active = true;
    public bool $showExpenseCategoryModal = false;

    public function mount(): void
    {
        $this->authorizePermission('settings.manage');
        $this->loadFinanceSettings();
    }

    public function deleteExpenseCategory(int $expenseCategoryId): void
    {
        $this->authorizePermission('settings.manage');

        $expenseCategory = ExpenseCategory::query()->findOrFail($expenseCategoryId);

        if (ActivityExpense::query()->where('expense_category_id', $expenseCategory->id)->exists()) {
            $this->addError('expenseCategoryDelete', __('settings.finance.errors.expense_category_delete_linked'));

            return;
        }

        $expenseCategory->delete();

        if ($this->expense_category_editing_id === $expenseCategoryId) {
            $this->cancelExpenseCategory();
        }

        session()->flash('status', __('settings.finance.messages.expense_category_deleted'));
    }

    public function deletePaymentMethod(int $paymentMethodId): void
    {
        $this->authorizePermission('settings.manage');

        $paymentMethod = PaymentMethod::query()->findOrFail($paymentMethodId);

        if (Payment::query()->where('payment_method_id', $paymentMethod->id)->exists() || ActivityPayment::query()->where('payment_method_id', $paymentMethod->id)->exists()) {
            $this->addError('paymentMethodDelete', __('settings.finance.errors.payment_method_delete_linked'));

            return;
        }

        $paymentMethod->delete();

        if ($this->payment_method_editing_id === $paymentMethodId) {
            $this->cancelPaymentMethod();
        }

        session()->flash('status', __('settings.finance.messages.payment_method_deleted'));
    }

    public function openFinanceSettingsModal(): void
    {
        $this->authorizePermission('settings.manage');
        $this->showFinanceSettingsModal = true;
        $this->resetValidation();
    }

    public function closeFinanceSettingsModal(): void
    {
        $this->showFinanceSettingsModal = false;
        $this->resetValidation();
    }

    public function openPaymentMethodModal(): void
    {
        $this->authorizePermission('settings.manage');
        $this->cancelPaymentMethod();
        $this->showPaymentMethodModal = true;
    }

    public function closePaymentMethodModal(): void
    {
        $this->cancelPaymentMethod();
    }

    public function openExpenseCategoryModal(): void
    {
        $this->authorizePermission('settings.manage');
        $this->cancelExpenseCategory();
        $this->showExpenseCategoryModal = true;
    }

    public function closeExpenseCategoryModal(): void
    {
        $this->cancelExpenseCategory();
    }

    public function editExpenseCategory(int $expenseCategoryId): void
    {
        $this->authorizePermission('settings.manage');

        $expenseCategory = ExpenseCategory::query()->findOrFail($expenseCategoryId);

        $this->expense_category_editing_id = $expenseCategory->id;
        $this->expense_category_name = $expenseCategory->name;
        $this->expense_category_code = $expenseCategory->code;
        $this->expense_category_is_active = $expenseCategory->is_active;
        $this->showExpenseCategoryModal = true;

        $this->resetValidation();
    }

    public function editPaymentMethod(int $paymentMethodId): void
    {
        $this->authorizePermission('settings.manage');

        $paymentMethod = PaymentMethod::query()->findOrFail($paymentMethodId);

        $this->payment_method_editing_id = $paymentMethod->id;
        $this->payment_method_name = $paymentMethod->name;
        $this->payment_method_code = $paymentMethod->code;
        $this->payment_method_is_active = $paymentMethod->is_active;
        $this->showPaymentMethodModal = true;

        $this->resetValidation();
    }

    public function expenseCategoryRules(): array
    {
        return [
            'expense_category_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('expense_categories', 'code')->ignore($this->expense_category_editing_id),
            ],
            'expense_category_is_active' => ['boolean'],
            'expense_category_name' => ['required', 'string', 'max:255'],
        ];
    }

    public function paymentMethodRules(): array
    {
        return [
            'payment_method_code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('payment_methods', 'code')->ignore($this->payment_method_editing_id),
            ],
            'payment_method_is_active' => ['boolean'],
            'payment_method_name' => ['required', 'string', 'max:255'],
        ];
    }

    public function saveExpenseCategory(): void
    {
        $this->authorizePermission('settings.manage');

        $validated = $this->validate($this->expenseCategoryRules());

        ExpenseCategory::query()->updateOrCreate(
            ['id' => $this->expense_category_editing_id],
            [
                'code' => $validated['expense_category_code'],
                'is_active' => $validated['expense_category_is_active'],
                'name' => $validated['expense_category_name'],
            ],
        );

        session()->flash(
            'status',
            $this->expense_category_editing_id
                ? __('settings.finance.messages.expense_category_updated')
                : __('settings.finance.messages.expense_category_created'),
        );
        $this->cancelExpenseCategory();
    }

    public function saveFinanceSettings(): void
    {
        $this->authorizePermission('settings.manage');

        $validated = $this->validate([
            'invoice_prefix' => ['required', 'string', 'max:20'],
        ]);

        AppSetting::query()->updateOrCreate(
            ['group' => 'finance', 'key' => 'invoice_prefix'],
            ['type' => 'string', 'value' => $validated['invoice_prefix']],
        );

        session()->flash('status', __('settings.finance.messages.settings_saved'));
        $this->showFinanceSettingsModal = false;
    }

    public function savePaymentMethod(): void
    {
        $this->authorizePermission('settings.manage');

        $validated = $this->validate($this->paymentMethodRules());

        PaymentMethod::query()->updateOrCreate(
            ['id' => $this->payment_method_editing_id],
            [
                'code' => $validated['payment_method_code'],
                'is_active' => $validated['payment_method_is_active'],
                'name' => $validated['payment_method_name'],
            ],
        );

        session()->flash(
            'status',
            $this->payment_method_editing_id
                ? __('settings.finance.messages.payment_method_updated')
                : __('settings.finance.messages.payment_method_created'),
        );
        $this->cancelPaymentMethod();
    }

    public function with(): array
    {
        return [
            'expenseCategories' => ExpenseCategory::query()->orderBy('name')->get(),
            'paymentMethods' => PaymentMethod::query()->orderBy('name')->get(),
            'totals' => [
                'active_expense_categories' => ExpenseCategory::query()->where('is_active', true)->count(),
                'active_payment_methods' => PaymentMethod::query()->where('is_active', true)->count(),
                'payment_methods' => PaymentMethod::count(),
            ],
        ];
    }

    protected function cancelExpenseCategory(): void
    {
        $this->expense_category_editing_id = null;
        $this->expense_category_name = '';
        $this->expense_category_code = '';
        $this->expense_category_is_active = true;
        $this->showExpenseCategoryModal = false;
        $this->resetValidation();
    }

    protected function cancelPaymentMethod(): void
    {
        $this->payment_method_editing_id = null;
        $this->payment_method_name = '';
        $this->payment_method_code = '';
        $this->payment_method_is_active = true;
        $this->showPaymentMethodModal = false;
        $this->resetValidation();
    }

    protected function loadFinanceSettings(): void
    {
        $this->invoice_prefix = (string) (AppSetting::query()
            ->where('group', 'finance')
            ->where('key', 'invoice_prefix')
            ->value('value') ?: 'INV');
    }
}; ?>

<div class="page-stack settings-admin-page">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.settings') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('settings.finance.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('settings.finance.subtitle') }}</p>
    </section>

    <x-settings.admin-nav />

    @if (session('status'))
        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif

    <div class="grid gap-4 md:grid-cols-3">
        <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700"><div class="text-sm text-neutral-500">{{ __('settings.finance.stats.payment_methods') }}</div><div class="mt-2 text-3xl font-semibold">{{ number_format($totals['payment_methods']) }}</div></div>
        <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700"><div class="text-sm text-neutral-500">{{ __('settings.finance.stats.active_payment_methods') }}</div><div class="mt-2 text-3xl font-semibold">{{ number_format($totals['active_payment_methods']) }}</div></div>
        <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700"><div class="text-sm text-neutral-500">{{ __('settings.finance.stats.active_expense_categories') }}</div><div class="mt-2 text-3xl font-semibold">{{ number_format($totals['active_expense_categories']) }}</div></div>
    </div>

    <section class="surface-panel p-5 lg:p-6">
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">{{ __('settings.finance.title') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('settings.finance.subtitle') }}</p>
            </div>
            <div class="admin-toolbar__actions">
                <button type="button" wire:click="openFinanceSettingsModal" class="pill-link">{{ __('settings.finance.actions.save_settings') }}</button>
            </div>
        </div>
    </section>

    <div class="space-y-6">
        <section class="hidden">
            <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <div class="mb-4"><h2 class="text-lg font-semibold">{{ __('settings.finance.sections.invoice_numbering.title') }}</h2><p class="text-sm text-neutral-500">{{ __('settings.finance.sections.invoice_numbering.copy') }}</p></div>
                <form wire:submit="saveFinanceSettings" class="space-y-4">
                    <div><label class="mb-1 block text-sm font-medium">{{ __('settings.finance.fields.invoice_prefix') }}</label><input wire:model="invoice_prefix" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm uppercase dark:border-neutral-700 dark:bg-neutral-900">@error('invoice_prefix') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror</div>
                    <button type="submit" class="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-neutral-900">{{ __('settings.finance.actions.save_settings') }}</button>
                </form>
            </div>

            <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <div class="mb-4"><h2 class="text-lg font-semibold">{{ $payment_method_editing_id ? __('settings.finance.sections.payment_method.edit') : __('settings.finance.sections.payment_method.create') }}</h2><p class="text-sm text-neutral-500">{{ __('settings.finance.sections.payment_method.copy') }}</p></div>
                <form wire:submit="savePaymentMethod" class="space-y-4">
                    <div><label class="mb-1 block text-sm font-medium">{{ __('settings.finance.fields.name') }}</label><input wire:model="payment_method_name" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">@error('payment_method_name') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror</div>
                    <div><label class="mb-1 block text-sm font-medium">{{ __('settings.finance.fields.code') }}</label><input wire:model="payment_method_code" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">@error('payment_method_code') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror</div>
                    <label class="flex items-center gap-3 text-sm"><input wire:model="payment_method_is_active" type="checkbox" class="rounded border-neutral-300 text-neutral-900"><span>{{ __('settings.finance.fields.is_active') }}</span></label>
                    @error('paymentMethodDelete') <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</div> @enderror
                    <div class="flex gap-3"><button type="submit" class="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-neutral-900">{{ $payment_method_editing_id ? __('settings.finance.actions.update_method') : __('settings.finance.actions.create_method') }}</button>@if ($payment_method_editing_id)<button type="button" wire:click="cancelPaymentMethod" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm font-medium dark:border-neutral-700">{{ __('crud.common.actions.cancel') }}</button>@endif</div>
                </form>
            </div>

            <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <div class="mb-4"><h2 class="text-lg font-semibold">{{ $expense_category_editing_id ? __('settings.finance.sections.expense_category.edit') : __('settings.finance.sections.expense_category.create') }}</h2><p class="text-sm text-neutral-500">{{ __('settings.finance.sections.expense_category.copy') }}</p></div>
                <form wire:submit="saveExpenseCategory" class="space-y-4">
                    <div><label class="mb-1 block text-sm font-medium">{{ __('settings.finance.fields.name') }}</label><input wire:model="expense_category_name" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">@error('expense_category_name') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror</div>
                    <div><label class="mb-1 block text-sm font-medium">{{ __('settings.finance.fields.code') }}</label><input wire:model="expense_category_code" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">@error('expense_category_code') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror</div>
                    <label class="flex items-center gap-3 text-sm"><input wire:model="expense_category_is_active" type="checkbox" class="rounded border-neutral-300 text-neutral-900"><span>{{ __('settings.finance.fields.is_active') }}</span></label>
                    @error('expenseCategoryDelete') <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</div> @enderror
                    <div class="flex gap-3"><button type="submit" class="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-neutral-900">{{ $expense_category_editing_id ? __('settings.finance.actions.update_category') : __('settings.finance.actions.create_category') }}</button>@if ($expense_category_editing_id)<button type="button" wire:click="cancelExpenseCategory" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm font-medium dark:border-neutral-700">{{ __('crud.common.actions.cancel') }}</button>@endif</div>
                </form>
            </div>
        </section>

        <section class="space-y-6">
            <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4 dark:border-neutral-700">
                    <div>
                        <div class="text-sm font-medium">{{ __('settings.finance.sections.payment_method.table') }}</div>
                        <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">{{ __('settings.finance.sections.payment_method.copy') }}</p>
                    </div>
                    <button type="button" wire:click="openPaymentMethodModal" class="pill-link pill-link--accent">{{ __('settings.finance.actions.create_method') }}</button>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                        <thead class="bg-neutral-50 dark:bg-neutral-900/60"><tr><th class="px-5 py-3 text-left font-medium">{{ __('settings.finance.table.method') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.finance.table.code') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.finance.table.state') }}</th><th class="px-5 py-3 text-right font-medium">{{ __('settings.finance.table.actions') }}</th></tr></thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                            @foreach ($paymentMethods as $paymentMethod)
                                <tr>
                                    <td class="px-5 py-3 font-medium">{{ $paymentMethod->name }}</td>
                                    <td class="px-5 py-3">{{ $paymentMethod->code }}</td>
                                    <td class="px-5 py-3">{{ $paymentMethod->is_active ? __('settings.common.states.active') : __('settings.common.states.inactive') }}</td>
                                    <td class="px-5 py-3"><div class="flex justify-end gap-2"><button type="button" wire:click="editPaymentMethod({{ $paymentMethod->id }})" class="rounded-lg border border-neutral-300 px-3 py-1.5 dark:border-neutral-700">{{ __('crud.common.actions.edit') }}</button><button type="button" wire:click="deletePaymentMethod({{ $paymentMethod->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="rounded-lg border border-red-300 px-3 py-1.5 text-red-700 dark:border-red-800 dark:text-red-300">{{ __('crud.common.actions.delete') }}</button></div></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4 dark:border-neutral-700">
                    <div>
                        <div class="text-sm font-medium">{{ __('settings.finance.sections.expense_category.table') }}</div>
                        <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">{{ __('settings.finance.sections.expense_category.copy') }}</p>
                    </div>
                    <button type="button" wire:click="openExpenseCategoryModal" class="pill-link pill-link--accent">{{ __('settings.finance.actions.create_category') }}</button>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                        <thead class="bg-neutral-50 dark:bg-neutral-900/60"><tr><th class="px-5 py-3 text-left font-medium">{{ __('settings.finance.table.category') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.finance.table.code') }}</th><th class="px-5 py-3 text-left font-medium">{{ __('settings.finance.table.state') }}</th><th class="px-5 py-3 text-right font-medium">{{ __('settings.finance.table.actions') }}</th></tr></thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                            @foreach ($expenseCategories as $expenseCategory)
                                <tr>
                                    <td class="px-5 py-3 font-medium">{{ $expenseCategory->name }}</td>
                                    <td class="px-5 py-3">{{ $expenseCategory->code }}</td>
                                    <td class="px-5 py-3">{{ $expenseCategory->is_active ? __('settings.common.states.active') : __('settings.common.states.inactive') }}</td>
                                    <td class="px-5 py-3"><div class="flex justify-end gap-2"><button type="button" wire:click="editExpenseCategory({{ $expenseCategory->id }})" class="rounded-lg border border-neutral-300 px-3 py-1.5 dark:border-neutral-700">{{ __('crud.common.actions.edit') }}</button><button type="button" wire:click="deleteExpenseCategory({{ $expenseCategory->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="rounded-lg border border-red-300 px-3 py-1.5 text-red-700 dark:border-red-800 dark:text-red-300">{{ __('crud.common.actions.delete') }}</button></div></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>

    <x-admin.modal :show="$showFinanceSettingsModal" :title="__('settings.finance.sections.invoice_numbering.title')" :description="__('settings.finance.sections.invoice_numbering.copy')" close-method="closeFinanceSettingsModal" max-width="3xl">
        <form wire:submit="saveFinanceSettings" class="space-y-4">
            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('settings.finance.fields.invoice_prefix') }}</label>
                <input wire:model="invoice_prefix" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm uppercase dark:border-neutral-700 dark:bg-neutral-900">
                @error('invoice_prefix') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
            </div>
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closeFinanceSettingsModal" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
                <button type="submit" class="pill-link pill-link--accent">{{ __('settings.finance.actions.save_settings') }}</button>
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal :show="$showPaymentMethodModal" :title="$payment_method_editing_id ? __('settings.finance.sections.payment_method.edit') : __('settings.finance.sections.payment_method.create')" :description="__('settings.finance.sections.payment_method.copy')" close-method="closePaymentMethodModal" max-width="3xl">
        <form wire:submit="savePaymentMethod" class="space-y-4">
            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('settings.finance.fields.name') }}</label>
                <input wire:model="payment_method_name" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                @error('payment_method_name') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('settings.finance.fields.code') }}</label>
                <input wire:model="payment_method_code" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                @error('payment_method_code') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
            </div>
            <label class="flex items-center gap-3 text-sm"><input wire:model="payment_method_is_active" type="checkbox" class="rounded border-neutral-300 text-neutral-900"><span>{{ __('settings.finance.fields.is_active') }}</span></label>
            @error('paymentMethodDelete') <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</div> @enderror
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closePaymentMethodModal" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
                <button type="submit" class="pill-link pill-link--accent">{{ $payment_method_editing_id ? __('settings.finance.actions.update_method') : __('settings.finance.actions.create_method') }}</button>
                <x-admin.create-and-new-button :show="! $payment_method_editing_id" click="saveAndNew('savePaymentMethod', 'openPaymentMethodModal')" />
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal :show="$showExpenseCategoryModal" :title="$expense_category_editing_id ? __('settings.finance.sections.expense_category.edit') : __('settings.finance.sections.expense_category.create')" :description="__('settings.finance.sections.expense_category.copy')" close-method="closeExpenseCategoryModal" max-width="3xl">
        <form wire:submit="saveExpenseCategory" class="space-y-4">
            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('settings.finance.fields.name') }}</label>
                <input wire:model="expense_category_name" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                @error('expense_category_name') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('settings.finance.fields.code') }}</label>
                <input wire:model="expense_category_code" type="text" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                @error('expense_category_code') <div class="mt-1 text-sm text-red-600">{{ $message }}</div> @enderror
            </div>
            <label class="flex items-center gap-3 text-sm"><input wire:model="expense_category_is_active" type="checkbox" class="rounded border-neutral-300 text-neutral-900"><span>{{ __('settings.finance.fields.is_active') }}</span></label>
            @error('expenseCategoryDelete') <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{{ $message }}</div> @enderror
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closeExpenseCategoryModal" class="pill-link">{{ __('crud.common.actions.cancel') }}</button>
                <button type="submit" class="pill-link pill-link--accent">{{ $expense_category_editing_id ? __('settings.finance.actions.update_category') : __('settings.finance.actions.create_category') }}</button>
                <x-admin.create-and-new-button :show="! $expense_category_editing_id" click="saveAndNew('saveExpenseCategory', 'openExpenseCategoryModal')" />
            </div>
        </form>
    </x-admin.modal>
</div>
