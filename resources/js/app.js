import jsQR from 'jsqr';

const adminConfirmState = {
    activeElement: null,
    componentId: null,
    method: null,
    params: [],
    form: null,
};
let adminConfirmDelegatedListenersBound = false;

function parseLivewireAction(expression) {
    if (!expression) {
        return null;
    }

    let payload = null;
    const normalizedExpression = expression.trim();
    const bareMethodCall = normalizedExpression.match(/^([A-Za-z_$][\w$]*)$/);
    const directMethodCall = normalizedExpression.match(/^([A-Za-z_$][\w$]*)\s*\(/);

    if (bareMethodCall) {
        return {
            method: bareMethodCall[1],
            params: [],
        };
    }

    try {
        const context = new Proxy({}, {
            get(_target, property) {
                return (...args) => {
                    payload = {
                        method: String(property),
                        params: args,
                    };
                };
            },
        });

        if (directMethodCall) {
            Function('ctx', `ctx.${normalizedExpression};`)(context);
        } else {
            Function('ctx', `with (ctx) { ${normalizedExpression}; }`)(context);
        }
    } catch (_error) {
        return null;
    }

    return payload;
}

function adminConfirmElements() {
    return {
        modal: document.getElementById('admin-confirm-modal'),
        title: document.getElementById('admin-confirm-title'),
        message: document.getElementById('admin-confirm-message'),
        accept: document.getElementById('admin-confirm-accept'),
        closeButtons: document.querySelectorAll('[data-admin-confirm-close]'),
    };
}

function resetAdminConfirmState() {
    adminConfirmState.componentId = null;
    adminConfirmState.method = null;
    adminConfirmState.params = [];
    adminConfirmState.form = null;
}

function closeAdminConfirm() {
    const { modal } = adminConfirmElements();

    if (!modal || modal.hidden) {
        return;
    }

    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('app-body--modal-open');

    const activeElement = adminConfirmState.activeElement;
    resetAdminConfirmState();
    adminConfirmState.activeElement = null;

    if (activeElement instanceof HTMLElement) {
        activeElement.focus();
    }
}

function openAdminConfirm(options = {}) {
    const { modal, title, message, accept } = adminConfirmElements();

    if (!modal || !title || !message || !accept) {
        return;
    }

    adminConfirmState.activeElement = document.activeElement instanceof HTMLElement
        ? document.activeElement
        : null;
    adminConfirmState.componentId = options.componentId ?? null;
    adminConfirmState.method = options.method ?? null;
    adminConfirmState.params = Array.isArray(options.params) ? options.params : [];
    adminConfirmState.form = options.form ?? null;

    title.textContent = options.title ?? modal.dataset.defaultTitle ?? 'Confirm action';
    message.textContent = options.message ?? modal.dataset.defaultMessage ?? '';
    accept.textContent = options.confirmLabel ?? modal.dataset.defaultConfirmLabel ?? 'Continue';

    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('app-body--modal-open');

    requestAnimationFrame(() => {
        accept.focus();
    });
}

function confirmAdminAction() {
    const { componentId, form, method, params } = adminConfirmState;

    closeAdminConfirm();

    if (form instanceof HTMLFormElement) {
        form.submit();

        return;
    }

    if (!componentId || !method || !window.Livewire) {
        return;
    }

    const component = window.Livewire.find(componentId);

    if (!component) {
        return;
    }

    component.call(method, ...params);
}

function handleLivewireConfirm(event) {
    const trigger = event.target instanceof Element
        ? event.target.closest('[wire\\:confirm][wire\\:click]')
        : null;

    if (!trigger) {
        return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();

    const action = parseLivewireAction(trigger.getAttribute('wire:click'));
    const componentRoot = trigger.closest('[wire\\:id]');

    if (!action || !componentRoot) {
        return;
    }

    openAdminConfirm({
        componentId: componentRoot.getAttribute('wire:id'),
        method: action.method,
        params: action.params,
        title: trigger.dataset.confirmTitle,
        message: trigger.getAttribute('wire:confirm'),
        confirmLabel: trigger.dataset.confirmLabel,
    });
}

function handleFormConfirm(event) {
    const form = event.target instanceof HTMLFormElement
        ? event.target.closest('form[data-admin-confirm-message]')
        : null;

    if (!form) {
        return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();

    openAdminConfirm({
        form,
        title: form.dataset.adminConfirmTitle,
        message: form.dataset.adminConfirmMessage,
        confirmLabel: form.dataset.adminConfirmLabel,
    });
}

function registerAdminConfirmListeners() {
    const { accept, closeButtons, modal } = adminConfirmElements();

    if (!modal) {
        return;
    }

    if (!adminConfirmDelegatedListenersBound) {
        adminConfirmDelegatedListenersBound = true;

        document.addEventListener('click', handleLivewireConfirm, true);
        document.addEventListener('submit', handleFormConfirm, true);

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeAdminConfirm();
            }
        });
    }

    if (modal.dataset.bound !== 'true') {
        modal.dataset.bound = 'true';

        accept?.addEventListener('click', confirmAdminAction);

        closeButtons.forEach((element) => {
            element.addEventListener('click', closeAdminConfirm);
        });
    }
}

window.AdminConfirm = {
    close: closeAdminConfirm,
    open: openAdminConfirm,
};

document.addEventListener('DOMContentLoaded', registerAdminConfirmListeners);
document.addEventListener('livewire:navigated', registerAdminConfirmListeners);

const SEARCHABLE_SELECT_BINDING_VERSION = '6';
let searchableSelectOpenSuppressedUntil = 0;

function suppressSearchableSelectOpen(duration = 400) {
    searchableSelectOpenSuppressedUntil = Math.max(
        searchableSelectOpenSuppressedUntil,
        Date.now() + duration,
    );
}

function searchableSelectOpenIsSuppressed() {
    return Date.now() < searchableSelectOpenSuppressedUntil;
}

function searchableSelectBinding(select) {
    return Array.from(select.attributes)
        .find((attribute) => attribute.name.startsWith('wire:model'))
        ?.value
        ?.toLowerCase() || '';
}

function searchableSelectPlaceholderOption(select) {
    const options = Array.from(select.options).filter((option) => !option.disabled && !option.hidden);
    const emptyOption = options.find((option) => option.value === '');

    if (emptyOption) {
        return emptyOption;
    }

    const isFilterControl = searchableSelectBinding(select).includes('filter')
        || select.id.toLowerCase().includes('filter')
        || Boolean(select.closest('.admin-filter-field, [data-mobile-table-filter-controls]'));

    return isFilterControl ? options.find((option) => option.value === 'all') || null : null;
}

function searchableSelectPlaceholderValue(select) {
    return searchableSelectPlaceholderOption(select)?.value ?? '';
}

function searchableSelectHasValue(select) {
    const placeholderOption = searchableSelectPlaceholderOption(select);

    return select.value !== '' && select.value !== placeholderOption?.value;
}

function createSearchableSelectChevron(inputMode = false) {
    const chevron = document.createElement('span');
    chevron.className = `searchable-select__chevron${inputMode ? ' searchable-select__chevron--input' : ''}`;
    chevron.setAttribute('aria-hidden', 'true');
    chevron.innerHTML = '<svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="m4 6 4 4 4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>';

    return chevron;
}

function selectedOptionText(select) {
    const option = select.options[select.selectedIndex];

    return option?.textContent?.trim() || select.dataset.placeholder || '';
}

function searchHintValue(select) {
    const targetId = select.dataset.searchHintTarget;

    if (!targetId || searchableSelectHasValue(select)) {
        return '';
    }

    const target = document.getElementById(targetId);

    return target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement
        ? target.value.trim()
        : '';
}

function closeSearchableSelect(wrapper) {
    wrapper.classList.remove('searchable-select--open');
    wrapper.querySelector('.searchable-select__panel')?.setAttribute('hidden', 'hidden');
    wrapper.querySelector('.searchable-select__button')?.setAttribute('aria-expanded', 'false');
    wrapper.querySelector('.searchable-select__search--trigger')?.setAttribute('aria-expanded', 'false');
}

function closeOtherSearchableSelects(currentWrapper) {
    document.querySelectorAll('.searchable-select--open').forEach((wrapper) => {
        if (wrapper !== currentWrapper) {
            closeSearchableSelect(wrapper);
        }
    });
}

function normalizeSearchableText(value) {
    return String(value ?? '')
        .trim()
        .replace(/\s+/gu, ' ')
        .replace(/[أإآٱ]/gu, 'ا')
        .replace(/ؤ/gu, 'و')
        .replace(/[ئى]/gu, 'ي')
        .replace(/ة/gu, 'ه')
        .replace(/ء/gu, '')
        .replace(/ـ/gu, '')
        .replace(/[\u064B-\u0652]/gu, '')
        .toLowerCase();
}

function buildSearchableSelectOptions(select, list, query = '') {
    const normalizedQuery = normalizeSearchableText(query);
    const options = Array.from(select.options);
    const placeholderOption = searchableSelectPlaceholderOption(select);
    let visibleCount = 0;

    list.replaceChildren();

    options.forEach((option) => {
        if (option.disabled || option.hidden) {
            return;
        }

        if (option === placeholderOption && select.dataset.hidePlaceholderOption !== 'false') {
            return;
        }

        const label = option.textContent.trim();
        const searchableText = normalizeSearchableText(`${label} ${option.dataset.search || ''}`);

        if (normalizedQuery && !searchableText.includes(normalizedQuery)) {
            return;
        }

        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'searchable-select__option';
        if (option.dataset.optionName !== undefined || option.dataset.optionNumber !== undefined) {
            item.classList.add('searchable-select__option--columns');

            const name = document.createElement('span');
            name.className = 'searchable-select__option-name';
            name.textContent = option.dataset.optionName || label || option.value;

            const number = document.createElement('span');
            number.className = 'searchable-select__option-number';
            number.textContent = option.dataset.optionNumber || '';

            item.append(name, number);
        } else {
            item.textContent = label || option.value;
        }
        item.dataset.value = option.value;
        item.setAttribute('role', 'option');
        item.setAttribute('aria-selected', option.selected ? 'true' : 'false');

        if (option.value === '') {
            item.classList.add('searchable-select__option--placeholder');
        }

        item.addEventListener('click', () => {
            if (select.dataset.financeCurrencyRequired === 'true' || select.dataset.searchSelectionRequired === 'true') {
                select.dataset.searchSelectionConfirmed = 'true';
            }

            select.value = option.value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            select.searchableSelectSync?.(true);
            closeSearchableSelect(item.closest('.searchable-select'));
        });

        list.appendChild(item);
        visibleCount += 1;
    });

    if (visibleCount === 0) {
        const empty = document.createElement('div');
        empty.className = 'searchable-select__empty';
        empty.textContent = select.dataset.emptyText || 'No results';
        list.appendChild(empty);
    }
}

function enhanceSearchableSelect(select) {
    if (
        !(select instanceof HTMLSelectElement)
        || select.multiple
        || select.dataset.searchable === 'false'
    ) {
        return;
    }

    const existingWrapper = select.nextElementSibling?.classList.contains('searchable-select')
        ? select.nextElementSibling
        : null;

    if (
        select.dataset.searchableBound === 'true'
        && select.dataset.searchableBindingVersion === SEARCHABLE_SELECT_BINDING_VERSION
        && existingWrapper
    ) {
        select.searchableSelectSync?.();

        return;
    }

    existingWrapper?.remove();

    if (select.dataset.searchableBound === 'true') {
        delete select.dataset.searchableBound;
        delete select.dataset.searchableBindingVersion;
        select.classList.remove('searchable-select__native');
        delete select.searchableSelectSync;
    }

    select.dataset.searchableBound = 'true';
    select.dataset.searchableBindingVersion = SEARCHABLE_SELECT_BINDING_VERSION;
    select.classList.add('searchable-select__native');

    const wrapper = document.createElement('div');
    wrapper.className = 'searchable-select';
    wrapper.setAttribute('wire:ignore', '');

    const searchInputMode = select.dataset.searchInput !== 'false';
    const openOnFocus = searchInputMode && select.dataset.openOnFocus !== 'false';
    const clearable = searchInputMode && select.dataset.clearable !== 'false';
    const financeCurrencyRequired = select.dataset.financeCurrencyRequired === 'true';
    const searchSelectionRequired = financeCurrencyRequired || select.dataset.searchSelectionRequired === 'true';
    const showChevron = select.dataset.showChevron !== 'false';
    let searchSelectionConfirmed = searchSelectionRequired && select.value !== '';

    if (searchInputMode) {
        wrapper.classList.add('searchable-select--input');
        wrapper.classList.toggle('searchable-select--clearable', clearable);
    }

    let button = null;
    let label = null;
    let clear = null;

    if (!searchInputMode) {
        button = document.createElement('button');
        button.type = 'button';
        button.className = 'searchable-select__button';
        button.setAttribute('aria-haspopup', 'listbox');
        button.setAttribute('aria-expanded', 'false');

        label = document.createElement('span');
        label.className = 'searchable-select__value';

        button.append(label);

        if (showChevron) {
            button.append(createSearchableSelectChevron());
        }
    }

    const panel = document.createElement('div');
    panel.className = 'searchable-select__panel';
    panel.setAttribute('hidden', 'hidden');

    const search = document.createElement('input');
    search.type = 'search';
    search.className = 'searchable-select__search';
    const placeholderOption = searchableSelectPlaceholderOption(select);
    search.placeholder = select.classList.contains('finance-amount-input__currency')
        ? ''
        : ('searchPlaceholder' in select.dataset
            ? select.dataset.searchPlaceholder
            : (placeholderOption?.textContent?.trim() || 'Search...'));
    search.autocomplete = 'off';

    if (searchInputMode) {
        search.classList.add('searchable-select__search--trigger');
        search.setAttribute('role', 'combobox');
        search.setAttribute('aria-autocomplete', 'list');
        search.setAttribute('aria-expanded', 'false');
    }

    const list = document.createElement('div');
    list.className = 'searchable-select__list';
    list.setAttribute('role', 'listbox');

    if (searchInputMode) {
        panel.append(list);
        wrapper.append(search);

        if (showChevron) {
            wrapper.append(createSearchableSelectChevron(true));
        }

        if (clearable) {
            clear = document.createElement('button');
            clear.type = 'button';
            clear.className = 'searchable-select__clear';
            clear.setAttribute('aria-label', select.dataset.clearLabel || 'Clear selection');
            clear.textContent = '×';
            wrapper.append(clear);
        }

        wrapper.append(panel);
    } else {
        panel.append(search, list);
        wrapper.append(button, panel);
    }
    select.insertAdjacentElement('afterend', wrapper);

    let optionsSignature = '';

    const updateRequiredSelectionValidity = (valid) => {
        if (!searchSelectionRequired) {
            return;
        }

        wrapper.classList.toggle('searchable-select--invalid', !valid);
        search.setAttribute('aria-invalid', valid ? 'false' : 'true');
        select.setCustomValidity(valid ? '' : 'Please select an option from the list.');

        const form = select.closest('form');

        if (!form) {
            return;
        }

        const formIsInvalid = Boolean(form.querySelector('.searchable-select--invalid'));
        form.toggleAttribute('data-search-selection-invalid', formIsInvalid);

        if (financeCurrencyRequired) {
            form.toggleAttribute('data-finance-currency-invalid', formIsInvalid);
        }

        form.querySelectorAll('button[type="submit"], input[type="submit"], button[wire\\:click*="saveAndNew"]').forEach((control) => {
            if (!(control instanceof HTMLButtonElement || control instanceof HTMLInputElement)) {
                return;
            }

            if (formIsInvalid) {
                if (!control.disabled) {
                    control.dataset.financeCurrencyDisabled = 'true';
                    control.disabled = true;
                    control.classList.add('finance-currency-save-disabled');
                }

                return;
            }

            if (control.dataset.financeCurrencyDisabled === 'true') {
                control.disabled = false;
                control.classList.remove('finance-currency-save-disabled');
                delete control.dataset.financeCurrencyDisabled;
            }
        });
    };

    const sync = (force = false) => {
        search.disabled = select.disabled;

        if (searchInputMode) {
            const selectedOption = select.options[select.selectedIndex];
            const hasSelectedValue = searchableSelectHasValue(select);
            const nextValue = hasSelectedValue ? selectedOption?.textContent.trim() || '' : '';

            if ((!hasSelectedValue || force || document.activeElement !== search) && search.value !== nextValue) {
                search.value = nextValue;
            }

            wrapper.classList.toggle(
                'searchable-select--selected',
                hasSelectedValue || search.value.trim() !== '',
            );
            wrapper.classList.toggle(
                'searchable-select--placeholder',
                !hasSelectedValue && search.value.trim() === '',
            );

            if (searchSelectionRequired && document.activeElement !== search && select.value !== '' && search.value === nextValue) {
                searchSelectionConfirmed = true;
                updateRequiredSelectionValidity(true);
            }
        } else {
            wrapper.classList.toggle('searchable-select--selected', searchableSelectHasValue(select));
            wrapper.classList.toggle('searchable-select--placeholder', !searchableSelectHasValue(select));
            const nextLabel = selectedOptionText(select) || placeholderOption?.textContent?.trim() || 'Select';

            if (label.textContent !== nextLabel) {
                label.textContent = nextLabel;
            }
        }

        const nextOptionsSignature = Array.from(select.options)
            .map((option) => `${option.value}\u0000${option.textContent}\u0000${option.dataset.search || ''}\u0000${option.dataset.optionName || ''}\u0000${option.dataset.optionNumber || ''}\u0000${option.disabled}\u0000${option.hidden}\u0000${option.selected}`)
            .join('\u0001');

        if (force || nextOptionsSignature !== optionsSignature) {
            optionsSignature = nextOptionsSignature;
            buildSearchableSelectOptions(select, list, search.value);
        }
    };

    select.searchableSelectSync = sync;
    sync();

    if (searchInputMode) {
        if (clear) {
            clear.addEventListener('pointerdown', (event) => {
                event.stopPropagation();
                suppressSearchableSelectOpen();
            });

            clear.addEventListener('mousedown', (event) => {
                event.preventDefault();
                event.stopPropagation();
                suppressSearchableSelectOpen();
            });

            clear.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                suppressSearchableSelectOpen();

                select.value = searchableSelectPlaceholderValue(select);
                search.value = '';
                select.dispatchEvent(new Event('change', { bubbles: true }));
                sync(true);
                closeSearchableSelect(wrapper);
                clear.blur();
            });
        }

        search.addEventListener('focus', () => {
            if (searchableSelectOpenIsSuppressed()) {
                closeSearchableSelect(wrapper);
                search.blur();

                return;
            }

            if (!openOnFocus) {
                return;
            }

            closeOtherSearchableSelects(wrapper);
            wrapper.classList.add('searchable-select--open');
            panel.removeAttribute('hidden');
            search.setAttribute('aria-expanded', 'true');
            buildSearchableSelectOptions(select, list, '');
            requestAnimationFrame(() => search.select());
        });

        search.addEventListener('input', () => {
            const hasQuery = search.value.trim() !== '';
            wrapper.classList.toggle('searchable-select--selected', hasQuery || searchableSelectHasValue(select));

            if (searchSelectionRequired) {
                searchSelectionConfirmed = false;
            }

            if (!hasQuery) {
                if (!clearable) {
                    sync(true);
                    buildSearchableSelectOptions(select, list, '');

                    return;
                }

                const placeholderValue = searchableSelectPlaceholderValue(select);

                if (select.value !== placeholderValue) {
                    select.value = placeholderValue;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                }

                if (openOnFocus && document.activeElement === search) {
                    closeOtherSearchableSelects(wrapper);
                    wrapper.classList.add('searchable-select--open');
                    panel.removeAttribute('hidden');
                    search.setAttribute('aria-expanded', 'true');
                    buildSearchableSelectOptions(select, list, '');
                } else {
                    closeSearchableSelect(wrapper);
                    search.setAttribute('aria-expanded', 'false');
                }

                return;
            }

            closeOtherSearchableSelects(wrapper);
            wrapper.classList.add('searchable-select--open');
            panel.removeAttribute('hidden');
            search.setAttribute('aria-expanded', 'true');
            buildSearchableSelectOptions(select, list, search.value);
        });

        wrapper.addEventListener('focusout', (event) => {
            if (event.relatedTarget instanceof Node && wrapper.contains(event.relatedTarget)) {
                return;
            }

            if (searchSelectionRequired) {
                const selectedOption = select.options[select.selectedIndex];
                const selectedLabel = selectedOption?.value ? selectedOption.textContent.trim() : '';
                const valid = searchSelectionConfirmed
                    && select.value !== ''
                    && search.value.trim() === selectedLabel;

                updateRequiredSelectionValidity(valid);
            }

            closeSearchableSelect(wrapper);
            search.setAttribute('aria-expanded', 'false');
        });
    } else {
        button.addEventListener('click', () => {
            const willOpen = !wrapper.classList.contains('searchable-select--open');
            closeOtherSearchableSelects(wrapper);

            wrapper.classList.toggle('searchable-select--open', willOpen);
            button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            panel.toggleAttribute('hidden', !willOpen);

            if (willOpen) {
                search.value = searchHintValue(select);
                buildSearchableSelectOptions(select, list, search.value);
                requestAnimationFrame(() => search.focus());
            }
        });

        search.addEventListener('input', () => buildSearchableSelectOptions(select, list, search.value));
    }

    select.addEventListener('change', () => {
        if (searchSelectionRequired && select.dataset.searchSelectionConfirmed === 'true') {
            searchSelectionConfirmed = select.value !== '';
            delete select.dataset.searchSelectionConfirmed;
            updateRequiredSelectionValidity(searchSelectionConfirmed);
        }

        sync();
    });
}

function initializeSearchableSelects() {
    document.querySelectorAll('select').forEach(enhanceSearchableSelect);
}

let searchableSelectInitializationTimer = null;

function scheduleSearchableSelectInitialization() {
    if (searchableSelectInitializationTimer) {
        window.clearTimeout(searchableSelectInitializationTimer);
    }

    window.requestAnimationFrame(() => {
        initializeSearchableSelects();
    });

    searchableSelectInitializationTimer = window.setTimeout(() => {
        searchableSelectInitializationTimer = null;
        initializeSearchableSelects();
    }, 160);
}

window.initializeSearchableSelects = initializeSearchableSelects;

document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element) || event.target.closest('.searchable-select')) {
        return;
    }

    document.querySelectorAll('.searchable-select--open').forEach(closeSearchableSelect);
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        document.querySelectorAll('.searchable-select--open').forEach(closeSearchableSelect);
    }
});

document.addEventListener('DOMContentLoaded', initializeSearchableSelects);
document.addEventListener('livewire:navigated', initializeSearchableSelects);
document.addEventListener('livewire:initialized', () => {
    scheduleSearchableSelectInitialization();

    window.Livewire?.hook('morph.updated', ({ el }) => {
        if (
            el instanceof HTMLSelectElement
            || el.matches?.('.searchable-select')
            || el.querySelector?.('select')
        ) {
            scheduleSearchableSelectInitialization();
        }
    });

    window.Livewire?.hook('morph.added', ({ el }) => {
        if (el instanceof HTMLSelectElement || el.querySelector?.('select')) {
            scheduleSearchableSelectInitialization();
        }
    });
});

const searchableSelectObserver = new MutationObserver((mutations) => {
    const shouldInitialize = mutations.some((mutation) => {
        if (mutation.target instanceof HTMLSelectElement) {
            return true;
        }

        return Array.from(mutation.addedNodes).some((node) => {
            return node instanceof Element && (node.matches('select') || node.querySelector('select'));
        });
    });

    if (shouldInitialize) {
        scheduleSearchableSelectInitialization();
    }
});

if (document.body) {
    searchableSelectObserver.observe(document.body, { childList: true, subtree: true });
} else {
    document.addEventListener('DOMContentLoaded', () => {
        searchableSelectObserver.observe(document.body, { childList: true, subtree: true });
    });
}

function restoreNativeDateInput(input) {
    if (!(input instanceof HTMLInputElement) || input.type !== 'date') return;

    const formattedWrapper = input.nextElementSibling?.classList.contains('formatted-date-input')
        ? input.nextElementSibling
        : null;
    const wasFormatted = input.dataset.dateFormatBound === 'true'
        || input.classList.contains('formatted-date-input__native');

    formattedWrapper?.remove();
    input.classList.remove('formatted-date-input__native');
    delete input.dataset.dateFormatBound;
    delete input.formattedDateSync;

    if (wasFormatted && input.getAttribute('tabindex') === '-1') {
        input.removeAttribute('tabindex');
    }

    if (wasFormatted && input.getAttribute('aria-hidden') === 'true') {
        input.removeAttribute('aria-hidden');
    }

    const syncDatePlaceholderState = () => {
        input.classList.toggle('date-input--empty', input.value === '');
    };

    syncDatePlaceholderState();

    if (input.dataset.datePlaceholderBound !== 'true') {
        input.dataset.datePlaceholderBound = 'true';
        input.addEventListener('input', syncDatePlaceholderState);
        input.addEventListener('change', syncDatePlaceholderState);
    }

    if (input.dataset.nativeDatePickerBound === 'true') return;

    input.dataset.nativeDatePickerBound = 'true';
    input.addEventListener('click', () => {
        if (input.disabled || input.readOnly || typeof input.showPicker !== 'function') return;

        try {
            input.showPicker();
        } catch (_error) {
            // The visible native control still provides its browser picker fallback.
        }
    });
}

function initializeNativeDateInputs(root = document) {
    if (root instanceof HTMLInputElement && root.type === 'date') {
        restoreNativeDateInput(root);
    }

    root.querySelectorAll?.('input[type="date"]').forEach(restoreNativeDateInput);
}

document.addEventListener('DOMContentLoaded', () => initializeNativeDateInputs());
document.addEventListener('livewire:navigated', () => initializeNativeDateInputs());
document.addEventListener('livewire:initialized', () => {
    window.Livewire?.hook('morph.updated', ({ el }) => initializeNativeDateInputs(el));
    window.Livewire?.hook('morph.added', ({ el }) => initializeNativeDateInputs(el));
});

const nativeDateInputObserver = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
            if (node instanceof Element) initializeNativeDateInputs(node);
        });
    });
});

if (document.body) {
    nativeDateInputObserver.observe(document.body, { childList: true, subtree: true });
} else {
    document.addEventListener('DOMContentLoaded', () => nativeDateInputObserver.observe(document.body, { childList: true, subtree: true }));
}

function createMobileFilterIcon() {
    const namespace = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(namespace, 'svg');
    const circle = document.createElementNS(namespace, 'circle');
    const path = document.createElementNS(namespace, 'path');

    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '2');
    svg.setAttribute('aria-hidden', 'true');
    svg.classList.add('mobile-table-action__icon');
    circle.setAttribute('cx', '11');
    circle.setAttribute('cy', '11');
    circle.setAttribute('r', '7');
    path.setAttribute('d', 'm20 20-3.5-3.5');
    svg.append(circle, path);

    return svg;
}

function createMobileTableActionIcon(kind) {
    const namespace = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(namespace, 'svg');
    const addPath = (definition) => {
        const path = document.createElementNS(namespace, 'path');
        path.setAttribute('d', definition);
        svg.append(path);
    };
    const addCircle = (cx, cy, radius) => {
        const circle = document.createElementNS(namespace, 'circle');
        circle.setAttribute('cx', cx);
        circle.setAttribute('cy', cy);
        circle.setAttribute('r', radius);
        svg.append(circle);
    };

    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '1.8');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    svg.classList.add('mobile-table-action__icon');

    switch (kind) {
        case 'add':
            addPath('M12 5v14M5 12h14');
            break;
        case 'export':
            addPath('M12 3v12m-4-4 4 4 4-4M5 17v3h14v-3');
            break;
        case 'print':
            addPath('M7 8V4h10v4M7 17H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2M7 14h10v7H7z');
            addCircle('18', '12', '.5');
            break;
        case 'pdf':
            addPath('M7 3h7l4 4v14H7zM14 3v5h4M9.5 16h5M9.5 12h5');
            break;
        case 'clear':
            addCircle('12', '12', '8');
            addPath('m9 9 6 6m0-6-6 6');
            break;
        case 'settings':
            addCircle('12', '12', '2.5');
            addPath('M19 12a7.6 7.6 0 0 0-.08-1l2-1.5-2-3.45-2.35.95a8 8 0 0 0-1.72-1L14.5 3h-5l-.35 3A8 8 0 0 0 7.43 7L5.08 6.05l-2 3.45L5.08 11a7.6 7.6 0 0 0 0 2l-2 1.5 2 3.45L7.43 17a8 8 0 0 0 1.72 1l.35 3h5l.35-3a8 8 0 0 0 1.72-1l2.35.95 2-3.45-2-1.5c.05-.33.08-.66.08-1Z');
            break;
        case 'view':
            addPath('M4 7h16M4 12h16M4 17h16');
            break;
        case 'search':
            addCircle('11', '11', '7');
            addPath('m20 20-4-4');
            break;
        default:
            addCircle('6', '12', '1');
            addCircle('12', '12', '1');
            addCircle('18', '12', '1');
    }

    return svg;
}

function mobileTableActionKind(action) {
    const descriptor = [
        action.textContent,
        action.getAttribute('title'),
        action.getAttribute('aria-label'),
        action.getAttribute('href'),
        action.getAttribute('wire:click'),
    ].filter(Boolean).join(' ').toLocaleLowerCase();

    if (/pdf/.test(descriptor)) return 'pdf';
    if (/print|طباعة/.test(descriptor)) return 'print';
    if (/export|download|تصدير|تنزيل/.test(descriptor)) return 'export';
    if (/create|add|new|opencreate|إنشاء|انشاء|إضافة|اضافة|جديد/.test(descriptor)) return 'add';
    if (/clear|reset|مسح|إلغاء التصفية|الغاء التصفية/.test(descriptor)) return 'clear';
    if (/setting|إعداد|اعداد/.test(descriptor)) return 'settings';
    if (/view|show|عرض/.test(descriptor)) return 'view';
    if (/search|filter|بحث|تصفية|فلتر/.test(descriptor)) return 'search';

    return 'more';
}

function initializeMobileTableHeaderActions(toolbar) {
    const actions = Array.from(toolbar.querySelectorAll('a, button')).filter((action) => (
        action.closest('.mobile-table-filter-criterion') === null
        && !action.hasAttribute('data-mobile-table-filter-open')
        && !action.hasAttribute('data-mobile-table-filter-close')
        && action.closest('.admin-toolbar__controls, [data-mobile-table-filter-controls]') === toolbar
    ));

    actions.forEach((action) => {
        const label = action.getAttribute('aria-label')
            || action.getAttribute('title')
            || action.textContent.trim();

        action.dataset.mobileTableHeaderAction = 'true';
        action.classList.add('mobile-table-header-action');
        action.setAttribute('aria-label', label);
        action.setAttribute('title', action.getAttribute('title') || label);

        // Livewire may restore the server-rendered button contents while leaving
        // the enhancement marker in place. Re-create the mobile icon whenever
        // that happens instead of returning the action to its desktop label.
        if (!action.querySelector('.mobile-table-action__icon')) {
            action.prepend(createMobileTableActionIcon(mobileTableActionKind(action)));
        }
    });
}

function initializeMobileTableFilters(root = document) {
    const toolbars = [];
    const selector = '.admin-grid-meta--controls .admin-toolbar__controls, [data-mobile-table-filter-controls]';

    if (root instanceof Element && root.matches(selector)) {
        toolbars.push(root);
    }
    root.querySelectorAll?.(selector).forEach((toolbar) => toolbars.push(toolbar));

    [...new Set(toolbars)].forEach((toolbar) => {
        const criteria = Array.from(toolbar.children).filter((child) => (
            child.matches('.admin-filter-field, input:not([type="hidden"]), select')
            || child.querySelector('input:not([type="hidden"]), select')
        ));

        toolbar.classList.add('mobile-table-header-controls');
        criteria.forEach((criterion) => criterion.classList.add('mobile-table-filter-criterion'));
        initializeMobileTableHeaderActions(toolbar);

        if (criteria.length === 0) return;

        const ownerId = toolbar.closest('[wire\\:id]')?.getAttribute('wire:id') || 'page';
        const criterionNames = criteria.map((criterion, index) => {
            const control = criterion.matches('input, select')
                ? criterion
                : criterion.querySelector('input, select');
            const model = Array.from(control?.attributes || [])
                .find((attribute) => attribute.name.startsWith('wire:model'))?.value;

            return control?.id || model || `${control?.tagName || 'filter'}-${index}`;
        });
        const filterKey = `${ownerId}:${criterionNames.join('|')}`;
        const shouldRemainOpen = toolbar.classList.contains('mobile-table-filters--open')
            || (
                document.body.classList.contains('mobile-table-filters-active')
                && document.body.dataset.mobileTableFilterOwner === filterKey
            );

        toolbar.dataset.mobileTableFilters = 'true';
        toolbar.dataset.mobileTableFilterKey = filterKey;
        toolbar.classList.add('mobile-table-filters');

        const isArabic = document.documentElement.dir === 'rtl' || document.documentElement.lang?.startsWith('ar');
        let trigger = Array.from(toolbar.children).find((child) => child.hasAttribute('data-mobile-table-filter-open'));
        if (!trigger) {
            trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.className = 'mobile-table-filter-trigger mobile-table-header-action';
            trigger.dataset.mobileTableFilterOpen = '';
            trigger.setAttribute('aria-label', isArabic ? 'بحث' : 'Search');
            const triggerLabel = document.createElement('span');
            triggerLabel.className = 'mobile-table-filter-trigger__label';
            triggerLabel.textContent = isArabic ? 'بحث' : 'Search';
            trigger.append(createMobileFilterIcon(), triggerLabel);
            toolbar.prepend(trigger);
        }

        let close = Array.from(toolbar.children).find((child) => child.hasAttribute('data-mobile-table-filter-close'));
        if (!close) {
            close = document.createElement('button');
            close.type = 'button';
            close.className = 'mobile-table-filter-close';
            close.dataset.mobileTableFilterClose = '';
            close.setAttribute('aria-label', isArabic ? 'إغلاق البحث' : 'Close search');
            close.textContent = '×';
            toolbar.append(close);
        }

        toolbar.classList.toggle('mobile-table-filters--open', shouldRemainOpen);
        trigger.setAttribute('aria-expanded', shouldRemainOpen ? 'true' : 'false');
    });
}

function openMobileTableFilters(toolbar) {
    if (!(toolbar instanceof Element)) return;

    toolbar.classList.add('mobile-table-filters--open');
    toolbar.querySelector('[data-mobile-table-filter-open]')?.setAttribute('aria-expanded', 'true');
    document.body.dataset.mobileTableFilterOwner = toolbar.dataset.mobileTableFilterKey || '';
    document.body.classList.add('mobile-table-filters-active');
}

function closeMobileTableFilters(toolbar) {
    if (toolbar instanceof Element) {
        toolbar.classList.remove('mobile-table-filters--open');
        toolbar.querySelector('[data-mobile-table-filter-open]')?.setAttribute('aria-expanded', 'false');
        initializeMobileTableHeaderActions(toolbar);
    }

    document.body.classList.remove('mobile-table-filters-active');
    delete document.body.dataset.mobileTableFilterOwner;

    // Closing a filter popup commonly coincides with a Livewire morph. Run once
    // after the current frame so its restored controls keep the mobile icons.
    window.requestAnimationFrame(() => initializeMobileTableFilters());
}

document.addEventListener('click', (event) => {
    const openButton = event.target.closest?.('[data-mobile-table-filter-open]');
    if (openButton) {
        const toolbar = openButton.closest('.mobile-table-filters');
        openMobileTableFilters(toolbar);

        return;
    }

    const closeButton = event.target.closest?.('[data-mobile-table-filter-close]');
    if (closeButton) {
        closeMobileTableFilters(closeButton.closest('.mobile-table-filters'));

        return;
    }

    const openToolbar = document.querySelector('.mobile-table-filters--open');
    if (openToolbar && !event.target.closest?.('.mobile-table-filters--open')) {
        closeMobileTableFilters(openToolbar);
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        closeMobileTableFilters(document.querySelector('.mobile-table-filters--open'));
    }
});

document.addEventListener('DOMContentLoaded', () => initializeMobileTableFilters());
document.addEventListener('livewire:navigating', () => closeMobileTableFilters(document.querySelector('.mobile-table-filters--open')));
document.addEventListener('livewire:navigated', () => {
    closeMobileTableFilters(document.querySelector('.mobile-table-filters--open'));
    initializeMobileTableFilters();
});
document.addEventListener('livewire:initialized', () => {
    window.Livewire?.hook('morph.updated', ({ el }) => {
        initializeMobileTableFilters(el);
        window.requestAnimationFrame(() => initializeMobileTableFilters());
    });
    window.Livewire?.hook('morph.added', ({ el }) => {
        initializeMobileTableFilters(el);
        window.requestAnimationFrame(() => initializeMobileTableFilters());
    });
});

const mobileTableFilterObserver = new MutationObserver((mutations) => {
    const selector = '.admin-grid-meta--controls .admin-toolbar__controls, [data-mobile-table-filter-controls]';
    const shouldInitialize = mutations.some((mutation) => {
        if (mutation.target instanceof Element && (
            mutation.target.matches(selector)
            || mutation.target.closest(selector)
        )) {
            return true;
        }

        return Array.from(mutation.addedNodes).some((node) => node instanceof Element && (
            node.matches(selector)
            || node.querySelector(selector)
        ));
    });

    if (shouldInitialize) {
        initializeMobileTableFilters();
    }
});

if (document.body) {
    mobileTableFilterObserver.observe(document.body, { childList: true, subtree: true });
} else {
    document.addEventListener('DOMContentLoaded', () => mobileTableFilterObserver.observe(document.body, { childList: true, subtree: true }));
}

const financeNumberInputSelector = 'input[data-thousand-separator]';

function normalizeFinanceNumberInputValue(value) {
    return String(value ?? '').replace(/[\s,\u00a0,\u066c,\u060c]/g, '');
}

function groupFinanceIntegerPart(value) {
    return value.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function formatFinanceNumberInputValue(value) {
    const initialValue = String(value ?? '').trim();

    if (['', '-', '.', '-.'].includes(initialValue)) {
        return initialValue;
    }

    let normalizedValue = normalizeFinanceNumberInputValue(initialValue);
    const isNegative = normalizedValue.startsWith('-');

    normalizedValue = normalizedValue.replace(/^-/, '');

    const hasDecimalPoint = normalizedValue.includes('.');
    const hasTrailingDecimalPoint = normalizedValue.endsWith('.');
    const [rawInteger = '', ...rawDecimalParts] = normalizedValue.split('.');
    const integerPart = rawInteger.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
    const decimalPart = rawDecimalParts.join('').replace(/\D/g, '');

    if (integerPart === '' && decimalPart === '') {
        return isNegative ? '-' : '';
    }

    const formattedInteger = groupFinanceIntegerPart(integerPart === '' ? '0' : integerPart);
    const formattedDecimal = hasDecimalPoint || hasTrailingDecimalPoint ? `.${decimalPart}` : '';

    return `${isNegative ? '-' : ''}${formattedInteger}${formattedDecimal}`;
}

function formatFinanceNumberInput(input) {
    const previousValue = input.value;
    const formattedValue = formatFinanceNumberInputValue(previousValue);

    if (previousValue === formattedValue) {
        return;
    }

    const cursorFromEnd = previousValue.length - (input.selectionStart ?? previousValue.length);
    input.value = formattedValue;

    if (document.activeElement === input && input.selectionStart !== null) {
        const nextCursor = Math.max(formattedValue.length - cursorFromEnd, 0);

        try {
            input.setSelectionRange(nextCursor, nextCursor);
        } catch (_error) {
            // Some mobile keyboards do not allow selection changes while composing.
        }
    }
}

function initializeFinanceNumberInputs() {
    document.querySelectorAll(financeNumberInputSelector).forEach((input) => {
        if (input instanceof HTMLInputElement) {
            formatFinanceNumberInput(input);
        }
    });
}

function scheduleFinanceNumberInitialization() {
    window.requestAnimationFrame(initializeFinanceNumberInputs);

    [80, 220, 500].forEach((delay) => {
        window.setTimeout(initializeFinanceNumberInputs, delay);
    });
}

document.addEventListener('input', (event) => {
    const input = event.target instanceof HTMLInputElement
        ? event.target.closest(financeNumberInputSelector)
        : null;

    if (input instanceof HTMLInputElement) {
        formatFinanceNumberInput(input);
    }
}, true);

document.addEventListener('DOMContentLoaded', scheduleFinanceNumberInitialization);
document.addEventListener('livewire:navigated', scheduleFinanceNumberInitialization);
document.addEventListener('livewire:initialized', scheduleFinanceNumberInitialization);
document.addEventListener('livewire:update', scheduleFinanceNumberInitialization);
document.addEventListener('livewire:commit', scheduleFinanceNumberInitialization);

const financeNumberInputObserver = new MutationObserver((mutations) => {
    const shouldInitialize = mutations.some((mutation) => {
        if (mutation.target instanceof HTMLInputElement && mutation.target.matches(financeNumberInputSelector)) {
            return true;
        }

        return Array.from(mutation.addedNodes).some((node) => {
            return node instanceof Element && (node.matches(financeNumberInputSelector) || node.querySelector(financeNumberInputSelector));
        });
    });

    if (shouldInitialize) {
        scheduleFinanceNumberInitialization();
    }
});

if (document.body) {
    financeNumberInputObserver.observe(document.body, { childList: true, subtree: true });
} else {
    document.addEventListener('DOMContentLoaded', () => {
        financeNumberInputObserver.observe(document.body, { childList: true, subtree: true });
    });
}

function initializePublicGallerySliders() {
    document.querySelectorAll('[data-public-gallery-slider]').forEach((slider) => {
        if (slider.dataset.bound === 'true') {
            return;
        }

        const slides = Array.from(slider.querySelectorAll('[data-public-gallery-slide]'));

        if (slides.length === 0) {
            return;
        }

        slider.dataset.bound = 'true';

        const dots = Array.from(slider.querySelectorAll('[data-public-gallery-dot]'));
        const next = slider.querySelector('[data-public-gallery-next]');
        const previous = slider.querySelector('[data-public-gallery-prev]');
        let activeIndex = Math.max(slides.findIndex((slide) => slide.classList.contains('is-active')), 0);
        let timer = null;

        const show = (index) => {
            activeIndex = (index + slides.length) % slides.length;

            slides.forEach((slide, slideIndex) => {
                slide.classList.toggle('is-active', slideIndex === activeIndex);
            });

            dots.forEach((dot, dotIndex) => {
                dot.classList.toggle('is-active', dotIndex === activeIndex);
            });
        };

        const stop = () => {
            if (timer) {
                window.clearInterval(timer);
                timer = null;
            }
        };

        const start = () => {
            stop();

            if (slides.length > 1) {
                timer = window.setInterval(() => show(activeIndex + 1), 5200);
            }
        };

        next?.addEventListener('click', () => {
            show(activeIndex + 1);
            start();
        });

        previous?.addEventListener('click', () => {
            show(activeIndex - 1);
            start();
        });

        dots.forEach((dot, dotIndex) => {
            dot.addEventListener('click', () => {
                show(dotIndex);
                start();
            });
        });

        slider.addEventListener('mouseenter', stop);
        slider.addEventListener('mouseleave', start);
        show(activeIndex);
        start();
    });
}

document.addEventListener('DOMContentLoaded', initializePublicGallerySliders);
document.addEventListener('livewire:navigated', initializePublicGallerySliders);

function adminToastRegion() {
    let region = document.getElementById('admin-toast-region');

    if (region) {
        return region;
    }

    region = document.createElement('div');
    region.id = 'admin-toast-region';
    region.className = 'admin-toast-region';
    region.setAttribute('aria-live', 'polite');
    region.setAttribute('aria-atomic', 'true');
    document.body.appendChild(region);

    return region;
}

let lastAdminToastText = '';
let lastAdminToastAt = 0;

function showAdminToast(message, type = 'success') {
    const text = String(message || '').trim();
    const now = Date.now();

    if (!text) {
        return;
    }

    if (text === lastAdminToastText && now - lastAdminToastAt < 700) {
        return;
    }

    lastAdminToastText = text;
    lastAdminToastAt = now;

    const toast = document.createElement('div');
    toast.className = `admin-toast admin-toast--${type}`;
    toast.setAttribute('role', 'status');
    toast.textContent = text;

    adminToastRegion().appendChild(toast);

    requestAnimationFrame(() => {
        toast.classList.add('is-visible');
    });

    window.setTimeout(() => {
        toast.classList.remove('is-visible');
        window.setTimeout(() => toast.remove(), 220);
    }, 3600);
}

function handleQuickAttendanceScanSucceeded(event) {
    const detail = Array.isArray(event) ? event[0] : event?.detail ?? event;

    showAdminToast(detail?.message, 'success');
}

document.addEventListener('quick-attendance-scan-succeeded', handleQuickAttendanceScanSucceeded);

document.addEventListener('livewire:init', () => {
    window.Livewire?.on?.('quick-attendance-scan-succeeded', handleQuickAttendanceScanSucceeded);
});

function initializeQuickAttendanceScanners() {
    document.querySelectorAll('[data-quick-attendance-scanner]').forEach((root) => {
        if (!(root instanceof HTMLElement) || root.dataset.bound === 'true') {
            return;
        }

        root.dataset.bound = 'true';

        const video = root.querySelector('[data-quick-attendance-video]');
        const message = root.querySelector('[data-quick-attendance-message]');
        const input = root.querySelector('#quick-attendance-scan');
        const startButton = root.querySelector('[data-quick-attendance-start]');
        const stopButton = root.querySelector('[data-quick-attendance-stop]');
        let stream = null;
        let detector = null;
        let scanning = false;
        let lastValue = '';
        let lastSeenAt = 0;
        let lastFrameScanAt = 0;
        let qrCanvas = null;
        let qrContext = null;

        const messageText = (key, fallback = '') => root.dataset[key] || fallback;

        const setMessage = (text) => {
            if (message) {
                message.textContent = text;
            }
        };

        const component = () => {
            const componentRoot = root.closest('[wire\\:id]');
            const componentId = componentRoot?.getAttribute('wire:id');

            return componentId && window.Livewire ? window.Livewire.find(componentId) : null;
        };

        const stop = () => {
            scanning = false;

            if (stream) {
                stream.getTracks().forEach((track) => track.stop());
                stream = null;
            }

            if (video instanceof HTMLVideoElement) {
                video.srcObject = null;
            }

            setMessage(messageText('cameraIdle'));
        };

        const submitValue = async (value) => {
            const normalizedValue = String(value || '').trim();
            const now = Date.now();

            if (!normalizedValue || (normalizedValue === lastValue && now - lastSeenAt < 2200)) {
                return;
            }

            lastValue = normalizedValue;
            lastSeenAt = now;
            setMessage(messageText('cameraDetected'));

            if (input instanceof HTMLInputElement) {
                input.value = normalizedValue;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }

            const livewireComponent = component();

            if (!livewireComponent) {
                return;
            }

            try {
                await livewireComponent.set('scan_value', normalizedValue, false);
                await livewireComponent.call('scanStudent');
            } catch (_error) {
                setMessage(messageText('cameraError'));
            }
        };

        const decodeQrFromCanvas = () => {
            if (!(video instanceof HTMLVideoElement) || !video.videoWidth || !video.videoHeight) {
                return '';
            }

            qrCanvas ??= document.createElement('canvas');

            if (qrCanvas.width !== video.videoWidth || qrCanvas.height !== video.videoHeight) {
                qrCanvas.width = video.videoWidth;
                qrCanvas.height = video.videoHeight;
                qrContext = qrCanvas.getContext('2d', { willReadFrequently: true });
            }

            if (!qrContext) {
                return '';
            }

            qrContext.drawImage(video, 0, 0, qrCanvas.width, qrCanvas.height);

            const imageData = qrContext.getImageData(0, 0, qrCanvas.width, qrCanvas.height);
            const code = jsQR(imageData.data, imageData.width, imageData.height, {
                inversionAttempts: 'attemptBoth',
            });

            return code?.data || '';
        };

        const detectCode = async () => {
            if (!(video instanceof HTMLVideoElement)) {
                return '';
            }

            if (detector) {
                try {
                    const codes = await detector.detect(video);

                    if (codes.length > 0) {
                        return codes[0].rawValue || '';
                    }
                } catch (_error) {
                    detector = null;
                }
            }

            return decodeQrFromCanvas();
        };

        const scanLoop = async () => {
            if (!scanning || !(video instanceof HTMLVideoElement) || video.readyState < 2) {
                if (scanning) {
                    requestAnimationFrame(scanLoop);
                }

                return;
            }

            const now = Date.now();

            if (now - lastFrameScanAt > 140) {
                lastFrameScanAt = now;

                try {
                    const value = await detectCode();

                    if (value) {
                        await submitValue(value);
                    }
                } catch (_error) {
                    setMessage(messageText('cameraError'));
                }
            }

            if (scanning) {
                requestAnimationFrame(scanLoop);
            }
        };

        const startCameraStream = async () => {
            try {
                return await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: { ideal: 'environment' },
                        width: { ideal: 1280 },
                        height: { ideal: 720 },
                    },
                });
            } catch (_error) {
                return navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
            }
        };

        startButton?.addEventListener('click', async () => {
            if (!navigator.mediaDevices?.getUserMedia) {
                setMessage(messageText('cameraNotSupported'));
                input?.focus();

                return;
            }

            try {
                detector = null;

                if ('BarcodeDetector' in window) {
                    try {
                        detector = new window.BarcodeDetector({ formats: ['qr_code', 'code_39', 'code_128', 'ean_13', 'ean_8'] });
                    } catch (_error) {
                        detector = null;
                    }
                }

                stream = await startCameraStream();

                if (video instanceof HTMLVideoElement) {
                    video.srcObject = stream;
                    await video.play();
                }

                scanning = true;
                setMessage(messageText('cameraRunning'));
                requestAnimationFrame(scanLoop);
            } catch (_error) {
                setMessage(messageText('cameraError'));
            }
        });

        stopButton?.addEventListener('click', stop);
        root.quickAttendanceStop = stop;
    });
}

function stopQuickAttendanceScanners() {
    document.querySelectorAll('[data-quick-attendance-scanner]').forEach((root) => {
        if (typeof root.quickAttendanceStop === 'function') {
            root.quickAttendanceStop();
        }
    });
}

document.addEventListener('DOMContentLoaded', initializeQuickAttendanceScanners);
document.addEventListener('livewire:navigated', initializeQuickAttendanceScanners);
document.addEventListener('livewire:commit', initializeQuickAttendanceScanners);
document.addEventListener('livewire:navigating', stopQuickAttendanceScanners);

async function writeAdminCopyText(text) {
    if (!text) {
        return;
    }

    if (navigator.clipboard?.writeText) {
        try {
            await navigator.clipboard.writeText(text);

            return;
        } catch (_error) {
            // Fall through to the textarea fallback.
        }
    }

    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', 'readonly');
    textarea.style.position = 'fixed';
    textarea.style.top = '-9999px';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.focus();
    textarea.select();

    try {
        document.execCommand('copy');
    } finally {
        textarea.remove();
    }
}

document.addEventListener('admin-copy-text', (event) => {
    const text = typeof event.detail?.text === 'string'
        ? event.detail.text.trim()
        : '';

    if (!text) {
        return;
    }

    void writeAdminCopyText(text);
});

const activePdfUploads = new Map();
let pdfUploadListenersBound = false;

function livewireModelName(input) {
    const modelAttribute = Array.from(input.attributes).find((attribute) => (
        attribute.name === 'wire:model' || attribute.name.startsWith('wire:model.')
    ));

    return modelAttribute?.value || '';
}

function acceptsPdf(input) {
    return input instanceof HTMLInputElement
        && input.type === 'file'
        && (input.accept.toLowerCase().includes('pdf') || input.hasAttribute('data-pdf-upload'));
}

function selectedFilesIncludePdf(input) {
    return acceptsPdf(input) && Array.from(input.files || []).some((file) => (
        file.type.toLowerCase() === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf')
    ));
}

function pdfUploadStatus(input) {
    const model = livewireModelName(input);
    const parent = input.parentElement;
    const hasNativeIndicator = parent && Array.from(parent.querySelectorAll('[wire\\:loading]')).some((indicator) => (
        !model || indicator.getAttribute('wire:target')?.split(',').map((target) => target.trim()).includes(model)
    ));

    if (hasNativeIndicator) {
        return null;
    }

    const existingStatus = input.parentElement?.querySelector(`[data-pdf-upload-status-for="${CSS.escape(model)}"]`);

    if (existingStatus) {
        return existingStatus;
    }

    const status = document.createElement('span');
    status.className = 'pdf-upload-status';
    status.dataset.pdfUploadStatusFor = model;
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    status.hidden = true;

    const spinner = document.createElement('span');
    spinner.className = 'pdf-upload-spinner';
    spinner.setAttribute('aria-hidden', 'true');

    const label = document.createElement('span');
    label.textContent = document.body?.dataset.pdfUploadingLabel
        || (document.documentElement.lang.toLowerCase().startsWith('ar') ? 'جارٍ رفع ملف PDF…' : 'Uploading PDF…');

    status.append(spinner, label);
    input.insertAdjacentElement('afterend', status);

    return status;
}

function pdfSaveControls(form) {
    if (!(form instanceof HTMLFormElement)) {
        return [];
    }

    return Array.from(form.querySelectorAll('button, input[type="submit"]')).filter((control) => {
        if (control.matches('button[type="submit"], button:not([type]), input[type="submit"]')) {
            return true;
        }

        return /^(save|store|create|submit|import|finali[sz]e|update)/i.test(
            control.getAttribute('wire:click')?.trim() || '',
        );
    });
}

function formHasActivePdfUpload(form) {
    return Array.from(activePdfUploads.values()).some((activeForm) => activeForm === form);
}

function updatePdfUploadForm(form) {
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const uploading = formHasActivePdfUpload(form);
    form.toggleAttribute('data-pdf-upload-active', uploading);
    form.setAttribute('aria-busy', uploading ? 'true' : 'false');

    pdfSaveControls(form).forEach((control) => {
        if (uploading && !control.disabled) {
            control.dataset.pdfUploadDisabled = 'true';
            control.disabled = true;
            control.setAttribute('aria-disabled', 'true');
        } else if (!uploading && control.dataset.pdfUploadDisabled === 'true') {
            delete control.dataset.pdfUploadDisabled;
            control.disabled = false;
            control.removeAttribute('aria-disabled');
        }
    });
}

function setPdfUploadActive(input, active) {
    if (!acceptsPdf(input)) {
        return;
    }

    const previousForm = activePdfUploads.get(input);
    const form = input.closest('form');
    const status = pdfUploadStatus(input);

    if (active) {
        activePdfUploads.set(input, form);
        input.dataset.pdfUploadActive = 'true';
        input.setAttribute('aria-busy', 'true');

        if (status) {
            status.hidden = false;
        }
    } else {
        activePdfUploads.delete(input);
        delete input.dataset.pdfUploadActive;
        input.removeAttribute('aria-busy');

        if (status) {
            status.hidden = true;
        }
    }

    updatePdfUploadForm(previousForm);
    updatePdfUploadForm(form);
}

function initializePdfUploads() {
    document.querySelectorAll('input[type="file"]').forEach((input) => {
        if (acceptsPdf(input) && livewireModelName(input)) {
            pdfUploadStatus(input);
        }
    });

    if (pdfUploadListenersBound) {
        return;
    }

    pdfUploadListenersBound = true;

    document.addEventListener('change', (event) => {
        const input = event.target;

        if (acceptsPdf(input) && livewireModelName(input)) {
            setPdfUploadActive(input, selectedFilesIncludePdf(input));
        }
    }, true);

    document.addEventListener('livewire-upload-start', (event) => {
        if (selectedFilesIncludePdf(event.target)) {
            setPdfUploadActive(event.target, true);
        }
    });

    ['livewire-upload-finish', 'livewire-upload-error', 'livewire-upload-cancel'].forEach((eventName) => {
        document.addEventListener(eventName, (event) => setPdfUploadActive(event.target, false));
    });

    document.addEventListener('submit', (event) => {
        if (event.target instanceof HTMLFormElement && formHasActivePdfUpload(event.target)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);

    document.addEventListener('click', (event) => {
        const control = event.target instanceof Element ? event.target.closest('button, input[type="submit"]') : null;
        const form = control?.closest('form');

        if (control && formHasActivePdfUpload(form) && pdfSaveControls(form).includes(control)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);
}

document.addEventListener('DOMContentLoaded', initializePdfUploads);
document.addEventListener('livewire:navigated', initializePdfUploads);
document.addEventListener('livewire:commit', initializePdfUploads);
