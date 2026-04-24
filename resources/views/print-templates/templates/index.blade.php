<x-layouts.app>
    <div class="page-stack">
        <section class="page-hero p-6 lg:p-8">
            <div class="eyebrow">{{ __('ui.nav.identity_tools') }}</div>
            <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('print_templates.templates.title') }}</h1>
            <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('print_templates.templates.subtitle') }}</p>
            <div class="mt-6 admin-action-cluster">
                @can('id-cards.templates.manage')
                    <a href="{{ route('print-templates.templates.create') }}" class="pill-link pill-link--accent">{{ __('print_templates.templates.actions.create') }}</a>
                @endcan
                @can('id-cards.print')
                    <a href="{{ route('print-templates.print.create') }}" class="pill-link">{{ __('print_templates.templates.actions.print') }}</a>
                @endcan
            </div>
        </section>

        @if (session('status'))
            <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
        @endif

        <section class="surface-table">
            <div class="admin-grid-meta">
                <div>
                    <div class="eyebrow">{{ __('print_templates.templates.table.eyebrow') }}</div>
                    <div class="admin-grid-meta__title">{{ __('print_templates.templates.title') }}</div>
                </div>
                <span class="badge-soft">{{ number_format($templates->count()) }}</span>
            </div>

            @if ($templates->isEmpty())
                <div class="admin-empty-state">{{ __('print_templates.templates.table.empty') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="text-sm">
                        <thead>
                            <tr>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('print_templates.templates.table.headers.template') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('print_templates.templates.table.headers.size') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('print_templates.templates.table.headers.sources') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('print_templates.templates.table.headers.elements') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('print_templates.templates.table.headers.status') }}</th>
                                <th class="px-5 py-4 text-right lg:px-6">{{ __('print_templates.templates.table.headers.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/6">
                            @foreach ($templates as $template)
                                <tr>
                                    <td class="px-5 py-4 lg:px-6">
                                        <div class="font-semibold text-white">{{ $template->name }}</div>
                                        <div class="mt-1 text-xs text-neutral-400">#{{ $template->id }}</div>
                                    </td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format($template->width_mm, 2) }} × {{ number_format($template->height_mm, 2) }} mm</td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">
                                        {{ collect($template->data_sources ?? [])->pluck('entity')->map(fn ($entity) => __('print_templates.entities.'.$entity))->implode(' / ') ?: __('print_templates.common.no_sources') }}
                                    </td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ count($template->layout_json ?? []) }}</td>
                                    <td class="px-5 py-4 lg:px-6">
                                        <span class="status-chip {{ $template->is_active ? 'status-chip--active' : 'status-chip--muted' }}">
                                            {{ $template->is_active ? __('print_templates.templates.status.active') : __('print_templates.templates.status.inactive') }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-right lg:px-6">
                                        <div class="admin-action-cluster admin-action-cluster--end">
                                            @can('id-cards.print')
                                                <a href="{{ route('print-templates.print.create', ['template' => $template->id]) }}" class="pill-link pill-link--compact">{{ __('print_templates.templates.actions.print') }}</a>
                                            @endcan
                                            @can('id-cards.templates.manage')
                                                <a href="{{ route('print-templates.templates.edit', $template) }}" class="pill-link pill-link--compact">{{ __('crud.common.actions.edit') }}</a>
                                                <form method="POST" action="{{ route('print-templates.templates.destroy', $template) }}" data-admin-confirm-message="{{ __('print_templates.templates.confirm_delete') }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="pill-link pill-link--compact pill-link--danger">{{ __('crud.common.actions.delete') }}</button>
                                                </form>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-layouts.app>
