<?php

namespace App\Services\IdCards;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Data\QRMatrix;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Throwable;

class QrCodeSvgRenderer
{
    public function render(string $value, array $options = []): ?string
    {
        $encodedValue = trim($value);

        if ($encodedValue === '') {
            return null;
        }

        $qr = $this->qrMarkup($encodedValue);

        if ($qr === null) {
            return null;
        }

        return $this->svg($qr, $encodedValue, $options);
    }

    private function qrMarkup(string $value): ?array
    {
        try {
            $svg = (new QRCode(new QROptions([
                'eccLevel' => EccLevel::M,
                'outputBase64' => false,
                'svgAddXmlHeader' => false,
                'drawLightModules' => false,
                'svgUseFillAttributes' => true,
                'moduleValues' => $this->darkModuleValues(),
            ])))->render($value);
        } catch (Throwable) {
            return null;
        }

        if (! preg_match('/viewBox="0 0 ([\d.]+) ([\d.]+)"/', $svg, $viewBoxMatch)) {
            return null;
        }

        if (! preg_match('/<svg\b[^>]*>(.*)<\/svg>/s', $svg, $contentMatch)) {
            return null;
        }

        return [
            'width' => (float) $viewBoxMatch[1],
            'height' => (float) $viewBoxMatch[2],
            'content' => trim($contentMatch[1]),
        ];
    }

    private function darkModuleValues(): array
    {
        return [
            QRMatrix::M_DARKMODULE => 'currentColor',
            QRMatrix::M_DATA_DARK => 'currentColor',
            QRMatrix::M_FINDER_DARK => 'currentColor',
            QRMatrix::M_ALIGNMENT_DARK => 'currentColor',
            QRMatrix::M_TIMING_DARK => 'currentColor',
            QRMatrix::M_FORMAT_DARK => 'currentColor',
            QRMatrix::M_VERSION_DARK => 'currentColor',
            QRMatrix::M_FINDER_DOT => 'currentColor',
            QRMatrix::M_LOGO_DARK => 'currentColor',
            QRMatrix::M_SEPARATOR_DARK => 'currentColor',
            QRMatrix::M_QUIETZONE_DARK => 'currentColor',
        ];
    }

    private function svg(array $qr, string $value, array $options): string
    {
        $width = max((float) ($options['width'] ?? 20), 10);
        $height = max((float) ($options['height'] ?? 20), 10);
        $showText = (bool) ($options['show_text'] ?? true);
        $fontSize = max((float) ($options['font_size'] ?? 2.8), 2.2);
        $textHeight = $showText ? max($fontSize * 1.35, 3.2) : 0;
        $codeHeight = max($height - $textHeight, 6);
        $boxSize = min($width, $codeHeight);
        $originX = ($width - $boxSize) / 2;
        $originY = $showText ? 0 : (($height - $boxSize) / 2);
        $scale = $boxSize / max((float) $qr['width'], (float) $qr['height'], 1);
        $textNode = '';

        if ($showText) {
            $textNode = sprintf(
                '<text x="%s" y="%s" font-size="%s" text-anchor="middle" font-family="system-ui, sans-serif" fill="currentColor">%s</text>',
                $this->format($width / 2),
                $this->format($height - ($textHeight * 0.25)),
                $this->format($fontSize),
                e($value),
            );
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$s %2$s" width="100%%" height="100%%" preserveAspectRatio="xMidYMid meet" role="img" data-code-type="qrcode" aria-label="%3$s"><rect width="100%%" height="100%%" fill="#fff" /><g transform="translate(%4$s %5$s) scale(%6$s)" shape-rendering="crispEdges">%7$s</g>%8$s</svg>',
            $this->format($width),
            $this->format($height),
            e('QR code '.$value),
            $this->format($originX),
            $this->format($originY),
            $this->format($scale),
            $qr['content'],
            $textNode,
        );
    }

    private function format(float $value): string
    {
        return number_format($value, 3, '.', '');
    }
}
