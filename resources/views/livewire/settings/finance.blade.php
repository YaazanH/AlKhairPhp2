<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Livewire\Concerns\FormatsFinanceNumbers;
use App\Models\ActivityPayment;
use App\Models\AppSetting;
use App\Models\FinanceCashBox;
use App\Models\FinanceCategory;
use App\Models\FinanceCurrency;
use App\Models\FinanceGeneratedReport;
use App\Models\FinancePullRequestKind;
use App\Models\FinanceRequest;
use App\Models\FinanceTransaction;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PrintTemplate;
use App\Models\User;
use App\Services\FinanceService;
use App\Services\PrintTemplates\PrintTemplateDataSourceService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use AuthorizesPermissions;
    use FormatsFinanceNumbers;
    use WithFileUploads;

    public string $invoice_prefix = '';
    public string $transaction_prefix = '';
    public string $pull_request_prefix = '';
    public string $expense_request_prefix = '';
    public string $revenue_request_prefix = '';
    public string $exchange_prefix = '';
    public string $transfer_prefix = '';
    public bool $maint_type_locked = false;
    public string $report_prefix = '';
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
    public bool $withdrawal_requests_enabled = true;
    public bool $showFinanceSettingsModal = false;

    public ?int $currency_editing_id = null;
    public string $currency_code = '';
    public string $currency_decimal_places = '2';
    public string $currency_name = '';
    public string $currency_symbol = '';
    public string $currency_rate_input = '1';
    public ?int $currency_rate_reference_currency_id = null;
    public bool $currency_is_active = true;
    public bool $currency_show_in_dropdowns = true;
    public bool $currency_is_local = false;
    public bool $currency_is_base = false;
    public bool $showCurrencyModal = false;

    public ?int $cash_box_editing_id = null;
    public string $cash_box_name = '';
    public string $cash_box_code = '';
    public bool $cash_box_is_active = true;
    public string $cash_box_notes = '';
    public array $cash_box_currency_ids = [];
    public bool $showCashBoxModal = false;

    public ?int $finance_category_editing_id = null;
    public string $finance_category_name = '';
    public string $finance_category_code = '';
    public string $finance_category_type = 'expense';
    public string $finance_category_mode = 'count';
    public bool $finance_category_is_active = true;
    public bool $finance_category_is_donation = false;
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

    public string $transaction_lookup_no = '';
    public ?int $maintaining_transaction_id = null;
    public bool $maintaining_transaction_deleted = false;
    public string $maint_transaction_date = '';
    public ?int $maint_cash_box_id = null;
    public ?int $maint_currency_id = null;
    public ?int $maint_category_id = null;
    public string $maint_type = '';
    public string $maint_direction = 'in';
    public string $maint_amount = '';
    public string $maint_description = '';
    public string $maint_special_transaction_no = '';
    public ?int $maint_entered_by = null;
    public string $maint_delete_reason = '';
    public string $report_lookup_no = '';
    public string $withdrawal_cleanup_request_no = '';
    public $legacy_report_pdf = null;
    public string $legacy_report_number = '';
    public string $legacy_report_period_mode = 'quarter';
    public int $legacy_report_year;
    public string $legacy_report_quarter = '';
    public string $legacy_report_date_from = '';
    public string $legacy_report_date_to = '';
    public string $legacy_report_cash_box = '';
    public string $legacy_report_currency = '';
    public string $legacy_report_generated_at = '';
    public bool $showLegacyReportModal = false;

    public function mount(): void
    {
        $this->authorizePermission('finance.settings.manage');
        $this->loadFinanceSettings();
        $this->legacy_report_year = (int) now()->year;
        $this->legacy_report_quarter = (string) now()->quarter;
        $this->cash_box_currency_ids = FinanceCurrency::query()->where('is_active', true)->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function openFinanceSettingsModal(): void
    {
        $this->authorizePermission('finance.settings.manage');
        $this->loadFinanceSettings();
        $this->showFinanceSettingsModal = true;
        $this->resetValidation();
    }

    public function closeFinanceSettingsModal(): void
    {
        $this->loadFinanceSettings();
        $this->showFinanceSettingsModal = false;
        $this->resetValidation();
    }

    public function toggleWithdrawalRequests(): void
    {
        $this->authorizePermission('finance.settings.manage');
        $this->withdrawal_requests_enabled = ! $this->withdrawal_requests_enabled;
        AppSetting::storeValue('finance', 'withdrawal_requests_enabled', $this->withdrawal_requests_enabled, 'boolean');
    }

    public function deleteCashBox(int $cashBoxId): void
    {
        $this->authorizePermission('finance.cash-box.manage');

        $cashBox = FinanceCashBox::query()->findOrFail($cashBoxId);

        if (FinanceTransaction::query()->where('cash_box_id', $cashBox->id)->exists()) {
            $this->addError('cashBoxDelete', 'This fund cannot be deleted while ledger transactions use it.');

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
        $this->currency_decimal_places = (string) ($currency->decimal_places ?? 2);
        $this->currency_name = $currency->name;
        $this->currency_symbol = $currency->symbol ?? '';
        $this->currency_is_active = $currency->is_active;
        $this->currency_show_in_dropdowns = $currency->show_in_dropdowns;
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
        $this->finance_category_type = $category->categoryType();
        $this->finance_category_mode = $category->categoryMode();
        $this->finance_category_is_active = $category->is_active;
        $this->finance_category_is_donation = $category->is_donation;
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
        ]);

        if (! $validated['cash_box_is_active'] && $this->cash_box_editing_id && FinanceTransaction::query()
            ->where('cash_box_id', $this->cash_box_editing_id)
            ->selectRaw('currency_id, SUM(signed_amount) as balance')
            ->groupBy('currency_id')
            ->havingRaw('ABS(SUM(signed_amount)) > 0.009')
            ->exists()) {
            $this->addError('cash_box_is_active', 'A fund with a non-zero balance cannot be deactivated.');

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
                    $this->addError('cash_box_currency_ids', 'A currency with a non-zero balance cannot be removed from this fund.');

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
            'currency_decimal_places' => ['required', 'integer', 'min:0', 'max:6'],
            'currency_is_active' => ['boolean'],
            'currency_show_in_dropdowns' => ['boolean'],
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

        $oldRateToBase = (float) ($current?->rate_to_base ?? 0);
        $currency = FinanceCurrency::query()->updateOrCreate(
            ['id' => $this->currency_editing_id],
            [
                'code' => strtoupper($validated['currency_code']),
                'decimal_places' => (int) $validated['currency_decimal_places'],
                'is_active' => $validated['currency_is_active'],
                'show_in_dropdowns' => $validated['currency_show_in_dropdowns'],
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

        if ($current && $oldRateToBase > 0) {
            app(FinanceService::class)->preserveReferencedCurrencyQuotes($currency->fresh(), $oldRateToBase);
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
            'finance_category_is_donation' => ['boolean'],
            'finance_category_name' => ['required', 'string', 'max:255'],
            'finance_category_type' => ['required', Rule::in(FinanceCategory::TYPES)],
            'finance_category_mode' => ['required', Rule::in(FinanceCategory::modesForType($this->finance_category_type))],
        ]);

        FinanceCategory::query()->updateOrCreate(
            ['id' => $this->finance_category_editing_id],
            [
                'code' => $validated['finance_category_code'],
                'is_active' => $validated['finance_category_is_active'],
                'is_donation' => $validated['finance_category_type'] === 'income' && $validated['finance_category_mode'] === 'donation',
                'name' => $validated['finance_category_name'],
                'type' => FinanceCategory::storageType($validated['finance_category_type'], $validated['finance_category_mode']),
                'mode' => $validated['finance_category_mode'],
            ],
        );

        $this->cancelFinanceCategory();
        session()->flash('status', 'Finance category saved.');
    }

    public function updatedFinanceCategoryType(): void
    {
        $allowedModes = FinanceCategory::modesForType($this->finance_category_type);
        if (! in_array($this->finance_category_mode, $allowedModes, true)) {
            $this->finance_category_mode = $allowedModes[0];
        }
    }

    public function savePullKind(): void
    {
        $this->authorizePermission('finance.settings.manage');

        $validated = $this->validate([
            'pull_kind_code' => ['required', 'string', 'max:50', Rule::unique('finance_categories', 'code')->ignore($this->pull_kind_editing_id)],
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
            'transaction_prefix' => ['required', 'string', 'max:20'],
            'pull_request_prefix' => ['required', 'string', 'max:20'],
            'expense_request_prefix' => ['required', 'string', 'max:20'],
            'revenue_request_prefix' => ['required', 'string', 'max:20'],
            'exchange_prefix' => ['required', 'string', 'max:20'],
            'transfer_prefix' => ['required', 'string', 'max:20'],
            'report_prefix' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9 -]+$/'],
            'request_terms' => ['nullable', 'string'],
            'default_cash_box_id' => ['nullable', 'integer', Rule::exists('finance_cash_boxes', 'id')->where('is_active', true)],
            'default_pull_request_kind_id' => ['nullable', 'integer', Rule::exists('finance_categories', 'id')->where('type', 'expense')->where('is_active', true)],
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
            'withdrawal_requests_enabled' => ['boolean'],
        ]);

        AppSetting::storeValue('finance', 'invoice_prefix', $this->normalizedFinancePrefix($validated['invoice_prefix'], 'INV'));
        AppSetting::storeValue('finance', 'transaction_prefix', $this->normalizedFinancePrefix($validated['transaction_prefix'], 'TX'));
        AppSetting::storeValue('finance', 'pull_request_prefix', $this->normalizedFinancePrefix($validated['pull_request_prefix'], 'PUL'));
        AppSetting::storeValue('finance', 'expense_request_prefix', $this->normalizedFinancePrefix($validated['expense_request_prefix'], 'EXP'));
        AppSetting::storeValue('finance', 'revenue_request_prefix', $this->normalizedFinancePrefix($validated['revenue_request_prefix'], 'REV'));
        AppSetting::storeValue('finance', 'exchange_prefix', $this->normalizedFinancePrefix($validated['exchange_prefix'], 'EXC'));
        AppSetting::storeValue('finance', 'transfer_prefix', $this->normalizedFinancePrefix($validated['transfer_prefix'], 'TRSF'));
        AppSetting::storeValue('finance', 'report_prefix', $this->normalizedFinancePrefix($validated['report_prefix'], 'FINR'));
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
        AppSetting::storeValue('finance', 'withdrawal_requests_enabled', $validated['withdrawal_requests_enabled'], 'boolean');

        session()->flash('status', __('settings.finance.messages.settings_saved'));
        $this->showFinanceSettingsModal = false;
    }

    public function findTransaction(): void
    {
        $this->authorizePermission('finance.entries.update');
        $this->validate(['transaction_lookup_no' => ['required', 'string', 'max:100']]);
        $lookup = trim($this->transaction_lookup_no);
        $transaction = FinanceTransaction::withTrashed()
            ->where(function ($query) use ($lookup) {
                $query->where('transaction_no', $lookup)
                    ->orWhere('special_transaction_no', $lookup)
                    ->orWhereHas('financeRequest', fn ($requestQuery) => $requestQuery
                        ->where('request_no', $lookup)
                        ->orWhere('expense_no', $lookup));
            })
            ->orderBy('id')
            ->first();

        if (! $transaction) {
            $this->addError('transaction_lookup_no', __('finance.validation.transaction_not_found'));
            return;
        }

        $transaction->loadMissing('financeRequest');
        $requestCategoryId = $transaction->financeRequest?->finance_category_id;
        if (! $requestCategoryId && $transaction->source_type === FinanceRequest::class && $transaction->source_id) {
            $requestCategoryId = FinanceRequest::query()->whereKey($transaction->source_id)->value('finance_category_id');
        }

        $this->maintaining_transaction_id = $transaction->id;
        $this->maintaining_transaction_deleted = $transaction->trashed();
        $this->maint_transaction_date = $transaction->transaction_date?->toDateString() ?: '';
        $this->maint_cash_box_id = $transaction->cash_box_id;
        $this->maint_currency_id = $transaction->currency_id;
        $this->maint_category_id = $transaction->finance_category_id ?: $requestCategoryId;
        $this->maint_type = $transaction->type;
        $this->maint_type_locked = in_array($transaction->source_type, [\App\Models\FinanceCurrencyExchange::class, \App\Models\FinanceCashBoxTransfer::class], true);
        $this->maint_direction = $transaction->direction;
        $this->maint_amount = $this->formatFinanceNumberForInput($transaction->amount);
        $this->maint_description = $transaction->description ?: '';
        $this->maint_special_transaction_no = $transaction->special_transaction_no ?: '';
        $this->maint_entered_by = $transaction->entered_by;
        $this->maint_delete_reason = '';
        $this->resetValidation();
    }

    public function saveTransactionMaintenance(): void
    {
        $this->authorizePermission('finance.entries.update');
        $this->normalizeFinanceNumberProperty('maint_amount');
        $validated = $this->validate([
            'maint_amount' => ['required', 'numeric', 'gt:0'],
            'maint_cash_box_id' => ['required', 'exists:finance_cash_boxes,id'],
            'maint_category_id' => ['nullable', 'exists:finance_categories,id'],
            'maint_currency_id' => ['required', 'exists:finance_currencies,id'],
            'maint_description' => ['nullable', 'string', 'max:2000'],
            'maint_direction' => ['required', 'in:in,out'],
            'maint_entered_by' => ['nullable', 'exists:users,id'],
            'maint_transaction_date' => ['required', 'date'],
            'maint_type' => ['required', Rule::in(['income', 'expense', 'return', 'exchange', 'transfer'])],
        ]);
        $transaction = FinanceTransaction::withTrashed()->findOrFail($this->maintaining_transaction_id);
        abort_if($transaction->trashed(), 422);
        if (in_array($transaction->source_type, [\App\Models\FinanceCurrencyExchange::class, \App\Models\FinanceCashBoxTransfer::class], true)) {
            $validated['maint_type'] = $transaction->source_type === \App\Models\FinanceCurrencyExchange::class ? 'exchange' : 'transfer';
        }
        app(FinanceService::class)->updateTransaction($transaction, [
            'amount' => $validated['maint_amount'],
            'cash_box_id' => $validated['maint_cash_box_id'],
            'currency_id' => $validated['maint_currency_id'],
            'finance_category_id' => $validated['maint_category_id'],
            'description' => $validated['maint_description'],
            'direction' => $validated['maint_direction'],
            'entered_by' => $validated['maint_entered_by'],
            'special_transaction_no' => $transaction->special_transaction_no,
            'transaction_date' => $validated['maint_transaction_date'],
            'type' => $validated['maint_type'],
        ], auth()->user());
        $this->maintaining_transaction_id = null;
        $this->maintaining_transaction_deleted = false;
        $this->transaction_lookup_no = '';
        $this->maint_special_transaction_no = '';
        session()->flash('status', __('finance.messages.transaction_updated'));
    }

    public function deleteTransactionMaintenance(): void
    {
        $this->authorizePermission('finance.entries.delete');
        $this->validate(['maint_delete_reason' => ['required', 'string', 'max:2000']]);
        $transaction = FinanceTransaction::query()->findOrFail($this->maintaining_transaction_id);
        app(FinanceService::class)->deleteTransactionRecord($transaction, auth()->user(), $this->maint_delete_reason);
        $this->maintaining_transaction_id = null;
        $this->maintaining_transaction_deleted = false;
        session()->flash('status', __('finance.messages.transaction_deleted'));
    }

    public function deleteGeneratedReport(): void
    {
        $this->authorizePermission('finance.settings.manage');

        $validated = $this->validate([
            'report_lookup_no' => ['required', 'string', 'max:50'],
        ]);

        if (! FinanceGeneratedReport::storageIsReady()) {
            $this->addError('report_lookup_no', __('finance.reports.generated_reports_unavailable'));

            return;
        }

        $lookup = trim($validated['report_lookup_no']);
        $generatedReport = FinanceGeneratedReport::query()
            ->where('report_type', 'ledger')
            ->where('report_data->original_report_number', $lookup)
            ->first();

        if (! $generatedReport && preg_match('/([1-9]\d*)$/', $lookup, $matches)) {
            $generatedReport = FinanceGeneratedReport::query()
                ->where('report_type', 'ledger')
                ->find((int) $matches[1]);
        }

        if (! $generatedReport) {
            $this->addError('report_lookup_no', __('finance.reports.saved_report_not_found'));

            return;
        }

        $pdfPath = FinanceGeneratedReport::pdfStorageIsReady()
            ? (string) ($generatedReport->pdf_path ?? '')
            : '';

        if ($pdfPath !== '') {
            Storage::disk('local')->delete($pdfPath);
        }

        $generatedReport->delete();
        $this->report_lookup_no = '';
        session()->flash('status', __('finance.reports.saved_report_deleted'));
    }

    public function openLegacyReportModal(): void
    {
        $this->authorizePermission('finance.settings.manage');
        abort_if((bool) AppSetting::groupValues('finance')->get('legacy_report_import_finished'), 403);
        $this->showLegacyReportModal = true;
    }

    public function closeLegacyReportModal(): void
    {
        $this->reset('legacy_report_pdf', 'legacy_report_number', 'legacy_report_date_from', 'legacy_report_date_to', 'legacy_report_cash_box', 'legacy_report_currency', 'legacy_report_generated_at');
        $this->legacy_report_period_mode = 'quarter';
        $this->legacy_report_year = (int) now()->year;
        $this->legacy_report_quarter = (string) now()->quarter;
        $this->showLegacyReportModal = false;
        $this->resetValidation();
    }

    public function importLegacyReport(): void
    {
        $this->authorizePermission('finance.settings.manage');
        abort_if((bool) AppSetting::groupValues('finance')->get('legacy_report_import_finished'), 403);
        abort_unless(FinanceGeneratedReport::pdfStorageIsReady(), 422);

        $validated = $this->validate([
            'legacy_report_pdf' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'legacy_report_number' => ['required', 'string', 'max:50'],
            'legacy_report_period_mode' => ['required', Rule::in(['quarter', 'custom'])],
            'legacy_report_year' => ['required_if:legacy_report_period_mode,quarter', 'integer', 'between:1900,2100'],
            'legacy_report_quarter' => ['required_if:legacy_report_period_mode,quarter', Rule::in(['1', '2', '3', '4'])],
            'legacy_report_date_from' => ['required_if:legacy_report_period_mode,custom', 'nullable', 'date'],
            'legacy_report_date_to' => ['required_if:legacy_report_period_mode,custom', 'nullable', 'date', 'after_or_equal:legacy_report_date_from'],
            'legacy_report_cash_box' => ['required', 'string', 'max:255'],
            'legacy_report_currency' => ['required', 'string', 'max:50'],
            'legacy_report_generated_at' => ['required', 'date'],
        ]);

        if ($validated['legacy_report_period_mode'] === 'quarter') {
            $quarterStart = \Illuminate\Support\Carbon::create((int) $validated['legacy_report_year'], (((int) $validated['legacy_report_quarter'] - 1) * 3) + 1, 1);
            $dateFrom = $quarterStart->toDateString();
            $dateTo = $quarterStart->copy()->endOfQuarter()->toDateString();
        } else {
            $dateFrom = $validated['legacy_report_date_from'];
            $dateTo = $validated['legacy_report_date_to'];
        }

        $path = $validated['legacy_report_pdf']->store('finance/generated-reports/imported', 'local');
        $report = FinanceGeneratedReport::query()->create([
            'report_type' => 'ledger',
            'filters' => [
                'cash_box_name' => $validated['legacy_report_cash_box'],
                'currency_code' => $validated['legacy_report_currency'],
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'period_mode' => $validated['legacy_report_period_mode'],
                'year' => $validated['legacy_report_period_mode'] === 'quarter' ? (int) $validated['legacy_report_year'] : null,
                'quarter' => $validated['legacy_report_period_mode'] === 'quarter' ? (int) $validated['legacy_report_quarter'] : null,
            ],
            'report_data' => [
                'cash_box' => ['name' => $validated['legacy_report_cash_box']],
                'currency' => ['code' => $validated['legacy_report_currency']],
                'end' => $dateTo,
                'imported_legacy' => true,
                'original_report_number' => $validated['legacy_report_number'],
                'start' => $dateFrom,
            ],
            'pdf_path' => $path,
            'generated_by' => auth()->id(),
        ]);
        $report->forceFill(['created_at' => $validated['legacy_report_generated_at'], 'updated_at' => $validated['legacy_report_generated_at']])->save();

        $this->closeLegacyReportModal();
        session()->flash('status', __('finance.reports.legacy_report_imported'));
    }

    public function finishLegacyReportImport(): void
    {
        $this->authorizePermission('finance.settings.manage');
        AppSetting::storeValue('finance', 'legacy_report_import_finished', true, 'boolean');
        $this->closeLegacyReportModal();
        session()->flash('status', __('finance.reports.legacy_report_import_finished'));
    }

    public function deleteWithdrawalRequest(): void
    {
        $this->authorizePermission('finance.settings.manage');
        abort_if((bool) AppSetting::groupValues('finance')->get('withdrawal_request_cleanup_finished'), 403);
        $this->resetValidation('withdrawal_cleanup_request_no');

        $validated = $this->validate(
            ['withdrawal_cleanup_request_no' => ['required', 'string', 'max:50']],
            [],
            ['withdrawal_cleanup_request_no' => __('finance.fields.request_no')],
        );

        $lookup = trim($validated['withdrawal_cleanup_request_no']);
        $request = FinanceRequest::query()
            ->where('type', FinanceRequest::TYPE_PULL)
            ->whereRaw('LOWER(request_no) = ?', [strtolower($lookup)])
            ->first();

        if (! $request) {
            $this->addError('withdrawal_cleanup_request_no', __('finance.messages.withdrawal_cleanup_not_found'));

            return;
        }

        try {
            $deleted = app(FinanceService::class)->deleteWithdrawalRequest(
                $request,
                auth()->user(),
                __('finance.descriptions.withdrawal_cleanup'),
            );
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('withdrawal_cleanup_request_no', __('finance.messages.withdrawal_cleanup_failed'));

            return;
        }

        if (! $deleted) {
            $this->addError('withdrawal_cleanup_request_no', __('finance.messages.withdrawal_cleanup_not_found'));

            return;
        }

        $this->withdrawal_cleanup_request_no = '';
        session()->flash('status', __('finance.messages.withdrawal_cleanup_deleted', ['request' => $request->request_no]));
    }

    public function finishWithdrawalRequestCleanup(): void
    {
        $this->authorizePermission('finance.settings.manage');
        AppSetting::storeValue('finance', 'withdrawal_request_cleanup_finished', true, 'boolean');
        session()->flash('status', __('finance.messages.withdrawal_cleanup_finished'));
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
            'localCurrency' => app(FinanceService::class)->localCurrency(),
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
            'financeCategories' => FinanceCategory::query()->orderBy('type')->orderBy('mode')->orderBy('name')->get(),
            'financeRequestPrintTemplates' => $this->financeRequestPrintTemplates(),
            'paymentMethods' => PaymentMethod::query()->orderBy('name')->get(),
            'pullRequestKinds' => FinancePullRequestKind::query()->orderBy('mode')->orderBy('name')->get(),
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(),
            'legacyReportImportEnabled' => ! (bool) AppSetting::groupValues('finance')->get('legacy_report_import_finished'),
            'withdrawalRequestCleanupEnabled' => ! (bool) AppSetting::groupValues('finance')->get('withdrawal_request_cleanup_finished'),
        ];
    }

    public function cancelCashBox(): void
    {
        $this->cash_box_editing_id = null;
        $this->cash_box_name = '';
        $this->cash_box_code = '';
        $this->cash_box_is_active = true;
        $this->cash_box_notes = '';
        $this->cash_box_currency_ids = FinanceCurrency::query()->where('is_active', true)->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->showCashBoxModal = false;
        $this->resetValidation();
    }

    public function cancelCurrency(): void
    {
        $this->currency_editing_id = null;
        $this->currency_code = '';
        $this->currency_decimal_places = '2';
        $this->currency_name = '';
        $this->currency_symbol = '';
        $this->currency_rate_input = '1';
        $this->currency_rate_reference_currency_id = app(FinanceService::class)->baseCurrency()->id;
        $this->currency_is_active = true;
        $this->currency_show_in_dropdowns = true;
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
        $this->finance_category_mode = 'count';
        $this->finance_category_is_active = true;
        $this->finance_category_is_donation = false;
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

        $this->invoice_prefix = $this->normalizedFinancePrefix((string) ($settings->get('invoice_prefix') ?: 'INV'), 'INV');
        $this->transaction_prefix = $this->normalizedFinancePrefix((string) ($settings->get('transaction_prefix') ?: 'TX'), 'TX');
        $this->pull_request_prefix = $this->normalizedFinancePrefix((string) ($settings->get('pull_request_prefix') ?: 'PUL'), 'PUL');
        $this->expense_request_prefix = $this->normalizedFinancePrefix((string) ($settings->get('expense_request_prefix') ?: 'EXP'), 'EXP');
        $this->revenue_request_prefix = $this->normalizedFinancePrefix((string) ($settings->get('revenue_request_prefix') ?: 'REV'), 'REV');
        $this->exchange_prefix = $this->normalizedFinancePrefix((string) ($settings->get('exchange_prefix') ?: 'EXC'), 'EXC');
        $this->transfer_prefix = $this->normalizedFinancePrefix((string) ($settings->get('transfer_prefix') ?: 'TRSF'), 'TRSF');
        $this->report_prefix = $this->normalizedFinancePrefix((string) ($settings->get('report_prefix') ?: 'FINR'), 'FINR');
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
        $this->withdrawal_requests_enabled = $settings->has('withdrawal_requests_enabled') ? (bool) $settings->get('withdrawal_requests_enabled') : true;
    }

    protected function normalizedFinancePrefix(string $value, string $fallback): string
    {
        $normalized = strtoupper(trim((string) preg_replace('/[\s-]+/u', '', $value)));

        return $normalized !== '' ? $normalized : $fallback;
    }

    protected function financeRequestPrintTemplates()
    {
        return PrintTemplate::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (PrintTemplate $template) => collect(app(PrintTemplateDataSourceService::class)->normalize($template->data_sources ?? []))
                ->contains(fn (array $source) => in_array($source['entity'], ['finance_request', 'revenue'], true) && $source['mode'] === 'single'))
            ->values();
    }
}; ?>

<div class="page-stack settings-admin-page">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.settings') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('finance.settings.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('finance.settings.subtitle') }}</p>
    </section>

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section id="finance-defaults" class="surface-panel p-5 lg:p-6" data-finance-settings-summary>
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">{{ __('finance.settings.finance_defaults') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('finance.settings.defaults_subtitle') }}</p>
            </div>
            <div class="admin-toolbar__actions">
                <x-edit-action-button wire:click="openFinanceSettingsModal" :label="__('finance.actions.edit')" data-finance-settings-edit-action />
            </div>
        </div>
        <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3" data-finance-settings-primary-row>
            <div class="flex items-center rounded-2xl border border-white/10 bg-white/5 p-4">
                <div class="flex w-full items-center justify-between gap-3">
                    <div class="text-xs font-semibold text-neutral-400">{{ __('finance.settings.withdrawal_requests_enabled') }}</div>
                    <button
                        type="button"
                        wire:click="toggleWithdrawalRequests"
                        wire:loading.attr="disabled"
                        wire:target="toggleWithdrawalRequests"
                        aria-pressed="{{ $withdrawal_requests_enabled ? 'true' : 'false' }}"
                        aria-label="{{ __('finance.settings.withdrawal_requests_enabled') }}: {{ $withdrawal_requests_enabled ? __('finance.common.active') : __('finance.common.inactive') }}"
                        class="status-chip cursor-pointer transition hover:brightness-125 disabled:cursor-wait disabled:opacity-60 {{ $withdrawal_requests_enabled ? 'status-chip--emerald' : 'status-chip--slate' }}"
                        data-withdrawal-requests-status
                    >
                        {{ $withdrawal_requests_enabled ? __('finance.common.active') : __('finance.common.inactive') }}
                    </button>
                </div>
            </div>

            @foreach ([
                [__('finance.settings.default_cash_box'), $defaultCashBoxes->firstWhere('id', (int) $default_cash_box_id)?->name ?: __('finance.settings.default_auto')],
                [__('finance.common.local_currency'), $localCurrency->code],
            ] as [$settingLabel, $settingValue])
                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                    <div class="text-xs font-semibold text-neutral-400">{{ $settingLabel }}</div>
                    <div class="mt-2 truncate text-sm font-semibold text-white">{{ $settingValue }}</div>
                </div>
            @endforeach
        </div>

        <div class="mt-4 overflow-x-auto" data-finance-settings-prefix-row>
            <div class="grid min-w-[72rem] grid-cols-8 gap-3">
                @foreach ([
                    [__('settings.finance.fields.invoice_prefix'), $invoice_prefix],
                    [__('settings.finance.fields.transaction_prefix'), $transaction_prefix],
                    [__('settings.finance.fields.pull_request_prefix'), $pull_request_prefix],
                    [__('settings.finance.fields.expense_request_prefix'), $expense_request_prefix],
                    [__('settings.finance.fields.revenue_request_prefix'), $revenue_request_prefix],
                    [__('settings.finance.fields.exchange_prefix'), $exchange_prefix],
                    [__('finance.settings.transfer_prefix'), $transfer_prefix],
                    [__('finance.settings.report_prefix'), $report_prefix],
                ] as [$prefixLabel, $prefixValue])
                    <div class="min-w-0 rounded-2xl border border-white/10 bg-white/5 p-4">
                        <div class="whitespace-nowrap text-xs font-semibold text-neutral-400">{{ $prefixLabel }}</div>
                        <div class="mt-2 truncate text-sm font-semibold text-white" dir="ltr" data-finance-prefix-value>{{ $prefixValue }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <x-admin.modal :show="$showFinanceSettingsModal" :title="__('finance.settings.finance_defaults')" close-method="closeFinanceSettingsModal" max-width="5xl">
        <x-slot:header-actions>
            <button type="submit" form="finance-settings-form" class="admin-modal__close" title="{{ __('settings.finance.actions.save_settings') }}" aria-label="{{ __('settings.finance.actions.save_settings') }}" data-finance-settings-save-icon>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 3.75h11.25L19.5 7v13.25H5V3.75Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 3.75v5.5h8v-5.5M8.25 20.25v-6.5h8v6.5" />
                </svg>
            </button>
        </x-slot:header-actions>
        <form id="finance-settings-form" wire:submit="saveFinanceSettings" class="grid gap-5">
            <div>
                <div class="mb-3">
                    <div class="admin-section-card__title">{{ __('finance.settings.number_prefixes') }}</div>
                    <p class="mt-1 text-sm text-neutral-400">{{ __('finance.settings.number_prefixes_subtitle') }}</p>
                </div>
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('settings.finance.fields.invoice_prefix') }}</label>
                        <input wire:model="invoice_prefix" type="text" class="w-full rounded-xl px-4 py-3 text-sm uppercase">
                        @error('invoice_prefix') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('settings.finance.fields.transaction_prefix') }}</label>
                        <input wire:model="transaction_prefix" type="text" class="w-full rounded-xl px-4 py-3 text-sm uppercase">
                        @error('transaction_prefix') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('settings.finance.fields.pull_request_prefix') }}</label>
                        <input wire:model="pull_request_prefix" type="text" class="w-full rounded-xl px-4 py-3 text-sm uppercase">
                        @error('pull_request_prefix') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('settings.finance.fields.expense_request_prefix') }}</label>
                        <input wire:model="expense_request_prefix" type="text" class="w-full rounded-xl px-4 py-3 text-sm uppercase">
                        @error('expense_request_prefix') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('settings.finance.fields.revenue_request_prefix') }}</label>
                        <input wire:model="revenue_request_prefix" type="text" class="w-full rounded-xl px-4 py-3 text-sm uppercase">
                        @error('revenue_request_prefix') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('settings.finance.fields.exchange_prefix') }}</label>
                        <input wire:model="exchange_prefix" type="text" class="w-full rounded-xl px-4 py-3 text-sm uppercase">
                        @error('exchange_prefix') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('finance.settings.transfer_prefix') }}</label>
                        <input wire:model="transfer_prefix" type="text" class="w-full rounded-xl px-4 py-3 text-sm uppercase">
                        @error('transfer_prefix') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium">{{ __('finance.settings.report_prefix') }}</label>
                        <input wire:model="report_prefix" type="text" class="w-full rounded-xl px-4 py-3 text-sm uppercase">
                        @error('report_prefix') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>

            <div class="grid gap-4">
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
                <div class="mb-3">
                    <div class="admin-section-card__title">{{ __('finance.settings.features') }}</div>
                </div>
                <label class="flex items-center justify-between gap-4 rounded-xl border border-white/10 px-4 py-3 text-sm">
                    <span><strong class="block text-white">{{ __('finance.settings.withdrawal_requests_enabled') }}</strong><small class="text-neutral-400">{{ __('finance.settings.withdrawal_requests_enabled_help') }}</small></span>
                    <input wire:model="withdrawal_requests_enabled" type="checkbox" class="rounded">
                </label>
            </div>

        </form>
    </x-admin.modal>

    <section id="finance-currencies" class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700" data-settings-table>
        <div class="admin-grid-meta admin-grid-meta--controls">
            <div>
                <div class="admin-grid-meta__title">{{ __('finance.settings.currencies') }}</div>
                <div class="admin-grid-meta__summary">{{ __('finance.settings.currencies_subtitle') }}</div>
            </div>
            @can('finance.currencies.manage')
                <x-add-action-button wire:click="openCurrencyModal" :label="__('finance.actions.create_currency')" />
            @endcan
        </div>
            @error('currencyDelete') <div class="mx-5 mb-4 rounded-2xl border border-red-500/25 bg-red-500/10 px-3 py-2 text-sm text-red-200">{{ $message }}</div> @enderror
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                    <thead class="bg-neutral-50 dark:bg-neutral-900/60"><tr><th class="px-5 py-3 text-left">{{ __('finance.common.currency') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.exchange_rate') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.flags') }}</th><th class="admin-actions-column px-5 py-3 text-center">{{ __('finance.actions.actions') }}</th></tr></thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                        @foreach ($currencies as $currency)
                            <tr>
                                <td class="px-5 py-3"><div class="font-medium text-white">{{ $currency->code }} {{ $currency->symbol ? '('.$currency->symbol.')' : '' }}</div><div class="text-xs text-neutral-500">{{ $currency->name }}</div></td>
                                <td class="px-5 py-3">@if ($currency->is_base)<span class="text-neutral-500">-</span>@else<bdi dir="ltr" class="inline-block">{{ app(FinanceService::class)->currencyRateLabel($currency, $baseCurrency) }}</bdi>@endif</td>
                                <td class="px-5 py-3">
                                    <div class="flex flex-wrap gap-2">
                                        @if ($currency->is_local)<span class="status-chip status-chip--emerald">{{ __('finance.common.local') }}</span>@endif
                                        @if ($currency->is_base)<span class="status-chip status-chip--slate">{{ __('finance.common.base') }}</span>@endif
                                        @unless($currency->is_local || $currency->is_base)<span class="status-chip {{ $currency->is_active ? 'status-chip--emerald' : 'status-chip--rose' }}">{{ $currency->is_active ? __('finance.common.active') : __('finance.common.inactive') }}</span>@endunless
                                    </div>
                                </td>
                                <td class="px-5 py-3"><div class="admin-action-cluster admin-action-cluster--end"><x-edit-action-button wire:click="editCurrency({{ $currency->id }})" :label="__('finance.actions.edit')" data-finance-currency-edit-action /></div></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
    </section>

    <section id="finance-cash-boxes" class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700" data-settings-table>
        <div class="admin-grid-meta admin-grid-meta--controls">
            <div>
                <div class="admin-grid-meta__title">{{ __('finance.settings.cash_boxes') }}</div>
                <div class="admin-grid-meta__summary">{{ __('finance.settings.cash_boxes_subtitle') }}</div>
            </div>
            @can('finance.cash-box.manage')
                <x-add-action-button wire:click="openCashBoxModal" :label="__('finance.actions.create_box')" />
            @endcan
        </div>
            @error('cashBoxDelete') <div class="mx-5 mb-4 rounded-2xl border border-red-500/25 bg-red-500/10 px-3 py-2 text-sm text-red-200">{{ $message }}</div> @enderror
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                    <thead class="bg-neutral-50 dark:bg-neutral-900/60"><tr><th class="px-5 py-3 text-left">{{ __('finance.fields.cash_box') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.supported_currencies') }}</th><th class="px-5 py-3 text-left">{{ __('finance.common.status') }}</th><th class="admin-actions-column px-5 py-3 text-center">{{ __('finance.actions.actions') }}</th></tr></thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">@foreach ($cashBoxes as $box)<tr><td class="px-5 py-3"><div class="font-medium text-white">{{ $box->name }}</div><div class="text-xs text-neutral-500">{{ $box->code }}</div></td><td class="px-5 py-3">{{ $box->currencies->pluck('code')->implode(', ') ?: '-' }}</td><td class="px-5 py-3">{{ $box->is_active ? __('finance.common.active') : __('finance.common.inactive') }}</td><td class="px-5 py-3"><div class="admin-action-cluster admin-action-cluster--end"><x-edit-action-button wire:click="editCashBox({{ $box->id }})" :label="__('finance.actions.edit')" data-finance-cash-box-edit-action /></div></td></tr>@endforeach</tbody>
                </table>
            </div>
    </section>

    <section id="finance-categories" class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700" data-settings-table>
        <div class="admin-grid-meta admin-grid-meta--controls">
            <div>
                <div class="admin-grid-meta__title">{{ __('finance.settings.finance_categories') }}</div>
                <div class="admin-grid-meta__summary">{{ __('finance.settings.finance_categories_subtitle') }}</div>
            </div>
            @can('finance.categories.manage')
                <x-add-action-button wire:click="openFinanceCategoryModal" :label="__('finance.actions.create_category')" />
            @endcan
        </div>
            @error('financeCategoryDelete') <div class="mx-5 mb-4 rounded-2xl border border-red-500/25 bg-red-500/10 px-3 py-2 text-sm text-red-200">{{ $message }}</div> @enderror
            <div class="overflow-x-auto"><table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700"><thead class="bg-neutral-50 dark:bg-neutral-900/60"><tr><th class="px-5 py-3 text-left">{{ __('finance.fields.name') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.type') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.mode') }}</th><th class="px-5 py-3 text-left">{{ __('finance.fields.state') }}</th><th class="admin-actions-column px-5 py-3 text-center">{{ __('finance.actions.actions') }}</th></tr></thead><tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">@foreach ($financeCategories as $category)<tr><td class="px-5 py-3"><div class="font-medium text-white">{{ $category->name }}</div><div class="text-xs text-neutral-500">{{ $category->code }}</div></td><td class="px-5 py-3">{{ __('finance.category_types.'.$category->categoryType()) }}</td><td class="px-5 py-3">{{ __('finance.category_modes.'.$category->categoryMode()) }}</td><td class="px-5 py-3">{{ $category->is_active ? __('finance.common.active') : __('finance.common.inactive') }}</td><td class="px-5 py-3"><div class="admin-action-cluster admin-action-cluster--end"><x-edit-action-button wire:click="editFinanceCategory({{ $category->id }})" :label="__('finance.actions.edit')" data-finance-category-edit-action /></div></td></tr>@endforeach</tbody></table></div>
    </section>

    @if (false)
    <section id="finance-legacy" class="surface-table">
        <div class="admin-grid-meta">
            <div><div class="admin-grid-meta__title">{{ __('settings.finance.sections.payment_method.table') }}</div></div>
            @can('finance.settings.manage')
                <x-add-action-button wire:click="openPaymentMethodModal" :label="__('settings.finance.actions.create_method')" />
            @endcan
        </div>
        @error('paymentMethodDelete') <div class="mx-5 mb-4 rounded-2xl border border-red-500/25 bg-red-500/10 px-3 py-2 text-sm text-red-200">{{ $message }}</div> @enderror
        <div class="overflow-x-auto"><table class="text-sm"><thead><tr><th class="px-5 py-3 text-left">{{ __('settings.finance.table.method') }}</th><th class="px-5 py-3 text-left">{{ __('settings.finance.table.state') }}</th><th class="admin-actions-column px-5 py-3 text-center">{{ __('settings.finance.table.actions') }}</th></tr></thead><tbody class="divide-y divide-white/6">@foreach ($paymentMethods as $method)<tr><td class="px-5 py-3"><div class="font-medium text-white">{{ $method->name }}</div><div class="text-xs text-neutral-500">{{ $method->code }}</div></td><td class="px-5 py-3">{{ $method->is_active ? __('finance.common.active') : __('finance.common.inactive') }}</td><td class="px-5 py-3"><div class="admin-action-cluster admin-action-cluster--end"><x-edit-action-button wire:click="editPaymentMethod({{ $method->id }})" :label="__('finance.actions.edit')" data-finance-payment-method-edit-action /></div></td></tr>@endforeach</tbody></table></div>
    </section>
    @endif

    <section class="surface-panel p-5 lg:p-6">
        <div class="admin-toolbar"><div><div class="admin-toolbar__title">{{ __('finance.settings.transaction_maintenance') }}</div><p class="admin-toolbar__subtitle">{{ __('finance.settings.transaction_maintenance_help') }}</p></div></div>
        @php($maintainingInvoice = $maintaining_transaction_id ? \App\Models\FinanceTransaction::withTrashed()->with('financeRequest.invoice')->find($maintaining_transaction_id)?->financeRequest?->invoice : null)
        @if ($maintaining_transaction_id)
            <div class="mt-5 flex flex-col gap-3 sm:flex-row">
                <input wire:model="transaction_lookup_no" readonly class="transaction-maintenance-lookup min-w-0 flex-1 rounded-xl px-4 py-3 opacity-75">
                @unless ($maintaining_transaction_deleted)<button type="submit" form="transaction-maintenance-form" class="admin-icon-button admin-icon-button--accent transaction-maintenance-action-button" title="{{ __('crud.common.actions.save') }}" aria-label="{{ __('crud.common.actions.save') }}" data-transaction-maintenance-save-action><x-admin-action-icon name="save" /></button>@endunless
                @if ($maintainingInvoice && auth()->user()?->can('finance.expense-requests.review'))
                    <a href="{{ route('finance.expense-requests.index', ['edit_invoice' => $maintainingInvoice->id]) }}" wire:navigate class="admin-icon-button transaction-maintenance-action-button" title="{{ __('finance.actions.edit_invoice') }}" aria-label="{{ __('finance.actions.edit_invoice') }}" data-transaction-maintenance-receipt-action><x-admin-action-icon name="receipt" /></a>
                @endif
            </div>
        @else
            <form wire:submit="findTransaction" class="mt-5 flex flex-col gap-3 sm:flex-row"><input wire:model="transaction_lookup_no" placeholder="{{ __('finance.fields.transaction_lookup') }}" class="transaction-maintenance-lookup min-w-0 flex-1 rounded-xl px-4 py-3"> <button type="submit" class="admin-icon-button admin-icon-button--accent transaction-maintenance-action-button" title="{{ __('finance.actions.find') }}" aria-label="{{ __('finance.actions.find') }}" data-transaction-maintenance-search-action><x-admin-action-icon name="search" /></button></form>
        @endif
        @error('transaction_lookup_no')<div class="mt-2 text-sm text-red-400">{{ $message }}</div>@enderror
        @if ($maintaining_transaction_id)
            @if ($maintaining_transaction_deleted)
                <div class="mt-5 rounded-2xl border border-red-400/25 bg-red-500/10 px-4 py-3 text-sm text-red-100">{{ __('finance.statuses.deleted') }}</div>
            @endif
            <form id="transaction-maintenance-form" wire:submit="saveTransactionMaintenance" class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div><label class="mb-1 block text-sm">{{ __('finance.common.date') }}</label><input wire:model="maint_transaction_date" type="date" class="w-full rounded-xl px-4 py-3"></div>
                <div><label class="mb-1 block text-sm">{{ __('finance.fields.cash_box') }}</label><select wire:model="maint_cash_box_id" class="w-full rounded-xl px-4 py-3">@foreach ($cashBoxes as $fund)<option value="{{ $fund->id }}">{{ $fund->name }}</option>@endforeach</select></div>
                <div><label class="mb-1 block text-sm">{{ __('finance.fields.category') }}</label><select wire:model="maint_category_id" class="w-full rounded-xl px-4 py-3"><option value="">-</option>@foreach ($financeCategories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></div>
                <div><label class="mb-1 block text-sm">{{ __('finance.fields.type') }}</label><select wire:model="maint_type" @disabled($maint_type_locked) class="w-full rounded-xl px-4 py-3">@foreach ($maint_type_locked ? [$maint_type] : ['income', 'expense', 'return', 'exchange', 'transfer'] as $transactionType)<option value="{{ $transactionType }}">{{ app(\App\Services\FinanceService::class)->transactionTypeLabel($transactionType) }}</option>@endforeach</select></div>
                <div><label class="mb-1 block text-sm">{{ __('finance.fields.direction') }}</label><select wire:model="maint_direction" class="w-full rounded-xl px-4 py-3"><option value="in">{{ __('finance.options.in') }}</option><option value="out">{{ __('finance.options.out') }}</option></select></div>
                <div><label class="mb-1 block text-sm">{{ __('finance.fields.amount') }}</label><x-finance.amount-input amount-model="maint_amount" currency-model="maint_currency_id" :currencies="$currencies" :currency-live="false" /></div>
                <div><label class="mb-1 block text-sm">{{ __('finance.fields.user') }}</label><select wire:model="maint_entered_by" class="w-full rounded-xl px-4 py-3"><option value="">-</option>@foreach ($users as $user)<option value="{{ $user->id }}">{{ $user->name }}</option>@endforeach</select></div>
                <div class="md:col-span-2 xl:col-span-4"><label class="mb-1 block text-sm">{{ __('finance.common.description') }}</label><textarea wire:model="maint_description" rows="1" class="h-[3.125rem] w-full resize-none rounded-xl px-4 py-3"></textarea></div>
            </form>
            @unless ($maintaining_transaction_deleted)
                @can('finance.entries.delete')
                    <div class="mt-5 border-t border-white/10 pt-5"><label class="mb-1 block text-sm">{{ __('finance.fields.deletion_reason') }}</label><div class="flex flex-col gap-3 sm:flex-row"><textarea wire:model="maint_delete_reason" class="min-w-0 flex-1 rounded-xl px-4 py-3"></textarea><button wire:click="deleteTransactionMaintenance" wire:confirm="{{ __('finance.messages.transaction_delete_warning') }}" type="button" class="admin-icon-button admin-icon-button--danger transaction-maintenance-action-button self-center" title="{{ __('finance.actions.delete') }}" aria-label="{{ __('finance.actions.delete') }}" data-transaction-maintenance-delete-action><x-admin-action-icon name="delete" /></button></div>@error('maint_delete_reason')<div class="text-sm text-red-400">{{ $message }}</div>@enderror</div>
                @endcan
            @endunless
        @endif
    </section>

    <section id="finance-generated-reports" class="surface-panel p-5 lg:p-6">
        <div class="admin-toolbar">
            <div>
                <div class="admin-toolbar__title">{{ __('finance.settings.generated_report_maintenance') }}</div>
                <p class="admin-toolbar__subtitle">{{ __('finance.settings.generated_report_maintenance_help', ['prefix' => $report_prefix]) }}</p>
            </div>
        </div>
        <div class="mt-5 flex flex-col gap-3 sm:flex-row">
            <input wire:model="report_lookup_no" placeholder="{{ __('finance.settings.generated_report_placeholder') }}" class="generated-report-lookup min-w-0 flex-1 rounded-xl px-4 py-3 {{ app()->isLocale('ar') ? 'text-right' : 'text-left' }}" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}" data-generated-report-number>
            <button wire:click="deleteGeneratedReport" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" type="button" class="admin-icon-button admin-icon-button--danger generated-report-maintenance-action-button" title="{{ __('finance.reports.delete_saved_report') }}" aria-label="{{ __('finance.reports.delete_saved_report') }}" data-generated-report-delete-action><x-admin-action-icon name="delete" /></button>
            @if ($legacyReportImportEnabled)<x-add-action-button wire:click="openLegacyReportModal" :label="__('finance.reports.import_legacy_report')" class="generated-report-import-action-button" data-legacy-report-import-action />@endif
        </div>
        @error('report_lookup_no')<div class="mt-2 text-sm text-red-400">{{ $message }}</div>@enderror
    </section>

    @if ($withdrawalRequestCleanupEnabled)
        <section class="surface-panel border-red-400/20 p-5 lg:p-6" data-withdrawal-request-cleanup>
            <div class="admin-toolbar">
                <div>
                    <div class="admin-toolbar__title">{{ __('finance.settings.withdrawal_cleanup') }}</div>
                    <p class="admin-toolbar__subtitle">{{ __('finance.settings.withdrawal_cleanup_help') }}</p>
                </div>
            </div>
            <div class="mt-5">
                <label class="mb-1 block text-sm" for="withdrawal-cleanup-request-no">{{ __('finance.fields.request_no') }}</label>
                <div class="flex flex-col gap-3 sm:flex-row">
                    <input id="withdrawal-cleanup-request-no" wire:model="withdrawal_cleanup_request_no" type="text" class="min-w-0 flex-1 rounded-xl px-4 py-3" placeholder="{{ __('finance.settings.withdrawal_cleanup_placeholder', ['prefix' => $pull_request_prefix]) }}" dir="ltr" data-withdrawal-cleanup-request-number>
                    <button wire:click="deleteWithdrawalRequest" wire:confirm="{{ __('finance.settings.withdrawal_cleanup_confirm') }}" type="button" class="pill-link pill-link--danger">{{ __('finance.settings.delete_withdrawal_request') }}</button>
                    <button wire:click="finishWithdrawalRequestCleanup" wire:confirm="{{ __('finance.settings.withdrawal_cleanup_finish_confirm') }}" type="button" class="pill-link">{{ __('finance.settings.withdrawal_cleanup_finished_action') }}</button>
                </div>
                @error('withdrawal_cleanup_request_no')<div class="mt-2 text-sm text-red-400">{{ $message }}</div>@enderror
            </div>
        </section>
    @endif

    @if ($legacyReportImportEnabled)
        <x-admin.modal :show="$showLegacyReportModal" :title="__('finance.reports.import_legacy_report')" close-method="closeLegacyReportModal" max-width="3xl">
            <form wire:submit="importLegacyReport" class="grid gap-4 md:grid-cols-2">
                <div class="md:col-span-2"><label class="mb-1 block text-sm">{{ __('finance.reports.legacy_pdf') }}</label><input wire:model="legacy_report_pdf" type="file" accept="application/pdf" class="w-full rounded-xl px-4 py-3">@error('legacy_report_pdf')<div data-pdf-upload-error-for="legacy_report_pdf" class="mt-1 text-sm text-red-400">{{ $message }}</div>@enderror</div>
                <div><label class="mb-1 block text-sm">{{ __('finance.fields.report_no') }}</label><input wire:model="legacy_report_number" class="w-full rounded-xl px-4 py-3" dir="ltr">@error('legacy_report_number')<div class="mt-1 text-sm text-red-400">{{ $message }}</div>@enderror</div>
                <div><label class="mb-1 block text-sm">{{ __('finance.common.date') }}</label><input wire:model="legacy_report_generated_at" type="date" class="w-full rounded-xl px-4 py-3"></div>
                <div><label class="mb-1 block text-sm">{{ __('finance.fields.period') }}</label><select wire:model.live="legacy_report_period_mode" class="w-full rounded-xl px-4 py-3"><option value="quarter">{{ __('finance.reports.period_quarter') }}</option><option value="custom">{{ __('finance.reports.period_custom') }}</option></select></div>
                @if ($legacy_report_period_mode === 'quarter')
                    <div><label class="mb-1 block text-sm">{{ __('finance.fields.year') }}</label><input wire:model="legacy_report_year" type="number" min="1900" max="2100" class="w-full rounded-xl px-4 py-3"></div>
                    <div><label class="mb-1 block text-sm">{{ __('finance.fields.quarter') }}</label><select wire:model="legacy_report_quarter" class="w-full rounded-xl px-4 py-3">@for($quarter = 1; $quarter <= 4; $quarter++)<option value="{{ $quarter }}">Q{{ $quarter }}</option>@endfor</select></div>
                @else
                    <div><label class="mb-1 block text-sm">{{ __('finance.fields.from_date') }}</label><input wire:model="legacy_report_date_from" type="date" class="w-full rounded-xl px-4 py-3"></div>
                    <div><label class="mb-1 block text-sm">{{ __('finance.fields.to_date') }}</label><input wire:model="legacy_report_date_to" type="date" class="w-full rounded-xl px-4 py-3"></div>
                @endif
                <div><label class="mb-1 block text-sm">{{ __('finance.fields.cash_box') }}</label><input wire:model="legacy_report_cash_box" class="w-full rounded-xl px-4 py-3"></div>
                <div><label class="mb-1 block text-sm">{{ __('finance.common.currency') }}</label><input wire:model="legacy_report_currency" class="w-full rounded-xl px-4 py-3"></div>
                <div class="md:col-span-2 flex flex-wrap justify-end gap-3"><button type="button" wire:click="finishLegacyReportImport" wire:confirm="{{ __('finance.reports.finish_legacy_import_confirm') }}" class="pill-link pill-link--danger">{{ __('finance.reports.finish_uploading') }}</button><button class="pill-link pill-link--accent">{{ __('finance.reports.import_legacy_report') }}</button></div>
            </form>
        </x-admin.modal>
    @endif

    <x-admin.modal :show="$showCurrencyModal" :title="$currency_editing_id ? __('finance.actions.edit').' '.__('finance.common.currency') : __('finance.actions.create_currency')" :description="__('finance.settings.currencies_subtitle')" close-method="closeCurrencyModal" max-width="3xl">
        <form wire:submit="saveCurrency" class="space-y-4">
            <div class="grid gap-4 md:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.code') }}</label>
                    <input wire:model="currency_code" type="text" class="w-full rounded-xl px-4 py-3 text-sm uppercase">
                    @error('currency_code') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.symbol') }}</label>
                    <input wire:model="currency_symbol" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.decimal_places') }}</label>
                    <input wire:model="currency_decimal_places" type="number" min="0" max="6" step="1" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('currency_decimal_places') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                </div>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium">{{ __('finance.fields.name') }}</label>
                <input wire:model="currency_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">
                @error('currency_name') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
            </div>
            @unless ($currency_is_base)
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
            @endunless
            <div class="grid gap-3 text-sm">
                <label class="flex items-center gap-3"><input wire:model="currency_is_active" type="checkbox" class="rounded"> {{ __('finance.common.active') }}</label>
                <label class="flex items-center gap-3"><input wire:model="currency_show_in_dropdowns" type="checkbox" class="rounded"> {{ app()->isLocale('ar') ? 'إظهار العملة في القوائم المنسدلة' : 'Show currency in dropdown menus' }}</label>
                <label class="flex items-center gap-3"><input wire:model="currency_is_local" type="checkbox" class="rounded"> {{ __('finance.common.local_currency') }}</label>
                <label class="flex items-center gap-3"><input wire:model.live="currency_is_base" type="checkbox" class="rounded"> {{ __('finance.common.base_currency') }}</label>
            </div>
            @error('currency_is_active') <div class="text-sm text-red-400">{{ $message }}</div> @enderror
            @error('currency_is_local') <div class="text-sm text-red-400">{{ $message }}</div> @enderror
            @error('currency_is_base') <div class="text-sm text-red-400">{{ $message }}</div> @enderror
            @error('currencyDelete') <div class="rounded-2xl border border-red-500/25 bg-red-500/10 px-3 py-2 text-sm text-red-200">{{ $message }}</div> @enderror
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closeCurrencyModal" class="pill-link">{{ __('finance.actions.cancel') }}</button>
                @if ($currency_editing_id)
                    <x-delete-action-button wire:click="deleteCurrency({{ $currency_editing_id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" :label="__('finance.actions.delete')" data-finance-currency-delete-action />
                @endif
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
            <div>
                <label class="mb-2 block text-sm font-medium">{{ __('finance.fields.supported_currencies') }}</label>
                <div class="grid gap-2 sm:grid-cols-2 md:grid-cols-3">
                    @foreach ($currencies as $currency)
                        <label class="flex items-center gap-3 rounded-xl border border-white/10 px-4 py-3 text-sm"><input wire:model="cash_box_currency_ids" value="{{ $currency->id }}" type="checkbox" class="rounded"><span>{{ $currency->code }} - {{ $currency->name }}</span></label>
                    @endforeach
                </div>
                @error('cash_box_currency_ids') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
            </div>
            <div><label class="mb-1 block text-sm font-medium">{{ __('finance.common.notes') }}</label><textarea wire:model="cash_box_notes" rows="2" class="w-full rounded-xl px-4 py-3 text-sm"></textarea></div>
            <label class="flex items-center gap-3 text-sm"><input wire:model="cash_box_is_active" type="checkbox" class="rounded"> {{ __('finance.common.active') }}</label>
            @error('cash_box_is_active') <div class="text-sm text-red-400">{{ $message }}</div> @enderror
            @error('cashBoxDelete') <div class="rounded-2xl border border-red-500/25 bg-red-500/10 px-3 py-2 text-sm text-red-200">{{ $message }}</div> @enderror
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closeCashBoxModal" class="pill-link">{{ __('finance.actions.cancel') }}</button>
                @if ($cash_box_editing_id)
                    <x-delete-action-button wire:click="deleteCashBox({{ $cash_box_editing_id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" :label="__('finance.actions.delete')" data-finance-cash-box-delete-action />
                @endif
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
            <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.type') }}</label><select wire:model.live="finance_category_type" class="w-full rounded-xl px-4 py-3 text-sm">@foreach (\App\Models\FinanceCategory::TYPES as $type)<option value="{{ $type }}">{{ __('finance.category_types.'.$type) }}</option>@endforeach</select></div>
            <div><label class="mb-1 block text-sm font-medium">{{ __('finance.fields.mode') }}</label><select wire:model="finance_category_mode" class="w-full rounded-xl px-4 py-3 text-sm">@foreach (\App\Models\FinanceCategory::modesForType($finance_category_type) as $mode)<option value="{{ $mode }}">{{ __('finance.category_modes.'.$mode) }}</option>@endforeach</select></div>
            <div class="flex flex-wrap gap-6"><label class="flex items-center gap-3 text-sm"><input wire:model="finance_category_is_active" type="checkbox" class="rounded"> {{ __('finance.common.active') }}</label>@if ($finance_category_type === 'revenue')<label class="flex items-center gap-3 text-sm"><input wire:model="finance_category_is_donation" type="checkbox" class="rounded"> {{ __('finance.settings.donation_category') }}</label>@endif</div>
            @error('financeCategoryDelete') <div class="rounded-2xl border border-red-500/25 bg-red-500/10 px-3 py-2 text-sm text-red-200">{{ $message }}</div> @enderror
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closeFinanceCategoryModal" class="pill-link">{{ __('finance.actions.cancel') }}</button>
                @if ($finance_category_editing_id)
                    <x-delete-action-button wire:click="deleteFinanceCategory({{ $finance_category_editing_id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" :label="__('finance.actions.delete')" data-finance-category-delete-action />
                @endif
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

    @if (false)
    <x-admin.modal :show="$showPaymentMethodModal" :title="$payment_method_editing_id ? __('settings.finance.sections.payment_method.edit') : __('settings.finance.sections.payment_method.create')" :description="__('settings.finance.sections.payment_method.copy')" close-method="closePaymentMethodModal" max-width="3xl">
        <form wire:submit="savePaymentMethod" class="space-y-4">
            <div><label class="mb-1 block text-sm font-medium">{{ __('settings.finance.fields.name') }}</label><input wire:model="payment_method_name" type="text" class="w-full rounded-xl px-4 py-3 text-sm">@error('payment_method_name') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
            <div><label class="mb-1 block text-sm font-medium">{{ __('settings.finance.fields.code') }}</label><input wire:model="payment_method_code" type="text" class="w-full rounded-xl px-4 py-3 text-sm">@error('payment_method_code') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror</div>
            <label class="flex items-center gap-3 text-sm"><input wire:model="payment_method_is_active" type="checkbox" class="rounded"> {{ __('settings.finance.fields.is_active') }}</label>
            @error('paymentMethodDelete') <div class="rounded-2xl border border-red-500/25 bg-red-500/10 px-3 py-2 text-sm text-red-200">{{ $message }}</div> @enderror
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closePaymentMethodModal" class="pill-link">{{ __('finance.actions.cancel') }}</button>
                @if ($payment_method_editing_id)
                    <x-delete-action-button wire:click="deletePaymentMethod({{ $payment_method_editing_id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" :label="__('finance.actions.delete')" data-finance-payment-method-delete-action />
                @endif
                <button type="submit" class="pill-link pill-link--accent">{{ $payment_method_editing_id ? __('settings.finance.actions.update_method') : __('settings.finance.actions.create_method') }}</button>
            </div>
        </form>
    </x-admin.modal>
    @endif

</div>
