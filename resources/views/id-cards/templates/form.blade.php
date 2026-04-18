@php
    $isEditing = $template->exists;
    $submitRoute = $isEditing ? route('id-cards.templates.update', $template) : route('id-cards.templates.store');
    $initialLayoutJson = old('layout_json', $layoutJson);
    $currentBackgroundUrl = $template->background_image_url;
@endphp

<x-layouts.app>
    <div class="page-stack">
        <section class="page-hero p-6 lg:p-8">
            <div class="eyebrow">{{ __('ui.nav.identity_tools') }}</div>
            <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">
                {{ $isEditing ? __('id_cards.templates.form.edit_title') : __('id_cards.templates.form.create_title') }}
            </h1>
            <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('id_cards.templates.form.description') }}</p>

            <div class="mt-6 flex flex-wrap gap-3">
                <a href="{{ route('id-cards.templates.index') }}" class="pill-link">{{ __('id_cards.templates.form.buttons.back') }}</a>
                @if ($isEditing && auth()->user()->can('id-cards.print'))
                    <a href="{{ route('id-cards.print.create', ['template' => $template->id]) }}" class="pill-link pill-link--accent">{{ __('id_cards.templates.actions.generate') }}</a>
                @endif
            </div>
        </section>

        @if (session('status'))
            <div class="flash-success px-4 py-3 text-sm">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="rounded-2xl border border-red-400/20 bg-red-500/10 px-4 py-3 text-sm text-red-100">
                <div class="font-semibold">{{ app()->getLocale() === 'ar' ? 'تعذر حفظ القالب. راجع الحقول التالية.' : 'The template could not be saved. Review the fields below.' }}</div>
                <ul class="space-y-1 list-disc pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ $submitRoute }}" enctype="multipart/form-data" class="page-stack">
            @csrf
            @if ($isEditing)
                @method('PUT')
            @endif

            <div class="website-workbench website-workbench--editor">
                <div class="space-y-6">
                    <section class="surface-panel p-5 lg:p-6">
                        <div class="admin-builder-header">
                            <div>
                                <div class="eyebrow">{{ __('id_cards.templates.form.sections.template') }}</div>
                                <h2 class="font-display mt-3 text-2xl text-white">{{ __('id_cards.templates.form.sections.template') }}</h2>
                                <p class="mt-3 text-sm leading-7 text-neutral-300">{{ __('id_cards.templates.form.warnings.clip') }}</p>
                            </div>
                            <span class="badge-soft" data-id-card-stage-dims>{{ number_format((float) old('width_mm', $template->width_mm), 2) }} × {{ number_format((float) old('height_mm', $template->height_mm), 2) }} mm</span>
                        </div>

                        <div class="mt-6 admin-form-grid">
                            <div class="admin-form-field">
                                <label for="id-card-template-name" class="mb-1 block text-sm font-medium">{{ __('id_cards.templates.form.fields.name') }}</label>
                                <input id="id-card-template-name" name="name" type="text" value="{{ old('name', $template->name) }}" class="w-full rounded-xl px-4 py-3 text-sm">
                            </div>

                            <div class="admin-form-field">
                                <label for="id-card-template-sample" class="mb-1 block text-sm font-medium">{{ __('id_cards.templates.form.fields.sample_student') }}</label>
                                <select id="id-card-template-sample" class="w-full rounded-xl px-4 py-3 text-sm" data-id-card-sample>
                                    <option value="">{{ __('id_cards.templates.form.placeholders.select_sample') }}</option>
                                    @foreach ($sampleStudents as $sampleStudent)
                                        <option value="{{ $sampleStudent->id }}" @selected($sampleStudentId === $sampleStudent->id)>
                                            {{ $sampleStudent->full_name }}{{ $sampleStudent->student_number ? ' | '.$sampleStudent->student_number : '' }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="admin-form-field">
                                <label for="id-card-template-width" class="mb-1 block text-sm font-medium">{{ __('id_cards.templates.form.fields.width_mm') }}</label>
                                <input id="id-card-template-width" name="width_mm" type="number" min="35" max="160" step="0.01" value="{{ old('width_mm', $template->width_mm) }}" class="w-full rounded-xl px-4 py-3 text-sm" data-id-card-width>
                            </div>

                            <div class="admin-form-field">
                                <label for="id-card-template-height" class="mb-1 block text-sm font-medium">{{ __('id_cards.templates.form.fields.height_mm') }}</label>
                                <input id="id-card-template-height" name="height_mm" type="number" min="20" max="120" step="0.01" value="{{ old('height_mm', $template->height_mm) }}" class="w-full rounded-xl px-4 py-3 text-sm" data-id-card-height>
                            </div>

                            <div class="admin-form-field">
                                <label for="id-card-template-background" class="mb-1 block text-sm font-medium">{{ __('id_cards.templates.form.fields.background_image') }}</label>
                                <input id="id-card-template-background" name="background_image" type="file" accept="image/*" class="w-full rounded-xl px-4 py-3 text-sm" data-id-card-background-input>
                            </div>

                            <label class="admin-checkbox">
                                <input type="checkbox" name="is_active" value="1" @checked((bool) old('is_active', $template->is_active))>
                                <span>{{ __('id_cards.templates.form.fields.is_active') }}</span>
                            </label>

                            @if ($currentBackgroundUrl)
                                <div class="admin-form-field admin-form-field--full">
                                    <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                                        <div class="text-sm font-semibold text-white">{{ __('id_cards.templates.form.fields.background_image') }}</div>
                                        <div class="mt-3 flex flex-wrap items-center gap-4">
                                            <img src="{{ $currentBackgroundUrl }}" alt="{{ $template->name }}" class="id-card-background-preview">
                                            <label class="admin-checkbox">
                                                <input type="checkbox" name="remove_background_image" value="1" data-id-card-remove-background>
                                                <span>{{ __('id_cards.templates.form.fields.remove_background') }}</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </section>

                    <section class="surface-panel p-5 lg:p-6">
                        <div class="admin-builder-header">
                            <div>
                                <div class="eyebrow">{{ __('id_cards.templates.form.sections.preview') }}</div>
                                <h2 class="font-display mt-3 text-2xl text-white">{{ __('id_cards.templates.form.sections.preview') }}</h2>
                            </div>
                        </div>

                        <div class="mt-6 id-card-builder-preview-shell">
                            <div class="id-card-builder-preview-surface">
                                <div class="id-card-builder-stage" data-id-card-stage data-background-url="{{ $currentBackgroundUrl }}"></div>
                            </div>
                        </div>
                    </section>
                </div>

                <div class="website-sidebar">
                    <section class="surface-panel p-5">
                        <div class="admin-builder-header">
                            <div>
                                <div class="eyebrow">{{ __('id_cards.templates.form.sections.toolbox') }}</div>
                                <div class="admin-section-card__title">{{ __('id_cards.templates.form.sections.toolbox') }}</div>
                            </div>
                        </div>
                        <div class="mt-4 grid gap-3">
                            <button type="button" class="pill-link pill-link--compact" data-id-card-add="text">{{ __('id_cards.templates.form.buttons.add_text') }}</button>
                            <button type="button" class="pill-link pill-link--compact" data-id-card-add="image">{{ __('id_cards.templates.form.buttons.add_image') }}</button>
                            <button type="button" class="pill-link pill-link--compact" data-id-card-add="barcode">{{ __('id_cards.templates.form.buttons.add_barcode') }}</button>
                        </div>
                    </section>

                    <section class="surface-panel p-5">
                        <div class="admin-builder-header">
                            <div>
                                <div class="eyebrow">{{ __('id_cards.templates.form.sections.layers') }}</div>
                                <div class="admin-section-card__title">{{ __('id_cards.templates.form.sections.layers') }}</div>
                            </div>
                        </div>
                        <div class="mt-4 id-card-layer-list" data-id-card-layer-list>
                            <div class="admin-empty-state">{{ __('id_cards.templates.form.empty_layers') }}</div>
                        </div>
                    </section>

                    <section class="surface-panel p-5">
                        <div class="admin-builder-header">
                            <div>
                                <div class="eyebrow">{{ __('id_cards.templates.form.sections.inspector') }}</div>
                                <div class="admin-section-card__title">{{ __('id_cards.templates.form.sections.inspector') }}</div>
                            </div>
                        </div>

                        <div class="mt-4 admin-form-grid id-card-inspector" data-id-card-inspector>
                            <div class="admin-empty-state">{{ __('id_cards.templates.form.empty_layers') }}</div>
                        </div>
                    </section>
                </div>
            </div>

            <textarea name="layout_json" class="hidden" data-id-card-layout-input>{{ $initialLayoutJson }}</textarea>

            <div class="admin-action-cluster">
                <button type="submit" class="pill-link pill-link--accent">
                    {{ $isEditing ? __('id_cards.templates.form.buttons.update') : __('id_cards.templates.form.buttons.save') }}
                </button>
                <a href="{{ route('id-cards.templates.index') }}" class="pill-link">{{ __('crud.common.actions.cancel') }}</a>
            </div>
        </form>
    </div>

    <script type="application/json" id="id-card-field-options-json">@json($fieldOptions)</script>
    <script type="application/json" id="id-card-sample-payloads-json">@json($samplePayloads)</script>
    <script>
        (() => {
            const stage = document.querySelector('[data-id-card-stage]');

            if (!stage) {
                return;
            }

            const fieldOptions = JSON.parse(document.getElementById('id-card-field-options-json').textContent);
            const samplePayloads = JSON.parse(document.getElementById('id-card-sample-payloads-json').textContent);
            const widthInput = document.querySelector('[data-id-card-width]');
            const heightInput = document.querySelector('[data-id-card-height]');
            const layoutInput = document.querySelector('[data-id-card-layout-input]');
            const sampleSelect = document.querySelector('[data-id-card-sample]');
            const layerList = document.querySelector('[data-id-card-layer-list]');
            const inspector = document.querySelector('[data-id-card-inspector]');
            const backgroundInput = document.querySelector('[data-id-card-background-input]');
            const removeBackgroundInput = document.querySelector('[data-id-card-remove-background]');
            const dimsBadge = document.querySelector('[data-id-card-stage-dims]');
            const barcodePreviewUrl = @json(route('id-cards.barcode-preview'));

            const state = {
                layout: [],
                selectedId: null,
                backgroundUrl: stage.dataset.backgroundUrl || '',
                sampleStudentId: sampleSelect?.value || Object.keys(samplePayloads)[0] || '',
                drag: null,
            };

            const typeLabels = {
                text: @json(__('id_cards.templates.form.element_types.text')),
                image: @json(__('id_cards.templates.form.element_types.image')),
                barcode: @json(__('id_cards.templates.form.element_types.barcode')),
            };

            const previewFallbacks = {
                text: @json(__('id_cards.templates.form.preview_fallbacks.text')),
                image: @json(__('id_cards.templates.form.preview_fallbacks.image')),
                barcode: @json(__('id_cards.templates.form.preview_fallbacks.barcode')),
            };

            const fontWeightOptions = @json(__('id_cards.templates.form.font_weights'));
            const textAlignOptions = @json(__('id_cards.templates.form.text_alignments'));
            const objectFitOptions = @json(__('id_cards.templates.form.image_fit'));
            const barcodeFormatOptions = @json(__('id_cards.templates.form.barcode_formats'));
            const inspectorLabels = @json(__('id_cards.templates.form.element'));

            const parseLayout = () => {
                try {
                    return JSON.parse(layoutInput.value || '[]');
                } catch (error) {
                    return [];
                }
            };

            const getScale = () => {
                const width = Math.max(parseFloat(widthInput.value || '85.6'), 35);
                return Math.max(4.2, Math.min(7.5, 700 / width));
            };

            const stageMetrics = () => {
                const width = Math.max(parseFloat(widthInput.value || '85.6'), 35);
                const height = Math.max(parseFloat(heightInput.value || '53.98'), 20);
                const scale = getScale();
                return { width, height, scale };
            };

            const clamp = (value, min, max) => Math.min(Math.max(value, min), max);
            const fieldChoices = (type) => fieldOptions[type] || [];
            const firstField = (type) => fieldChoices(type)[0]?.key || 'full_name';
            const samplePayload = () => samplePayloads[state.sampleStudentId] || {};
            const fitElementToStage = (element) => {
                const { width, height } = stageMetrics();
                element.width = clamp(Number(element.width || 4), 4, width);
                element.height = clamp(Number(element.height || 4), 4, height);
                element.x = clamp(Number(element.x || 0), 0, Math.max(width - element.width, 0));
                element.y = clamp(Number(element.y || 0), 0, Math.max(height - element.height, 0));
            };
            const applyBarcodeFormatDefaults = (element) => {
                if (element.type !== 'barcode') {
                    return;
                }

                if ((element.styling.barcode_format || 'code39') === 'qrcode') {
                    const { width, height } = stageMetrics();
                    const size = Math.min(24, Math.max(18, Math.min(width, height) * 0.45));
                    element.width = Number(size.toFixed(1));
                    element.height = Number((element.styling.show_text ? size + 4 : size).toFixed(1));
                    fitElementToStage(element);
                    return;
                }

                if (element.width <= element.height * 1.4) {
                    element.width = 50;
                    element.height = 14;
                    fitElementToStage(element);
                }
            };

            const syncLayoutInput = () => {
                layoutInput.value = JSON.stringify(state.layout);
            };

            const setBackgroundFromUpload = () => {
                if (removeBackgroundInput?.checked) {
                    stage.style.setProperty('--id-card-builder-background-image', 'none');
                    return;
                }

                if (backgroundInput?.files?.[0]) {
                    stage.style.setProperty('--id-card-builder-background-image', `url('${URL.createObjectURL(backgroundInput.files[0])}')`);
                    return;
                }

                stage.style.setProperty('--id-card-builder-background-image', state.backgroundUrl ? `url('${state.backgroundUrl}')` : 'none');
            };

            const elementLabel = (element) => {
                const fieldLabel = fieldChoices(element.type).find((option) => option.key === element.field)?.label;
                return `${typeLabels[element.type] || element.type} · ${fieldLabel || element.field}`;
            };

            const addElement = (type) => {
                const offset = state.layout.length * 4;
                const element = {
                    id: crypto.randomUUID ? crypto.randomUUID() : `element-${Date.now()}-${Math.random().toString(16).slice(2)}`,
                    type,
                    field: firstField(type),
                    x: 6 + offset,
                    y: 6 + offset,
                    width: type === 'image' ? 20 : (type === 'barcode' ? 50 : 32),
                    height: type === 'image' ? 24 : (type === 'barcode' ? 14 : 8),
                    z_index: state.layout.length + 1,
                    styling: {
                        font_size: type === 'barcode' ? 3 : 4.2,
                        font_weight: type === 'text' ? '600' : '500',
                        color: '#102316',
                        text_align: 'left',
                        border_radius: type === 'image' ? 3 : 0,
                        object_fit: 'cover',
                        show_text: true,
                        barcode_format: 'code39',
                    },
                };

                state.layout.push(element);
                state.selectedId = element.id;
                render();
            };

            const selectedElement = () => state.layout.find((element) => element.id === state.selectedId);

            const renderStage = () => {
                const { width, height, scale } = stageMetrics();
                dimsBadge.textContent = `${width.toFixed(2)} × ${height.toFixed(2)} mm`;
                stage.style.width = `${width * scale}px`;
                stage.style.height = `${height * scale}px`;
                setBackgroundFromUpload();
                stage.innerHTML = '';

                state.layout
                    .slice()
                    .sort((a, b) => a.z_index - b.z_index)
                    .forEach((element) => {
                        const node = document.createElement('button');
                        node.type = 'button';
                        node.className = `id-card-builder-stage__element${state.selectedId === element.id ? ' is-selected' : ''}`;
                        node.style.left = `${element.x * scale}px`;
                        node.style.top = `${element.y * scale}px`;
                        node.style.width = `${element.width * scale}px`;
                        node.style.height = `${element.height * scale}px`;
                        node.style.zIndex = element.z_index;
                        const payload = samplePayload();

                        if (element.type === 'image') {
                            node.classList.add('id-card-builder-stage__element--image');
                            const src = payload[element.field];

                            if (src) {
                                const image = document.createElement('img');
                                image.src = src;
                                image.alt = payload.full_name || 'Student';
                                image.style.objectFit = element.styling.object_fit;
                                node.appendChild(image);
                            } else {
                                node.innerHTML = `<span>${previewFallbacks.image}</span>`;
                            }
                        } else if (element.type === 'barcode') {
                            const barcodeFormat = element.styling.barcode_format || 'code39';
                            const barcodeValue = payload[element.field] || previewFallbacks.barcode;
                            const params = new URLSearchParams({
                                format: barcodeFormat,
                                value: barcodeValue,
                                width: Number(element.width || 20).toFixed(2),
                                height: Number(element.height || 20).toFixed(2),
                                show_text: element.styling.show_text ? '1' : '0',
                            });
                            node.classList.add('id-card-builder-stage__element--barcode');
                            node.style.color = element.styling.color;
                            node.classList.toggle('id-card-builder-stage__element--qr', barcodeFormat === 'qrcode');
                            node.innerHTML = `<img class="id-card-builder-stage__barcode-image" src="${barcodePreviewUrl}?${params.toString()}" alt="${typeLabels.barcode} ${barcodeValue}">`;
                        } else {
                            node.classList.add('id-card-builder-stage__element--text');
                            node.style.color = element.styling.color;
                            node.style.fontSize = `${element.styling.font_size * scale}px`;
                            node.style.fontWeight = element.styling.font_weight;
                            node.style.textAlign = element.styling.text_align;
                            node.textContent = payload[element.field] || previewFallbacks.text;
                        }

                        node.addEventListener('pointerdown', (event) => {
                            if (event.button !== 0) return;
                            state.selectedId = element.id;
                            state.drag = { id: element.id, pointerX: event.clientX, pointerY: event.clientY, originX: element.x, originY: element.y };
                            node.setPointerCapture(event.pointerId);
                            render();
                        });

                        stage.appendChild(node);
                    });
            };

            const renderLayers = () => {
                if (!state.layout.length) {
                    layerList.innerHTML = `<div class="admin-empty-state">${@json(__('id_cards.templates.form.empty_layers'))}</div>`;
                    return;
                }

                layerList.innerHTML = state.layout
                    .slice()
                    .sort((a, b) => b.z_index - a.z_index)
                    .map((element) => `
                        <div class="id-card-layer-card ${state.selectedId === element.id ? 'is-selected' : ''}">
                            <button type="button" class="id-card-layer-card__body" data-layer-select="${element.id}">
                                <div class="id-card-layer-card__title">${elementLabel(element)}</div>
                                <div class="id-card-layer-card__meta">${element.width.toFixed(1)} × ${element.height.toFixed(1)} mm</div>
                            </button>
                            <div class="admin-action-cluster">
                                <button type="button" class="pill-link pill-link--compact" data-layer-move="up" data-layer-id="${element.id}">${@json(__('id_cards.templates.form.buttons.move_up'))}</button>
                                <button type="button" class="pill-link pill-link--compact" data-layer-move="down" data-layer-id="${element.id}">${@json(__('id_cards.templates.form.buttons.move_down'))}</button>
                                <button type="button" class="pill-link pill-link--compact border-red-400/25 text-red-200 hover:border-red-300/35 hover:bg-red-500/12" data-layer-remove="${element.id}">${@json(__('id_cards.templates.form.buttons.remove'))}</button>
                            </div>
                        </div>
                    `)
                    .join('');
            };

            const renderInspector = () => {
                const element = selectedElement();
                if (!element) {
                    inspector.innerHTML = `<div class="admin-empty-state">${@json(__('id_cards.templates.form.empty_layers'))}</div>`;
                    return;
                }

                const fieldSelectOptions = fieldChoices(element.type).map((option) => `<option value="${option.key}" ${option.key === element.field ? 'selected' : ''}>${option.label}</option>`).join('');
                const fontWeightSelectOptions = Object.entries(fontWeightOptions).map(([value, label]) => `<option value="${value}" ${value === element.styling.font_weight ? 'selected' : ''}>${label}</option>`).join('');
                const textAlignSelectOptions = Object.entries(textAlignOptions).map(([value, label]) => `<option value="${value}" ${value === element.styling.text_align ? 'selected' : ''}>${label}</option>`).join('');
                const objectFitSelectOptions = Object.entries(objectFitOptions).map(([value, label]) => `<option value="${value}" ${value === element.styling.object_fit ? 'selected' : ''}>${label}</option>`).join('');
                const barcodeFormat = element.styling.barcode_format || 'code39';
                const barcodeFormatSelectOptions = Object.entries(barcodeFormatOptions).map(([value, label]) => `<option value="${value}" ${value === barcodeFormat ? 'selected' : ''}>${label}</option>`).join('');

                inspector.innerHTML = `
                    <div class="admin-form-field"><label class="mb-1 block text-sm font-medium">${inspectorLabels.type}</label><select class="w-full rounded-xl px-4 py-3 text-sm" data-inspector="type"><option value="text" ${element.type === 'text' ? 'selected' : ''}>${typeLabels.text}</option><option value="image" ${element.type === 'image' ? 'selected' : ''}>${typeLabels.image}</option><option value="barcode" ${element.type === 'barcode' ? 'selected' : ''}>${typeLabels.barcode}</option></select></div>
                    <div class="admin-form-field"><label class="mb-1 block text-sm font-medium">${inspectorLabels.field}</label><select class="w-full rounded-xl px-4 py-3 text-sm" data-inspector="field">${fieldSelectOptions}</select></div>
                    <div class="admin-form-field"><label class="mb-1 block text-sm font-medium">${inspectorLabels.x}</label><input type="number" step="0.1" min="0" class="w-full rounded-xl px-4 py-3 text-sm" value="${element.x}" data-inspector="x"></div>
                    <div class="admin-form-field"><label class="mb-1 block text-sm font-medium">${inspectorLabels.y}</label><input type="number" step="0.1" min="0" class="w-full rounded-xl px-4 py-3 text-sm" value="${element.y}" data-inspector="y"></div>
                    <div class="admin-form-field"><label class="mb-1 block text-sm font-medium">${inspectorLabels.width}</label><input type="number" step="0.1" min="4" class="w-full rounded-xl px-4 py-3 text-sm" value="${element.width}" data-inspector="width"></div>
                    <div class="admin-form-field"><label class="mb-1 block text-sm font-medium">${inspectorLabels.height}</label><input type="number" step="0.1" min="4" class="w-full rounded-xl px-4 py-3 text-sm" value="${element.height}" data-inspector="height"></div>
                    <div class="admin-form-field"><label class="mb-1 block text-sm font-medium">${inspectorLabels.z_index}</label><input type="number" step="1" min="1" class="w-full rounded-xl px-4 py-3 text-sm" value="${element.z_index}" data-inspector="z_index"></div>
                    ${element.type !== 'image' ? `<div class="admin-form-field"><label class="mb-1 block text-sm font-medium">${inspectorLabels.font_size}</label><input type="number" step="0.1" min="2" class="w-full rounded-xl px-4 py-3 text-sm" value="${element.styling.font_size}" data-inspector-style="font_size"></div>` : ''}
                    ${element.type === 'text' ? `<div class="admin-form-field"><label class="mb-1 block text-sm font-medium">${inspectorLabels.font_weight}</label><select class="w-full rounded-xl px-4 py-3 text-sm" data-inspector-style="font_weight">${fontWeightSelectOptions}</select></div><div class="admin-form-field"><label class="mb-1 block text-sm font-medium">${inspectorLabels.text_align}</label><select class="w-full rounded-xl px-4 py-3 text-sm" data-inspector-style="text_align">${textAlignSelectOptions}</select></div>` : ''}
                    <div class="admin-form-field"><label class="mb-1 block text-sm font-medium">${inspectorLabels.color}</label><input type="color" class="w-full rounded-xl px-4 py-3 text-sm" value="${element.styling.color}" data-inspector-style="color"></div>
                    ${element.type === 'image' ? `<div class="admin-form-field"><label class="mb-1 block text-sm font-medium">${inspectorLabels.object_fit}</label><select class="w-full rounded-xl px-4 py-3 text-sm" data-inspector-style="object_fit">${objectFitSelectOptions}</select></div><div class="admin-form-field"><label class="mb-1 block text-sm font-medium">${inspectorLabels.border_radius}</label><input type="number" step="0.1" min="0" class="w-full rounded-xl px-4 py-3 text-sm" value="${element.styling.border_radius}" data-inspector-style="border_radius"></div>` : ''}
                    ${element.type === 'barcode' ? `<div class="admin-form-field admin-form-field--full"><label class="mb-1 block text-sm font-medium">${inspectorLabels.barcode_format}</label><select class="w-full rounded-xl px-4 py-3 text-sm" data-inspector-style="barcode_format">${barcodeFormatSelectOptions}</select></div><label class="admin-checkbox admin-form-field--full"><input type="checkbox" ${element.styling.show_text ? 'checked' : ''} data-inspector-style="show_text"><span>${inspectorLabels.show_text}</span></label>` : ''}
                `;
            };

            const render = () => {
                syncLayoutInput();
                renderStage();
                renderLayers();
                renderInspector();
            };

            const moveLayer = (id, direction) => {
                const ordered = state.layout.slice().sort((a, b) => a.z_index - b.z_index);
                const index = ordered.findIndex((element) => element.id === id);
                const swapIndex = direction === 'up' ? index + 1 : index - 1;
                if (index === -1 || swapIndex < 0 || swapIndex >= ordered.length) return;
                [ordered[index].z_index, ordered[swapIndex].z_index] = [ordered[swapIndex].z_index, ordered[index].z_index];
                render();
            };

            layerList.addEventListener('click', (event) => {
                const selectButton = event.target.closest('[data-layer-select]');
                const removeButton = event.target.closest('[data-layer-remove]');
                const moveButton = event.target.closest('[data-layer-move]');
                if (selectButton) {
                    state.selectedId = selectButton.dataset.layerSelect;
                    render();
                }
                if (removeButton) {
                    state.layout = state.layout.filter((element) => element.id !== removeButton.dataset.layerRemove);
                    if (state.selectedId === removeButton.dataset.layerRemove) state.selectedId = state.layout[0]?.id || null;
                    render();
                }
                if (moveButton) {
                    moveLayer(moveButton.dataset.layerId, moveButton.dataset.layerMove);
                }
            });

            inspector.addEventListener('input', (event) => {
                const element = selectedElement();
                if (!element) return;

                if (event.target.matches('[data-inspector]')) {
                    const key = event.target.dataset.inspector;
                    const value = ['x', 'y', 'width', 'height'].includes(key) ? parseFloat(event.target.value || '0') : (key === 'z_index' ? parseInt(event.target.value || '1', 10) : event.target.value);
                    if (key === 'type') {
                        element.type = value;
                        element.field = firstField(value);
                        if (value === 'barcode') {
                            element.styling.show_text = element.styling.show_text ?? true;
                            element.styling.barcode_format = element.styling.barcode_format || 'code39';
                            applyBarcodeFormatDefaults(element);
                        }
                    } else {
                        element[key] = value;
                        fitElementToStage(element);
                    }
                    render();
                }

                if (event.target.matches('[data-inspector-style]')) {
                    const key = event.target.dataset.inspectorStyle;
                    element.styling[key] = event.target.type === 'checkbox' ? event.target.checked : (['font_size', 'border_radius'].includes(key) ? parseFloat(event.target.value || '0') : event.target.value);
                    if (key === 'barcode_format' || key === 'show_text') {
                        applyBarcodeFormatDefaults(element);
                    }
                    render();
                }
            });

            document.querySelectorAll('[data-id-card-add]').forEach((button) => button.addEventListener('click', () => addElement(button.dataset.idCardAdd)));
            sampleSelect?.addEventListener('change', () => {
                state.sampleStudentId = sampleSelect.value;
                renderStage();
            });
            backgroundInput?.addEventListener('change', setBackgroundFromUpload);
            removeBackgroundInput?.addEventListener('change', setBackgroundFromUpload);
            widthInput?.addEventListener('input', renderStage);
            heightInput?.addEventListener('input', renderStage);

            stage.addEventListener('pointermove', (event) => {
                if (!state.drag) return;
                const dragged = state.layout.find((element) => element.id === state.drag.id);
                if (!dragged) return;
                const { width, height, scale } = stageMetrics();
                const deltaX = (event.clientX - state.drag.pointerX) / scale;
                const deltaY = (event.clientY - state.drag.pointerY) / scale;
                dragged.x = clamp(Number((state.drag.originX + deltaX).toFixed(2)), 0, Math.max(width - dragged.width, 0));
                dragged.y = clamp(Number((state.drag.originY + deltaY).toFixed(2)), 0, Math.max(height - dragged.height, 0));
                syncLayoutInput();
                renderStage();
                renderInspector();
            });

            const stopDrag = () => state.drag = null;
            stage.addEventListener('pointerup', stopDrag);
            stage.addEventListener('pointercancel', stopDrag);
            window.addEventListener('pointerup', stopDrag);

            state.layout = parseLayout();
            state.layout.forEach((element) => {
                if (element.type === 'barcode' && (element.styling?.barcode_format || 'code39') === 'qrcode' && (element.width > element.height * 1.4 || element.height < 18)) {
                    applyBarcodeFormatDefaults(element);
                }
            });
            state.selectedId = state.layout[0]?.id || null;
            render();
        })();
    </script>
</x-layouts.app>
