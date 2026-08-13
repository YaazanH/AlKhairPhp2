<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Services\PdfBrandingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PdfBrandingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_logo_uses_only_the_file_uploaded_in_main_page_settings(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('settings/branding/report-logo.png', 'configured-png');
        Storage::disk('public')->put('website/branding/logo.jpeg', 'website-logo');
        AppSetting::storeValue('general', 'pdf_logo_path', 'settings/branding/report-logo.png');
        AppSetting::storeValue('website', 'logo_path', 'website/branding/logo.jpeg');

        $this->assertSame(
            'data:image/png;base64,'.base64_encode('configured-png'),
            app(PdfBrandingService::class)->logoSource(),
        );
    }

    public function test_pdf_logo_does_not_fall_back_to_the_public_website_logo(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('website/branding/logo.jpeg', 'website-logo');
        AppSetting::storeValue('website', 'logo_path', 'website/branding/logo.jpeg');

        $this->assertNull(app(PdfBrandingService::class)->logoSource());
    }

    public function test_pdf_logo_accepts_a_storage_prefixed_path(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('settings/branding/report-logo.png', 'configured-png');
        AppSetting::storeValue('general', 'pdf_logo_path', '/storage/settings/branding/report-logo.png');

        $this->assertSame(
            'data:image/png;base64,'.base64_encode('configured-png'),
            app(PdfBrandingService::class)->logoSource(),
        );
    }
}
