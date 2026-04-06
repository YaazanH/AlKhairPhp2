<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use App\Models\WebsiteMenu;
use App\Models\WebsiteMenuItem;
use App\Models\WebsitePage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class WebsiteSeeder extends Seeder
{
    public function run(): void
    {
        $logoPath = $this->seedMediaAsset('logo.jpeg', 'website/branding/logo.jpeg');
        $heroImagePath = $this->seedMediaAsset('WhatsApp Image 2026-03-22 at 2.14.18 PM.jpeg', 'website/gallery/hero.jpeg');
        $galleryPaths = array_values(array_filter([
            $this->seedMediaAsset('WhatsApp Image 2026-03-09 at 3.12.16 AM.jpeg', 'website/gallery/gallery-1.jpeg'),
            $this->seedMediaAsset('WhatsApp Image 2026-03-21 at 9.22.37 PM.jpeg', 'website/gallery/gallery-2.jpeg'),
            $this->seedMediaAsset('WhatsApp Image 2026-03-22 at 2.14.17 PM.jpeg', 'website/gallery/gallery-3.jpeg'),
        ]));
        $videoPath = $this->seedMediaAsset('Eid.mov', 'website/video/eid.mov');

        AppSetting::storeValue('website', 'site_name', 'Masjid AlKhair');
        AppSetting::storeValue('website', 'site_tagline', [
            'en' => 'Quran learning, family guidance, and a welcoming mosque community.',
            'ar' => 'تعليم القرآن والإرشاد الأسري ومجتمع مسجد مرحّب بالجميع.',
        ], 'array');
        AppSetting::storeValue('website', 'site_description', [
            'en' => 'A bilingual mosque homepage connected to the AlKhair platform, built for families, students, and the wider community.',
            'ar' => 'واجهة مسجد ثنائية اللغة مرتبطة بمنصة الخير ومصممة للعائلات والطلاب والمجتمع الأوسع.',
        ], 'array');
        AppSetting::storeValue('website', 'contact_phone', '+963 944 555 000');
        AppSetting::storeValue('website', 'contact_email', 'info@alkhair.test');
        AppSetting::storeValue('website', 'contact_address', [
            'en' => 'AlKhair Mosque, Damascus, Syria',
            'ar' => 'مسجد الخير، دمشق، سوريا',
        ], 'array');
        AppSetting::storeValue('website', 'primary_color', '#006b2d');
        AppSetting::storeValue('website', 'accent_color', '#0b8f43');
        AppSetting::storeValue('website', 'logo_path', $logoPath);
        AppSetting::storeValue('website', 'hero_image_path', $heroImagePath);
        AppSetting::storeValue('website', 'featured_video_path', $videoPath);
        AppSetting::storeValue('website', 'gallery_paths', $galleryPaths, 'array');

        WebsitePage::query()->updateOrCreate(
            ['slug' => 'home'],
            [
                'template' => 'home',
                'title' => [
                    'en' => 'Masjid AlKhair',
                    'ar' => 'مسجد الخير',
                ],
                'excerpt' => [
                    'en' => 'A public homepage for worship, learning, and family programs.',
                    'ar' => 'واجهة عامة للعبادة والتعلّم وبرامج العائلة.',
                ],
                'seo_title' => [
                    'en' => 'Masjid AlKhair',
                    'ar' => 'مسجد الخير',
                ],
                'seo_description' => [
                    'en' => 'Discover Quran programs, community activities, and family learning at Masjid AlKhair.',
                    'ar' => 'اكتشف برامج القرآن والأنشطة المجتمعية وتعلّم الأسرة في مسجد الخير.',
                ],
                'sections' => [
                    [
                        'type' => 'hero',
                        'eyebrow' => [
                            'en' => 'Rooted in Quran. Open to every family.',
                            'ar' => 'منطلقون من القرآن، ومفتوحون لكل أسرة.',
                        ],
                        'title' => [
                            'en' => 'A living mosque website, not a login screen.',
                            'ar' => 'واجهة مسجد حيّة، وليست مجرد شاشة دخول.',
                        ],
                        'subtitle' => [
                            'en' => 'Share your programs, teachers, events, and values with the public while keeping the internal platform for management and learning.',
                            'ar' => 'اعرض برامجكم ومعلميكم وأنشطتكم وقيمكم للناس، مع إبقاء المنصة الداخلية للإدارة والتعلّم.',
                        ],
                        'primary_cta_label' => [
                            'en' => 'Explore Programs',
                            'ar' => 'اكتشف البرامج',
                        ],
                        'primary_cta_url' => '/pages/programs',
                        'secondary_cta_label' => [
                            'en' => 'Contact the Mosque',
                            'ar' => 'تواصل مع المسجد',
                        ],
                        'secondary_cta_url' => '/pages/visit-us',
                    ],
                    [
                        'type' => 'stats',
                        'items' => [
                            [
                                'value' => '30+',
                                'label' => [
                                    'en' => 'Quran circles and support tracks',
                                    'ar' => 'حلقة ومسار دعم قرآني',
                                ],
                            ],
                            [
                                'value' => '5',
                                'label' => [
                                    'en' => 'Audience groups served',
                                    'ar' => 'فئات نخدمها',
                                ],
                            ],
                            [
                                'value' => '2',
                                'label' => [
                                    'en' => 'Languages across the public site',
                                    'ar' => 'لغتان في الموقع العام',
                                ],
                            ],
                        ],
                    ],
                    [
                        'type' => 'story',
                        'title' => [
                            'en' => 'A mosque home for worship, learning, and belonging.',
                            'ar' => 'بيت مسجد يجمع العبادة والتعلّم والانتماء.',
                        ],
                        'body' => [
                            'en' => 'Masjid AlKhair welcomes students, parents, teachers, and the wider neighborhood through Quran programs, memorization support, activities, and steady pastoral care.',
                            'ar' => 'يرحّب مسجد الخير بالطلاب وأولياء الأمور والمعلمين وأهل الحي من خلال برامج القرآن ودعم الحفظ والأنشطة والرعاية التربوية المستمرة.',
                        ],
                        'quote' => [
                            'en' => 'Build a calm, trustworthy first impression before anyone creates an account.',
                            'ar' => 'امنح الزائر انطباعاً هادئاً وموثوقاً قبل أن ينشئ أي حساب.',
                        ],
                    ],
                    [
                        'type' => 'programs',
                        'title' => [
                            'en' => 'Featured pathways',
                            'ar' => 'مسارات مميزة',
                        ],
                        'subtitle' => [
                            'en' => 'Use these cards to point families toward the programs you want to highlight this season.',
                            'ar' => 'استخدم هذه البطاقات لتوجيه العائلات إلى البرامج التي تريد إبرازها هذا الموسم.',
                        ],
                        'cards' => [
                            [
                                'title' => [
                                    'en' => 'Quran Memorization',
                                    'ar' => 'حلقات الحفظ',
                                ],
                                'summary' => [
                                    'en' => 'Structured memorization, revision, attendance, and teacher follow-up for every student.',
                                    'ar' => 'حفظ منظم ومراجعة ودَوام ومتابعة معلم لكل طالب.',
                                ],
                                'link_label' => [
                                    'en' => 'Open details',
                                    'ar' => 'عرض التفاصيل',
                                ],
                                'link_url' => '/pages/programs',
                            ],
                            [
                                'title' => [
                                    'en' => 'Family Learning',
                                    'ar' => 'تعليم الأسرة',
                                ],
                                'summary' => [
                                    'en' => 'A parent-facing path for updates, encouragement, and mosque-wide communication.',
                                    'ar' => 'مسار موجّه للأهالي للتحديثات والدعم والتواصل مع المسجد.',
                                ],
                                'link_label' => [
                                    'en' => 'See family page',
                                    'ar' => 'صفحة العائلة',
                                ],
                                'link_url' => '/pages/about-us',
                            ],
                            [
                                'title' => [
                                    'en' => 'Seasonal Events',
                                    'ar' => 'فعاليات موسمية',
                                ],
                                'summary' => [
                                    'en' => 'Community gatherings, student activities, and special programs during Ramadan and Eid.',
                                    'ar' => 'لقاءات مجتمعية وأنشطة طلابية وبرامج خاصة في رمضان والعيد.',
                                ],
                                'link_label' => [
                                    'en' => 'Plan a visit',
                                    'ar' => 'خطط للزيارة',
                                ],
                                'link_url' => '/pages/visit-us',
                            ],
                        ],
                    ],
                ],
                'is_home' => true,
                'is_published' => true,
                'show_in_navigation' => false,
                'published_at' => now(),
            ],
        );

        $pages = [
            [
                'slug' => 'about-us',
                'order' => 10,
                'title' => ['en' => 'About Us', 'ar' => 'من نحن'],
                'excerpt' => ['en' => 'Learn the mission and rhythm of Masjid AlKhair.', 'ar' => 'تعرّف إلى رسالة مسجد الخير وإيقاعه التربوي.'],
                'body' => [
                    'en' => "Masjid AlKhair is built around steady Quran learning, disciplined care for students, and respectful communication with families.\n\nThis public website can carry your introduction, your values, your weekly priorities, and the pages you want visitors to read before they log in.",
                    'ar' => "يقوم مسجد الخير على تعلّم قرآني ثابت، ورعاية منضبطة للطلاب، وتواصل محترم مع العائلات.\n\nيمكن لهذا الموقع العام أن يحمل تعريفكم ورسالتكم وأولوياتكم الأسبوعية والصفحات التي تريدون أن يقرأها الزوار قبل تسجيل الدخول.",
                ],
            ],
            [
                'slug' => 'programs',
                'order' => 20,
                'title' => ['en' => 'Programs', 'ar' => 'البرامج'],
                'excerpt' => ['en' => 'Show the main learning tracks and mosque services.', 'ar' => 'اعرض المسارات التعليمية الرئيسية وخدمات المسجد.'],
                'body' => [
                    'en' => "Create a page for Quran memorization, tajweed, revision, youth activities, and parent engagement.\n\nEach page supports English and Arabic content, public navigation, and its own hero copy.",
                    'ar' => "أنشئ صفحة للحفظ والتجويد والمراجعة والأنشطة الشبابية ومشاركة أولياء الأمور.\n\nكل صفحة تدعم المحتوى بالإنجليزية والعربية، والظهور في التنقل العام، ونصوصها التعريفية الخاصة.",
                ],
            ],
            [
                'slug' => 'visit-us',
                'order' => 30,
                'title' => ['en' => 'Visit Us', 'ar' => 'زرنا'],
                'excerpt' => ['en' => 'Give families a clear next step to reach the mosque.', 'ar' => 'امنح العائلات خطوة واضحة للوصول إلى المسجد.'],
                'body' => [
                    'en' => "Use this page for visiting hours, prayer times links, location notes, and questions from new families.\n\nYou can also place maps, phone numbers, WhatsApp links, and upcoming event notes here.",
                    'ar' => "استخدم هذه الصفحة لساعات الزيارة وروابط أوقات الصلاة وملاحظات الموقع وأسئلة العائلات الجديدة.\n\nويمكنك كذلك وضع الخرائط وأرقام الهاتف وروابط واتساب وملاحظات الفعاليات القادمة هنا.",
                ],
            ],
        ];

        foreach ($pages as $page) {
            WebsitePage::query()->updateOrCreate(
                ['slug' => $page['slug']],
                [
                    'template' => 'page',
                    'title' => $page['title'],
                    'navigation_label' => $page['title'],
                    'excerpt' => $page['excerpt'],
                    'body' => $page['body'],
                    'is_home' => false,
                    'is_published' => true,
                    'show_in_navigation' => true,
                    'navigation_order' => $page['order'],
                    'published_at' => now(),
                ],
            );
        }

        $menu = WebsiteMenu::query()->updateOrCreate(
            ['key' => 'primary'],
            ['title' => ['en' => 'Primary Navigation', 'ar' => 'التنقل الرئيسي']],
        );

        $menu->items()->delete();

        $aboutPage = WebsitePage::query()->where('slug', 'about-us')->first();
        $programsPage = WebsitePage::query()->where('slug', 'programs')->first();
        $visitPage = WebsitePage::query()->where('slug', 'visit-us')->first();

        WebsiteMenuItem::query()->create([
            'website_menu_id' => $menu->id,
            'website_page_id' => $aboutPage?->id,
            'sort_order' => 10,
            'is_active' => true,
        ]);

        $programsItem = WebsiteMenuItem::query()->create([
            'website_menu_id' => $menu->id,
            'website_page_id' => $programsPage?->id,
            'sort_order' => 20,
            'is_active' => true,
        ]);

        WebsiteMenuItem::query()->create([
            'website_menu_id' => $menu->id,
            'parent_id' => $programsItem->id,
            'label' => [
                'en' => 'Quran Memorization',
                'ar' => 'حفظ القرآن',
            ],
            'url' => '/pages/programs',
            'sort_order' => 10,
            'is_active' => true,
        ]);

        WebsiteMenuItem::query()->create([
            'website_menu_id' => $menu->id,
            'parent_id' => $programsItem->id,
            'label' => [
                'en' => 'Family Learning',
                'ar' => 'تعليم الأسرة',
            ],
            'url' => '/pages/about-us',
            'sort_order' => 20,
            'is_active' => true,
        ]);

        WebsiteMenuItem::query()->create([
            'website_menu_id' => $menu->id,
            'parent_id' => $programsItem->id,
            'website_page_id' => $visitPage?->id,
            'sort_order' => 30,
            'is_active' => true,
        ]);

        WebsiteMenuItem::query()->create([
            'website_menu_id' => $menu->id,
            'website_page_id' => $visitPage?->id,
            'sort_order' => 30,
            'is_active' => true,
        ]);
    }

    protected function seedMediaAsset(string $sourceFilename, string $targetPath): ?string
    {
        $sourcePath = base_path('visual identity/'.$sourceFilename);

        if (! File::exists($sourcePath)) {
            return null;
        }

        $storage = Storage::disk('public');

        if (! $storage->exists($targetPath)) {
            $stream = fopen($sourcePath, 'rb');

            if ($stream === false) {
                return null;
            }

            $storage->put($targetPath, $stream);

            fclose($stream);
        }

        return $targetPath;
    }
}
