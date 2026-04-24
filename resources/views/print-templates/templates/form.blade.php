@php
    $isEditing = $template->exists;
    $backgroundImageUrl = $template->background_image_url;
    $initialLayoutJson = old('layout_json', $layoutJson);
    $initialDataSourcesJson = old('data_sources_json', $dataSourcesJson);
@endphp

<x-layouts.app>
    <div class="page-stack">
        <section class="page-hero p-6 lg:p-8">
            <div class="eyebrow">{{ __('ui.nav.identity_tools') }}</div>
            <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">
                {{ $isEditing ? __('print_templates.templates.edit_title') : __('print_templates.templates.create_title') }}
            </h1>
            <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('print_templates.templates.form_subtitle') }}</p>
        </section>

        @include('print-templates.templates.partials.form-body')
    </div>
</x-layouts.app>
