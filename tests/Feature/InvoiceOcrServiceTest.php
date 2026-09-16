<?php

namespace Tests\Feature;

use App\Services\InvoiceCaptureService;
use App\Services\InvoiceOcrService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InvoiceOcrServiceTest extends TestCase
{
    private string $work;

    protected function setUp(): void
    {
        parent::setUp();
        config(['invoice_capture.engine' => 'tesseract']);
        $this->work = sys_get_temp_dir().'/invoice-ocr-test-'.Str::uuid();
        File::ensureDirectoryExists($this->work, 0700);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->work);
        parent::tearDown();
    }

    public function test_real_pdf_text_and_scanned_pdf_are_read_without_an_external_service(): void
    {
        $this->requireExecutables();
        config(['invoice_capture.languages' => 'eng']);
        $pdf = new Mpdf(['tempDir' => $this->work, 'default_font' => 'dejavusans', 'default_font_size' => 18]);
        $pdf->WriteHTML('<h1>Example Stationery</h1><p>Invoice No: TEST-18<br>Date: 06/09/2026</p><table cellpadding="10"><tr><th>Item</th><th>Qty</th><th>Price</th><th>Amount</th></tr><tr><td>Notebooks</td><td>2</td><td>10</td><td>20</td></tr></table><p>Grand Total: 20 USD</p>');
        $pdf->Output($this->work.'/text.pdf', 'F');
        $plain = app(InvoiceCaptureService::class)->capture(new UploadedFile($this->work.'/text.pdf', 'invoice.pdf', 'application/pdf', null, true));
        $this->assertSame('TEST-18', $plain['original_invoice_no']);
        $this->assertSame(20.0, $plain['total']);
        $visionPages = app(InvoiceOcrService::class)->images(new UploadedFile($this->work.'/text.pdf', 'invoice.pdf', 'application/pdf', null, true));
        $this->assertCount(5, $visionPages);
        $dimensions = getimagesizefromstring(base64_decode($visionPages[0]['image']));
        $this->assertSame('image/png', $dimensions['mime']);
        $this->assertSame(1280, max($dimensions[0], $dimensions[1]));
        $detail = getimagesizefromstring(base64_decode($visionPages[1]['image']));
        $this->assertSame(1536, max($detail[0], $detail[1]));

        $render = new Process([config('invoice_capture.pdftoppm_binary'), '-singlefile', '-scale-to', '2400', '-png', $this->work.'/text.pdf', $this->work.'/scan']);
        $render->mustRun();
        $scannedPdf = new Mpdf(['tempDir' => $this->work]);
        $scannedPdf->AddPage();
        $scannedPdf->Image($this->work.'/scan.png', 0, 0, 210, 297);
        $scannedPdf->Output($this->work.'/scan.pdf', 'F');
        $before = glob(storage_path('framework/cache/invoice-ocr/*')) ?: [];
        $scanned = app(InvoiceCaptureService::class)->capture(new UploadedFile($this->work.'/scan.pdf', 'scan.pdf', 'application/pdf', null, true));
        $this->assertSame('TEST-18', $scanned['original_invoice_no']);
        $this->assertSame('2026-09-06', $scanned['invoice_date']);
        $this->assertSame(20.0, $scanned['total']);
        $this->assertSame($before, glob(storage_path('framework/cache/invoice-ocr/*')) ?: []);
    }

    public function test_long_pdfs_are_rejected_and_intermediate_files_are_cleaned(): void
    {
        $this->requireExecutables();
        $pdf = new Mpdf(['tempDir' => $this->work]);
        $pdf->WriteHTML('Page one<pagebreak />Page two<pagebreak />Page three<pagebreak />Page four');
        $pdf->Output($this->work.'/long.pdf', 'F');
        $before = glob(storage_path('framework/cache/invoice-ocr/*')) ?: [];
        foreach (['read', 'images'] as $method) {
            try {
                app(InvoiceOcrService::class)->{$method}(new UploadedFile($this->work.'/long.pdf', 'long.pdf', 'application/pdf', null, true));
                $this->fail('A four-page document should be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertSame('invoice_capture.errors.page_limit', $exception->getMessage());
            }
            $this->assertSame($before, glob(storage_path('framework/cache/invoice-ocr/*')) ?: []);
        }
    }

    public function test_three_pdf_pages_keep_their_detail_views_associated_with_the_correct_page(): void
    {
        $this->requireExecutables();
        $pdf = new Mpdf(['tempDir' => $this->work]);
        $pdf->WriteHTML('First page<pagebreak />Second page<pagebreak />Third page');
        $pdf->Output($this->work.'/three.pdf', 'F');
        $before = glob(storage_path('framework/cache/invoice-ocr/*')) ?: [];

        $views = app(InvoiceOcrService::class)->images(new UploadedFile($this->work.'/three.pdf', 'three.pdf', 'application/pdf', null, true));

        $this->assertCount(15, $views);
        foreach (array_chunk($views, 5) as $index => $pageViews) {
            $this->assertSame('Page '.($index + 1).': full page', $pageViews[0]['label']);
            foreach ($pageViews as $view) {
                $this->assertStringStartsWith('Page '.($index + 1).': ', $view['label']);
            }
        }
        $this->assertSame($before, glob(storage_path('framework/cache/invoice-ocr/*')) ?: []);
    }

    public function test_small_ink_marks_survive_lossless_detail_views_including_at_crop_boundaries(): void
    {
        $image = imagecreatetruecolor(2000, 2000);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $ink = imagecolorallocate($image, 45, 38, 102);
        // A row of tiny zero-sized marks crosses the centre and is visible in all
        // overlapping views. Assert against the pixels, not model accuracy.
        foreach ([960, 1000, 1040] as $x) {
            imagefilledrectangle($image, $x - 2, 998, $x + 2, 1002, $ink);
        }
        imagepng($image, $this->work.'/dots.png');
        $before = glob(storage_path('framework/cache/invoice-ocr/*')) ?: [];
        $views = app(InvoiceOcrService::class)->images(new UploadedFile($this->work.'/dots.png', 'dots.png', 'image/png', null, true));

        $this->assertCount(5, $views);
        foreach ([[1, 0, 0], [2, 800, 0], [3, 0, 800], [4, 800, 800]] as [$index, $left, $top]) {
            $bytes = base64_decode($views[$index]['image']);
            $this->assertSame('image/png', getimagesizefromstring($bytes)['mime']);
            $detail = imagecreatefromstring($bytes);
            foreach ([960, 1000, 1040] as $x) {
                $pixel = imagecolorat($detail, (int) round(($x - $left) * 1.28), (int) round((1000 - $top) * 1.28));
                $this->assertSame($ink, $pixel, 'Small ink dots must survive the detail view.');
            }
        }
        $this->assertSame($before, glob(storage_path('framework/cache/invoice-ocr/*')) ?: []);
    }

    public function test_missing_ocr_has_a_recoverable_error_and_cleans_intermediate_files(): void
    {
        config(['invoice_capture.tesseract_binary' => $this->work.'/missing-tesseract']);
        $before = glob(storage_path('framework/cache/invoice-ocr/*')) ?: [];
        try {
            app(InvoiceOcrService::class)->read(UploadedFile::fake()->image('invoice.png'));
            $this->fail('A missing OCR executable should be reported.');
        } catch (RuntimeException $exception) {
            $this->assertSame('invoice_capture.errors.unavailable', $exception->getMessage());
        }
        $this->assertSame($before, glob(storage_path('framework/cache/invoice-ocr/*')) ?: []);
    }

    private function requireExecutables(): void
    {
        foreach (['tesseract', 'pdfinfo', 'pdftotext', 'pdftoppm'] as $binary) {
            $path = (new ExecutableFinder)->find($binary);
            if (! $path) {
                $this->markTestSkipped('Local invoice OCR requires '.$binary.'.');
            }
            config(['invoice_capture.'.$binary.'_binary' => $path]);
        }
    }
}
