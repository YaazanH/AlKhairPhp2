const adminConfirmState = {
    activeElement: null,
    componentId: null,
    method: null,
    params: [],
    form: null,
};

function parseLivewireAction(expression) {
    if (!expression) {
        return null;
    }

    let payload = null;

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

        Function('ctx', `with (ctx) { ${expression}; }`)(context);
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

    if (!modal || modal.dataset.bound === 'true') {
        return;
    }

    modal.dataset.bound = 'true';

    document.addEventListener('click', handleLivewireConfirm, true);
    document.addEventListener('submit', handleFormConfirm, true);

    accept?.addEventListener('click', confirmAdminAction);
    cancel?.addEventListener('click', closeAdminConfirm);

    closeButtons.forEach((element) => {
        element.addEventListener('click', closeAdminConfirm);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeAdminConfirm();
        }
    });
}

window.AdminConfirm = {
    close: closeAdminConfirm,
    open: openAdminConfirm,
};

document.addEventListener('DOMContentLoaded', registerAdminConfirmListeners);
