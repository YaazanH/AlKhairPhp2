<?php

namespace Tests\Unit;

use App\Models\PrintTemplate;
use App\Models\Student;
use App\Services\PrintTemplates\PrintTemplateFieldRegistry;
use App\Services\PrintTemplates\PrintTemplateRenderService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PrintTemplateArabicJustificationTest extends TestCase
{
    public function test_it_adds_real_balanced_kashidas_to_justified_arabic_text(): void
    {
        $rendered = app(PrintTemplateRenderService::class)->render($this->template(
            'برنامج تعليم القرآن الكريم للطلاب المتميزين',
            56,
        ));

        $value = $rendered['elements'][0]['resolved']['value'];

        $this->assertStringContainsString("\u{0640}", $value);
        $this->assertStringNotContainsString('Ù€', $value);
        $this->assertLessThanOrEqual(12, substr_count($value, "\u{0640}"));
        $this->assertDoesNotMatchRegularExpression('/\x{0640}{3,}/u', $value);
    }

    public function test_it_leaves_short_justified_arabic_text_unstretched_for_centering(): void
    {
        $rendered = app(PrintTemplateRenderService::class)->render($this->template('مسجد الخير', 90));

        $this->assertStringNotContainsString("\u{0640}", $rendered['elements'][0]['resolved']['value']);
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

    private function template(string $content, float $width): PrintTemplate
    {
        return new PrintTemplate([
            'name' => 'Arabic justification',
            'width_mm' => 100,
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
