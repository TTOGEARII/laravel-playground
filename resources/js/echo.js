import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * Laravel Reverb(WebSocket) 용 Echo 인스턴스.
 * 게임 로비·방·매칭은 서버 이벤트로, 대전 중 가비지·보드 스냅샷은 클라이언트 이벤트(whisper)로
 * WS 서버가 피어 간 직접 중계한다(라라벨 백엔드·DB 를 안 거쳐 저지연).
 *
 * 필요할 때 createEcho() 로 1회 생성(모든 페이지가 아니라 멀티플레이 페이지에서만 로드).
 */
let instance = null;

export function createEcho() {
    if (instance) {
        return instance;
    }

    window.Pusher = Pusher;

    const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https';
    const port = Number(import.meta.env.VITE_REVERB_PORT ?? (scheme === 'https' ? 443 : 80));

    // private/presence 채널 인증(/broadcasting/auth)은 web 그룹이라 CSRF 토큰이 필요하다.
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

    instance = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        auth: { headers: { 'X-CSRF-TOKEN': csrf } },
    });

    return instance;
}
