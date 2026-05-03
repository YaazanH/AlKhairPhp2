<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\ActivityExpense;
use App\Models\ActivityPayment;
use App\Models\AppSetting;
use App\Models\ExpenseCategory;
use App\Models\FinanceCashBox;
use App\Models\FinanceCategory;
use App\Models\FinanceCurrency;
use App\Models\FinanceTransaction;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\FinanceService;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;

    public string $invoice_prefix = '';
    public string $request_terms = '';

    public ?int $currency_editing_id = null;
    public string $currency_code = '';
    public string $currency_name = '';
    public string $currency_symbol = '';
    public string $currency_rate_input = '1';
    public bool $currency_is_active = true;
    public bool $currency_is_local = false;
    public bool $currency_is_base = false;

    public ?int $cash_box_editing_id = null;
    public string $cash_box_name = '';
    public string $cash_box_code = '';
    public bool $cash_box_is_active = true;
    public string $cash_box_notes = '';
    public array $cash_box_user_ids = [];

    public ?int $finance_category_editing_id = null;
    public string $finance_category_name = '';
    public string $finance_category_code = '';
    public string $finance_category_type = 'expense';
    public bool $finance_category_is_active = true;

    public ?int $payment_method_editing_id = null;
    public string $payment_method_name = '';
    public string $payment_method_code = '';
    public bool $payment_method_is_active = true;

    public ?int $expense_category_editing_id = null;
    public string $expense_category_name = '';
    public string $expense_category_code = '';
    public bool $expense_category_is_active = true;

    public function mount(): void
    {
        $this->authorizePermission('finance.settings.manage');
        $this->loadFinanceSettings();
    }

    public function deleteCashBox(int $cashBoxId): void
    {
        $this->authorizePermission('finance.cash-box.manage');

        $cashBox = FinanceCashBox::query()->findOrFail($cashBoxId);

        if (FinanceTransaction::query()->where('cash_box_id', $cashBox->id)->exists()) {
            $this->addError('cashBoxDelete', 'This cash box cannot be deleted while ledger transactions use it.');

            return;
        }

        $cashBox->assignedUsers()->detach();
        $cashBox->delete();
        $this->cancelCashBox();
        session()->flash('status', 'Cash box deleted.');
    }

    public function deleteCurrency(int $currencyId): void
    {
        $this->authorizePermission('finance.currencies.manage');

        $currency = FinanceCurrency::query()->findOrFail($currencyId);

        if ($currency->is_local || $currency->is_base) {
            $this->addError('currencyDelete', 'Local and base currencies cannot be deleted.');

            return;
        }

        if (app(FinanceService::class)->currencyIsUsed($currency)) {
            $this->addError('currencyDelete', 'This currency is used and cannot be deleted.');

            return;
        }

        $currency->delete();
        $this->cancelCurrency();
        session()->flash('status', 'Currency deleted.');
    }

    public function deleteExpenseCategory(int $expenseCategoryId): void
    {
        $this->authorizePermission('finance.categories.manage');

        $expenseCategory = ExpenseCategory::query()->findOrFail($expenseCategoryId);

        if (ActivityExpense::query()->where('expense_category_id', $expenseCategory->id)->exists()) {
            $this->addError('expenseCategoryDelete', __('settings.finance.errors.expense_category_delete_linked'));

            return;
        }

        $expenseCategory->delete();
        $this->cancelExpenseCategory();
        session()->flash('status', __('settings.finance.messages.expense_category_deleted'));
    }

    public function deleteFinanceCategory(int $categoryId): void
    {
        $this->authorizePermission('finance.categories.manage');

        $category = FinanceCategory::query()->findOrFail($categoryId);

        if ($category->transactions()->exists() || $category->requests()->exists()) {
            $this->addError('financeCategoryDelete', 'This category is used and cannot be deleted.');

            return;
        }

        $category->delete();
        $this->cancelFinanceCategory();
        session()->flash('status', 'Finance category deleted.');
    }

    public function deletePaymentMethod(int $paymentMethodId): void
    {
        $this->authorizePermission('finance.settings.manage');

        $paymentMethod = PaymentMethod::query()->findOrFail($paymentMethodId);

        if (Payment::query()->where('payment_method_id', $paymentMethod->id)->exists() || ActivityPayment::query()->where('payment_method_id', $paymentMethod->id)->exists()) {
            $this->addError('paymentMethodDelete', __('settings.finance.errors.payment_method_delete_linked'));

            return;
        }

        $paymentMethod->delete();
        $this->cancelPaymentMethod();
        session()->flash('status', __('settings.finance.messages.payment_method_deleted'));
    }

    public function editCashBox(int $cashBoxId): void
    {
        $this->authorizePermission('finance.cash-box.manage');

        $cashBox = FinanceCashBox::query()->with('assignedUsers')->findOrFail($cashBoxId);
        $this->cash_box_editing_id = $cashBox->id;
        $this->cash_box_name = $cashBox->name;
        $this->cash_box_code = $cashBox->code;
        $this->cash_box_is_active = $cashBox->is_active;
        $this->cash_box_notes = $cashBox->notes ?? '';
        $this->cash_box_user_ids = $cashBox->assignedUsers->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->resetValidation();
    }

    public function editCurrency(int $currencyId): void
    {
        $this->authorizePermission('finance.currencies.manage');

        $currency = FinanceCurrency::query()->findOrFail($currencyId);
        $this->currency_editing_id = $currency->id;
        $this->currency_code = $currency->code;
        $this->currency_name = $currency->name;
        $this->currency_symbol = $currency->symbol ?? '';
        $this->currency_is_active = $currency->is_active;
        $this->currency_is_local = $currency->is_local;
        $this->currency_is_base = $currency->is_base;
        $this->currency_rate_input = $currency->is_local && (float) $currency->rate_to_base > 0
            ? number_format(1 / (float) $currency->rate_to_base, 4, '.', '')
            : number_format((float) $currency->rate_to_base, 4, '.', '');
        $this->resetValidation();
    }

    public function editExpenseCategory(int $expenseCategoryId): void
    {
        $this->authorizePermission('finance.categories.manage');

        $expenseCategory = ExpenseCategory::query()->findOrFail($expenseCategoryId);
        $this->expense_category_editing_id = $expenseCategory->id;
        $this->expense_category_name = $expenseCategory->name;
        $this->expense_category_code = $expenseCategory->code;
        $this->expense_category_is_active = $expenseCategory->is_active;
        $this->resetValidation();
    }

    public function editFinanceCategory(int $categoryId): void
    {
        $this->authorizePermission('finance.categories.manage');

        $category = FinanceCategory::query()->findOrFail($categoryId);
        $this->finance_category_editing_id = $category->id;
        $this->finance_category_name = $category->name;
        $this->finance_category_code = $category->code;
        $this->finance_category_type = $category->type;
        $this->finance_category_is_active = $category->is_active;
        $this->resetValidation();
    }

    public function editPaymentMethod(int $paymentMethodId): void
    {
        $this->authorizePermission('finance.settings.manage');

        $paymentMethod = PaymentMethod::query()->findOrFail($paymentMethodId);
        $this->payment_method_editing_id = $paymentMethod->id;
        $this->payment_method_name = $paymentMethod->name;
        $this->payment_method_code = $paymentMethod->code;
        $this->payment_method_is_active = $paymentMethod->is_active;
        $this->resetValidation();
    }

    public function saveCashBox(): void
    {
        $this->authorizePermission('finance.cash-box.manage');

        $validated = $this->validate([
            'cash_box_code' => ['required', 'string', 'max:50', Rule::unique('finance_cash_boxes', 'code')->ignore($this->cash_box_editing_id)],
            'cash_box_is_active' => ['boolean'],
            'cash_box_name' => ['required', 'string', 'max:255'],
            'cash_box_notes' => ['nullable', 'string'],
            'cash_box_user_ids' => ['array'],
            'cash_box_user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        if (! $validated['cash_box_is_active'] && $this->cash_box_editing_id && FinanceTransaction::query()
            ->where('cash_box_id', $this->cash_box_editing_id)
            ->selectRaw('currency_id, SUM(signed_amount) as balance')
            ->groupBy('currency_id')
            ->havingRaw('ABS(SUM(signed_amount)) > 0.009')
            ->exists()) {
            $this->addError('cash_box_is_active', 'A cash box with a non-zero balance cannot be deactivated.');

            return;
        }

        $cashBox = FinanceCashBox::query()->updateOrCreate(
            ['id' => $this->cash_box_editing_id],
            [
                'code' => $validated['cash_box_code'],
                'is_active' => $validated['cash_box_is_active'],
                'name' => $validated['cash_box_name'],
                'notes' => $validated['cash_box_notes'] ?: null,
            ],
        );

        $cashBox->assignedUsers()->sync(array_map('intval', $validated['cash_box_user_ids']));
        $this->cancelCashBox();
        session()->flash('status', 'Cash box saved.');
    }

    public function saveCurrency(): void
    {
        $this->authorizePermission('finance.currencies.manage');

        $validated = $this->validate([
            'currency_code' => ['required', 'string', 'max:10', Rule::unique('finance_currencies', 'code')->ignore($this->currency_editing_id)],
            'currency_is_active' => ['boolean'],
            'currency_is_base' => ['boolean'],
            'currency_is_local' => ['boolean'],
            'currency_name' => ['required', 'string', 'max:255'],
            'currency_rate_input' => ['required', 'numeric', 'gt:0'],
            'currency_symbol' => ['nullable', 'string', 'max:20'],
        ]);

        if ($validated['currency_is_base'] && $validated['currency_is_local']) {
            $this->addError('currency_is_base', 'Base and local currency must be two different currencies.');

            return;
        }

        $current = $this->currency_editing_id ? FinanceCurrency::query()->findOrFail($this->currency_editing_id) : null;

        if (! $validated['currency_is_active'] && $current) {
            if ($current->is_base || $current->is_local) {
                $this->addError('currency_is_active', 'The local or base currency cannot be deactivated.');

                return;
            }

            if (app(FinanceService::class)->currencyBalance($current) != 0.0) {
                $this->addError('currency_is_active', 'A currency with a non-zero balance cannot be deactivated.');

                return;
            }
        }

        if (! $validated['currency_is_base'] && $current?->is_base && ! FinanceCurrency::query()->where('is_base', true)->where('is_active', true)->whereKeyNot($current->id)->exists()) {
            $this->addError('currency_is_base', 'Choose another active base currency before removing this one.');

            return;
        }

        if (! $validated['currency_is_local'] && $current?->is_local && ! FinanceCurrency::query()->where('is_local', true)->where('is_active', true)->whereKeyNot($current->id)->exists()) {
            $this->addError('currency_is_local', 'Choose another active local currency before removing this one.');

            return;
        }

        $rateToBase = (float) $validated['currency_rate_input'];

        if ($validated['currency_is_base']) {
            $validated['currency_is_active'] = true;
            $rateToBase = 1;
        } elseif ($validated['currency_is_local']) {
            $validated['currency_is_active'] = true;
            $rateToBase = 1 / (float) $validated['currency_rate_input'];
        }

        $currency = FinanceCurrency::query()->updateOrCreate(
            ['id' => $this->currency_editing_id],
            [
                'code' => strtoupper($validated['currency_code']),
                'is_active' => $validated['currency_is_active'],
                'is_base' => $validated['currency_is_base'],
                'is_local' => $validated['currency_is_local'],
                'name' => $validated['currency_name'],
                'rate_to_base' => $rateToBase,
                'rate_updated_at' => now(),
                'rate_updated_by' => auth()->id(),
                'symbol' => $validated['currency_symbol'] ?: null,
            ],
        );

        if ($currency->is_base) {
            FinanceCurrency::query()->whereKeyNot($currency->id)->update(['is_base' => false]);
        }

        if ($currency->is_local) {
            FinanceCurrency::query()->whereKeyNot($currency->id)->update(['is_local' => false]);
        }

        $this->cancelCurrency();
        session()->flash('status', 'Currency saved.');
    }

    public function saveExpenseCategory(): void
    {
        $this->authorizePermission('finance.categories.manage');

        $validated = $this->validate([
            'expense_category_code' => ['required', 'string', 'max:50', Rule::unique('expense_categories', 'code')->ignore($this->expense_category_editing_id)],
            'expense_category_is_active' => ['boolean'],
            'expense_category_name' => ['required', 'string', 'max:255'],
        ]);

        ExpenseCategory::query()->updateOrCreate(
            ['id' => $this->expense_category_editing_id],
            [
                'code' => $validated['expense_category_code'],
                'is_active' => $validated['expense_category_is_active'],
                'name' => $validated['expense_category_name'],
            ],
        );

        $this->cancelExpenseCategory();
        session()->flash('status', __('settings.finance.messages.expense_category_created'));
    }

    public function saveFinanceCategory(): void
    {
        $this->authorizePermission('finance.categories.manage');

        $validated = $this->validate([
            'finance_category_code' => ['required', 'string', 'max:50', Rule::unique('finance_categories', 'code')->ignore($this->finance_category_editing_id)],
            'finance_category_is_active' => ['boolean'],
            'finance_category_name' => ['required', 'string', 'max:255'],
            'finance_category_type' => ['required', Rule::in(FinanceCategory::TYPES)],
        ]);

        FinanceCategory::query()->updateOrCreate(
            ['id' => $this->finance_category_editing_id],
            [
                'code' => $validated['finance_category_code'],
                'is_active' => $validated['finance_category_is_active'],
                'name' => $validated['finance_category_name'],
                'type' => $validated['finance_category_type'],
            ],
        );

        $this->cancelFinanceCategory();
        session()->flash('status', 'Finance category saved.');
    }

    public function saveFinanceSettings(): void
    {
        $this->authorizePermission('finance.settings.manage');

        $validated = $this->validate([
            'invoice_prefix' => ['required', 'string', 'max:20'],
            'request_terms' => ['nullable', 'string'],
        ]);

        AppSetting::storeValue('finance', 'invoice_prefix', strtoupper($validated['invoice_prefix']));
        AppSetting::storeValue('finance', 'request_terms', $validated['request_terms'] ?: null);

        session()->flash('status', __('settings.finance.messages.settings_saved'));
    }

    public function savePaymentMethod(): void
    {
        $this->authorizePermission('finance.settings.manage');

        $validated = $this->validate([
            'payment_method_code' => ['required', 'string', 'max:50', Rule::unique('payment_methods', 'code')->ignore($this->payment_method_editing_id)],
            'payment_method_is_active' => ['boolean'],
            'payment_method_name' => ['required', 'string', 'max:255'],
        ]);

        PaymentMethod::query()->updateOrCreate(
            ['id' => $this->payment_method_editing_id],
            [
                'code' => $validated['payment_method_code'],
                'is_active' => $validated['payment_method_is_active'],
                'name' => $validated['payment_method_name'],
            ],
        );

        $this->cancelPaymentMethod();
        session()->flash('status', __('settings.finance.messages.payment_method_created'));
    }

    public function with(): array
    {
        return [
            'balances' => app(FinanceService::class)->cashBoxBalances(auth()->user()),
            'cashBoxes' => FinanceCashBox::query()->with('assignedUsers')->orderBy('name')->get(),
            'currencies' => FinanceCurrency::query()->orderByDesc('is_local')->orderByDesc('is_base')->orderBy('code')->get(),
            'expenseCategories' => ExpenseCategory::query()->orderBy('name')->get(),
            'financeCategories' => FinanceCategory::query()->orderBy('type')->orderBy('name')->get(),
            'paymentMethods' => PaymentMethod::query()->orderBy('name')->get(),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(),
        ];
    }

    public function cancelCashBox(): void
    {
        $this->cash_box_editing_id = null;
        $this->cash_box_name = '';
        $this->cash_box_code = '';
        $this->cash_box_is_active = true;
        $this->cash_box_notes = '';
        $this->cash_box_user_ids = [];
        $this->resetValidation();
    }

    public function cancelCurrency(): void
    {
        $this->currency_editing_id = null;
        $this->currency_code = '';
        $this->currency_name = '';
        $this->currency_symbol = '';
        $this->currency_rate_input = '1';
        $this->currency_is_active = true;
        $this->currency_is_local = false;
        $this->currency_is_base = false;
        $this->resetValidation();
    }

    public function cancelExpenseCategory(): void
    {
        $this->expense_category_editing_id = null;
        $this->expense_category_name = '';
        $this->expense_category_code = '';
        $this->expense_category_is_active = true;
        $this->resetValidation();
    }

    public function cancelFinanceCategory(): void
    {
        $this->finance_category_editing_id = null;
        $this->finance_category_name = '';
        $this->finance_category_code = '';
        $this->finance_category_type = 'expense';
        $this->finance_category_is_active = true;
        $this->resetValidation();
    }

    public function cancelPaymentMethod(): void
    {
        $this->payment_method_editing_id = null;
        $this->payment_method_name = '';
        $this->payment_method_code = '';
        $this->payment_method_is_active = true;
        $this->resetValidation();
    }

    protected function loadFinanceSettings(): void
    {
        $settings = AppSetting::groupValues('finance');

        $this->invoice_prefix = (string) ($settings->get('invoice_prefix') ?: 'INV');
        $this->request_terms = (string) ($settings->get('request_terms') ?: '');
    }
}; ?>

<div class="page-stack settings-admin-page">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.settings') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">Finance Settings</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">Manage currencies, rates, cash boxes, request terms, categories, and existing payment setup.</p>
    </section>

    <x-settings.admin-nav section="dashboard" current="settings.finance" />

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="surface-panel p-5 lg:p-6">
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">Finance defaults</div>
                <p class="admin-toolbar__subtitle">Request terms are snapshotted when a teacher submits a pull request. Empty terms means no acceptance checkbox is required.</p>
            </div>
        </div>
        <form wire:submit="saveFinanceSettings" class="mt-5 grid gap-4 lg:grid-cols-[16rem_minmax(0,1fr)_auto] lg:items-end">
            <div>
                <label class="mb-1 block text-sm font-medium">Invoice prefix</label>
                <input wire:model="invoice_prefix" type="text" class="w-full rounded-xl px-4 py-3 text-sm uppercase">
                @error('invoice_prefix') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">Teacher pull-request terms</label>
                <textarea wire:model="request_terms" rows="2" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>
                @error('request_terms') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
            </div>
            <button type="submit" class="pill-link pill-link--accent">Save defaults</button>
        </form>
    </section>

    <section class="grid gap-6 xl:grid-cols-[25rem_minmax(0,1fr)]">
        <div class="surface-panel p-5 lg:p-6">
            <div class="admin-section-card__title">{{ $currency_editing_id ? 'Edit currency' : 'New currency' }}</div>
            <form wire:submit="saveCurrency" class="mt-5 space-y-4">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium">Code</label>
                        <input wire:model="currency_code" type="text" class="w-full rounded-xl px-4 py-3 text-sm uppercase">
                        @error('currency_code') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">Symbol</label>
                        <input wire:model="currency_symbol" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                    </div>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Name</label>
                    <input wire:model="currency_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('currency_name') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Rate</label>
                    <input wire:model="currency_rate_input" type="number" min="0" step="0.00000001" class="w-full rounded-xl px-4 py-3 text-sm">
                    <p class="mt-1 text-xs text-neutral-500">For local currency enter base to local, for other currencies enter this currency to base. Base currency is always 1.</p>
                    @error('currency_rate_input') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
                <div class="grid gap-3 text-sm">
                    <label class="flex items-center gap-3"><input wire:model="currency_is_active" type="checkbox" class="rounded"> Active</label>
                    <label class="flex items-center gap-3"><input wire:model="currency_is_local" type="checkbox" class="rounded"> Local currency</label>
                    <label class="flex items-center gap-3"><input wire:model="currency_is_base" type="checkbox" class="rounded"> Base currency</label>
                </div>
                @error('currency_is_active') <div class="text-sm text-red-400">{{ $message }}</div> @enderror
                @error('currency_is_local') <div class="text-sm text-red-400">{{ $message }}</div> @enderror
                @error('currency_is_base') <div class="text-sm text-red-400">{{ $message }}</div> @enderror
                <div class="flex gap-3">
                    <button type="submit" class="pill-link pill-link--accent">{{ $currency_editing_id ? 'Update currency' : 'Create currency' }}</button>
                    @if ($currency_editing_id)
                        <button type="button" wire:click="cancelCurrency" class="pill-link">Cancel</button>
                    @endif
                </div>
            </form>
        </div>

        <div class="surface-table">
            <div class="admin-grid-meta"><div><div class="admin-grid-meta__title">Currencies</div><div class="admin-grid-meta__summary">Only one active local and one active base currency should exist.</div></div></div>
            @error('currencyDelete') <div class="mx-5 mb-4 rounded-2xl border border-red-500/25 bg-red-500/10 px-3 py-2 text-sm text-red-200">{{ $message }}</div> @enderror
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead><tr><th class="px-5 py-3 text-left">Currency</th><th class="px-5 py-3 text-left">Rate to base</th><th class="px-5 py-3 text-left">Flags</th><th class="px-5 py-3 text-right">Actions</th></tr></thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($currencies as $currency)
                            <tr>
                                <td class="px-5 py-3"><div class="font-medium text-white">{{ $currency->code }} {{ $currency->symbol ? '('.$currency->symbol.')' : '' }}</div><div class="text-xs text-neutral-500">{{ $currency->name }}</div></td>
                                <td class="px-5 py-3">{{ number_format((float) $currency->rate_to_base, 8) }}</td>
                                <td class="px-5 py-3">
                                    <div class="flex flex-wrap gap-2">
                                        @if ($currency->is_local)<span class="status-chip status-chip--emerald">Local</span>@endif
                                        @if ($currency->is_base)<span class="status-chip status-chip--slate">Base</span>@endif
                                        <span class="status-chip {{ $currency->is_active ? 'status-chip--emerald' : 'status-chip--rose' }}">{{ $currency->is_active ? 'Active' : 'Inactive' }}</span>
                                    </div>
                                </td>
                                <td class="px-5 py-3"><div class="admin-action-cluster admin-action-cluster--end"><button type="button" wire:click="editCurrency({{ $currency->id }})" class="pill-link pill-link--compact">Edit</button><button type="button" wire:click="deleteCurrency({{ $currency->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200">Delete</button></div></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="grid gap-6 xl:grid-cols-[25rem_minmax(0,1fr)]">
        <div class="surface-panel p-5 lg:p-6">
            <div class="admin-section-card__title">{{ $cash_box_editing_id ? 'Edit cash box' : 'New cash box' }}</div>
            <form wire:submit="saveCashBox" class="mt-5 space-y-4">
                <div><label class="mb-1 block text-sm font-medium">Name</label><input wire:model="cash_box_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">@error('cash_box_name') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
                <div><label class="mb-1 block text-sm font-medium">Code</label><input wire:model="cash_box_code" type="text" class="w-full rounded-xl px-4 py-3 text-sm">@error('cash_box_code') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
                <div><label class="mb-1 block text-sm font-medium">Assigned users</label><select wire:model="cash_box_user_ids" multiple size="6" class="w-full rounded-xl px-4 py-3 text-sm">@foreach ($users as $user)<option value="{{ $user->id }}">{{ $user->name }} {{ $user->username ? '('.$user->username.')' : '' }}</option>@endforeach</select></div>
                <div><label class="mb-1 block text-sm font-medium">Notes</label><textarea wire:model="cash_box_notes" rows="2" class="w-full rounded-xl px-4 py-3 text-sm"></textarea></div>
                <label class="flex items-center gap-3 text-sm"><input wire:model="cash_box_is_active" type="checkbox" class="rounded"> Active</label>
                @error('cash_box_is_active') <div class="text-sm text-red-400">{{ $message }}</div> @enderror
                <div class="flex gap-3"><button type="submit" class="pill-link pill-link--accent">{{ $cash_box_editing_id ? 'Update box' : 'Create box' }}</button>@if ($cash_box_editing_id)<button type="button" wire:click="cancelCashBox" class="pill-link">Cancel</button>@endif</div>
            </form>
        </div>

        <div class="surface-table">
            <div class="admin-grid-meta"><div><div class="admin-grid-meta__title">Cash boxes</div><div class="admin-grid-meta__summary">Assigned users can only use their boxes unless they have manage permission.</div></div></div>
            @error('cashBoxDelete') <div class="mx-5 mb-4 rounded-2xl border border-red-500/25 bg-red-500/10 px-3 py-2 text-sm text-red-200">{{ $message }}</div> @enderror
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead><tr><th class="px-5 py-3 text-left">Box</th><th class="px-5 py-3 text-left">Users</th><th class="px-5 py-3 text-left">Status</th><th class="px-5 py-3 text-right">Actions</th></tr></thead>
                    <tbody class="divide-y divide-white/6">@foreach ($cashBoxes as $box)<tr><td class="px-5 py-3"><div class="font-medium text-white">{{ $box->name }}</div><div class="text-xs text-neutral-500">{{ $box->code }}</div></td><td class="px-5 py-3">{{ $box->assignedUsers->pluck('name')->implode(', ') ?: '-' }}</td><td class="px-5 py-3">{{ $box->is_active ? 'Active' : 'Inactive' }}</td><td class="px-5 py-3"><div class="admin-action-cluster admin-action-cluster--end"><button type="button" wire:click="editCashBox({{ $box->id }})" class="pill-link pill-link--compact">Edit</button><button type="button" wire:click="deleteCashBox({{ $box->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200">Delete</button></div></td></tr>@endforeach</tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="grid gap-6 xl:grid-cols-2">
        <div class="surface-panel p-5 lg:p-6">
            <div class="admin-section-card__title">{{ $finance_category_editing_id ? 'Edit finance category' : 'New finance category' }}</div>
            <form wire:submit="saveFinanceCategory" class="mt-5 grid gap-4 md:grid-cols-2">
                <div><label class="mb-1 block text-sm font-medium">Name</label><input wire:model="finance_category_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">@error('finance_category_name') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
                <div><label class="mb-1 block text-sm font-medium">Code</label><input wire:model="finance_category_code" type="text" class="w-full rounded-xl px-4 py-3 text-sm">@error('finance_category_code') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
                <div><label class="mb-1 block text-sm font-medium">Type</label><select wire:model="finance_category_type" class="w-full rounded-xl px-4 py-3 text-sm">@foreach (\App\Models\FinanceCategory::TYPES as $type)<option value="{{ $type }}">{{ ucfirst($type) }}</option>@endforeach</select></div>
                <label class="flex items-center gap-3 text-sm"><input wire:model="finance_category_is_active" type="checkbox" class="rounded"> Active</label>
                <div class="md:col-span-2 flex gap-3"><button type="submit" class="pill-link pill-link--accent">{{ $finance_category_editing_id ? 'Update category' : 'Create category' }}</button>@if ($finance_category_editing_id)<button type="button" wire:click="cancelFinanceCategory" class="pill-link">Cancel</button>@endif</div>
            </form>
            @error('financeCategoryDelete') <div class="mt-4 text-sm text-red-400">{{ $message }}</div> @enderror
        </div>

        <div class="surface-table">
            <div class="admin-grid-meta"><div><div class="admin-grid-meta__title">Finance categories</div><div class="admin-grid-meta__summary">Used for management reasons, expenses, revenues, and returns.</div></div></div>
            <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-5 py-3 text-left">Name</th><th class="px-5 py-3 text-left">Type</th><th class="px-5 py-3 text-left">State</th><th class="px-5 py-3 text-right">Actions</th></tr></thead><tbody class="divide-y divide-white/6">@foreach ($financeCategories as $category)<tr><td class="px-5 py-3"><div class="font-medium text-white">{{ $category->name }}</div><div class="text-xs text-neutral-500">{{ $category->code }}</div></td><td class="px-5 py-3">{{ ucfirst($category->type) }}</td><td class="px-5 py-3">{{ $category->is_active ? 'Active' : 'Inactive' }}</td><td class="px-5 py-3"><div class="admin-action-cluster admin-action-cluster--end"><button type="button" wire:click="editFinanceCategory({{ $category->id }})" class="pill-link pill-link--compact">Edit</button><button type="button" wire:click="deleteFinanceCategory({{ $category->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200">Delete</button></div></td></tr>@endforeach</tbody></table></div>
        </div>
    </section>

    <section class="grid gap-6 xl:grid-cols-2">
        <div class="surface-panel p-5 lg:p-6">
            <div class="admin-section-card__title">{{ $payment_method_editing_id ? __('settings.finance.sections.payment_method.edit') : __('settings.finance.sections.payment_method.create') }}</div>
            <form wire:submit="savePaymentMethod" class="mt-5 space-y-4">
                <div><label class="mb-1 block text-sm font-medium">{{ __('settings.finance.fields.name') }}</label><input wire:model="payment_method_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">@error('payment_method_name') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
                <div><label class="mb-1 block text-sm font-medium">{{ __('settings.finance.fields.code') }}</label><input wire:model="payment_method_code" type="text" class="w-full rounded-xl px-4 py-3 text-sm">@error('payment_method_code') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
                <label class="flex items-center gap-3 text-sm"><input wire:model="payment_method_is_active" type="checkbox" class="rounded"> {{ __('settings.finance.fields.is_active') }}</label>
                <div class="flex gap-3"><button type="submit" class="pill-link pill-link--accent">{{ $payment_method_editing_id ? __('settings.finance.actions.update_method') : __('settings.finance.actions.create_method') }}</button>@if ($payment_method_editing_id)<button type="button" wire:click="cancelPaymentMethod" class="pill-link">Cancel</button>@endif</div>
            </form>
            @error('paymentMethodDelete') <div class="mt-4 text-sm text-red-400">{{ $message }}</div> @enderror
        </div>

        <div class="surface-panel p-5 lg:p-6">
            <div class="admin-section-card__title">{{ $expense_category_editing_id ? __('settings.finance.sections.expense_category.edit') : __('settings.finance.sections.expense_category.create') }}</div>
            <form wire:submit="saveExpenseCategory" class="mt-5 space-y-4">
                <div><label class="mb-1 block text-sm font-medium">{{ __('settings.finance.fields.name') }}</label><input wire:model="expense_category_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">@error('expense_category_name') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
                <div><label class="mb-1 block text-sm font-medium">{{ __('settings.finance.fields.code') }}</label><input wire:model="expense_category_code" type="text" class="w-full rounded-xl px-4 py-3 text-sm">@error('expense_category_code') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
                <label class="flex items-center gap-3 text-sm"><input wire:model="expense_category_is_active" type="checkbox" class="rounded"> {{ __('settings.finance.fields.is_active') }}</label>
                <div class="flex gap-3"><button type="submit" class="pill-link pill-link--accent">{{ $expense_category_editing_id ? __('settings.finance.actions.update_category') : __('settings.finance.actions.create_category') }}</button>@if ($expense_category_editing_id)<button type="button" wire:click="cancelExpenseCategory" class="pill-link">Cancel</button>@endif</div>
            </form>
            @error('expenseCategoryDelete') <div class="mt-4 text-sm text-red-400">{{ $message }}</div> @enderror
        </div>
    </section>

    <section class="grid gap-6 xl:grid-cols-2">
        <div class="surface-table"><div class="admin-grid-meta"><div><div class="admin-grid-meta__title">{{ __('settings.finance.sections.payment_method.table') }}</div></div></div><div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-5 py-3 text-left">Method</th><th class="px-5 py-3 text-left">State</th><th class="px-5 py-3 text-right">Actions</th></tr></thead><tbody class="divide-y divide-white/6">@foreach ($paymentMethods as $method)<tr><td class="px-5 py-3"><div class="font-medium text-white">{{ $method->name }}</div><div class="text-xs text-neutral-500">{{ $method->code }}</div></td><td class="px-5 py-3">{{ $method->is_active ? 'Active' : 'Inactive' }}</td><td class="px-5 py-3"><div class="admin-action-cluster admin-action-cluster--end"><button type="button" wire:click="editPaymentMethod({{ $method->id }})" class="pill-link pill-link--compact">Edit</button><button type="button" wire:click="deletePaymentMethod({{ $method->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200">Delete</button></div></td></tr>@endforeach</tbody></table></div></div>
        <div class="surface-table"><div class="admin-grid-meta"><div><div class="admin-grid-meta__title">{{ __('settings.finance.sections.expense_category.table') }}</div></div></div><div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-5 py-3 text-left">Category</th><th class="px-5 py-3 text-left">State</th><th class="px-5 py-3 text-right">Actions</th></tr></thead><tbody class="divide-y divide-white/6">@foreach ($expenseCategories as $category)<tr><td class="px-5 py-3"><div class="font-medium text-white">{{ $category->name }}</div><div class="text-xs text-neutral-500">{{ $category->code }}</div></td><td class="px-5 py-3">{{ $category->is_active ? 'Active' : 'Inactive' }}</td><td class="px-5 py-3"><div class="admin-action-cluster admin-action-cluster--end"><button type="button" wire:click="editExpenseCategory({{ $category->id }})" class="pill-link pill-link--compact">Edit</button><button type="button" wire:click="deleteExpenseCategory({{ $category->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200">Delete</button></div></td></tr>@endforeach</tbody></table></div></div>
    </section>
</div>
