<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Landlord\Tenant;
use App\Models\WebsitePage;
use App\Services\Landlord\TenantContext;
use App\Services\WebsiteService;
use App\Support\BrandIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->assertSame('AlKhair Platform', app(BrandIdentity::class)->currentName());

        app(TenantContext::class)->set(new Tenant(['name' => 'Al Noor Learning Centre']));

        $this->assertSame('Al Noor Learning Centre', app(BrandIdentity::class)->currentName());

        $head = view('partials.head', ['title' => 'Dashboard'])->render();

        $this->assertStringContainsString('<title>Dashboard | Al Noor Learning Centre</title>', $head);
        $this->assertStringContainsString('property="og:site_name" content="Al Noor Learning Centre"', $head);
        $this->assertStringNotContainsString('جامع الخير', $head);
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
}
