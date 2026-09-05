<?php

use App\Services\SidebarNavigationService;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {
    protected const MOBILE_HIDDEN_ITEM_KEYS = [
        'print_templates',
        'id_card_print',
        'public_website_settings',
        'data_quality',
        'data_audit',
    ];

    public array $sidebarGroups = [];

    public function mount(): void
    {
        $this->refreshNavigation();
    }

    #[On('sidebar-navigation-updated')]
    public function refreshNavigation(): void
    {
        $this->sidebarGroups = collect(app(SidebarNavigationService::class)->sidebarFor(auth()->user()))
            ->map(function (array $group): array {
                $group['items'] = collect($group['items'])
                    ->map(function (array $item): array {
                        $item['mobile_hidden'] = in_array($item['key'], self::MOBILE_HIDDEN_ITEM_KEYS, true);

                        return $item;
                    })
                    ->all();
                $group['has_mobile_items'] = collect($group['items'])->contains(
                    fn (array $item): bool => ! $item['mobile_hidden'],
                );

                return $group;
            })
            ->all();
    }
}; ?>

<flux:navlist variant="outline" class="mt-5" data-app-sidebar-navigation>
    @foreach ($sidebarGroups as $group)
        <flux:navlist.group
            wire:key="app-sidebar-navigation-group-{{ $group['key'] }}"
            :heading="$group['title']"
            class="grid {{ $group['has_mobile_items'] ? '' : 'max-lg:hidden' }}"
            data-app-sidebar-navigation-group="{{ $group['key'] }}"
            data-app-sidebar-navigation-mobile-empty="{{ $group['has_mobile_items'] ? 'false' : $group['key'] }}"
        >
            @foreach ($group['items'] as $item)
                <flux:navlist.item
                    wire:key="app-sidebar-navigation-item-{{ $item['key'] }}"
                    :icon="$item['icon']"
                    :href="$item['href']"
                    :current="$item['current']"
                    class="{{ $item['mobile_hidden'] ? 'max-lg:hidden' : '' }}"
                    data-app-sidebar-navigation-item="{{ $item['key'] }}"
                    wire:navigate
                >
                    {{ $item['label'] }}
                </flux:navlist.item>
            @endforeach
        </flux:navlist.group>
    @endforeach
</flux:navlist>
