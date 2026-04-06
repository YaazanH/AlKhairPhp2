<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\WebsitePage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use AuthorizesPermissions, WithFileUploads;

    public ?int $editing_page_id = null;
    public string $slug = '';
    public string $title_en = '';
    public string $title_ar = '';
    public string $nav_label_en = '';
    public string $nav_label_ar = '';
    public string $excerpt_en = '';
    public string $excerpt_ar = '';
    public array $sections = [];
    public string $navigation_order = '10';
    public bool $is_published = true;
    public bool $show_in_navigation = true;
    public ?string $hero_media_path = null;
    public $hero_media_upload = null;

    public function mount(): void
    {
        $this->authorizePermission('website.manage');
        $this->createPage();
    }

    public function with(): array
    {
        $pages = WebsitePage::query()
            ->where('is_home', false)
            ->orderBy('navigation_order')
            ->orderBy('id')
            ->get();

        return [
            'pages' => $pages,
            'totals' => [
                'total' => $pages->count(),
                'published' => $pages->where('is_published', true)->count(),
                'navigation' => $pages->where('show_in_navigation', true)->count(),
            ],
            'sectionTypeOptions' => $this->sectionTypeOptions(),
        ];
    }

    public function createPage(): void
    {
        $this->cancelPage();
        $this->navigation_order = (string) (((int) WebsitePage::query()->where('is_home', false)->max('navigation_order')) + 10 ?: 10);
    }

    public function cancelPage(): void
    {
        $this->editing_page_id = null;
        $this->slug = '';
        $this->title_en = '';
        $this->title_ar = '';
        $this->nav_label_en = '';
        $this->nav_label_ar = '';
        $this->excerpt_en = '';
        $this->excerpt_ar = '';
        $this->sections = [$this->blankSection()];
        $this->navigation_order = '10';
        $this->is_published = true;
        $this->show_in_navigation = true;
        $this->hero_media_path = null;
        $this->hero_media_upload = null;
        $this->resetValidation();
    }

    public function editPage(int $pageId): void
    {
        $page = WebsitePage::query()->findOrFail($pageId);

        $this->editing_page_id = $page->id;
        $this->slug = $page->slug;
        $this->title_en = (string) data_get($page->title, 'en', '');
        $this->title_ar = (string) data_get($page->title, 'ar', '');
        $this->nav_label_en = (string) data_get($page->navigation_label, 'en', '');
        $this->nav_label_ar = (string) data_get($page->navigation_label, 'ar', '');
        $this->excerpt_en = (string) data_get($page->excerpt, 'en', '');
        $this->excerpt_ar = (string) data_get($page->excerpt, 'ar', '');
        $this->sections = $this->sectionsForPage($page);
        $this->navigation_order = (string) $page->navigation_order;
        $this->is_published = $page->is_published;
        $this->show_in_navigation = $page->show_in_navigation;
        $this->hero_media_path = $page->hero_media_path;
        $this->hero_media_upload = null;
    }

    public function addSection(string $type = 'rich_text'): void
    {
        $this->sections[] = $this->blankSection($type);
    }

    public function removeSection(int $index): void
    {
        if (! array_key_exists($index, $this->sections)) {
            return;
        }

        unset($this->sections[$index]);
        $this->sections = array_values($this->sections);

        if ($this->sections === []) {
            $this->sections[] = $this->blankSection();
        }
    }

    public function moveSectionUp(int $index): void
    {
        if ($index < 1 || ! isset($this->sections[$index - 1], $this->sections[$index])) {
            return;
        }

        [$this->sections[$index - 1], $this->sections[$index]] = [$this->sections[$index], $this->sections[$index - 1]];
        $this->sections = array_values($this->sections);
    }

    public function moveSectionDown(int $index): void
    {
        if (! isset($this->sections[$index], $this->sections[$index + 1])) {
            return;
        }

        [$this->sections[$index], $this->sections[$index + 1]] = [$this->sections[$index + 1], $this->sections[$index]];
        $this->sections = array_values($this->sections);
    }

    public function deletePage(int $pageId): void
    {
        $page = WebsitePage::query()->findOrFail($pageId);

        if ($page->is_home) {
            $this->addError('pageDelete', __('site.admin.pages.errors.delete_home'));

            return;
        }

        if ($page->hero_media_path) {
            Storage::disk('public')->delete($page->hero_media_path);
        }

        $page->delete();

        if ($this->editing_page_id === $pageId) {
            $this->createPage();
        }

        session()->flash('status', __('site.admin.pages.messages.deleted'));
    }

    public function savePage(): void
    {
        $validated = $this->validate([
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('website_pages', 'slug')->ignore($this->editing_page_id), 'not_in:home'],
            'title_en' => ['required', 'string', 'max:255'],
            'title_ar' => ['required', 'string', 'max:255'],
            'nav_label_en' => ['nullable', 'string', 'max:255'],
            'nav_label_ar' => ['nullable', 'string', 'max:255'],
            'excerpt_en' => ['nullable', 'string'],
            'excerpt_ar' => ['nullable', 'string'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*.type' => ['required', 'string', Rule::in($this->availableSectionTypes())],
            'sections.*.heading_en' => ['nullable', 'string', 'max:255'],
            'sections.*.heading_ar' => ['nullable', 'string', 'max:255'],
            'sections.*.body_en' => ['nullable', 'string'],
            'sections.*.body_ar' => ['nullable', 'string'],
            'sections.*.secondary_en' => ['nullable', 'string'],
            'sections.*.secondary_ar' => ['nullable', 'string'],
            'sections.*.button_label_en' => ['nullable', 'string', 'max:255'],
            'sections.*.button_label_ar' => ['nullable', 'string', 'max:255'],
            'sections.*.button_url' => ['nullable', 'string', 'max:2048'],
            'sections.*.embed_code' => ['nullable', 'string'],
            'sections.*.custom_html' => ['nullable', 'string'],
            'navigation_order' => ['required', 'integer', 'min:0'],
            'is_published' => ['boolean'],
            'show_in_navigation' => ['boolean'],
            'hero_media_upload' => ['nullable', 'image', 'max:4096'],
        ]);

        $sections = $this->prepareSectionsForStorage($validated['sections']);

        if ($sections === []) {
            $this->addError('sections', __('site.admin.pages.builder.empty'));

            return;
        }

        if ($validated['hero_media_upload'] ?? null) {
            if ($this->hero_media_path) {
                Storage::disk('public')->delete($this->hero_media_path);
            }

            $this->hero_media_path = $validated['hero_media_upload']->store('website/pages', 'public');
        }

        $body = [
            'en' => collect($sections)->map(fn (array $section): string => (string) data_get($section, 'body.en', ''))->filter(fn (string $value): bool => filled($value))->implode("\n\n"),
            'ar' => collect($sections)->map(fn (array $section): string => (string) data_get($section, 'body.ar', ''))->filter(fn (string $value): bool => filled($value))->implode("\n\n"),
        ];

        $page = WebsitePage::query()->updateOrCreate(
            ['id' => $this->editing_page_id],
            [
                'slug' => Str::of($validated['slug'])->lower()->trim()->toString(),
                'template' => 'page',
                'title' => ['en' => $validated['title_en'], 'ar' => $validated['title_ar']],
                'navigation_label' => ['en' => $validated['nav_label_en'] ?: $validated['title_en'], 'ar' => $validated['nav_label_ar'] ?: $validated['title_ar']],
                'excerpt' => ['en' => $validated['excerpt_en'], 'ar' => $validated['excerpt_ar']],
                'body' => $body,
                'sections' => $sections,
                'seo_title' => ['en' => $validated['title_en'], 'ar' => $validated['title_ar']],
                'seo_description' => ['en' => $validated['excerpt_en'], 'ar' => $validated['excerpt_ar']],
                'hero_media_path' => $this->hero_media_path,
                'is_home' => false,
                'is_published' => $validated['is_published'],
                'show_in_navigation' => $validated['show_in_navigation'],
                'navigation_order' => (int) $validated['navigation_order'],
                'published_at' => $validated['is_published'] ? now() : null,
            ],
        );

        session()->flash('status', $this->editing_page_id ? __('site.admin.pages.messages.saved_update') : __('site.admin.pages.messages.saved_create'));
        $this->editPage($page->id);
    }

    protected function sectionsForPage(WebsitePage $page): array
    {
        $sections = collect($page->sections ?? [])
            ->map(fn (mixed $section): array => $this->blankSection((string) data_get($section, 'type', 'rich_text'), is_array($section) ? $section : []))
            ->values()
            ->all();

        if ($sections !== []) {
            return $sections;
        }

        return [$this->blankSection('rich_text', [
            'body' => [
                'en' => (string) data_get($page->body, 'en', ''),
                'ar' => (string) data_get($page->body, 'ar', ''),
            ],
        ])];
    }

    protected function blankSection(string $type = 'rich_text', array $section = []): array
    {
        $type = in_array($type, $this->availableSectionTypes(), true) ? $type : 'rich_text';

        return [
            'type' => $type,
            'heading_en' => (string) data_get($section, 'heading_en', data_get($section, 'heading.en', '')),
            'heading_ar' => (string) data_get($section, 'heading_ar', data_get($section, 'heading.ar', '')),
            'body_en' => (string) data_get($section, 'body_en', data_get($section, 'body.en', '')),
            'body_ar' => (string) data_get($section, 'body_ar', data_get($section, 'body.ar', '')),
            'secondary_en' => (string) data_get($section, 'secondary_en', data_get($section, 'secondary.en', '')),
            'secondary_ar' => (string) data_get($section, 'secondary_ar', data_get($section, 'secondary.ar', '')),
            'button_label_en' => (string) data_get($section, 'button_label_en', data_get($section, 'button_label.en', '')),
            'button_label_ar' => (string) data_get($section, 'button_label_ar', data_get($section, 'button_label.ar', '')),
            'button_url' => (string) data_get($section, 'button_url', ''),
            'embed_code' => (string) data_get($section, 'embed_code', ''),
            'custom_html' => (string) data_get($section, 'custom_html', ''),
        ];
    }

    protected function sectionTypeOptions(): array
    {
        return collect($this->availableSectionTypes())
            ->mapWithKeys(fn (string $type): array => [$type => __('site.admin.pages.builder.types.'.$type)])
            ->all();
    }

    protected function availableSectionTypes(): array
    {
        return ['rich_text', 'two_columns', 'cta', 'map', 'embed', 'custom_html'];
    }

    protected function prepareSectionsForStorage(array $sections): array
    {
        return collect($sections)
            ->map(function (array $section): array {
                $normalized = $this->blankSection((string) data_get($section, 'type', 'rich_text'), $section);

                return [
                    'type' => $normalized['type'],
                    'heading' => ['en' => trim($normalized['heading_en']), 'ar' => trim($normalized['heading_ar'])],
                    'body' => ['en' => trim($normalized['body_en']), 'ar' => trim($normalized['body_ar'])],
                    'secondary' => ['en' => trim($normalized['secondary_en']), 'ar' => trim($normalized['secondary_ar'])],
                    'button_label' => ['en' => trim($normalized['button_label_en']), 'ar' => trim($normalized['button_label_ar'])],
                    'button_url' => trim($normalized['button_url']),
                    'embed_code' => trim($normalized['embed_code']),
                    'custom_html' => trim($normalized['custom_html']),
                ];
            })
            ->filter(fn (array $section): bool => $this->storedSectionHasContent($section))
            ->values()
            ->all();
    }

    protected function storedSectionHasContent(array $section): bool
    {
        return filled(data_get($section, 'heading.en'))
            || filled(data_get($section, 'heading.ar'))
            || filled(data_get($section, 'body.en'))
            || filled(data_get($section, 'body.ar'))
            || filled(data_get($section, 'secondary.en'))
            || filled(data_get($section, 'secondary.ar'))
            || filled(data_get($section, 'button_label.en'))
            || filled(data_get($section, 'button_label.ar'))
            || filled(data_get($section, 'button_url'))
            || filled(data_get($section, 'embed_code'))
            || filled(data_get($section, 'custom_html'));
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('site.admin.nav.meta') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('site.admin.pages.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('site.admin.pages.subtitle') }}</p>
    </section>

    <x-settings.admin-nav />

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="admin-kpi-grid">
        <article class="stat-card">
            <div class="kpi-label">{{ __('site.admin.pages.stats.total') }}</div>
            <div class="metric-value mt-3">{{ number_format($totals['total']) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('site.admin.pages.stats.published') }}</div>
            <div class="metric-value mt-3">{{ number_format($totals['published']) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('site.admin.pages.stats.navigation') }}</div>
            <div class="metric-value mt-3">{{ number_format($totals['navigation']) }}</div>
        </article>
    </section>

    <div class="website-workbench website-workbench--editor">
        <section class="surface-panel p-6">
            <div class="website-editor-toolbar">
                <div>
                    <div class="text-lg font-semibold text-white">{{ __('site.admin.pages.workspace.library_title') }}</div>
                    <p class="mt-2 text-sm leading-6 text-neutral-400">{{ __('site.admin.pages.workspace.library_copy') }}</p>
                </div>
                <div class="admin-action-cluster">
                    <button type="button" wire:click="createPage" class="pill-link">{{ __('site.admin.pages.workspace.new_page') }}</button>
                    <a href="{{ route('settings.website.navigation') }}" wire:navigate class="pill-link">{{ __('site.admin.pages.workspace.open_navigation') }}</a>
                </div>
            </div>

            <div class="website-library mt-6">
                @forelse ($pages as $page)
                    <article class="website-library-card">
                        <div class="website-library-card__header">
                            <div class="min-w-0">
                                <div class="font-semibold text-white">{{ $page->localizedText('title') }}</div>
                                <div class="mt-1 text-sm text-neutral-400">{{ $page->slug }}</div>
                            </div>
                            <div class="admin-action-cluster">
                                <button type="button" wire:click="editPage({{ $page->id }})" class="pill-link pill-link--compact">{{ __('crud.common.actions.edit') }}</button>
                                <button type="button" wire:click="deletePage({{ $page->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">{{ __('crud.common.actions.delete') }}</button>
                            </div>
                        </div>

                        <div class="website-library-card__meta">
                            <span class="status-chip {{ $page->is_published ? 'status-chip--emerald' : 'status-chip--rose' }}">{{ $page->is_published ? __('workflow.common.flags.active') : __('workflow.common.flags.inactive') }}</span>
                            <span class="status-chip status-chip--neutral">{{ $page->show_in_navigation ? __('site.admin.pages.badges.in_navigation') : __('site.admin.pages.badges.not_in_navigation') }}</span>
                            <span class="status-chip status-chip--neutral">{{ __('site.admin.pages.workspace.section_count', ['count' => count($page->sections ?? [])]) }}</span>
                        </div>

                        @if ($page->localizedText('excerpt'))
                            <p class="mt-4 text-sm leading-6 text-neutral-300">{{ $page->localizedText('excerpt') }}</p>
                        @endif

                        <div class="admin-action-cluster mt-4">
                            <a href="{{ route('website.pages.show', $page) }}" target="_blank" class="pill-link pill-link--compact">{{ __('site.admin.pages.workspace.preview_page') }}</a>
                        </div>
                    </article>
                @empty
                    <div class="admin-empty-state">{{ __('site.admin.pages.table.empty') }}</div>
                @endforelse
            </div>
        </section>

        <section class="surface-panel p-6">
            <div class="website-editor-toolbar">
                <div>
                    <div class="text-lg font-semibold text-white">{{ __('site.admin.pages.workspace.editor_title') }}</div>
                    <p class="mt-2 text-sm leading-6 text-neutral-400">{{ __('site.admin.pages.workspace.editor_copy') }}</p>
                </div>
                <div class="admin-action-cluster">
                    @if ($editing_page_id)
                        <a href="{{ route('website.pages.show', WebsitePage::query()->find($editing_page_id)) }}" target="_blank" class="pill-link">{{ __('site.admin.pages.workspace.preview_page') }}</a>
                    @endif
                    <button type="button" wire:click="createPage" class="pill-link">{{ __('site.admin.pages.workspace.new_page') }}</button>
                </div>
            </div>

            <form wire:submit="savePage" class="mt-6 space-y-6">
                <section class="soft-callout p-4">
                    <div class="text-sm font-semibold text-white">{{ __('site.admin.pages.fields.slug') }}</div>
                    <div class="grid gap-4 lg:grid-cols-2 mt-4">
                        <input wire:model="slug" type="text" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.pages.fields.slug') }}">
                        <input wire:model="navigation_order" type="number" min="0" class="rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.pages.fields.navigation_order') }}">
                        <input wire:model="title_en" type="text" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.pages.fields.title_en') }}">
                        <input wire:model="title_ar" type="text" dir="rtl" class="admin-locale-field--ar rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.pages.fields.title_ar') }}">
                        <input wire:model="nav_label_en" type="text" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.pages.fields.nav_label_en') }}">
                        <input wire:model="nav_label_ar" type="text" dir="rtl" class="admin-locale-field--ar rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.pages.fields.nav_label_ar') }}">
                    </div>
                    <div class="grid gap-4 lg:grid-cols-2 mt-4">
                        <textarea wire:model="excerpt_en" rows="3" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.pages.fields.excerpt_en') }}"></textarea>
                        <textarea wire:model="excerpt_ar" rows="3" dir="rtl" class="admin-locale-field--ar rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.pages.fields.excerpt_ar') }}"></textarea>
                    </div>
                </section>

                <section class="soft-callout p-4">
                    <div class="text-sm font-semibold text-white">{{ __('site.admin.pages.workspace.visibility') }}</div>
                    <div class="admin-checkbox-stack mt-4">
                        <label class="admin-checkbox"><input wire:model="is_published" type="checkbox" class="rounded"><span>{{ __('site.admin.pages.fields.published') }}</span></label>
                        <label class="admin-checkbox"><input wire:model="show_in_navigation" type="checkbox" class="rounded"><span>{{ __('site.admin.pages.fields.show_in_navigation') }}</span></label>
                    </div>
                </section>

                <section class="soft-callout p-4">
                    <div class="text-sm font-semibold text-white">{{ __('site.admin.pages.workspace.hero_media') }}</div>
                    @if ($hero_media_path)
                        <img src="{{ asset('storage/'.ltrim($hero_media_path, '/')) }}" alt="{{ __('site.admin.website.media.hero_alt') }}" class="mt-4 h-40 w-full rounded-2xl object-cover">
                    @endif
                    <input wire:model="hero_media_upload" type="file" accept="image/*" class="mt-4 block w-full text-sm">
                </section>

                <section class="soft-callout p-4">
                    <div class="admin-builder-header">
                        <div>
                            <div class="text-sm font-semibold text-white">{{ __('site.admin.pages.builder.title') }}</div>
                            <p class="mt-1 text-sm text-neutral-400">{{ __('site.admin.pages.builder.subtitle') }}</p>
                        </div>
                        <div class="admin-action-cluster">
                            @foreach ($sectionTypeOptions as $sectionType => $sectionLabel)
                                <button type="button" wire:click="addSection('{{ $sectionType }}')" class="pill-link pill-link--compact">
                                    {{ __('site.admin.pages.builder.actions.add_section') }}: {{ $sectionLabel }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    @error('sections')
                        <div class="mt-3 text-sm text-red-400">{{ $message }}</div>
                    @enderror

                    <div class="mt-4 space-y-4">
                        @foreach ($sections as $index => $section)
                            <div class="website-builder-card">
                                <div class="admin-builder-section-header">
                                    <div class="admin-builder-section-title text-sm font-semibold text-white">
                                        {{ __('site.admin.pages.builder.title') }} {{ $index + 1 }}
                                        <span class="admin-builder-chip">{{ $sectionTypeOptions[$section['type']] ?? $section['type'] }}</span>
                                    </div>
                                    <div class="admin-action-cluster">
                                        <button type="button" wire:click="moveSectionUp({{ $index }})" class="pill-link pill-link--compact">{{ __('site.admin.pages.builder.actions.move_up') }}</button>
                                        <button type="button" wire:click="moveSectionDown({{ $index }})" class="pill-link pill-link--compact">{{ __('site.admin.pages.builder.actions.move_down') }}</button>
                                        <button type="button" wire:click="removeSection({{ $index }})" class="pill-link pill-link--compact">{{ __('site.admin.pages.builder.actions.remove_section') }}</button>
                                    </div>
                                </div>

                                <div class="mt-4 space-y-4">
                                    <select wire:model="sections.{{ $index }}.type" class="w-full rounded-xl px-4 py-3 text-sm">
                                        @foreach ($sectionTypeOptions as $sectionType => $sectionLabel)
                                            <option value="{{ $sectionType }}">{{ $sectionLabel }}</option>
                                        @endforeach
                                    </select>

                                    <div class="grid gap-4 lg:grid-cols-2">
                                        <input wire:model="sections.{{ $index }}.heading_en" type="text" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.pages.builder.fields.heading_en') }}">
                                        <input wire:model="sections.{{ $index }}.heading_ar" type="text" dir="rtl" class="admin-locale-field--ar rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.pages.builder.fields.heading_ar') }}">
                                    </div>

                                    @if (in_array($section['type'], ['rich_text', 'two_columns', 'cta', 'map'], true))
                                        <div class="grid gap-4 lg:grid-cols-2">
                                            <textarea wire:model="sections.{{ $index }}.body_en" rows="5" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.pages.builder.fields.body_en') }}"></textarea>
                                            <textarea wire:model="sections.{{ $index }}.body_ar" rows="5" dir="rtl" class="admin-locale-field--ar rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.pages.builder.fields.body_ar') }}"></textarea>
                                        </div>
                                    @endif

                                    @if ($section['type'] === 'two_columns')
                                        <div class="grid gap-4 lg:grid-cols-2">
                                            <textarea wire:model="sections.{{ $index }}.secondary_en" rows="5" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.pages.builder.fields.secondary_en') }}"></textarea>
                                            <textarea wire:model="sections.{{ $index }}.secondary_ar" rows="5" dir="rtl" class="admin-locale-field--ar rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.pages.builder.fields.secondary_ar') }}"></textarea>
                                        </div>
                                    @endif

                                    @if (in_array($section['type'], ['cta', 'map'], true))
                                        <div class="grid gap-4 lg:grid-cols-2">
                                            <input wire:model="sections.{{ $index }}.button_label_en" type="text" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.pages.builder.fields.button_label_en') }}">
                                            <input wire:model="sections.{{ $index }}.button_label_ar" type="text" dir="rtl" class="admin-locale-field--ar rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.pages.builder.fields.button_label_ar') }}">
                                        </div>
                                        <input wire:model="sections.{{ $index }}.button_url" type="text" dir="ltr" class="admin-locale-field--en w-full rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.pages.builder.fields.button_url') }}">
                                    @endif

                                    @if (in_array($section['type'], ['map', 'embed'], true))
                                        <textarea wire:model="sections.{{ $index }}.embed_code" rows="6" dir="ltr" class="admin-locale-field--en w-full rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.pages.builder.fields.embed_code') }}"></textarea>
                                    @endif

                                    @if ($section['type'] === 'custom_html')
                                        <textarea wire:model="sections.{{ $index }}.custom_html" rows="8" dir="ltr" class="admin-locale-field--en w-full rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.pages.builder.fields.custom_html') }}"></textarea>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>

                @error('pageDelete') <div class="text-sm text-red-400">{{ $message }}</div> @enderror

                <div class="admin-action-cluster">
                    <button type="submit" class="pill-link pill-link--accent">{{ $editing_page_id ? __('site.admin.pages.form.save_update') : __('site.admin.pages.form.save_create') }}</button>
                    @if ($editing_page_id)
                        <button type="button" wire:click="createPage" class="pill-link">{{ __('site.admin.pages.workspace.new_page') }}</button>
                    @endif
                </div>
            </form>
        </section>
    </div>
</div>
