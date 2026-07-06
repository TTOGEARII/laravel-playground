// 승리의 여신: 니케 — 레츠도로(letsdoro.com)
// 사이트가 봇 UA 를 403 처리하므로 실 브라우저 컨텍스트에서 공개 API 를 호출한다.
// 캐릭터: /api/public/nikkes. 레이드: 솔로 레이드 시즌 + 서버 랭킹 편성 API.
import { log } from '../lib/normalize.mjs';

export const SOURCE = 'letsdoro';

const API_BASE = 'https://api3.letsdoro.com';

/** 페이지 컨텍스트(fetch)로 레츠도로 API 호출 — CORS 는 사이트 자체가 쓰는 API 라 허용된다. */
async function apiGet(page, path) {
    return page.evaluate(async ({ apiBase, p }) => {
        const res = await fetch(`${apiBase}${p}`, { headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error(`API ${p} 실패: ${res.status}`);
        return res.json();
    }, { apiBase: API_BASE, p: path });
}

async function openSite(page, base) {
    await page.goto(base, { waitUntil: 'domcontentloaded', timeout: 60000 });
}

export async function crawlCharacters(page, base) {
    await openSite(page, base);
    const nikkes = await apiGet(page, '/api/public/nikkes');
    if (!Array.isArray(nikkes)) {
        log('nikke 캐릭터 응답이 배열이 아님');
        return [];
    }
    log(`nikke 캐릭터 ${nikkes.length}명`);

    return nikkes
        .filter((n) => n?.nameCode != null && n?.koreanName)
        .map((n) => ({
            external_key: String(n.nameCode),
            name: String(n.koreanName).trim(),
            rarity: null,
            traits: {
                burst: n.burstStep ?? null,
                element: n.element ?? null,
                manufacturer: n.manufacturer ?? null,
                weapon: n.weaponType ?? null,
            },
            // 레츠도로 자체 아이콘 CDN — nameCode 가 그대로 파일명(128×128 webp, 봇/Referer 차단 없음)
            image_url: `https://img.letsdoro.com/si/${n.nameCode}.webp`,
            source_url: `${base}/soloraid`,
        }));
}

const RECENT_SEASONS = 2; // 최신 N개 시즌만 (진행 중 + 직전)
const TOP_RANKS = 3; // 시즌당 상위 N위 편성

export async function crawlRaids(page, base) {
    await openSite(page, base);
    const seasons = await apiGet(page, '/api/soloraid/seasons');
    if (!Array.isArray(seasons) || seasons.length === 0) {
        log('nikke 시즌 응답 비어있음');
        return [];
    }

    const items = [];
    for (const season of seasons.slice(0, RECENT_SEASONS)) {
        const parties = [];
        try {
            const ranking = await apiGet(page, `/api/soloraid/seasons/${season.id}/ranking?server=KR`);
            let sort = 0;
            for (const entry of (ranking?.rankings ?? []).filter((r) => r.rank <= TOP_RANKS)) {
                for (const squad of entry.squads ?? []) {
                    parties.push({
                        title: `${entry.rank}위 ${squad.squadNumber}파티`,
                        difficulty: null,
                        sort: sort++,
                        source_url: `${base}/soloraid`,
                        note: squad.damage ? `데미지 ${Number(squad.damage).toLocaleString('ko-KR')}` : null,
                        members: (squad.nikkes ?? []).map((n, j) => ({
                            external_key: String(n.nikkeId),
                            name: String(n.nikkeName ?? '').trim(),
                            slot_type: null,
                            sort: j,
                        })),
                    });
                }
            }
        } catch (e) {
            log(`nikke 시즌 ${season.id} 랭킹 조회 실패: ${e.message}`);
        }

        items.push({
            external_key: `soloraid-${season.seasonNumber}`,
            name: `솔로 레이드 ${season.name}`,
            boss_name: null,
            raid_type: '솔로 레이드',
            tags: null,
            starts_at: season.startAt ?? season.startDate ?? null,
            ends_at: season.endAt ?? season.endDate ?? null,
            source_url: `${base}/soloraid`,
            parties,
        });
    }
    log(`nikke 레이드 ${items.length}건`);

    return items;
}
