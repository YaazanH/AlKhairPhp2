<?php

namespace App\Services;

use App\Models\FinanceCashBox;
use App\Models\FinanceCurrency;
use App\Models\FinanceGeneratedReport;
use App\Models\FinanceReportTemplate;
use App\Models\FinanceRequest;
use App\Models\FinanceTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class FinanceReportService
{
    public function report(int $year, ?int $quarter = null): array
    {
        [$start, $end] = $this->period($year, $quarter);
        $localCurrency = app(FinanceService::class)->localCurrency();

        $transactions = FinanceTransaction::query()
            ->with(['cashBox', 'category', 'currency', 'activity', 'teacher'])
            ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('transaction_date')
            ->get();

        $income = (float) $transactions->where('local_amount', '>', 0)->sum('local_amount');
        $expense = abs((float) $transactions->where('local_amount', '<', 0)->sum('local_amount'));

        return [
            'period' => [
                'start' => $start,
                'end' => $end,
                'year' => $year,
                'quarter' => $quarter,
            ],
            'balances' => app(FinanceService::class)->cashBoxBalances(auth()->user()),
            'category_totals' => $transactions
                ->groupBy(fn (FinanceTransaction $transaction) => $transaction->category?->name ?: __('finance.options.uncategorized'))
                ->map(fn ($rows, $category) => [
                    'category' => $category,
                    'income' => round((float) $rows->where('local_amount', '>', 0)->sum('local_amount'), 2),
                    'expense' => round(abs((float) $rows->where('local_amount', '<', 0)->sum('local_amount')), 2),
                    'net' => round((float) $rows->sum('local_amount'), 2),
                ])
                ->values(),
            'pending_pull_requests' => FinanceRequest::query()
                ->with(['activity', 'pullRequestKind', 'requestedBy', 'requestedCurrency'])
                ->where('type', FinanceRequest::TYPE_PULL)
                ->where('status', FinanceRequest::STATUS_PENDING)
                ->latest()
                ->limit(10)
                ->get(),
            'quarter_totals' => $this->quarterTotals($year),
            'summary_by_currency' => $transactions
                ->groupBy('currency_id')
                ->map(function ($rows) {
                    $currency = $rows->first()->currency;
                    $income = (float) $rows->where('signed_amount', '>', 0)->sum('signed_amount');
                    $expense = abs((float) $rows->where('signed_amount', '<', 0)->sum('signed_amount'));

                    return [
                        'currency' => $currency,
                        'income' => round($income, 2),
                        'expense' => round($expense, 2),
                        'net' => round($income - $expense, 2),
                    ];
                })
                ->sortBy(fn (array $row) => sprintf('%d%d%s', $row['currency']?->is_local ? 0 : 1, $row['currency']?->is_base ? 0 : 1, $row['currency']?->code))
                ->values(),
            'summary' => [
                'income' => round($income, 2),
                'expense' => round($expense, 2),
                'local_currency' => $localCurrency,
                'net' => round($income - $expense, 2),
                'transactions' => $transactions->count(),
            ],
            'transactions' => $transactions,
        ];
    }

    public function exportRows(int $year, ?int $quarter = null): array
    {
        return $this->report($year, $quarter)['transactions']
            ->map(fn (FinanceTransaction $transaction) => [
                $transaction->transaction_date?->format('Y-m-d'),
                $transaction->transaction_no,
                $transaction->cashBox?->name,
                $transaction->currency?->code,
                app(FinanceService::class)->transactionTypeLabel((string) $transaction->type, $transaction),
                $transaction->direction,
                (float) $transaction->amount,
                (float) $transaction->signed_amount,
                (float) $transaction->base_amount,
                (float) $transaction->local_amount,
                $transaction->category?->name,
                $transaction->activity?->title,
                $transaction->teacher ? trim($transaction->teacher->first_name.' '.$transaction->teacher->last_name) : null,
                $transaction->description,
            ])
            ->all();
    }

    public function defaultLedgerTemplate(): FinanceReportTemplate
    {
        return FinanceReportTemplate::query()
            ->where('is_default', true)
            ->orderBy('id')
            ->first()
            ?: FinanceReportTemplate::query()->orderBy('id')->first()
            ?: FinanceReportTemplate::query()->create([
                'columns' => FinanceReportTemplate::DEFAULT_COLUMNS,
                'date_mode' => 'exported_at',
                'include_closing_balance' => true,
                'include_exported_at' => true,
                'include_opening_balance' => true,
                'is_default' => true,
                'language' => FinanceReportTemplate::LANGUAGE_BOTH,
                'name' => 'Default ledger report',
                'show_issuer_name' => true,
                'show_page_numbers' => false,
                'subtitle' => 'Cash box ledger by selected currency and date range.',
                'title' => 'Finance Ledger Report',
            ]);
    }

    public function ledgerColumnDefinitions(): array
    {
        return [
            'transaction_date' => ['en' => 'Date', 'ar' => 'التاريخ'],
            'transaction_no' => ['en' => 'Ledger no.', 'ar' => 'رقم القيد'],
            'description' => ['en' => 'Description', 'ar' => 'الوصف'],
            'type' => ['en' => 'Type', 'ar' => 'النوع'],
            'category' => ['en' => 'Category', 'ar' => 'التصنيف'],
            'income' => ['en' => 'Income', 'ar' => 'الإيراد'],
            'expense' => ['en' => 'Expense', 'ar' => 'المصروف'],
            'running_balance' => ['en' => 'Balance', 'ar' => 'الرصيد'],
            'amount' => ['en' => 'Amount', 'ar' => 'المبلغ'],
            'direction' => ['en' => 'Direction', 'ar' => 'الاتجاه'],
            'cash_box' => ['en' => 'Cash box', 'ar' => 'الصندوق'],
            'currency' => ['en' => 'Currency', 'ar' => 'العملة'],
            'entered_by' => ['en' => 'User', 'ar' => 'المستخدم'],
            'reference' => ['en' => 'Reference', 'ar' => 'المرجع'],
        ];
    }

    public function ledgerColumnLabel(string $column, string $language): string
    {
        $definition = $this->ledgerColumnDefinitions()[$column] ?? ['en' => $column, 'ar' => $column];

        return match ($language) {
            FinanceReportTemplate::LANGUAGE_AR => $definition['ar'],
            FinanceReportTemplate::LANGUAGE_EN => $definition['en'],
            default => $definition['ar'].' / '.$definition['en'],
        };
    }

    public function ledgerReport(FinanceReportTemplate $template, FinanceCashBox $cashBox, FinanceCurrency $currency, string $startDate, string $endDate, ?User $issuer = null): array
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();
        $exportedAt = now();

        $openingBalance = round((float) FinanceTransaction::query()
            ->where('cash_box_id', $cashBox->id)
            ->where('currency_id', $currency->id)
            ->whereDate('transaction_date', '<', $start->toDateString())
            ->sum('signed_amount'), 2);

        $transactions = FinanceTransaction::query()
            ->with(['cashBox', 'category', 'currency', 'enteredBy', 'financeRequest'])
            ->where('cash_box_id', $cashBox->id)
            ->where('currency_id', $currency->id)
            ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $runningBalance = $openingBalance;
        $rows = $transactions->map(function (FinanceTransaction $transaction) use (&$runningBalance): array {
            $signedAmount = (float) $transaction->signed_amount;
            $income = $signedAmount > 0 ? $signedAmount : 0.0;
            $expense = $signedAmount < 0 ? abs($signedAmount) : 0.0;
            $runningBalance = round($runningBalance + $signedAmount, 2);

            return $this->ledgerRowFromTransaction($transaction, $income, $expense, $runningBalance);
        })->values()->all();

        $income = round((float) collect($rows)->sum('_income_raw'), 2);
        $expense = round((float) collect($rows)->sum('_expense_raw'), 2);

        return $this->buildLedgerReportArray(
            $template,
            $cashBox->name,
            $this->currencySnapshot($currency),
            $start,
            $end,
            $openingBalance,
            $income,
            $expense,
            $rows,
            $exportedAt,
            $issuer,
        );
    }

    public function previewLedgerReport(FinanceReportTemplate $template, ?User $issuer = null): array
    {
        $currency = app(FinanceService::class)->localCurrency();
        $currencySnapshot = $this->currencySnapshot($currency);
        $start = now()->startOfMonth();
        $end = now();
        $exportedAt = now();
        $openingBalance = 1250.0;
        $runningBalance = $openingBalance;
        $sampleRows = [
            [
                'transaction_date' => $start->copy()->addDay()->toDateString(),
                'transaction_no' => 'TX-00010001',
                'description' => 'Course fees batch',
                'type' => app(FinanceService::class)->transactionTypeLabel('revenue_request'),
                'category' => 'Student fees',
                'signed_amount' => 2500.0,
                'cash_box' => 'Main Cash Box',
                'currency' => $currencySnapshot['code'],
                'entered_by' => $issuer?->name ?: 'Finance Manager',
                'reference' => 'REV-000321',
                'direction' => __('finance.options.in'),
            ],
            [
                'transaction_date' => $start->copy()->addDays(4)->toDateString(),
                'transaction_no' => 'TX-00010002',
                'description' => 'Class supplies',
                'type' => app(FinanceService::class)->transactionTypeLabel('expense_request'),
                'category' => 'Supplies',
                'signed_amount' => -600.0,
                'cash_box' => 'Main Cash Box',
                'currency' => $currencySnapshot['code'],
                'entered_by' => $issuer?->name ?: 'Finance Manager',
                'reference' => 'EXP-000118',
                'direction' => __('finance.options.out'),
            ],
            [
                'transaction_date' => $start->copy()->addDays(9)->toDateString(),
                'transaction_no' => 'TX-00010003',
                'description' => 'Teacher pull settlement',
                'type' => app(FinanceService::class)->transactionTypeLabel('pull_request'),
                'category' => 'Teacher support',
                'signed_amount' => -300.0,
                'cash_box' => 'Main Cash Box',
                'currency' => $currencySnapshot['code'],
                'entered_by' => $issuer?->name ?: 'Finance Manager',
                'reference' => 'PUL-000042',
                'direction' => __('finance.options.out'),
            ],
        ];

        $rows = collect($sampleRows)->map(function (array $row) use (&$runningBalance, $currency) {
            $signedAmount = (float) $row['signed_amount'];
            $income = $signedAmount > 0 ? $signedAmount : 0.0;
            $expense = $signedAmount < 0 ? abs($signedAmount) : 0.0;
            $runningBalance = round($runningBalance + $signedAmount, 2);

            return [
                'amount' => $this->formatMoney(abs($signedAmount), $currency),
                'cash_box' => $row['cash_box'],
                'category' => $row['category'],
                'currency' => $row['currency'],
                'description' => $row['description'],
                'direction' => $row['direction'],
                'entered_by' => $row['entered_by'],
                'expense' => $expense > 0 ? $this->formatMoney($expense, $currency) : '',
                'income' => $income > 0 ? $this->formatMoney($income, $currency) : '',
                'reference' => $row['reference'],
                'running_balance' => $this->formatMoney($runningBalance, $currency),
                'transaction_date' => $row['transaction_date'],
                'transaction_no' => $row['transaction_no'],
                'type' => $row['type'],
                '_expense_raw' => $expense,
                '_income_raw' => $income,
                '_running_balance_raw' => $runningBalance,
            ];
        })->all();

        $income = round((float) collect($rows)->sum('_income_raw'), 2);
        $expense = round((float) collect($rows)->sum('_expense_raw'), 2);

        return $this->buildLedgerReportArray(
            $template,
            'Main Cash Box',
            $currencySnapshot,
            $start,
            $end,
            $openingBalance,
            $income,
            $expense,
            $rows,
            $exportedAt,
            $issuer,
        );
    }

    public function storeGeneratedLedgerReport(array $report, array $filters, ?User $user = null): ?FinanceGeneratedReport
    {
        if (! FinanceGeneratedReport::storageIsReady()) {
            return null;
        }

        return FinanceGeneratedReport::query()->create([
            'report_type' => 'ledger',
            'filters' => [
                'cash_box_id' => (int) ($filters['cash_box_id'] ?? 0),
                'cash_box_name' => data_get($report, 'cash_box.name'),
                'currency_id' => (int) ($filters['currency_id'] ?? 0),
                'currency_code' => data_get($report, 'currency.code'),
                'date_from' => data_get($report, 'start'),
                'date_to' => data_get($report, 'end'),
                'template_id' => (int) (data_get($report, 'template.id') ?? 0),
                'template_name' => data_get($report, 'template.name'),
            ],
            'report_data' => $report,
            'generated_by' => $user?->id,
        ]);
    }

    public function generatedLedgerReport(FinanceGeneratedReport $generatedReport): array
    {
        $report = $generatedReport->report_data ?: [];
        $template = $report['template'] ?? $this->templateSnapshot($this->defaultLedgerTemplate());

        $report['template'] = array_merge($this->templateSnapshot($this->defaultLedgerTemplate()), is_array($template) ? $template : []);
        $report['generated_report_id'] = $generatedReport->id;
        $report['issuer_name'] = $report['issuer_name'] ?? ($generatedReport->generatedBy?->name ?: null);
        $report['page_number'] = (int) ($report['page_number'] ?? 1);
        $report['rows'] = is_array($report['rows'] ?? null) ? $report['rows'] : [];

        return $report;
    }

    public function ledgerExportRows(FinanceReportTemplate $template, FinanceCashBox $cashBox, FinanceCurrency $currency, string $startDate, string $endDate, ?User $issuer = null): array
    {
        return $this->ledgerExportRowsFromReport(
            $this->ledgerReport($template, $cashBox, $currency, $startDate, $endDate, $issuer)
        );
    }

    public function ledgerExportRowsFromReport(array $report): array
    {
        $template = $report['template'];
        $columns = $report['columns'] ?? ($template['columns'] ?? FinanceReportTemplate::DEFAULT_COLUMNS);
        $language = $template['language'] ?? FinanceReportTemplate::LANGUAGE_BOTH;
        $rows = [
            [$template['title'] ?? __('finance.report_templates.default_title')],
            [$this->bilingual('Date range', 'الفترة', $language), ($report['start'] ?? '').' - '.($report['end'] ?? '')],
            [$this->bilingual('Cash box', 'الصندوق', $language), data_get($report, 'cash_box.name', '')],
            [$this->bilingual('Currency', 'العملة', $language), data_get($report, 'currency.code', '')],
        ];

        if (! empty($report['report_date'])) {
            $rows[] = [$this->bilingual('Report date', 'تاريخ التقرير', $language), $report['report_date']];
        }

        if (! empty($template['show_issuer_name']) && ! empty($report['issuer_name'])) {
            $rows[] = [$this->bilingual('Issued by', 'أصدر بواسطة', $language), $report['issuer_name']];
        }

        if (! empty($template['include_exported_at']) && ! empty($report['exported_at'])) {
            $rows[] = [$this->bilingual('Export date', 'تاريخ التصدير', $language), Carbon::parse($report['exported_at'])->format('Y-m-d H:i')];
        }

        $rows[] = [];

        if (! empty($template['include_opening_balance'])) {
            $rows[] = [$this->bilingual('Opening balance', 'الرصيد الافتتاحي', $language), data_get($report, 'formatted.opening_balance', '')];
        }

        $rows[] = array_map(fn (string $column) => $this->ledgerColumnLabel($column, $language), $columns);

        foreach ($report['rows'] ?? [] as $row) {
            $rows[] = array_map(fn (string $column) => $this->ledgerColumnValue($row, $column), $columns);
        }

        if (! empty($template['include_closing_balance'])) {
            $rows[] = [];
            $rows[] = [$this->bilingual('Closing balance', 'الرصيد الختامي', $language), data_get($report, 'formatted.closing_balance', '')];
        }

        return $rows;
    }

    public function ledgerColumnValue(array $row, string $column): string
    {
        if (array_key_exists($column, $row) && ! is_array($row[$column]) && ! is_object($row[$column])) {
            return (string) $row[$column];
        }

        /** @var FinanceTransaction|null $transaction */
        $transaction = $row['transaction'] ?? null;

        if (! $transaction instanceof FinanceTransaction) {
            return '';
        }

        return match ($column) {
            'amount' => $this->formatMoney((float) $transaction->amount, $transaction->currency),
            'cash_box' => (string) ($transaction->cashBox?->name ?: ''),
            'category' => (string) ($transaction->category?->name ?: ''),
            'currency' => (string) ($transaction->currency?->code ?: ''),
            'description' => (string) ($transaction->description ?: ''),
            'direction' => __('finance.options.'.$transaction->direction),
            'entered_by' => (string) ($transaction->enteredBy?->name ?: ''),
            'expense' => ($row['expense'] ?? 0) > 0 ? $this->formatMoney((float) $row['expense'], $transaction->currency) : '',
            'income' => ($row['income'] ?? 0) > 0 ? $this->formatMoney((float) $row['income'], $transaction->currency) : '',
            'reference' => (string) ($transaction->financeRequest?->request_no ?: data_get($transaction->metadata, 'reference', '')),
            'running_balance' => $this->formatMoney((float) ($row['running_balance'] ?? 0), $transaction->currency),
            'transaction_date' => $transaction->transaction_date?->format('Y-m-d') ?: '',
            'transaction_no' => (string) ($transaction->transaction_no ?: ''),
            'type' => app(FinanceService::class)->transactionTypeLabel((string) $transaction->type, $transaction),
            default => '',
        };
    }

    public function bilingual(string $en, string $ar, string $language): string
    {
        return match ($language) {
            FinanceReportTemplate::LANGUAGE_AR => $ar,
            FinanceReportTemplate::LANGUAGE_EN => $en,
            default => $ar.' / '.$en,
        };
    }

    public function shapeRgbChannels(?string $hex): string
    {
        $hex = ltrim((string) $hex, '#');

        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return '15,122,61';
        }

        return sprintf(
            '%d, %d, %d',
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        );
    }

    protected function buildLedgerReportArray(
        FinanceReportTemplate $template,
        string $cashBoxName,
        array $currency,
        Carbon $start,
        Carbon $end,
        float $openingBalance,
        float $income,
        float $expense,
        array $rows,
        Carbon $exportedAt,
        ?User $issuer = null,
    ): array {
        $closingBalance = round($openingBalance + $income - $expense, 2);
        $templateSnapshot = $this->templateSnapshot($template);

        return [
            'cash_box' => [
                'id' => null,
                'name' => $cashBoxName,
            ],
            'closing_balance' => $closingBalance,
            'columns' => $template->normalizedColumns(),
            'currency' => $currency,
            'end' => $end->toDateString(),
            'expense' => $expense,
            'exported_at' => $exportedAt->toIso8601String(),
            'formatted' => [
                'closing_balance' => $this->formatMoney($closingBalance, $currency),
                'expense' => $this->formatMoney($expense, $currency),
                'income' => $this->formatMoney($income, $currency),
                'net' => $this->formatMoney($income - $expense, $currency),
                'opening_balance' => $this->formatMoney($openingBalance, $currency),
            ],
            'income' => $income,
            'issuer_name' => $issuer?->name ?: auth()->user()?->name,
            'net' => round($income - $expense, 2),
            'opening_balance' => $openingBalance,
            'page_number' => 1,
            'report_date' => $this->resolveReportDate($template, $exportedAt),
            'rows' => $rows,
            'start' => $start->toDateString(),
            'template' => $templateSnapshot,
        ];
    }

    protected function currencySnapshot(FinanceCurrency $currency): array
    {
        return [
            'code' => $currency->code,
            'decimal_places' => $currency->decimal_places,
            'id' => $currency->id,
            'name' => $currency->name,
        ];
    }

    protected function formatMoney(float $amount, FinanceCurrency|array|null $currency): string
    {
        if ($currency instanceof FinanceCurrency || $currency === null) {
            return app(FinanceService::class)->formatCurrencyAmount($amount, $currency);
        }

        return app(FinanceService::class)->formatCurrencyAmount($amount, new FinanceCurrency([
            'code' => $currency['code'] ?? null,
            'decimal_places' => $currency['decimal_places'] ?? 2,
            'name' => $currency['name'] ?? null,
        ]));
    }

    protected function ledgerRowFromTransaction(FinanceTransaction $transaction, float $income, float $expense, float $runningBalance): array
    {
        $currency = $transaction->currency;

        return [
            'amount' => $this->formatMoney((float) $transaction->amount, $currency),
            'cash_box' => (string) ($transaction->cashBox?->name ?: ''),
            'category' => (string) ($transaction->category?->name ?: ''),
            'currency' => (string) ($currency?->code ?: ''),
            'description' => (string) ($transaction->description ?: ''),
            'direction' => __('finance.options.'.$transaction->direction),
            'entered_by' => (string) ($transaction->enteredBy?->name ?: ''),
            'expense' => $expense > 0 ? $this->formatMoney($expense, $currency) : '',
            'income' => $income > 0 ? $this->formatMoney($income, $currency) : '',
            'reference' => (string) ($transaction->financeRequest?->request_no ?: data_get($transaction->metadata, 'reference', '')),
            'running_balance' => $this->formatMoney($runningBalance, $currency),
            'transaction_date' => $transaction->transaction_date?->format('Y-m-d') ?: '',
            'transaction_no' => (string) ($transaction->transaction_no ?: ''),
            'type' => app(FinanceService::class)->transactionTypeLabel((string) $transaction->type, $transaction),
            '_expense_raw' => $expense,
            '_income_raw' => $income,
            '_running_balance_raw' => $runningBalance,
        ];
    }

    protected function normalizeTemplateDateMode(?string $mode): string
    {
        return in_array($mode, ['exported_at', 'today', 'custom'], true) ? $mode : 'exported_at';
    }

    protected function period(int $year, ?int $quarter): array
    {
        if ($quarter !== null) {
            $quarter = max(1, min(4, $quarter));
            $startMonth = (($quarter - 1) * 3) + 1;

            $start = Carbon::create($year, $startMonth, 1)->startOfDay();

            return [$start, $start->copy()->addMonths(3)->subDay()->endOfDay()];
        }

        return [
            Carbon::create($year, 1, 1)->startOfDay(),
            Carbon::create($year, 12, 31)->endOfDay(),
        ];
    }

    protected function quarterTotals(int $year): array
    {
        return collect([1, 2, 3, 4])
            ->map(function (int $quarter) use ($year) {
                [$start, $end] = $this->period($year, $quarter);
                $transactions = FinanceTransaction::query()
                    ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
                    ->get(['local_amount']);

                return [
                    'income' => round((float) $transactions->where('local_amount', '>', 0)->sum('local_amount'), 2),
                    'expense' => round(abs((float) $transactions->where('local_amount', '<', 0)->sum('local_amount')), 2),
                    'quarter' => $quarter,
                    'start' => $start,
                    'end' => $end,
                ];
            })
            ->all();
    }

    protected function resolveReportDate(FinanceReportTemplate $template, Carbon $exportedAt): string
    {
        return match ($this->normalizeTemplateDateMode($template->date_mode)) {
            'custom' => $template->custom_date?->toDateString() ?: $exportedAt->toDateString(),
            'today' => now()->toDateString(),
            default => $exportedAt->toDateString(),
        };
    }

    protected function templateSnapshot(FinanceReportTemplate $template): array
    {
        return [
            'background_image_url' => $template->background_image_url,
            'columns' => $template->normalizedColumns(),
            'custom_date' => $template->custom_date?->toDateString(),
            'custom_text' => $template->custom_text,
            'date_mode' => $this->normalizeTemplateDateMode($template->date_mode),
            'footer_text' => $template->footer_text,
            'header_text' => $template->header_text,
            'id' => $template->id,
            'include_closing_balance' => (bool) $template->include_closing_balance,
            'include_exported_at' => (bool) $template->include_exported_at,
            'include_opening_balance' => (bool) $template->include_opening_balance,
            'is_default' => (bool) $template->is_default,
            'language' => $template->language,
            'logo_image_url' => $template->logo_image_url,
            'name' => $template->name,
            'shape_color' => $template->shape_color ?: '#0f7a3d',
            'shape_opacity' => (float) ($template->shape_opacity ?? 0.12),
            'shape_type' => $template->shape_type,
            'show_issuer_name' => (bool) $template->show_issuer_name,
            'show_page_numbers' => (bool) $template->show_page_numbers,
            'subtitle' => $template->subtitle,
            'title' => $template->title,
        ];
    }
}
