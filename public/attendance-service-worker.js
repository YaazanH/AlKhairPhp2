const ASSET_CACHE = 'alkhair-attendance-assets-v1';
const PAGE_CACHE_PREFIX = 'alkhair-attendance-pages-v1-';
const META_CACHE = 'alkhair-attendance-meta-v1';
const USER_META_URL = '/__alkhair_offline_attendance_user__';

const attendancePagePatterns = [
    /^\/(?:attendance|student-attendance|teacher-attendance)\/?$/,
    /^\/attendance\/days\/(?:students|teachers)\/\d+\/?$/,
    /^\/student-attendance\/(?:groups|days)\/\d+\/?$/,
    /^\/teacher-attendance\/days\/\d+\/?$/,
];

function isAttendancePage(pathname) {
    return attendancePagePatterns.some((pattern) => pattern.test(pathname));
}

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

async function currentUserId() {
    const cache = await caches.open(META_CACHE);
    const response = await cache.match(USER_META_URL);

    return response ? response.text() : null;
}

async function setCurrentUser(userId) {
    const cache = await caches.open(META_CACHE);
    await cache.put(USER_META_URL, new Response(String(userId)));
}

async function clearPrivateCaches() {
    const names = await caches.keys();
    await Promise.all(names.filter((name) => name.startsWith(PAGE_CACHE_PREFIX)).map((name) => caches.delete(name)));
    await caches.delete(META_CACHE);
}

self.addEventListener('message', (event) => {
    if (event.data?.type === 'ATTENDANCE_SET_USER' && event.data.userId) {
        event.waitUntil((async () => {
            await setCurrentUser(event.data.userId);

            if (event.data.pageUrl) {
                const url = new URL(event.data.pageUrl);
                if (url.origin === self.location.origin && isAttendancePage(url.pathname)) {
                    await cacheAttendancePage(new Request(url, { credentials: 'include' }));
                }
            }
        })());
    }

    if (event.data?.type === 'ATTENDANCE_CLEAR_USER') {
        event.waitUntil(clearPrivateCaches().finally(() => event.ports[0]?.postMessage({ cleared: true })));
    }
});

async function cacheAttendancePage(request) {
    const userId = await currentUserId();
    const response = await fetch(request);

    const responseUrl = new URL(response.url);
    if (userId && response.ok && !response.redirected && response.type === 'basic' && isAttendancePage(responseUrl.pathname)) {
        const cache = await caches.open(`${PAGE_CACHE_PREFIX}${userId}`);
        await cache.put(request, response.clone());
    }

    return response;
}

async function attendancePageFallback(request) {
    try {
        return await cacheAttendancePage(request);
    } catch (_error) {
        const userId = await currentUserId();

        if (! userId) throw _error;

        const cache = await caches.open(`${PAGE_CACHE_PREFIX}${userId}`);
        const cached = await cache.match(request);

        if (cached) return cached;

        throw _error;
    }
}

async function assetFallback(request) {
    const cache = await caches.open(ASSET_CACHE);
    const cached = await cache.match(request);

    if (cached) return cached;

    const response = await fetch(request);
    if (response.ok && response.type === 'basic') await cache.put(request, response.clone());

    return response;
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    if (isAttendancePage(url.pathname)) {
        event.respondWith(attendancePageFallback(request));
        return;
    }

    if (['font', 'image', 'script', 'style'].includes(request.destination)) {
        event.respondWith(assetFallback(request));
    }
});
