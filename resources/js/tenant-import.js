const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content;

export function uploadChunks(bytes, chunkBytes) {
    if (!Number.isSafeInteger(bytes) || bytes < 1 || !Number.isSafeInteger(chunkBytes) || chunkBytes < 1) {
        throw new Error('Invalid upload size');
    }
    return Math.ceil(bytes / chunkBytes);
}

function bindUploads() {
    document.querySelectorAll('[data-tenant-import-upload]').forEach((form) => {
        if (form.dataset.bound) return;
        form.dataset.bound = 'true';
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const file = form.querySelector('[data-import-file]').files[0];
            const error = form.querySelector('[data-import-upload-error]');
            const button = form.querySelector('button[type="submit"], button');
            const progress = form.querySelector('progress');
            const label = form.querySelector('[data-import-upload-label]');
            error.hidden = true;
            if (!file) return;
            if (file.size > Number(form.dataset.maximumBytes)) {
                error.textContent = form.dataset.tooLarge;
                error.hidden = false;
                return;
            }
            if (form.dataset.expectedBytes && file.size !== Number(form.dataset.expectedBytes)) {
                error.textContent = form.dataset.failure;
                error.hidden = false;
                return;
            }
            button.disabled = true;
            form.querySelector('[data-import-upload-progress]').hidden = false;
            let operationUrl = form.dataset.operationUrl;
            const headers = { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' };
            try {
                if (!operationUrl) {
                    const response = await fetch(form.dataset.startUrl, {
                        method: 'POST', headers: { ...headers, 'Content-Type': 'application/json' },
                        body: JSON.stringify({ filename: file.name, bytes: file.size }),
                    });
                    const result = await response.json();
                    if (!response.ok) throw new Error(result.message || form.dataset.failure);
                    operationUrl = result.url;
                    form.dataset.operationUrl = operationUrl;
                    // Save a recoverable URL without navigating away from the selected File object.
                    window.history.replaceState({}, '', operationUrl);
                }
                const chunkBytes = Number(form.dataset.chunkBytes);
                let next = Number(form.dataset.nextChunk || 0);
                const total = uploadChunks(file.size, chunkBytes);
                const send = async (index) => {
                    const response = await fetch(`${operationUrl}/chunks/${index}`, {
                        method: 'PUT', headers: { ...headers, 'Content-Type': 'application/octet-stream' },
                        body: file.slice(index * chunkBytes, Math.min(file.size, (index + 1) * chunkBytes)),
                    });
                    if (!response.ok) throw new Error(form.dataset.failure);
                    return response.json();
                };
                // A resume must match the first persisted chunk, not merely the file's name and size.
                if (next > 0) await send(0);
                for (; next < total; next++) {
                    await send(next);
                    form.dataset.nextChunk = String(next + 1);
                    const percent = Math.floor(100 * Math.min(file.size, (next + 1) * chunkBytes) / file.size);
                    progress.value = percent;
                    if (label) label.textContent = `${percent}%`;
                }
                window.location.assign(operationUrl);
            } catch (exception) {
                error.textContent = exception.message || form.dataset.failure;
                error.hidden = false;
                button.disabled = false;
            }
        });
    });
}

function bindProgress() {
    document.querySelectorAll('[data-poll-url]').forEach((root) => {
        if (root.dataset.polling) return;
        root.dataset.polling = 'true';
        const update = async () => {
            if (!root.isConnected) return;
            try {
                const response = await fetch(root.dataset.pollUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
                if (response.status === 401 || response.status === 403 || response.status === 404) {
                    window.location.reload();
                    return;
                }
                if (!response.ok) throw new Error('Unavailable');
                const result = await response.json();
                if (result.status !== root.dataset.initialState) {
                    window.location.reload();
                    return;
                }
                root.querySelector('[data-import-phase]').textContent = result.phase;
                root.querySelector('[data-import-progress]').value = result.progress;
                root.querySelector('[data-import-poll-message]').textContent = result.label;
            } catch {
                // Keep the saved operation visible; retry transient disconnections.
                root.querySelector('[data-import-poll-message]').textContent = root.dataset.reconnecting || '';
            }
            window.setTimeout(update, 2500);
        };
        window.setTimeout(update, 1500);
    });
    document.querySelectorAll('[data-import-unlock]').forEach((form) => {
        if (form.dataset.bound) return;
        form.dataset.bound = 'true';
        form.addEventListener('submit', () => {
            form.querySelector('button').disabled = true;
            form.querySelector('[data-import-unlocking]').hidden = false;
        });
    });
}

if (typeof document !== 'undefined') {
    const initialize = () => { bindUploads(); bindProgress(); };
    document.addEventListener('DOMContentLoaded', initialize);
    document.addEventListener('livewire:navigated', initialize);
    if (document.readyState !== 'loading') initialize();
}
