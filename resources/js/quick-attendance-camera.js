export function prepareInlineCameraVideo(video) {
    video.autoplay = true;
    video.muted = true;
    video.defaultMuted = true;
    video.playsInline = true;
    video.setAttribute('autoplay', '');
    video.setAttribute('muted', '');
    video.setAttribute('playsinline', '');
    video.setAttribute('webkit-playsinline', '');
}

export function cameraStreamConstraints(supportedConstraints = {}) {
    const supportsFacingMode = supportedConstraints.facingMode !== false;

    return [
        {
            video: supportsFacingMode
                ? {
                    facingMode: { ideal: 'environment' },
                    width: { ideal: 1280 },
                    height: { ideal: 720 },
                }
                : true,
            audio: false,
        },
        {
            video: supportsFacingMode ? { facingMode: { ideal: 'environment' } } : true,
            audio: false,
        },
        { video: true, audio: false },
    ];
}

export async function requestCameraStream(mediaDevices) {
    const supportedConstraints = typeof mediaDevices.getSupportedConstraints === 'function'
        ? mediaDevices.getSupportedConstraints()
        : {};
    let lastError;

    for (const constraints of cameraStreamConstraints(supportedConstraints)) {
        try {
            return await mediaDevices.getUserMedia(constraints);
        } catch (error) {
            lastError = error;

            if (['NotAllowedError', 'SecurityError'].includes(error?.name)) {
                throw error;
            }
        }
    }

    throw lastError || new Error('Camera stream is unavailable.');
}

function waitForVideoEvent(video, events, timeout) {
    return new Promise((resolve, reject) => {
        const cleanup = () => {
            window.clearTimeout(timer);
            events.forEach((eventName) => video.removeEventListener(eventName, onReady));
            video.removeEventListener('error', onError);
        };
        const onReady = () => {
            cleanup();
            resolve();
        };
        const onError = () => {
            cleanup();
            reject(video.error || new Error('Camera preview failed.'));
        };
        const timer = window.setTimeout(() => {
            cleanup();
            resolve();
        }, timeout);

        events.forEach((eventName) => video.addEventListener(eventName, onReady, { once: true }));
        video.addEventListener('error', onError, { once: true });
    });
}

export async function attachCameraStream(video, stream, timeout = 5000) {
    prepareInlineCameraVideo(video);
    video.srcObject = stream;

    if (video.readyState < 1) {
        await waitForVideoEvent(video, ['loadedmetadata'], timeout);
    }

    await video.play();

    if (video.readyState < 2) {
        await waitForVideoEvent(video, ['loadeddata', 'canplay', 'playing'], timeout);
    }
}
