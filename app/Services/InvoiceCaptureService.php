<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;

class InvoiceCaptureService
{
    public function __construct(private InvoiceOcrService $reader, private InvoiceDraftParser $parser) {}

    public function capture(UploadedFile $file): array
    {
        if (config('invoice_capture.engine') === 'vision') {
            return app(InvoiceVisionService::class)->capture($file);
        }
        if (config('invoice_capture.engine') !== 'tesseract') {
            throw new RuntimeException('invoice_capture.errors.unavailable');
        }
        $text = $this->reader->read($file);
        if (trim($text) === '') {
            throw new RuntimeException('invoice_capture.errors.no_text');
        }

        return $this->parser->parse($text);
    }
}
