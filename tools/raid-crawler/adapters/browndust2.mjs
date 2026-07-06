// 브라운더스트2 — BD2DB(browndust2-db.souseha.com, Vue SPA)
// 캐릭터: /ko/characters 희귀도 섹션("5 ★" 헤딩)별 카드 그리드.
// 레이드 일정은 이 사이트가 제공하지 않아 crawlRaids 는 빈 배열.
import { log } from '../lib/normalize.mjs';

export const SOURCE = 'souseha';

const SEL = {
    charImg: 'img[src*="/characters/"]',
};

export async function crawlCharacters(page, base) {
    await page.goto(`${base}/ko/characters`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForSelector(SEL.charImg, { timeout: 30000 });
    // lazy 이미지·후속 섹션 로드 유도
    for (let i = 0; i < 6; i++) {
        await page.evaluate(() => window.scrollBy(0, 2000));
        await page.waitForTimeout(300);
    }

    const raw = await page.$$eval(SEL.charImg, (imgs) => imgs.map((img) => {
        const m = (img.getAttribute('src') ?? '').match(/\/characters\/(.+?)_\d+\.webp/);
        if (!m) return null;
        const name = (img.getAttribute('alt') ?? '').trim();
        // 희귀도: 카드가 속한 섹션(article) 직전 헤딩의 "N ★" 텍스트
        let rarity = null;
        const article = img.closest('article');
        const heading = article?.previousElementSibling?.textContent ?? '';
        const rm = heading.match(/(\d)\s*★/);
        if (rm) rarity = `${rm[1]}★`;
        // 속성: 카드 하단 아이콘 파일명(fire/water/wind/light/dark 등)
        const card = img.closest('button');
        let element = null;
        for (const icon of card?.querySelectorAll('img[src*="/common/"]') ?? []) {
            const em = (icon.getAttribute('src') ?? '').match(/\/(fire|water|wind|light|dark|thunder|ice|earth)\.webp/);
            if (em) { element = em[1]; break; }
        }
        return { key: m[1], name, rarity, element, src: img.src };
    }).filter(Boolean));

    const items = new Map();
    for (const c of raw) {
        if (c.name === '' || items.has(c.key)) continue;
        items.set(c.key, {
            external_key: c.key,
            name: c.name,
            rarity: c.rarity,
            traits: c.element ? { element: c.element } : null,
            image_url: c.src,
            source_url: `${base}/ko/characters`,
        });
    }
    log(`browndust2 캐릭터 ${items.size}명`);

    return [...items.values()];
}

export async function crawlRaids() {
    log('browndust2 레이드 일정 소스 없음(수동 입력/공략글로 커버)');
    return [];
}
