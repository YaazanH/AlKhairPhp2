import { shouldRefreshMergedAttendance } from './offline-attendance-merge';

const DATABASE_NAME = 'alkhair-offline-attendance';
const DATABASE_VERSION = 1;
const OUTBOX_STORE = 'outbox';
const RETRY_MAX_DELAY = 30000;

let retryDelay = 1500;
let retryTimer = null;
let syncRunning = false;
let offlineStorageReady = typeof indexedDB !== 'undefined';

function openDatabase() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DATABASE_NAME, DATABASE_VERSION);
        request.onerror = () => reject(request.error);
        request.onsuccess = () => resolve(request.result);
        request.onupgradeneeded = () => {
            const database = request.result;
            if (!database.objectStoreNames.contains(OUTBOX_STORE)) {
                const store = database.createObjectStore(OUTBOX_STORE, { keyPath: 'key' });
                store.createIndex('user_id', 'user_id');
            }
        };
    });
}

async function withStore(mode, callback) {
    const database = await openDatabase();

    return new Promise((resolve, reject) => {
        const transaction = database.transaction(OUTBOX_STORE, mode);
        const store = transaction.objectStore(OUTBOX_STORE);
        let value;

        try {
            value = callback(store);
        } catch (error) {
            database.close();
            reject(error);
            return;
        }

        transaction.oncomplete = () => {
            database.close();
            resolve(value);
        };
        transaction.onerror = () => {
            database.close();
            reject(transaction.error);
        };
    });
}

function requestValue(request) {
    return new Promise((resolve, reject) => {
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

async function allOperations() {
    const database = await openDatabase();

    try {
        const transaction = database.transaction(OUTBOX_STORE, 'readonly');
        return await requestValue(transaction.objectStore(OUTBOX_STORE).getAll());
    } finally {
        database.close();
    }
}

function currentUserId() {
    return document.body?.dataset.currentUserId || '';
}

function attendanceRoot() {
    return document.querySelector('[data-offline-attendance-root]');
}

function syncConfiguration() {
    const root = attendanceRoot();

    return {
        conflictLabel: root?.dataset.syncConflictLabel || document.body?.dataset.attendanceSyncConflictLabel || '',
        url: root?.dataset.syncUrl || document.body?.dataset.attendanceSyncUrl || '',
    };
}

function operationKey(type, dayId, entityId) {
    return `${currentUserId()}:${type}:${dayId}:${entityId}`;
}

function operationId() {
    if (crypto.randomUUID) return crypto.randomUUID();

    return `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

function connectionIsPoor() {
    const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    if (!connection) return false;

    return ['slow-2g', '2g'].includes(connection.effectiveType)
        || (Number.isFinite(connection.rtt) && connection.rtt >= 1000)
        || (Number.isFinite(connection.downlink) && connection.downlink > 0 && connection.downlink < 0.7);
}

function rowFor(operation) {
    return document.querySelector(`[data-offline-attendance-row][data-attendance-type="${operation.type}"][data-attendance-day-id="${operation.day_id}"][data-attendance-entity-id="${operation.entity_id}"]`);
}

function setRowState(operation, state, message = '') {
    const row = rowFor(operation);
    if (!row) return;

    row.dataset.attendanceSyncState = state;
    row.dataset.attendanceSyncMessage = message;
}

async function updateSummary() {
    const root = attendanceRoot();
    if (!root) return;

    const userId = currentUserId();
    const operations = (await allOperations()).filter((operation) => operation.user_id === userId);
    const pending = operations.filter((operation) => operation.state !== 'conflict').length;
    const conflicts = operations.filter((operation) => operation.state === 'conflict').length;
    const state = root.querySelector('[data-attendance-sync-state]');
    if (!state) return;

    let label;

    if (conflicts > 0) {
        state.dataset.state = 'conflict';
        label = root.dataset.syncConflictLabel;
    } else if (pending > 0) {
        state.dataset.state = navigator.onLine ? (connectionIsPoor() ? 'poor' : 'pending') : 'offline';
        label = navigator.onLine
            ? (connectionIsPoor() ? root.dataset.syncPoorLabel : root.dataset.syncPendingLabel)
            : root.dataset.syncOfflineLabel;
    } else {
        state.dataset.state = navigator.onLine ? 'synced' : 'offline';
        label = navigator.onLine ? root.dataset.syncSyncedLabel : root.dataset.syncOfflineReadyLabel;
    }

    state.dataset.pendingCount = String(pending + conflicts);
    state.title = label || '';
    state.setAttribute('aria-label', label || '');
}

async function putOperation(operation) {
    await withStore('readwrite', (store) => store.put(operation));
    setRowState(operation, 'pending');
    await updateSummary();
}

async function deleteIfCurrent(operation) {
    const database = await openDatabase();

    try {
        const transaction = database.transaction(OUTBOX_STORE, 'readwrite');
        const store = transaction.objectStore(OUTBOX_STORE);
        const current = await requestValue(store.get(operation.key));
        if (current?.client_operation_id === operation.client_operation_id) store.delete(operation.key);
    } finally {
        database.close();
    }
}

async function markConflict(operation, message) {
    await withStore('readwrite', (store) => store.put({ ...operation, state: 'conflict', message }));
    setRowState(operation, 'conflict', message);
}

function scheduleSync(delay = 0) {
    window.clearTimeout(retryTimer);
    retryTimer = window.setTimeout(syncOperations, delay);
}

async function syncOperations() {
    const root = attendanceRoot();
    const configuration = syncConfiguration();
    if (!configuration.url || !navigator.onLine || syncRunning) {
        await updateSummary();
        return;
    }

    const userId = currentUserId();
    const operations = (await allOperations())
        .filter((operation) => operation.user_id === userId && operation.state !== 'conflict')
        .sort((first, second) => first.changed_at.localeCompare(second.changed_at));

    if (!operations.length) {
        retryDelay = 1500;
        await updateSummary();
        return;
    }

    syncRunning = true;
    root?.querySelector('[data-attendance-sync-state]')?.setAttribute('data-state', 'syncing');
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), connectionIsPoor() ? 12000 : 8000);
    const syncedOperations = [];

    try {
        const response = await fetch(configuration.url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({ operations }),
            signal: controller.signal,
        });

        if (!response.ok) throw new Error(`Attendance sync failed (${response.status})`);

        const payload = await response.json();
        const byId = new Map(operations.map((operation) => [operation.client_operation_id, operation]));

        for (const result of payload.results || []) {
            const operation = byId.get(result.client_operation_id);
            if (!operation) continue;

            if (result.status === 'synced') {
                await deleteIfCurrent(operation);
                setRowState(operation, 'synced');
                syncedOperations.push(operation);
            } else {
                await markConflict(operation, result.message || configuration.conflictLabel);
            }
        }

        retryDelay = 1500;
    } catch (_error) {
        retryDelay = Math.min(retryDelay * 2, RETRY_MAX_DELAY);
        scheduleSync(retryDelay);
    } finally {
        window.clearTimeout(timeout);
        syncRunning = false;
        await updateSummary();

        if (syncedOperations.length) {
            const remainingOperations = (await allOperations())
                .filter((operation) => operation.user_id === userId);

            if (shouldRefreshMergedAttendance(syncedOperations, remainingOperations)) {
                window.setTimeout(() => {
                    window.dispatchEvent(new CustomEvent('attendance:offline-merged'));
                    if (window.Livewire?.navigate) {
                        window.Livewire.navigate(window.location.href);
                    } else {
                        window.location.reload();
                    }
                }, 0);
            } else if (remainingOperations.some((operation) => operation.state !== 'conflict')) {
                scheduleSync(0);
            }
        }
    }
}

async function queueFromElement(element, statusId) {
    const row = element.closest('[data-offline-attendance-row]');
    const root = attendanceRoot();
    if (!row || !root) return;

    const key = operationKey(row.dataset.attendanceType, row.dataset.attendanceDayId, row.dataset.attendanceEntityId);
    const existingOperation = (await allOperations()).find((operation) => operation.key === key);
    const operation = {
        key,
        client_operation_id: operationId(),
        user_id: currentUserId(),
        type: row.dataset.attendanceType,
        day_id: Number(row.dataset.attendanceDayId),
        entity_id: Number(row.dataset.attendanceEntityId),
        status_id: Number(statusId),
        changed_at: new Date().toISOString(),
        queued_offline: existingOperation?.queued_offline || !navigator.onLine,
        state: 'pending',
    };

    await putOperation(operation);
    scheduleSync(connectionIsPoor() ? 1200 : 0);
}

function handleAttendanceChange(event) {
    const select = event.target instanceof Element ? event.target.closest('[data-offline-attendance-select]') : null;
    if (!select || !offlineStorageReady) return;

    event.stopImmediatePropagation();
    queueFromElement(select, select.value);
}

function handleAttendanceClick(event) {
    const button = event.target instanceof Element ? event.target.closest('[data-offline-attendance-choice]') : null;
    if (!button || !offlineStorageReady) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    button.parentElement?.querySelectorAll('[data-offline-attendance-choice]').forEach((choice) => {
        choice.setAttribute('aria-pressed', choice === button ? 'true' : 'false');
    });
    queueFromElement(button, button.dataset.attendanceStatusId);
}

async function restoreQueuedStates() {
    const root = attendanceRoot();
    if (!root) return;

    const operations = (await allOperations()).filter((operation) => operation.user_id === currentUserId());
    operations.forEach((operation) => {
        const row = rowFor(operation);
        if (!row) return;

        const select = row.querySelector('[data-offline-attendance-select]');
        if (select) {
            select.value = String(operation.status_id);
            select.searchableSelectSync?.(true);
        }

        const choice = row.querySelector(`[data-offline-attendance-choice][data-attendance-status-id="${operation.status_id}"]`);
        choice?.parentElement?.querySelectorAll('[data-offline-attendance-choice]').forEach((button) => {
            button.setAttribute('aria-pressed', button === choice ? 'true' : 'false');
        });

        setRowState(operation, operation.state === 'conflict' ? 'conflict' : 'pending', operation.message || '');
    });

    await updateSummary();
    scheduleSync(connectionIsPoor() ? 1200 : 0);
}

async function initializeOfflineAttendance() {
    if (!offlineStorageReady) return;

    try {
        await openDatabase().then((database) => database.close());
    } catch (_error) {
        offlineStorageReady = false;
        return;
    }

    if ('serviceWorker' in navigator && window.isSecureContext) {
        try {
            await navigator.serviceWorker.register('/attendance-service-worker.js');
            const registration = await navigator.serviceWorker.ready;
            registration.active?.postMessage({
                type: 'ATTENDANCE_SET_USER',
                userId: currentUserId(),
                pageUrl: window.location.href,
            });
        } catch (_error) {
            // IndexedDB still provides resilient local-first marking while this page remains open.
        }
    }

    await restoreQueuedStates();
    scheduleSync(connectionIsPoor() ? 1200 : 0);
}

document.addEventListener('change', handleAttendanceChange, true);
document.addEventListener('click', handleAttendanceClick, true);
document.addEventListener('DOMContentLoaded', initializeOfflineAttendance);
document.addEventListener('livewire:navigated', initializeOfflineAttendance);
window.addEventListener('online', () => scheduleSync(0));
window.addEventListener('offline', updateSummary);
window.addEventListener('focus', () => scheduleSync(0));

const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
connection?.addEventListener?.('change', () => scheduleSync(0));
window.setInterval(() => {
    if (attendanceRoot() && navigator.onLine) scheduleSync(0);
}, 15000);

document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!form?.matches?.('form[action$="/logout"]') || form.dataset.offlineAttendanceCleared === 'true') return;

    event.preventDefault();
    event.stopImmediatePropagation();
    form.dataset.offlineAttendanceCleared = 'true';

    indexedDB.deleteDatabase(DATABASE_NAME);

    try {
        const registration = await navigator.serviceWorker?.ready;
        if (registration?.active) {
            await new Promise((resolve) => {
                const channel = new MessageChannel();
                const timeout = window.setTimeout(resolve, 700);
                channel.port1.onmessage = () => {
                    window.clearTimeout(timeout);
                    resolve();
                };
                registration.active.postMessage({ type: 'ATTENDANCE_CLEAR_USER' }, [channel.port2]);
            });
        }
    } finally {
        form.submit();
    }
}, true);
