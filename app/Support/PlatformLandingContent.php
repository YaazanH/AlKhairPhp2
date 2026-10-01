<?php

namespace App\Support;

use Illuminate\Support\Arr;

class PlatformLandingContent
{
    public const SECTIONS = ['features', 'showcase', 'packages', 'faq', 'contact'];

    public static function defaults(): array
    {
        return [
            'brand' => ['en' => 'AlKhair Platform', 'ar' => 'منصة الخير'],
            'meta_title' => ['en' => 'AlKhair Platform · One place to run your learning organisation', 'ar' => 'منصة الخير · مكان واحد لإدارة مؤسستك التعليمية'],
            'meta_description' => ['en' => 'A clear, connected platform for students, attendance, learning, finance, reporting, and your public presence.', 'ar' => 'منصة مترابطة لإدارة الطلاب والحضور والتعلّم والمالية والتقارير والحضور الرقمي.'],
            'hero_eyebrow' => ['en' => 'Built for purposeful learning organisations', 'ar' => 'صُممت للمؤسسات التعليمية ذات الرسالة'],
            'hero_title' => ['en' => 'Give your team clarity. Give every learner room to grow.', 'ar' => 'امنح فريقك الوضوح، وكل متعلم مساحة للنمو.'],
            'hero_body' => ['en' => 'Bring daily operations, learning progress, family communication, and useful reporting into one calm workspace shaped around your organisation.', 'ar' => 'اجمع العمليات اليومية وتقدم التعلم والتواصل مع الأسر والتقارير المفيدة في مساحة هادئة تتشكل حول مؤسستك.'],
            'hero_primary_label' => ['en' => 'Request a guided demo', 'ar' => 'اطلب عرضاً توضيحياً'],
            'hero_secondary_label' => ['en' => 'Platform sign in', 'ar' => 'دخول إدارة المنصة'],
            'features_kicker' => ['en' => 'One connected system', 'ar' => 'نظام واحد مترابط'],
            'features_title' => ['en' => 'The details your team needs, without the noise', 'ar' => 'التفاصيل التي يحتاجها فريقك بلا تعقيد'],
            'features_body' => ['en' => 'Start with the modules you need and keep one reliable source of truth as your organisation grows.', 'ar' => 'ابدأ بالوحدات التي تحتاجها واحتفظ بمصدر موثوق واحد للمعلومات مع نمو مؤسستك.'],
            'feature_items' => [
                ['title' => ['en' => 'Learning that follows your method', 'ar' => 'تعلم يتبع منهجيتك'], 'body' => ['en' => 'Track programmes, groups, attendance, assessments, and learner progress in a practical daily flow.', 'ar' => 'تابع البرامج والمجموعات والحضور والتقييمات وتقدم المتعلم ضمن سير يومي عملي.']],
                ['title' => ['en' => 'Operations that stay connected', 'ar' => 'عمليات تبقى مترابطة'], 'body' => ['en' => 'Keep people, schedules, documents, finance, and communication organised around the same records.', 'ar' => 'نظّم الأشخاص والجداول والوثائق والمالية والتواصل حول السجلات نفسها.']],
                ['title' => ['en' => 'Insight people can act on', 'ar' => 'رؤية تقود إلى عمل'], 'body' => ['en' => 'Use focused dashboards and reports to spot what needs attention and make confident decisions.', 'ar' => 'استخدم لوحات وتقارير مركزة لاكتشاف ما يحتاج إلى اهتمام واتخاذ قرارات واثقة.']],
            ],
            'showcase_kicker' => ['en' => 'See the work clearly', 'ar' => 'شاهد العمل بوضوح'],
            'showcase_title' => ['en' => 'A workspace that explains itself', 'ar' => 'مساحة عمل واضحة من أول نظرة'],
            'showcase_body' => ['en' => 'Each view is designed around a real task, so staff can understand what matters and move forward quickly.', 'ar' => 'صُممت كل شاشة حول مهمة حقيقية ليعرف الموظفون ما يهم ويتقدموا بسرعة.'],
            'showcase_items' => [
                ['title' => ['en' => 'A useful daily overview', 'ar' => 'نظرة يومية مفيدة'], 'body' => ['en' => 'See activity, attendance, progress, and priorities together.', 'ar' => 'شاهد النشاط والحضور والتقدم والأولويات معاً.'], 'image_path' => null],
                ['title' => ['en' => 'Progress with context', 'ar' => 'تقدم ضمن سياقه'], 'body' => ['en' => 'Move from organisation-level insight to the learner details behind it.', 'ar' => 'انتقل من مؤشرات المؤسسة إلى تفاصيل المتعلم التي تقف خلفها.'], 'image_path' => null],
                ['title' => ['en' => 'Reports shaped for decisions', 'ar' => 'تقارير مصممة للقرار'], 'body' => ['en' => 'Build clear reporting views for the people responsible for acting on them.', 'ar' => 'أنشئ تقارير واضحة للأشخاص المسؤولين عن اتخاذ الإجراء.'], 'image_path' => null],
            ],
            'packages_kicker' => ['en' => 'A practical way to begin', 'ar' => 'طريقة عملية للبدء'],
            'packages_title' => ['en' => 'Choose the capabilities that fit today', 'ar' => 'اختر الإمكانات المناسبة لاحتياجك اليوم'],
            'packages_body' => ['en' => 'Your package can grow with your organisation. We will help you choose a clear starting point.', 'ar' => 'يمكن لباقتك أن تنمو مع مؤسستك، وسنساعدك في اختيار نقطة بداية واضحة.'],
            'faq_kicker' => ['en' => 'Questions, answered', 'ar' => 'إجابات واضحة'],
            'faq_title' => ['en' => 'What organisations ask before they begin', 'ar' => 'ما تسأل عنه المؤسسات قبل البدء'],
            'faq_items' => [
                ['question' => ['en' => 'Can the platform match our organisation?', 'ar' => 'هل يمكن للمنصة أن تناسب مؤسستنا؟'], 'answer' => ['en' => 'Yes. Your organisation receives its own secure workspace, identity, modules, and configuration.', 'ar' => 'نعم. تحصل مؤسستك على مساحة آمنة وهوية ووحدات وإعدادات خاصة بها.']],
                ['question' => ['en' => 'Do we need every module?', 'ar' => 'هل نحتاج إلى جميع الوحدات؟'], 'answer' => ['en' => 'No. Start with the capabilities you need and add supported modules as your needs grow.', 'ar' => 'لا. ابدأ بالإمكانات التي تحتاجها وأضف الوحدات المدعومة مع نمو احتياجاتك.']],
                ['question' => ['en' => 'How do we know whether it fits?', 'ar' => 'كيف نعرف أنها مناسبة لنا؟'], 'answer' => ['en' => 'Request a guided demo. We will discuss your workflow and can prepare a time-limited tenant for evaluation.', 'ar' => 'اطلب عرضاً توضيحياً. سنناقش سير عملك ويمكننا تجهيز مساحة مؤقتة للتجربة.']],
            ],
            'contact_kicker' => ['en' => 'Start a useful conversation', 'ar' => 'ابدأ حواراً مفيداً'],
            'contact_title' => ['en' => 'Tell us how your organisation works', 'ar' => 'حدثنا عن طريقة عمل مؤسستك'],
            'contact_body' => ['en' => 'We will listen first, then show you the parts of the Platform that match your priorities.', 'ar' => 'سنستمع أولاً، ثم نعرض لك الأجزاء التي تناسب أولويات مؤسستك.'],
            'section_order' => self::SECTIONS,
            'enabled_sections' => array_fill_keys(self::SECTIONS, true),
        ];
    }

    public static function normalize(?array $content): array
    {
        $content = array_replace_recursive(self::defaults(), $content ?? []);
        $content['section_order'] = collect($content['section_order'] ?? [])
            ->filter(fn ($section) => in_array($section, self::SECTIONS, true))
            ->unique()->concat(self::SECTIONS)->unique()->values()->all();
        $content['enabled_sections'] = collect(self::SECTIONS)
            ->mapWithKeys(fn ($section) => [$section => (bool) Arr::get($content, 'enabled_sections.'.$section, true)])
            ->all();

        return $content;
    }

    public static function localized(array $content, string $locale): array
    {
        $fallback = config('app.fallback_locale', 'en');

        return collect($content)->map(function ($value) use ($locale, $fallback) {
            if (is_array($value) && array_key_exists('en', $value) && array_key_exists('ar', $value)) {
                return $value[$locale] ?: $value[$fallback];
            }

            if (is_array($value)) {
                return array_map(function ($item) use ($locale, $fallback) {
                    if (! is_array($item)) {
                        return $item;
                    }

                    return collect($item)->map(fn ($field) => is_array($field) && array_key_exists('en', $field)
                        ? ($field[$locale] ?: $field[$fallback])
                        : $field)->all();
                }, $value);
            }

            return $value;
        })->all();
    }
}
