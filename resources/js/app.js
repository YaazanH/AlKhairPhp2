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
        cancel: document.getElementById('admin-confirm-cancel'),
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
    const { accept, cancel, closeButtons, modal } = adminConfirmElements();

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
        cancel?.addEventListener('click', closeAdminConfirm);

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

function selectedOptionText(select) {
    const option = select.options[select.selectedIndex];

    return option?.textContent?.trim() || select.dataset.placeholder || '';
}

function searchHintValue(select) {
    const targetId = select.dataset.searchHintTarget;

    if (!targetId || select.value) {
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
}

function closeOtherSearchableSelects(currentWrapper) {
    document.querySelectorAll('.searchable-select--open').forEach((wrapper) => {
        if (wrapper !== currentWrapper) {
            closeSearchableSelect(wrapper);
        }
    });
}

function buildSearchableSelectOptions(select, list, query = '') {
    const normalizedQuery = query.trim().toLowerCase();
    const options = Array.from(select.options);
    let visibleCount = 0;

    list.replaceChildren();

    options.forEach((option) => {
        if (option.disabled || option.hidden) {
            return;
        }

        const label = option.textContent.trim();
        const searchableText = `${label} ${option.dataset.search || ''}`.toLowerCase();

        if (normalizedQuery && !searchableText.includes(normalizedQuery)) {
            return;
        }

        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'searchable-select__option';
        item.textContent = label || option.value;
        item.dataset.value = option.value;
        item.setAttribute('role', 'option');
        item.setAttribute('aria-selected', option.selected ? 'true' : 'false');

        if (option.value === '') {
            item.classList.add('searchable-select__option--placeholder');
        }

        item.addEventListener('click', () => {
            select.value = option.value;
            select.dispatchEvent(new Event('input', { bubbles: true }));
            select.dispatchEvent(new Event('change', { bubbles: true }));
            select.searchableSelectSync?.();
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

    if (select.dataset.searchableBound === 'true' && existingWrapper) {
        select.searchableSelectSync?.();

        return;
    }

    existingWrapper?.remove();

    if (select.dataset.searchableBound === 'true') {
        delete select.dataset.searchableBound;
        select.classList.remove('searchable-select__native');
        delete select.searchableSelectSync;
    }

    select.dataset.searchableBound = 'true';
    select.classList.add('searchable-select__native');

    const wrapper = document.createElement('div');
    wrapper.className = 'searchable-select';
    wrapper.setAttribute('wire:ignore', '');

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'searchable-select__button';
    button.setAttribute('aria-haspopup', 'listbox');
    button.setAttribute('aria-expanded', 'false');

    const label = document.createElement('span');
    label.className = 'searchable-select__value';

    const chevron = document.createElement('span');
    chevron.className = 'searchable-select__chevron';
    chevron.textContent = '⌄';

    button.append(label, chevron);

    const panel = document.createElement('div');
    panel.className = 'searchable-select__panel';
    panel.setAttribute('hidden', 'hidden');

    const search = document.createElement('input');
    search.type = 'search';
    search.className = 'searchable-select__search';
    search.placeholder = select.dataset.searchPlaceholder || 'Search...';
    search.autocomplete = 'off';

    const list = document.createElement('div');
    list.className = 'searchable-select__list';
    list.setAttribute('role', 'listbox');

    panel.append(search, list);
    wrapper.append(button, panel);
    select.insertAdjacentElement('afterend', wrapper);

    const sync = () => {
        label.textContent = selectedOptionText(select) || select.querySelector('option[value=""]')?.textContent?.trim() || 'Select';
        buildSearchableSelectOptions(select, list, search.value);
    };

    select.searchableSelectSync = sync;
    sync();

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
    select.addEventListener('change', sync);
}

function initializeSearchableSelects() {
    document.querySelectorAll('select').forEach(enhanceSearchableSelect);
}

function scheduleSearchableSelectInitialization() {
    window.requestAnimationFrame(() => {
        initializeSearchableSelects();
    });

    [120, 350, 800].forEach((delay) => {
        window.setTimeout(initializeSearchableSelects, delay);
    });
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
document.addEventListener('livewire:initialized', scheduleSearchableSelectInitialization);

document.addEventListener('click', (event) => {
    const trigger = event.target instanceof Element
        ? event.target.closest('[wire\\:click], [wire\\:submit], [data-searchable-refresh]')
        : null;

    if (trigger) {
        scheduleSearchableSelectInitialization();
    }
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
