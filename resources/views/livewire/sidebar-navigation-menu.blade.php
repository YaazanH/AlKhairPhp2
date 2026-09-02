<?php

use App\Services\SidebarNavigationService;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {
    public array $sidebarGroups = [];

    public function mount(): void
    {
        $this->refreshNavigation();
    }

    #[On('sidebar-navigation-updated')]
    public function refreshNavigation(): void
    {
        $this->sidebarGroups = app(SidebarNavigationService::class)->sidebarFor(auth()->user());
    }
}; ?>

<flux:navlist variant="outline" class="mt-5" data-app-sidebar-navigation>
    @foreach ($sidebarGroups as $group)
        <flux:navlist.group
            wire:key="app-sidebar-navigation-group-{{ $group['key'] }}"
            :heading="$group['title']"
            class="grid"
            data-app-sidebar-navigation-group="{{ $group['key'] }}"
        >
            @foreach ($group['items'] as $item)
                <flux:navlist.item
                    wire:key="app-sidebar-navigation-item-{{ $item['key'] }}"
                    :icon="$item['icon']"
                    :href="$item['href']"
                    :current="$item['current']"
                    class="{{ in_array($item['key'], ['print_templates', 'id_card_print', 'public_website_settings', 'data_quality', 'data_audit'], true) ? 'max-lg:hidden' : '' }}"
                    data-app-sidebar-navigation-item="{{ $item['key'] }}"
                    wire:navigate
                >
                    {{ $item['label'] }}
                </flux:navlist.item>
            @endforeach
        </flux:navlist.group>
    @endforeach
</flux:navlist>
