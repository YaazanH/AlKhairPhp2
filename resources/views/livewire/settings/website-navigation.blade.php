<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Models\WebsiteMenu;
use App\Models\WebsiteMenuItem;
use App\Models\WebsitePage;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;

    public ?int $editing_item_id = null;
    public ?int $website_page_id = null;
    public ?int $parent_id = null;
    public string $label_en = '';
    public string $label_ar = '';
    public string $url = '';
    public string $sort_order = '10';
    public bool $is_active = true;
    public bool $open_in_new_tab = false;

    public function mount(): void
    {
        $this->authorizePermission('website.manage');
        $this->startRootItem();
    }

    public function with(): array
    {
        $menu = $this->primaryMenu();
        $items = $menu->items()
            ->with(['page', 'parent'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $rootItems = $items
            ->whereNull('parent_id')
            ->values()
            ->map(fn (WebsiteMenuItem $item): array => [
                'item' => $item,
                'children' => $items->where('parent_id', $item->id)->values(),
            ]);

        return [
            'items' => $items,
            'rootItems' => $rootItems,
            'totals' => [
                'total' => $items->count(),
                'active' => $items->where('is_active', true)->count(),
                'dropdown_children' => $items->whereNotNull('parent_id')->count(),
            ],
            'pages' => WebsitePage::query()
                ->published()
                ->where('is_home', false)
                ->orderBy('navigation_order')
                ->orderBy('id')
                ->get(),
            'parentOptions' => $menu->rootItems()
                ->with('page')
                ->get()
                ->reject(fn (WebsiteMenuItem $item): bool => $item->id === $this->editing_item_id)
                ->values(),
        ];
    }

    public function startRootItem(): void
    {
        $this->cancelItem();
        $this->sort_order = (string) $this->nextSortOrder();
    }

    public function startChildItem(int $parentId): void
    {
        $this->cancelItem();
        $this->parent_id = $parentId;
        $this->sort_order = (string) $this->nextSortOrder($parentId);
    }

    public function updatedWebsitePageId($value): void
    {
        if (blank($value)) {
            return;
        }

        $page = WebsitePage::query()->find($value);

        if (! $page) {
            return;
        }

        if (blank($this->label_en)) {
            $this->label_en = (string) data_get($page->navigation_label, 'en', data_get($page->title, 'en', ''));
        }

        if (blank($this->label_ar)) {
            $this->label_ar = (string) data_get($page->navigation_label, 'ar', data_get($page->title, 'ar', ''));
        }
    }

    public function cancelItem(): void
    {
        $this->editing_item_id = null;
        $this->website_page_id = null;
        $this->parent_id = null;
        $this->label_en = '';
        $this->label_ar = '';
        $this->url = '';
        $this->sort_order = '10';
        $this->is_active = true;
        $this->open_in_new_tab = false;
        $this->resetValidation();
    }

    public function editItem(int $itemId): void
    {
        $item = WebsiteMenuItem::query()->with('page')->findOrFail($itemId);

        $this->editing_item_id = $item->id;
        $this->website_page_id = $item->website_page_id;
        $this->parent_id = $item->parent_id;
        $this->label_en = (string) data_get($item->label, 'en', '');
        $this->label_ar = (string) data_get($item->label, 'ar', '');
        $this->url = (string) ($item->url ?? '');
        $this->sort_order = (string) $item->sort_order;
        $this->is_active = $item->is_active;
        $this->open_in_new_tab = $item->open_in_new_tab;
    }

    public function saveItem(): void
    {
        $menu = $this->primaryMenu();

        $validated = $this->validate([
            'website_page_id' => ['nullable', Rule::exists('website_pages', 'id')],
            'parent_id' => ['nullable', Rule::exists('website_menu_items', 'id')],
            'label_en' => ['nullable', 'string', 'max:255'],
            'label_ar' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'string', 'max:2048'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['boolean'],
            'open_in_new_tab' => ['boolean'],
        ]);

        $parent = null;

        if ($validated['parent_id']) {
            $parent = WebsiteMenuItem::query()
                ->where('website_menu_id', $menu->id)
                ->whereNull('parent_id')
                ->find($validated['parent_id']);

            if (! $parent || $parent->id === $this->editing_item_id) {
                $this->addError('parent_id', __('site.admin.menus.errors.parent_must_be_root'));

                return;
            }
        }

        if (
            ! $validated['website_page_id']
            && blank(trim($validated['url'] ?? ''))
            && blank(trim($validated['label_en'] ?? ''))
            && blank(trim($validated['label_ar'] ?? ''))
        ) {
            $this->addError('label_en', __('site.admin.menus.errors.content_required'));

            return;
        }

        WebsiteMenuItem::query()->updateOrCreate(
            ['id' => $this->editing_item_id],
            [
                'website_menu_id' => $menu->id,
                'parent_id' => $parent?->id,
                'website_page_id' => $validated['website_page_id'],
                'label' => [
                    'en' => trim((string) ($validated['label_en'] ?? '')),
                    'ar' => trim((string) ($validated['label_ar'] ?? '')),
                ],
                'url' => filled(trim((string) ($validated['url'] ?? ''))) ? trim((string) $validated['url']) : null,
                'sort_order' => (int) $validated['sort_order'],
                'is_active' => (bool) $validated['is_active'],
                'open_in_new_tab' => (bool) $validated['open_in_new_tab'],
            ],
        );

        session()->flash('status', $this->editing_item_id ? __('site.admin.menus.messages.saved_update') : __('site.admin.menus.messages.saved_create'));

        if ($parent?->id) {
            $this->startChildItem($parent->id);

            return;
        }

        $this->startRootItem();
    }

    public function deleteItem(int $itemId): void
    {
        $item = WebsiteMenuItem::query()->findOrFail($itemId);
        $item->delete();

        if ($this->editing_item_id === $itemId) {
            $this->startRootItem();
        }

        session()->flash('status', __('site.admin.menus.messages.deleted'));
    }

    protected function nextSortOrder(?int $parentId = null): int
    {
        $query = WebsiteMenuItem::query()
            ->where('website_menu_id', $this->primaryMenu()->id);

        if ($parentId) {
            $query->where('parent_id', $parentId);
        } else {
            $query->whereNull('parent_id');
        }

        return ((int) $query->max('sort_order')) + 10;
    }

    protected function primaryMenu(): WebsiteMenu
    {
        return WebsiteMenu::query()->firstOrCreate(
            ['key' => 'primary'],
            ['title' => ['en' => 'Primary Navigation', 'ar' => 'التنقل الرئيسي']],
        );
    }
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('site.admin.nav.meta') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('site.admin.menus.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('site.admin.menus.subtitle') }}</p>
    </section>

    <x-settings.admin-nav section="website" current="settings.website.navigation" />

    @if (session('status'))
        <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
    @endif

    <section class="admin-kpi-grid">
        <article class="stat-card">
            <div class="kpi-label">{{ __('site.admin.menus.stats.total') }}</div>
            <div class="metric-value mt-3">{{ number_format($totals['total']) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('site.admin.menus.stats.active') }}</div>
            <div class="metric-value mt-3">{{ number_format($totals['active']) }}</div>
        </article>
        <article class="stat-card">
            <div class="kpi-label">{{ __('site.admin.menus.stats.dropdown_children') }}</div>
            <div class="metric-value mt-3">{{ number_format($totals['dropdown_children']) }}</div>
        </article>
    </section>

    <div class="website-workbench website-workbench--editor">
        <section class="surface-panel p-6">
            <div class="website-editor-toolbar">
                <div>
                    <div class="text-lg font-semibold text-white">{{ __('site.admin.menus.workspace.structure_title') }}</div>
                    <p class="mt-2 text-sm leading-6 text-neutral-400">{{ __('site.admin.menus.workspace.structure_copy') }}</p>
                </div>
                <div class="admin-action-cluster">
                    <button type="button" wire:click="startRootItem" class="pill-link">{{ __('site.admin.menus.workspace.new_root') }}</button>
                    <a href="{{ route('home') }}" target="_blank" class="pill-link">{{ __('site.admin.menus.workspace.preview_site') }}</a>
                </div>
            </div>

            @if ($rootItems->isEmpty())
                <div class="admin-empty-state mt-6">{{ __('site.admin.menus.table.empty') }}</div>
            @else
                <div class="website-tree mt-6">
                    @foreach ($rootItems as $branch)
                        @php
                            /** @var \App\Models\WebsiteMenuItem $rootItem */
                            $rootItem = $branch['item'];
                            $children = $branch['children'];
                            $rootDestination = filled($rootItem->url)
                                ? $rootItem->url
                                : ($rootItem->page ? route('website.pages.show', $rootItem->page) : '—');
                        @endphp
                        <article class="website-tree__branch">
                            <div class="website-tree__header">
                                <div class="min-w-0">
                                    <div class="website-tree__title">{{ $rootItem->localizedLabel() ?: __('site.admin.menus.options.custom_link') }}</div>
                                    <div class="website-tree__meta">{{ __('site.admin.menus.workspace.child_count', ['count' => $children->count()]) }}</div>
                                </div>
                                <div class="admin-action-cluster">
                                    <button type="button" wire:click="startChildItem({{ $rootItem->id }})" class="pill-link pill-link--compact">{{ __('site.admin.menus.workspace.add_child') }}</button>
                                    <button type="button" wire:click="editItem({{ $rootItem->id }})" class="pill-link pill-link--compact">{{ __('crud.common.actions.edit') }}</button>
                                    <button type="button" wire:click="deleteItem({{ $rootItem->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">{{ __('crud.common.actions.delete') }}</button>
                                </div>
                            </div>

                            <div class="website-tree__details">
                                <div class="website-tree__badge">{{ __('site.admin.menus.workspace.custom_target') }}</div>
                                <div class="text-sm text-neutral-300">{{ $rootDestination }}</div>
                            </div>

                            @if ($children->isNotEmpty())
                                <div class="website-tree__children">
                                    @foreach ($children as $child)
                                        @php
                                            $childDestination = filled($child->url)
                                                ? $child->url
                                                : ($child->page ? route('website.pages.show', $child->page) : '—');
                                        @endphp
                                        <div class="website-tree__child">
                                            <div class="min-w-0">
                                                <div class="website-tree__title website-tree__title--child">{{ $child->localizedLabel() ?: __('site.admin.menus.options.custom_link') }}</div>
                                                <div class="website-tree__meta">{{ $childDestination }}</div>
                                            </div>
                                            <div class="admin-action-cluster">
                                                <button type="button" wire:click="editItem({{ $child->id }})" class="pill-link pill-link--compact">{{ __('crud.common.actions.edit') }}</button>
                                                <button type="button" wire:click="deleteItem({{ $child->id }})" wire:confirm="{{ __('crud.common.confirm_delete.message') }}" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">{{ __('crud.common.actions.delete') }}</button>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="surface-panel p-6">
            <div class="website-editor-toolbar">
                <div>
                    <div class="text-lg font-semibold text-white">{{ __('site.admin.menus.workspace.editor_title') }}</div>
                    <p class="mt-2 text-sm leading-6 text-neutral-400">{{ __('site.admin.menus.workspace.editor_copy') }}</p>
                </div>
            </div>

            <form wire:submit="saveItem" class="mt-6 space-y-6">
                <section class="soft-callout p-4">
                    <div class="text-sm font-semibold text-white">{{ __('site.admin.menus.workspace.linked_page') }}</div>
                    <p class="mt-1 text-sm text-neutral-400">{{ __('site.admin.menus.hints.page_fallback') }}</p>
                    <select wire:model="website_page_id" class="mt-4 w-full rounded-xl px-4 py-3 text-sm">
                        <option value="">{{ __('site.admin.menus.options.custom_link') }}</option>
                        @foreach ($pages as $page)
                            <option value="{{ $page->id }}">{{ $page->localizedText('title', 'en') }} / {{ $page->localizedText('title', 'ar') }}</option>
                        @endforeach
                    </select>
                </section>

                <section class="soft-callout p-4">
                    <div class="text-sm font-semibold text-white">{{ __('site.admin.menus.workspace.custom_target') }}</div>
                    <div class="grid gap-4 lg:grid-cols-2 mt-4">
                        <input wire:model="label_en" type="text" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.menus.fields.label_en') }}">
                        <input wire:model="label_ar" type="text" dir="rtl" class="admin-locale-field--ar rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.menus.fields.label_ar') }}">
                        <input wire:model="url" type="text" dir="ltr" class="admin-locale-field--en rounded-xl px-4 py-3 text-sm lg:col-span-2" placeholder="{{ __('site.admin.menus.fields.url') }}">
                    </div>
                </section>

                <section class="soft-callout p-4">
                    <div class="text-sm font-semibold text-white">{{ __('site.admin.menus.hints.dropdown_scope') }}</div>
                    <div class="grid gap-4 lg:grid-cols-2 mt-4">
                        <select wire:model="parent_id" class="w-full rounded-xl px-4 py-3 text-sm">
                            <option value="">{{ __('site.admin.menus.options.none') }}</option>
                            @foreach ($parentOptions as $parentOption)
                                <option value="{{ $parentOption->id }}">{{ $parentOption->localizedLabel('en') ?: $parentOption->localizedLabel() }}</option>
                            @endforeach
                        </select>
                        <input wire:model="sort_order" type="number" min="0" class="rounded-xl px-4 py-3 text-sm" placeholder="{{ __('site.admin.menus.fields.sort_order') }}">
                    </div>
                    <div class="admin-checkbox-stack mt-4">
                        <label class="admin-checkbox"><input wire:model="is_active" type="checkbox" class="rounded"><span>{{ __('site.admin.menus.fields.is_active') }}</span></label>
                        <label class="admin-checkbox"><input wire:model="open_in_new_tab" type="checkbox" class="rounded"><span>{{ __('site.admin.menus.fields.open_in_new_tab') }}</span></label>
                    </div>
                </section>

                <div class="space-y-1">
                    @error('parent_id') <div class="text-sm text-red-400">{{ $message }}</div> @enderror
                    @error('label_en') <div class="text-sm text-red-400">{{ $message }}</div> @enderror
                    @error('url') <div class="text-sm text-red-400">{{ $message }}</div> @enderror
                    @error('website_page_id') <div class="text-sm text-red-400">{{ $message }}</div> @enderror
                </div>

                <div class="admin-action-cluster">
                    <button type="submit" class="pill-link pill-link--accent">{{ $editing_item_id ? __('site.admin.menus.form.save_update') : __('site.admin.menus.form.save_create') }}</button>
                    @if ($editing_item_id)
                        <button type="button" wire:click="cancelItem" class="pill-link">{{ __('site.admin.menus.form.cancel') }}</button>
                    @else
                        <button type="button" wire:click="startRootItem" class="pill-link">{{ __('site.admin.menus.workspace.new_root') }}</button>
                    @endif
                </div>
            </form>
        </section>
    </div>
</div>
