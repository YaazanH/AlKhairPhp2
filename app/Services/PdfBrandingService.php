<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Storage;

class PdfBrandingService
{
    public function logoSource(): ?string
    {
        $path = AppSetting::groupValues('general')->get('pdf_logo_path')
            ?: AppSetting::groupValues('website')->get('logo_path');

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $path = ltrim(trim($path), '/');

        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        if (! Storage::disk('public')->exists($path)) {
            return null;
        }

        $contents = Storage::disk('public')->get($path);

        if ($contents === '') {
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mimeType = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            default => Storage::disk('public')->mimeType($path) ?: 'application/octet-stream',
        };

        return 'data:'.$mimeType.';base64,'.base64_encode($contents);
    }
}
