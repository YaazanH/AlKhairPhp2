@php
    $isEditing = $template->exists;
    $backgroundImageUrl = $template->background_image_url;
    $initialLayoutJson = old('layout_json', $layoutJson);
    $initialDataSourcesJson = old('data_sources_json', $dataSourcesJson);
@endphp

<x-layouts.app>
    <div class="page-stack">
        <section class="page-hero p-6 lg:p-8">
            <x-back-link :href="route('print-templates.templates.index')" />
            <div class="eyebrow mt-4">{{ __('ui.nav.identity_tools') }}</div>
            <div class="print-template-title-row mt-3">
                <div class="min-w-0">
                    <h1 class="font-display text-4xl leading-none text-white md:text-5xl">
                        {{ $isEditing ? __('print_templates.templates.edit_title') : __('print_templates.templates.create_title') }}
                    </h1>
                </div>
                <div class="admin-action-cluster admin-action-cluster--end print-template-header-actions">
                    <button type="submit" form="print-template-editor-form" class="pill-link pill-link--accent print-template-symbol-button" title="{{ $isEditing ? __('print_templates.templates.form.buttons.update') : __('print_templates.templates.form.buttons.save') }}" aria-label="{{ $isEditing ? __('print_templates.templates.form.buttons.update') : __('print_templates.templates.form.buttons.save') }}" data-print-template-save><x-print-template-icon name="save" /></button>
                    @if ($isEditing)
                        @can('id-cards.templates.manage')
                            <form method="POST" action="{{ route('print-templates.templates.copy', $template) }}">@csrf<button type="submit" class="pill-link print-template-symbol-button" title="{{ __('print_templates.templates.actions.copy') }}" aria-label="{{ __('print_templates.templates.actions.copy') }}" data-print-template-symbol-action="copy"><x-print-template-icon name="copy" /></button></form>
                        @endcan
                    @endif
                    <button type="button" class="pill-link print-template-symbol-button" onclick="document.getElementById('print-template-data-sources')?.showModal()" title="{{ __('print_templates.templates.form.sections.data_sources') }}" aria-label="{{ __('print_templates.templates.form.sections.data_sources') }}" data-print-template-symbol-action="data-sources"><x-print-template-icon name="database" /></button>
                    <button type="button" class="pill-link print-template-symbol-button" onclick="document.getElementById('print-template-settings')?.showModal()" title="{{ __('print_templates.templates.form.fields.paper_settings') }}" aria-label="{{ __('print_templates.templates.form.fields.paper_settings') }}" data-print-template-symbol-action="settings"><x-print-template-icon name="settings" /></button>
                    @if ($isEditing)
                        @if ($template->is_active && ! $template->is_student_card && ! $template->is_report_card)
                            @can('id-cards.print')
                                <a href="{{ route('print-templates.print.create', ['template' => $template->id]) }}" class="pill-link print-template-symbol-button" title="{{ __('print_templates.templates.actions.print') }}" aria-label="{{ __('print_templates.templates.actions.print') }}" data-print-template-symbol-action="print"><x-print-template-icon name="print" /></a>
                            @endcan
                        @endif
                    @endif
                </div>
            </div>
        </section>

        @if ($isEditing)
            @can('id-cards.templates.manage')
                <form id="print-template-delete-form" method="POST" action="{{ route('print-templates.templates.destroy', $template) }}" class="hidden" data-admin-confirm-message="{{ __('print_templates.templates.confirm_delete') }}">
                    @csrf
                    @method('DELETE')
                </form>
            @endcan
        @endif

        @include('print-templates.templates.partials.form-body')
    </div>
</x-layouts.app>
