<?php

namespace App\Services;

use App\Models\FinanceRequest;
use App\Models\FinanceCashBox;
use App\Models\FinanceCurrency;
use App\Models\FinanceReportTemplate;
use App\Models\FinanceTransaction;
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
                $transaction->type,
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
                'include_closing_balance' => true,
                'include_exported_at' => true,
                'include_opening_balance' => true,
                'is_default' => true,
                'language' => FinanceReportTemplate::LANGUAGE_BOTH,
                'name' => 'Default ledger report',
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

    public function ledgerReport(FinanceReportTemplate $template, FinanceCashBox $cashBox, FinanceCurrency $currency, string $startDate, string $endDate): array
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

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
            $runningBalance = round($runningBalance + $signedAmount, 2);

            return [
                'expense' => $signedAmount < 0 ? abs($signedAmount) : 0.0,
                'income' => $signedAmount > 0 ? $signedAmount : 0.0,
                'running_balance' => $runningBalance,
                'transaction' => $transaction,
            ];
        });

        $income = round((float) $rows->sum('income'), 2);
        $expense = round((float) $rows->sum('expense'), 2);

        return [
            'cash_box' => $cashBox,
            'closing_balance' => round($openingBalance + $income - $expense, 2),
            'columns' => $template->normalizedColumns(),
            'currency' => $currency,
            'end' => $end,
            'expense' => $expense,
            'exported_at' => now(),
            'income' => $income,
            'net' => round($income - $expense, 2),
            'opening_balance' => $openingBalance,
            'rows' => $rows,
            'start' => $start,
            'template' => $template,
        ];
    }

    public function ledgerExportRows(FinanceReportTemplate $template, FinanceCashBox $cashBox, FinanceCurrency $currency, string $startDate, string $endDate): array
    {
        $report = $this->ledgerReport($template, $cashBox, $currency, $startDate, $endDate);
        $columns = $report['columns'];
        $language = $template->language;
        $rows = [
            [$template->title],
            [$this->bilingual('Date range', 'الفترة', $language), $report['start']->format('Y-m-d').' - '.$report['end']->format('Y-m-d')],
            [$this->bilingual('Cash box', 'الصندوق', $language), $cashBox->name],
            [$this->bilingual('Currency', 'العملة', $language), $currency->code],
        ];

        if ($template->include_exported_at) {
            $rows[] = [$this->bilingual('Export date', 'تاريخ التصدير', $language), $report['exported_at']->format('Y-m-d H:i')];
        }

        $rows[] = [];

        if ($template->include_opening_balance) {
            $rows[] = [$this->bilingual('Opening balance', 'الرصيد الافتتاحي', $language), $this->formatMoney($report['opening_balance'], $currency)];
        }

        $rows[] = array_map(fn (string $column) => $this->ledgerColumnLabel($column, $language), $columns);

        foreach ($report['rows'] as $row) {
            $rows[] = array_map(fn (string $column) => $this->ledgerColumnValue($row, $column), $columns);
        }

        if ($template->include_closing_balance) {
            $rows[] = [];
            $rows[] = [$this->bilingual('Closing balance', 'الرصيد الختامي', $language), $this->formatMoney($report['closing_balance'], $currency)];
        }

        return $rows;
    }

    public function ledgerColumnValue(array $row, string $column): string
    {
        /** @var FinanceTransaction $transaction */
        $transaction = $row['transaction'];

        return match ($column) {
            'amount' => $this->formatMoney((float) $transaction->amount, $transaction->currency),
            'cash_box' => (string) ($transaction->cashBox?->name ?: ''),
            'category' => (string) ($transaction->category?->name ?: ''),
            'currency' => (string) ($transaction->currency?->code ?: ''),
            'description' => (string) ($transaction->description ?: ''),
            'direction' => __('finance.options.'.$transaction->direction),
            'entered_by' => (string) ($transaction->enteredBy?->name ?: ''),
            'expense' => $row['expense'] > 0 ? $this->formatMoney((float) $row['expense'], $transaction->currency) : '',
            'income' => $row['income'] > 0 ? $this->formatMoney((float) $row['income'], $transaction->currency) : '',
            'reference' => (string) ($transaction->financeRequest?->request_no ?: data_get($transaction->metadata, 'reference', '')),
            'running_balance' => $this->formatMoney((float) $row['running_balance'], $transaction->currency),
            'transaction_date' => $transaction->transaction_date?->format('Y-m-d') ?: '',
            'transaction_no' => (string) ($transaction->transaction_no ?: ''),
            'type' => Str::headline((string) $transaction->type),
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

    protected function formatMoney(float $amount, ?FinanceCurrency $currency): string
    {
        return app(FinanceService::class)->formatCurrencyAmount($amount, $currency);
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
                $localTotal = (float) FinanceTransaction::query()
                    ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
                    ->sum('local_amount');

                return [
                    'quarter' => $quarter,
                    'net' => round($localTotal, 2),
                    'start' => $start,
                    'end' => $end,
                ];
            })
            ->all();
    }
}
