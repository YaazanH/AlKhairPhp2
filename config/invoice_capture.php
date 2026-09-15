<?php

return [
    'engine' => env('INVOICE_CAPTURE_ENGINE', 'vision'),
    'vision_url' => env('INVOICE_VISION_URL', 'http://127.0.0.1:11434'),
    'vision_model' => env('INVOICE_VISION_MODEL', 'qwen3.5:9b'),
    'vision_timeout_seconds' => (int) env('INVOICE_VISION_TIMEOUT', 120),
    'tesseract_binary' => env('INVOICE_OCR_TESSERACT', 'tesseract'),
    'pdftotext_binary' => env('INVOICE_OCR_PDFTOTEXT', 'pdftotext'),
    'pdftoppm_binary' => env('INVOICE_OCR_PDFTOPPM', 'pdftoppm'),
    'pdfinfo_binary' => env('INVOICE_OCR_PDFINFO', 'pdfinfo'),
    'languages' => env('INVOICE_OCR_LANGUAGES', 'ara+eng'),
    'tessdata_directory' => env('INVOICE_OCR_TESSDATA'),
    'max_pages' => 3,
    'timeout_seconds' => 45,
    'max_image_pixels' => 25000000,
];
