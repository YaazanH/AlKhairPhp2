<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Landlord\Tenant;
use App\Models\WebsitePage;
use App\Services\Landlord\TenantContext;
use App\Services\WebsiteService;
use App\Support\BrandIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BrandIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    public function test_platform_and_tenant_requests_use_the_correct_brand_name(): void
    {
        app()->setLocale('en');

        $branding = app(BrandIdentity::class);
        $this->assertSame('AlKhair Platform', $branding->currentName());
        $this->assertSame(asset('images/platform-brand/alkhair-platform-logo.png'), $branding->platformLogoUrl());
        $this->assertSame(asset('images/platform-brand/alkhair-platform-icon-v2.png'), $branding->platformIconUrl());

        app(TenantContext::class)->set(new Tenant(['name' => 'Al Noor Learning Centre']));

        $this->assertSame('Al Noor Learning Centre', app(BrandIdentity::class)->currentName());

        $head = view('partials.head', ['title' => 'Dashboard'])->render();

        $this->assertStringContainsString('<title>Dashboard | Al Noor Learning Centre</title>', $head);
        $this->assertStringContainsString('property="og:site_name" content="Al Noor Learning Centre"', $head);
        $this->assertStringNotContainsString('جامع الخير', $head);
    }

    public function test_mobile_logo_is_independent_of_the_main_logo_and_retains_default_mark(): void
    {
        $branding = app(BrandIdentity::class);
        $this->assertNull($branding->mobileLogoUrl());
        app(TenantContext::class)->set(new Tenant(['name' => 'Mobile centre', 'logo_path' => 'logo/main.png']));
        $default = Blade::render('<x-mobile-header-mark />');
        $this->assertStringContainsString('<svg', $default);

        AppSetting::storeValue('general', 'tenant_mobile_logo_path', 'logo/mobile/compact.png');
        $this->assertSame(asset('storage/logo/mobile/compact.png'), $branding->mobileLogoUrl());
        $this->assertSame(asset('storage/logo/main.png'), $branding->currentLogoUrl());
        $custom = Blade::render('<x-mobile-header-mark />');
        $this->assertStringContainsString('data-tenant-mobile-logo', $custom);
        $this->assertStringContainsString('storage/logo/mobile/compact.png', $custom);
        $this->assertStringNotContainsString('<svg', $custom);

        AppSetting::storeValue('general', 'tenant_mobile_logo_path', null);
        $this->assertStringContainsString('<svg', Blade::render('<x-mobile-header-mark />'));
        app(TenantContext::class)->clear();
        Schema::dropIfExists('app_settings');
        $this->assertNull($branding->mobileLogoUrl());
    }

    public function test_tenant_organisation_name_overrides_legacy_website_branding(): void
    {
        app(TenantContext::class)->set(new Tenant(['name' => 'Future Learning Centre']));
        AppSetting::storeValue('website', 'site_name', 'Masjid AlKhair');
        $home = WebsitePage::query()->create([
            'slug' => 'home',
            'template' => 'home',
            'title' => ['en' => 'Masjid AlKhair'],
            'seo_title' => ['en' => 'Masjid AlKhair'],
            'is_home' => true,
            'is_published' => true,
        ]);

        $website = app(WebsiteService::class);

        $this->assertSame('Future Learning Centre', $website->siteSettings()['site_name']);
        $this->assertSame('Future Learning Centre', $website->resolveMetaTitle($home));
    }

    public function test_platform_site_settings_do_not_query_tenant_settings(): void
    {
        app(TenantContext::class)->clear();
        Schema::dropIfExists('app_settings');

        $settings = app(WebsiteService::class)->siteSettings();

        $this->assertSame(app(BrandIdentity::class)->platformName(), $settings['site_name']);
        $this->assertNull($settings['logo_url']);
    }
}
