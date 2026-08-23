@php
    $isEditing = $template->exists;
    $backgroundImageUrl = $template->background_image_url;
    $initialLayoutJson = old('layout_json', $layoutJson);
    $initialDataSourcesJson = old('data_sources_json', $dataSourcesJson);
@endphp

<x-layouts.app>
    <div class="page-stack">
        <section class="page-hero p-6 lg:p-8">
            <div class="flex flex-wrap items-start justify-between gap-5">
                <div>
                    <div class="eyebrow">{{ __('ui.nav.identity_tools') }}</div>
                    <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">
                        {{ $isEditing ? __('print_templates.templates.edit_title') : __('print_templates.templates.create_title') }}
                    </h1>
                </div>
                <div class="admin-action-cluster admin-action-cluster--end">
                    <button type="button" class="pill-link" onclick="document.getElementById('print-template-settings')?.showModal()">{{ __('print_templates.templates.form.fields.paper_settings') }}</button>
                    <button type="button" class="pill-link" onclick="document.getElementById('print-template-data-sources')?.showModal()">{{ __('print_templates.templates.form.sections.data_sources') }}</button>
                    @if ($isEditing)
                        @if ($template->is_active && ! $template->is_student_card && ! $template->is_report_card)
                            @can('id-cards.print')
                                <a href="{{ route('print-templates.print.create', ['template' => $template->id]) }}" class="pill-link">{{ __('print_templates.templates.actions.print') }}</a>
                            @endcan
                        @endif
                        @can('id-cards.templates.manage')
                            <form method="POST" action="{{ route('print-templates.templates.copy', $template) }}">@csrf<button type="submit" class="pill-link">{{ __('print_templates.templates.actions.copy') }}</button></form>
                            <form method="POST" action="{{ route('print-templates.templates.destroy', $template) }}" data-admin-confirm-message="{{ __('print_templates.templates.confirm_delete') }}">@csrf @method('DELETE')<button type="submit" class="pill-link pill-link--danger">{{ __('crud.common.actions.delete') }}</button></form>
                        @endcan
                    @endif
                </div>
            </div>
        </section>

        @include('print-templates.templates.partials.form-body')
    </div>
</x-layouts.app>
