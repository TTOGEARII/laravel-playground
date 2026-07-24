// 일러스타페스(illustar.net) 행사 일정 수집 사이드카.
// React SPA(공개 API 는 난독화)라 실브라우저로 홈을 렌더한 뒤, 회차 롤링 배너에서
// 날짜·장소 텍스트를 추출한다. 클래스가 styled-components 해시라 매우 취약하므로
// 클래스에 의존하지 않고 "날짜 정규식이 매칭되는 li 텍스트" 구조 기반으로 뽑는다.
// 파싱(날짜 해석)은 PHP 드라이버 몫 — 여기서는 원문 텍스트만 전달한다(테스트 용이).
// stdout 계약: {"source":"illustar","items":[{"text":"2026년 8월 1, 2일 (토, 일)\n부산 벡스코 ..."}]}
import { chromium } from 'playwright';

const DATE_RE = /\d{4}\s*년\s*\d{1,2}\s*월/;

async function main() {
  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  try {
    const page = await (await browser.newContext({
      viewport: { width: 1440, height: 1000 },
      userAgent: process.env.SGI_CRAWLER_UA
        || 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
    })).newPage();

    await page.goto('https://illustar.net/', { waitUntil: 'networkidle', timeout: 60000 });
    await page.waitForTimeout(3000); // SPA 렌더 여유

    // 날짜 텍스트를 포함한 li(슬라이더 슬라이드)를 구조 기준으로 수집.
    const texts = await page.$$eval('li', (els) =>
      els.map((el) => (el.innerText || '').trim()).filter((t) => t.length > 0 && t.length < 300),
    );

    // 날짜 정규식 매칭 + 정규화 중복 제거(슬라이더 클론 슬라이드 대응)
    const seen = new Set();
    const items = [];
    for (const text of texts) {
      if (!DATE_RE.test(text)) continue;
      const key = text.replace(/\s+/g, ' ');
      if (seen.has(key)) continue;
      seen.add(key);
      items.push({ text });
    }

    process.stdout.write(JSON.stringify({ source: 'illustar', items }));
  } finally {
    await browser.close();
  }
}

main().catch((e) => {
  process.stderr.write(String(e?.stack || e));
  process.exit(1);
});
