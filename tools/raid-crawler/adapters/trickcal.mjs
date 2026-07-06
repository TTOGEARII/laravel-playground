// 트릭컬 리바이브 — 트릭컬 노트(tr.triple-lab.com, React SPA)
// 캐릭터: /personal 사도 그리드(react-virtuoso 가상 스크롤 → 스크롤하며 수집).
// 레이드 일정은 이 사이트가 제공하지 않아 crawlRaids 는 빈 배열
// (공략글 피드 + subculture:import-raids 수동 편성으로 커버).
import { absUrl, log } from '../lib/normalize.mjs';

export const SOURCE = 'triplelab';

const SEL = {
    card: '.virtuoso-grid-item',
    charaImg: 'img[src^="/charas/"]',
};

const MAX_SCROLLS = 80; // 가상 스크롤 안전 상한
const IDLE_LIMIT = 5; // 신규 0명이 연속 N회면 끝으로 판단

export async function crawlCharacters(page, base) {
    await page.goto(`${base}/personal`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForSelector(SEL.card, { timeout: 30000 });

    const items = new Map();
    let idleRounds = 0;

    for (let i = 0; i < MAX_SCROLLS && idleRounds < IDLE_LIMIT; i++) {
        const batch = await page.$$eval(SEL.card, (cards) => cards.map((card) => {
            const img = card.querySelector('img[src^="/charas/"]');
            if (!img) return null;
            const key = (img.getAttribute('src') ?? '').match(/\/charas\/(.+?)\.png/)?.[1];
            if (!key) return null;
            // 이름은 카드 마지막 텍스트 블록, 성급은 HeroGrade 아이콘 개수, 성격은 배경 클래스
            const name = [...card.querySelectorAll('div')].map((d) => d.textContent.trim())
                .filter((t) => t !== '').at(-1) ?? '';
            const stars = card.querySelectorAll('img[src*="HeroGrade"]').length;
            const personality = [...img.classList].find((c) => c.startsWith('bg-personality-'))
                ?.replace('bg-personality-', '') ?? null;
            return { key, name, stars, personality };
        }).filter(Boolean));

        const before = items.size;
        for (const c of batch) {
            if (c.name === '' || items.has(c.key)) continue;
            items.set(c.key, {
                external_key: c.key,
                name: c.name,
                rarity: c.stars > 0 ? `${c.stars}성` : null,
                traits: c.personality ? { personality: c.personality } : null,
                image_url: absUrl(base, `/charas/${c.key}.png`),
                source_url: `${base}/personal`,
            });
        }
        idleRounds = items.size === before ? idleRounds + 1 : 0;

        await page.evaluate(() => window.scrollBy(0, 900));
        await page.waitForTimeout(300);
    }
    log(`trickcal 사도 ${items.size}명`);

    return [...items.values()];
}

export async function crawlRaids() {
    log('trickcal 레이드 일정 소스 없음(수동 입력/공략글로 커버)');
    return [];
}
