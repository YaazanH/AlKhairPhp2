<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\AppSetting;
use App\Models\WebsitePage;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use AuthorizesPermissions, WithFileUploads;

    public string $site_name = '';
    public string $site_tagline_en = '';
    public string $site_tagline_ar = '';
    public string $site_description_en = '';
    public string $site_description_ar = '';
    public string $contact_phone = '';
    public string $contact_email = '';
    public string $contact_address_en = '';
    public string $contact_address_ar = '';
    public string $maps_url = '';
    public string $whatsapp_url = '';
    public string $primary_color = '#006b2d';
    public string $accent_color = '#0b8f43';
    public ?string $logo_path = null;
    public ?string $hero_image_path = null;
    public ?string $featured_video_path = null;
    public array $gallery_paths = [];
    public array $gallery_items = [];
    public $logo_upload = null;
    public $hero_image_upload = null;
    public $featured_video_upload = null;
    public array $gallery_uploads = [];
    public string $hero_eyebrow_en = '';
    public string $hero_eyebrow_ar = '';
    public string $hero_title_en = '';
    public string $hero_title_ar = '';
    public string $hero_subtitle_en = '';
    public string $hero_subtitle_ar = '';
    public string $primary_cta_label_en = '';
    public string $primary_cta_label_ar = '';
    public string $primary_cta_url = '';
    public string $secondary_cta_label_en = '';
    public string $secondary_cta_label_ar = '';
    public string $secondary_cta_url = '';
    public string $story_title_en = '';
    public string $story_title_ar = '';
    public string $story_body_en = '';
    public string $story_body_ar = '';
    public string $story_quote_en = '';
    public string $story_quote_ar = '';
    public string $programs_title_en = '';
    public string $programs_title_ar = '';
    public string $programs_subtitle_en = '';
    public string $programs_subtitle_ar = '';
    public array $program_cards = [];
    public array $stats = [];

    public function mount(): void
    {
        $this->authorizePermission('website.manage');
        $this->loadState();
    }

    public function with(): array
    {
        return [
            'totals' => [
                'published_pages' => WebsitePage::query()->where('is_home', false)->where('is_published', true)->count(),
                'program_cards' => count($this->program_cards),
                'quick_stats' => count($this->stats),
                'media_assets' => count(array_filter([$this->logo_path, $this->hero_image_path, $this->featured_video_path])) + count($this->gallery_items),
            ],
        ];
    }

    public function updatedLogoUpload(): void
    {
        $this->autoSaveWebsiteAsset('logo_upload', 'logo_path', 'website/branding', ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,svg', 'max:2048']);
    }

    public function updatedHeroImageUpload(): void
    {
        $this->autoSaveWebsiteAsset('hero_image_upload', 'hero_image_path', 'website/gallery', ['nullable', 'image', 'max:4096']);
    }

    public function updatedFeaturedVideoUpload(): void
    {
        $this->autoSaveWebsiteAsset('featured_video_upload', 'featured_video_path', 'website/video', ['nullable', 'file', 'mimetypes:video/mp4,video/quicktime,video/webm', 'max:51200']);
    }

    public function updatedGalleryUploads(): void
    {
        $this->validate([
            'gallery_uploads' => ['nullable', 'array'],
            'gallery_uploads.*' => ['image', 'max:4096'],
        ]);

        foreach ($this->gallery_uploads as $galleryUpload) {
            $this->gallery_items[] = [
                'path' => $galleryUpload->store('website/gallery', 'public'),
                'title_en' => '',
                'title_ar' => '',
                'details_en' => '',
                'details_ar' => '',
                'caption_en' => '',
                'caption_ar' => '',
            ];
        }

        $this->syncGalleryPaths();
        $this->persistGallery();
        $this->reset('gallery_uploads');
        session()->flash('status', __('site.admin.website.messages.media_saved'));
    }

    public function addProgramCard(): void
    {
        $this->program_cards[] = $this->blankProgramCard();
    }

    public function removeProgramCard(int $index): void
    {
        unset($this->program_cards[$index]);
        $this->program_cards = array_values($this->program_cards);
    }

    public function addStat(): void
    {
        $this->stats[] = $this->blankStat();
    }

    public function removeStat(int $index): void
    {
        unset($this->stats[$index]);
        $this->stats = array_values($this->stats);
    }

    public function removeGalleryAsset(int $index): void
    {
        if ($path = data_get($this->gallery_items, $index.'.path')) {
            Storage::disk('public')->delete($path);
        }

        unset($this->gallery_items[$index]);
        $this->gallery_items = array_values($this->gallery_items);
        $this->syncGalleryPaths();
        $this->persistGallery();
        session()->flash('status', __('site.admin.website.messages.media_removed'));
    }

    public function removeWebsiteAsset(string $asset): void
    {
        $map = ['logo' => 'logo_path', 'hero_image' => 'hero_image_path', 'featured_video' => 'featured_video_path'];
        abort_unless(isset($map[$asset]), 404);
        $property = $map[$asset];

        if ($this->{$property}) {
            Storage::disk('public')->delete($this->{$property});
        }

        $this->{$property} = null;
        AppSetting::storeValue('website', $property, null);
        session()->flash('status', __('site.admin.website.messages.media_removed'));
    }

    protected function autoSaveWebsiteAsset(string $uploadField, string $pathProperty, string $directory, array $rules): void
    {
        $validated = $this->validate([$uploadField => $rules]);

        if (! ($validated[$uploadField] ?? null)) {
            return;
        }

        if ($this->{$pathProperty}) {
            Storage::disk('public')->delete($this->{$pathProperty});
        }

        $this->{$pathProperty} = $validated[$uploadField]->store($directory, 'public');
        AppSetting::storeValue('website', $pathProperty, $this->{$pathProperty});
        $this->reset($uploadField);
        session()->flash('status', __('site.admin.website.messages.media_saved'));
    }

    public function saveWebsite(): void
    {
        $validated = $this->validate([
            'site_name' => ['required', 'string', 'max:255'],
            'site_tagline_en' => ['required', 'string', 'max:255'],
            'site_tagline_ar' => ['required', 'string', 'max:255'],
            'site_description_en' => ['required', 'string'],
            'site_description_ar' => ['required', 'string'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_address_en' => ['nullable', 'string'],
            'contact_address_ar' => ['nullable', 'string'],
            'maps_url' => ['nullable', 'url'],
            'whatsapp_url' => ['nullable', 'url'],
            'primary_color' => ['required', 'regex:/^#([0-9a-fA-F]{6})$/'],
            'accent_color' => ['required', 'regex:/^#([0-9a-fA-F]{6})$/'],
            'logo_upload' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,svg', 'max:2048'],
            'hero_image_upload' => ['nullable', 'image', 'max:4096'],
            'featured_video_upload' => ['nullable', 'file', 'mimetypes:video/mp4,video/quicktime,video/webm', 'max:51200'],
            'gallery_uploads' => ['nullable', 'array'],
            'gallery_uploads.*' => ['image', 'max:4096'],
            'gallery_items' => ['nullable', 'array'],
            'gallery_items.*.path' => ['nullable', 'string'],
            'gallery_items.*.title_en' => ['nullable', 'string', 'max:255'],
            'gallery_items.*.title_ar' => ['nullable', 'string', 'max:255'],
            'gallery_items.*.details_en' => ['nullable', 'string', 'max:500'],
            'gallery_items.*.details_ar' => ['nullable', 'string', 'max:500'],
            'gallery_items.*.caption_en' => ['nullable', 'string', 'max:255'],
            'gallery_items.*.caption_ar' => ['nullable', 'string', 'max:255'],
            'hero_eyebrow_en' => ['required', 'string', 'max:255'],
            'hero_eyebrow_ar' => ['required', 'string', 'max:255'],
            'hero_title_en' => ['required', 'string', 'max:255'],
            'hero_title_ar' => ['required', 'string', 'max:255'],
            'hero_subtitle_en' => ['required', 'string'],
            'hero_subtitle_ar' => ['required', 'string'],
            'primary_cta_label_en' => ['required', 'string', 'max:100'],
            'primary_cta_label_ar' => ['required', 'string', 'max:100'],
            'primary_cta_url' => ['required', 'string', 'max:255'],
            'secondary_cta_label_en' => ['nullable', 'string', 'max:100'],
            'secondary_cta_label_ar' => ['nullable', 'string', 'max:100'],
            'secondary_cta_url' => ['nullable', 'string', 'max:255'],
            'story_title_en' => ['required', 'string', 'max:255'],
            'story_title_ar' => ['required', 'string', 'max:255'],
            'story_body_en' => ['required', 'string'],
            'story_body_ar' => ['required', 'string'],
            'story_quote_en' => ['nullable', 'string', 'max:255'],
            'story_quote_ar' => ['nullable', 'string', 'max:255'],
            'programs_title_en' => ['required', 'string', 'max:255'],
            'programs_title_ar' => ['required', 'string', 'max:255'],
            'programs_subtitle_en' => ['required', 'string'],
            'programs_subtitle_ar' => ['required', 'string'],
            'program_cards' => ['required', 'array', 'min:1'],
            'program_cards.*.title_en' => ['required', 'string', 'max:255'],
            'program_cards.*.title_ar' => ['required', 'string', 'max:255'],
            'program_cards.*.summary_en' => ['required', 'string'],
            'program_cards.*.summary_ar' => ['required', 'string'],
            'program_cards.*.link_label_en' => ['nullable', 'string', 'max:100'],
            'program_cards.*.link_label_ar' => ['nullable', 'string', 'max:100'],
            'program_cards.*.link_url' => ['nullable', 'string', 'max:255'],
            'stats' => ['required', 'array', 'min:1'],
            'stats.*.value' => ['required', 'string', 'max:50'],
            'stats.*.label_en' => ['required', 'string', 'max:255'],
            'stats.*.label_ar' => ['required', 'string', 'max:255'],
        ]);

        foreach (['logo_upload' => ['logo_path', 'website/branding'], 'hero_image_upload' => ['hero_image_path', 'website/gallery'], 'featured_video_upload' => ['featured_video_path', 'website/video']] as $field => [$property, $directory]) {
            if ($validated[$field] ?? null) {
                if ($this->{$property}) {
                    Storage::disk('public')->delete($this->{$property});
                }

                $this->{$property} = $validated[$field]->store($directory, 'public');
            }
        }

        $submittedGalleryItems = $validated['gallery_items'] ?? $this->gallery_items;

        foreach ($validated['gallery_uploads'] ?? [] as $galleryUpload) {
            $submittedGalleryItems[] = [
                'path' => $galleryUpload->store('website/gallery', 'public'),
                'title_en' => '',
                'title_ar' => '',
                'details_en' => '',
                'details_ar' => '',
                'caption_en' => '',
                'caption_ar' => '',
            ];
        }

        $this->gallery_items = collect($submittedGalleryItems)
            ->filter(fn (array $item) => filled($item['path'] ?? null))
            ->map(fn (array $item) => [
                'path' => (string) $item['path'],
                'title_en' => (string) ($item['title_en'] ?? ''),
                'title_ar' => (string) ($item['title_ar'] ?? ''),
                'details_en' => (string) ($item['details_en'] ?? ''),
                'details_ar' => (string) ($item['details_ar'] ?? ''),
                'caption_en' => (string) ($item['caption_en'] ?? ''),
                'caption_ar' => (string) ($item['caption_ar'] ?? ''),
            ])
            ->values()
            ->all();
        $this->syncGalleryPaths();

        AppSetting::storeValue('website', 'site_name', $validated['site_name']);
        AppSetting::storeValue('website', 'site_tagline', ['en' => $validated['site_tagline_en'], 'ar' => $validated['site_tagline_ar']], 'array');
        AppSetting::storeValue('website', 'site_description', ['en' => $validated['site_description_en'], 'ar' => $validated['site_description_ar']], 'array');
        AppSetting::storeValue('website', 'contact_phone', $validated['contact_phone']);
        AppSetting::storeValue('website', 'contact_email', $validated['contact_email']);
        AppSetting::storeValue('website', 'contact_address', ['en' => $validated['contact_address_en'], 'ar' => $validated['contact_address_ar']], 'array');
        AppSetting::storeValue('website', 'maps_url', $validated['maps_url']);
        AppSetting::storeValue('website', 'whatsapp_url', $validated['whatsapp_url']);
        AppSetting::storeValue('website', 'primary_color', $validated['primary_color']);
        AppSetting::storeValue('website', 'accent_color', $validated['accent_color']);
        AppSetting::storeValue('website', 'logo_path', $this->logo_path);
        AppSetting::storeValue('website', 'hero_image_path', $this->hero_image_path);
        AppSetting::storeValue('website', 'featured_video_path', $this->featured_video_path);
        $this->persistGallery();

        WebsitePage::query()->updateOrCreate(['slug' => 'home'], [
            'template' => 'home',
            'title' => ['en' => $validated['site_name'], 'ar' => $validated['site_name']],
            'excerpt' => ['en' => $validated['site_description_en'], 'ar' => $validated['site_description_ar']],
            'seo_title' => ['en' => $validated['site_name'], 'ar' => $validated['site_name']],
            'seo_description' => ['en' => $validated['site_description_en'], 'ar' => $validated['site_description_ar']],
            'hero_media_path' => $this->hero_image_path,
            'is_home' => true,
            'is_published' => true,
            'show_in_navigation' => false,
            'published_at' => now(),
            'sections' => [
                ['type' => 'hero', 'eyebrow' => ['en' => $validated['hero_eyebrow_en'], 'ar' => $validated['hero_eyebrow_ar']], 'title' => ['en' => $validated['hero_title_en'], 'ar' => $validated['hero_title_ar']], 'subtitle' => ['en' => $validated['hero_subtitle_en'], 'ar' => $validated['hero_subtitle_ar']], 'primary_cta_label' => ['en' => $validated['primary_cta_label_en'], 'ar' => $validated['primary_cta_label_ar']], 'primary_cta_url' => $validated['primary_cta_url'], 'secondary_cta_label' => ['en' => $validated['secondary_cta_label_en'], 'ar' => $validated['secondary_cta_label_ar']], 'secondary_cta_url' => $validated['secondary_cta_url']],
                ['type' => 'stats', 'items' => collect($validated['stats'])->map(fn (array $item) => ['value' => $item['value'], 'label' => ['en' => $item['label_en'], 'ar' => $item['label_ar']]])->all()],
                ['type' => 'story', 'title' => ['en' => $validated['story_title_en'], 'ar' => $validated['story_title_ar']], 'body' => ['en' => $validated['story_body_en'], 'ar' => $validated['story_body_ar']], 'quote' => ['en' => $validated['story_quote_en'], 'ar' => $validated['story_quote_ar']]],
                ['type' => 'programs', 'title' => ['en' => $validated['programs_title_en'], 'ar' => $validated['programs_title_ar']], 'subtitle' => ['en' => $validated['programs_subtitle_en'], 'ar' => $validated['programs_subtitle_ar']], 'cards' => collect($validated['program_cards'])->map(fn (array $card) => ['title' => ['en' => $card['title_en'], 'ar' => $card['title_ar']], 'summary' => ['en' => $card['summary_en'], 'ar' => $card['summary_ar']], 'link_label' => ['en' => $card['link_label_en'], 'ar' => $card['link_label_ar']], 'link_url' => $card['link_url']])->all()],
            ],
        ]);

        $this->reset('logo_upload', 'hero_image_upload', 'featured_video_upload', 'gallery_uploads');
        session()->flash('status', __('site.admin.website.messages.saved'));
    }

    protected function loadState(): void
    {
        $settings = AppSetting::groupValues('website');
        $sections = collect(WebsitePage::query()->where('is_home', true)->value('sections') ?? []);
        $hero = $sections->firstWhere('type', 'hero') ?? [];
        $story = $sections->firstWhere('type', 'story') ?? [];
        $programs = $sections->firstWhere('type', 'programs') ?? [];
        $stats = $sections->firstWhere('type', 'stats')['items'] ?? [];
        foreach (['site_name', 'contact_phone', 'contact_email', 'maps_url', 'whatsapp_url', 'primary_color', 'accent_color', 'logo_path', 'hero_image_path', 'featured_video_path'] as $key) {
            $this->{$key} = (string) ($settings->get($key) ?? $this->{$key});
        }

        $this->gallery_items = collect($settings->get('gallery_items') ?: [])
            ->map(fn (array $item) => [
                'path' => (string) data_get($item, 'path', ''),
                'title_en' => (string) data_get($item, 'title_en', ''),
                'title_ar' => (string) data_get($item, 'title_ar', ''),
                'details_en' => (string) data_get($item, 'details_en', ''),
                'details_ar' => (string) data_get($item, 'details_ar', ''),
                'caption_en' => (string) data_get($item, 'caption_en', ''),
                'caption_ar' => (string) data_get($item, 'caption_ar', ''),
            ])
            ->filter(fn (array $item) => filled($item['path']))
            ->values()
            ->all();

        if ($this->gallery_items === []) {
            $this->gallery_items = collect($settings->get('gallery_paths') ?? [])
                ->filter(fn (mixed $path) => is_string($path) && filled($path))
                ->map(fn (string $path) => ['path' => $path, 'title_en' => '', 'title_ar' => '', 'details_en' => '', 'details_ar' => '', 'caption_en' => '', 'caption_ar' => ''])
                ->values()
                ->all();
        }

        $this->syncGalleryPaths();
        foreach (['site_tagline', 'site_description', 'contact_address'] as $key) {
            $this->{$key.'_en'} = (string) data_get($settings->get($key, []), 'en', '');
            $this->{$key.'_ar'} = (string) data_get($settings->get($key, []), 'ar', '');
        }
        foreach (['hero_eyebrow' => 'eyebrow', 'hero_title' => 'title', 'hero_subtitle' => 'subtitle', 'primary_cta_label' => 'primary_cta_label', 'secondary_cta_label' => 'secondary_cta_label'] as $property => $source) {
            $this->{$property.'_en'} = (string) data_get($hero, $source.'.en', '');
            $this->{$property.'_ar'} = (string) data_get($hero, $source.'.ar', '');
        }
        $this->primary_cta_url = (string) data_get($hero, 'primary_cta_url', '/pages/programs');
        $this->secondary_cta_url = (string) data_get($hero, 'secondary_cta_url', '/pages/visit-us');
        foreach (['story_title' => 'title', 'story_body' => 'body', 'story_quote' => 'quote', 'programs_title' => 'title', 'programs_subtitle' => 'subtitle'] as $property => $source) {
            $sourceGroup = str_starts_with($property, 'programs_') ? $programs : $story;
            $this->{$property.'_en'} = (string) data_get($sourceGroup, $source.'.en', '');
            $this->{$property.'_ar'} = (string) data_get($sourceGroup, $source.'.ar', '');
        }
        $this->program_cards = collect(data_get($programs, 'cards', []))->map(fn (array $card) => ['title_en' => (string) data_get($card, 'title.en', ''), 'title_ar' => (string) data_get($card, 'title.ar', ''), 'summary_en' => (string) data_get($card, 'summary.en', ''), 'summary_ar' => (string) data_get($card, 'summary.ar', ''), 'link_label_en' => (string) data_get($card, 'link_label.en', ''), 'link_label_ar' => (string) data_get($card, 'link_label.ar', ''), 'link_url' => (string) data_get($card, 'link_url', '')])->values()->all() ?: [$this->blankProgramCard()];
        $this->stats = collect($stats)->map(fn (array $item) => ['value' => (string) data_get($item, 'value', ''), 'label_en' => (string) data_get($item, 'label.en', ''), 'label_ar' => (string) data_get($item, 'label.ar', '')])->values()->all() ?: [$this->blankStat()];
    }

    protected function blankProgramCard(): array
    {
        return ['title_en' => '', 'title_ar' => '', 'summary_en' => '', 'summary_ar' => '', 'link_label_en' => '', 'link_label_ar' => '', 'link_url' => ''];
    }

    protected function blankStat(): array
    {
        return ['value' => '', 'label_en' => '', 'label_ar' => ''];
    }

    protected function syncGalleryPaths(): void
    {
        $this->gallery_paths = collect($this->gallery_items)
            ->pluck('path')
            ->filter()
            ->values()
            ->all();
    }

    protected function persistGallery(): void
    {
        AppSetting::storeValue('website', 'gallery_items', $this->gallery_items, 'array');
        AppSetting::storeValue('website', 'gallery_paths', $this->gallery_paths, 'array');
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('site.admin.nav.meta') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('site.admin.website.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('site.admin.website.subtitle') }}</p>
    </section>

    <x-settings.admin-nav />

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="admin-kpi-grid">
        <article class="stat-card">
            <div class="kpi-label">{{ __('site.admin.website.stats.published_pages') }}</div>
            <div class="metric-value mt-3">{{ number_format($totals['published_pages']) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('site.admin.website.stats.program_cards') }}</div>
            <div class="metric-value mt-3">{{ number_format($totals['program_cards']) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('site.admin.website.stats.quick_stats') }}</div>
            <div class="metric-value mt-3">{{ number_format($totals['quick_stats']) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('site.admin.website.stats.media_assets') }}</div>
            <div class="metric-value mt-3">{{ number_format($totals['media_assets']) }}</div>
        </article>
    </section>

    @php
        $homepageSections = [
            ['id' => 'identity', 'title' => __('site.admin.website.sections.identity.title'), 'copy' => __('site.admin.website.sections.identity.copy')],
            ['id' => 'media', 'title' => __('site.admin.website.sections.gallery.title'), 'copy' => __('site.admin.website.sections.gallery.copy')],
            ['id' => 'hero', 'title' => __('site.admin.website.sections.hero.title'), 'copy' => __('site.admin.website.sections.hero.copy')],
            ['id' => 'story', 'title' => __('site.admin.website.sections.story.title'), 'copy' => __('site.admin.website.sections.story.copy')],
            ['id' => 'programs', 'title' => __('site.admin.website.sections.programs.title'), 'copy' => __('site.admin.website.sections.programs.copy')],
            ['id' => 'stats', 'title' => __('site.admin.website.sections.stats.title'), 'copy' => __('site.admin.website.sections.stats.copy')],
        ];
    @endphp

    <div class="website-workbench">
    <form wire:submit="saveWebsite" class="space-y-6">
        <section class="surface-panel p-6">
            <div class="website-editor-toolbar">
                <div>
                    <div class="text-lg font-semibold text-white">{{ __('site.admin.website.control.title') }}</div>
                    <p class="mt-2 text-sm leading-6 text-neutral-400">{{ __('site.admin.website.control.subtitle') }}</p>
                </div>
                <div class="admin-action-cluster">
                    <a href="{{ route('home') }}" target="_blank" class="pill-link">{{ __('site.admin.website.control.preview_site') }}</a>
                    <a href="{{ route('settings.website.pages') }}" wire:navigate class="pill-link">{{ __('site.admin.website.control.manage_pages') }}</a>
                    <a href="{{ route('settings.website.navigation') }}" wire:navigate class="pill-link">{{ __('site.admin.website.control.manage_navigation') }}</a>
                    <button type="submit" class="pill-link pill-link--accent">{{ __('site.admin.website.actions.save') }}</button>
                </div>
            </div>
        </section>
        <section id="identity" class="surface-panel p-6 website-section-card">
            <div class="mb-4">
                <div class="text-lg font-semibold text-white">{{ __('site.admin.website.sections.identity.title') }}</div>
                <p class="mt-2 text-sm text-neutral-400">{{ __('site.admin.website.sections.identity.copy') }}</p>
            </div>
            <div class="grid gap-4 lg:grid-cols-2">
                <input wire:model="site_name" type="text" class="rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.site_name') }}">
                <input wire:model="contact_phone" type="text" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.contact_phone') }}">
                <input wire:model="site_tagline_en" type="text" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.site_tagline_en') }}">
                <input wire:model="site_tagline_ar" type="text" dir="rtl" class="admin-locale-field--ar rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.site_tagline_ar') }}">
                <textarea wire:model="site_description_en" rows="3" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm lg:col-span-2" placeholder="{{ __('site.admin.website.fields.site_description_en') }}"></textarea>
                <textarea wire:model="site_description_ar" rows="3" dir="rtl" class="admin-locale-field--ar rounded-xl px-4 py-3 text-sm lg:col-span-2" placeholder="{{ __('site.admin.website.fields.site_description_ar') }}"></textarea>
                <input wire:model="contact_email" type="email" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.contact_email') }}">
                <input wire:model="maps_url" type="url" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.maps_url') }}">
                <textarea wire:model="contact_address_en" rows="3" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.contact_address_en') }}"></textarea>
                <textarea wire:model="contact_address_ar" rows="3" dir="rtl" class="admin-locale-field--ar rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.contact_address_ar') }}"></textarea>
                <input wire:model="whatsapp_url" type="url" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.whatsapp_url') }}">
                <div class="grid gap-4 md:grid-cols-2">
                    <input wire:model="primary_color" type="text" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.primary_color') }}">
                    <input wire:model="accent_color" type="text" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.accent_color') }}">
                </div>
            </div>
        </section>

        <section id="media" class="surface-panel p-6 website-section-card">
            <div class="mb-4">
                <div class="text-lg font-semibold text-white">{{ __('site.admin.website.sections.gallery.title') }}</div>
                <p class="mt-2 text-sm text-neutral-400">{{ __('site.admin.website.sections.gallery.copy') }}</p>
            </div>
            <div class="grid gap-4 xl:grid-cols-3">
                <div class="soft-callout p-4">@if ($logo_path)<img src="{{ asset('storage/'.ltrim($logo_path, '/')) }}" alt="{{ __('site.admin.website.media.logo_alt') }}" class="mb-3 h-24 rounded-2xl bg-white p-3">@endif<input wire:model="logo_upload" type="file" accept="image/*" class="block w-full text-sm">@if ($logo_path)<button type="button" wire:click="removeWebsiteAsset('logo')" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact mt-3">{{ __('site.admin.website.actions.remove_media') }}</button>@endif</div>
                <div class="soft-callout p-4">@if ($hero_image_path)<img src="{{ asset('storage/'.ltrim($hero_image_path, '/')) }}" alt="{{ __('site.admin.website.media.hero_alt') }}" class="mb-3 h-40 w-full rounded-2xl object-cover">@endif<input wire:model="hero_image_upload" type="file" accept="image/*" class="block w-full text-sm">@if ($hero_image_path)<button type="button" wire:click="removeWebsiteAsset('hero_image')" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact mt-3">{{ __('site.admin.website.actions.remove_media') }}</button>@endif</div>
                <div class="soft-callout p-4">@if ($featured_video_path)<video src="{{ asset('storage/'.ltrim($featured_video_path, '/')) }}" class="mb-3 h-40 w-full rounded-2xl object-cover" controls></video>@endif<input wire:model="featured_video_upload" type="file" accept="video/mp4,video/webm,video/quicktime" class="block w-full text-sm">@if ($featured_video_path)<button type="button" wire:click="removeWebsiteAsset('featured_video')" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact mt-3">{{ __('site.admin.website.actions.remove_media') }}</button>@endif</div>
            </div>
            <div class="mt-4">
                <input wire:model="gallery_uploads" type="file" accept="image/*" multiple class="block w-full text-sm">
                <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach ($gallery_items as $index => $galleryItem)
                        <div class="soft-callout space-y-3 p-3">
                            <img src="{{ asset('storage/'.ltrim((string) ($galleryItem['path'] ?? ''), '/')) }}" alt="{{ __('site.admin.website.media.gallery_alt') }}" class="h-32 w-full rounded-2xl object-cover">
                            <input wire:model="gallery_items.{{ $index }}.title_en" type="text" dir="ltr" class="admin-locale-field--en w-full rounded-xl px-3 py-2 text-sm" placeholder="{{ __('site.admin.website.fields.gallery_title_en') }}">
                            <input wire:model="gallery_items.{{ $index }}.title_ar" type="text" dir="rtl" class="admin-locale-field--ar w-full rounded-xl px-3 py-2 text-sm" placeholder="{{ __('site.admin.website.fields.gallery_title_ar') }}">
                            <textarea wire:model="gallery_items.{{ $index }}.details_en" rows="2" dir="ltr" class="admin-locale-field--en w-full rounded-xl px-3 py-2 text-sm" placeholder="{{ __('site.admin.website.fields.gallery_details_en') }}"></textarea>
                            <textarea wire:model="gallery_items.{{ $index }}.details_ar" rows="2" dir="rtl" class="admin-locale-field--ar w-full rounded-xl px-3 py-2 text-sm" placeholder="{{ __('site.admin.website.fields.gallery_details_ar') }}"></textarea>
                            <button type="button" wire:click="removeGalleryAsset({{ $index }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact">{{ __('site.admin.website.actions.remove_media') }}</button>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section id="hero" class="surface-panel p-6 website-section-card">
            <div class="mb-4">
                <div class="text-lg font-semibold text-white">{{ __('site.admin.website.sections.hero.title') }}</div>
                <p class="mt-2 text-sm text-neutral-400">{{ __('site.admin.website.sections.hero.copy') }}</p>
            </div>
            <div class="grid gap-4 lg:grid-cols-2">
                <input wire:model="hero_eyebrow_en" type="text" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.hero_eyebrow_en') }}">
                <input wire:model="hero_eyebrow_ar" type="text" dir="rtl" class="admin-locale-field--ar rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.hero_eyebrow_ar') }}">
                <input wire:model="hero_title_en" type="text" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.hero_title_en') }}">
                <input wire:model="hero_title_ar" type="text" dir="rtl" class="admin-locale-field--ar rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.hero_title_ar') }}">
                <textarea wire:model="hero_subtitle_en" rows="3" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm lg:col-span-2" placeholder="{{ __('site.admin.website.fields.hero_subtitle_en') }}"></textarea>
                <textarea wire:model="hero_subtitle_ar" rows="3" dir="rtl" class="admin-locale-field--ar rounded-xl px-4 py-3 text-sm lg:col-span-2" placeholder="{{ __('site.admin.website.fields.hero_subtitle_ar') }}"></textarea>
                <input wire:model="primary_cta_label_en" type="text" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.primary_cta_label_en') }}">
                <input wire:model="primary_cta_label_ar" type="text" dir="rtl" class="admin-locale-field--ar rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.primary_cta_label_ar') }}">
                <input wire:model="primary_cta_url" type="text" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.primary_cta_url') }}">
                <input wire:model="secondary_cta_url" type="text" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.secondary_cta_url') }}">
                <input wire:model="secondary_cta_label_en" type="text" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.secondary_cta_label_en') }}">
                <input wire:model="secondary_cta_label_ar" type="text" dir="rtl" class="admin-locale-field--ar rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.secondary_cta_label_ar') }}">
            </div>
        </section>

        <section id="story" class="surface-panel p-6 website-section-card">
            <div class="mb-4">
                <div class="text-lg font-semibold text-white">{{ __('site.admin.website.sections.story.title') }}</div>
                <p class="mt-2 text-sm text-neutral-400">{{ __('site.admin.website.sections.story.copy') }}</p>
            </div>
            <div class="grid gap-4 lg:grid-cols-2">
                <input wire:model="story_title_en" type="text" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.story_title_en') }}">
                <input wire:model="story_title_ar" type="text" dir="rtl" class="admin-locale-field--ar rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.story_title_ar') }}">
                <textarea wire:model="story_body_en" rows="4" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm lg:col-span-2" placeholder="{{ __('site.admin.website.fields.story_body_en') }}"></textarea>
                <textarea wire:model="story_body_ar" rows="4" dir="rtl" class="admin-locale-field--ar rounded-xl px-4 py-3 text-sm lg:col-span-2" placeholder="{{ __('site.admin.website.fields.story_body_ar') }}"></textarea>
                <input wire:model="story_quote_en" type="text" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.story_quote_en') }}">
                <input wire:model="story_quote_ar" type="text" dir="rtl" class="admin-locale-field--ar rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.story_quote_ar') }}">
            </div>
        </section>

        <section id="programs" class="surface-panel p-6 website-section-card">
            <div class="mb-4">
                <div class="text-lg font-semibold text-white">{{ __('site.admin.website.sections.programs.title') }}</div>
                <p class="mt-2 text-sm text-neutral-400">{{ __('site.admin.website.sections.programs.copy') }}</p>
            </div>
            <div class="grid gap-4 lg:grid-cols-2">
                <input wire:model="programs_title_en" type="text" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.programs_title_en') }}">
                <input wire:model="programs_title_ar" type="text" dir="rtl" class="admin-locale-field--ar rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.programs_title_ar') }}">
                <textarea wire:model="programs_subtitle_en" rows="3" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm lg:col-span-2" placeholder="{{ __('site.admin.website.fields.programs_subtitle_en') }}"></textarea>
                <textarea wire:model="programs_subtitle_ar" rows="3" dir="rtl" class="admin-locale-field--ar rounded-xl px-4 py-3 text-sm lg:col-span-2" placeholder="{{ __('site.admin.website.fields.programs_subtitle_ar') }}"></textarea>
            </div>
            <div class="mt-4 space-y-4">
                @foreach ($program_cards as $index => $programCard)
                    <div class="soft-callout grid gap-4 p-4 lg:grid-cols-2">
                        <input wire:model="program_cards.{{ $index }}.title_en" type="text" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.program_title_en') }}">
                        <input wire:model="program_cards.{{ $index }}.title_ar" type="text" dir="rtl" class="admin-locale-field--ar rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.program_title_ar') }}">
                        <textarea wire:model="program_cards.{{ $index }}.summary_en" rows="3" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm lg:col-span-2" placeholder="{{ __('site.admin.website.fields.program_summary_en') }}"></textarea>
                        <textarea wire:model="program_cards.{{ $index }}.summary_ar" rows="3" dir="rtl" class="admin-locale-field--ar rounded-xl px-4 py-3 text-sm lg:col-span-2" placeholder="{{ __('site.admin.website.fields.program_summary_ar') }}"></textarea>
                        <input wire:model="program_cards.{{ $index }}.link_label_en" type="text" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.program_link_label_en') }}">
                        <input wire:model="program_cards.{{ $index }}.link_label_ar" type="text" dir="rtl" class="admin-locale-field--ar rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.program_link_label_ar') }}">
                        <input wire:model="program_cards.{{ $index }}.link_url" type="text" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm lg:col-span-2" placeholder="{{ __('site.admin.website.fields.program_link_url') }}">
                        <button type="button" wire:click="removeProgramCard({{ $index }})" class="pill-link pill-link--compact">{{ __('site.admin.website.actions.remove_program') }}</button>
                    </div>
                @endforeach
            </div>
            <button type="button" wire:click="addProgramCard" class="pill-link mt-4">{{ __('site.admin.website.actions.add_program') }}</button>
        </section>

        <section id="stats" class="surface-panel p-6 website-section-card">
            <div class="mb-4">
                <div class="text-lg font-semibold text-white">{{ __('site.admin.website.sections.stats.title') }}</div>
                <p class="mt-2 text-sm text-neutral-400">{{ __('site.admin.website.sections.stats.copy') }}</p>
            </div>
            <div class="space-y-4">
                @foreach ($stats as $index => $stat)
                    <div class="soft-callout grid gap-4 p-4 lg:grid-cols-[10rem_minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end">
                        <input wire:model="stats.{{ $index }}.value" type="text" class="rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.stat_value') }}">
                        <input wire:model="stats.{{ $index }}.label_en" type="text" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.stat_label_en') }}">
                        <input wire:model="stats.{{ $index }}.label_ar" type="text" dir="rtl" class="admin-locale-field--ar rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.website.fields.stat_label_ar') }}">
                        <button type="button" wire:click="removeStat({{ $index }})" class="pill-link pill-link--compact">{{ __('site.admin.website.actions.remove_stat') }}</button>
                    </div>
                @endforeach
            </div>
            <div class="mt-4 flex flex-wrap gap-3">
                <button type="button" wire:click="addStat" class="pill-link">{{ __('site.admin.website.actions.add_stat') }}</button>
                <button type="submit" class="pill-link pill-link--accent">{{ __('site.admin.website.actions.save') }}</button>
            </div>
        </section>
    </form>

    <aside class="website-sidebar">
        <section class="surface-panel p-5">
            <div class="eyebrow">{{ __('site.admin.website.control.home_map') }}</div>
            <div class="website-outline mt-4">
                @foreach ($homepageSections as $section)
                    <a href="#{{ $section['id'] }}" class="website-outline__link">
                        <span class="website-outline__title">{{ $section['title'] }}</span>
                        <span class="website-outline__copy">{{ $section['copy'] }}</span>
                    </a>
                @endforeach
            </div>
        </section>

        <section class="surface-panel p-5">
            <div class="eyebrow">{{ __('site.admin.website.control.quick_links') }}</div>
            <div class="website-shortcut-grid mt-4">
                <a href="{{ route('home') }}" target="_blank" class="pill-link">{{ __('site.admin.website.control.preview_site') }}</a>
                <a href="{{ route('settings.website.pages') }}" wire:navigate class="pill-link">{{ __('site.admin.website.control.manage_pages') }}</a>
                <a href="{{ route('settings.website.navigation') }}" wire:navigate class="pill-link">{{ __('site.admin.website.control.manage_navigation') }}</a>
            </div>
        </section>

        <section class="surface-panel p-5">
            <div class="eyebrow">{{ __('site.admin.website.control.current_assets') }}</div>
            <div class="website-asset-stack mt-4">
                @if ($logo_path)
                    <div class="website-asset-stack__item">
                        <img src="{{ asset('storage/'.ltrim($logo_path, '/')) }}" alt="{{ __('site.admin.website.media.logo_alt') }}" class="website-asset-stack__thumb website-asset-stack__thumb--logo">
                        <div>
                            <div class="text-sm font-semibold text-white">{{ __('site.admin.website.fields.logo_upload') }}</div>
                            <div class="text-xs text-neutral-400">{{ basename($logo_path) }}</div>
                        </div>
                    </div>
                @endif
                @if ($hero_image_path)
                    <div class="website-asset-stack__item">
                        <img src="{{ asset('storage/'.ltrim($hero_image_path, '/')) }}" alt="{{ __('site.admin.website.media.hero_alt') }}" class="website-asset-stack__thumb">
                        <div>
                            <div class="text-sm font-semibold text-white">{{ __('site.admin.website.fields.hero_image_upload') }}</div>
                            <div class="text-xs text-neutral-400">{{ basename($hero_image_path) }}</div>
                        </div>
                    </div>
                @endif
                @if ($featured_video_path)
                    <div class="website-asset-stack__item">
                        <div class="website-asset-stack__icon">▶</div>
                        <div>
                            <div class="text-sm font-semibold text-white">{{ __('site.admin.website.fields.featured_video_upload') }}</div>
                            <div class="text-xs text-neutral-400">{{ basename($featured_video_path) }}</div>
                        </div>
                    </div>
                @endif
                <div class="website-asset-stack__item">
                    <div class="website-asset-stack__icon">{{ count($gallery_items) }}</div>
                    <div>
                        <div class="text-sm font-semibold text-white">{{ __('site.admin.website.fields.gallery_uploads') }}</div>
                        <div class="text-xs text-neutral-400">{{ __('crud.common.badges.in_view', ['count' => number_format(count($gallery_items))]) }}</div>
                    </div>
                </div>
            </div>
        </section>
    </aside>
    </div>
</div>
