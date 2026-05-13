<x-layouts.app>
    @php($filterMetaKeys = ['activity_ids', 'finance_request_ids', 'group_ids', 'parent_ids', 'student_ids', 'teacher_ids', 'user_ids'])

    <div class="page-stack">
        <section class="page-hero p-6 lg:p-8">
            <div class="eyebrow">{{ __('ui.nav.identity_tools') }}</div>
            <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('print_templates.print.title') }}</h1>
            <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('print_templates.print.subtitle') }}</p>
        </section>

        @if ($errors->any())
            <div class="rounded-2xl border border-red-400/20 bg-red-500/10 px-4 py-3 text-sm text-red-100">
                <ul class="space-y-1 list-disc pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('print-templates.print.preview') }}" target="_blank" class="space-y-6">
            @csrf

            <div class="website-workbench website-workbench--editor">
                <section class="surface-panel p-5 lg:p-6">
                    <div class="admin-builder-header">
                        <div>
                            <div class="eyebrow">{{ __('print_templates.print.setup.sections.template') }}</div>
                            <h2 class="font-display mt-3 text-2xl text-white">{{ __('print_templates.print.setup.sections.template') }}</h2>
                        </div>
                    </div>

                    <div class="mt-6 admin-form-grid">
                        <div class="admin-form-field admin-form-field--full">
                            <label for="print-template-print-template">{{ __('print_templates.print.setup.fields.template') }}</label>
                            <select id="print-template-print-template" name="template_id" data-print-template-select>
                                @foreach ($templates as $template)
                                    <option value="{{ $template->id }}" @selected((string) request('template') === (string) $template->id)>{{ $template->name }} | {{ number_format($template->width_mm, 2) }} × {{ number_format($template->height_mm, 2) }} mm</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="admin-form-field admin-form-field--full" data-copy-count-panel>
                            <label for="print-template-copy-count">{{ __('print_templates.print.setup.fields.copy_count') }}</label>
                            <input id="print-template-copy-count" name="copy_count" type="number" min="1" max="1000" value="{{ old('copy_count', 1) }}">
                        </div>

                        <div class="admin-form-field admin-form-field--full">
                            <label for="print-template-page-size">{{ __('settings.organization.sections.print_page_size.table') }}</label>
                            <select id="print-template-page-size" name="print_page_size_id" data-print-page-size-select>
                                @foreach ($pageSizes as $pageSize)
                                    <option
                                        value="{{ $pageSize->id }}"
                                        data-layout='@json($pageSize->layoutConfig())'
                                        @selected((string) old('print_page_size_id', $defaultPageSize?->id) === (string) $pageSize->id)
                                    >
                                        {{ $pageSize->name }} | {{ number_format($pageSize->page_width_mm, 1) }} × {{ number_format($pageSize->page_height_mm, 1) }} mm
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        @foreach (['page_width_mm', 'page_height_mm', 'margin_top_mm', 'margin_right_mm', 'margin_bottom_mm', 'margin_left_mm', 'gap_x_mm', 'gap_y_mm'] as $field)
                            <input name="{{ $field }}" type="hidden" value="{{ old($field, $defaults[$field]) }}" data-page-layout-field="{{ $field }}">
                        @endforeach
                    </div>
                </section>

                <section class="surface-panel p-5 lg:p-6">
                    <div class="admin-builder-header">
                        <div>
                            <div class="eyebrow">{{ __('print_templates.print.setup.sections.sources') }}</div>
                            <h2 class="font-display mt-3 text-2xl text-white">{{ __('print_templates.print.setup.sections.sources') }}</h2>
                        </div>
                    </div>

                    <p class="mt-4 text-sm leading-7 text-neutral-300">{{ __('print_templates.print.setup.sources_help') }}</p>

                    <div class="mt-6 grid gap-5">
                        @foreach ($entities as $entity => $payload)
                            <section class="admin-section-card" data-source-panel="{{ $entity }}" hidden>
                                <div class="admin-builder-header">
                                    <div>
                                        <div class="eyebrow">{{ $payload['label'] }}</div>
                                        <div class="admin-section-card__title" data-source-panel-title="{{ $entity }}">{{ $payload['label'] }}</div>
                                    </div>
                                    <span class="badge-soft" data-source-mode-label="{{ $entity }}"></span>
                                </div>

                                <div class="mt-4 admin-form-field" data-source-single="{{ $entity }}">
                                    <label>{{ __('print_templates.print.setup.fields.select_one', ['entity' => $payload['label']]) }}</label>
                                    <select name="sources[{{ $entity }}][single]" data-source-single-select="{{ $entity }}">
                                        <option value="">{{ __('print_templates.common.none') }}</option>
                                        @foreach ($payload['options'] as $option)
                                            <option
                                                value="{{ $option['id'] }}"
                                                data-record-id="{{ $option['id'] }}"
                                                @foreach ($filterMetaKeys as $metaKey)
                                                    @if (array_key_exists($metaKey, $option['meta'] ?? []))
                                                        data-related-{{ str_replace('_', '-', $metaKey) }}="{{ implode(',', (array) $option['meta'][$metaKey]) }}"
                                                    @endif
                                                @endforeach
                                            >{{ $option['label'] }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div data-source-multiple="{{ $entity }}" hidden>
                                    <div class="admin-toolbar mt-4">
                                        <div class="admin-toolbar__controls">
                                            <div class="admin-filter-field">
                                                <label>{{ __('crud.common.filters.search') }}</label>
                                                <input type="search" data-source-search="{{ $entity }}" placeholder="{{ __('crud.common.filters.search_placeholder') }}">
                                            </div>
                                        </div>
                                        <div class="admin-toolbar__actions">
                                            <button type="button" class="pill-link pill-link--compact" data-source-select-visible="{{ $entity }}">{{ __('print_templates.print.setup.buttons.select_visible') }}</button>
                                            <button type="button" class="pill-link pill-link--compact" data-source-clear="{{ $entity }}">{{ __('print_templates.print.setup.buttons.clear') }}</button>
                                        </div>
                                    </div>

                                    <div class="mt-4 id-card-student-grid">
                                        @foreach ($payload['options'] as $option)
                                            <div
                                                class="id-card-student-card"
                                                data-source-card="{{ $entity }}"
                                                data-search="{{ $option['search'] }}"
                                                data-record-id="{{ $option['id'] }}"
                                                @foreach ($filterMetaKeys as $metaKey)
                                                    @if (array_key_exists($metaKey, $option['meta'] ?? []))
                                                        data-related-{{ str_replace('_', '-', $metaKey) }}="{{ implode(',', (array) $option['meta'][$metaKey]) }}"
                                                    @endif
                                                @endforeach
                                                role="checkbox"
                                                tabindex="0"
                                                aria-checked="false"
                                            >
                                                <input type="checkbox" name="sources[{{ $entity }}][multiple][]" value="{{ $option['id'] }}" class="sr-only" data-source-checkbox="{{ $entity }}">
                                                <div class="student-inline__body">
                                                    <div class="student-inline__name">{{ $option['label'] }}</div>
                                                    <div class="student-inline__meta">#{{ $option['id'] }}</div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </section>
                        @endforeach
                    </div>
                </section>
            </div>

            <div class="admin-action-cluster">
                <button type="submit" class="pill-link pill-link--accent">{{ __('print_templates.print.setup.buttons.preview') }}</button>
                <a href="{{ route('print-templates.templates.index') }}" class="pill-link">{{ __('crud.common.actions.cancel') }}</a>
            </div>
        </form>
    </div>

    <script type="application/json" id="print-template-configs-json">@json($templateConfigs)</script>
    <script>
        (() => {
            const configs = JSON.parse(document.getElementById('print-template-configs-json').textContent);
            const templateSelect = document.querySelector('[data-print-template-select]');
            const pageSizeSelect = document.querySelector('[data-print-page-size-select]');
            const copyPanel = document.querySelector('[data-copy-count-panel]');

            function applyPageSize() {
                const layout = JSON.parse(pageSizeSelect?.selectedOptions?.[0]?.dataset.layout || '{}');

                Object.entries(layout).forEach(([field, value]) => {
                    const input = document.querySelector(`[data-page-layout-field="${field}"]`);

                    if (input && value !== null && value !== undefined) {
                        input.value = value;
                    }
                });
            }

            function activeSources() {
                return configs[templateSelect.value]?.sources || [];
            }

            function activeSource(entity) {
                return activeSources().find((source) => source.entity === entity);
            }

            function setSourceCardChecked(card, checked) {
                const checkbox = card.querySelector('input[type="checkbox"]');
                checkbox.checked = checked;
                card.classList.toggle('is-selected', checked);
                card.setAttribute('aria-checked', checked ? 'true' : 'false');
            }

            function relatedIds(element, entity) {
                const attribute = `data-related-${entity.replaceAll('_', '-')}-ids`;

                if (!element.hasAttribute(attribute)) {
                    return null;
                }

                const value = element.getAttribute(attribute) || '';

                return value.split(',').map((id) => id.trim()).filter(Boolean);
            }

            function selectedSingleSources(exceptEntity = '') {
                return [...document.querySelectorAll('[data-source-single-select]')]
                    .map((select) => ({
                        entity: select.dataset.sourceSingleSelect,
                        id: select.value,
                        option: select.selectedOptions[0],
                    }))
                    .filter((source) => source.entity !== exceptEntity && source.id !== '' && source.option);
            }

            function recordMatchesSelectedSources(targetEntity, targetId, targetElement, exceptEntity = '') {
                return selectedSingleSources(exceptEntity).every((source) => {
                    const selectedAllowedIds = relatedIds(source.option, targetEntity);

                    if (selectedAllowedIds !== null && !selectedAllowedIds.includes(String(targetId))) {
                        return false;
                    }

                    const targetAllowedIds = relatedIds(targetElement, source.entity);

                    return targetAllowedIds === null || targetAllowedIds.includes(String(source.id));
                });
            }

            function applySingleSelectFilter(entity) {
                const select = document.querySelector(`[data-source-single-select="${entity}"]`);

                if (!select) {
                    return;
                }

                [...select.options].forEach((option) => {
                    if (option.value === '') {
                        option.hidden = false;
                        option.disabled = false;
                        return;
                    }

                    const visible = recordMatchesSelectedSources(entity, option.value, option, entity);
                    option.hidden = !visible;
                    option.disabled = !visible;
                });

                if (select.value !== '' && select.selectedOptions[0]?.disabled) {
                    select.value = '';
                }
            }

            function applySourceFilter(entity) {
                const searchInput = document.querySelector(`[data-source-search="${entity}"]`);
                const term = (searchInput?.value || '').trim().toLowerCase();

                document.querySelectorAll(`[data-source-card="${entity}"]`).forEach((card) => {
                    const searchMiss = term !== '' && !card.dataset.search.includes(term);
                    const relationMiss = !recordMatchesSelectedSources(entity, card.dataset.recordId, card);
                    card.hidden = searchMiss || relationMiss;

                    if (card.hidden) {
                        setSourceCardChecked(card, false);
                    }
                });
            }

            function applyAllFilters() {
                document.querySelectorAll('[data-source-single-select]').forEach((select) => {
                    applySingleSelectFilter(select.dataset.sourceSingleSelect);
                });
                document.querySelectorAll('[data-source-panel]').forEach((panel) => applySourceFilter(panel.dataset.sourcePanel));
            }

            function updatePanels() {
                const sources = activeSources();
                const active = sources.map((source) => source.entity);
                document.querySelectorAll('[data-source-panel]').forEach((panel) => {
                    const entity = panel.dataset.sourcePanel;
                    const source = sources.find((item) => item.entity === entity);
                    panel.hidden = !active.includes(entity);
                    if (!source) return;
                    panel.querySelector(`[data-source-single="${entity}"]`).hidden = source.mode !== 'single';
                    panel.querySelector(`[data-source-multiple="${entity}"]`).hidden = source.mode !== 'multiple';
                    panel.querySelector(`[data-source-mode-label="${entity}"]`).textContent = source.mode === 'multiple'
                        ? @json(__('print_templates.templates.form.source_modes.multiple'))
                        : @json(__('print_templates.templates.form.source_modes.single'));
                });
                copyPanel.hidden = sources.length > 0;
                applyAllFilters();
            }

            document.querySelectorAll('[data-source-card]').forEach((card) => {
                const checkbox = card.querySelector('input[type="checkbox"]');
                card.addEventListener('click', (event) => {
                    if (event.target.matches('input')) return;
                    setSourceCardChecked(card, !checkbox.checked);
                });
                card.addEventListener('keydown', (event) => {
                    if (!['Enter', ' '].includes(event.key)) return;
                    event.preventDefault();
                    setSourceCardChecked(card, !checkbox.checked);
                });
                checkbox.addEventListener('change', () => setSourceCardChecked(card, checkbox.checked));
            });

            document.querySelectorAll('[data-source-search]').forEach((input) => {
                input.addEventListener('input', () => applySourceFilter(input.dataset.sourceSearch));
            });

            document.querySelectorAll('[data-source-single-select]').forEach((select) => {
                select.addEventListener('change', applyAllFilters);
            });

            document.querySelectorAll('[data-source-select-visible]').forEach((button) => {
                button.addEventListener('click', () => {
                    document.querySelectorAll(`[data-source-card="${button.dataset.sourceSelectVisible}"]`).forEach((card) => {
                        if (card.hidden) return;
                        setSourceCardChecked(card, true);
                    });
                });
            });

            document.querySelectorAll('[data-source-clear]').forEach((button) => {
                button.addEventListener('click', () => {
                    document.querySelectorAll(`[data-source-card="${button.dataset.sourceClear}"]`).forEach((card) => {
                        setSourceCardChecked(card, false);
                    });
                });
            });

            templateSelect?.addEventListener('change', updatePanels);
            pageSizeSelect?.addEventListener('change', applyPageSize);
            updatePanels();
        })();
    </script>
</x-layouts.app>
