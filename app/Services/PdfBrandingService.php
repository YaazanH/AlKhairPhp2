<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Storage;

class PdfBrandingService
{
    public function logoSource(): ?string
    {
        $path = AppSetting::groupValues('website')->get('logo_path');

        if (is_string($path) && $path !== '' && Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->path($path);
        }

        return app(FinanceReportService::class)->defaultReportLogoPdfSource();
    }
}
