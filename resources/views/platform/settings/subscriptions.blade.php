<x-platform-layout title="Subscription settings">
    <header>
        <p class="text-sm font-semibold text-emerald-700">Platform settings</p>
        <h1 class="mt-2 text-3xl font-bold">Subscription lifecycle</h1>
        <p class="mt-2 max-w-2xl text-zinc-600">Control how long suspended tenant data is retained. This setting never deletes data automatically.</p>
    </header>

    <section class="max-w-2xl rounded-3xl border bg-white p-6 shadow-sm">
        @if(auth('platform')->user()->hasPlatformPermission('manage.subscriptions'))
        <form method="POST" action="{{ route('platform.subscription-settings.update') }}" class="space-y-5">
            @csrf
            @method('PUT')
            <label class="grid gap-2 text-sm font-medium">
                Suspended tenant data retention
                <span class="text-xs font-normal text-zinc-500">The initial recommendation is 12 months. Permanent deletion remains a separate, confirmed Platform action.</span>
                <div class="flex items-center gap-3">
                    <input name="suspended_data_retention_months" type="number" min="1" max="120" value="{{ old('suspended_data_retention_months', $settings->suspended_data_retention_months) }}" required class="w-32 rounded-xl border p-3 font-normal">
                    <span class="text-sm text-zinc-600">months</span>
                </div>
            </label>
            <button class="rounded-xl bg-emerald-700 px-4 py-3 font-medium text-white">Save retention setting</button>
        </form>
        @else
            <p class="text-sm text-zinc-600">Suspended tenant data is retained for <strong>{{ $settings->suspended_data_retention_months }} months</strong>. You need the manage subscriptions permission to change this setting.</p>
        @endif
    </section>
</x-platform-layout>
