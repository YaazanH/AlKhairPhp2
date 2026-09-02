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
        $this->persistSettings(true);
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'group_settings.') || str_starts_with($property, 'item_settings.')) {
            $this->autosave();
        }
    }

    protected function autosave(): void
    {
        foreach ($this->group_settings as $group) {
            if (($group['is_custom'] ?? false) && trim((string) ($group['title'] ?? '')) === '') {
                return;
            }
        }

        $this->persistSettings(false);
    }

    protected function persistSettings(bool $showMessage): void
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

        if ($showMessage) {
            session()->flash('status', __('settings.sidebar_navigation.messages.saved'));
        }

        $this->loadSettings();
        $this->dispatch('sidebar-navigation-updated');
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

        $this->dispatch('sidebar-navigation-group-added', key: $key);
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
        $this->autosave();
    }

    public function moveItem(string $itemKey, string $groupKey, ?string $beforeItemKey = null): void
    {
        $this->authorizePermission('sidebar-navigation.manage');

        if (! isset($this->item_settings[$itemKey], $this->group_settings[$groupKey])) {
            return;
        }

        $orderedKeys = collect($this->availableItems())
            ->where('group_key', $groupKey)
            ->pluck('key')
            ->reject(fn (string $key) => $key === $itemKey)
            ->values();
        $position = $beforeItemKey ? $orderedKeys->search($beforeItemKey) : false;
        $position === false ? $orderedKeys->push($itemKey) : $orderedKeys->splice($position, 0, [$itemKey]);

        $this->item_settings[$itemKey]['group_key'] = $groupKey;
        foreach ($orderedKeys as $index => $key) {
            $this->item_settings[$key]['sort_order'] = (string) (($index + 1) * 10);
        }

        $this->autosave();
    }

    public function moveGroup(string $groupKey, string $beforeGroupKey): void
    {
        $this->authorizePermission('sidebar-navigation.manage');

        if ($groupKey === $beforeGroupKey || ! isset($this->group_settings[$groupKey], $this->group_settings[$beforeGroupKey])) {
            return;
        }

        $keys = collect(array_keys($this->availableGroups()))->reject(fn (string $key) => $key === $groupKey)->values();
        $position = $keys->search($beforeGroupKey);
        $keys->splice($position === false ? $keys->count() : $position, 0, [$groupKey]);
        foreach ($keys as $index => $key) {
            $this->group_settings[$key]['sort_order'] = (string) (($index + 1) * 10);
        }

        $this->autosave();
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
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('ui.common.settings') }}</h1>
    </section>

    <x-settings.admin-nav section="dashboard" current="settings.sidebar-navigation" />

    @if (session('status'))
        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">{{ session('status') }}</div>
    @endif

    <div class="space-y-6" x-data="{ draggedItem: null, draggedGroup: null, itemDropTarget: null, groupDropTarget: null, settledItem: null, settledGroup: null }">
        <section class="surface-panel p-5 lg:p-6">
            <div class="admin-toolbar">
                <div></div>
                <div class="admin-toolbar__actions">
                    <x-add-action-button wire:click="addGroup" :label="__('settings.sidebar_navigation.actions.add_group')" :accent="false" />
                </div>
            </div>

            <div class="mt-5 space-y-4">
                @foreach ($availableGroups as $group)
                    <details
                        wire:key="sidebar-navigation-group-{{ $group['key'] }}"
                        class="nav-sort-group rounded-2xl border border-white/10 bg-white/4 p-4"
                        data-sidebar-navigation-group="{{ $group['key'] }}"
                        :class="{
                            'nav-sort-group--dragging': draggedGroup === @js($group['key']),
                            'nav-sort-group--drop-target': groupDropTarget === @js($group['key']),
                            'nav-sort-group--item-drop-target': itemDropTarget === @js('group:'.$group['key']),
                            'nav-sort-group--settled': settledGroup === @js($group['key'])
                        }"
                        open
                        x-data="{ editing: {{ $group['is_custom'] && $group['title'] === '' ? 'true' : 'false' }} }"
                        data-sidebar-navigation-item-drop-zone
                        @dragenter.prevent="if (draggedItem) { itemDropTarget = @js('group:'.$group['key']) } else if (draggedGroup && draggedGroup !== @js($group['key'])) { groupDropTarget = @js($group['key']) }"
                        @dragover.prevent
                        @drop.prevent="if (draggedItem) { const movingItem = draggedItem; $wire.moveItem(movingItem, @js($group['key'])).then(() => { draggedItem = null; itemDropTarget = null; settledItem = movingItem; setTimeout(() => settledItem = null, 320) }) } else if (draggedGroup && draggedGroup !== @js($group['key'])) { const movingGroup = draggedGroup; groupDropTarget = @js($group['key']); $wire.moveGroup(movingGroup, @js($group['key'])).then(() => { draggedGroup = null; groupDropTarget = null; settledGroup = movingGroup; setTimeout(() => settledGroup = null, 320) }) }"
                    >
                        <summary class="flex cursor-pointer list-none items-center gap-3">
                            <span draggable="true" @dragstart.stop="draggedGroup = @js($group['key']); groupDropTarget = null" @dragend="draggedGroup = null; groupDropTarget = null" class="nav-sort-handle" aria-hidden="true">⠿</span>
                            <span class="min-w-0 flex-1 text-sm font-semibold text-white">{{ $group['title'] ?: $group['default_title'] ?: __('settings.sidebar_navigation.labels.custom_group') }}</span>
                            <x-edit-action-button
                                x-on:click.prevent="editing = ! editing"
                                :label="__('crud.common.actions.edit')"
                                data-sidebar-group-edit-action
                            />
                            @if ($group['is_custom'])
                                <x-delete-action-button
                                    wire:click="removeGroup('{{ $group['key'] }}')"
                                    :label="__('crud.common.actions.delete')"
                                    data-sidebar-group-delete-action
                                />
                            @endif
                        </summary>
                        <div x-show="editing" x-cloak class="mt-4"><input wire:model.live.debounce.500ms="group_settings.{{ $group['key'] }}.title" type="text" class="w-full rounded-xl px-4 py-3 text-sm" placeholder="{{ __('settings.sidebar_navigation.fields.use_default_title') }}" data-sidebar-navigation-group-title></div>
                        <div class="mt-4 space-y-2 rounded-xl border border-dashed border-white/10 p-2" @dragover.prevent @drop.prevent.stop="if(draggedItem){ const movingItem = draggedItem; $wire.moveItem(movingItem, @js($group['key'])).then(() => { draggedItem = null; itemDropTarget = null; settledItem = movingItem; setTimeout(() => settledItem = null, 320) }) }">
                            @foreach (collect($availableItems)->where('group_key', $group['key']) as $item)
                                <div
                                    wire:key="sidebar-navigation-item-{{ $item['key'] }}"
                                    class="nav-sort-item flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 px-4 py-3"
                                    :class="{
                                        'nav-sort-item--dragging': draggedItem === @js($item['key']),
                                        'nav-sort-item--drop-target': itemDropTarget === @js($item['key']),
                                        'nav-sort-item--settled': settledItem === @js($item['key'])
                                    }"
                                    @dragenter.prevent.stop="if (draggedItem && draggedItem !== @js($item['key'])) itemDropTarget = @js($item['key'])"
                                    @dragover.prevent.stop
                                    @drop.prevent.stop="if (draggedItem && draggedItem !== @js($item['key'])) { const movingItem = draggedItem; itemDropTarget = @js($item['key']); $wire.moveItem(movingItem, @js($group['key']), @js($item['key'])).then(() => { draggedItem = null; itemDropTarget = null; settledItem = movingItem; setTimeout(() => settledItem = null, 320) }) }"
                                ><span draggable="true" @dragstart.stop="draggedItem = @js($item['key']); itemDropTarget = null" @dragend="draggedItem = null; itemDropTarget = null" class="nav-sort-handle" aria-hidden="true">⠿</span><span class="text-sm text-white">{{ $item['label'] }}</span></div>
                            @endforeach
                        </div>
                    </details>
                @endforeach
            </div>
        </section>
    </div>
</div>
