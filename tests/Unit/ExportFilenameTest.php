<?php

namespace Tests\Unit;

use App\Models\FinanceGeneratedReport;
use App\Services\FinanceReportService;
use App\Support\ExportFilename;
use Tests\TestCase;

class ExportFilenameTest extends TestCase
{
    public function test_it_builds_readable_pdf_names_in_the_active_language(): void
    {
        app()->setLocale('en');

        $this->assertSame(
            'Student attendance - Summer Course.pdf',
            ExportFilename::pdf([
                __('exports.pdf.student_attendance'),
                'Summer Course',
                __('exports.pdf.date_range', ['from' => '01-08-2026', 'to' => '31-08-2026']),
            ]),
        );

        app()->setLocale('ar');

        $this->assertSame(
            'حضور الطلاب - الدورة الصيفية.pdf',
            ExportFilename::pdf([
                __('exports.pdf.student_attendance'),
                'الدورة الصيفية',
                __('exports.pdf.date_range', ['from' => '01-08-2026', 'to' => '31-08-2026']),
            ]),
        );
    }

    public function test_it_uses_the_first_two_non_empty_parts_only(): void
    {
        $this->assertSame(
            'تقرير المالية - Q2-2026.pdf',
            ExportFilename::pdf([
                'تقرير المالية',
                null,
                'Q2-2026',
                'الصندوق الرئيسي',
                '2026',
            ]),
        );
    }

    public function test_finance_report_pdf_names_use_the_quarter_or_custom_dates_instead_of_the_report_number(): void
    {
        app()->setLocale('ar');
        $service = app(FinanceReportService::class);

        $quarterlyReport = new FinanceGeneratedReport([
            'filters' => [
                'date_from' => '2026-04-01',
                'date_to' => '2026-06-30',
                'period_mode' => 'quarter',
            ],
            'report_data' => ['original_report_number' => 'RPT-0042'],
        ]);
        $customReport = new FinanceGeneratedReport([
            'filters' => [
                'date_from' => '2026-01-01',
                'date_to' => '2026-03-31',
                'period_mode' => 'custom',
            ],
            'report_data' => ['original_report_number' => 'RPT-0043'],
        ]);

        $quarterlyFilename = $service->ledgerPdfFilename([], $quarterlyReport);
        $customFilename = $service->ledgerPdfFilename([], $customReport);

        $this->assertSame('تقرير مالي - Q2-2026.pdf', $quarterlyFilename);
        $this->assertSame('تقرير مالي - 01-01-2026 - 31-03-2026.pdf', $customFilename);
        $this->assertStringNotContainsString('RPT-0042', $quarterlyFilename);
        $this->assertStringNotContainsString('RPT-0043', $customFilename);
    }

    public function test_it_emits_a_browser_safe_utf8_content_disposition(): void
    {
        app()->setLocale('ar');

        $disposition = ExportFilename::inlinePdf([
            __('exports.pdf.assessment_results'),
            'اختبار/الفصل',
        ], 'assessment-results-15.pdf');

        $this->assertStringContainsString('filename=assessment-results-15.pdf', $disposition);
        $this->assertStringContainsString("filename*=utf-8''", $disposition);
        $this->assertStringContainsString(rawurlencode('نتائج التقييم - اختبار-الفصل.pdf'), $disposition);
    }
}
