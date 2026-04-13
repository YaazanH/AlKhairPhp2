<x-layouts.app>
    <div class="page-stack">
        <section class="page-hero p-6 lg:p-8">
            <div class="eyebrow">{{ __('ui.nav.identity_tools') }}</div>
            <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('id_cards.print.title') }}</h1>
            <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('id_cards.print.subtitle') }}</p>
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

        <form method="POST" action="{{ route('id-cards.print.preview') }}" target="_blank" class="space-y-6">
            @csrf

            <div class="website-workbench website-workbench--editor">
                <section class="surface-panel p-5 lg:p-6">
                    <div class="admin-builder-header">
                        <div>
                            <div class="eyebrow">{{ __('id_cards.print.setup.sections.template') }}</div>
                            <h2 class="font-display mt-3 text-2xl text-white">{{ __('id_cards.print.setup.sections.template') }}</h2>
                        </div>
                    </div>

                    <div class="mt-6 admin-form-grid">
                        <div class="admin-form-field admin-form-field--full">
                            <label for="id-card-print-template" class="mb-1 block text-sm font-medium">{{ __('id_cards.print.setup.fields.template') }}</label>
                            <select id="id-card-print-template" name="template_id" class="w-full rounded-xl px-4 py-3 text-sm">
                                @foreach ($templates as $template)
                                    <option value="{{ $template->id }}" @selected((string) request('template') === (string) $template->id)>{{ $template->name }} | {{ number_format($template->width_mm, 2) }} × {{ number_format($template->height_mm, 2) }} mm</option>
                                @endforeach
                            </select>
                        </div>

                        @foreach (['page_width_mm', 'page_height_mm', 'margin_top_mm', 'margin_right_mm', 'margin_bottom_mm', 'margin_left_mm', 'gap_x_mm', 'gap_y_mm'] as $field)
                            <div class="admin-form-field">
                                <label class="mb-1 block text-sm font-medium">{{ __('id_cards.print.setup.fields.'.$field) }}</label>
                                <input name="{{ $field }}" type="number" min="0" step="0.1" value="{{ old($field, $defaults[$field]) }}" class="w-full rounded-xl px-4 py-3 text-sm">
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="surface-panel p-5 lg:p-6">
                    <div class="admin-builder-header">
                        <div>
                            <div class="eyebrow">{{ __('id_cards.print.setup.sections.students') }}</div>
                            <h2 class="font-display mt-3 text-2xl text-white">{{ __('id_cards.print.setup.sections.students') }}</h2>
                        </div>
                        <span class="badge-soft" data-id-card-selected-count>{{ __('id_cards.print.setup.selected', ['count' => 0]) }}</span>
                    </div>

                    <div class="mt-6 admin-toolbar">
                        <div class="admin-toolbar__controls">
                            <div class="admin-filter-field">
                                <label for="id-card-student-search">{{ __('id_cards.print.setup.fields.search') }}</label>
                                <input id="id-card-student-search" type="search" placeholder="{{ __('id_cards.print.setup.placeholders.search') }}" data-id-card-student-search>
                            </div>
                        </div>
                        <div class="admin-toolbar__actions">
                            <button type="button" class="pill-link pill-link--compact" data-id-card-select-visible>{{ __('id_cards.print.setup.buttons.select_all') }}</button>
                            <button type="button" class="pill-link pill-link--compact" data-id-card-clear-selection>{{ __('id_cards.print.setup.buttons.clear') }}</button>
                        </div>
                    </div>

                    @if ($students->isEmpty())
                        <div class="admin-empty-state">{{ __('id_cards.print.setup.empty') }}</div>
                    @else
                        <div class="mt-6 id-card-student-grid">
                            @foreach ($students as $student)
                                <label class="id-card-student-card" data-student-card data-search="{{ strtolower($student->full_name.' '.($student->student_number ?? '').' '.($student->parentProfile?->father_name ?? '')) }}">
                                    <input type="checkbox" name="student_ids[]" value="{{ $student->id }}" class="sr-only" data-id-card-student-checkbox>
                                    <div class="student-inline">
                                        <x-student-avatar :student="$student" size="sm" />
                                        <div class="student-inline__body">
                                            <div class="student-inline__name">{{ $student->full_name }}</div>
                                            <div class="student-inline__meta">
                                                {{ $student->student_number ?: __('id_cards.common.not_available') }}
                                                @if ($student->gradeLevel?->name)
                                                    | {{ $student->gradeLevel->name }}
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mt-3 text-sm text-neutral-400">{{ $student->parentProfile?->father_name ?: __('id_cards.common.not_available') }}</div>
                                </label>
                            @endforeach
                        </div>
                    @endif
                </section>
            </div>

            <div class="admin-action-cluster">
                <button type="submit" class="pill-link pill-link--accent">{{ __('id_cards.print.setup.buttons.preview') }}</button>
                <a href="{{ route('id-cards.templates.index') }}" class="pill-link">{{ __('crud.common.actions.cancel') }}</a>
            </div>
        </form>
    </div>

    <script>
        (() => {
            const searchInput = document.querySelector('[data-id-card-student-search]');
            const cards = Array.from(document.querySelectorAll('[data-student-card]'));
            const checkboxes = Array.from(document.querySelectorAll('[data-id-card-student-checkbox]'));
            const selectedCount = document.querySelector('[data-id-card-selected-count]');
            const selectVisibleButton = document.querySelector('[data-id-card-select-visible]');
            const clearButton = document.querySelector('[data-id-card-clear-selection]');

            if (!cards.length || !selectedCount) return;

            const selectedTemplate = @json(__('id_cards.print.setup.selected', ['count' => ':count']));

            const updateSelectedCount = () => {
                selectedCount.textContent = selectedTemplate.replace(':count', checkboxes.filter((checkbox) => checkbox.checked).length.toLocaleString());
            };

            const applyFilter = () => {
                const term = (searchInput?.value || '').trim().toLowerCase();
                cards.forEach((card) => {
                    card.hidden = term !== '' && !card.dataset.search.includes(term);
                });
            };

            cards.forEach((card) => {
                const checkbox = card.querySelector('[data-id-card-student-checkbox]');

                card.addEventListener('click', (event) => {
                    if (event.target.matches('input')) return;
                    checkbox.checked = !checkbox.checked;
                    card.classList.toggle('is-selected', checkbox.checked);
                    updateSelectedCount();
                });

                checkbox.addEventListener('change', () => {
                    card.classList.toggle('is-selected', checkbox.checked);
                    updateSelectedCount();
                });
            });

            searchInput?.addEventListener('input', applyFilter);
            selectVisibleButton?.addEventListener('click', () => {
                cards.filter((card) => !card.hidden).forEach((card) => {
                    card.classList.add('is-selected');
                    card.querySelector('[data-id-card-student-checkbox]').checked = true;
                });
                updateSelectedCount();
            });
            clearButton?.addEventListener('click', () => {
                cards.forEach((card) => card.classList.remove('is-selected'));
                checkboxes.forEach((checkbox) => checkbox.checked = false);
                updateSelectedCount();
            });

            updateSelectedCount();
        })();
    </script>
</x-layouts.app>
