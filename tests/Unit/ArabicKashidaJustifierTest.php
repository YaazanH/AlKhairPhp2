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
}
