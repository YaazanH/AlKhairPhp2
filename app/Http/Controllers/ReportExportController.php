<?php

namespace App\Http\Controllers;

use App\Models\FinanceGeneratedReport;
use App\Models\Group;
use App\Services\AccessScopeService;
use App\Services\FinanceReportService;
use App\Services\FinanceService;
use App\Services\ReportingService;
use App\Services\XlsxExportService;
use App\Support\ExportFilename;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
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

    public function studentActivitySummary(Request $request): StreamedResponse
    {
        return $this->xlsxDownload(
            'student-progress-detail-report',
            ['Student', 'Current Juz', 'Memorized Pages', 'Latest Partial Saber', 'Passed Final Tests', 'Attended Days', 'Points', 'Group', 'Course', 'Academic Year'],
            app(ReportingService::class)->studentActivitySummaryRows($this->validatedFilters($request)),
        );
    }

    public function studentQuranTestSummary(Request $request): StreamedResponse
    {
        return $this->xlsxDownload(
            'student-quran-tests-report',
            ['Student', 'Partial Tests', 'Final Tests', 'Group', 'Course', 'Academic Year'],
            app(ReportingService::class)->studentQuranTestSummaryRows($this->validatedFilters($request)),
        );
    }

    public function financeLedger(Request $request)
    {
        abort_unless($request->user()?->can('finance.reports.export'), 403);
        abort_unless($request->user()?->financeSignaturePdfSource(), 422, __('finance.reports.signature_required'));

        $validated = $request->validate([
            'cash_box_id' => ['nullable', 'integer', 'exists:finance_cash_boxes,id'],
            'cash_box_ids' => ['nullable', 'array', 'min:1'],
            'cash_box_ids.*' => ['integer', 'distinct', 'exists:finance_cash_boxes,id'],
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'ledger_notes' => ['nullable', 'string', 'max:4000'],
            'format' => ['required', 'in:pdf'],
        ]);

        $financeService = app(FinanceService::class);
        $reportService = app(FinanceReportService::class);
        $template = $reportService->defaultLedgerTemplate();
        $cashBoxIds = collect($validated['cash_box_ids'] ?? [($validated['cash_box_id'] ?? null)])->filter()->map(fn ($id) => (int) $id)->unique()->values();
        abort_if($cashBoxIds->isEmpty(), 422);
        $reports = $cashBoxIds->flatMap(function (int $cashBoxId) use ($financeService, $reportService, $template, $validated, $request) {
            $box = $financeService->cashBoxForUser($cashBoxId, $request->user());
            $currencies = $financeService->currenciesForCashBox($box->id)->get();
            if ($currencies->isEmpty()) {
                $currencies = collect([$financeService->localCurrency()]);
            }

            return $currencies->map(fn ($currency) => $reportService->ledgerReport(
                $template,
                $box,
                $currency,
                $validated['date_from'],
                $validated['date_to'],
                $request->user(),
                $validated['ledger_notes'] ?? null,
            ));
        })->values();
        abort_if($reports->isEmpty(), 422);

        $report = $reports->first();
        if ($reports->count() > 1) {
            $report['cash_box']['name'] = $reports->pluck('cash_box.name')->unique()->implode('، ');
            $report['fund_reports'] = $reports->all();
            $report['rows'] = [];
        }
        $generatedReport = $reportService->storeGeneratedLedgerReport($report, $validated, $request->user());

        return $this->ledgerPdfResponse($reportService, $report, $generatedReport);
    }

    public function generatedFinanceLedger(Request $request, int $generatedReport)
    {
        abort_unless($request->user()?->can('finance.reports.export'), 403);
        abort_unless(FinanceGeneratedReport::storageIsReady(), 404);

        $generatedReport = FinanceGeneratedReport::query()
            ->with('generatedBy')
            ->findOrFail($generatedReport);
        abort_unless($generatedReport->report_type === 'ledger', 404);

        $reportService = app(FinanceReportService::class);
        $report = $reportService->generatedLedgerReport($generatedReport);

        return $this->ledgerPdfResponse($reportService, $report, $generatedReport);
    }

    protected function xlsxDownload(string $filenamePrefix, array $headers, array $rows): StreamedResponse
    {
        return app(XlsxExportService::class)->download($filenamePrefix, $headers, $rows);
    }

    protected function ledgerPdfResponse(FinanceReportService $reportService, array $report, ?FinanceGeneratedReport $generatedReport = null): Response
    {
        $filename = $reportService->ledgerPdfFilename($report, $generatedReport);
        $fallback = 'financial-report'.($generatedReport ? '-'.$generatedReport->id : '').'.pdf';
        $disposition = ExportFilename::inlinePdf([
            pathinfo($filename, PATHINFO_FILENAME),
        ], $fallback);
        $storedPath = $generatedReport ? $reportService->ensureStoredLedgerPdf($generatedReport, $report) : null;

        if ($storedPath !== null && Storage::disk('local')->exists($storedPath)) {
            return response(Storage::disk('local')->get($storedPath), 200, [
                'Content-Disposition' => $disposition,
                'Content-Type' => 'application/pdf',
            ]);
        }

        return response($reportService->renderLedgerPdf($report, $generatedReport), 200, [
            'Content-Disposition' => $disposition,
            'Content-Type' => 'application/pdf',
        ]);
    }

    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'assessment_type_id' => ['nullable', 'integer', 'exists:assessment_types,id'],
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
        ]);

        if (($validated['group_id'] ?? null) !== null) {
            $scopedGroup = app(AccessScopeService::class)
                ->scopeGroups(Group::query(), $request->user())
                ->whereKey($validated['group_id'])
                ->exists();

            abort_unless($scopedGroup, 403);
        }

        if (($validated['course_id'] ?? null) !== null) {
            $courseIsAccessible = app(AccessScopeService::class)
                ->scopeGroups(Group::query(), $request->user())
                ->where('course_id', $validated['course_id'])
                ->exists();

            abort_unless($courseIsAccessible, 403);
        }

        return $validated;
    }
}
