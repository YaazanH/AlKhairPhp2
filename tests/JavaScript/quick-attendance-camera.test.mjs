import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    attachCameraStream,
    cameraStreamConstraints,
    prepareInlineCameraVideo,
    requestCameraStream,
} from '../../resources/js/quick-attendance-camera.js';

test('camera video is prepared for inline muted playback before iOS receives the stream', () => {
    const attributes = new Map();
    const video = {
        setAttribute(name, value) { attributes.set(name, value); },
    };

    prepareInlineCameraVideo(video);

    assert.equal(video.autoplay, true);
    assert.equal(video.muted, true);
    assert.equal(video.defaultMuted, true);
    assert.equal(video.playsInline, true);
    assert.equal(attributes.has('autoplay'), true);
    assert.equal(attributes.has('muted'), true);
    assert.equal(attributes.has('playsinline'), true);
    assert.equal(attributes.has('webkit-playsinline'), true);
});

test('inline playback is configured before the camera stream is attached', async () => {
    const order = [];
    const attributes = new Map();
    const video = {
        readyState: 2,
        setAttribute(name, value) { attributes.set(name, value); },
        set srcObject(value) {
            assert.equal(attributes.has('playsinline'), true);
            assert.equal(this.muted, true);
            order.push(['stream', value]);
        },
        async play() { order.push(['play']); },
    };
    const stream = { id: 'camera' };

    await attachCameraStream(video, stream);

    assert.deepEqual(order, [['stream', stream], ['play']]);
});

test('camera constraints prefer the rear camera and retain an unconstrained iOS fallback', () => {
    const attempts = cameraStreamConstraints({ facingMode: true });

    assert.deepEqual(attempts[0].video.facingMode, { ideal: 'environment' });
    assert.deepEqual(attempts[1], {
        video: { facingMode: { ideal: 'environment' } },
        audio: false,
    });
    assert.deepEqual(attempts.at(-1), { video: true, audio: false });
});

test('camera request retries unsupported constraints but does not repeat a denied permission prompt', async () => {
    const calls = [];
    const stream = { id: 'ios-camera' };
    const mediaDevices = {
        getSupportedConstraints: () => ({ facingMode: true }),
        getUserMedia: async (constraints) => {
            calls.push(constraints);
            if (calls.length === 1) {
                throw Object.assign(new Error('unsupported'), { name: 'OverconstrainedError' });
            }

            return stream;
        },
    };

    assert.equal(await requestCameraStream(mediaDevices), stream);
    assert.equal(calls.length, 2);

    let deniedCalls = 0;
    await assert.rejects(
        requestCameraStream({
            getUserMedia: async () => {
                deniedCalls += 1;
                throw Object.assign(new Error('denied'), { name: 'NotAllowedError' });
            },
        }),
        { name: 'NotAllowedError' },
    );
    assert.equal(deniedCalls, 1);
});
