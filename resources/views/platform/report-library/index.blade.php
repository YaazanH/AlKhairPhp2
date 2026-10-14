<x-platform-layout :title="__('platform-content.library')">
    <div class="platform-management platform-content">
        <header class="platform-management__header"><p class="text-sm font-semibold text-emerald-700">{{ __('platform-workspace.groups.content') }}</p><h1 class="mt-2 font-bold">{{ __('platform-content.library') }}</h1><p class="mt-3 max-w-3xl text-zinc-500">{{ __('platform-content.library_intro') }}</p>@if(auth('platform')->user()->hasPlatformPermission('manage.report-library'))<a href="{{ route('platform.report-library.create') }}" class="mt-5 inline-flex rounded-xl bg-emerald-700 px-5 py-3 font-semibold text-white">{{ __('platform-content.create_item') }}</a>@endif</header>
        <section class="rounded-3xl border bg-white">
            <form method="GET" action="{{ route('platform.report-library.index') }}" class="platform-request-filters">
                <label>{{ __('platform-content.search') }}<input name="search" value="{{ request('search') }}" class="rounded-xl border p-3"></label>
                <label>{{ __('platform-content.kind') }}<select name="kind" class="rounded-xl border p-3"><option value="">{{ __('platform-content.all') }}</option>@foreach(['report', 'widget', 'both'] as $kind)<option value="{{ $kind }}" @selected(request('kind') === $kind)>{{ __('platform-content.kinds.'.$kind) }}</option>@endforeach</select></label>
                <label>{{ __('platform-content.state') }}<select name="state" class="rounded-xl border p-3"><option value="">{{ __('platform-content.all') }}</option>@foreach(['draft' => 'draft_only', 'published' => 'published'] as $state => $label)<option value="{{ $state }}" @selected(request('state') === $state)>{{ __('platform-content.'.$label) }}</option>@endforeach</select></label>
                <label>{{ __('platform-content.origin') }}<select name="origin" class="rounded-xl border p-3"><option value="">{{ __('platform-content.all') }}</option>@foreach(['system', 'custom'] as $origin)<option value="{{ $origin }}" @selected(request('origin') === $origin)>{{ __('platform-content.'.$origin) }}</option>@endforeach</select></label>
                <button class="rounded-xl bg-emerald-700 px-4 py-3 font-semibold text-white">{{ __('platform-content.filter') }}</button><a href="{{ route('platform.report-library.index') }}">{{ __('platform-content.reset') }}</a>
            </form>
        </section>
        <section class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            @forelse($items as $item)
                <article class="platform-library-card rounded-3xl border bg-white p-6">
                    <p class="text-sm text-emerald-700">{{ $item->is_system ? __('platform-content.system') : __('platform-content.kinds.'.$item->kind) }}</p>
                    <h2 class="mt-3 text-xl font-bold">{{ $item->name[app()->getLocale()] ?? $item->name['en'] }}</h2><p class="mt-2 text-sm text-zinc-500" dir="{{ app()->getLocale() === 'ar' ? 'ltr' : 'rtl' }}">{{ $item->name[app()->getLocale() === 'ar' ? 'en' : 'ar'] ?? '' }}</p>
                    <div class="mt-4 flex flex-wrap gap-2"><span class="platform-request-status">{{ $item->published_revision_id ? __('platform-content.published_version', ['version' => $item->latest_version]) : __('platform-content.draft_only') }}</span>@if($item->published_revision_id && $item->hasUnpublishedChanges())<span class="platform-request-status">{{ __('platform-content.pending') }}</span>@endif</div>
                    <p class="mt-4 text-sm text-zinc-500">{{ $item->description[app()->getLocale()] ?? __('platform-content.no_description') }}</p>
                    <div class="mt-4 flex flex-wrap gap-2">@foreach($item->required_modules ?? [] as $module)<span class="platform-request-status">{{ app(\App\Support\PlatformFeaturePresentation::class)->name($module) }}</span>@endforeach</div>
                    <footer class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t pt-4"><span class="text-xs text-zinc-500">{{ __('platform-content.updated', ['date' => $item->updated_at->format('Y-m-d')]) }}</span><a href="{{ route('platform.report-library.edit', $item) }}" class="text-sm font-semibold text-emerald-700">{{ __('platform-content.review_item') }}</a></footer>
                </article>
            @empty<p class="rounded-3xl border bg-white p-6 text-sm text-zinc-500 md:col-span-2 xl:col-span-3">{{ __('platform-content.empty_library') }}</p>@endforelse
        </section>
        {{ $items->links() }}
    </div>
</x-platform-layout>
