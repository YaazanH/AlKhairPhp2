<x-layouts.app>
    <div class="page-stack">
        <section class="page-hero p-6 lg:p-8">
            <div class="eyebrow">{{ __('ui.nav.identity_tools') }}</div>
            <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('id_cards.templates.title') }}</h1>
            <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('id_cards.templates.subtitle') }}</p>

            <div class="mt-6 flex flex-wrap gap-3">
                @can('id-cards.templates.manage')
                    <a href="{{ route('id-cards.templates.create') }}" class="pill-link pill-link--accent">{{ __('id_cards.templates.actions.create') }}</a>
                @endcan
                @can('id-cards.print')
                    <a href="{{ route('id-cards.print.create') }}" class="pill-link">{{ __('id_cards.templates.actions.generate') }}</a>
                @endcan
            </div>
        </section>

        @if (session('status'))
            <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
        @endif

        <section class="surface-table">
            <div class="admin-grid-meta">
                <div>
                    <div class="admin-grid-meta__title">{{ __('id_cards.templates.title') }}</div>
                    <div class="admin-grid-meta__summary">{{ trans_choice('crud.common.badges.in_view', $templates->count(), ['count' => number_format($templates->count())]) }}</div>
                </div>
            </div>

            @if ($templates->isEmpty())
                <div class="admin-empty-state">{{ __('id_cards.templates.table.empty') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('id_cards.templates.table.headers.template') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('id_cards.templates.table.headers.size') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('id_cards.templates.table.headers.elements') }}</th>
                                <th class="px-5 py-4 text-left lg:px-6">{{ __('id_cards.templates.table.headers.status') }}</th>
                                <th class="px-5 py-4 text-right lg:px-6">{{ __('id_cards.templates.table.headers.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/6">
                            @foreach ($templates as $template)
                                <tr>
                                    <td class="px-5 py-4 lg:px-6">
                                        <div class="admin-identity-stack">
                                            <div class="admin-identity-stack__title">{{ $template->name }}</div>
                                            <div class="admin-identity-stack__meta">
                                                <span>{{ number_format($template->width_mm, 2) }} × {{ number_format($template->height_mm, 2) }} mm</span>
                                                <span>{{ $template->updated_at?->format('Y-m-d') }}</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 text-neutral-300 lg:px-6">{{ number_format($template->width_mm, 2) }} × {{ number_format($template->height_mm, 2) }} mm</td>
                                    <td class="px-5 py-4 text-white lg:px-6">{{ count($template->layout_json ?? []) }}</td>
                                    <td class="px-5 py-4 lg:px-6">
                                        <span class="{{ $template->is_active ? 'status-chip status-chip--emerald' : 'status-chip status-chip--slate' }}">
                                            {{ $template->is_active ? __('id_cards.templates.status.active') : __('id_cards.templates.status.inactive') }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-right lg:px-6">
                                        <div class="admin-action-cluster admin-action-cluster--end">
                                            @can('id-cards.print')
                                                <a href="{{ route('id-cards.print.create', ['template' => $template->id]) }}" class="pill-link pill-link--compact">{{ __('id_cards.templates.actions.generate') }}</a>
                                            @endcan
                                            @can('id-cards.templates.manage')
                                                <a href="{{ route('id-cards.templates.edit', $template) }}" class="pill-link pill-link--compact">{{ __('crud.common.actions.edit') }}</a>
                                                <form
                                                    method="POST"
                                                    action="{{ route('id-cards.templates.destroy', $template) }}"
                                                    data-admin-confirm-label="{{ __('crud.common.confirm_delete.confirm') }}"
                                                    data-admin-confirm-message="{{ __('crud.common.confirm_delete.message') }}"
                                                    data-admin-confirm-title="{{ __('crud.common.confirm_delete.title') }}"
                                                    class="inline-flex"
                                                >
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12">
                                                        {{ __('crud.common.actions.delete') }}
                                                    </button>
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
