<x-platform-layout :title="__('platform.ui.subscription_settings.title')">
    <header>
        <p class="text-sm font-semibold text-emerald-700">{{ __('platform.ui.subscription_settings.eyebrow') }}</p>
        <h1 class="mt-2 text-3xl font-bold">{{ __('platform.ui.subscription_settings.title') }}</h1>
        <p class="mt-2 max-w-2xl text-zinc-600">{{ __('platform.ui.subscription_settings.description') }}</p>
    </header>

    <div><a href="{{ route('platform.vouchers.index') }}" class="inline-flex rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 font-medium text-emerald-800">{{ __('platform.ui.subscription_settings.vouchers') }}</a></div>

    <section class="max-w-2xl rounded-3xl border bg-white p-6 shadow-sm">
        @if(auth('platform')->user()->hasPlatformPermission('manage.subscriptions'))
        <form method="POST" action="{{ route('platform.subscription-settings.update') }}" class="space-y-5">
            @csrf
            @method('PUT')
            <label class="grid gap-2 text-sm font-medium">
                {{ __('platform.ui.subscription_settings.retention') }}
                <span class="text-xs font-normal text-zinc-500">{{ __('platform.ui.subscription_settings.retention_help') }}</span>
                <div class="flex items-center gap-3">
                    <input name="suspended_data_retention_months" type="number" min="1" max="120" value="{{ old('suspended_data_retention_months', $settings->suspended_data_retention_months) }}" required class="w-32 rounded-xl border p-3 font-normal">
                    <span class="text-sm text-zinc-600">{{ __('platform.ui.subscription_settings.months') }}</span>
                </div>
            </label>
            <button class="rounded-xl bg-emerald-700 px-4 py-3 font-medium text-white">{{ __('platform.ui.subscription_settings.save') }}</button>
        </form>
        @else
            <p class="text-sm text-zinc-600">{{ __('platform.ui.subscription_settings.readonly', ['months' => $settings->suspended_data_retention_months]) }}</p>
        @endif
    </section>
</x-platform-layout>
