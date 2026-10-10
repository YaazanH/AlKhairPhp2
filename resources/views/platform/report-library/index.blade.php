<x-platform-layout title="Report & Widget Library">
    <header class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
            <p class="text-sm font-medium text-emerald-600">Platform workspace</p>
            <h1 class="text-3xl font-bold">Report & Widget Library</h1>
            <p class="mt-1 max-w-3xl text-zinc-500">Prepare reusable definitions without tenant data. Only published revisions will be available for tenant installation.</p>
        </div>
        @if(auth('platform')->user()->hasPlatformPermission('manage.report-library'))
            <a href="{{ route('platform.report-library.create') }}" class="rounded-xl bg-emerald-700 px-4 py-3 text-center font-medium text-white">+ Create library item</a>
        @endif
    </header>

    <section class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
        @forelse($items as $item)
            <article class="flex min-h-64 flex-col rounded-3xl border bg-white p-6 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-emerald-700">{{ $item->is_system ? 'Built-in template' : ucfirst($item->kind) }}</p>
                        <h2 class="mt-1 text-xl font-bold">{{ $item->name['en'] }}</h2>
                        <p class="mt-1 text-sm text-zinc-500" dir="rtl">{{ $item->name['ar'] }}</p>
                    </div>
                    <span class="rounded-full px-3 py-1 text-xs font-medium {{ $item->published_revision_id ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                        {{ $item->published_revision_id ? 'Published v'.$item->latest_version : 'Draft only' }}
                    </span>
                </div>
                <p class="mt-4 text-sm text-zinc-600">{{ $item->description['en'] ?? 'No English description yet.' }}</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <span class="rounded-full bg-zinc-100 px-2.5 py-1 text-xs text-zinc-700">{{ str_replace('_', ' ', data_get($item->draft_definition, 'data_source')) }}</span>
                    @foreach($item->required_modules as $module)
                        <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs text-emerald-700">{{ config('modules.definitions.'.$module.'.name', str_replace('_', ' ', $module)) }}</span>
                    @endforeach
                </div>
                <div class="mt-auto flex items-center justify-between border-t pt-5 text-sm">
                    <span class="text-zinc-500">Updated {{ $item->updated_at->diffForHumans() }}</span>
                    <a href="{{ route('platform.report-library.edit', $item) }}" class="font-semibold text-emerald-700">{{ $item->is_system ? 'View template' : 'Review item' }} &rarr;</a>
                </div>
            </article>
        @empty
            <div class="rounded-3xl border border-dashed bg-white p-10 text-center text-zinc-500 md:col-span-2 xl:col-span-3">
                No library items exist yet. Create the first safe report or widget template.
            </div>
        @endforelse
    </section>
</x-platform-layout>
