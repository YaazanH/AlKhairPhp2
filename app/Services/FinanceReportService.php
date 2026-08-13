<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Course;
use App\Models\FinanceCashBox;
use App\Models\FinanceCurrency;
use App\Models\FinanceGeneratedReport;
use App\Models\FinanceReportTemplate;
use App\Models\FinanceRequest;
use App\Models\FinanceTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class FinanceReportService
{
    public function report(int $year, ?int $quarter = null): array
    {
        [$start, $end] = $this->period($year, $quarter);
        $localCurrency = app(FinanceService::class)->localCurrency();

        $transactions = FinanceTransaction::query()
            ->with(['cashBox', 'category', 'currency', 'activity', 'teacher', 'financeRequest.pullRequestKind'])
            ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('transaction_date')
            ->get();

        $operatingTransactions = $transactions
            ->reject(fn (FinanceTransaction $transaction) => in_array($transaction->type, ['transfer', 'exchange'], true));

        $income = (float) $operatingTransactions->where('local_amount', '>', 0)->sum('local_amount');
        $expense = abs((float) $operatingTransactions->where('local_amount', '<', 0)->sum('local_amount'));

        return [
            'period' => [
                'start' => $start,
                'end' => $end,
                'year' => $year,
                'quarter' => $quarter,
            ],
            'balances' => app(FinanceService::class)->cashBoxBalances(auth()->user()),
            'category_totals' => $operatingTransactions
                ->where('local_amount', '<', 0)
                ->groupBy(fn (FinanceTransaction $transaction) => app(FinanceService::class)->transactionCategoryLabel($transaction))
                ->map(fn ($rows, $category) => [
                    'category' => $category,
                    'expense' => round(abs((float) $rows->where('local_amount', '<', 0)->sum('local_amount')), 2),
                ])
                ->sortByDesc('expense')
                ->values(),
            'pending_pull_requests' => FinanceRequest::query()
                ->with(['activity', 'pullRequestKind', 'requestedBy', 'requestedCurrency'])
                ->where('type', FinanceRequest::TYPE_PULL)
                ->where('status', FinanceRequest::STATUS_PENDING)
                ->latest()
                ->limit(10)
                ->get(),
            'quarter_totals' => $this->quarterTotals($year, $localCurrency),
            'previous_year_quarter_totals' => $this->quarterTotals($year - 1, $localCurrency),
            'latest_transactions' => $transactions->sortByDesc(fn (FinanceTransaction $transaction) => $transaction->transaction_date?->format('Y-m-d').str_pad((string) $transaction->id, 12, '0', STR_PAD_LEFT))->take(4)->values(),
            'summary_by_currency' => $operatingTransactions
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

    public function defaultLedgerTemplate(): FinanceReportTemplate
    {
        $settings = FinanceReportTemplate::query()
            ->where('is_default', true)
            ->orderBy('id')
            ->first()
            ?: FinanceReportTemplate::query()->orderBy('id')->first()
            ?: new FinanceReportTemplate;

        $settings->forceFill([
            'columns' => FinanceReportTemplate::DEFAULT_COLUMNS,
            'created_by' => $settings->created_by ?: auth()->id(),
            'date_mode' => 'exported_at',
            'include_closing_balance' => true,
            'include_exported_at' => true,
            'include_opening_balance' => true,
            'is_default' => true,
            'language' => FinanceReportTemplate::LANGUAGE_AR,
            'name' => 'Financial ledger',
            'show_issuer_name' => true,
            'show_page_numbers' => true,
            'subtitle' => null,
            'title' => 'تقرير مالي',
        ]);
        if ($settings->isDirty()) {
            $settings->save();
        }

        return $settings;
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
            'expense' => ['en' => 'Expense', 'ar' => 'مصاريف'],
            'running_balance' => ['en' => 'Balance', 'ar' => 'الرصيد'],
            'amount' => ['en' => 'Amount', 'ar' => 'المبلغ'],
            'direction' => ['en' => 'Direction', 'ar' => 'الاتجاه'],
            'cash_box' => ['en' => 'Fund', 'ar' => 'الصندوق'],
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

    public function ledgerReport(FinanceReportTemplate $template, FinanceCashBox $cashBox, FinanceCurrency $currency, string $startDate, string $endDate, ?User $issuer = null, ?string $notes = null): array
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
            ->with(['cashBox', 'category', 'currency', 'enteredBy', 'financeRequest.category', 'financeRequest.pullRequestKind'])
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

            $row = $this->ledgerRowFromTransaction($transaction, $income, $expense, $runningBalance);
            if (in_array($transaction->type, ['exchange', 'transfer'], true)) {
                $row['_expense_raw'] = 0.0;
                $row['_income_raw'] = 0.0;
            }

            return $row;
        })->values()->all();

        $income = round((float) collect($rows)->sum('_income_raw'), 2);
        $expense = round((float) collect($rows)->sum('_expense_raw'), 2);

        $report = $this->buildLedgerReportArray(
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
            $notes,
        );

        $report['closing_balance'] = $runningBalance;
        $report['formatted']['closing_balance'] = $this->formatMoney($runningBalance, $currency);

        return $report;
    }

    public function localCurrencyLedgerReport(FinanceReportTemplate $template, FinanceCashBox $cashBox, string $startDate, string $endDate, ?User $issuer = null, ?string $notes = null): array
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();
        $localCurrency = app(FinanceService::class)->localCurrency();
        $openingBalance = round((float) FinanceTransaction::query()
            ->where('cash_box_id', $cashBox->id)
            ->whereDate('transaction_date', '<', $start->toDateString())
            ->sum('local_amount'), 2);

        $transactions = FinanceTransaction::query()
            ->with(['cashBox', 'category', 'currency', 'enteredBy', 'financeRequest.category', 'financeRequest.pullRequestKind'])
            ->where('cash_box_id', $cashBox->id)
            ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $runningBalance = $openingBalance;
        $rows = $transactions->map(function (FinanceTransaction $transaction) use (&$runningBalance, $localCurrency): array {
            $signedAmount = (float) $transaction->local_amount;
            $income = $signedAmount > 0 ? $signedAmount : 0.0;
            $expense = $signedAmount < 0 ? abs($signedAmount) : 0.0;
            $runningBalance = round($runningBalance + $signedAmount, 2);
            $row = $this->ledgerRowFromTransaction($transaction, $income, $expense, $runningBalance);
            $row['amount'] = $this->formatMoney(abs($signedAmount), $localCurrency);
            $row['expense'] = $expense > 0 ? $this->formatMoney($expense, $localCurrency) : '';
            $row['income'] = $income > 0 ? $this->formatMoney($income, $localCurrency) : '';
            $row['running_balance'] = $this->formatMoney($runningBalance, $localCurrency);
            if (in_array($transaction->type, ['exchange', 'transfer'], true)) {
                $row['_expense_raw'] = 0.0;
                $row['_income_raw'] = 0.0;
            }

            return $row;
        })->values()->all();

        $income = round((float) collect($rows)->sum('_income_raw'), 2);
        $expense = round((float) collect($rows)->sum('_expense_raw'), 2);

        $report = $this->buildLedgerReportArray(
            $template,
            $cashBox->name,
            $this->currencySnapshot($localCurrency),
            $start,
            $end,
            $openingBalance,
            $income,
            $expense,
            $rows,
            now(),
            $issuer,
            $notes,
        );

        $report['closing_balance'] = $runningBalance;
        $report['formatted']['closing_balance'] = $this->formatMoney($runningBalance, $localCurrency);

        return $report;
    }

    public function reportPrefix(): string
    {
        $configured = AppSetting::groupValues('finance')->get('report_prefix');
        $normalized = Str::upper(trim((string) preg_replace('/[\s-]+/u', '', (string) ($configured ?: 'FINR'))));

        return $normalized !== '' ? $normalized : 'FINR';
    }

    public function reportNumber(?FinanceGeneratedReport $generatedReport = null, array $report = []): string
    {
        $prefix = (string) ($report['report_prefix'] ?? $this->reportPrefix());

        return $prefix.'-'.str_pad((string) ($generatedReport?->id ?? 0), 6, '0', STR_PAD_LEFT);
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
                'cash_box' => 'Main Fund',
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
                'cash_box' => 'Main Fund',
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
                'cash_box' => 'Main Fund',
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
            'Main Fund',
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

        $generationKey = hash('sha256', json_encode([
            $user?->id,
            $filters['cash_box_ids'] ?? data_get($report, 'cash_box.name'),
            data_get($report, 'start'),
            data_get($report, 'end'),
            $filters['ledger_notes'] ?? null,
        ]));

        return Cache::remember('finance-ledger-generation:'.$generationKey, now()->addSeconds(15), fn () => FinanceGeneratedReport::query()->create([
            'report_type' => 'ledger',
            'filters' => [
                'cash_box_id' => (int) ($filters['cash_box_id'] ?? 0),
                'cash_box_ids' => array_values(array_map('intval', $filters['cash_box_ids'] ?? [])),
                'cash_box_name' => data_get($report, 'cash_box.name'),
                'currency_id' => (int) ($filters['currency_id'] ?? 0),
                'currency_code' => data_get($report, 'currency.code'),
                'date_from' => data_get($report, 'start'),
                'date_to' => data_get($report, 'end'),
            ],
            'report_data' => $report,
            'generated_by' => $user?->id,
        ]));
    }

    public function generatedLedgerReport(FinanceGeneratedReport $generatedReport): array
    {
        $report = $generatedReport->report_data ?: [];
        $template = $report['template'] ?? $this->templateSnapshot($this->defaultLedgerTemplate());

        $report['template'] = $this->normalizeLedgerTemplateSnapshot(
            array_merge($this->templateSnapshot($this->defaultLedgerTemplate()), is_array($template) ? $template : [])
        );
        $report['generated_report_id'] = $generatedReport->id;
        $report['default_course'] = $report['default_course'] ?? $this->defaultCourseName();
        $report['issuer_name'] = $report['issuer_name'] ?? ($generatedReport->generatedBy?->name ?: null);
        $report['issuer_signature_pdf_src'] = $generatedReport->generatedBy?->financeSignaturePdfSource();
        $report['issuer_signature_url'] = $generatedReport->generatedBy?->financeSignatureUrl();
        $report['report_stamp_pdf_src'] = $this->reportStampPdfSource();
        $report['report_stamp_url'] = $this->reportStampUrl();
        $report['page_number'] = (int) ($report['page_number'] ?? 1);
        $report['rows'] = is_array($report['rows'] ?? null) ? $report['rows'] : [];
        if (is_array($report['fund_reports'] ?? null)) {
            $report['fund_reports'] = collect($report['fund_reports'])->map(function (array $fundReport) use ($report): array {
                $fundReport['default_course'] = $fundReport['default_course'] ?? $report['default_course'];
                $fundReport['template'] = $this->normalizeLedgerTemplateSnapshot(array_merge($report['template'], $fundReport['template'] ?? []));
                $fundReport['rows'] = is_array($fundReport['rows'] ?? null) ? $fundReport['rows'] : [];
                $fundReport['issuer_signature_pdf_src'] = $fundReport['issuer_signature_pdf_src'] ?? $report['issuer_signature_pdf_src'];
                $fundReport['issuer_signature_url'] = $report['issuer_signature_url'];
                $fundReport['report_stamp_pdf_src'] = $fundReport['report_stamp_pdf_src'] ?? $report['report_stamp_pdf_src'];
                $fundReport['report_stamp_url'] = $report['report_stamp_url'];

                return $fundReport;
            })->values()->all();
        }

        return $report;
    }

    public function ensureStoredLedgerPdf(FinanceGeneratedReport $generatedReport, array $report): ?string
    {
        $existingPath = is_string($generatedReport->pdf_path ?? null) ? $generatedReport->pdf_path : null;
        $currentRenderer = $this->ledgerPdfRendererVersion();
        $storedRenderer = is_array($generatedReport->report_data)
            ? (string) ($generatedReport->report_data['pdf_renderer'] ?? '')
            : '';

        if (
            $storedRenderer === $currentRenderer
            && $existingPath !== null
            && $existingPath !== ''
            && Storage::disk('local')->exists($existingPath)
        ) {
            return $existingPath;
        }

        $pdfBinary = $this->renderLedgerPdf($report, $generatedReport);

        if (! FinanceGeneratedReport::pdfStorageIsReady()) {
            return null;
        }

        $relativePath = $this->ledgerPdfStoragePath($generatedReport);
        Storage::disk('local')->put($relativePath, $pdfBinary);

        $reportData = is_array($generatedReport->report_data) ? $generatedReport->report_data : [];
        $reportData['pdf_renderer'] = $currentRenderer;

        if ($generatedReport->pdf_path !== $relativePath || ($generatedReport->report_data['pdf_renderer'] ?? null) !== $currentRenderer) {
            $generatedReport->forceFill([
                'report_data' => $reportData,
                'pdf_path' => $relativePath,
            ])->save();
        }

        return $relativePath;
    }

    public function renderLedgerPdf(array $report, ?FinanceGeneratedReport $generatedReport = null): string
    {
        $templateLanguage = (string) data_get($report, 'template.language', FinanceReportTemplate::LANGUAGE_BOTH);
        $defaultFont = 'dubai';
        $tempDir = storage_path('app/mpdf');

        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        $mpdf = new Mpdf([
            'default_font' => $defaultFont,
            'fontDir' => array_merge((new ConfigVariables)->getDefaults()['fontDir'], [public_path('fonts/dubai'), public_path('fonts/barcode')]),
            'fontdata' => (new FontVariables)->getDefaults()['fontdata'] + [
                'dubai' => ['R' => 'Dubai-Regular.ttf', 'M' => 'Dubai-Medium.ttf', 'B' => 'Dubai-Bold.ttf', 'L' => 'Dubai-Light.ttf', 'useOTL' => 0xFF, 'useKashida' => 75],
                'code39' => ['R' => '3OF9_NEW.TTF'],
            ],
            'format' => 'A4',
            'margin_bottom' => 18,
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 45,
            'margin_header' => 0,
            'margin_footer' => 0,
            'mode' => 'utf-8',
            'tempDir' => $tempDir,
        ]);
        $mpdf->autoLangToFont = false;
        $mpdf->autoScriptToLang = false;
        $mpdf->useSubstitutions = true;
        $mpdf->SetDirectionality('rtl');

        $backgroundImage = data_get($report, 'template.background_image_pdf_src');

        if (filled($backgroundImage)) {
            $mpdf->SetWatermarkImage($backgroundImage, 1, [210, 297], [0, 0]);
            $mpdf->watermarkImgBehind = true;
            $mpdf->showWatermarkImage = true;
        }

        $fundReports = is_array($report['fund_reports'] ?? null) ? $report['fund_reports'] : [$report];
        foreach ($fundReports as $index => $fundReport) {
            if ($index > 0) {
                $mpdf->AddPage();
            }
            $mpdf->WriteHTML(view('reports.finance-ledger-pdf-export', [
                'generatedReport' => $generatedReport,
                'report' => $fundReport,
                'service' => $this,
            ])->render());
        }

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    public function ledgerPdfFilename(array $report, ?FinanceGeneratedReport $generatedReport = null): string
    {
        $cashBox = Str::slug((string) data_get($report, 'cash_box.name', 'cash-box'));
        $currency = Str::lower(Str::slug((string) data_get($report, 'currency.code', 'currency')));
        $start = preg_replace('/[^0-9-]+/', '', (string) ($report['start'] ?? now()->toDateString())) ?: now()->toDateString();
        $end = preg_replace('/[^0-9-]+/', '', (string) ($report['end'] ?? $start)) ?: $start;
        $reportId = $generatedReport?->id ? '-'.str_pad((string) $generatedReport->id, 6, '0', STR_PAD_LEFT) : '';

        return sprintf(
            'finance-ledger%s-%s-%s-%s-to-%s.pdf',
            $reportId,
            $cashBox !== '' ? $cashBox : 'cash-box',
            $currency !== '' ? $currency : 'currency',
            $start,
            $end,
        );
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
            [$this->bilingual('Fund', 'الصندوق', $language), data_get($report, 'cash_box.name', '')],
            [$this->bilingual('Currency', 'العملة', $language), data_get($report, 'currency.code', '')],
        ];

        if (! empty($report['report_date'])) {
            $rows[] = [$this->bilingual('Report date', 'تاريخ التقرير', $language), $report['report_date']];
        }

        if (! empty($template['show_issuer_name']) && ! empty($report['issuer_name'])) {
            $rows[] = [$this->bilingual('Issued by', 'أصدر بواسطة', $language), $report['issuer_name']];
        }

        if (! empty($template['include_exported_at']) && ! empty($report['exported_at'])) {
            $rows[] = [$this->bilingual('Export date', 'تاريخ التصدير', $language), Carbon::parse($report['exported_at'])->format('Y-m-d')];
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
        if ($column === 'category' && array_key_exists('category', $row)) {
            return collect([$row['category'] ?? null, $row['description'] ?? null])
                ->filter(fn ($value) => filled($value))
                ->implode("\n");
        }

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
            'category' => (string) ($transaction->category?->name
                ?: ($transaction->financeRequest?->category?->name
                    ?: $transaction->financeRequest?->pullRequestKind?->name)),
            'currency' => (string) ($transaction->currency?->code ?: ''),
            'description' => (string) ($transaction->description ?: ''),
            'direction' => __('finance.options.'.$transaction->direction),
            'entered_by' => (string) ($transaction->enteredBy?->name ?: ''),
            'expense' => ($row['expense'] ?? 0) > 0 ? $this->formatMoney((float) $row['expense'], $transaction->currency) : '',
            'income' => ($row['income'] ?? 0) > 0 ? $this->formatMoney((float) $row['income'], $transaction->currency) : '',
            'reference' => (string) ($transaction->financeRequest?->request_no ?: data_get($transaction->metadata, 'reference', '')),
            'running_balance' => $this->formatMoney((float) ($row['running_balance'] ?? 0), $transaction->currency),
            'transaction_date' => $transaction->transaction_date?->format('d-m-Y') ?: '',
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
        ?string $notes = null,
    ): array {
        $closingBalance = round($openingBalance + $income - $expense, 2);
        $templateSnapshot = $this->normalizeLedgerTemplateSnapshot($this->templateSnapshot($template));
        $effectiveIssuer = $issuer ?: auth()->user();

        return [
            'cash_box' => [
                'id' => null,
                'name' => $cashBoxName,
            ],
            'closing_balance' => $closingBalance,
            'columns' => FinanceReportTemplate::DEFAULT_COLUMNS,
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
            'default_course' => $this->defaultCourseName(),
            'issuer_name' => $effectiveIssuer?->name,
            'issuer_signature_pdf_src' => $effectiveIssuer?->financeSignaturePdfSource(),
            'issuer_signature_url' => $effectiveIssuer?->financeSignatureUrl(),
            'net' => round($income - $expense, 2),
            'notes' => filled($notes) ? trim($notes) : null,
            'opening_balance' => $openingBalance,
            'page_number' => 1,
            'pdf_renderer' => $this->ledgerPdfRendererVersion(),
            'report_date' => $this->resolveReportDate($template, $exportedAt),
            'report_prefix' => $this->reportPrefix(),
            'report_stamp_pdf_src' => $this->reportStampPdfSource(),
            'report_stamp_url' => $this->reportStampUrl(),
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
            'symbol' => $currency->symbol,
        ];
    }

    protected function defaultCourseName(): ?string
    {
        return Course::query()
            ->where('is_default', true)
            ->where('is_active', true)
            ->value('name') ?: Course::query()->where('is_active', true)->orderBy('name')->value('name');
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
            'symbol' => $currency['symbol'] ?? null,
        ]));
    }

    protected function ledgerRowFromTransaction(FinanceTransaction $transaction, float $income, float $expense, float $runningBalance): array
    {
        $currency = $transaction->currency;
        $description = (string) ($transaction->description ?: '');
        $category = $transaction->category ?: $transaction->financeRequest?->category;
        $maskedDonor = $category?->is_donation ? $transaction->financeRequest?->maskedCounterpartyName() : '';
        if ($maskedDonor) {
            $description = $maskedDonor.($description !== '' ? ' — '.$description : '');
        }

        return [
            'amount' => $this->formatMoney((float) $transaction->amount, $currency),
            'cash_box' => (string) ($transaction->cashBox?->name ?: ''),
            'category' => (string) ($transaction->category?->name
                ?: $transaction->financeRequest?->category?->name
                ?: $transaction->financeRequest?->pullRequestKind?->name
                ?: ''),
            'currency' => (string) ($currency?->code ?: ''),
            'description' => $description,
            'direction' => __('finance.options.'.$transaction->direction),
            'entered_by' => (string) ($transaction->enteredBy?->name ?: ''),
            'expense' => $expense > 0 ? $this->formatMoney($expense, $currency) : '',
            'income' => $income > 0 ? $this->formatMoney($income, $currency) : '',
            'reference' => (string) ($transaction->financeRequest?->request_no ?: data_get($transaction->metadata, 'reference', '')),
            'running_balance' => $this->formatMoney($runningBalance, $currency),
            'transaction_date' => $transaction->transaction_date?->format('d-m-Y') ?: '',
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

    protected function quarterTotals(int $year, FinanceCurrency $comparisonCurrency): array
    {
        return collect([1, 2, 3, 4])
            ->map(function (int $quarter) use ($comparisonCurrency, $year) {
                [$start, $end] = $this->period($year, $quarter);
                $comparisonRate = max((float) $comparisonCurrency->rate_to_base, 0.000000000001);
                $transactions = FinanceTransaction::query()
                    ->whereBetween('transaction_date', [$start->toDateString(), $end->toDateString()])
                    ->whereNotIn('type', ['transfer', 'exchange'])
                    ->get(['base_amount']);
                $convertedAmounts = $transactions->map(fn (FinanceTransaction $transaction): float => round((float) $transaction->base_amount / $comparisonRate, 2));

                return [
                    'income' => round((float) $convertedAmounts->filter(fn (float $amount): bool => $amount > 0)->sum(), 2),
                    'expense' => round(abs((float) $convertedAmounts->filter(fn (float $amount): bool => $amount < 0)->sum()), 2),
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
        $backgroundImageUrl = $template->background_image_url;
        $brandLogoPdfSource = app(PdfBrandingService::class)->logoSource();
        $logoImageUrl = $template->logo_image_url;

        return [
            'background_image_pdf_src' => $this->pdfAssetSource($backgroundImageUrl),
            'background_image_url' => $backgroundImageUrl,
            'columns' => FinanceReportTemplate::DEFAULT_COLUMNS,
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
            'logo_image_pdf_src' => $brandLogoPdfSource ?: $this->pdfAssetSource($logoImageUrl),
            'logo_image_url' => $logoImageUrl,
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

    protected function ledgerPdfStoragePath(FinanceGeneratedReport $generatedReport): string
    {
        $timestamp = $generatedReport->created_at ?: now();

        return sprintf(
            'reports/finance/ledger/%s/%s/ledger-report-%s.pdf',
            $timestamp->format('Y'),
            $timestamp->format('m'),
            str_pad((string) $generatedReport->id, 6, '0', STR_PAD_LEFT),
        );
    }

    protected function ledgerPdfRendererVersion(): string
    {
        return 'mpdf-fixed-ledger-v9';
    }

    public function reportStampPdfSource(): ?string
    {
        $path = AppSetting::groupValues('finance')->get('report_stamp_path');

        return is_string($path) && $path !== '' && Storage::disk('public')->exists($path)
            ? Storage::disk('public')->path($path)
            : null;
    }

    public function reportStampUrl(): ?string
    {
        $path = AppSetting::groupValues('finance')->get('report_stamp_path');

        return is_string($path) && $path !== '' && Storage::disk('public')->exists($path)
            ? Storage::disk('public')->url($path)
            : null;
    }

    public function defaultReportLogoPdfSource(): ?string
    {
        return app(PdfBrandingService::class)->logoSource()
            ?: $this->pdfAssetSource($this->defaultLedgerTemplate()->logo_image_url);
    }

    protected function normalizeLedgerTemplateSnapshot(array $template): array
    {
        $template['background_image_pdf_src'] = $template['background_image_pdf_src'] ?? $this->pdfAssetSource($template['background_image_url'] ?? null);
        $template['logo_image_pdf_src'] = $template['logo_image_pdf_src'] ?? $this->pdfAssetSource($template['logo_image_url'] ?? null);

        return $template;
    }

    protected function pdfAssetSource(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        if (str_starts_with($path, 'data:') || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (str_starts_with($path, '/storage/')) {
            $relativePath = ltrim(Str::after($path, '/storage/'), '/');
            $storagePath = storage_path('app/public/'.$relativePath);

            return is_file($storagePath)
                ? $this->fileUri($storagePath)
                : $this->fileUri(public_path('storage/'.$relativePath));
        }

        return $this->fileUri(public_path(ltrim($path, '/')));
    }

    protected function fileUri(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        if (! str_starts_with($normalized, '/')) {
            $normalized = '/'.$normalized;
        }

        return 'file://'.$normalized;
    }
}
