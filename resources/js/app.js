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
    const confirmLabel = options.confirmLabel ?? modal.dataset.defaultConfirmLabel ?? 'Continue';
    accept.setAttribute('aria-label', confirmLabel);
    accept.setAttribute('title', confirmLabel);
    accept.dataset.modalActionForceKind = options.actionKind ?? 'approve';

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
        actionKind: modalActionKind(trigger) ?? 'approve',
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
        actionKind: event.submitter instanceof Element ? modalActionKind(event.submitter) ?? 'approve' : 'approve',
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

const SEARCHABLE_SELECT_BINDING_VERSION = '9';
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

function restoreSearchableSelectClear(clear) {
    if (!(clear instanceof HTMLButtonElement) || !clear.classList.contains('searchable-select__clear')) return;

    clear.replaceChildren('×');
    clear.dataset.modalActionIconIgnore = 'true';
    clear.classList.remove(
        'admin-icon-button',
        'admin-modal-action-button',
        'admin-icon-button--accent',
        'admin-icon-button--danger',
        'mobile-table-header-action',
        'mobile-table-header-action--native-icon',
    );
    clear.removeAttribute('data-modal-action-icon');
    clear.removeAttribute('data-mobile-table-header-action');
    clear.removeAttribute('title');
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
    const search = wrapper.querySelector('.searchable-select__search--trigger');
    search?.setAttribute('aria-expanded', 'false');
    search?.removeAttribute('aria-activedescendant');
    wrapper.querySelectorAll('.searchable-select__option--highlighted').forEach((option) => {
        option.classList.remove('searchable-select__option--highlighted');
    });
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

    // Avoid replaceChildren(), which is unreliable on the older WebKit
    // versions still used by some managed iPads and Macs.
    while (list.firstChild) {
        list.removeChild(list.firstChild);
    }

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
        item.tabIndex = -1;
        item.className = 'searchable-select__option';
        item.id = `searchable-select-option-${Math.random().toString(36).slice(2)}`;
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

        // Safari can move focus away from the search input before dispatching
        // the option's click. Keep focus in the combobox until the selection is
        // committed so focusout cannot hide the option underneath the pointer.
        item.addEventListener('mousedown', (event) => event.preventDefault());

        item.addEventListener('click', () => {
            if (select.dataset.financeCurrencyRequired === 'true' || select.dataset.searchSelectionRequired === 'true') {
                select.dataset.searchSelectionConfirmed = 'true';
            }

            select.value = option.value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            select.searchableSelectSync?.(true);

            const currentWrapper = select.nextElementSibling;

            if (currentWrapper?.classList.contains('searchable-select')) {
                closeSearchableSelect(currentWrapper);
            }
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

function searchableSelectOptionButtons(list) {
    return Array.from(list.querySelectorAll('.searchable-select__option'));
}

function highlightSearchableSelectOption(list, search, index) {
    const options = searchableSelectOptionButtons(list);

    if (options.length === 0) {
        search.removeAttribute('aria-activedescendant');

        return null;
    }

    const normalizedIndex = ((index % options.length) + options.length) % options.length;
    const highlighted = options[normalizedIndex];

    options.forEach((option) => {
        option.classList.toggle('searchable-select__option--highlighted', option === highlighted);
    });
    search.setAttribute('aria-activedescendant', highlighted.id);
    highlighted.scrollIntoView({ block: 'nearest' });

    return highlighted;
}

function focusNextInteractiveControl(current) {
    const root = current.closest('form') || document;
    const controls = Array.from(root.querySelectorAll('input, textarea, button, select, [tabindex]'))
        .filter((control) => control instanceof HTMLElement)
        .filter((control) => !control.matches(':disabled, [hidden], [tabindex="-1"]'))
        .filter((control) => !control.classList.contains('searchable-select__native'))
        .filter((control) => !control.classList.contains('searchable-select__clear'))
        .filter((control) => !control.closest('.searchable-select__panel'))
        .filter((control) => control.getClientRects().length > 0);
    const currentIndex = controls.indexOf(current);
    const next = currentIndex >= 0 ? controls[currentIndex + 1] : null;

    if (!(next instanceof HTMLElement)) {
        return false;
    }

    next.focus({ preventScroll: true });

    return true;
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
        restoreSearchableSelectClear(existingWrapper.querySelector('.searchable-select__clear'));
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
            restoreSearchableSelectClear(clear);
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

            // WebKit frequently reports a null relatedTarget for taps inside
            // the option list. Deferring the close lets that tap finish first.
            window.setTimeout(() => {
                if (document.activeElement instanceof Node && wrapper.contains(document.activeElement)) {
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
            }, 0);
        });

        search.addEventListener('keydown', (event) => {
            if (['ArrowDown', 'ArrowUp'].includes(event.key)) {
                event.preventDefault();
                event.stopPropagation();

                if (!wrapper.classList.contains('searchable-select--open')) {
                    closeOtherSearchableSelects(wrapper);
                    wrapper.classList.add('searchable-select--open');
                    panel.removeAttribute('hidden');
                    search.setAttribute('aria-expanded', 'true');
                    buildSearchableSelectOptions(select, list, search.value);
                }

                const options = searchableSelectOptionButtons(list);
                const currentIndex = options.findIndex((option) => option.classList.contains('searchable-select__option--highlighted'));
                const nextIndex = event.key === 'ArrowDown'
                    ? (currentIndex < 0 ? 0 : currentIndex + 1)
                    : (currentIndex < 0 ? options.length - 1 : currentIndex - 1);

                highlightSearchableSelectOption(list, search, nextIndex);

                return;
            }

            if (!['Enter', 'Tab'].includes(event.key) || event.shiftKey) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            const highlighted = list.querySelector('.searchable-select__option--highlighted');

            if (highlighted instanceof HTMLButtonElement) {
                highlighted.click();
            } else {
                closeSearchableSelect(wrapper);
            }

            window.requestAnimationFrame(() => {
                if (select.dataset.saveAndNewOnKeydown === 'true' && searchableSelectHasValue(select)) {
                    const action = select.closest('form')?.querySelector('[data-create-and-new-action]');

                    if (action instanceof HTMLButtonElement && search.dataset.saveAndNewPending !== 'true') {
                        search.dataset.saveAndNewPending = 'true';
                        action.click();

                        window.setTimeout(() => {
                            delete search.dataset.saveAndNewPending;
                        }, 1000);
                    }

                    return;
                }

                if (
                    select.dataset.focusNextSearchableOnTab
                    && focusSearchableSelectById(select.dataset.focusNextSearchableOnTab)
                ) {
                    return;
                }

                focusNextInteractiveControl(search);
            });
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

function focusSearchableSelectById(selectId) {
    if (typeof selectId !== 'string' || selectId === '') {
        return false;
    }

    const select = document.getElementById(selectId);

    if (!(select instanceof HTMLSelectElement)) {
        return false;
    }

    enhanceSearchableSelect(select);
    select.searchableSelectSync?.(true);

    const input = select.nextElementSibling?.querySelector('.searchable-select__search--trigger');

    if (!(input instanceof HTMLInputElement)) {
        return false;
    }

    input.focus({ preventScroll: true });

    return true;
}

function focusSearchableSelect(event) {
    const selectId = event?.detail?.id;

    window.requestAnimationFrame(() => focusSearchableSelectById(selectId));
    window.setTimeout(() => focusSearchableSelectById(selectId), 180);
}

window.addEventListener('focus-searchable-select', focusSearchableSelect);

function assessmentQuickScoreStudentNameInput() {
    const select = document.getElementById('assessment-student-entry');

    if (!(select instanceof HTMLSelectElement)) {
        return null;
    }

    enhanceSearchableSelect(select);
    select.searchableSelectSync?.(true);

    const wrapper = select.nextElementSibling;
    const input = wrapper?.classList.contains('searchable-select')
        ? wrapper.querySelector('.searchable-select__search--trigger')
        : null;

    return input instanceof HTMLInputElement ? input : null;
}

function focusAssessmentQuickScoreStudentName() {
    const input = assessmentQuickScoreStudentNameInput();

    if (!input) {
        return null;
    }

    input.focus({ preventScroll: true });
    input.select();

    return input;
}

function scheduleAssessmentQuickScoreStudentFocus() {
    let firstInput = null;

    window.requestAnimationFrame(() => {
        firstInput = focusAssessmentQuickScoreStudentName();
    });

    // Livewire's searchable-select morph recovery runs on a short timer. If
    // that recovery replaced the focused input, restore focus to the rebuilt
    // student-name control without stealing it after a deliberate user click.
    window.setTimeout(() => {
        const activeElement = document.activeElement;
        const mayRestoreFocus = !activeElement
            || activeElement === document.body
            || activeElement === firstInput
            || activeElement?.id === 'assessment-student-score';
        const focusNeedsRestoring = firstInput === null
            || !firstInput.isConnected
            || activeElement !== firstInput;

        if (mayRestoreFocus && focusNeedsRestoring) {
            focusAssessmentQuickScoreStudentName();
        }
    }, 180);
}

window.scheduleAssessmentQuickScoreStudentFocus = scheduleAssessmentQuickScoreStudentFocus;
window.addEventListener('assessment-quick-score-saved', scheduleAssessmentQuickScoreStudentFocus);

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

function formattedDateValue(value) {
    const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);

    return match ? `${match[3]}-${match[2]}-${match[1]}` : '';
}

function dateInputPlaceholder(input = null) {
    const specifiedPlaceholder = input instanceof HTMLInputElement
        ? input.dataset.datePlaceholder?.trim()
        : '';

    if (specifiedPlaceholder) return specifiedPlaceholder;

    return document.documentElement.lang.toLowerCase().startsWith('ar') ? 'التاريخ' : 'Date';
}

function isDateInputLayoutClass(className) {
    const normalizedClassName = className.replace(/^!/, '').replace(/^(?:sm|md|lg|xl|2xl):/, '');

    return /^(?:w-|min-w-|max-w-|m[trblxy]?-|self-|justify-self-|col-span-|row-span-|flex-|basis-|grow|shrink)/.test(normalizedClassName);
}

function syncFormattedDateInputAppearance(input, wrapper, display) {
    const internalClasses = ['formatted-date-input__native', 'date-input--empty', 'date-input--filled'];
    const inputClasses = Array.from(input.classList)
        .filter((className) => !internalClasses.includes(className));
    const layoutClasses = inputClasses.filter(isDateInputLayoutClass);
    const controlClasses = inputClasses.filter((className) => !isDateInputLayoutClass(className));

    wrapper.className = ['formatted-date-input', ...layoutClasses].join(' ');
    display.className = [...controlClasses, 'formatted-date-input__display'].join(' ');

    if (input.hasAttribute('data-flux-control')) {
        display.setAttribute('data-flux-control', '');
    } else {
        display.removeAttribute('data-flux-control');
    }

    if (input.getAttribute('aria-invalid') === 'true') {
        display.setAttribute('aria-invalid', 'true');
    } else {
        display.removeAttribute('aria-invalid');
    }
}

function createDatePickerIcon() {
    const namespace = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(namespace, 'svg');
    const outline = document.createElementNS(namespace, 'rect');
    const topLine = document.createElementNS(namespace, 'path');
    const bindingLine = document.createElementNS(namespace, 'path');

    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '1.8');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    svg.classList.add('formatted-date-input__calendar-icon');
    outline.setAttribute('x', '3');
    outline.setAttribute('y', '5');
    outline.setAttribute('width', '18');
    outline.setAttribute('height', '16');
    outline.setAttribute('rx', '2');
    topLine.setAttribute('d', 'M3 10h18');
    bindingLine.setAttribute('d', 'M8 3v4m8-4v4');
    svg.append(outline, topLine, bindingLine);

    return svg;
}

function createDateClearIcon() {
    const clear = document.createElement('span');

    clear.classList.add('formatted-date-input__clear-icon');
    clear.setAttribute('aria-hidden', 'true');
    clear.textContent = '×';

    return clear;
}

let activeNativeDateInput = null;

function closeActiveNativeDatePicker() {
    if (!(activeNativeDateInput instanceof HTMLInputElement)) return;

    activeNativeDateInput.blur();
    activeNativeDateInput = null;
}

function openNativeDatePicker(input) {
    if (input.disabled || input.readOnly) return;

    closeActiveNativeDatePicker();
    activeNativeDateInput = input;

    try {
        input.focus({ preventScroll: true });
    } catch (_error) {
        input.focus();
    }

    if (typeof input.showPicker === 'function') {
        try {
            input.showPicker();

            return;
        } catch (_error) {
            // Older Safari versions fall back to a trusted native click.
        }
    }

    input.click();
}

function enhanceDateInput(input) {
    if (!(input instanceof HTMLInputElement) || input.type !== 'date' || input.dataset.dateFormatNative === 'true') return;

    const existingWrapper = input.nextElementSibling?.classList.contains('formatted-date-input')
        ? input.nextElementSibling
        : null;

    if (input.dataset.dateFormatBound === 'true' && existingWrapper) {
        const existingDisplay = existingWrapper.querySelector('.formatted-date-input__display');

        if (existingDisplay instanceof HTMLInputElement) {
            syncFormattedDateInputAppearance(input, existingWrapper, existingDisplay);
        }

        input.formattedDateSync?.();

        return;
    }

    existingWrapper?.remove();
    input.dataset.dateFormatBound = 'true';
    input.classList.remove('date-input--empty', 'date-input--filled');
    input.classList.add('formatted-date-input__native');
    input.tabIndex = -1;
    input.setAttribute('aria-hidden', 'true');

    const wrapper = document.createElement('div');
    wrapper.className = 'formatted-date-input';
    wrapper.setAttribute('wire:ignore', '');

    const display = document.createElement('input');
    display.type = 'text';
    display.inputMode = 'none';
    display.autocomplete = 'off';
    display.readOnly = true;
    display.dir = 'ltr';
    display.placeholder = dateInputPlaceholder(input);
    display.className = 'formatted-date-input__display';
    display.setAttribute('aria-label', input.getAttribute('aria-label') || display.placeholder);

    const picker = document.createElement('button');
    picker.type = 'button';
    picker.tabIndex = -1;
    picker.className = 'formatted-date-input__picker';
    picker.setAttribute('aria-label', document.documentElement.lang.toLowerCase().startsWith('ar') ? 'اختيار التاريخ' : 'Choose date');
    picker.append(createDatePickerIcon(), createDateClearIcon());

    wrapper.append(display, picker);
    input.insertAdjacentElement('afterend', wrapper);
    syncFormattedDateInputAppearance(input, wrapper, display);

    const stopDateActionEvent = (event) => {
        event.preventDefault();
        event.stopPropagation();
    };
    const openPicker = (event) => {
        stopDateActionEvent(event);
        openNativeDatePicker(input);
    };
    const runPickerAction = (event) => {
        stopDateActionEvent(event);

        if (input.value === '') {
            openNativeDatePicker(input);

            return;
        }

        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        closeActiveNativeDatePicker();
    };
    const sync = () => {
        syncFormattedDateInputAppearance(input, wrapper, display);
        const isEmpty = input.value === '';
        display.value = formattedDateValue(input.value);
        display.placeholder = dateInputPlaceholder(input);
        display.disabled = input.disabled;
        display.readOnly = true;
        display.classList.toggle('date-input--empty', isEmpty);
        display.classList.toggle('date-input--filled', !isEmpty);
        picker.disabled = input.disabled || input.readOnly;
        picker.setAttribute('aria-label', isEmpty
            ? (document.documentElement.lang.toLowerCase().startsWith('ar') ? 'اختيار التاريخ' : 'Choose date')
            : (document.documentElement.lang.toLowerCase().startsWith('ar') ? 'مسح التاريخ' : 'Clear date'));
        picker.title = picker.getAttribute('aria-label');
        wrapper.classList.toggle('formatted-date-input--has-value', !isEmpty);
        wrapper.hidden = input.hidden;
    };

    input.formattedDateSync = sync;
    display.addEventListener('click', openPicker);
    display.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ' || event.key === 'ArrowDown') openPicker(event);
    });
    picker.addEventListener('click', runPickerAction);
    input.addEventListener('input', sync);
    input.addEventListener('change', () => {
        sync();

        if (activeNativeDateInput === input) closeActiveNativeDatePicker();
    });
    sync();
}

document.addEventListener('pointerdown', (event) => {
    if (!(activeNativeDateInput instanceof HTMLInputElement)) return;

    const wrapper = activeNativeDateInput.nextElementSibling;

    if (wrapper instanceof Element && event.target instanceof Node && wrapper.contains(event.target)) return;

    closeActiveNativeDatePicker();
}, true);

function cleanupOrphanedFormattedDateInputs() {
    document.querySelectorAll('.formatted-date-input').forEach((wrapper) => {
        const input = wrapper.previousElementSibling;

        if (!(input instanceof HTMLInputElement) || input.type !== 'date') wrapper.remove();
    });
}

function initializeFormattedDateInputs(root = document) {
    cleanupOrphanedFormattedDateInputs();

    if (root instanceof HTMLInputElement && root.type === 'date') enhanceDateInput(root);
    root.querySelectorAll?.('input[type="date"]').forEach(enhanceDateInput);
}

let formattedDateInitializationTimer = null;
function scheduleFormattedDateInitialization() {
    if (formattedDateInitializationTimer) window.clearTimeout(formattedDateInitializationTimer);
    window.requestAnimationFrame(() => initializeFormattedDateInputs());
    formattedDateInitializationTimer = window.setTimeout(() => {
        formattedDateInitializationTimer = null;
        initializeFormattedDateInputs();
    }, 160);
}

document.addEventListener('DOMContentLoaded', () => initializeFormattedDateInputs());
document.addEventListener('livewire:navigated', () => initializeFormattedDateInputs());
document.addEventListener('livewire:initialized', () => {
    scheduleFormattedDateInitialization();
    window.Livewire?.hook('morph.updated', ({ el }) => {
        if ((el instanceof HTMLInputElement && el.type === 'date') || el.querySelector?.('input[type="date"]')) {
            scheduleFormattedDateInitialization();
        }
    });
    window.Livewire?.hook('morph.added', ({ el }) => initializeFormattedDateInputs(el));
});

const formattedDateObserver = new MutationObserver((mutations) => {
    const datesChanged = mutations.some((mutation) => {
        return [...mutation.addedNodes, ...mutation.removedNodes].some((node) => {
            return node instanceof Element && (node.matches('input[type="date"], .formatted-date-input') || node.querySelector('input[type="date"]'));
        });
    });

    if (datesChanged) scheduleFormattedDateInitialization();
});

if (document.body) {
    formattedDateObserver.observe(document.body, { childList: true, subtree: true });
} else {
    document.addEventListener('DOMContentLoaded', () => formattedDateObserver.observe(document.body, { childList: true, subtree: true }));
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
        return path;
    };
    const addCircle = (cx, cy, radius) => {
        const circle = document.createElementNS(namespace, 'circle');
        circle.setAttribute('cx', cx);
        circle.setAttribute('cy', cy);
        circle.setAttribute('r', radius);
        svg.append(circle);
        return circle;
    };
    const addRect = (x, y, width, height, radius) => {
        const rect = document.createElementNS(namespace, 'rect');
        rect.setAttribute('x', x);
        rect.setAttribute('y', y);
        rect.setAttribute('width', width);
        rect.setAttribute('height', height);
        rect.setAttribute('rx', radius);
        svg.append(rect);
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
            addPath('M4 4.5h16v3.6l-6.25 6.1v3.25L10.5 20v-5.5L4 8.1V4.5');
            addCircle('8.65', '14.2', '4').classList.add('clear-filter-icon__badge');
            addPath('m6.95 12.5 3.4 3.4m0-3.4-3.4 3.4').classList.add('clear-filter-icon__mark');
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
        case 'edit':
            addPath('m4 20 4.2-1 10.7-10.7a2.1 2.1 0 0 0-3-3L5.2 16 4 20Z');
            break;
        case 'delete':
            addPath('M4 7h16M9 7V4h6v3m-8 0 1 13h8l1-13M10 11v5m4-5v5');
            break;
        case 'save':
            svg.setAttribute('viewBox', '300 280 720 720');
            svg.setAttribute('fill', 'currentColor');
            svg.setAttribute('stroke', 'currentColor');
            svg.setAttribute('stroke-width', '16');
            svg.setAttribute('stroke-linecap', 'round');
            svg.setAttribute('stroke-linejoin', 'round');
            svg.dataset.iconName = 'save';
            addPath('M385.8,337.3l453.7-.2c12.7,1.9,18.5,10.5,26.6,18.4,15.3,15.1,30.1,30.7,45,46,13.3,13.7,44.7,39.6,49,57,2.5,150.4.3,301.4,1.1,452-5.8,38-41.8,29.7-69.5,29.7-158.4.2-317.4.6-476,.8-7.7,0-16.3,0-23.8-.2-16.9-.4-29.4-16.2-29.6-32.4V371.5c1-17,5.8-29.8,23.8-34.2h-.3ZM484,354h-94.5c-2.4,0-8.7,5-9,8v552.1c.7,3.5,6.7,9,10,9h37.5v-237.5c0-8,15.7-21.3,24.5-19.5h401.1c7.9-.2,21.5,12.2,21.5,19.5v237.5h56.5c5.9,0,13-10.6,12.5-16.5l-.2-438.9c-.9-5.3-3.2-10.3-6.4-14.6l-90-92c-2.6-2.3-9.8-7-13-7h-18.5v182.5c0,.6-3.2,8-3.8,9.2-4.4,8.1-11.6,12.9-20.7,14.3h-280c-12.4.7-27.5-13.3-27.5-25.5v-180.5h0ZM799,354h-298c.7,1.5,1,2.7,1.1,4.4,1.3,52.8-.4,106.3-1,159,2.7,13.5-4.1,24.1,14.4,25.6,91.4-.5,183,.9,274.4-.7,3.8-.3,9.2-6.5,9.2-9.8v-178.5h-.1ZM858,682.9h-413v240h413v-240Z');
            addPath('M506.7,752.2l287.8-.2c11.2,1.3,11.9,16.5-1,18h-285c-11.4-1.4-12.7-14.6-1.8-17.8h0Z');
            addPath('M800.7,829.3c5.3,4.9,1.7,15.5-6.1,14.7h-288.1c-8.9-2.3-9.9-12.6-1.5-16.6l288.6-.5c2.2,0,5.5.9,7.1,2.4h0Z');
            addPath('M705.7,385.2c16.3,1,36-2.1,51.8-.2,5.2.6,6.1,2.6,6.5,7.5,3.3,35.9-2.6,76.7,0,113.1,0,6.5-3.8,8-9.5,8.5-10.4,1-33.8,1.1-44,0-4.2-.5-7.3-1.9-9-6l-.5-113.6c0-3.6,1.2-7.6,4.8-9.2h0ZM719,402v94h28v-94h-28Z');
            break;
        case 'close':
            addPath('M6 6l12 12M18 6 6 18');
            break;
        case 'approve':
            addCircle('12', '12', '8.5');
            addPath('m8.25 12 2.5 2.5 5-5');
            break;
        case 'decline':
            addCircle('12', '12', '8.5');
            addPath('m9 9 6 6m0-6-6 6');
            break;
        case 'copy':
            addRect('8', '8', '11', '12', '2');
            addPath('M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h2');
            break;
        case 'upload':
            addPath('M12 15.5V4m0 0L7.75 8.25M12 4l4.25 4.25M5 13v4.5A2.5 2.5 0 0 0 7.5 20h9a2.5 2.5 0 0 0 2.5-2.5V13');
            break;
        case 'open':
            addRect('4', '4', '16', '16', '3');
            addPath('m9 15 6-6m-5 0h5v5');
            break;
        case 'up':
            addPath('m6 14 6-6 6 6');
            break;
        case 'down':
            addPath('m6 10 6 6 6-6');
            break;
        case 'refresh':
            addPath('M20 6v5h-5M4 18v-5h5M6.1 9A7 7 0 0 1 18.5 7.5L20 11M4 13l1.5 3.5A7 7 0 0 0 17.9 15');
            break;
        case 'transfer':
            addPath('M4 8h14m0 0-3.5-3.5M18 8l-3.5 3.5M20 16H6m0 0 3.5-3.5M6 16l3.5 3.5');
            break;
        default:
            addCircle('6', '12', '1');
            addCircle('12', '12', '1');
            addCircle('18', '12', '1');
    }

    return svg;
}

function modalActionDescriptor(action) {
    const submitsClosestForm = action.matches('button[type="submit"], button:not([type])')
        && !action.hasAttribute('wire:click');
    const formAction = submitsClosestForm
        ? action.closest('form')?.getAttribute('wire:submit')
        : null;
    const attributes = Array.from(action.attributes)
        .filter((attribute) => attribute.name.startsWith('wire:') || attribute.name.startsWith('data-'))
        .map((attribute) => attribute.value);

    return [
        action.textContent,
        action.getAttribute('title'),
        action.getAttribute('aria-label'),
        action.getAttribute('href'),
        formAction,
        ...attributes,
    ].filter(Boolean).join(' ').replace(/\s+/g, ' ').trim().toLocaleLowerCase();
}

function modalActionKind(action) {
    const descriptor = modalActionDescriptor(action);

    if (/\bpdf\b/.test(descriptor)) return 'pdf';
    if (/print|طباعة/.test(descriptor)) return 'print';
    if (/download|export|تنزيل|تحميل|تصدير/.test(descriptor)) return 'export';
    if (/upload|import|رفع|استيراد/.test(descriptor)) return 'upload';
    if (/move.?up|تحريك.*أعلى|تحريك.*اعلى/.test(descriptor)) return 'up';
    if (/move.?down|تحريك.*أسفل|تحريك.*اسفل/.test(descriptor)) return 'down';
    if (/delete|remove|destroy|void|حذف|إزالة|ازالة/.test(descriptor)) return 'delete';
    if (/decline|reject|deactivate|رفض|تعطيل/.test(descriptor)) return 'decline';
    if (/save.?and.?close|حفظ.*(?:إغلاق|اغلاق)/.test(descriptor)) return 'save';
    if (/close|cancel|dismiss|إغلاق|اغلاق|إلغاء|الغاء/.test(descriptor)) return 'close';
    if (/copy|clone|duplicate|نسخ|تكرار/.test(descriptor)) return 'copy';
    if (/edit|تعديل/.test(descriptor)) return 'edit';
    if (/search|find|بحث/.test(descriptor)) return 'search';
    if (/clear|reset|مسح|إعادة ضبط|اعادة ضبط/.test(descriptor)) return 'clear';
    if (/setting|إعداد|اعداد/.test(descriptor)) return 'settings';
    if (/reactivate|refresh|regenerate|generate.?password|إعادة تفعيل|اعادة تفعيل|تحديث كلمة/.test(descriptor)) return 'refresh';
    if (/transfer|move.?money|تحويل/.test(descriptor)) return 'transfer';
    if (/create|add|new|إنشاء|انشاء|إضافة|اضافة|جديد/.test(descriptor)) return 'add';
    if (/view|show|details|preview|open|عرض|تفاصيل|معاينة|فتح/.test(descriptor)) return 'open';
    if (/scan|مسح.*ضوئي/.test(descriptor)) return 'search';
    if (/accept|approve|finali[sz]e|settle|promote|respond|send.?verification|mark|اعتماد|قبول|ترفيع|استجابة|تحقق|تسجيل|إنهاء|انهاء/.test(descriptor)) return 'approve';
    if (/save|update|submit|confirm|apply|post|finish|حفظ|تحديث|تأكيد|تاكيد|تطبيق|إنهاء|انهاء/.test(descriptor)) return 'save';
    if (action.classList.contains('pill-link--danger')) return 'delete';
    if (action.matches('button[type="submit"], button:not([type])') && action.closest('form[wire\\:submit]')) return 'save';

    return null;
}

function initializeAdminModalActionIcons(root = document) {
    const selector = '.admin-modal__body :is(button, a)';
    const actions = [];

    if (root instanceof Element && root.matches(selector)) actions.push(root);
    root.querySelectorAll?.(selector).forEach((action) => actions.push(action));

    actions.forEach((action) => {
        if (action.closest('.searchable-select')) {
            // Dropdown clear controls must remain the compact native ×. An
            // earlier modal pass may already have replaced one, so restore it
            // while excluding every searchable-select control from icon work.
            restoreSearchableSelectClear(action);

            return;
        }
        if (action.hasAttribute('data-modal-action-icon-ignore')) return;
        if (action.closest('[role="tablist"], [role="listbox"]')) return;
        if (action.classList.contains('admin-icon-button') && action.querySelector(':scope > svg')) return;

        const kind = action.dataset.modalActionForceKind || modalActionKind(action);

        if (!kind) return;

        const label = action.getAttribute('aria-label')
            || action.getAttribute('title')
            || action.textContent.replace(/\s+/g, ' ').trim();
        const isAccent = action.classList.contains('pill-link--accent');
        const isDanger = action.classList.contains('pill-link--danger')
            || action.classList.contains('admin-icon-button--danger')
            || /border-red|text-red/.test(action.className);
        const icon = createMobileTableActionIcon(kind);

        icon.classList.remove('mobile-table-action__icon');
        icon.classList.add('admin-modal-action__icon');
        action.replaceChildren(icon);
        action.classList.remove('pill-link', 'pill-link--compact', 'pill-link--accent', 'pill-link--danger');
        action.classList.add('admin-icon-button', 'admin-modal-action-button');
        if (isAccent) action.classList.add('admin-icon-button--accent');
        if (isDanger) action.classList.add('admin-icon-button--danger');
        action.dataset.modalActionIcon = kind;
        action.setAttribute('aria-label', label);
        action.setAttribute('title', action.getAttribute('title') || label);
    });
}

let adminModalActionObserverStarted = false;

function startAdminModalActionObserver() {
    initializeAdminModalActionIcons(document);

    if (adminModalActionObserverStarted || !document.body) return;

    const observer = new MutationObserver((mutations) => {
        const modalRoots = new Set();

        mutations.forEach((mutation) => {
            const target = mutation.target instanceof Element
                ? mutation.target.closest('.admin-modal')
                : null;

            if (target) modalRoots.add(target);

            mutation.addedNodes.forEach((node) => {
                if (!(node instanceof Element)) return;
                if (node.matches('.admin-modal')) modalRoots.add(node);
                node.querySelectorAll?.('.admin-modal').forEach((modal) => modalRoots.add(modal));
            });
        });

        modalRoots.forEach((modal) => initializeAdminModalActionIcons(modal));
    });

    observer.observe(document.body, { childList: true, subtree: true });
    adminModalActionObserverStarted = true;
}

document.addEventListener('DOMContentLoaded', startAdminModalActionObserver);
document.addEventListener('livewire:navigated', startAdminModalActionObserver);

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
        && action.closest('.admin-toolbar__controls, [data-mobile-table-filter-controls], .admin-toolbar') === toolbar
    ));

    actions.forEach((action) => {
        const label = action.getAttribute('aria-label')
            || action.getAttribute('title')
            || action.textContent.trim();

        action.dataset.mobileTableHeaderAction = 'true';
        action.classList.add('mobile-table-header-action');
        action.setAttribute('aria-label', label);
        action.setAttribute('title', action.getAttribute('title') || label);

        const nativeIcons = Array.from(action.querySelectorAll(':scope > svg:not(.mobile-table-action__icon)'));

        if (action.classList.contains('admin-icon-button') && nativeIcons.length > 0) {
            // Shared symbol buttons already ship with their final icon. Remove
            // responsive icons left by an earlier enhancement or Livewire
            // morph, and collapse any restored native duplicates to one.
            action.querySelectorAll(':scope > .mobile-table-action__icon').forEach((icon) => icon.remove());
            nativeIcons.slice(1).forEach((icon) => icon.remove());
            action.classList.add('mobile-table-header-action--native-icon');

            return;
        }

        action.classList.remove('mobile-table-header-action--native-icon');

        // Livewire may restore the server-rendered button contents while leaving
        // the enhancement marker in place. Re-create the mobile icon whenever
        // that happens instead of returning the action to its desktop label.
        if (!action.querySelector('.mobile-table-action__icon')) {
            action.prepend(createMobileTableActionIcon(mobileTableActionKind(action)));
        }
    });
}

let mobileTableFilterScrollY = null;

function isMobileTableFilterPopoverOpen(toolbar) {
    if (!(toolbar instanceof HTMLElement) || !toolbar.hasAttribute('popover')) return false;

    try {
        return toolbar.matches(':popover-open');
    } catch {
        return toolbar.dataset.mobileTableFilterTopLayer === 'true';
    }
}

function ensureMobileTableFilterBackdrop() {
    let backdrop = document.querySelector('[data-mobile-table-filter-backdrop]');

    if (!backdrop) {
        backdrop = document.createElement('div');
        backdrop.className = 'mobile-table-filter-backdrop';
        backdrop.dataset.mobileTableFilterBackdrop = '';
        backdrop.setAttribute('aria-hidden', 'true');
        document.body.append(backdrop);
    }

    return backdrop;
}

function lockMobileTableFilterViewport() {
    if (mobileTableFilterScrollY === null) {
        mobileTableFilterScrollY = window.scrollY;
        document.body.style.setProperty('--mobile-table-filter-scroll-y', `${mobileTableFilterScrollY}px`);
    }

    document.documentElement.classList.add('mobile-table-filters-active');
    document.body.classList.add('mobile-table-filters-active');
    ensureMobileTableFilterBackdrop();
}

function unlockMobileTableFilterViewport() {
    const scrollY = mobileTableFilterScrollY;

    document.documentElement.classList.remove('mobile-table-filters-active');
    document.body.classList.remove('mobile-table-filters-active');
    document.body.style.removeProperty('--mobile-table-filter-scroll-y');
    document.querySelector('[data-mobile-table-filter-backdrop]')?.remove();
    mobileTableFilterScrollY = null;

    if (scrollY !== null) {
        window.requestAnimationFrame(() => window.scrollTo(window.scrollX, scrollY));
    }
}

function presentMobileTableFiltersInTopLayer(toolbar) {
    if (!(toolbar instanceof HTMLElement)) return;

    toolbar.setAttribute('role', 'dialog');
    toolbar.setAttribute('aria-modal', 'true');
    toolbar.setAttribute(
        'aria-label',
        toolbar.querySelector('[data-mobile-table-filter-open]')?.getAttribute('aria-label') || 'Search',
    );

    // Safari makes fixed descendants of backdrop-filter elements relative to
    // that element. The browser top layer keeps this popup tied to the viewport
    // without moving it out of its Livewire component.
    if (typeof toolbar.showPopover !== 'function') {
        toolbar.dataset.mobileTableFilterTopLayer = 'fallback';

        return;
    }

    toolbar.setAttribute('popover', 'manual');

    try {
        if (!isMobileTableFilterPopoverOpen(toolbar)) {
            toolbar.showPopover();
        }
        toolbar.dataset.mobileTableFilterTopLayer = 'true';
    } catch {
        toolbar.removeAttribute('popover');
        toolbar.dataset.mobileTableFilterTopLayer = 'fallback';
    }
}

function dismissMobileTableFilterTopLayer(toolbar) {
    if (!(toolbar instanceof HTMLElement)) return;

    if (typeof toolbar.hidePopover === 'function' && isMobileTableFilterPopoverOpen(toolbar)) {
        try {
            toolbar.hidePopover();
        } catch {
            // Removing the attribute below also restores the toolbar in place.
        }
    }

    toolbar.removeAttribute('popover');
    toolbar.removeAttribute('role');
    toolbar.removeAttribute('aria-modal');
    toolbar.removeAttribute('aria-label');
    delete toolbar.dataset.mobileTableFilterTopLayer;
}

function initializeMobileTableFilters(root = document) {
    const toolbars = [];
    const selector = '.admin-grid-meta--controls .admin-toolbar__controls, [data-mobile-table-filter-controls], .surface-table > .admin-toolbar, .surface-panel > .admin-toolbar';
    const belongsToTableSurface = (toolbar) => (
        !toolbar.matches('.admin-toolbar')
        || toolbar.parentElement?.matches('.surface-table')
        || Boolean(toolbar.parentElement?.querySelector('table'))
    );

    if (root instanceof Element && root.matches(selector) && belongsToTableSurface(root)) {
        toolbars.push(root);
    }
    root.querySelectorAll?.(selector).forEach((toolbar) => {
        if (belongsToTableSurface(toolbar)) toolbars.push(toolbar);
    });

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
        const filterSurface = toolbar.closest('.surface-table, .surface-panel');

        if (shouldRemainOpen) {
            filterSurface?.classList.add('mobile-table-filter-surface--open');
            lockMobileTableFilterViewport();
            presentMobileTableFiltersInTopLayer(toolbar);
        } else if (!filterSurface?.querySelector('.mobile-table-filters--open')) {
            filterSurface?.classList.remove('mobile-table-filter-surface--open');
            dismissMobileTableFilterTopLayer(toolbar);
        }

        trigger.setAttribute('aria-expanded', shouldRemainOpen ? 'true' : 'false');
    });
}

function openMobileTableFilters(toolbar) {
    if (!(toolbar instanceof Element)) return;

    const openToolbar = document.querySelector('.mobile-table-filters--open');
    if (openToolbar && openToolbar !== toolbar) {
        closeMobileTableFilters(openToolbar);
    }

    toolbar.classList.add('mobile-table-filters--open');
    toolbar.closest('.surface-table, .surface-panel')
        ?.classList.add('mobile-table-filter-surface--open');
    toolbar.querySelector('[data-mobile-table-filter-open]')?.setAttribute('aria-expanded', 'true');
    document.body.dataset.mobileTableFilterOwner = toolbar.dataset.mobileTableFilterKey || '';
    lockMobileTableFilterViewport();
    presentMobileTableFiltersInTopLayer(toolbar);
}

function closeMobileTableFilters(toolbar) {
    if (toolbar instanceof Element) {
        dismissMobileTableFilterTopLayer(toolbar);
        toolbar.classList.remove('mobile-table-filters--open');
        toolbar.closest('.surface-table, .surface-panel')
            ?.classList.remove('mobile-table-filter-surface--open');
        toolbar.querySelector('[data-mobile-table-filter-open]')?.setAttribute('aria-expanded', 'false');
        initializeMobileTableHeaderActions(toolbar);
    }

    document.querySelectorAll('.mobile-table-filter-surface--open').forEach((surface) => {
        surface.classList.remove('mobile-table-filter-surface--open');
    });

    delete document.body.dataset.mobileTableFilterOwner;

    if (!document.querySelector('.mobile-table-filters--open')) {
        unlockMobileTableFilterViewport();
    }

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

function lockPdfUploadInput(input, state) {
    if (!acceptsPdf(input)) {
        return;
    }

    input.dataset.pdfUploadLockState = state;
    input.disabled = true;
    input.setAttribute('aria-disabled', 'true');
}

function unlockPdfUploadInput(input) {
    if (!acceptsPdf(input) || !input.dataset.pdfUploadLockState) {
        return;
    }

    delete input.dataset.pdfUploadLockState;
    input.disabled = false;
    input.removeAttribute('aria-disabled');
}

function clearPreviousPdfUploadErrors(input) {
    const model = livewireModelName(input);
    const scope = input.closest('form') || input.closest('[wire\\:id]');

    if (!model || !scope) {
        return;
    }

    scope.querySelectorAll('[data-pdf-upload-error-for]').forEach((error) => {
        if (error.dataset.pdfUploadErrorFor === model) {
            error.remove();
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

            if (input.dataset.pdfUploadLockState) {
                input.disabled = true;
                input.setAttribute('aria-disabled', 'true');
            }
        }
    });

    if (pdfUploadListenersBound) {
        return;
    }

    pdfUploadListenersBound = true;

    document.addEventListener('change', (event) => {
        const input = event.target;

        if (acceptsPdf(input) && livewireModelName(input)) {
            if ((input.files?.length || 0) > 0) {
                clearPreviousPdfUploadErrors(input);
            }

            const includesPdf = selectedFilesIncludePdf(input);

            if (!includesPdf) {
                unlockPdfUploadInput(input);
            }

            setPdfUploadActive(input, includesPdf);
        }
    }, true);

    document.addEventListener('livewire-upload-start', (event) => {
        if (selectedFilesIncludePdf(event.target)) {
            setPdfUploadActive(event.target, true);
            lockPdfUploadInput(event.target, 'uploading');
        }
    });

    document.addEventListener('livewire-upload-finish', (event) => {
        if (!selectedFilesIncludePdf(event.target)) {
            return;
        }

        setPdfUploadActive(event.target, false);
        lockPdfUploadInput(event.target, 'complete');
    });

    ['livewire-upload-error', 'livewire-upload-cancel'].forEach((eventName) => {
        document.addEventListener(eventName, (event) => {
            setPdfUploadActive(event.target, false);
            unlockPdfUploadInput(event.target);
        });
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

const curriculumResourceColumnRules = {
    index: { min: 64, max: 72 },
    name: { min: 212, max: 372 },
    author: { min: 128 },
    publisher: { min: 128 },
    edition: { min: 112 },
    year: { min: 52, max: 110 },
    book: { min: 80 },
    actions: { min: 72 },
};

const curriculumResourceColumnGrowWeights = {
    index: 0.05,
    name: 0.275,
    author: 0.16,
    publisher: 0.16,
    edition: 0.12,
    year: 0.055,
    book: 0.08,
    actions: 0.10,
};

let curriculumResourceColumnFrame = null;

function synchronizeCurriculumSubjectResourceColumns() {
    curriculumResourceColumnFrame = null;

    const tables = Array.from(document.querySelectorAll('[data-curriculum-subject-resource-grid]'));

    if (!tables.length) {
        return;
    }

    const columnNames = Object.keys(curriculumResourceColumnRules);
    const widths = columnNames.map((name) => curriculumResourceColumnRules[name].min);

    tables.forEach((table) => table.classList.add('curriculum-subject-resource-grid--measuring'));

    tables.forEach((table) => {
        table.querySelectorAll('tr').forEach((row) => {
            if (row.cells.length !== columnNames.length) {
                return;
            }

            Array.from(row.cells).forEach((cell, index) => {
                const rules = curriculumResourceColumnRules[columnNames[index]];
                const measuredWidth = Math.ceil(cell.getBoundingClientRect().width);
                const boundedWidth = rules.max ? Math.min(measuredWidth, rules.max) : measuredWidth;

                widths[index] = Math.max(widths[index], boundedWidth);
            });
        });
    });

    tables.forEach((table) => table.classList.remove('curriculum-subject-resource-grid--measuring'));

    const availableWidth = Math.max(...tables.map((table) => table.parentElement?.clientWidth || 0));
    const measuredTotal = widths.reduce((total, width) => total + width, 0);

    if (availableWidth > measuredTotal) {
        const extraWidth = availableWidth - measuredTotal;
        let distributedWidth = 0;

        columnNames.forEach((name, index) => {
            const addition = Math.floor(extraWidth * curriculumResourceColumnGrowWeights[name]);

            widths[index] += addition;
            distributedWidth += addition;
        });

        widths[columnNames.indexOf('name')] += extraWidth - distributedWidth;
    }

    const tableWidth = widths.reduce((total, width) => total + width, 0);

    tables.forEach((table) => {
        table.style.width = `${tableWidth}px`;
        table.style.minWidth = `${tableWidth}px`;

        columnNames.forEach((name, index) => {
            const column = table.querySelector(`[data-curriculum-resource-column="${name}"]`);

            if (column) {
                column.style.width = `${widths[index]}px`;
            }
        });
    });
}

function scheduleCurriculumSubjectResourceColumnSync() {
    if (curriculumResourceColumnFrame !== null) {
        window.cancelAnimationFrame(curriculumResourceColumnFrame);
    }

    curriculumResourceColumnFrame = window.requestAnimationFrame(synchronizeCurriculumSubjectResourceColumns);
}

document.addEventListener('DOMContentLoaded', scheduleCurriculumSubjectResourceColumnSync);
document.addEventListener('livewire:navigated', scheduleCurriculumSubjectResourceColumnSync);
window.addEventListener('resize', scheduleCurriculumSubjectResourceColumnSync, { passive: true });
document.addEventListener('livewire:initialized', () => {
    window.Livewire?.hook('morph.updated', ({ el }) => {
        if (el.matches?.('[data-curriculum-subject-resource-grid]') || el.querySelector?.('[data-curriculum-subject-resource-grid]')) {
            scheduleCurriculumSubjectResourceColumnSync();
        }
    });

    window.Livewire?.hook('morph.added', ({ el }) => {
        if (el.matches?.('[data-curriculum-subject-resource-grid]') || el.querySelector?.('[data-curriculum-subject-resource-grid]')) {
            scheduleCurriculumSubjectResourceColumnSync();
        }
    });
});
