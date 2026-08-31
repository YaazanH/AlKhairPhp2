<?php

namespace Tests\Unit;

use App\Support\PdfOptions;
use Tests\TestCase;

class PdfOptionsTest extends TestCase
{
    public function test_pdf_generation_raises_a_small_runtime_memory_limit(): void
    {
        $originalLimit = ini_get('memory_limit');

        try {
            $previousLimit = ini_set('memory_limit', '64M');

            if ($previousLimit === false) {
                $this->markTestSkipped('The PHP runtime does not permit changing memory_limit.');
            }

            PdfOptions::make();

            $this->assertSame('256M', ini_get('memory_limit'));
        } finally {
            if ($originalLimit !== false) {
                ini_set('memory_limit', $originalLimit);
            }
        }
    }

    public function test_attendance_exports_raise_memory_before_constructing_mpdf(): void
    {
        foreach ([
            app_path('Http/Controllers/TeacherAttendanceExportController.php'),
            app_path('Http/Controllers/StudentAttendanceExportController.php'),
        ] as $controller) {
            $source = file_get_contents($controller);
            $guardPosition = strpos($source, 'PdfOptions::ensureMemoryCapacity();');
            $mpdfPosition = strpos($source, 'new Mpdf(');

            $this->assertNotFalse($guardPosition);
            $this->assertNotFalse($mpdfPosition);
            $this->assertLessThan($mpdfPosition, $guardPosition);
        }
    }
}
