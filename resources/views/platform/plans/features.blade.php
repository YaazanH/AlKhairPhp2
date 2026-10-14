<x-platform-layout :title="__('platform-features.catalog')">
    <div class="platform-management"><header class="platform-management__header"><a href="{{ route('platform.plans.index') }}" class="text-sm text-emerald-700">{{ __('platform-features.back') }}</a><h1 class="mt-3 font-bold">{{ __('platform-features.catalog') }}</h1><p class="mt-3 text-zinc-500">{{ __('platform-features.catalog_help') }}</p></header><section class="rounded-3xl border bg-white p-6"><x-platform.feature-picker :catalog="$catalog" reference /></section></div>
</x-platform-layout>
