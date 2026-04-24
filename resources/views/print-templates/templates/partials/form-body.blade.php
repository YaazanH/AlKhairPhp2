@if (session('status'))
    <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
@endif

@if ($errors->any())
    <div class="rounded-2xl border border-red-400/20 bg-red-500/10 px-4 py-3 text-sm text-red-100">
        <ul class="space-y-1 list-disc pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form
    method="POST"
    action="{{ $isEditing ? route('print-templates.templates.update', $template) : route('print-templates.templates.store') }}"
    enctype="multipart/form-data"
    class="space-y-6"
>
    @csrf
    @if ($isEditing)
        @method('PUT')
    @endif

    <section class="surface-panel print-template-setup-panel">
        <div class="admin-builder-header">
            <div>
                <div class="eyebrow">{{ __('print_templates.templates.form.sections.details') }}</div>
                <h2 class="font-display mt-3 text-2xl text-white">{{ __('print_templates.templates.form.sections.details') }}</h2>
                <p class="mt-3 max-w-3xl text-sm leading-7 text-neutral-300">{{ __('print_templates.templates.form.data_sources_help') }}</p>
            </div>
            <label class="admin-checkbox">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $template->is_active))>
                <span>{{ __('print_templates.templates.form.fields.is_active') }}</span>
            </label>
        </div>

        <div class="mt-6 print-template-setup-panel__grid">
            <div class="print-template-setup-panel__details">
                <div class="admin-form-field admin-form-field--full">
                    <label for="print-template-name">{{ __('print_templates.templates.form.fields.name') }}</label>
                    <input id="print-template-name" name="name" value="{{ old('name', $template->name) }}" required class="print-template-input">
                </div>

                <div class="admin-form-field print-template-size-field">
                    <label for="print-template-width">{{ __('print_templates.templates.form.fields.width_mm') }}</label>
                    <div class="print-template-number-control">
                        <input id="print-template-width" name="width_mm" type="number" min="20" max="500" step="0.1" value="{{ old('width_mm', $template->width_mm) }}" required data-print-template-width>
                        <span>mm</span>
                    </div>
                </div>

                <div class="admin-form-field print-template-size-field">
                    <label for="print-template-height">{{ __('print_templates.templates.form.fields.height_mm') }}</label>
                    <div class="print-template-number-control">
                        <input id="print-template-height" name="height_mm" type="number" min="20" max="500" step="0.1" value="{{ old('height_mm', $template->height_mm) }}" required data-print-template-height>
                        <span>mm</span>
                    </div>
                </div>

                <div class="admin-form-field admin-form-field--full">
                    <label for="print-template-background">{{ __('print_templates.templates.form.fields.background_image') }}</label>
                    <label class="print-template-file-drop" for="print-template-background">
                        <span class="print-template-file-drop__title">{{ __('print_templates.templates.form.fields.background_image') }}</span>
                        <span class="print-template-file-drop__copy" data-print-template-file-name>{{ __('print_templates.templates.form.fields.choose_background') }}</span>
                        <input id="print-template-background" name="background_image" type="file" accept="image/*" data-print-template-background-input>
                    </label>
                    @if ($backgroundImageUrl)
                        <div class="mt-3 flex flex-wrap items-center gap-4">
                            <img src="{{ $backgroundImageUrl }}" alt="{{ $template->name }}" class="id-card-background-preview">
                            <label class="admin-checkbox">
                                <input type="checkbox" name="remove_background_image" value="1" data-print-template-remove-background>
                                <span>{{ __('print_templates.templates.form.fields.remove_background_image') }}</span>
                            </label>
                        </div>
                    @endif
                </div>
            </div>

            <div class="print-template-source-card">
                <div class="eyebrow">{{ __('print_templates.templates.form.sections.data_sources') }}</div>
                <div class="mt-3 print-template-source-grid">
                    @foreach ($entityOptions as $entity)
                        <div class="print-template-source-pill" data-print-template-source-row="{{ $entity['key'] }}">
                            <label class="admin-checkbox">
                                <input type="checkbox" data-source-enabled="{{ $entity['key'] }}">
                                <span>{{ $entity['label'] }}</span>
                            </label>
                            <select class="rounded-xl px-3 py-2 text-sm" data-source-mode="{{ $entity['key'] }}">
                                <option value="single">{{ __('print_templates.templates.form.source_modes.single') }}</option>
                                <option value="multiple">{{ __('print_templates.templates.form.source_modes.multiple') }}</option>
                            </select>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <textarea name="data_sources_json" class="hidden" data-print-template-data-sources-input>{{ $initialDataSourcesJson }}</textarea>
    </section>

    <section class="surface-panel print-template-studio">
        <div class="print-template-studio__header">
            <div>
                <div class="eyebrow">{{ __('print_templates.templates.form.sections.builder') }}</div>
                <h2 class="font-display mt-3 text-2xl text-white">{{ __('print_templates.templates.form.sections.builder') }}</h2>
                <p class="mt-3 max-w-3xl text-sm leading-7 text-neutral-300">{{ __('print_templates.templates.form.workspace_hint') }}</p>
            </div>
            <span class="badge-soft" data-print-template-stage-dims></span>
        </div>

        <div class="print-template-command-bar">
            <div>
                <div class="eyebrow">{{ __('print_templates.templates.form.sections.elements') }}</div>
                <div class="print-template-command-bar__title">{{ __('print_templates.templates.form.sections.elements') }}</div>
            </div>
            <div class="print-template-command-bar__actions">
                <button type="button" class="pill-link pill-link--compact" data-print-template-add="custom_text">{{ __('print_templates.templates.form.buttons.add_custom_text') }}</button>
                <button type="button" class="pill-link pill-link--compact" data-print-template-add="dynamic_text">{{ __('print_templates.templates.form.buttons.add_dynamic_text') }}</button>
                <button type="button" class="pill-link pill-link--compact" data-print-template-add="dynamic_image">{{ __('print_templates.templates.form.buttons.add_image') }}</button>
                <button type="button" class="pill-link pill-link--compact" data-print-template-add="barcode">{{ __('print_templates.templates.form.buttons.add_barcode') }}</button>
            </div>
        </div>

        <div class="print-template-studio__workspace">
            <aside class="print-template-panel print-template-panel--layers">
                <div class="print-template-panel__header">
                    <div>
                        <div class="eyebrow">{{ __('print_templates.templates.form.sections.layers') }}</div>
                        <div class="admin-section-card__title">{{ __('print_templates.templates.form.sections.layers') }}</div>
                    </div>
                </div>
                <div class="id-card-layer-list" data-print-template-layer-list>
                    <div class="admin-empty-state">{{ __('print_templates.templates.form.empty_layers') }}</div>
                </div>
            </aside>

            <section class="print-template-panel print-template-panel--inspector">
                <div class="print-template-panel__header">
                    <div>
                        <div class="eyebrow">{{ __('print_templates.templates.form.sections.inspector') }}</div>
                        <div class="admin-section-card__title">{{ __('print_templates.templates.form.sections.inspector') }}</div>
                    </div>
                </div>
                <div class="admin-form-grid id-card-inspector print-template-studio__inspector" data-print-template-inspector>
                    <div class="admin-empty-state">{{ __('print_templates.templates.form.empty_layers') }}</div>
                </div>
            </section>
        </div>

        <div class="print-template-canvas-card">
            <div class="print-template-panel__header">
                <div>
                    <div class="eyebrow">{{ __('print_templates.templates.form.sections.live_preview') }}</div>
                    <div class="admin-section-card__title">{{ __('print_templates.templates.form.sections.live_preview') }}</div>
                </div>
            </div>
            <div class="mt-4 id-card-builder-preview-shell print-template-canvas-card__shell">
                <div class="id-card-builder-preview-surface print-template-canvas-card__surface">
                    <div
                        class="id-card-builder-stage"
                        data-print-template-stage
                        data-background-url="{{ $backgroundImageUrl }}"
                    ></div>
                </div>
            </div>
        </div>

        <textarea name="layout_json" class="hidden" data-print-template-layout-input>{{ $initialLayoutJson }}</textarea>
    </section>

    <div class="admin-action-cluster">
        <button type="submit" class="pill-link pill-link--accent">
            {{ $isEditing ? __('print_templates.templates.form.buttons.update') : __('print_templates.templates.form.buttons.save') }}
        </button>
        <a href="{{ route('print-templates.templates.index') }}" class="pill-link">{{ __('crud.common.actions.cancel') }}</a>
    </div>
</form>

@include('print-templates.templates.partials.form-script')
