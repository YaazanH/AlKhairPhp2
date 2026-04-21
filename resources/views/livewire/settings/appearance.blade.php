<?php

use Livewire\Volt\Component;

new class extends Component {
    //
}; ?>

<div class="page-stack">
    <section class="page-hero p-6 lg:p-8">
        <div class="eyebrow">{{ __('ui.nav.settings') }}</div>
        <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('settings.account.appearance.title') }}</h1>
        <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('settings.account.appearance.subtitle') }}</p>
    </section>

    <x-settings.layout :heading="__('settings.account.appearance.form_title')" :subheading="__('settings.account.appearance.form_subtitle')">
        <flux:radio.group x-data variant="segmented" x-model="$flux.appearance" class="max-w-xl">
            <flux:radio value="light" icon="sun">{{ __('settings.account.appearance.options.light') }}</flux:radio>
            <flux:radio value="dark" icon="moon">{{ __('settings.account.appearance.options.dark') }}</flux:radio>
            <flux:radio value="system" icon="computer-desktop">{{ __('settings.account.appearance.options.system') }}</flux:radio>
        </flux:radio.group>
    </x-settings.layout>
</div>
