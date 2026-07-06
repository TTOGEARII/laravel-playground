import { chromium } from 'playwright';

// 실제 브라우저 UA — 봇 UA는 letsdoro 등 일부 사이트가 403 처리한다.
// PHP 쪽 config subculture-game-info.http.user_agent 와 동일 값을 기본으로 쓴다.
const DEFAULT_UA =
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

/**
 * chromium 기동. SGI_PLAYWRIGHT_WS 가 있으면 원격 Playwright 서버에 접속(전용 컨테이너 전환 대비),
 * 없으면 로컬 실행(PLAYWRIGHT_BROWSERS_PATH 존중).
 */
export async function launchBrowser() {
    const ws = process.env.SGI_PLAYWRIGHT_WS;
    if (ws) {
        return chromium.connect(ws);
    }

    return chromium.launch({
        headless: true,
        // 컨테이너(user namespace 미지원) 실행 대비 + 자동화 흔적 최소화
        args: ['--no-sandbox', '--disable-blink-features=AutomationControlled'],
    });
}

/** 한국어 로케일 + 실제 UA 컨텍스트의 새 페이지. */
export async function newPage(browser) {
    const context = await browser.newContext({
        userAgent: process.env.SGI_CRAWLER_UA || DEFAULT_UA,
        locale: 'ko-KR',
        timezoneId: 'Asia/Seoul',
        viewport: { width: 1440, height: 900 },
    });
    return context.newPage();
}
