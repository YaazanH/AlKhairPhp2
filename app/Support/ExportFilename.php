<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\HeaderUtils;

final class ExportFilename
{
    /**
     * Build a readable, filesystem-safe PDF filename from translated labels and context.
     *
     * @param  array<int, mixed>  $parts
     */
    public static function pdf(array $parts): string
    {
        $name = collect($parts)
            ->filter(fn ($part) => is_scalar($part) && trim((string) $part) !== '')
            ->map(fn ($part) => trim((string) $part))
            ->implode(' - ');

        $name = strip_tags($name);
        $name = preg_replace('/[<>:"\/\\|?*\x00-\x1F\x7F]+/u', '-', $name) ?: '';
        $name = preg_replace('/\s+/u', ' ', $name) ?: '';
        $name = trim($name, " .-\t\n\r\0\x0B");
        $name = $name !== '' ? $name : __('exports.pdf.document');

        while (strlen($name) > 200 && mb_strlen($name) > 1) {
            $name = mb_substr($name, 0, -1);
        }

        return rtrim($name, ' .-').'.pdf';
    }

    public static function inlinePdf(array $parts, string $asciiFallback): string
    {
        return HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_INLINE,
            self::pdf($parts),
            self::asciiFallback($asciiFallback),
        );
    }

    protected static function asciiFallback(string $filename): string
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'document.pdf';
        $filename = trim($filename, '.-');

        if (! str_ends_with(strtolower($filename), '.pdf')) {
            $filename .= '.pdf';
        }

        return $filename;
    }
}
