// 블루아카이브 — 몰루로그(mollulog.net, Remix SSR)
// 캐릭터: /students 학생 카드 그리드. 레이드: /raids(현재 회차로 리다이렉트) + /ranks(상위권 편성).
import { absUrl, log, text } from '../lib/normalize.mjs';

export const SOURCE = 'mollulog';

const SEL = {
    studentImg: 'img[src*="/students/collection/"]',
    bossImg: 'img[alt="보스 이미지"]',
};

/** 학생 카드 이미지에서 (공식 학생 ID, 이름, 이미지) 추출. */
export async function crawlCharacters(page, base) {
    await page.goto(`${base}/students`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForSelector(SEL.studentImg, { timeout: 30000 });

    const raw = await page.$$eval(SEL.studentImg, (imgs) => imgs.map((img) => ({
        src: img.getAttribute('src') ?? '',
        alt: (img.getAttribute('alt') ?? '').trim(),
    })));

    // 스트라이커/스페셜 구분 — baql GraphQL 의 uid→role (몰루로그 DOM 에는 없음)
    let roles = {};
    try {
        const res = await page.request.post('https://api.baql.net/graphql', {
            data: { query: '{ students { uid role } }' },
        });
        const students = (await res.json())?.data?.students ?? [];
        roles = Object.fromEntries(students.map((s) => [s.uid, s.role]));
        log(`bluearchive role ${Object.keys(roles).length}건 (baql)`);
    } catch (e) {
        log(`bluearchive role 조회 실패(traits 없이 진행): ${e.message}`);
    }

    const items = new Map();
    for (const { src, alt } of raw) {
        const m = src.match(/collection\/(\d+)\.webp/);
        if (!m || alt === '') continue;
        items.set(m[1], {
            external_key: m[1],
            name: alt,
            rarity: null,
            traits: roles[m[1]] ? { role: roles[m[1]] } : null,
            image_url: absUrl(base, src),
            source_url: `${base}/students`,
        });
    }
    log(`bluearchive 학생 ${items.size}명`);

    return [...items.values()];
}

/** 현재 진행 레이드 1건 + 상위권 편성. /raids 는 진행 중 회차 상세로 리다이렉트된다. */
export async function crawlRaids(page, base) {
    await page.goto(`${base}/raids`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForSelector(SEL.bossImg, { timeout: 30000 });

    // 최종 URL 에서 레이드 종류·회차 추출: /raids/total-assault/83
    const url = page.url();
    const um = url.match(/\/raids\/([a-z-]+)\/(\d+)/);
    if (!um) {
        log(`bluearchive 레이드 URL 패턴 불일치: ${url}`);
        return [];
    }
    const [, typeSlug, season] = um;

    // 보스 카드 텍스트: "총력전 #83 · 야외 비나 2026.06.30 ~ 07.07 루나틱 중장갑 ..."
    const cardText = await page.evaluate((sel) => {
        const img = document.querySelector(sel);
        let node = img;
        // 기간(YYYY.MM.DD)이 포함될 때까지 부모로 올라가 카드 컨테이너를 찾는다.
        while (node && !/\d{4}\.\d{2}\.\d{2}/.test(node.innerText ?? '')) {
            node = node.parentElement;
        }
        return node ? node.innerText.replace(/\s+/g, ' ').trim() : '';
    }, SEL.bossImg);

    const raidType = cardText.match(/총력전|대결전|제약해제결전/)?.[0] ?? null;
    const terrain = cardText.match(/야외|시가지|실내/)?.[0] ?? null;
    // 대결전은 장갑 3종이 동시에 나온다 — 전부 수집(armor_type 은 첫 항목으로 하위 호환)
    const armors = [...new Set([...cardText.matchAll(/경장갑|중장갑|특수장갑|탄력장갑/g)].map((m) => m[0]))];
    const armor = armors[0] ?? null;
    const difficulty = cardText.match(/루나틱|토먼트|TORMENT|LUNATIC|인세인|INSANE/i)?.[0] ?? null;
    // 보스명: "지형 보스명 기간" 순서에서 지형과 기간 사이 토큰
    const bossM = cardText.match(/(?:야외|시가지|실내)\s+(\S+)\s+\d{4}\./);
    const boss = bossM ? text(bossM[1]) : null;

    // 기간: "2026.06.30 ~ 07.07" (종료가 MM.DD 이면 시작 연도 승계, 월 역전 시 이듬해)
    let startsAt = null;
    let endsAt = null;
    const dm = cardText.match(/(\d{4})\.(\d{2})\.(\d{2})\s*~\s*(?:(\d{4})\.)?(\d{2})\.(\d{2})/);
    if (dm) {
        const [, y1, mo1, d1, y2, mo2, d2] = dm;
        startsAt = `${y1}-${mo1}-${d1}`;
        const endYear = y2 ?? (Number(mo2) < Number(mo1) ? String(Number(y1) + 1) : y1);
        endsAt = `${endYear}-${mo2}-${d2}`;
    }

    const detailUrl = `${base}/raids/${typeSlug}/${season}`;
    const parties = await crawlParties(page, `${detailUrl}/ranks`, difficulty);

    log(`bluearchive 레이드: ${raidType} #${season} ${boss ?? '?'} · 편성 ${parties.length}개`);

    return [{
        external_key: `${typeSlug}-${season}`,
        name: `${raidType ?? '레이드'} #${season}${boss ? ` - ${boss}` : ''}`,
        boss_name: boss,
        raid_type: raidType,
        tags: { terrain, armor_type: armor, armor_types: armors, difficulty },
        starts_at: startsAt,
        ends_at: endsAt,
        source_url: detailUrl,
        parties,
    }];
}

const TOP_RANKS = 3; // 상위 N위까지의 편성만 수집

/** 상위권 편성: "N위" 블록 아래 "M편성" 라벨 + 학생 6칸 그리드. */
async function crawlParties(page, ranksUrl, difficulty) {
    try {
        await page.goto(ranksUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
        await page.waitForSelector(SEL.studentImg, { timeout: 30000 });
    } catch {
        log(`bluearchive 편성 페이지 로드 실패: ${ranksUrl}`);
        return [];
    }

    const groups = await page.evaluate((sel) => {
        // 문서 순서대로 "N위" 라벨로 현재 순위를 갱신하고, "M편성" 라벨 옆 그리드에서 멤버를 모은다.
        const out = [];
        let currentRank = null;
        for (const span of document.querySelectorAll('span')) {
            const t = (span.textContent ?? '').trim();
            const rankM = t.match(/^(\d+)위$/);
            if (rankM) {
                currentRank = Number(rankM[1]);
                continue;
            }
            const partyM = t.match(/^(\d+)편성$/);
            if (!partyM || currentRank === null) continue;
            const grid = span.nextElementSibling;
            if (!grid) continue;
            const members = [];
            for (const img of grid.querySelectorAll(sel)) {
                const m = (img.getAttribute('src') ?? '').match(/collection\/(\d+)\.webp/);
                if (m) {
                    members.push({ external_key: m[1], name: (img.getAttribute('alt') ?? '').trim() });
                }
            }
            if (members.length > 0) {
                out.push({ rank: currentRank, partyNo: Number(partyM[1]), members });
            }
        }
        return out;
    }, SEL.studentImg);

    return groups
        .filter((g) => g.rank <= TOP_RANKS)
        .map((g, i) => ({
            title: `${g.rank}위 ${g.partyNo}편성`,
            difficulty,
            sort: i,
            source_url: ranksUrl,
            members: g.members.map((m, j) => ({
                external_key: m.external_key,
                name: m.name,
                // 블아 편성은 스트라이커 4 + 스페셜 2 구성이 관례
                slot_type: j < 4 ? 'striker' : 'special',
                sort: j,
            })),
        }));
}
