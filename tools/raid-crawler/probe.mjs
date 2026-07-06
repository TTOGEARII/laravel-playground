// 개발용 사이트 구조 조사 스크립트 (크롤 파이프라인과 무관).
// 사용: node probe.mjs <url> [--html out.html] [--links 패턴] [--xhr]
//   --html  렌더링 후 HTML 을 파일로 저장
//   --links 정규식과 매칭되는 href 의 앵커(텍스트+href) 목록 출력
//   --xhr   JSON 응답 XHR URL 목록 출력
import { writeFileSync } from 'node:fs';
import { launchBrowser, newPage } from './lib/browser.mjs';
import { log } from './lib/normalize.mjs';

const [url, ...rest] = process.argv.slice(2);
if (!url) {
    log('사용법: node probe.mjs <url> [--html out.html] [--links 패턴] [--xhr]');
    process.exit(1);
}
const opt = (name) => {
    const i = rest.indexOf(name);
    return i === -1 ? null : (rest[i + 1] ?? true);
};

const browser = await launchBrowser();
const page = await newPage(browser);

const xhrJson = [];
page.on('response', async (res) => {
    const ct = res.headers()['content-type'] ?? '';
    if (ct.includes('json') || res.url().includes('.data')) {
        xhrJson.push(`${res.status()} ${res.url()}`);
    }
});

log('이동:', url);
const res = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
log('상태:', res?.status(), '| 제목:', await page.title());
await page.waitForLoadState('networkidle', { timeout: 30000 }).catch(() => log('networkidle 타임아웃(계속 진행)'));
await page.waitForTimeout(1500);

if (opt('--xhr')) {
    log('--- JSON XHR ---');
    xhrJson.forEach((u) => console.log(u));
}

const linkPattern = opt('--links');
if (linkPattern) {
    const re = new RegExp(linkPattern);
    const links = await page.$$eval('a[href]', (as) =>
        as.map((a) => ({ href: a.getAttribute('href'), text: a.textContent.replace(/\s+/g, ' ').trim().slice(0, 80) })));
    log(`--- 링크 (${linkPattern}) ---`);
    links.filter((l) => re.test(l.href ?? '')).slice(0, 60).forEach((l) => console.log(`${l.href}\t${l.text}`));
}

const htmlOut = opt('--html');
if (htmlOut) {
    writeFileSync(htmlOut, await page.content());
    log('HTML 저장:', htmlOut);
}

await browser.close();
