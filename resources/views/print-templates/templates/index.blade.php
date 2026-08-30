<x-layouts.app>
    <div class="page-stack">
        <section class="page-hero p-6 lg:p-8">
            <div class="eyebrow">{{ __('ui.nav.identity_tools') }}</div>
            <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('print_templates.templates.title') }}</h1>
        </section>

        @if (session('status'))
            <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
        @endif

        <section class="surface-table">
            <div class="admin-grid-meta admin-grid-meta--controls">
                <div class="admin-grid-meta__title">{{ __('print_templates.templates.title') }}</div>
                <div class="admin-toolbar__actions">
                    @can('id-cards.templates.manage')
                        <x-add-action-button :href="route('print-templates.templates.create')" :label="__('print_templates.templates.actions.create')" />
                    @endcan
                </div>
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
                                <th class="admin-actions-column px-5 py-4 text-center lg:px-6">{{ __('print_templates.templates.table.headers.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/6">
                            @foreach ($templates as $template)
                                <tr>
                                    <td class="px-5 py-4 lg:px-6">
                                        <div class="font-semibold text-white">{{ $template->name }}</div>
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
                                    <td class="px-5 py-4 lg:px-6">
                                        @can('id-cards.templates.manage')
                                            <x-open-action-button :href="route('print-templates.templates.edit', $template)" :label="__('crud.common.actions.open')" />
                                        @endcan
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
