<?php

namespace App\Http\Controllers\IdCards;

use App\Http\Controllers\Controller;
use App\Services\IdCards\Code39SvgRenderer;
use App\Services\IdCards\QrCodeSvgRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class IdCardBarcodePreviewController extends Controller
{
    public function __construct(
        protected Code39SvgRenderer $code39Renderer,
        protected QrCodeSvgRenderer $qrCodeRenderer,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'format' => ['nullable', Rule::in(['code39', 'qrcode'])],
            'value' => ['nullable', 'string', 'max:64'],
            'width' => ['nullable', 'numeric', 'min:8', 'max:160'],
            'height' => ['nullable', 'numeric', 'min:8', 'max:120'],
            'show_text' => ['nullable', 'boolean'],
        ]);

        $format = $validated['format'] ?? 'code39';
        $value = (string) ($validated['value'] ?? '');
        $options = [
            'width' => (float) ($validated['width'] ?? ($format === 'qrcode' ? 24 : 50)),
            'height' => (float) ($validated['height'] ?? ($format === 'qrcode' ? 28 : 14)),
            'show_text' => (bool) ($validated['show_text'] ?? true),
            'font_size' => 2.8,
        ];

        $svg = $format === 'qrcode'
            ? $this->qrCodeRenderer->render($value, $options)
            : $this->code39Renderer->render($value, $options);

        return response($svg ?: $this->fallbackSvg(), 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    protected function fallbackSvg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 20" role="img" aria-label="No barcode"><rect width="100%" height="100%" fill="#fff"/><text x="16" y="11" text-anchor="middle" font-size="3" fill="#111">N/A</text></svg>';
    }
}
