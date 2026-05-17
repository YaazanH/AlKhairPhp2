<script type="application/json" id="print-template-entities-json">@json($entityOptions)</script>
<script type="application/json" id="print-template-field-options-json">@json($fieldOptions)</script>
<script type="application/json" id="print-template-sample-payloads-json">@json($samplePayloads)</script>

<script>
    (() => {
        const stage = document.querySelector('[data-print-template-stage]');
        if (!stage) return;

        const entities = JSON.parse(document.getElementById('print-template-entities-json').textContent);
        const fieldOptions = JSON.parse(document.getElementById('print-template-field-options-json').textContent);
        const samples = JSON.parse(document.getElementById('print-template-sample-payloads-json').textContent);
        const widthInput = document.querySelector('[data-print-template-width]');
        const heightInput = document.querySelector('[data-print-template-height]');
        const layoutInput = document.querySelector('[data-print-template-layout-input]');
        const sourcesInput = document.querySelector('[data-print-template-data-sources-input]');
        const layerList = document.querySelector('[data-print-template-layer-list]');
        const inspector = document.querySelector('[data-print-template-inspector]');
        const layersPanel = document.querySelector('[data-print-template-layers-panel]');
        const backgroundInput = document.querySelector('[data-print-template-background-input]');
        const removeBackgroundInput = document.querySelector('[data-print-template-remove-background]');
        const backgroundFileName = document.querySelector('[data-print-template-file-name]');
        const dimsBadge = document.querySelector('[data-print-template-stage-dims]');
        const barcodePreviewUrl = @json(route('id-cards.barcode-preview'));

        const labels = {
            types: @json(__('print_templates.templates.form.element_types')),
            element: @json(__('print_templates.templates.form.element')),
            buttons: @json(__('print_templates.templates.form.buttons')),
            empty: @json(__('print_templates.templates.form.empty_layers')),
            noField: @json(__('print_templates.templates.form.no_field_available')),
            barcodeFormats: @json(__('print_templates.templates.form.barcode_formats')),
            fontWeights: @json(__('print_templates.templates.form.font_weights')),
            textAlignments: @json(__('print_templates.templates.form.text_alignments')),
            dateModes: @json(__('print_templates.templates.form.date_modes')),
            shapeTypes: @json(__('print_templates.templates.form.shape_types')),
            imageFit: @json(__('print_templates.templates.form.image_fit')),
            preview: @json(__('print_templates.templates.form.preview_fallbacks')),
            placeholderPicker: @json(__('print_templates.templates.form.element.placeholder_picker')),
        };

        const state = {
            sources: parseArray(sourcesInput.value),
            elements: parseArray(layoutInput.value),
            selectedId: null,
            backgroundUrl: stage.dataset.backgroundUrl || '',
            drag: null,
        };

        function parseArray(value) {
            try {
                const parsed = JSON.parse(value || '[]');
                return Array.isArray(parsed) ? parsed : [];
            } catch (_error) {
                return [];
            }
        }

        function h(value) {
            return String(value ?? '').replace(/[&<>"']/g, (char) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
            }[char]));
        }

        function id() {
            return window.crypto?.randomUUID ? crypto.randomUUID() : `element-${Date.now()}-${Math.random().toString(16).slice(2)}`;
        }

        function isFieldElement(type) {
            return ['dynamic_text', 'dynamic_image', 'barcode'].includes(type);
        }

        function isTextElement(type) {
            return ['custom_text', 'dynamic_text', 'date_text', 'page_number'].includes(type);
        }

        function widthMm() {
            return Math.max(parseFloat(widthInput.value || '85.6'), 20);
        }

        function heightMm() {
            return Math.max(parseFloat(heightInput.value || '53.98'), 20);
        }

        function previewWidth() {
            const shell = stage.closest('.print-template-canvas-card__shell');

            return shell ? Math.max(shell.clientWidth - 56, 320) : 700;
        }

        function previewHeight() {
            const shell = stage.closest('.print-template-canvas-card__shell');

            return shell ? Math.max(shell.clientHeight - 56, 240) : 520;
        }

        function scale() {
            return Math.max(1.2, Math.min(9, previewWidth() / widthMm(), previewHeight() / heightMm()));
        }

        function metrics() {
            return { width: widthMm(), height: heightMm(), scale: scale() };
        }

        function clamp(value, min, max) {
            return Math.min(Math.max(value, min), max);
        }

        function sourceLabel(entity) {
            return entities.find((item) => item.key === entity)?.label || entity;
        }

        function enabledSources() {
            return [...new Set(state.sources.map((source) => source.entity || source.key).filter(Boolean))];
        }

        function checkedSources() {
            const checked = Array.from(document.querySelectorAll('[data-source-enabled]:checked'))
                .map((checkbox) => checkbox.dataset.sourceEnabled)
                .filter(Boolean);

            return checked.length ? [...new Set(checked)] : enabledSources();
        }

        function groupsFor(type) {
            if (!isFieldElement(type)) {
                return [];
            }

            const enabled = enabledSources();
            return (fieldOptions[type] || []).filter((group) => enabled.includes(group.entity));
        }

        function fieldsFor(type, source) {
            return (fieldOptions[type] || []).find((group) => group.entity === source)?.fields || [];
        }

        function defaultSelection(type, preferredSource = null) {
            if (!isFieldElement(type)) {
                return { source: null, field: null };
            }

            const groups = groupsFor(type);
            const group = preferredSource
                ? groups.find((item) => item.entity === preferredSource) || groups[0]
                : groups[0];
            const field = group?.fields?.[0] || null;

            return field ? { source: group.entity, field: field.key } : { source: null, field: null };
        }

        function sample(source, field) {
            return samples?.[source]?.[field] || labels.preview.text;
        }

        function replaceToken(template, key, value) {
            return String(template || '').replace(new RegExp(`\\{\\{\\s*${key}\\s*\\}\\}`, 'ig'), value);
        }

        function customPreview(content) {
            return String(content || '').replace(/\{\{\s*([a-z_]+)\.([a-z_]+)\s*\}\}/ig, (_match, source, field) => sample(source, field));
        }

        function previewDateValue(element) {
            return element.styling?.date_mode === 'custom' && element.styling?.custom_date
                ? element.styling.custom_date
                : labels.preview.date_text;
        }

        function previewTextValue(element) {
            if (element.type === 'custom_text') {
                return customPreview(element.content || labels.preview.custom_text);
            }

            if (element.type === 'date_text') {
                return replaceToken(element.content || labels.preview.date_text, 'date', previewDateValue(element));
            }

            if (element.type === 'page_number') {
                return replaceToken(element.content || labels.preview.page_number, 'page_number', '1');
            }

            return sample(element.source, element.field);
        }

        function isRtlText(value, align = 'left') {
            return /[\u0590-\u08FF]/.test(String(value || '')) || align === 'right';
        }

        function syncSourcesToControls() {
            document.querySelectorAll('[data-source-enabled]').forEach((checkbox) => {
                checkbox.checked = state.sources.some((source) => source.entity === checkbox.dataset.sourceEnabled);
            });

            document.querySelectorAll('[data-source-mode]').forEach((select) => {
                select.value = state.sources.find((source) => source.entity === select.dataset.sourceMode)?.mode || 'single';
            });
        }

        function syncControlsToSources() {
            state.sources = entities.map((entity) => {
                if (!document.querySelector(`[data-source-enabled="${entity.key}"]`)?.checked) return null;

                return {
                    key: entity.key,
                    entity: entity.key,
                    mode: document.querySelector(`[data-source-mode="${entity.key}"]`)?.value || 'single',
                };
            }).filter(Boolean);

            let repeatingSeen = false;
            state.sources = state.sources.map((source) => {
                if (source.mode !== 'multiple') return source;
                if (repeatingSeen) return { ...source, mode: 'single' };
                repeatingSeen = true;
                return source;
            });

            ensureElementBindings();
            syncSourcesToControls();
        }

        function ensureElementBinding(element) {
            if (!isFieldElement(element.type)) {
                element.source = null;
                element.field = null;
                return;
            }

            const currentFields = fieldsFor(element.type, element.source);
            if (currentFields.some((field) => field.key === element.field)) {
                return;
            }

            const selected = defaultSelection(element.type, element.source);
            element.source = selected.source;
            element.field = selected.field;
        }

        function ensureElementBindings() {
            state.elements.forEach(ensureElementBinding);
        }

        function fitElementToStage(element) {
            const { width, height } = metrics();
            element.width = clamp(Number(element.width || 4), 4, width);
            element.height = clamp(Number(element.height || 4), 4, height);
            element.x = clamp(Number(element.x || 0), 0, Math.max(width - element.width, 0));
            element.y = clamp(Number(element.y || 0), 0, Math.max(height - element.height, 0));
        }

        function applyBarcodeFormatDefaults(element) {
            if (element.type !== 'barcode') return;

            const barcodeFormat = element.styling?.barcode_format || 'code39';
            if (barcodeFormat === 'qrcode') {
                const { width, height } = metrics();
                const size = Math.min(28, Math.max(18, Math.min(width, height) * 0.45));
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
        }

        function syncHidden() {
            sourcesInput.value = JSON.stringify(state.sources);
            layoutInput.value = JSON.stringify(state.elements);
        }

        function setBackground() {
            if (removeBackgroundInput?.checked) {
                stage.style.setProperty('--id-card-builder-background-image', 'none');
                return;
            }

            if (backgroundInput?.files?.[0]) {
                stage.style.setProperty('--id-card-builder-background-image', `url('${URL.createObjectURL(backgroundInput.files[0])}')`);
                return;
            }

            stage.style.setProperty('--id-card-builder-background-image', state.backgroundUrl ? `url('${state.backgroundUrl}')` : 'none');
        }

        function shapeClip(shapeType) {
            return shapeType === 'triangle' ? 'polygon(50% 0, 0 100%, 100% 100%)' : '';
        }

        function renderStage() {
            const { width, height, scale } = metrics();
            dimsBadge.textContent = `${width.toFixed(1)} × ${height.toFixed(1)} mm`;
            stage.style.width = `${width * scale}px`;
            stage.style.height = `${height * scale}px`;
            setBackground();
            stage.innerHTML = '';

            state.elements
                .slice()
                .sort((a, b) => (a.z_index || 0) - (b.z_index || 0))
                .forEach((element) => {
                    ensureElementBinding(element);

                    const node = document.createElement('button');
                    node.type = 'button';
                    node.className = `id-card-builder-stage__element${state.selectedId === element.id ? ' is-selected' : ''}`;
                    node.style.left = `${Number(element.x || 0) * scale}px`;
                    node.style.top = `${Number(element.y || 0) * scale}px`;
                    node.style.width = `${Number(element.width || 4) * scale}px`;
                    node.style.height = `${Number(element.height || 4) * scale}px`;
                    node.style.zIndex = element.z_index || 1;
                    node.style.background = 'rgba(255,255,255,0.18)';
                    node.style.borderRadius = '';
                    node.style.clipPath = '';
                    node.dataset.elementId = element.id;

                    if (element.type === 'dynamic_image') {
                        const src = sample(element.source, element.field);
                        node.classList.add('id-card-builder-stage__element--image');
                        node.style.borderRadius = `${Number(element.styling?.border_radius || 0) * scale}px`;

                        if (src && (String(src).startsWith('/') || String(src).startsWith('http'))) {
                            const image = document.createElement('img');
                            image.src = src;
                            image.alt = elementLabel(element);
                            image.style.objectFit = element.styling?.object_fit || 'cover';
                            node.appendChild(image);
                        } else {
                            node.innerHTML = `<span>${h(labels.preview.image)}</span>`;
                        }
                    } else if (element.type === 'barcode') {
                        const barcodeFormat = element.styling?.barcode_format || 'code39';
                        const params = new URLSearchParams({
                            format: barcodeFormat,
                            value: sample(element.source, element.field),
                            width: Number(element.width || 20).toFixed(2),
                            height: Number(element.height || 20).toFixed(2),
                            show_text: element.styling?.show_text ? '1' : '0',
                        });

                        node.classList.add('id-card-builder-stage__element--barcode');
                        node.classList.toggle('id-card-builder-stage__element--qr', barcodeFormat === 'qrcode');
                        node.style.color = element.styling?.color || '#102316';
                        node.innerHTML = `<img class="id-card-builder-stage__barcode-image" src="${barcodePreviewUrl}?${params.toString()}" alt="">`;
                    } else if (element.type === 'shape') {
                        node.style.borderStyle = 'solid';
                        node.style.borderColor = 'rgba(15, 36, 20, 0.22)';
                        node.style.background = element.styling?.color || '#102316';
                        node.style.opacity = element.styling?.fill_opacity ?? 0.18;

                        if ((element.styling?.shape_type || 'rectangle') === 'circle') {
                            node.style.borderRadius = '9999px';
                        }

                        if ((element.styling?.shape_type || 'rectangle') === 'triangle') {
                            node.style.clipPath = shapeClip('triangle');
                        }
                    } else {
                        const align = element.styling?.text_align || 'left';
                        const previewValue = previewTextValue(element);
                        node.classList.add('id-card-builder-stage__element--text');
                        node.style.color = element.styling?.color || '#102316';
                        node.style.fontSize = `${Number(element.styling?.font_size || 4.2) * scale}px`;
                        node.style.fontWeight = element.styling?.font_weight || '600';
                        node.style.textAlign = align;
                        node.style.justifyContent = align === 'center' ? 'center' : (align === 'right' ? 'flex-end' : 'flex-start');
                        node.style.alignItems = 'flex-start';
                        node.style.whiteSpace = 'pre-wrap';
                        node.style.lineHeight = element.styling?.line_height || 1.2;
                        node.style.letterSpacing = `${Number(element.styling?.letter_spacing || 0) * scale}px`;
                        node.style.direction = isRtlText(previewValue, align) ? 'rtl' : 'ltr';
                        node.style.unicodeBidi = 'plaintext';
                        node.textContent = previewValue;
                    }

                    node.addEventListener('pointerdown', (event) => {
                        if (event.button !== 0) return;
                        event.preventDefault();
                        state.selectedId = element.id;
                        state.drag = {
                            id: element.id,
                            pointerX: event.clientX,
                            pointerY: event.clientY,
                            originX: Number(element.x || 0),
                            originY: Number(element.y || 0),
                        };
                        renderAll();
                    });

                    stage.appendChild(node);
                });
        }

        function fieldLabel(type, source, field) {
            return fieldsFor(type, source).find((item) => item.key === field)?.label || field;
        }

        function elementLabel(element) {
            if (['custom_text', 'date_text', 'page_number'].includes(element.type)) {
                const content = String(previewTextValue(element) || labels.types[element.type]).trim();
                return `${labels.types[element.type]}: ${content.slice(0, 28)}`;
            }

            if (element.type === 'shape') {
                return `${labels.types.shape}: ${labels.shapeTypes[element.styling?.shape_type || 'rectangle']}`;
            }

            return `${labels.types[element.type]}: ${sourceLabel(element.source)} / ${fieldLabel(element.type, element.source, element.field)}`;
        }

        function renderLayers() {
            if (!state.elements.length) {
                layerList.innerHTML = `<div class="admin-empty-state">${labels.empty}</div>`;
                return;
            }

            layerList.innerHTML = state.elements
                .slice()
                .sort((a, b) => (b.z_index || 0) - (a.z_index || 0))
                .map((element) => `
                    <div class="id-card-layer-card ${state.selectedId === element.id ? 'is-selected' : ''}">
                        <button type="button" class="id-card-layer-card__body" data-layer-select="${h(element.id)}">
                            <div class="id-card-layer-card__title">${h(elementLabel(element))}</div>
                            <div class="id-card-layer-card__meta">${Number(element.width || 0).toFixed(1)} × ${Number(element.height || 0).toFixed(1)} mm</div>
                        </button>
                        <div class="admin-action-cluster admin-action-cluster--end p-2">
                            <button type="button" class="pill-link pill-link--compact" data-layer-move="up" data-layer-id="${h(element.id)}">${h(labels.buttons.move_up)}</button>
                            <button type="button" class="pill-link pill-link--compact" data-layer-move="down" data-layer-id="${h(element.id)}">${h(labels.buttons.move_down)}</button>
                            <button type="button" class="pill-link pill-link--compact pill-link--danger" data-layer-remove="${h(element.id)}">${h(labels.buttons.remove)}</button>
                        </div>
                    </div>
                `)
                .join('');
        }

        function optionList(options, selectedValue) {
            return options.map(([value, label]) => `<option value="${h(value)}" ${String(value) === String(selectedValue) ? 'selected' : ''}>${h(label)}</option>`).join('');
        }

        function sourceSelect(element) {
            const groups = groupsFor(element.type);
            if (!groups.length) {
                return `<option value="">${h(labels.noField)}</option>`;
            }

            return groups.map((group) => `<option value="${h(group.entity)}" ${group.entity === element.source ? 'selected' : ''}>${h(group.entity_label)}</option>`).join('');
        }

        function fieldSelect(element) {
            const fields = fieldsFor(element.type, element.source);
            if (!fields.length) {
                return `<option value="">${h(labels.noField)}</option>`;
            }

            return fields.map((field) => `<option value="${h(field.key)}" ${field.key === element.field ? 'selected' : ''}>${h(field.label)}</option>`).join('');
        }

        function placeholderButtons() {
            const enabled = [...new Set([...checkedSources(), ...enabledSources()])];
            const groups = (fieldOptions.dynamic_text || [])
                .filter((group) => enabled.includes(group.entity))
                .sort((a, b) => enabled.indexOf(a.entity) - enabled.indexOf(b.entity));

            if (!groups.length) {
                return '';
            }

            return `
                <div class="print-template-placeholder-palette admin-form-field--full">
                    <div class="mb-2 text-xs font-semibold uppercase tracking-[0.18em] text-neutral-400">${h(labels.placeholderPicker)}</div>
                    <div class="print-template-placeholder-palette__groups">
                        ${groups.map((group) => `
                            <div class="print-template-placeholder-group">
                                <div class="print-template-placeholder-group__title">${h(group.entity_label)}</div>
                                <div class="flex flex-wrap gap-2">
                                    ${group.fields.map((field) => `<button type="button" class="pill-link pill-link--compact" data-placeholder-token="${h(group.entity)}.${h(field.key)}">${h(field.label)}</button>`).join('')}
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            `;
        }

        function renderInspector() {
            const element = selectedElement();
            if (!element) {
                inspector.innerHTML = `<div class="admin-empty-state">${labels.empty}</div>`;
                return;
            }

            ensureElementBinding(element);

            const typeOptions = Object.entries(labels.types).map(([value, label]) => `<option value="${h(value)}" ${element.type === value ? 'selected' : ''}>${h(label)}</option>`).join('');
            const fontWeights = optionList(Object.entries(labels.fontWeights), element.styling?.font_weight || '600');
            const textAlignments = optionList(Object.entries(labels.textAlignments), element.styling?.text_align || 'left');
            const imageFit = optionList(Object.entries(labels.imageFit), element.styling?.object_fit || 'cover');
            const barcodeFormats = optionList(Object.entries(labels.barcodeFormats), element.styling?.barcode_format || 'code39');
            const dateModes = optionList(Object.entries(labels.dateModes), element.styling?.date_mode || 'today');
            const shapeTypes = optionList(Object.entries(labels.shapeTypes), element.styling?.shape_type || 'rectangle');
            const usesContent = ['custom_text', 'date_text', 'page_number'].includes(element.type);

            inspector.innerHTML = `
                <div class="admin-form-field"><label>${h(labels.element.type)}</label><select class="w-full rounded-xl px-4 py-3 text-sm" data-i="type">${typeOptions}</select></div>
                ${usesContent ? `<div class="admin-form-field admin-form-field--full"><label>${h(labels.element.content)}</label><textarea rows="4" class="w-full rounded-xl px-4 py-3 text-sm" data-i="content">${h(element.content || '')}</textarea>${element.type === 'custom_text' ? `<p class="mt-1 text-xs text-neutral-400">${h(labels.element.placeholder_help)}</p>` : ''}</div>${element.type === 'custom_text' ? placeholderButtons() : ''}` : ''}
                ${isFieldElement(element.type) ? `<div class="admin-form-field"><label>${h(labels.element.source)}</label><select class="w-full rounded-xl px-4 py-3 text-sm" data-i="source">${sourceSelect(element)}</select></div><div class="admin-form-field"><label>${h(labels.element.field)}</label><select class="w-full rounded-xl px-4 py-3 text-sm" data-i="field">${fieldSelect(element)}</select></div>` : ''}
                <div class="admin-form-field"><label>${h(labels.element.x)}</label><input class="w-full rounded-xl px-4 py-3 text-sm" type="number" step="0.1" min="0" value="${h(element.x)}" data-n="x"></div>
                <div class="admin-form-field"><label>${h(labels.element.y)}</label><input class="w-full rounded-xl px-4 py-3 text-sm" type="number" step="0.1" min="0" value="${h(element.y)}" data-n="y"></div>
                <div class="admin-form-field"><label>${h(labels.element.width)}</label><input class="w-full rounded-xl px-4 py-3 text-sm" type="number" step="0.1" min="4" value="${h(element.width)}" data-n="width"></div>
                <div class="admin-form-field"><label>${h(labels.element.height)}</label><input class="w-full rounded-xl px-4 py-3 text-sm" type="number" step="0.1" min="4" value="${h(element.height)}" data-n="height"></div>
                <div class="admin-form-field"><label>${h(labels.element.z_index)}</label><input class="w-full rounded-xl px-4 py-3 text-sm" type="number" step="1" min="1" value="${h(element.z_index)}" data-n="z_index"></div>
                ${isTextElement(element.type) || element.type === 'barcode' ? `<div class="admin-form-field"><label>${h(labels.element.font_size)}</label><input class="w-full rounded-xl px-4 py-3 text-sm" type="number" step="0.1" min="1.5" value="${h(element.styling?.font_size || 4.2)}" data-s-n="font_size"></div>` : ''}
                ${isTextElement(element.type) ? `<div class="admin-form-field"><label>${h(labels.element.font_weight)}</label><select class="w-full rounded-xl px-4 py-3 text-sm" data-s="font_weight">${fontWeights}</select></div><div class="admin-form-field"><label>${h(labels.element.text_align)}</label><select class="w-full rounded-xl px-4 py-3 text-sm" data-s="text_align">${textAlignments}</select></div>` : ''}
                ${element.type !== 'dynamic_image' ? `<div class="admin-form-field"><label>${h(labels.element.color)}</label><input class="w-full rounded-xl px-4 py-3 text-sm" type="color" value="${h(element.styling?.color || '#102316')}" data-s="color"></div>` : ''}
                ${isTextElement(element.type) ? `<div class="admin-form-field"><label>${h(labels.element.line_height)}</label><input class="w-full rounded-xl px-4 py-3 text-sm" type="number" step="0.1" min="0.8" max="2.5" value="${h(element.styling?.line_height || 1.2)}" data-s-n="line_height"></div><div class="admin-form-field"><label>${h(labels.element.letter_spacing)}</label><input class="w-full rounded-xl px-4 py-3 text-sm" type="number" step="0.1" min="0" max="3" value="${h(element.styling?.letter_spacing || 0)}" data-s-n="letter_spacing"></div>` : ''}
                ${element.type === 'dynamic_image' ? `<div class="admin-form-field"><label>${h(labels.element.object_fit)}</label><select class="w-full rounded-xl px-4 py-3 text-sm" data-s="object_fit">${imageFit}</select></div><div class="admin-form-field"><label>${h(labels.element.border_radius)}</label><input class="w-full rounded-xl px-4 py-3 text-sm" type="number" step="0.1" min="0" value="${h(element.styling?.border_radius || 0)}" data-s-n="border_radius"></div>` : ''}
                ${element.type === 'barcode' ? `<div class="admin-form-field"><label>${h(labels.element.barcode_format)}</label><select class="w-full rounded-xl px-4 py-3 text-sm" data-s="barcode_format">${barcodeFormats}</select></div><label class="admin-checkbox admin-form-field--full"><input type="checkbox" data-s-c="show_text" ${element.styling?.show_text ? 'checked' : ''}><span>${h(labels.element.show_text)}</span></label>` : ''}
                ${element.type === 'date_text' ? `<div class="admin-form-field"><label>${h(labels.element.date_mode)}</label><select class="w-full rounded-xl px-4 py-3 text-sm" data-s="date_mode">${dateModes}</select></div>${(element.styling?.date_mode || 'today') === 'custom' ? `<div class="admin-form-field"><label>${h(labels.element.custom_date)}</label><input class="w-full rounded-xl px-4 py-3 text-sm" type="date" value="${h(element.styling?.custom_date || '')}" data-s="custom_date"></div>` : ''}` : ''}
                ${element.type === 'shape' ? `<div class="admin-form-field"><label>${h(labels.element.shape_type)}</label><select class="w-full rounded-xl px-4 py-3 text-sm" data-s="shape_type">${shapeTypes}</select></div><div class="admin-form-field"><label>${h(labels.element.fill_opacity)}</label><input class="w-full rounded-xl px-4 py-3 text-sm" type="number" step="0.05" min="0" max="1" value="${h(element.styling?.fill_opacity ?? 0.18)}" data-s-n="fill_opacity"></div>` : ''}
            `;
        }

        function selectedElement() {
            return state.elements.find((item) => item.id === state.selectedId);
        }

        function renderAll() {
            syncHidden();
            renderStage();
            renderLayers();
            renderInspector();
        }

        function defaultContent(type) {
            if (type === 'date_text') return '{{ date }}';
            if (type === 'page_number') return '{{ page_number }}';
            return labels.preview.custom_text;
        }

        function addElement(type) {
            const selected = defaultSelection(type);
            const offset = state.elements.length * 4;
            const isShape = type === 'shape';
            const element = {
                id: id(),
                type,
                source: isFieldElement(type) ? selected.source : null,
                field: isFieldElement(type) ? selected.field : null,
                content: ['custom_text', 'date_text', 'page_number'].includes(type) ? defaultContent(type) : '',
                x: 6 + offset,
                y: 6 + offset,
                width: type === 'dynamic_image' ? 22 : (type === 'barcode' ? 50 : (isShape ? 18 : 50)),
                height: type === 'dynamic_image' ? 28 : (type === 'barcode' ? 14 : (isShape ? 18 : 10)),
                z_index: state.elements.length + 1,
                styling: {
                    font_size: type === 'barcode' ? 2.8 : 4.2,
                    font_weight: '600',
                    color: '#102316',
                    text_align: 'left',
                    border_radius: type === 'dynamic_image' ? 3 : 0,
                    object_fit: 'cover',
                    show_text: true,
                    barcode_format: 'code39',
                    line_height: 1.2,
                    letter_spacing: 0,
                    date_mode: 'today',
                    custom_date: '',
                    shape_type: 'rectangle',
                    fill_opacity: 0.18,
                },
            };

            if (type === 'barcode') applyBarcodeFormatDefaults(element);
            fitElementToStage(element);
            state.elements.push(element);
            state.selectedId = element.id;
            renderAll();
        }

        function moveLayer(id, direction) {
            const ordered = state.elements.slice().sort((a, b) => (a.z_index || 0) - (b.z_index || 0));
            const index = ordered.findIndex((element) => element.id === id);
            const swapIndex = direction === 'up' ? index + 1 : index - 1;
            if (index === -1 || swapIndex < 0 || swapIndex >= ordered.length) return;

            [ordered[index].z_index, ordered[swapIndex].z_index] = [ordered[swapIndex].z_index, ordered[index].z_index];
            renderAll();
        }

        document.querySelectorAll('[data-print-template-add]').forEach((button) => button.addEventListener('click', () => addElement(button.dataset.printTemplateAdd)));

        document.querySelectorAll('[data-source-enabled], [data-source-mode]').forEach((control) => {
            control.addEventListener('change', () => {
                syncControlsToSources();
                renderAll();
            });
        });

        [widthInput, heightInput].forEach((input) => input.addEventListener('input', () => {
            state.elements.forEach(fitElementToStage);
            syncHidden();
            renderStage();
            renderLayers();
        }));

        backgroundInput?.addEventListener('change', () => {
            state.backgroundUrl = backgroundInput.files?.[0] ? URL.createObjectURL(backgroundInput.files[0]) : stage.dataset.backgroundUrl;
            if (backgroundFileName) {
                backgroundFileName.textContent = backgroundInput.files?.[0]?.name || @json(__('print_templates.templates.form.fields.choose_background'));
            }
            renderStage();
        });

        removeBackgroundInput?.addEventListener('change', () => {
            state.backgroundUrl = removeBackgroundInput.checked ? '' : stage.dataset.backgroundUrl;
            renderStage();
        });

        layerList.addEventListener('click', (event) => {
            const select = event.target.closest('[data-layer-select]');
            const remove = event.target.closest('[data-layer-remove]');
            const move = event.target.closest('[data-layer-move]');

            if (select) {
                state.selectedId = select.dataset.layerSelect;
            }

            if (remove) {
                state.elements = state.elements.filter((element) => element.id !== remove.dataset.layerRemove);
                if (state.selectedId === remove.dataset.layerRemove) {
                    state.selectedId = state.elements[0]?.id || null;
                }
            }

            if (move) {
                moveLayer(move.dataset.layerId, move.dataset.layerMove);
                return;
            }

            renderAll();
        });

        inspector.addEventListener('input', updateElement);
        inspector.addEventListener('change', updateElement);
        inspector.addEventListener('click', (event) => {
            const button = event.target.closest('[data-placeholder-token]');
            if (!button) return;

            const element = selectedElement();
            const textarea = inspector.querySelector('[data-i="content"]');
            if (!element || !textarea) return;

            const token = `${String.fromCharCode(123, 123)} ${button.dataset.placeholderToken} ${String.fromCharCode(125, 125)}`;
            const start = textarea.selectionStart ?? textarea.value.length;
            const end = textarea.selectionEnd ?? textarea.value.length;
            textarea.value = `${textarea.value.slice(0, start)}${token}${textarea.value.slice(end)}`;
            textarea.selectionStart = textarea.selectionEnd = start + token.length;
            textarea.focus();
            element.content = textarea.value;
            syncHidden();
            renderStage();
            renderLayers();
        });

        function updateElement(event) {
            const element = selectedElement();
            if (!element) return;

            if (event.target.matches('[data-i="type"]')) {
                const type = event.target.value;
                const selected = defaultSelection(type, element.source);
                element.type = type;
                element.source = isFieldElement(type) ? selected.source : null;
                element.field = isFieldElement(type) ? selected.field : null;
                element.content = ['custom_text', 'date_text', 'page_number'].includes(type)
                    ? (element.content || defaultContent(type))
                    : '';
                if (type === 'barcode') applyBarcodeFormatDefaults(element);
                renderAll();
                return;
            }

            if (event.target.matches('[data-i="source"]')) {
                element.source = event.target.value || null;
                element.field = fieldsFor(element.type, element.source)[0]?.key || null;
                renderAll();
                return;
            }

            if (event.target.matches('[data-i="field"]')) {
                element.field = event.target.value || null;
                syncHidden();
                renderStage();
                renderLayers();
                return;
            }

            if (event.target.matches('[data-i="content"]')) {
                element.content = event.target.value;
                syncHidden();
                renderStage();
                renderLayers();
                return;
            }

            if (event.target.matches('[data-n]')) {
                const key = event.target.dataset.n;
                if (event.target.value === '') return;

                const value = key === 'z_index' ? parseInt(event.target.value, 10) : parseFloat(event.target.value);
                if (!Number.isFinite(value)) return;

                element[key] = value;
                if (['x', 'y', 'width', 'height'].includes(key)) {
                    fitElementToStage(element);
                }

                syncHidden();
                renderStage();
                renderLayers();
                return;
            }

            if (event.target.matches('[data-s-n]')) {
                if (event.target.value === '') return;
                const value = parseFloat(event.target.value);
                if (!Number.isFinite(value)) return;
                element.styling[event.target.dataset.sN] = value;
                syncHidden();
                renderStage();
                renderInspector();
                return;
            }

            if (event.target.matches('[data-s-c]')) {
                element.styling[event.target.dataset.sC] = event.target.checked;
                applyBarcodeFormatDefaults(element);
                renderAll();
                return;
            }

            if (event.target.matches('[data-s]')) {
                element.styling[event.target.dataset.s] = event.target.value;
                if (event.target.dataset.s === 'barcode_format') {
                    applyBarcodeFormatDefaults(element);
                    renderAll();
                    return;
                }
                syncHidden();
                renderStage();
                renderInspector();
            }
        }

        window.addEventListener('pointermove', (event) => {
            if (!state.drag) return;

            const dragged = state.elements.find((element) => element.id === state.drag.id);
            if (!dragged) return;

            const { width, height, scale } = metrics();
            const deltaX = (event.clientX - state.drag.pointerX) / scale;
            const deltaY = (event.clientY - state.drag.pointerY) / scale;
            dragged.x = clamp(Number((state.drag.originX + deltaX).toFixed(2)), 0, Math.max(width - dragged.width, 0));
            dragged.y = clamp(Number((state.drag.originY + deltaY).toFixed(2)), 0, Math.max(height - dragged.height, 0));

            syncHidden();
            renderStage();
            renderLayers();
            renderInspector();
        });

        const stopDrag = () => state.drag = null;
        window.addEventListener('pointerup', stopDrag);
        window.addEventListener('pointercancel', stopDrag);

        function syncLayerPanelForViewport() {
            if (!layersPanel) return;

            if (window.matchMedia('(max-width: 1199px)').matches) {
                layersPanel.removeAttribute('open');
            }
        }

        window.addEventListener('resize', () => {
            renderStage();
            syncLayerPanelForViewport();
        });

        syncSourcesToControls();
        syncLayerPanelForViewport();
        ensureElementBindings();
        state.elements.forEach((element) => {
            element.styling = element.styling || {};
            if (element.type === 'barcode' && (element.styling.barcode_format || 'code39') === 'qrcode' && (element.width > element.height * 1.4 || element.height < 18)) {
                applyBarcodeFormatDefaults(element);
            }
            fitElementToStage(element);
        });
        state.selectedId = state.elements[0]?.id || null;
        renderAll();
    })();
</script>
