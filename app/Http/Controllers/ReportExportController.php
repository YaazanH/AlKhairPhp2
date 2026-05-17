<?php

namespace App\Http\Controllers;

use App\Models\FinanceGeneratedReport;
use App\Models\FinanceReportTemplate;
use App\Services\AccessScopeService;
use App\Services\FinanceReportService;
use App\Services\FinanceService;
use App\Services\ReportingService;
use App\Services\XlsxExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportController extends Controller
{
    /**
     * Download the attendance report as XLSX.
     */
    public function attendance(Request $request): StreamedResponse
    {
        return $this->xlsxDownload(
            'attendance-report',
            ['Date', 'Academic Year', 'Group', 'Course', 'Student', 'Status', 'Status Code', 'Notes'],
            app(ReportingService::class)->attendanceRows($this->validatedFilters($request)),
        );
    }

    /**
     * Download the assessment report as XLSX.
     */
    public function assessments(Request $request): StreamedResponse
    {
        return $this->xlsxDownload(
            'assessment-report',
            ['Scheduled At', 'Academic Year', 'Group', 'Course', 'Assessment', 'Type', 'Student', 'Score', 'Status', 'Attempt', 'Teacher', 'Notes'],
            app(ReportingService::class)->assessmentRows($this->validatedFilters($request)),
        );
    }

    /**
     * Download the memorization report as XLSX.
     */
    public function memorization(Request $request): StreamedResponse
    {
        return $this->xlsxDownload(
            'memorization-report',
            ['Recorded On', 'Academic Year', 'Group', 'Course', 'Student', 'Teacher', 'Entry Type', 'From Page', 'To Page', 'Pages Count', 'Notes'],
            app(ReportingService::class)->memorizationRows($this->validatedFilters($request)),
        );
    }

    /**
     * Download the point ledger report as XLSX.
     */
    public function points(Request $request): StreamedResponse
    {
        return $this->xlsxDownload(
            'points-report',
            ['Entered At', 'Academic Year', 'Group', 'Course', 'Student', 'Point Type', 'Policy', 'Source Type', 'Points', 'Notes'],
            app(ReportingService::class)->pointRows($this->validatedFilters($request)),
        );
    }

    public function finance(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('finance.reports.export'), 403);

        $validated = $request->validate([
            'quarter' => ['nullable', 'integer', 'between:1,4'],
            'year' => ['required', 'integer', 'between:2000,2100'],
        ]);

        return $this->xlsxDownload(
            'finance-report',
            ['Date', 'Transaction No', 'Cash Box', 'Currency', 'Type', 'Direction', 'Amount', 'Signed Amount', 'Base Amount', 'Local Amount', 'Category', 'Activity', 'Teacher', 'Description'],
            app(FinanceReportService::class)->exportRows((int) $validated['year'], isset($validated['quarter']) ? (int) $validated['quarter'] : null),
        );
    }

    public function financeLedger(Request $request)
    {
        abort_unless($request->user()?->can('finance.reports.export'), 403);

        $validated = $request->validate([
            'cash_box_id' => ['required', 'integer', 'exists:finance_cash_boxes,id'],
            'currency_id' => ['required', 'integer', 'exists:finance_currencies,id'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'format' => ['required', 'in:xlsx,pdf'],
            'template_id' => ['nullable', 'integer', 'exists:finance_report_templates,id'],
        ]);

        $financeService = app(FinanceService::class);
        $reportService = app(FinanceReportService::class);
        $template = isset($validated['template_id'])
            ? FinanceReportTemplate::query()->findOrFail((int) $validated['template_id'])
            : $reportService->defaultLedgerTemplate();
        $cashBox = $financeService->cashBoxForUser((int) $validated['cash_box_id'], $request->user());
        $currency = $financeService->currenciesForCashBox($cashBox->id)
            ->whereKey((int) $validated['currency_id'])
            ->firstOrFail();
        $report = $reportService->ledgerReport($template, $cashBox, $currency, $validated['date_from'], $validated['date_to'], $request->user());
        $generatedReport = $reportService->storeGeneratedLedgerReport($report, $validated, $request->user());

        if ($validated['format'] === 'pdf') {
            return view('reports.finance-ledger-pdf', [
                'generatedReport' => $generatedReport,
                'report' => $report,
                'service' => $reportService,
            ]);
        }

        $rows = $reportService->ledgerExportRowsFromReport($report);
        $headers = array_shift($rows) ?: [data_get($report, 'template.title', $template->title)];

        return $this->xlsxDownload('finance-ledger-report', $headers, $rows);
    }

    public function generatedFinanceLedger(Request $request, int $generatedReport)
    {
        abort_unless($request->user()?->can('finance.reports.export'), 403);
        abort_unless(FinanceGeneratedReport::storageIsReady(), 404);

        $generatedReport = FinanceGeneratedReport::query()
            ->with('generatedBy')
            ->findOrFail($generatedReport);
        abort_unless($generatedReport->report_type === 'ledger', 404);

        $validated = $request->validate([
            'format' => ['nullable', 'in:xlsx,pdf'],
        ]);

        $reportService = app(FinanceReportService::class);
        $report = $reportService->generatedLedgerReport($generatedReport);

        if (($validated['format'] ?? 'pdf') === 'pdf') {
            return view('reports.finance-ledger-pdf', [
                'generatedReport' => $generatedReport,
                'report' => $report,
                'service' => $reportService,
            ]);
        }

        $rows = $reportService->ledgerExportRowsFromReport($report);
        $headers = array_shift($rows) ?: [data_get($report, 'template.title', __('finance.report_templates.default_title'))];

        return $this->xlsxDownload('finance-ledger-report', $headers, $rows);
    }

    protected function xlsxDownload(string $filenamePrefix, array $headers, array $rows): StreamedResponse
    {
        return app(XlsxExportService::class)->download($filenamePrefix, $headers, $rows);
    }

    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'assessment_type_id' => ['nullable', 'integer', 'exists:assessment_types,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
        ]);

        if (($validated['group_id'] ?? null) !== null) {
            $scopedGroup = app(AccessScopeService::class)
                ->scopeGroups(\App\Models\Group::query(), $request->user())
                ->whereKey($validated['group_id'])
                ->exists();

            abort_unless($scopedGroup, 403);
        }

        return $validated;
    }
}
