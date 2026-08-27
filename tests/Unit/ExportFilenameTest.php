<?php

namespace Tests\Unit;

use App\Support\ExportFilename;
use Tests\TestCase;

class ExportFilenameTest extends TestCase
{
    public function test_it_builds_readable_pdf_names_in_the_active_language(): void
    {
        app()->setLocale('en');

        $this->assertSame(
            'Student attendance - Summer Course - 01-08-2026 to 31-08-2026.pdf',
            ExportFilename::pdf([
                __('exports.pdf.student_attendance'),
                'Summer Course',
                __('exports.pdf.date_range', ['from' => '01-08-2026', 'to' => '31-08-2026']),
            ]),
        );

        app()->setLocale('ar');

        $this->assertSame(
            'حضور الطلاب - الدورة الصيفية - من 01-08-2026 إلى 31-08-2026.pdf',
            ExportFilename::pdf([
                __('exports.pdf.student_attendance'),
                'الدورة الصيفية',
                __('exports.pdf.date_range', ['from' => '01-08-2026', 'to' => '31-08-2026']),
            ]),
        );
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
