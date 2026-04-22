<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\WebsiteMenu;
use App\Models\WebsiteMenuItem;
use App\Models\WebsitePage;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class WebsiteService
{
    public function homePage(): WebsitePage
    {
        return WebsitePage::query()
            ->published()
            ->where('is_home', true)
            ->first()
            ?? new WebsitePage([
                'slug' => 'home',
                'template' => 'home',
                'title' => [
                    'en' => 'Masjid AlKhair',
                    'ar' => 'مسجد الخير',
                ],
                'excerpt' => [
                    'en' => 'A welcoming mosque website connected to the AlKhair platform.',
                    'ar' => 'واجهة مسجد مرحبة مرتبطة بمنصة الخير.',
                ],
                'sections' => [
                    [
                        'type' => 'hero',
                        'eyebrow' => [
                            'en' => 'Mosque homepage',
                            'ar' => 'الواجهة العامة للمسجد',
                        ],
                        'title' => [
                            'en' => 'A public front door for your community.',
                            'ar' => 'واجهة عامة لمجتمع المسجد.',
                        ],
                        'subtitle' => [
                            'en' => 'Seed the website module to replace this starter content with your own pages, photos, and programs.',
                            'ar' => 'قم بزرع بيانات الموقع لاستبدال هذا المحتوى الافتراضي بصفحاتكم وصوركم وبرامجكم.',
                        ],
                        'primary_cta_label' => [
                            'en' => 'Login',
                            'ar' => 'تسجيل الدخول',
                        ],
                        'primary_cta_url' => route('login'),
                        'secondary_cta_label' => [
                            'en' => 'Dashboard',
                            'ar' => 'لوحة التحكم',
                        ],
                        'secondary_cta_url' => route('dashboard'),
                    ],
                    [
                        'type' => 'stats',
                        'items' => [
                            ['value' => '2', 'label' => ['en' => 'Supported languages', 'ar' => 'لغتان مدعومتان']],
                            ['value' => '1', 'label' => ['en' => 'Public homepage', 'ar' => 'صفحة رئيسية عامة']],
                            ['value' => '0', 'label' => ['en' => 'Extra pages yet', 'ar' => 'صفحات إضافية حالياً']],
                        ],
                    ],
                    [
                        'type' => 'story',
                        'title' => [
                            'en' => 'Start with the website settings page.',
                            'ar' => 'ابدأ من إعدادات الموقع.',
                        ],
                        'body' => [
                            'en' => 'The website customization module controls this homepage, the header navigation, and the bilingual public pages.',
                            'ar' => 'وحدة تخصيص الموقع تتحكم في هذه الصفحة الرئيسية وفي ترويسة التنقل وفي الصفحات العامة ثنائية اللغة.',
                        ],
                        'quote' => [
                            'en' => 'Public site first, dashboard second.',
                            'ar' => 'الموقع العام أولاً، ولوحة التحكم ثانياً.',
                        ],
                    ],
                    [
                        'type' => 'programs',
                        'title' => [
                            'en' => 'Next steps',
                            'ar' => 'الخطوات التالية',
                        ],
                        'subtitle' => [
                            'en' => 'Seed the website content, then tailor it from the admin workspace.',
                            'ar' => 'قم بزرع محتوى الموقع ثم عدله من داخل لوحة الإدارة.',
                        ],
                        'cards' => [
                            [
                                'title' => ['en' => 'Website Identity', 'ar' => 'هوية الموقع'],
                                'summary' => ['en' => 'Set logo, hero content, media, and contact details.', 'ar' => 'حدد الشعار ومحتوى الواجهة والوسائط وبيانات التواصل.'],
                                'link_label' => ['en' => 'Open admin', 'ar' => 'افتح الإدارة'],
                                'link_url' => route('login'),
                            ],
                        ],
                    ],
                ],
            ]);
    }

    public function navigationPages(): EloquentCollection
    {
        return WebsitePage::query()
            ->published()
            ->where('show_in_navigation', true)
            ->where('is_home', false)
            ->orderBy('navigation_order')
            ->orderBy('id')
            ->get();
    }

    public function navigationMenu(): array
    {
        $menu = WebsiteMenu::query()
            ->where('key', 'primary')
            ->with([
                'items' => fn ($query) => $query->with('page')->orderBy('sort_order')->orderBy('id'),
            ])
            ->first();

        if (! $menu) {
            return $this->fallbackNavigationMenu();
        }

        $items = $menu->items
            ->filter(function (WebsiteMenuItem $item): bool {
                if (! $item->is_active) {
                    return false;
                }

                if ($item->page && ! $item->page->is_published) {
                    return false;
                }

                return true;
            })
            ->values();

        $tree = $items
            ->whereNull('parent_id')
            ->map(fn (WebsiteMenuItem $item): ?array => $this->transformMenuItem($item, $items))
            ->filter()
            ->values()
            ->all();

        return $tree !== [] ? $tree : $this->fallbackNavigationMenu();
    }

    public function siteSettings(): array
    {
        $website = AppSetting::groupValues('website');
        $general = AppSetting::groupValues('general');

        $siteName = $website->get('site_name') ?: $general->get('school_name') ?: __('ui.app.name');
        $tagline = $website->get('site_tagline') ?: [
            'en' => 'Quran, community, and family learning under one roof.',
            'ar' => 'القرآن والمجتمع وتعلّم الأسرة تحت سقف واحد.',
        ];
        $description = $website->get('site_description') ?: [
            'en' => 'A bilingual mosque website connected to the AlKhair platform.',
            'ar' => 'موقع مسجد ثنائي اللغة مرتبط بمنصة الخير.',
        ];
        $address = $website->get('contact_address') ?: [
            'en' => (string) ($general->get('school_address') ?: 'Damascus'),
            'ar' => (string) ($general->get('school_address') ?: 'دمشق'),
        ];
        $galleryItems = collect($website->get('gallery_items') ?: [])
            ->map(function (array $item) {
                $path = (string) data_get($item, 'path', '');

                return [
                    'path' => $path,
                    'url' => $this->mediaUrl($path),
                    'caption' => data_get($item, 'caption_'.app()->getLocale())
                        ?: data_get($item, 'caption_'.config('app.fallback_locale', 'en'))
                        ?: '',
                ];
            })
            ->filter(fn (array $item) => filled($item['url']))
            ->values();

        if ($galleryItems->isEmpty()) {
            $galleryItems = collect($website->get('gallery_paths') ?: [])
                ->map(fn (mixed $path) => [
                    'path' => is_string($path) ? $path : '',
                    'url' => $this->mediaUrl(is_string($path) ? $path : null),
                    'caption' => '',
                ])
                ->filter(fn (array $item) => filled($item['url']))
                ->values();
        }

        return [
            'site_name' => $siteName,
            'site_tagline' => $tagline,
            'site_description' => $description,
            'contact_phone' => $website->get('contact_phone') ?: $general->get('school_phone'),
            'contact_email' => $website->get('contact_email') ?: $general->get('school_email'),
            'contact_address' => $address,
            'primary_color' => $website->get('primary_color') ?: '#006b2d',
            'accent_color' => $website->get('accent_color') ?: '#0b8f43',
            'logo_path' => $website->get('logo_path'),
            'hero_image_path' => $website->get('hero_image_path'),
            'featured_video_path' => $website->get('featured_video_path'),
            'gallery_paths' => $website->get('gallery_paths') ?: [],
            'gallery_items' => $galleryItems->all(),
            'maps_url' => $website->get('maps_url'),
            'whatsapp_url' => $website->get('whatsapp_url'),
            'logo_url' => $this->mediaUrl($website->get('logo_path')),
            'hero_image_url' => $this->mediaUrl($website->get('hero_image_path')),
            'featured_video_url' => $this->mediaUrl($website->get('featured_video_path')),
            'gallery_urls' => $galleryItems->pluck('url')->all(),
        ];
    }

    public function resolveMetaTitle(WebsitePage $page): string
    {
        return $page->localizedText('seo_title')
            ?: $page->localizedText('title')
            ?: $this->siteSettings()['site_name'];
    }

    public function resolveMetaDescription(WebsitePage $page): string
    {
        return $page->localizedText('seo_description')
            ?: $page->localizedText('excerpt')
            ?: data_get($this->siteSettings(), 'site_description.'.app()->getLocale())
            ?: data_get($this->siteSettings(), 'site_description.'.config('app.fallback_locale', 'en'), '');
    }

    public function mediaUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://', '/'])) {
            return $path;
        }

        return asset('storage/'.ltrim($path, '/'));
    }

    protected function fallbackNavigationMenu(): array
    {
        return $this->navigationPages()
            ->map(fn (WebsitePage $page): array => [
                'label' => $page->localizedText('navigation_label') ?: $page->localizedText('title'),
                'url' => route('website.pages.show', $page),
                'open_in_new_tab' => false,
                'children' => [],
            ])
            ->values()
            ->all();
    }

    protected function transformMenuItem(WebsiteMenuItem $item, Collection $items): ?array
    {
        $children = $items
            ->where('parent_id', $item->id)
            ->map(fn (WebsiteMenuItem $child): ?array => $this->transformMenuItem($child, $items))
            ->filter()
            ->values()
            ->all();

        $url = filled($item->url)
            ? $item->url
            : ($item->page ? route('website.pages.show', $item->page) : null);
        $label = $item->localizedLabel();

        if (blank($label) && $children === [] && blank($url)) {
            return null;
        }

        return [
            'label' => $label,
            'url' => $url,
            'open_in_new_tab' => $item->open_in_new_tab,
            'children' => $children,
        ];
    }
}
