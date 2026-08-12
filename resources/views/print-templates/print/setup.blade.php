<x-layouts.app>
    @php($filterMetaKeys = ['activity_ids', 'finance_request_ids', 'group_ids', 'parent_ids', 'student_ids', 'teacher_ids', 'user_ids'])

    <div class="page-stack">
        <section class="page-hero !overflow-visible p-6 lg:p-8">
            <div class="flex flex-wrap items-start justify-between gap-5">
                <div>
                    <div class="eyebrow">{{ __('ui.nav.identity_tools') }}</div>
                    <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ $pageTitle ?? __('print_templates.print.title') }}</h1>
                    <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ $pageSubtitle ?? __('print_templates.print.subtitle') }}</p>
                </div>
                @if ($studentCardMode ?? false)
                    <div class="admin-form-field relative z-20 min-w-64 overflow-visible">
                        <label for="student-card-course">{{ __('crud.students.bulk_status.fields.course') }}</label>
                        <select id="student-card-course" class="relative z-30" onchange="window.location.href = `${window.location.pathname}?course_id=${this.value}`">
                            @foreach ($activeCourses as $course)<option value="{{ $course->id }}" @selected((int)$selectedCourseId === (int)$course->id)>{{ $course->name }}</option>@endforeach
                        </select>
                    </div>
                @endif
            </div>
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

        @if ($templates->isEmpty())
            <section class="surface-panel p-5 lg:p-6">
                <div class="admin-empty-state">
                    <div class="text-base font-semibold text-white">{{ $emptyStateTitle }}</div>
                    <p class="mt-2 text-sm leading-7 text-neutral-300">{{ $emptyStateDescription }}</p>
                    @can('id-cards.templates.manage')
                        <div class="mt-4">
                            <a href="{{ $emptyStateCreateUrl }}" class="pill-link pill-link--accent">{{ __('print_templates.templates.actions.create') }}</a>
                        </div>
                    @endcan
                </div>
            </section>
        @else
            <form method="POST" action="{{ $previewRoute }}" target="_blank" class="space-y-6">
                @csrf
                @if (($studentCardMode ?? false) || ($courseReportMode ?? false))<input type="hidden" name="course_id" value="{{ $selectedCourseId }}">@endif

                <div class="grid gap-6">
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
                                <label>{{ __('print_templates.templates.form.fields.paper_size') }}</label>
                                <div class="rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-neutral-200" data-template-paper-label></div>
                            </div>

                            @foreach (['page_width_mm', 'page_height_mm', 'margin_top_mm', 'margin_right_mm', 'margin_bottom_mm', 'margin_left_mm', 'gap_x_mm', 'gap_y_mm'] as $field)
                                <input name="{{ $field }}" type="hidden" value="{{ old($field, $defaults[$field]) }}" data-page-layout-field="{{ $field }}">
                            @endforeach
                        </div>

                        <div class="mt-6 admin-action-cluster">
                            <button type="submit" class="pill-link pill-link--accent">{{ __('print_templates.print.setup.buttons.preview') }}</button>
                            @if ($studentCardMode ?? false)
                                <button
                                    type="button"
                                    class="pill-link"
                                    data-toggle-selected-print-status
                                    data-record-url="{{ route('id-cards.print.record') }}"
                                    data-clear-url="{{ route('id-cards.print.clear') }}"
                                >
                                    {{ __('print_templates.print.setup.buttons.mark_printed') }}
                                </button>
                            @endif
                            <a href="{{ $cancelUrl }}" class="pill-link">{{ __('crud.common.actions.cancel') }}</a>
                        </div>
                        @if ($studentCardMode ?? false)
                            <p class="mt-3 text-sm text-neutral-300" data-print-status-notice hidden></p>
                        @endif
                    </section>

                    <section class="surface-panel p-5 lg:p-6">
                        <div class="admin-builder-header">
                            <div>
                                <div class="eyebrow">{{ __('print_templates.print.setup.sections.sources') }}</div>
                                <h2 class="font-display mt-3 text-2xl text-white">{{ __('print_templates.print.setup.sections.sources') }}</h2>
                            </div>
                        </div>

                        <div class="mt-4 grid gap-5">
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
                                        <div class="admin-toolbar print-template-source-toolbar mt-4 rounded-2xl border border-white/10 bg-white/5 p-4">
                                            <div class="admin-toolbar__controls">
                                                <div class="admin-filter-field">
                                                    <label>{{ __('crud.common.filters.search') }}</label>
                                                    <input type="search" data-source-search="{{ $entity }}" placeholder="{{ __('crud.common.filters.search_placeholder') }}">
                                                </div>
                                                @if ($entity === 'student')
                                                    <div class="admin-filter-field">
                                                        <label>{{ __('print_templates.print.setup.fields.filter_group') }}</label>
                                                        <select data-source-group-filter="{{ $entity }}">
                                                            <option value="">{{ __('print_templates.print.setup.fields.all_groups') }}</option>
                                                            @foreach ($studentFilters['groups'] as $group)
                                                                <option value="{{ $group->id }}">{{ $group->name }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                    @unless ($studentCardMode ?? false)<div class="admin-filter-field"><label>{{ __('print_templates.print.setup.fields.filter_status') }}</label><select data-source-status-filter="{{ $entity }}"><option value="">{{ __('print_templates.print.setup.fields.all_students') }}</option><option value="active">{{ __('print_templates.print.setup.fields.active_students') }}</option><option value="not_active">{{ __('print_templates.print.setup.fields.non_active_students') }}</option></select></div>@endunless
                                                    @if ($studentCardMode ?? false)
                                                        <div class="admin-filter-field">
                                                            <label>{{ __('print_templates.print.setup.fields.filter_printed') }}</label>
                                                            <select data-source-printed-filter="{{ $entity }}">
                                                                <option value="printed">{{ __('print_templates.print.setup.fields.printed_students') }}</option>
                                                                <option value="not_printed" selected>{{ __('print_templates.print.setup.fields.not_printed_students') }}</option>
                                                            </select>
                                                        </div>
                                                    @endif
                                                @endif
                                                <div class="admin-toolbar__actions print-template-source-toolbar__actions">
                                                    <button type="button" class="pill-link pill-link--compact" data-source-select-visible="{{ $entity }}">{{ __('print_templates.print.setup.buttons.select_visible') }}</button>
                                                    <button type="button" class="pill-link pill-link--compact" data-source-clear="{{ $entity }}">{{ __('print_templates.print.setup.buttons.clear') }}</button>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="mt-4 id-card-student-grid">
                                            @foreach ($payload['options'] as $option)
                                                <div
                                                    class="id-card-student-card print-template-student-card {{ ($courseReportMode ?? false) && $entity === 'course_student' ? 'is-selected' : '' }}"
                                                    data-source-card="{{ $entity }}"
                                                    data-search="{{ $option['search'] }}"
                                                    data-record-id="{{ $option['id'] }}"
                                                    @foreach ($filterMetaKeys as $metaKey)
                                                        @if (array_key_exists($metaKey, $option['meta'] ?? []))
                                                            data-related-{{ str_replace('_', '-', $metaKey) }}="{{ implode(',', (array) $option['meta'][$metaKey]) }}"
                                                        @endif
                                                    @endforeach
                                                    @if (filled($option['meta']['status'] ?? null))
                                                        data-status="{{ $option['meta']['status'] }}"
                                                    @endif
                                                    @if (array_key_exists('card_printed', $option['meta'] ?? []))
                                                        data-card-printed="{{ $option['meta']['card_printed'] ? '1' : '0' }}"
                                                    @endif
                                                    role="checkbox"
                                                    tabindex="0"
                                                    aria-checked="{{ ($courseReportMode ?? false) && $entity === 'course_student' ? 'true' : 'false' }}"
                                                >
                                                    <input type="checkbox" name="sources[{{ $entity }}][multiple][]" value="{{ $option['id'] }}" class="{{ ($courseReportMode ?? false) && $entity === 'course_student' ? 'h-4 w-4 shrink-0 rounded border-neutral-300' : 'sr-only' }}" data-source-checkbox="{{ $entity }}" @checked(($courseReportMode ?? false) && $entity === 'course_student')>
                                                    <div class="student-inline print-template-student-card__content">
                                                        <div class="student-inline__body">
                                                            <div class="student-inline__name">{{ $option['label'] }}</div>
                                                            <div class="student-inline__meta">#{{ $option['id'] }}</div>
                                                            @if ($entity === 'student')
                                                                <div class="student-inline__meta">{{ $option['meta']['group_name'] ?? __('print_templates.common.not_available') }}</div>
                                                                <div class="student-inline__meta" data-student-print-state>
                                                                    {{ ($option['meta']['card_printed'] ?? false) ? __('print_templates.print.setup.fields.printed_flag') : __('print_templates.print.setup.fields.not_printed_flag') }}
                                                                    @if (filled($option['meta']['card_last_printed_at'] ?? null))
                                                                        | {{ $option['meta']['card_last_printed_at'] }}
                                                                    @endif
                                                                </div>
                                                            @endif
                                                            @if (($courseReportMode ?? false) && $entity === 'course_student')
                                                                <label class="mt-3 block text-xs text-neutral-300">
                                                                    <span>{{ __('print_templates.fields.special_note') }}</span>
                                                                    <textarea name="special_notes[{{ $option['id'] }}]" rows="2" class="mt-1 w-full rounded-lg" placeholder="{{ __('print_templates.fields.special_note') }}">{{ old('special_notes.'.$option['id']) }}</textarea>
                                                                </label>
                                                            @endif
                                                        </div>
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
            </form>
        @endif
    </div>

    <script type="application/json" id="print-template-configs-json">@json($templateConfigs)</script>
    <script>
        (() => {
            const configs = JSON.parse(document.getElementById('print-template-configs-json').textContent);
            const templateSelect = document.querySelector('[data-print-template-select]');
            const paperLabel = document.querySelector('[data-template-paper-label]');
            const copyPanel = document.querySelector('[data-copy-count-panel]');
            const printStatusButton = document.querySelector('[data-toggle-selected-print-status]');
            const printStatusNotice = document.querySelector('[data-print-status-notice]');

            if (!templateSelect) {
                return;
            }

            const printedFlagLabel = @json(__('print_templates.print.setup.fields.printed_flag'));
            const notPrintedFlagLabel = @json(__('print_templates.print.setup.fields.not_printed_flag'));
            const markPrintedDefaultLabel = @json(__('print_templates.print.setup.buttons.mark_printed'));
            const markUnprintedDefaultLabel = @json(__('print_templates.print.setup.buttons.mark_unprinted'));
            const markPrintedBusyLabel = @json(__('print_templates.print.setup.buttons.mark_printed_busy'));
            const markUnprintedBusyLabel = @json(__('print_templates.print.setup.buttons.mark_unprinted_busy'));
            const markPrintedSuccessMessage = @json(__('print_templates.print.setup.messages.marked_printed'));
            const markUnprintedSuccessMessage = @json(__('print_templates.print.setup.messages.marked_unprinted'));
            const markPrintedEmptyMessage = @json(__('print_templates.print.setup.errors.no_students_selected'));
            const markPrintedFailedMessage = @json(__('print_templates.print.setup.errors.mark_printed_failed'));
            const markUnprintedFailedMessage = @json(__('print_templates.print.setup.errors.mark_unprinted_failed'));
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                || document.querySelector('input[name="_token"]')?.value
                || '';
            const selectedCourseId = document.querySelector('input[name="course_id"]')?.value || '';

            function applyTemplateLayout() {
                const config = configs[templateSelect?.value || ''] || {};
                const layout = config.layout || {};

                Object.entries(layout).forEach(([field, value]) => {
                    const input = document.querySelector(`[data-page-layout-field="${field}"]`);

                    if (input && value !== null && value !== undefined) {
                        input.value = value;
                    }
                });

                if (paperLabel) {
                    paperLabel.textContent = config.paper_label || '';
                }
            }

            function activeSources() {
                return configs[templateSelect?.value || '']?.sources || [];
            }

            function setSourceCardChecked(card, checked) {
                const checkbox = card.querySelector('input[type="checkbox"]');
                checkbox.checked = checked;
                card.classList.toggle('is-selected', checked);
                card.setAttribute('aria-checked', checked ? 'true' : 'false');
                updatePrintStatusButton();
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
                const groupFilter = document.querySelector(`[data-source-group-filter="${entity}"]`);
                const statusFilter = document.querySelector(`[data-source-status-filter="${entity}"]`);
                const printedFilter = document.querySelector(`[data-source-printed-filter="${entity}"]`);
                const term = (searchInput?.value || '').trim().toLowerCase();
                const selectedGroupId = groupFilter?.value || '';
                const selectedStatus = statusFilter?.value || '';
                const selectedPrintedState = printedFilter?.value || '';

                document.querySelectorAll(`[data-source-card="${entity}"]`).forEach((card) => {
                    const searchMiss = term !== '' && !card.dataset.search.includes(term);
                    const relationMiss = !recordMatchesSelectedSources(entity, card.dataset.recordId, card);
                    const groupMiss = selectedGroupId !== '' && !(relatedIds(card, 'group') || []).includes(selectedGroupId);
                    const statusMiss = selectedStatus === 'active'
                        ? card.dataset.status !== 'active'
                        : selectedStatus === 'not_active'
                            ? card.dataset.status === 'active'
                            : false;
                    const printedMiss = selectedPrintedState === 'printed'
                        ? card.dataset.cardPrinted !== '1'
                        : selectedPrintedState === 'not_printed'
                            ? card.dataset.cardPrinted === '1'
                            : false;
                    card.hidden = searchMiss || relationMiss || groupMiss || statusMiss || printedMiss;
                });
            }

            function applyAllFilters() {
                document.querySelectorAll('[data-source-single-select]').forEach((select) => {
                    applySingleSelectFilter(select.dataset.sourceSingleSelect);
                });
                document.querySelectorAll('[data-source-panel]').forEach((panel) => applySourceFilter(panel.dataset.sourcePanel));
            }

            function setPrintStatusNotice(message = '', tone = 'neutral') {
                if (!printStatusNotice) {
                    return;
                }

                if (message === '') {
                    printStatusNotice.hidden = true;
                    printStatusNotice.textContent = '';
                    printStatusNotice.classList.remove('text-emerald-300', 'text-red-300', 'text-neutral-300');
                    return;
                }

                printStatusNotice.hidden = false;
                printStatusNotice.textContent = message;
                printStatusNotice.classList.remove('text-emerald-300', 'text-red-300', 'text-neutral-300');
                printStatusNotice.classList.add(
                    tone === 'success' ? 'text-emerald-300' : tone === 'error' ? 'text-red-300' : 'text-neutral-300',
                );
            }

            function selectedStudentIds() {
                return [...document.querySelectorAll('input[data-source-checkbox="student"]:checked')]
                    .map((checkbox) => Number(checkbox.value))
                    .filter((value) => Number.isInteger(value) && value > 0);
            }

            function shouldClearSelectedPrints() {
                const selectedCards = [...document.querySelectorAll('[data-source-card="student"]')]
                    .filter((card) => card.querySelector('input[type="checkbox"]')?.checked);
                const printedCount = selectedCards.filter((card) => card.dataset.cardPrinted === '1').length;

                return printedCount > (selectedCards.length - printedCount);
            }

            function updatePrintStatusButton() {
                if (!printStatusButton) return;
                printStatusButton.textContent = shouldClearSelectedPrints() ? markUnprintedDefaultLabel : markPrintedDefaultLabel;
            }

            function updateStudentPrintedState(studentIds, printedAtLabel = '') {
                const selectedIds = new Set(studentIds.map((id) => String(id)));

                document.querySelectorAll('[data-source-card="student"]').forEach((card) => {
                    if (!selectedIds.has(card.dataset.recordId)) {
                        return;
                    }

                    card.dataset.cardPrinted = '1';

                    const stateLabel = card.querySelector('[data-student-print-state]');

                    if (stateLabel) {
                        stateLabel.textContent = printedAtLabel === ''
                            ? printedFlagLabel
                            : `${printedFlagLabel} | ${printedAtLabel}`;
                    }
                });
            }

            function updateStudentUnprintedState(studentIds) {
                const selectedIds = new Set(studentIds.map((id) => String(id)));

                document.querySelectorAll('[data-source-card="student"]').forEach((card) => {
                    if (!selectedIds.has(card.dataset.recordId)) {
                        return;
                    }

                    card.dataset.cardPrinted = '0';

                    const stateLabel = card.querySelector('[data-student-print-state]');

                    if (stateLabel) {
                        stateLabel.textContent = notPrintedFlagLabel;
                    }
                });
            }

            function updatePanels() {
                applyTemplateLayout();
                const sources = activeSources();
                const active = sources.map((source) => source.entity);

                document.querySelectorAll('[data-source-panel]').forEach((panel) => {
                    const entity = panel.dataset.sourcePanel;
                    const source = sources.find((item) => item.entity === entity);
                    panel.hidden = !active.includes(entity);

                    if (!source) {
                        return;
                    }

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
                    if (event.target.closest('input, textarea, select, label, button')) {
                        return;
                    }

                    setSourceCardChecked(card, !checkbox.checked);
                });
                card.addEventListener('keydown', (event) => {
                    if (!['Enter', ' '].includes(event.key)) {
                        return;
                    }

                    event.preventDefault();
                    setSourceCardChecked(card, !checkbox.checked);
                });
                checkbox.addEventListener('change', () => setSourceCardChecked(card, checkbox.checked));
            });

            document.querySelectorAll('[data-source-search]').forEach((input) => {
                input.addEventListener('input', () => applySourceFilter(input.dataset.sourceSearch));
            });

            document.querySelectorAll('[data-source-group-filter], [data-source-status-filter], [data-source-printed-filter]').forEach((select) => {
                select.addEventListener('change', () => applySourceFilter(select.dataset.sourceGroupFilter || select.dataset.sourceStatusFilter || select.dataset.sourcePrintedFilter));
            });

            document.querySelectorAll('[data-source-single-select]').forEach((select) => {
                select.addEventListener('change', applyAllFilters);
            });

            document.querySelectorAll('[data-source-select-visible]').forEach((button) => {
                button.addEventListener('click', () => {
                    document.querySelectorAll(`[data-source-card="${button.dataset.sourceSelectVisible}"]`).forEach((card) => {
                        if (card.hidden) {
                            return;
                        }

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

            printStatusButton?.addEventListener('click', async () => {
                const studentIds = selectedStudentIds();

                if (studentIds.length === 0) {
                    setPrintStatusNotice(markPrintedEmptyMessage, 'error');
                    return;
                }

                setPrintStatusNotice('', 'neutral');
                const clearPrints = shouldClearSelectedPrints();
                printStatusButton.disabled = true;
                printStatusButton.textContent = clearPrints ? markUnprintedBusyLabel : markPrintedBusyLabel;

                try {
                    const response = await fetch(clearPrints ? printStatusButton.dataset.clearUrl : printStatusButton.dataset.recordUrl, {
                        method: clearPrints ? 'DELETE' : 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({
                            template_id: templateSelect.value,
                            student_ids: studentIds,
                            course_id: selectedCourseId,
                        }),
                    });

                    if (!response.ok) {
                        throw new Error(`Request failed with status ${response.status}`);
                    }

                    const payload = await response.json();
                    if (clearPrints) {
                        updateStudentUnprintedState(studentIds);
                    } else {
                        const printedAtLabel = payload.printed_at ? new Date(payload.printed_at).toLocaleString() : '';
                        updateStudentPrintedState(studentIds, printedAtLabel);
                    }
                    applySourceFilter('student');
                    setPrintStatusNotice(clearPrints ? markUnprintedSuccessMessage : markPrintedSuccessMessage, 'success');
                } catch (error) {
                    console.error(error);
                    setPrintStatusNotice(clearPrints ? markUnprintedFailedMessage : markPrintedFailedMessage, 'error');
                } finally {
                    printStatusButton.disabled = false;
                    updatePrintStatusButton();
                }
            });

            templateSelect.addEventListener('change', updatePanels);
            updatePanels();
        })();
    </script>
</x-layouts.app>
