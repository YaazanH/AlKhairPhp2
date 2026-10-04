<?php

namespace Tests\Unit;

use App\Models\PrintTemplate;
use App\Models\Student;
use App\Services\PrintTemplates\PrintTemplateFieldRegistry;
use App\Services\PrintTemplates\PrintTemplateRenderService;
use App\Support\PdfOptions;
use Illuminate\Support\Carbon;
use Mpdf\Mpdf;
use Tests\TestCase;

class PrintTemplateArabicJustificationTest extends TestCase
{
    public function test_it_adds_real_balanced_kashidas_to_justified_arabic_text(): void
    {
        $rendered = app(PrintTemplateRenderService::class)->render($this->template(
            'استلمنا من السيد محمد',
            90,
        ));

        $value = $rendered['elements'][0]['resolved']['value'];

        $this->assertStringContainsString("\u{0640}", $value);
        $this->assertStringNotContainsString('Ù€', $value);
        $this->assertLessThanOrEqual(640, substr_count($value, "\u{0640}"));
    }

    public function test_it_stretches_short_justified_arabic_text_instead_of_leaving_it_centered(): void
    {
        $rendered = app(PrintTemplateRenderService::class)->render($this->template('مسجد الخير', 90));

        $this->assertStringContainsString("\u{0640}", $rendered['elements'][0]['resolved']['value']);
    }

    public function test_pdf_justification_uses_the_actual_dubai_font_width(): void
    {
        $service = app(PrintTemplateRenderService::class);
        $mpdf = new Mpdf(PdfOptions::make());
        $rendered = $service->render($this->template('استلمنا من السيد غير متاح', 120));
        $pages = $service->preparePdfPages([[$rendered]], $mpdf);
        $value = $pages[0][0]['elements'][0]['resolved']['value'];

        $mpdf->SetFont('dubai', '', 4.2 * 72 / 25.4);

        $this->assertStringContainsString("\u{0640}", $value);
        $this->assertGreaterThanOrEqual(118.5, $mpdf->GetStringWidth($value));
        $this->assertLessThanOrEqual(119.25, $mpdf->GetStringWidth($value));
    }

    public function test_dynamic_text_fields_include_and_resolve_the_current_date(): void
    {
        Carbon::setTestNow('2026-08-15 12:00:00');
        $registry = app(PrintTemplateFieldRegistry::class);
        $studentFields = collect($registry->selectableFields('dynamic_text'))
            ->firstWhere('entity', 'student')['fields'];

        $this->assertTrue(collect($studentFields)->contains('key', 'current_date'));
        $this->assertSame('15-08-2026', $registry->resolve(['student' => new Student], 'student', 'current_date'));

        Carbon::setTestNow();
    }

    public function test_pdf_template_keeps_rtl_and_justification_rules(): void
    {
        $source = file_get_contents(resource_path('views/print-templates/print/pdf.blade.php'));

        $this->assertStringContainsString('direction:{{ $textDirection }}', $source);
        $this->assertStringContainsString('unicode-bidi:isolate', $source);
        $this->assertStringContainsString("@if (\$textAlign === 'justify') text-align-last:justify;text-justify:auto;@endif", $source);
        $this->assertStringContainsString("background:{{ \$backgroundImageSource ? 'transparent' : '#f7fbf8' }}", $source);
    }

    private function template(string $content, float $width): PrintTemplate
    {
        return new PrintTemplate([
            'name' => 'Arabic justification',
            'width_mm' => max(100, $width + 4),
            'height_mm' => 60,
            'data_sources' => [],
            'layout_json' => [[
                'type' => 'custom_text',
                'content' => $content,
                'x' => 2,
                'y' => 2,
                'width' => $width,
                'height' => 14,
                'z_index' => 1,
                'styling' => [
                    'font_size' => 4.2,
                    'font_weight' => '500',
                    'color' => '#102316',
                    'text_align' => 'justify',
                ],
            ]],
        ]);
    }
}
