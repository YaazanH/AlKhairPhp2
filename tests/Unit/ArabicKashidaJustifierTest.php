<?php

namespace Tests\Unit;

use App\Support\ArabicKashidaJustifier;
use App\Support\PdfOptions;
use Mpdf\Mpdf;
use Tests\TestCase;

class ArabicKashidaJustifierTest extends TestCase
{
    public function test_it_stretches_shorter_arabic_roles_to_the_longest_measured_role(): void
    {
        $mpdf = new Mpdf(PdfOptions::make());
        $mpdf->SetFont('dubai', '', 11);
        $targetWidth = $mpdf->GetStringWidth('المعلم المساعد');
        $value = ArabicKashidaJustifier::toWidth(
            'معلم',
            $targetWidth,
            fn (string $text): float => $mpdf->GetStringWidth($text),
        );

        $this->assertStringContainsString('ـ', $value);
        $this->assertLessThanOrEqual($targetWidth, $mpdf->GetStringWidth($value));
        $this->assertLessThan(0.5, $targetWidth - $mpdf->GetStringWidth($value));
    }

    public function test_it_justifies_each_current_column_instance_to_its_own_longest_label(): void
    {
        $measure = static fn (string $value): float => (float) mb_strlen($value);

        $shortInstance = ArabicKashidaJustifier::column([
            'passed' => 'ناجح',
            'failed' => 'راسب',
        ], $measure);
        $longInstance = ArabicKashidaJustifier::column([
            'passed' => 'ناجح',
            'absent' => 'لم يتقدم',
        ], $measure);

        $this->assertSame(['passed', 'failed'], array_keys($shortInstance));
        $this->assertSame(mb_strlen($shortInstance['failed']), mb_strlen($shortInstance['passed']));
        $this->assertGreaterThan(
            mb_strlen($shortInstance['passed']),
            mb_strlen($longInstance['passed']),
        );
        $this->assertSame(mb_strlen($longInstance['absent']), mb_strlen($longInstance['passed']));
    }
}
