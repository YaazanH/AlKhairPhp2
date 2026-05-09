<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\FormatsFinanceNumbers;
use App\Models\ActivityPayment;
use App\Models\AppSetting;
use App\Models\FinanceCashBox;
use App\Models\FinanceCategory;
use App\Models\FinanceCurrency;
use App\Models\FinancePullRequestKind;
use App\Models\FinanceRequest;
use App\Models\FinanceTransaction;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PrintTemplate;
use App\Models\User;
use App\Services\FinanceService;
use App\Services\PrintTemplates\PrintTemplateDataSourceService;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;
    use FormatsFinanceNumbers;

    public string $invoice_prefix = '';
    public string $request_terms = '';
    public string $default_cash_box_id = '';
    public string $default_pull_request_kind_id = '';
    public string $default_revenue_category_id = '';
    public string $default_pull_print_template_id = '';
    public string $default_expense_print_template_id = '';
    public string $default_revenue_print_template_id = '';
    public string $default_return_print_template_id = '';
    public bool $cash_box_manual_adjustment_enabled = true;
    public bool $cash_box_transfer_enabled = true;

    public ?int $currency_editing_id = null;
    public string $currency_code = '';
    public string $currency_name = '';
    public string $currency_symbol = '';
    public string $currency_rate_input = '1';
    public ?int $currency_rate_reference_currency_id = null;
    public bool $currency_is_active = true;
    public bool $currency_is_local = false;
    public bool $currency_is_base = false;
    public bool $showCurrencyModal = false;

    public ?int $cash_box_editing_id = null;
    public string $cash_box_name = '';
    public string $cash_box_code = '';
    public bool $cash_box_is_active = true;
    public string $cash_box_notes = '';
    public array $cash_box_user_ids = [];
    public array $cash_box_currency_ids = [];
    public bool $showCashBoxModal = false;

    public ?int $finance_category_editing_id = null;
    public string $finance_category_name = '';
    public string $finance_category_code = '';
    public string $finance_category_type = 'expense';
    public bool $finance_category_is_active = true;
    public bool $showFinanceCategoryModal = false;

    public ?int $pull_kind_editing_id = null;
    public string $pull_kind_name = '';
    public string $pull_kind_code = '';
    public string $pull_kind_mode = 'count';
    public bool $pull_kind_is_active = true;
    public bool $showPullKindModal = false;

    public ?int $payment_method_editing_id = null;
    public string $payment_method_name = '';
    public string $payment_method_code = '';
    public bool $payment_method_is_active = true;
    public bool $showPaymentMethodModal = false;

    public function mount(): void
    {
        $this->authorizePermission('finance.settings.manage');
        $this->loadFinanceSettings();
        $this->cash_box_currency_ids = FinanceCurrency::query()->where('is_active', true)->pluck('id')->map(fn ($id) => (string) $id)->all();
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

    public function deletePullKind(int $kindId): void
    {
        $this->authorizePermission('finance.settings.manage');

        $kind = FinancePullRequestKind::query()->findOrFail($kindId);

        if ($kind->requests()->exists()) {
            $this->addError('pullKindDelete', __('finance.validation.pull_kind_used'));

            return;
        }

        $kind->delete();
        $this->cancelPullKind();
        session()->flash('status', __('finance.messages.pull_kind_deleted'));
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

        $cashBox = FinanceCashBox::query()->with(['assignedUsers', 'currencies'])->findOrFail($cashBoxId);
        $this->cash_box_editing_id = $cashBox->id;
        $this->cash_box_name = $cashBox->name;
        $this->cash_box_code = $cashBox->code;
        $this->cash_box_is_active = $cashBox->is_active;
        $this->cash_box_notes = $cashBox->notes ?? '';
        $this->cash_box_user_ids = $cashBox->assignedUsers->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->cash_box_currency_ids = $cashBox->currencies->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->showCashBoxModal = true;
        $this->resetValidation();
    }

    public function editCurrency(int $currencyId): void
    {
        $this->authorizePermission('finance.currencies.manage');

        $currency = FinanceCurrency::query()->with('rateReferenceCurrency')->findOrFail($currencyId);
        $this->currency_editing_id = $currency->id;
        $this->currency_code = $currency->code;
        $this->currency_name = $currency->name;
        $this->currency_symbol = $currency->symbol ?? '';
        $this->currency_is_active = $currency->is_active;
        $this->currency_is_local = $currency->is_local;
        $this->currency_is_base = $currency->is_base;
        $this->currency_rate_input = app(FinanceService::class)->currencyRateInput($currency);
        $this->currency_rate_reference_currency_id = $currency->rate_reference_currency_id ?: app(FinanceService::class)->baseCurrency()->id;
        $this->showCurrencyModal = true;
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
        $this->showFinanceCategoryModal = true;
        $this->resetValidation();
    }

    public function editPullKind(int $kindId): void
    {
        $this->authorizePermission('finance.settings.manage');

        $kind = FinancePullRequestKind::query()->findOrFail($kindId);
        $this->pull_kind_editing_id = $kind->id;
        $this->pull_kind_name = $kind->name;
        $this->pull_kind_code = $kind->code;
        $this->pull_kind_mode = $kind->mode;
        $this->pull_kind_is_active = $kind->is_active;
        $this->showPullKindModal = true;
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
        $this->showPaymentMethodModal = true;
        $this->resetValidation();
    }

    public function openCashBoxModal(): void
    {
        $this->authorizePermission('finance.cash-box.manage');
        $this->cancelCashBox();
        $this->showCashBoxModal = true;
    }

    public function closeCashBoxModal(): void
    {
        $this->cancelCashBox();
    }

    public function openCurrencyModal(): void
    {
        $this->authorizePermission('finance.currencies.manage');
        $this->cancelCurrency();
        $this->showCurrencyModal = true;
    }

    public function closeCurrencyModal(): void
    {
        $this->cancelCurrency();
    }

    public function openFinanceCategoryModal(): void
    {
        $this->authorizePermission('finance.categories.manage');
        $this->cancelFinanceCategory();
        $this->showFinanceCategoryModal = true;
    }

    public function closeFinanceCategoryModal(): void
    {
        $this->cancelFinanceCategory();
    }

    public function openPullKindModal(): void
    {
        $this->authorizePermission('finance.settings.manage');
        $this->cancelPullKind();
        $this->showPullKindModal = true;
    }

    public function closePullKindModal(): void
    {
        $this->cancelPullKind();
    }

    public function openPaymentMethodModal(): void
    {
        $this->authorizePermission('finance.settings.manage');
        $this->cancelPaymentMethod();
        $this->showPaymentMethodModal = true;
    }

    public function closePaymentMethodModal(): void
    {
        $this->cancelPaymentMethod();
    }

    public function saveCashBox(): void
    {
        $this->authorizePermission('finance.cash-box.manage');

        $validated = $this->validate([
            'cash_box_code' => ['required', 'string', 'max:50', Rule::unique('finance_cash_boxes', 'code')->ignore($this->cash_box_editing_id)],
            'cash_box_is_active' => ['boolean'],
            'cash_box_name' => ['required', 'string', 'max:255'],
            'cash_box_notes' => ['nullable', 'string'],
            'cash_box_currency_ids' => ['required', 'array', 'min:1'],
            'cash_box_currency_ids.*' => ['integer', 'exists:finance_currencies,id'],
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

        if ($this->cash_box_editing_id) {
            $removedCurrencyIds = FinanceCashBox::query()
                ->with('currencies')
                ->findOrFail($this->cash_box_editing_id)
                ->currencies
                ->pluck('id')
                ->diff(array_map('intval', $validated['cash_box_currency_ids']));

            foreach ($removedCurrencyIds as $currencyId) {
                $balance = (float) FinanceTransaction::query()
                    ->where('cash_box_id', $this->cash_box_editing_id)
                    ->where('currency_id', $currencyId)
                    ->sum('signed_amount');

                if (round($balance, 2) !== 0.0) {
                    $this->addError('cash_box_currency_ids', 'A currency with a non-zero balance cannot be removed from this cash box.');

                    return;
                }
            }
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
        $cashBox->currencies()->sync(array_map('intval', $validated['cash_box_currency_ids']));
        $this->cancelCashBox();
        session()->flash('status', 'Cash box saved.');
    }

    public function saveCurrency(): void
    {
        $this->authorizePermission('finance.currencies.manage');
        $this->normalizeFinanceNumberProperty('currency_rate_input');

        $validated = $this->validate([
            'currency_code' => ['required', 'string', 'max:10', Rule::unique('finance_currencies', 'code')->ignore($this->currency_editing_id)],
            'currency_is_active' => ['boolean'],
            'currency_is_base' => ['boolean'],
            'currency_is_local' => ['boolean'],
            'currency_name' => ['required', 'string', 'max:255'],
            'currency_rate_input' => ['required', 'numeric', 'gt:0'],
            'currency_rate_reference_currency_id' => ['nullable', 'integer', 'exists:finance_currencies,id'],
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

        $referenceCurrency = $validated['currency_rate_reference_currency_id']
            ? FinanceCurrency::query()->find((int) $validated['currency_rate_reference_currency_id'])
            : app(FinanceService::class)->baseCurrency();

        if (! $validated['currency_is_base'] && $current && $referenceCurrency && (int) $referenceCurrency->id === (int) $current->id) {
            $this->addError('currency_rate_reference_currency_id', __('finance.validation.currency_reference_self'));

            return;
        }

        $rateToBase = $referenceCurrency
            ? (float) $referenceCurrency->rate_to_base / (float) $validated['currency_rate_input']
            : (float) $validated['currency_rate_input'];

        if ($validated['currency_is_base']) {
            $validated['currency_is_active'] = true;
            $rateToBase = 1;
            $referenceCurrency = null;
        } elseif ($validated['currency_is_local']) {
            $validated['currency_is_active'] = true;
        }

        $currency = FinanceCurrency::query()->updateOrCreate(
            ['id' => $this->currency_editing_id],
            [
                'code' => strtoupper($validated['currency_code']),
                'is_active' => $validated['currency_is_active'],
                'is_base' => $validated['currency_is_base'],
                'is_local' => $validated['currency_is_local'],
                'name' => $validated['currency_name'],
                'rate_reference_currency_id' => $validated['currency_is_base'] ? null : $referenceCurrency?->id,
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

    public function savePullKind(): void
    {
        $this->authorizePermission('finance.settings.manage');

        $validated = $this->validate([
            'pull_kind_code' => ['required', 'string', 'max:50', Rule::unique('finance_pull_request_kinds', 'code')->ignore($this->pull_kind_editing_id)],
            'pull_kind_is_active' => ['boolean'],
            'pull_kind_mode' => ['required', Rule::in(FinancePullRequestKind::MODES)],
            'pull_kind_name' => ['required', 'string', 'max:255'],
        ]);

        FinancePullRequestKind::query()->updateOrCreate(
            ['id' => $this->pull_kind_editing_id],
            [
                'code' => strtolower($validated['pull_kind_code']),
                'is_active' => $validated['pull_kind_is_active'],
                'mode' => $validated['pull_kind_mode'],
                'name' => $validated['pull_kind_name'],
            ],
        );

        $this->cancelPullKind();
        session()->flash('status', __('finance.messages.pull_kind_saved'));
    }

    public function saveFinanceSettings(): void
    {
        $this->authorizePermission('finance.settings.manage');

        $validated = $this->validate([
            'invoice_prefix' => ['required', 'string', 'max:20'],
            'request_terms' => ['nullable', 'string'],
            'default_cash_box_id' => ['nullable', 'integer', Rule::exists('finance_cash_boxes', 'id')->where('is_active', true)],
            'default_pull_request_kind_id' => ['nullable', 'integer', Rule::exists('finance_pull_request_kinds', 'id')->where('is_active', true)],
            'default_revenue_category_id' => [
                'nullable',
                'integer',
                Rule::exists('finance_categories', 'id')
                    ->where('is_active', true)
                    ->whereIn('type', [FinanceRequest::TYPE_REVENUE, FinanceRequest::TYPE_RETURN]),
            ],
            'default_pull_print_template_id' => ['nullable', 'integer', 'exists:print_templates,id'],
            'default_expense_print_template_id' => ['nullable', 'integer', 'exists:print_templates,id'],
            'default_revenue_print_template_id' => ['nullable', 'integer', 'exists:print_templates,id'],
            'default_return_print_template_id' => ['nullable', 'integer', 'exists:print_templates,id'],
            'cash_box_manual_adjustment_enabled' => ['boolean'],
            'cash_box_transfer_enabled' => ['boolean'],
        ]);

        AppSetting::storeValue('finance', 'invoice_prefix', strtoupper($validated['invoice_prefix']));
        AppSetting::storeValue('finance', 'request_terms', $validated['request_terms'] ?: null);
        AppSetting::storeValue('finance', 'default_cash_box_id', $validated['default_cash_box_id'] ?: null, 'integer');
        AppSetting::storeValue('finance', 'default_pull_request_kind_id', $validated['default_pull_request_kind_id'] ?: null, 'integer');
        AppSetting::storeValue('finance', 'default_revenue_category_id', $validated['default_revenue_category_id'] ?: null, 'integer');
        AppSetting::storeValue('finance', 'default_pull_print_template_id', $validated['default_pull_print_template_id'] ?: null, 'integer');
        AppSetting::storeValue('finance', 'default_expense_print_template_id', $validated['default_expense_print_template_id'] ?: null, 'integer');
        AppSetting::storeValue('finance', 'default_revenue_print_template_id', $validated['default_revenue_print_template_id'] ?: null, 'integer');
        AppSetting::storeValue('finance', 'default_return_print_template_id', $validated['default_return_print_template_id'] ?: null, 'integer');
        AppSetting::storeValue('finance', 'cash_box_manual_adjustment_enabled', $validated['cash_box_manual_adjustment_enabled'], 'boolean');
        AppSetting::storeValue('finance', 'cash_box_transfer_enabled', $validated['cash_box_transfer_enabled'], 'boolean');

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
            'baseCurrency' => app(FinanceService::class)->baseCurrency(),
            'balances' => app(FinanceService::class)->cashBoxBalances(auth()->user()),
            'cashBoxes' => FinanceCashBox::query()->with(['assignedUsers', 'currencies'])->orderBy('name')->get(),
            'currencies' => FinanceCurrency::query()->with('rateReferenceCurrency')->orderByDesc('is_local')->orderByDesc('is_base')->orderBy('code')->get(),
            'defaultCashBoxes' => FinanceCashBox::query()->where('is_active', true)->orderBy('name')->get(),
            'defaultPullRequestKinds' => FinancePullRequestKind::query()->where('is_active', true)->orderBy('mode')->orderBy('name')->get(),
            'defaultRevenueCategories' => FinanceCategory::query()
                ->where('is_active', true)
                ->whereIn('type', [FinanceRequest::TYPE_REVENUE, FinanceRequest::TYPE_RETURN])
                ->orderByRaw("case when type = 'revenue' then 0 else 1 end")
                ->orderBy('name')
                ->get(),
            'financeCategories' => FinanceCategory::query()->orderBy('type')->orderBy('name')->get(),
            'financeRequestPrintTemplates' => $this->financeRequestPrintTemplates(),
            'paymentMethods' => PaymentMethod::query()->orderBy('name')->get(),
            'pullRequestKinds' => FinancePullRequestKind::query()->orderBy('mode')->orderBy('name')->get(),
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
        $this->cash_box_currency_ids = FinanceCurrency::query()->where('is_active', true)->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->showCashBoxModal = false;
        $this->resetValidation();
    }

    public function cancelCurrency(): void
    {
        $this->currency_editing_id = null;
        $this->currency_code = '';
        $this->currency_name = '';
        $this->currency_symbol = '';
        $this->currency_rate_input = '1';
        $this->currency_rate_reference_currency_id = app(FinanceService::class)->baseCurrency()->id;
        $this->currency_is_active = true;
        $this->currency_is_local = false;
        $this->currency_is_base = false;
        $this->showCurrencyModal = false;
        $this->resetValidation();
    }

    public function cancelFinanceCategory(): void
    {
        $this->finance_category_editing_id = null;
        $this->finance_category_name = '';
        $this->finance_category_code = '';
        $this->finance_category_type = 'expense';
        $this->finance_category_is_active = true;
        $this->showFinanceCategoryModal = false;
        $this->resetValidation();
    }

    public function cancelPullKind(): void
    {
        $this->pull_kind_editing_id = null;
        $this->pull_kind_name = '';
        $this->pull_kind_code = '';
        $this->pull_kind_mode = 'count';
        $this->pull_kind_is_active = true;
        $this->showPullKindModal = false;
        $this->resetValidation();
    }

    public function cancelPaymentMethod(): void
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
        $settings = AppSetting::groupValues('finance');

        $this->invoice_prefix = (string) ($settings->get('invoice_prefix') ?: 'INV');
        $this->request_terms = (string) ($settings->get('request_terms') ?: '');
        $this->default_cash_box_id = (string) ($settings->get('default_cash_box_id') ?: '');
        $this->default_pull_request_kind_id = (string) ($settings->get('default_pull_request_kind_id') ?: '');
        $this->default_revenue_category_id = (string) ($settings->get('default_revenue_category_id') ?: '');
        $this->default_pull_print_template_id = (string) ($settings->get('default_pull_print_template_id') ?: '');
        $this->default_expense_print_template_id = (string) ($settings->get('default_expense_print_template_id') ?: '');
        $this->default_revenue_print_template_id = (string) ($settings->get('default_revenue_print_template_id') ?: '');
        $this->default_return_print_template_id = (string) ($settings->get('default_return_print_template_id') ?: '');
        $this->cash_box_manual_adjustment_enabled = $settings->has('cash_box_manual_adjustment_enabled') ? (bool) $settings->get('cash_box_manual_adjustment_enabled') : true;
        $this->cash_box_transfer_enabled = $settings->has('cash_box_transfer_enabled') ? (bool) $settings->get('cash_box_transfer_enabled') : true;
    }

    protected function financeRequestPrintTemplates()
    {
        return PrintTemplate::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (PrintTemplate $template) => collect(app(PrintTemplateDataSourceService::class)->normalize($template->data_sources ?? []))
                ->contains(fn (array $source) => $source['entity'] === 'finance_request' && $source['mode'] === 'single'))
            ->values();
    }
}; ?>

<div class="page-stack settings-admin-page">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.settings') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('finance.settings.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('finance.settings.subtitle') }}</p>
    </section>

    <section class="surface-panel p-4">
        <div class="flex flex-wrap gap-2">
            <a href="#finance-defaults" class="pill-link pill-link--compact">{{ __('finance.settings.defaults') }}</a>
            <a href="#finance-currencies" class="pill-link pill-link--compact">{{ __('finance.settings.currencies') }}</a>
            <a href="#finance-cash-boxes" class="pill-link pill-link--compact">{{ __('finance.settings.cash_boxes') }}</a>
            <a href="#finance-categories" class="pill-link pill-link--compact">{{ __('finance.settings.categories') }}</a>
            <a href="#finance-request-kinds" class="pill-link pill-link--compact">{{ __('finance.settings.request_kinds') }}</a>
            <a href="#finance-legacy" class="pill-link pill-link--compact">{{ __('finance.settings.payment_setup') }}</a>
        </div>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section id="finance-defaults" class="surface-panel p-5 lg:p-6">
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">{{ __('finance.settings.finance_defaults') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('finance.settings.defaults_subtitle') }}</p>
            </div>
        </div>
        <form wire:submit="saveFinanceSettings" class="mt-5 grid gap-5">
            <div class="grid gap-4 lg:grid-cols-[16rem_minmax(0,1fr)]">
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('settings.finance.fields.invoice_prefix') }}</label>
                    <input wire:model="invoice_prefix" type="text" class="w-full rounded-xl px-4 py-3 text-sm uppercase">
                    @error('invoice_prefix') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('finance.settings.teacher_pull_terms') }}</label>
                    <textarea wire:model="request_terms" rows="2" class="w-full rounded-xl px-4 py-3 text-sm"></textarea>
                    @error('request_terms') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
            </div>

            <div>
                <div class="mb-3">
                    <div class="admin-section-card__title">{{ __('finance.settings.default_create_values') }}</div>
                    <p class="mt-1 text-sm text-neutral-400">{{ __('finance.settings.default_create_values_subtitle') }}</p>
                </div>
                <div class="grid gap-4 md:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('finance.settings.default_cash_box') }}</label>
                        <select wire:model="default_cash_box_id" class="w-full rounded-xl px-4 py-3 text-sm">
                            <option value="">{{ __('finance.settings.default_auto') }}</option>
                            @foreach ($defaultCashBoxes as $box)
                                <option value="{{ $box->id }}">{{ $box->name }}</option>
                            @endforeach
                        </select>
                        @error('default_cash_box_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('finance.settings.default_pull_kind') }}</label>
                        <select wire:model="default_pull_request_kind_id" class="w-full rounded-xl px-4 py-3 text-sm">
                            <option value="">{{ __('finance.settings.default_auto') }}</option>
                            @foreach ($defaultPullRequestKinds as $kind)
                                <option value="{{ $kind->id }}">{{ $kind->name }} - {{ __('finance.pull_modes.'.$kind->mode) }}</option>
                            @endforeach
                        </select>
                        @error('default_pull_request_kind_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('finance.settings.default_revenue_kind') }}</label>
                        <select wire:model="default_revenue_category_id" class="w-full rounded-xl px-4 py-3 text-sm">
                            <option value="">{{ __('finance.settings.default_auto') }}</option>
                            @foreach ($defaultRevenueCategories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }} - {{ __('finance.category_types.'.$category->type) }}</option>
                            @endforeach
                        </select>
                        @error('default_revenue_category_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>

            <div class="grid gap-3 rounded-3xl border border-white/10 bg-white/[0.03] p-4 md:grid-cols-2">
                <label class="flex items-start gap-3 text-sm">
                    <input wire:model="cash_box_manual_adjustment_enabled" type="checkbox" class="mt-1 rounded">
                    <span>
                        <span class="block font-semibold text-white">{{ __('finance.settings.show_manual_adjustment') }}</span>
                        <span class="mt-1 block text-xs leading-5 text-neutral-400">{{ __('finance.settings.show_manual_adjustment_help') }}</span>
                    </span>
                </label>
                <label class="flex items-start gap-3 text-sm">
                    <input wire:model="cash_box_transfer_enabled" type="checkbox" class="mt-1 rounded">
                    <span>
                        <span class="block font-semibold text-white">{{ __('finance.settings.show_cash_box_transfer') }}</span>
                        <span class="mt-1 block text-xs leading-5 text-neutral-400">{{ __('finance.settings.show_cash_box_transfer_help') }}</span>
                    </span>
                </label>
            </div>

            <div>
                <div class="mb-3">
                    <div class="admin-section-card__title">{{ __('finance.settings.default_print_templates') }}</div>
                    <p class="mt-1 text-sm text-neutral-400">{{ __('finance.settings.default_print_templates_subtitle') }}</p>
                </div>
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    @foreach ([
                        'default_pull_print_template_id' => __('finance.pull_requests.title'),
                        'default_expense_print_template_id' => __('finance.expense_requests.title'),
                        'default_revenue_print_template_id' => __('finance.revenue_requests.title'),
                        'default_return_print_template_id' => __('finance.settings.return_requests'),
                    ] as $field => $label)
                        <div>
                            <label class="mb-1 block text-sm font-medium">{{ $label }}</label>
                            <select wire:model="{{ $field }}" class="w-full rounded-xl px-4 py-3 text-sm">
                                <option value="">{{ __('finance.print.choose_each_time') }}</option>
                                @foreach ($financeRequestPrintTemplates as $template)
                                    <option value="{{ $template->id }}">{{ $template->name }}</option>
                                @endforeach
                            </select>
                            @error($field) <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                        </div>
                    @endforeach
                </div>
            </div>

            <div>
                <button type="submit" class="pill-link pill-link--accent">{{ __('settings.finance.actions.save_settings') }}</button>
            </div>
        </form>
    </section>

    <section id="finance-currencies" class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('finance.settings.currencies') }}</div>
                <div class="admin-grid-meta__summary">{{ __('finance.settings.currencies_subtitle') }}</div>
            </div>
            @can('finance.currencies.manage')
                <button type="button" wire:click="openCurrencyModal" class="pill-link pill-link--accent">{{ __('finance.actions.create_currency') }}</button>
            @endcan
        </div>
            @error('currencyDelete') <div class="mx-5 mb-4 rounded-2xl border border-red-500/25 bg-red-500/10 px-3 py-2 text-sm text-red-200">{{ $message }}</div> @enderror
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead><tr><th class="px-5 py-3 text-left">{{ __('finance.common.currency') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.exchange_rate') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.flags') }}</th><th class="px-5 py-3 text-right">{{ __('finance.actions.actions') }}</th></tr></thead>
                    <tbody class="divide-y divide-white/6">
                        @foreach ($currencies as $currency)
                            <tr>
                                <td class="px-5 py-3"><div class="font-medium text-white">{{ $currency->code }} {{ $currency->symbol ? '('.$currency->symbol.')' : '' }}</div><div class="text-xs text-neutral-500">{{ $currency->name }}</div></td>
                                <td class="px-5 py-3"><bdi dir="ltr" class="inline-block">{{ app(FinanceService::class)->currencyRateLabel($currency, $baseCurrency) }}</bdi></td>
                                <td class="px-5 py-3">
                                    <div class="flex flex-wrap gap-2">
                                        @if ($currency->is_local)<span class="status-chip status-chip--emerald">{{ __('finance.common.local') }}</span>@endif
                                        @if ($currency->is_base)<span class="status-chip status-chip--slate">{{ __('finance.common.base') }}</span>@endif
                                        <span class="status-chip {{ $currency->is_active ? 'status-chip--emerald' : 'status-chip--rose' }}">{{ $currency->is_active ? __('finance.common.active') : __('finance.common.inactive') }}</span>
                                    </div>
                                </td>
                                <td class="px-5 py-3"><div class="admin-action-cluster admin-action-cluster--end"><button type="button" wire:click="editCurrency({{ $currency->id }})" class="pill-link pill-link--compact">{{ __('finance.actions.edit') }}</button><button type="button" wire:click="deleteCurrency({{ $currency->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200">{{ __('finance.actions.delete') }}</button></div></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
    </section>

    <section id="finance-cash-boxes" class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('finance.settings.cash_boxes') }}</div>
                <div class="admin-grid-meta__summary">{{ __('finance.settings.cash_boxes_subtitle') }}</div>
            </div>
            @can('finance.cash-box.manage')
                <button type="button" wire:click="openCashBoxModal" class="pill-link pill-link--accent">{{ __('finance.actions.create_box') }}</button>
            @endcan
        </div>
            @error('cashBoxDelete') <div class="mx-5 mb-4 rounded-2xl border border-red-500/25 bg-red-500/10 px-3 py-2 text-sm text-red-200">{{ $message }}</div> @enderror
            <div class="overflow-x-auto">
                <table class="text-sm">
                    <thead><tr><th class="px-5 py-3 text-left">{{ __('finance.fields.cash_box') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.users') }}</th><th class="px-5 py-3 text-left">{{ __('finance.common.status') }}</th><th class="px-5 py-3 text-right">{{ __('finance.actions.actions') }}</th></tr></thead>
                    <tbody class="divide-y divide-white/6">@foreach ($cashBoxes as $box)<tr><td class="px-5 py-3"><div class="font-medium text-white">{{ $box->name }}</div><div class="text-xs text-neutral-500">{{ $box->code }}</div></td><td class="px-5 py-3"><div>{{ $box->assignedUsers->pluck('name')->implode(', ') ?: '-' }}</div><div class="mt-1 text-xs text-neutral-500">{{ $box->currencies->pluck('code')->implode(', ') ?: '-' }}</div></td><td class="px-5 py-3">{{ $box->is_active ? __('finance.common.active') : __('finance.common.inactive') }}</td><td class="px-5 py-3"><div class="admin-action-cluster admin-action-cluster--end"><button type="button" wire:click="editCashBox({{ $box->id }})" class="pill-link pill-link--compact">{{ __('finance.actions.edit') }}</button><button type="button" wire:click="deleteCashBox({{ $box->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200">{{ __('finance.actions.delete') }}</button></div></td></tr>@endforeach</tbody>
                </table>
            </div>
    </section>

    <section id="finance-categories" class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('finance.settings.finance_categories') }}</div>
                <div class="admin-grid-meta__summary">{{ __('finance.settings.finance_categories_subtitle') }}</div>
            </div>
            @can('finance.categories.manage')
                <button type="button" wire:click="openFinanceCategoryModal" class="pill-link pill-link--accent">{{ __('finance.actions.create_category') }}</button>
            @endcan
        </div>
            @error('financeCategoryDelete') <div class="mx-5 mb-4 rounded-2xl border border-red-500/25 bg-red-500/10 px-3 py-2 text-sm text-red-200">{{ $message }}</div> @enderror
            <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-5 py-3 text-left">{{ __('finance.fields.name') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.type') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.state') }}</th><th class="px-5 py-3 text-right">{{ __('finance.actions.actions') }}</th></tr></thead><tbody class="divide-y divide-white/6">@foreach ($financeCategories as $category)<tr><td class="px-5 py-3"><div class="font-medium text-white">{{ $category->name }}</div><div class="text-xs text-neutral-500">{{ $category->code }}</div></td><td class="px-5 py-3">{{ __('finance.category_types.'.$category->type) }}</td><td class="px-5 py-3">{{ $category->is_active ? __('finance.common.active') : __('finance.common.inactive') }}</td><td class="px-5 py-3"><div class="admin-action-cluster admin-action-cluster--end"><button type="button" wire:click="editFinanceCategory({{ $category->id }})" class="pill-link pill-link--compact">{{ __('finance.actions.edit') }}</button><button type="button" wire:click="deleteFinanceCategory({{ $category->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200">{{ __('finance.actions.delete') }}</button></div></td></tr>@endforeach</tbody></table></div>
    </section>

    <section id="finance-request-kinds" class="surface-table">
        <div class="admin-grid-meta">
            <div>
                <div class="admin-grid-meta__title">{{ __('finance.settings.pull_request_kinds') }}</div>
                <div class="admin-grid-meta__summary">{{ __('finance.settings.pull_request_kinds_subtitle') }}</div>
            </div>
            @can('finance.settings.manage')
                <button type="button" wire:click="openPullKindModal" class="pill-link pill-link--accent">{{ __('finance.actions.create_pull_kind') }}</button>
            @endcan
        </div>
        @error('pullKindDelete') <div class="mx-5 mb-4 rounded-2xl border border-red-500/25 bg-red-500/10 px-3 py-2 text-sm text-red-200">{{ $message }}</div> @enderror
        <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-5 py-3 text-left">{{ __('finance.fields.name') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.mode') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.state') }}</th><th class="px-5 py-3 text-right">{{ __('finance.actions.actions') }}</th></tr></thead><tbody class="divide-y divide-white/6">@foreach ($pullRequestKinds as $kind)<tr><td class="px-5 py-3"><div class="font-medium text-white">{{ $kind->name }}</div><div class="text-xs text-neutral-500">{{ $kind->code }}</div></td><td class="px-5 py-3">{{ __('finance.pull_modes.'.$kind->mode) }}</td><td class="px-5 py-3">{{ $kind->is_active ? __('finance.common.active') : __('finance.common.inactive') }}</td><td class="px-5 py-3"><div class="admin-action-cluster admin-action-cluster--end"><button type="button" wire:click="editPullKind({{ $kind->id }})" class="pill-link pill-link--compact">{{ __('finance.actions.edit') }}</button><button type="button" wire:click="deletePullKind({{ $kind->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200">{{ __('finance.actions.delete') }}</button></div></td></tr>@endforeach</tbody></table></div>
    </section>

    <section id="finance-legacy" class="surface-table">
        <div class="admin-grid-meta">
            <div><div class="admin-grid-meta__title">{{ __('settings.finance.sections.payment_method.table') }}</div></div>
            @can('finance.settings.manage')
                <button type="button" wire:click="openPaymentMethodModal" class="pill-link pill-link--accent">{{ __('settings.finance.actions.create_method') }}</button>
            @endcan
        </div>
        @error('paymentMethodDelete') <div class="mx-5 mb-4 rounded-2xl border border-red-500/25 bg-red-500/10 px-3 py-2 text-sm text-red-200">{{ $message }}</div> @enderror
        <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-5 py-3 text-left">{{ __('settings.finance.table.method') }}</th><th class="px-5 py-3 text-left">{{ __('settings.finance.table.state') }}</th><th class="px-5 py-3 text-right">{{ __('settings.finance.table.actions') }}</th></tr></thead><tbody class="divide-y divide-white/6">@foreach ($paymentMethods as $method)<tr><td class="px-5 py-3"><div class="font-medium text-white">{{ $method->name }}</div><div class="text-xs text-neutral-500">{{ $method->code }}</div></td><td class="px-5 py-3">{{ $method->is_active ? __('finance.common.active') : __('finance.common.inactive') }}</td><td class="px-5 py-3"><div class="admin-action-cluster admin-action-cluster--end"><button type="button" wire:click="editPaymentMethod({{ $method->id }})" class="pill-link pill-link--compact">{{ __('finance.actions.edit') }}</button><button type="button" wire:click="deletePaymentMethod({{ $method->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200">{{ __('finance.actions.delete') }}</button></div></td></tr>@endforeach</tbody></table></div>
    </section>

    <x-admin.modal :show="$showCurrencyModal" :title="$currency_editing_id ? __('finance.actions.edit').' '.__('finance.common.currency') : __('finance.actions.create_currency')" :description="__('finance.settings.currencies_subtitle')" close-method="closeCurrencyModal" max-width="3xl">
        <form wire:submit="saveCurrency" class="space-y-4">
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.code') }}</label>
                    <input wire:model="currency_code" type="text" class="w-full rounded-xl px-4 py-3 text-sm uppercase">
                    @error('currency_code') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.symbol') }}</label>
                    <input wire:model="currency_symbol" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                </div>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.name') }}</label>
                <input wire:model="currency_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                @error('currency_name') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
            </div>
            <div class="grid gap-4 md:grid-cols-[16rem_minmax(0,1fr)]">
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.rate_reference_currency') }}</label>
                    <select wire:model="currency_rate_reference_currency_id" @disabled($currency_is_base) class="w-full rounded-xl px-4 py-3 text-sm">
                        @foreach ($currencies as $currencyOption)
                            @if (! $currency_editing_id || (int) $currencyOption->id !== (int) $currency_editing_id)
                                <option value="{{ $currencyOption->id }}">{{ $currencyOption->code }} - {{ $currencyOption->name }}</option>
                            @endif
                        @endforeach
                    </select>
                    @error('currency_rate_reference_currency_id') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
                <div>
                <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.exchange_rate') }}</label>
                <input wire:model="currency_rate_input" type="text" inputmode="decimal" data-thousand-separator class="w-full rounded-xl px-4 py-3 text-sm">
                <p class="mt-1 text-xs text-neutral-500">{{ __('finance.settings.rate_help') }}</p>
                @error('currency_rate_input') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
            </div>
            <div class="grid gap-3 text-sm">
                <label class="flex items-center gap-3"><input wire:model="currency_is_active" type="checkbox" class="rounded"> {{ __('finance.common.active') }}</label>
                <label class="flex items-center gap-3"><input wire:model="currency_is_local" type="checkbox" class="rounded"> {{ __('finance.common.local_currency') }}</label>
                <label class="flex items-center gap-3"><input wire:model="currency_is_base" type="checkbox" class="rounded"> {{ __('finance.common.base_currency') }}</label>
            </div>
            @error('currency_is_active') <div class="text-sm text-red-400">{{ $message }}</div> @enderror
            @error('currency_is_local') <div class="text-sm text-red-400">{{ $message }}</div> @enderror
            @error('currency_is_base') <div class="text-sm text-red-400">{{ $message }}</div> @enderror
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closeCurrencyModal" class="pill-link">{{ __('finance.actions.cancel') }}</button>
                <button type="submit" class="pill-link pill-link--accent">{{ $currency_editing_id ? __('finance.actions.update_currency') : __('finance.actions.create_currency') }}</button>
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal :show="$showCashBoxModal" :title="$cash_box_editing_id ? __('finance.actions.edit').' '.__('finance.fields.cash_box') : __('finance.actions.create_box')" :description="__('finance.settings.cash_boxes_subtitle')" close-method="closeCashBoxModal" max-width="4xl">
        <form wire:submit="saveCashBox" class="space-y-4">
            <div class="grid gap-4 md:grid-cols-2">
                <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.name') }}</label><input wire:model="cash_box_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">@error('cash_box_name') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
                <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.code') }}</label><input wire:model="cash_box_code" type="text" class="w-full rounded-xl px-4 py-3 text-sm">@error('cash_box_code') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.supported_currencies') }}</label><select wire:model="cash_box_currency_ids" multiple size="6" class="w-full rounded-xl px-4 py-3 text-sm">@foreach ($currencies as $currency)<option value="{{ $currency->id }}">{{ $currency->code }} - {{ $currency->name }}</option>@endforeach</select>@error('cash_box_currency_ids') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
                <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.users') }}</label><select wire:model="cash_box_user_ids" multiple size="6" class="w-full rounded-xl px-4 py-3 text-sm">@foreach ($users as $user)<option value="{{ $user->id }}">{{ $user->name }} {{ $user->username ? '('.$user->username.')' : '' }}</option>@endforeach</select></div>
            </div>
            <div><label class="mb-1 block text-sm font-medium">{{ __('finance.common.notes') }}</label><textarea wire:model="cash_box_notes" rows="2" class="w-full rounded-xl px-4 py-3 text-sm"></textarea></div>
            <label class="flex items-center gap-3 text-sm"><input wire:model="cash_box_is_active" type="checkbox" class="rounded"> {{ __('finance.common.active') }}</label>
            @error('cash_box_is_active') <div class="text-sm text-red-400">{{ $message }}</div> @enderror
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closeCashBoxModal" class="pill-link">{{ __('finance.actions.cancel') }}</button>
                <button type="submit" class="pill-link pill-link--accent">{{ $cash_box_editing_id ? __('finance.actions.update_box') : __('finance.actions.create_box') }}</button>
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal :show="$showFinanceCategoryModal" :title="$finance_category_editing_id ? __('finance.actions.edit').' '.__('finance.fields.category') : __('finance.actions.create_category')" :description="__('finance.settings.finance_categories_subtitle')" close-method="closeFinanceCategoryModal" max-width="3xl">
        <form wire:submit="saveFinanceCategory" class="space-y-4">
            <div class="grid gap-4 md:grid-cols-2">
                <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.name') }}</label><input wire:model="finance_category_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">@error('finance_category_name') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
                <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.code') }}</label><input wire:model="finance_category_code" type="text" class="w-full rounded-xl px-4 py-3 text-sm">@error('finance_category_code') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
            </div>
            <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.type') }}</label><select wire:model="finance_category_type" class="w-full rounded-xl px-4 py-3 text-sm">@foreach (\App\Models\FinanceCategory::TYPES as $type)<option value="{{ $type }}">{{ __('finance.category_types.'.$type) }}</option>@endforeach</select></div>
            <label class="flex items-center gap-3 text-sm"><input wire:model="finance_category_is_active" type="checkbox" class="rounded"> {{ __('finance.common.active') }}</label>
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closeFinanceCategoryModal" class="pill-link">{{ __('finance.actions.cancel') }}</button>
                <button type="submit" class="pill-link pill-link--accent">{{ $finance_category_editing_id ? __('finance.actions.update_category') : __('finance.actions.create_category') }}</button>
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal :show="$showPullKindModal" :title="$pull_kind_editing_id ? __('finance.actions.edit').' '.__('finance.fields.pull_kind') : __('finance.actions.create_pull_kind')" :description="__('finance.settings.pull_request_kinds_subtitle')" close-method="closePullKindModal" max-width="3xl">
        <form wire:submit="savePullKind" class="space-y-4">
            <div class="grid gap-4 md:grid-cols-2">
                <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.name') }}</label><input wire:model="pull_kind_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">@error('pull_kind_name') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
                <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.code') }}</label><input wire:model="pull_kind_code" type="text" class="w-full rounded-xl px-4 py-3 text-sm">@error('pull_kind_code') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
            </div>
            <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.mode') }}</label><select wire:model="pull_kind_mode" class="w-full rounded-xl px-4 py-3 text-sm"><option value="count">{{ __('finance.pull_modes.count') }}</option><option value="invoice">{{ __('finance.pull_modes.invoice') }}</option></select>@error('pull_kind_mode') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
            <label class="flex items-center gap-3 text-sm"><input wire:model="pull_kind_is_active" type="checkbox" class="rounded"> {{ __('finance.common.active') }}</label>
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closePullKindModal" class="pill-link">{{ __('finance.actions.cancel') }}</button>
                <button type="submit" class="pill-link pill-link--accent">{{ $pull_kind_editing_id ? __('finance.actions.update_pull_kind') : __('finance.actions.create_pull_kind') }}</button>
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal :show="$showPaymentMethodModal" :title="$payment_method_editing_id ? __('settings.finance.sections.payment_method.edit') : __('settings.finance.sections.payment_method.create')" :description="__('settings.finance.sections.payment_method.copy')" close-method="closePaymentMethodModal" max-width="3xl">
        <form wire:submit="savePaymentMethod" class="space-y-4">
            <div><label class="mb-1 block text-sm font-medium">{{ __('settings.finance.fields.name') }}</label><input wire:model="payment_method_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">@error('payment_method_name') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
            <div><label class="mb-1 block text-sm font-medium">{{ __('settings.finance.fields.code') }}</label><input wire:model="payment_method_code" type="text" class="w-full rounded-xl px-4 py-3 text-sm">@error('payment_method_code') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
            <label class="flex items-center gap-3 text-sm"><input wire:model="payment_method_is_active" type="checkbox" class="rounded"> {{ __('settings.finance.fields.is_active') }}</label>
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closePaymentMethodModal" class="pill-link">{{ __('finance.actions.cancel') }}</button>
                <button type="submit" class="pill-link pill-link--accent">{{ $payment_method_editing_id ? __('settings.finance.actions.update_method') : __('settings.finance.actions.create_method') }}</button>
            </div>
        </form>
    </x-admin.modal>

</div>
