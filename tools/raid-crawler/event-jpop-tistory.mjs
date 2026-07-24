// J-pop 내한 캘린더(j-pop-playlist.tistory.com/1109) 수집 사이드카.
// 큐레이션된 J-pop 전용 캘린더 위젯(JS 렌더) — pill 요소가 data 속성으로 전체 정보를 담는다:
//   data-date(ISO)·data-title(풀네임)·data-location·data-link(예매), class 에 festival/fanmeeting.
// 광고 때문에 networkidle 이 안 오므로 domcontentloaded + 그리드 셀렉터 대기.
// 월 이동 버튼은 오버레이가 가로채 Playwright 클릭이 막힘 → JS click 으로 우회.
// stdout 계약: {"source":"jpoptistory","items":[{date,title,location,link,category}]}
import { chromium } from 'playwright';

const MONTHS_AHEAD = 6; // 현재 달 포함 앞으로 볼 개월 수

async function main() {
  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  try {
    const page = await (await browser.newContext({
      viewport: { width: 1280, height: 900 },
      userAgent: process.env.SGI_CRAWLER_UA
        || 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
    })).newPage();

    await page.goto('https://j-pop-playlist.tistory.com/1109', { waitUntil: 'domcontentloaded', timeout: 45000 });
    await page.waitForSelector('.concert-calendar-grid', { timeout: 20000 });
    await page.waitForTimeout(2000);

    const seen = new Set();
    const items = [];
    for (let m = 0; m < MONTHS_AHEAD; m++) {
      const events = await page.$$eval('.concert-event', (els) =>
        els.map((el) => ({
          date: el.dataset.date || '',
          title: el.dataset.title || el.innerText.trim(),
          location: el.dataset.location || '',
          link: el.dataset.link || '',
          category: el.classList.contains('festival') ? 'festival'
            : el.classList.contains('fanmeeting') ? 'fanmeeting' : 'concert',
        })),
      );
      for (const ev of events) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(ev.date) || !ev.title) continue;
        const key = ev.title + '|' + ev.date;
        if (seen.has(key)) continue; // 이웃달 셀 중복
        seen.add(key);
        items.push(ev);
      }
      // 다음 달로 — 오버레이 인터셉트 회피(JS click)
      await page.$$eval('.concert-calendar-header button', (btns) => btns[btns.length - 1]?.click());
      await page.waitForTimeout(700);
    }

    process.stdout.write(JSON.stringify({ source: 'jpoptistory', items }));
  } finally {
    await browser.close();
  }
}

main().catch((e) => {
  process.stderr.write(String(e?.stack || e));
  process.exit(1);
});
