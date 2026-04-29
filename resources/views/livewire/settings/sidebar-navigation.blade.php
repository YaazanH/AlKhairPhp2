<?php

use App\Livewire\Concerns\AuthorizesPermissions;
use App\Services\SidebarNavigationService;
use Illuminate\Support\Str;
use Livewire\Volt\Component;

new class extends Component {
    use AuthorizesPermissions;

    public array $group_settings = [];
    public array $item_settings = [];

    public function mount(): void
    {
        $this->authorizePermission('sidebar-navigation.manage');
        $this->loadSettings();
    }

    public function with(): array
    {
        return [
            'availableGroups' => $this->availableGroups(),
            'availableItems' => $this->availableItems(),
        ];
    }

    public function save(): void
    {
        $this->authorizePermission('sidebar-navigation.manage');

        $service = app(SidebarNavigationService::class);
        $defaultGroupKeys = array_keys($service->defaultGroups());
        $itemKeys = array_keys($service->defaultItems());

        $validated = $this->validate([
            'group_settings' => ['required', 'array'],
            'group_settings.*.title' => ['nullable', 'string', 'max:80'],
            'group_settings.*.sort_order' => ['required', 'integer', 'min:0', 'max:999'],
            'group_settings.*.is_custom' => ['nullable', 'boolean'],
            'item_settings' => ['required', 'array'],
            'item_settings.*.group_key' => ['required', 'string'],
            'item_settings.*.sort_order' => ['required', 'integer', 'min:0', 'max:999'],
        ]);

        $validGroupKeys = [];

        foreach ($validated['group_settings'] as $groupKey => $groupSetting) {
            $isCustom = ! in_array($groupKey, $defaultGroupKeys, true);

            if (! in_array($groupKey, $defaultGroupKeys, true) && ! str_starts_with($groupKey, 'custom_')) {
                continue;
            }

            if ($isCustom && trim((string) ($groupSetting['title'] ?? '')) === '') {
                $this->addError('group_settings.'.$groupKey.'.title', __('settings.sidebar_navigation.errors.custom_group_title_required'));
            }

            $validGroupKeys[] = $groupKey;
        }

        $validated['item_settings'] = array_intersect_key($validated['item_settings'], array_flip($itemKeys));

        foreach ($validated['item_settings'] as $itemKey => $itemSetting) {
            if (! in_array((string) $itemSetting['group_key'], $validGroupKeys, true)) {
                $this->addError('item_settings.'.$itemKey.'.group_key', __('settings.sidebar_navigation.errors.group_required'));
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $service->save($validated['group_settings'], $validated['item_settings']);

        session()->flash('status', __('settings.sidebar_navigation.messages.saved'));
        $this->loadSettings();
    }

    public function addGroup(): void
    {
        $this->authorizePermission('sidebar-navigation.manage');

        do {
            $key = 'custom_'.Str::lower(Str::random(8));
        } while (isset($this->group_settings[$key]));

        $this->group_settings[$key] = [
            'title' => '',
            'sort_order' => (string) $this->nextGroupSortOrder(),
            'is_custom' => true,
        ];
    }

    public function removeGroup(string $groupKey): void
    {
        $this->authorizePermission('sidebar-navigation.manage');

        if (! ($this->group_settings[$groupKey]['is_custom'] ?? false)) {
            return;
        }

        unset($this->group_settings[$groupKey]);

        $defaultItems = app(SidebarNavigationService::class)->defaultItems();

        foreach ($this->item_settings as $itemKey => $itemSetting) {
            if (($itemSetting['group_key'] ?? null) !== $groupKey) {
                continue;
            }

            $this->item_settings[$itemKey]['group_key'] = $defaultItems[$itemKey]['group_key'] ?? 'platform';
        }

        $this->resetValidation();
    }

    protected function loadSettings(): void
    {
        $service = app(SidebarNavigationService::class);
        $this->group_settings = [];
        $this->item_settings = [];

        foreach ($service->editableGroups() as $group) {
            $this->group_settings[$group['key']] = [
                'title' => $group['title'],
                'sort_order' => (string) $group['sort_order'],
                'is_custom' => (bool) ($group['is_custom'] ?? false),
            ];
        }

        foreach ($service->editableItems() as $item) {
            $this->item_settings[$item['key']] = [
                'group_key' => $item['group_key'],
                'sort_order' => (string) $item['sort_order'],
            ];
        }
    }

    protected function availableGroups(): array
    {
        $service = app(SidebarNavigationService::class);
        $defaultGroups = $service->defaultGroups();
        $groups = [];

        foreach ($this->group_settings as $key => $groupSetting) {
            $definition = $defaultGroups[$key] ?? null;

            $groups[$key] = [
                'key' => $key,
                'default_title' => $definition ? __($definition['title_key']) : '',
                'title' => (string) ($groupSetting['title'] ?? ''),
                'sort_order' => (int) ($groupSetting['sort_order'] ?? ($definition['sort_order'] ?? 999)),
                'is_custom' => (bool) ($groupSetting['is_custom'] ?? ! $definition),
            ];
        }

        uasort($groups, function (array $left, array $right): int {
            return [$left['sort_order'], $left['title'] ?: $left['default_title']] <=> [$right['sort_order'], $right['title'] ?: $right['default_title']];
        });

        return $groups;
    }

    protected function availableItems(): array
    {
        $definitions = app(SidebarNavigationService::class)->defaultItems();
        $items = [];

        foreach ($definitions as $key => $definition) {
            $itemSetting = $this->item_settings[$key] ?? [];

            $items[$key] = [
                'key' => $key,
                'label' => __($definition['label_key']),
                'group_key' => (string) ($itemSetting['group_key'] ?? $definition['group_key']),
                'sort_order' => (int) ($itemSetting['sort_order'] ?? $definition['sort_order']),
            ];
        }

        uasort($items, fn (array $left, array $right) => [$left['group_key'], $left['sort_order'], $left['label']] <=> [$right['group_key'], $right['sort_order'], $right['label']]);

        return $items;
    }

    protected function nextGroupSortOrder(): int
    {
        $sortOrders = array_map(
            fn (array $group): int => (int) ($group['sort_order'] ?? 0),
            $this->group_settings
        );

        return ($sortOrders === [] ? 0 : max($sortOrders)) + 10;
    }
}; ?>

<div class="page-stack settings-admin-page">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.settings') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('settings.sidebar_navigation.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('settings.sidebar_navigation.subtitle') }}</p>
    </section>

    <x-settings.admin-nav section="dashboard" current="settings.sidebar-navigation" />

    @if (session('status'))
        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif

    <form wire:submit="save" class="grid gap-6 xl:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)]">
        <section class="surface-panel p-5 lg:p-6">
            <div class="admin-toolbar">
                <div>
                    <div class="admin-toolbar__title">{{ __('settings.sidebar_navigation.sections.groups.title') }}</div>
                    <p class="admin-toolbar__subtitle">{{ __('settings.sidebar_navigation.sections.groups.copy') }}</p>
                </div>

                <div class="admin-toolbar__actions">
                    <button type="button" wire:click="addGroup" class="pill-link">{{ __('settings.sidebar_navigation.actions.add_group') }}</button>
                </div>
            </div>

            <div class="mt-5 space-y-4">
                @foreach ($availableGroups as $group)
                    <div class="rounded-2xl border border-white/10 bg-white/4 p-4">
                        <div class="mb-4 flex items-center justify-between gap-3">
                            <div class="text-sm font-semibold text-white">
                                {{ $group['is_custom'] ? __('settings.sidebar_navigation.labels.custom_group') : $group['default_title'] }}
                            </div>

                            @if ($group['is_custom'])
                                <button type="button" wire:click="removeGroup('{{ $group['key'] }}')" class="pill-link pill-link--compact">{{ __('settings.sidebar_navigation.actions.remove_group') }}</button>
                            @endif
                        </div>

                        <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_120px]">
                            <div>
                                <label class="mb-1 block text-sm font-medium">
                                    {{ $group['is_custom'] ? __('settings.sidebar_navigation.fields.custom_group_title') : $group['default_title'] }}
                                </label>
                                <input wire:model="group_settings.{{ $group['key'] }}.title" type="text" class="w-full rounded-xl px-4 py-3 text-sm" placeholder="{{ $group['is_custom'] ? __('settings.sidebar_navigation.fields.custom_group_title_placeholder') : __('settings.sidebar_navigation.fields.use_default_title') }}">
                                @error('group_settings.'.$group['key'].'.title') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                            </div>

                            <div>
                                <label class="mb-1 block text-sm font-medium">{{ __('settings.sidebar_navigation.fields.group_order') }}</label>
                                <input wire:model="group_settings.{{ $group['key'] }}.sort_order" type="number" min="0" max="999" class="w-full rounded-xl px-4 py-3 text-sm">
                                @error('group_settings.'.$group['key'].'.sort_order') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="surface-panel p-5 lg:p-6">
            <div class="admin-toolbar">
                <div>
                    <div class="admin-toolbar__title">{{ __('settings.sidebar_navigation.sections.items.title') }}</div>
                    <p class="admin-toolbar__subtitle">{{ __('settings.sidebar_navigation.sections.items.copy') }}</p>
                </div>
            </div>

            <div class="mt-5 space-y-4">
                @foreach ($availableItems as $item)
                    <div class="rounded-2xl border border-white/10 bg-white/4 p-4">
                        <div class="mb-3 text-sm font-semibold text-white">{{ $item['label'] }}</div>
                        <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_120px]">
                            <div>
                                <label class="mb-1 block text-sm font-medium">{{ __('settings.sidebar_navigation.fields.group') }}</label>
                                <select wire:model="item_settings.{{ $item['key'] }}.group_key" class="w-full rounded-xl px-4 py-3 text-sm">
                                    @foreach ($availableGroups as $group)
                                        <option value="{{ $group['key'] }}">{{ $group['title'] ?: $group['default_title'] }}</option>
                                    @endforeach
                                </select>
                                @error('item_settings.'.$item['key'].'.group_key') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                            </div>

                            <div>
                                <label class="mb-1 block text-sm font-medium">{{ __('settings.sidebar_navigation.fields.item_order') }}</label>
                                <input wire:model="item_settings.{{ $item['key'] }}.sort_order" type="number" min="0" max="999" class="w-full rounded-xl px-4 py-3 text-sm">
                                @error('item_settings.'.$item['key'].'.sort_order') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <div class="xl:col-span-2 flex justify-end">
            <button type="submit" class="pill-link pill-link--accent">{{ __('settings.sidebar_navigation.actions.save') }}</button>
        </div>
    </form>
</div>
