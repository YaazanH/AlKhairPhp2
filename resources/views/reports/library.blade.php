<x-layouts.app>
    <div class="page-stack" data-report-library>
        <section class="page-hero p-6 lg:p-8">
            <div class="eyebrow">{{ __('report_library.eyebrow') }}</div>
            <div class="mt-4 flex flex-wrap items-end justify-between gap-5">
                <div>
                    <h1 class="font-display text-4xl leading-none text-white md:text-5xl">{{ __('report_library.title') }}</h1>
                    <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('report_library.subtitle') }}</p>
                </div>
                @canany(['report-designer.view', 'report-dashboard-layout.manage'])
                    <a href="{{ route('reports.designer') }}" class="pill-link">{{ __('report_library.actions.back') }}</a>
                @endcanany
            </div>
        </section>

        @if(session('status'))
            <div class="flash-success px-4 py-3 text-sm" role="status">{{ session('status') }}</div>
        @endif

        <section class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            @forelse($items as $item)
                @php
                    $revision = $item->publishedRevision;
                    $locale = app()->getLocale();
                    $name = $revision->name[$locale] ?? $revision->name['en'] ?? $revision->name['ar'];
                    $description = $revision->description[$locale] ?? $revision->description['en'] ?? $revision->description['ar'] ?? null;
                    $compatibility = $item->tenant_compatibility;
                    $source = data_get($revision->definition, 'data_source');
                @endphp
                <article class="surface-panel flex min-h-80 flex-col p-5 lg:p-6" data-library-item="{{ $item->uuid }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="eyebrow">{{ __('report_library.labels.'.$revision->kind) }}</div>
                            <h2 class="font-display mt-3 text-2xl text-white">{{ $name }}</h2>
                        </div>
                        <span class="shrink-0 rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs text-neutral-300">{{ __('report_library.labels.version', ['version' => $revision->version]) }}</span>
                    </div>

                    @if(filled($description))<p class="mt-4 text-sm leading-6 text-neutral-300">{{ $description }}</p>@endif

                    <div class="mt-5 flex flex-wrap gap-2 text-xs">
                        <span class="rounded-full bg-white/5 px-3 py-1.5 text-neutral-300">{{ __('report_designer.sources.'.$source.'.label') }}</span>
                        @foreach($revision->required_modules as $module)
                            <span class="rounded-full bg-emerald-400/10 px-3 py-1.5 text-emerald-200">{{ config('modules.definitions.'.$module.'.name', str_replace('_', ' ', $module)) }}</span>
                        @endforeach
                    </div>

                    <div class="mt-auto border-t border-white/10 pt-5">
                        @if($compatibility['compatible'])
                            <div class="mb-3 text-sm text-emerald-300">{{ __('report_library.labels.ready') }}</div>
                            <form method="POST" action="{{ route('reports.library.install', $item) }}">
                                @csrf
                                <button class="button-primary w-full justify-center">{{ __('report_library.actions.install') }}</button>
                            </form>
                        @else
                            <div class="rounded-xl border border-amber-300/20 bg-amber-300/10 p-3 text-sm leading-6 text-amber-100" role="note">{{ $compatibility['reason'] }}</div>
                        @endif
                    </div>
                </article>
            @empty
                <div class="surface-panel p-10 text-center md:col-span-2 xl:col-span-3">
                    <h2 class="font-display text-2xl text-white">{{ __('report_library.empty.title') }}</h2>
                    <p class="mt-2 text-sm text-neutral-400">{{ __('report_library.empty.copy') }}</p>
                </div>
            @endforelse
        </section>
    </div>
</x-layouts.app>
