/** 공백 정리(연속 공백 → 한 칸, 양끝 trim). */
export function text(value) {
    return String(value ?? '').replace(/\s+/g, ' ').trim();
}

/** 상대 경로를 절대 URL 로. 파싱 실패 시 null. */
export function absUrl(base, href) {
    if (!href) return null;
    try {
        return new URL(href, base).toString();
    } catch {
        return null;
    }
}

/** stderr 로그(stdout 은 JSON 데이터 전용). */
export function log(...args) {
    console.error('[raid-crawler]', ...args);
}
