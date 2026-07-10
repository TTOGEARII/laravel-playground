// 범용 HTML fetch — Cloudflare 등으로 일반 HTTP 가 막히는 페이지를 실브라우저로 가져온다.
// 사용: node fetch-html.mjs --url=<URL> [--wait=<CSS 셀렉터>]
// 출력(stdout): JSON { url, html } / 로그는 stderr, 실패 시 exit code 1.
import { parseArgs } from 'node:util';
import { launchBrowser, newPage } from './lib/browser.mjs';
import { log } from './lib/normalize.mjs';

const { values } = parseArgs({
    options: {
        url: { type: 'string' },
        wait: { type: 'string' },
    },
});

if (!values.url) {
    log('사용법: node fetch-html.mjs --url=<URL> [--wait=<CSS 셀렉터>]');
    process.exit(1);
}

const browser = await launchBrowser();

try {
    const page = await newPage(browser);
    await page.goto(values.url, { waitUntil: 'domcontentloaded', timeout: 30_000 });
    if (values.wait) {
        // 셀렉터가 나타날 때까지 대기(Cloudflare 챌린지 통과 포함)
        await page.waitForSelector(values.wait, { timeout: 20_000 });
    } else {
        await page.waitForTimeout(2_000);
    }

    process.stdout.write(JSON.stringify({ url: values.url, html: await page.content() }));
} catch (e) {
    log(`fetch-html 실패(${values.url}): ${e.message}`);
    process.exitCode = 1;
} finally {
    await browser.close();
}
