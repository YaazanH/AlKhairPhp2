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

        if (is_string($path) && $path !== '' && Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->path($path);
        }

        return null;
    }
}
