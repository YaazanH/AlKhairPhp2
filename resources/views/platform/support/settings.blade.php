<x-platform-layout :title="__('support.settings.title')">
    <header>
        <a href="{{ route('platform.support.index') }}" class="text-sm text-emerald-700 underline">{{ __('support.platform.back') }}</a>
        <h1 class="mt-3 text-3xl font-bold">{{ __('support.settings.title') }}</h1>
        <p class="mt-2 max-w-3xl text-zinc-600">{{ __('support.settings.description') }}</p>
    </header>

    <form
        method="POST"
        action="{{ route('platform.support.settings.update') }}"
        class="space-y-6"
        x-data="{ groups: @js(old('support_request_options', $options)), blank() { return { key: '', label_en: '', label_ar: '', enabled: true } } }"
    >
        @csrf
        @method('PUT')

        @foreach ([
            \App\Services\Landlord\SupportRequestConfiguration::REASONS => __('support.settings.reasons'),
            \App\Services\Landlord\SupportRequestConfiguration::PRIORITIES => __('support.settings.priorities'),
            \App\Services\Landlord\SupportRequestConfiguration::IMPACTS => __('support.settings.impacts'),
        ] as $group => $heading)
            <section class="rounded-3xl border bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between gap-4">
                    <h2 class="text-lg font-semibold">{{ $heading }}</h2>
                    <button type="button" class="rounded-xl border px-3 py-2 text-sm" x-on:click="groups['{{ $group }}'].push(blank())">{{ __('support.settings.add') }}</button>
                </div>
                @error('support_request_options.'.$group)<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror

                <div class="mt-4 space-y-3">
                    <template x-for="(option, index) in groups['{{ $group }}']" :key="index">
                        <div class="grid gap-3 rounded-2xl border p-4 lg:grid-cols-[1fr_1.5fr_1.5fr_auto_auto] lg:items-end">
                            <label class="grid gap-1 text-sm">
                                <span>{{ __('support.settings.key') }}</span>
                                <input x-model="option.key" x-bind:name="`support_request_options[{{ $group }}][${index}][key]`" required pattern="[a-z0-9_]+" class="rounded-xl border p-3" dir="ltr">
                            </label>
                            <label class="grid gap-1 text-sm">
                                <span>{{ __('support.settings.label_en') }}</span>
                                <input x-model="option.label_en" x-bind:name="`support_request_options[{{ $group }}][${index}][label_en]`" required class="rounded-xl border p-3" dir="ltr">
                            </label>
                            <label class="grid gap-1 text-sm">
                                <span>{{ __('support.settings.label_ar') }}</span>
                                <input x-model="option.label_ar" x-bind:name="`support_request_options[{{ $group }}][${index}][label_ar]`" required class="rounded-xl border p-3" dir="rtl">
                            </label>
                            <label class="flex items-center gap-2 pb-3 text-sm">
                                <input type="checkbox" value="1" x-model="option.enabled" x-bind:name="`support_request_options[{{ $group }}][${index}][enabled]`" class="rounded">
                                <span>{{ __('support.settings.enabled') }}</span>
                            </label>
                            <button type="button" class="mb-1 rounded-xl border border-red-200 px-3 py-2 text-sm text-red-700" x-on:click="groups['{{ $group }}'].splice(index, 1)">{{ __('support.settings.remove') }}</button>
                        </div>
                    </template>
                </div>
            </section>
        @endforeach

        <button class="rounded-xl bg-emerald-700 px-5 py-3 font-medium text-white">{{ __('support.settings.save') }}</button>
    </form>
</x-platform-layout>
