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
        <div class="print-template-setup-panel__grid">
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

                <dialog id="print-template-settings" class="print-template-settings-dialog">
                    <div class="print-template-settings-dialog__panel">
                        <div class="admin-builder-header mb-5">
                            <h2 class="font-display text-2xl text-white">{{ __('print_templates.templates.form.fields.paper_settings') }}</h2>
                            <button type="button" class="admin-modal__close" onclick="this.closest('dialog').close()" aria-label="{{ __('crud.common.actions.close') }}">&times;</button>
                        </div>
                        <div class="mb-5 flex flex-wrap gap-5">
                            <label class="admin-checkbox"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $template->is_active))><span>{{ __('print_templates.templates.form.fields.is_active') }}</span></label>
                            <label class="admin-checkbox"><input type="checkbox" name="is_student_card" value="1" @checked(old('is_student_card', $template->is_student_card))><span>{{ __('print_templates.templates.form.fields.is_student_card') }}</span></label>
                            <label class="admin-checkbox"><input type="checkbox" name="is_report_card" value="1" @checked(old('is_report_card', $template->is_report_card))><span>{{ __('print_templates.templates.form.fields.is_report_card') }}</span></label>
                        </div>
                        <div class="grid gap-4 md:grid-cols-2">
                <div class="admin-form-field">
                    <label for="print-template-paper-size">{{ __('print_templates.templates.form.fields.paper_size') }}</label>
                    <select id="print-template-paper-size" name="paper_size" required>
                        @foreach ($paperSizes as $key => [$paperWidth, $paperHeight])
                            <option value="{{ $key }}" @selected(old('paper_size', $template->paper_size ?: 'a4') === $key)>{{ __('print_templates.templates.form.paper_sizes.'.$key) }} ({{ $paperWidth }} × {{ $paperHeight }} mm)</option>
                        @endforeach
                    </select>
                </div>

                <div class="admin-form-field">
                    <label for="print-template-orientation">{{ __('print_templates.templates.form.fields.orientation') }}</label>
                    <select id="print-template-orientation" name="orientation" required>
                        <option value="portrait" @selected(old('orientation', $template->orientation ?: 'portrait') === 'portrait')>{{ __('print_templates.templates.form.orientations.portrait') }}</option>
                        <option value="landscape" @selected(old('orientation', $template->orientation) === 'landscape')>{{ __('print_templates.templates.form.orientations.landscape') }}</option>
                    </select>
                </div>

                @foreach (['top', 'right', 'bottom', 'left'] as $side)
                    <div class="admin-form-field print-template-size-field">
                        <label for="print-template-margin-{{ $side }}">{{ __('print_templates.templates.form.fields.margin_'.$side) }}</label>
                        <div class="print-template-number-control">
                            <input id="print-template-margin-{{ $side }}" name="margin_{{ $side }}_mm" type="number" min="0" max="40" step="0.1" value="{{ old('margin_'.$side.'_mm', $template->{'margin_'.$side.'_mm'} ?? 10) }}" required>
                            <span>mm</span>
                        </div>
                    </div>
                @endforeach

                @foreach (['x', 'y'] as $axis)
                    <div class="admin-form-field print-template-size-field">
                        <label for="print-template-gap-{{ $axis }}">{{ __('print_templates.templates.form.fields.gap_'.$axis) }}</label>
                        <div class="print-template-number-control">
                            <input id="print-template-gap-{{ $axis }}" name="gap_{{ $axis }}_mm" type="number" min="0" max="30" step="0.1" value="{{ old('gap_'.$axis.'_mm', $template->{'gap_'.$axis.'_mm'} ?? 6) }}" required>
                            <span>mm</span>
                        </div>
                    </div>
                @endforeach
                <label class="admin-checkbox admin-form-field--full">
                    <input type="checkbox" name="rounded_corners" value="1" @checked(old('rounded_corners', $template->rounded_corners))>
                    <span>{{ __('print_templates.templates.form.fields.rounded_corners') }}</span>
                </label>

                <div class="admin-form-field admin-form-field--full">
                    <label for="print-template-background">{{ __('print_templates.templates.form.fields.background_image') }}</label>
                    <label class="print-template-file-drop" for="print-template-background">
                        <span class="print-template-file-drop__title">{{ __('print_templates.templates.form.fields.background_image') }}</span>
                        <span class="print-template-file-drop__copy" data-print-template-file-name>{{ __('print_templates.templates.form.fields.choose_background') }}</span>
                        <input id="print-template-background" name="background_image" type="file" accept="image/*" data-print-template-background-input>
                    </label>
                    <p class="mt-2 text-xs text-neutral-400">{{ __('print_templates.templates.form.fields.background_max_size') }}</p>
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
                        <div class="mt-5 flex justify-end"><button type="button" class="pill-link pill-link--accent" onclick="this.closest('dialog').close()">{{ __('crud.common.actions.close') }}</button></div>
                    </div>
                </dialog>
            </div>

            <div class="print-template-source-card">
                <div class="mt-3 grid gap-3">
                    <div class="admin-form-field">
                        <label for="print-template-data-source">{{ __('print_templates.templates.form.sections.data_sources') }}</label>
                        <select id="print-template-data-source" data-source-picker>
                            @foreach ($entityOptions as $entity)
                                <option value="{{ $entity['key'] }}">{{ $entity['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <label class="admin-checkbox">
                        <input type="checkbox" data-source-multiple-records>
                        <span>{{ __('print_templates.templates.form.source_modes.multiple') }}</span>
                    </label>
                </div>
            </div>
        </div>

        <textarea name="data_sources_json" class="hidden" data-print-template-data-sources-input>{{ $initialDataSourcesJson }}</textarea>
    </section>

    <section class="surface-panel print-template-studio">
        <div class="print-template-studio__header">
            <div>
                <h2 class="font-display text-2xl text-white">{{ __('print_templates.templates.form.sections.builder') }}</h2>
            </div>
            <div class="flex items-center gap-2">
                <span class="badge-soft" data-print-template-stage-dims></span>
            </div>
        </div>

        <div class="print-template-command-bar">
            <div>
                <div class="print-template-command-bar__title">{{ __('print_templates.templates.form.sections.elements') }}</div>
            </div>
            <div class="print-template-command-bar__actions">
                <button type="button" class="pill-link pill-link--compact" data-print-template-add="custom_text">{{ __('print_templates.templates.form.buttons.add_custom_text') }}</button>
                <button type="button" class="pill-link pill-link--compact" data-print-template-add="dynamic_text">{{ __('print_templates.templates.form.buttons.add_dynamic_text') }}</button>
                <button type="button" class="pill-link pill-link--compact" data-print-template-add="date_text">{{ __('print_templates.templates.form.buttons.add_date') }}</button>
                <button type="button" class="pill-link pill-link--compact" data-print-template-add="page_number">{{ __('print_templates.templates.form.buttons.add_page_number') }}</button>
                <button type="button" class="pill-link pill-link--compact" data-print-template-add="dynamic_image">{{ __('print_templates.templates.form.buttons.add_image') }}</button>
                <button type="button" class="pill-link pill-link--compact" data-print-template-add="static_image">{{ __('print_templates.templates.form.buttons.add_static_image') }}</button>
                <button type="button" class="pill-link pill-link--compact" data-print-template-add="barcode">{{ __('print_templates.templates.form.buttons.add_barcode') }}</button>
                <button type="button" class="pill-link pill-link--compact" data-print-template-add="shape">{{ __('print_templates.templates.form.buttons.add_shape') }}</button>
            </div>
        </div>

        <div class="print-template-studio__workspace">
            <details class="print-template-panel print-template-panel--layers" open data-print-template-layers-panel>
                <summary class="print-template-panel__summary">
                    <div>
                        <div class="admin-section-card__title">{{ __('print_templates.templates.form.sections.layers') }}</div>
                    </div>
                    <span class="print-template-layer-toggle" aria-hidden="true">
                        <span></span>
                        <span></span>
                        <span></span>
                    </span>
                </summary>
                <div class="id-card-layer-list" data-print-template-layer-list>
                    <div class="admin-empty-state">{{ __('print_templates.templates.form.empty_layers') }}</div>
                </div>
            </details>

            <section class="print-template-panel print-template-panel--inspector">
                <div class="print-template-panel__header">
                    <div>
                        <div class="admin-section-card__title">{{ __('print_templates.templates.form.sections.inspector') }}</div>
                    </div>
                </div>
                <div class="admin-form-grid id-card-inspector print-template-studio__inspector" data-print-template-inspector>
                    <div class="admin-empty-state">{{ __('print_templates.templates.form.empty_layers') }}</div>
                </div>
            </section>
        </div>

        <div class="print-template-canvas-card">
            <div class="id-card-builder-preview-shell print-template-canvas-card__shell">
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
        <div class="hidden" data-static-image-inputs></div>
    </section>

    <div class="admin-action-cluster">
        <button type="submit" class="pill-link pill-link--accent">
            {{ $isEditing ? __('print_templates.templates.form.buttons.update') : __('print_templates.templates.form.buttons.save') }}
        </button>
        <a href="{{ route('print-templates.templates.index') }}" class="pill-link">{{ __('crud.common.actions.cancel') }}</a>
    </div>
</form>

@include('print-templates.templates.partials.form-script')
