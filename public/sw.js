// PWA 서비스워커 — 설치 가능성 확보 + 오프라인 폴백.
// 이 사이트의 데이터(가격·리딤코드·레이드)는 실시간성이 커서 공격적 캐싱은 하지 않는다:
//  - 페이지 이동(navigate): network-first, 실패 시 오프라인 폴백 페이지
//  - 빌드 에셋(/build/, 해시 파일명): cache-first (내용 불변이라 안전)
//  - 그 외(API 등): 서비스워커가 관여하지 않음(브라우저 기본 동작)
const VERSION = 'v5'; // 상태바용 모노크롬 배지 아이콘 적용 (가시 돋친 꽃 실루엣)
const SHELL_CACHE = `shell-${VERSION}`;
const ASSET_CACHE = `assets-${VERSION}`;
const OFFLINE_URL = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL_CACHE)
            .then((cache) => cache.addAll([OFFLINE_URL, '/images/pwa/icon-192.png']))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((key) => key !== SHELL_CACHE && key !== ASSET_CACHE)
                    .map((key) => caches.delete(key)),
            ))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    // 페이지 이동: 항상 네트워크 우선(실시간 데이터), 끊겼을 때만 오프라인 페이지
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match(OFFLINE_URL)),
        );
        return;
    }

    // Vite 빌드 에셋: 파일명에 해시가 박혀 내용이 불변 → cache-first
    const url = new URL(request.url);
    if (url.origin === self.location.origin && url.pathname.startsWith('/build/')) {
        event.respondWith(
            caches.match(request).then((hit) => hit ?? fetch(request).then((res) => {
                if (res.ok) {
                    const copy = res.clone();
                    caches.open(ASSET_CACHE).then((cache) => cache.put(request, copy));
                }
                return res;
            })),
        );
    }
});

// ── 웹푸시: 새 리딤코드 알림 ──────────────────────────────────────
self.addEventListener('push', (event) => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) { /* 비 JSON 페이로드 무시 */ }

    event.waitUntil(self.registration.showNotification(data.title || '가시있음', {
        body: data.body || '새 소식이 있어요.',
        icon: '/images/pwa/icon-192.png',
        // badge = 안드로이드 상태바의 작은 아이콘. OS가 흑백 실루엣으로 강제하므로
        // 컬러 아이콘 대신 투명 배경 모노크롬 전용 이미지를 쓴다(사각형 뭉개짐 방지).
        badge: '/images/pwa/badge-96.png',
        // 같은 태그 알림은 겹쳐 쌓이지 않고 최신 내용으로 교체된다
        tag: data.tag || 'sgi-push',
        renotify: true,
        data: { url: data.url || '/' },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || '/';

    // 이미 열린 탭이 있으면 포커스+이동, 없으면 새로 연다
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((tabs) => {
            for (const tab of tabs) {
                if (new URL(tab.url).origin === self.location.origin) {
                    tab.navigate(url);
                    return tab.focus();
                }
            }
            return clients.openWindow(url);
        }),
    );
});
