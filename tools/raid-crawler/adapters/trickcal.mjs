// 트릭컬 리바이브 — 3개 소스 병합 캐릭터 마스터.
//
// 1) 트릭컬 노트(tr.triple-lab.com, React SPA) — 기존 external_key 104개의 단일 출처.
//    사이트가 104명 시점에서 방치돼 신규 사도는 없지만, 사용자 데이터가 붙는 키 체계라 반드시 유지한다.
//    가상 스크롤 DOM 크롤(수집량 95~104 불안정) 대신 index-*.js 번들을 정적 파싱한다:
//    셸 HTML → assets/index-*.js 추적(빌드 해시 대응, browndust2 패턴) 후
//    키→한국어명 사전 + 캐릭터 t 코드(6자리 enum, [0]=성격 [1]=초기 성급)를 추출.
// 2) GitHub comfffffff/trickcal_apostle_name_guess 의 apostles.json — KR 전체 로스터(이름·종족·초상).
//    트리플랩에 없는 신규 사도를 `kr:{공백제거 이름}` 키로 추가하고, 기존 사도에는 종족을 병합.
// 3) 나무위키 '트릭컬 리바이브/캐릭터' 문서 — 성급 보강 + 양쪽에 없는 최신 사도 보충.
//    (robots.txt 가 /w/ 를 허용, 주 1회 스케줄이라 부담 없음. 카드 100건 미만이면 마크업 변경으로 보고 스킵.)
//
// 트리플랩 파싱 실패 = 키 보존 불가이므로 전체 실패, 2)·3) 실패는 로그 후 계속(부분 수집).
// 레이드 일정은 어느 소스도 제공하지 않아 crawlRaids 는 빈 배열
// (공략글 피드 + subculture:import-raids 수동 편성으로 커버).
import { absUrl, log, text } from '../lib/normalize.mjs';

export const SOURCE = 'triplelab';

const GITHUB_RAW_BASE = 'https://raw.githubusercontent.com/comfffffff/trickcal_apostle_name_guess/main';
const GITHUB_REPO_URL = 'https://github.com/comfffffff/trickcal_apostle_name_guess';
const NAMU_DOC_URL = 'https://namu.wiki/w/%ED%8A%B8%EB%A6%AD%EC%BB%AC%20%EB%A6%AC%EB%B0%94%EC%9D%B4%EB%B8%8C/%EC%BA%90%EB%A6%AD%ED%84%B0';

// 나무위키 등은 봇 UA 를 차단할 수 있어 실브라우저 UA 필수(lib/browser.mjs 와 동일 값).
const DEFAULT_UA =
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

// 트리플랩 t 코드 [0]자리 성격 enum — 기존 DB traits.personality 값과 동일한 표기.
const PERSONALITIES = ['Cool', 'Gloomy', 'Jolly', 'Mad', 'Naive'];

/** 이름 동일성 키: 공백 제거("네르 (빡침)" ↔ "네르(빡침)", "마에스트로 2호" ↔ "마에스트로2호"). */
const nameKey = (value) => String(value ?? '').replace(/\s+/g, '');

/** 표시 이름: 여는 괄호 앞 공백 제거 — 트리플랩 표기("디아나(왕년)")와 통일. */
const displayName = (value) => text(value).replace(/\s+\(/g, '(');

async function fetchText(url) {
    const res = await fetch(url, {
        headers: { 'User-Agent': process.env.SGI_CRAWLER_UA || DEFAULT_UA },
    });
    if (!res.ok) throw new Error(`GET ${url} 실패: ${res.status}`);
    return res.text();
}

/**
 * 트리플랩 번들에서 키→한국어명 사전을 추출한다.
 * 번들에는 chara:{...} 사전이 로케일별(ko/zh)로 여러 개 있으므로 값이 한글인 쪽을 고른다.
 */
function extractNameDict(bundle) {
    for (const m of bundle.matchAll(/chara:\{/g)) {
        const start = m.index + m[0].length - 1;
        const end = bundle.indexOf('}', start); // 값이 단순 문자열뿐이라 중첩 없음
        if (end < 0) continue;

        const pairs = [...bundle.slice(start, end + 1).matchAll(/([A-Za-z][\w$]*):"([^"]+)"/g)];
        if (pairs.length < 50) continue;

        const hangul = pairs.filter(([, , name]) => /[가-힣]/.test(name)).length;
        if (hangul / pairs.length > 0.8) {
            return Object.fromEntries(pairs.map(([, key, name]) => [key, name]));
        }
    }
    throw new Error('키→한국어명 사전을 찾지 못함(번들 구조 변경 의심)');
}

/**
 * 캐릭터 메타(성격/초기 성급)를 추출한다. 번들 구조:
 *   Lc={t:"330114",...}  — t 6자리 enum, [0]=성격(0~4) [1]=초기 성급(1~3)
 *   Q={Alice:Lc,Allet:Rc,...} — 캐릭터 키 → 메타 변수 매핑
 * 변수명은 빌드마다 바뀌므로 "ident:ident 페어 80개 이상 + 키가 사전과 일치" 시그니처로 맵을 찾는다.
 */
function extractMeta(bundle, dictKeys) {
    const keySet = new Set(dictKeys);

    let charToVar = null;
    for (const m of bundle.matchAll(/\{(?:[A-Za-z][\w$]*:[A-Za-z$_][\w$]*,){80,}[A-Za-z][\w$]*:[A-Za-z$_][\w$]*\}/g)) {
        const entries = m[0].slice(1, -1).split(',').map((pair) => pair.split(':'));
        const known = entries.filter(([key]) => keySet.has(key)).length;
        if (known / entries.length > 0.9) {
            charToVar = Object.fromEntries(entries);
            break;
        }
    }
    if (!charToVar) {
        log('trickcal 메타 맵 미발견 — 성격/성급 없이 진행(번들 구조 변경 의심)');
        return {};
    }

    // t 코드가 있는 변수 정의를 전부 수집 (변수명에 $ 가 포함될 수 있어 \b 대신 lookbehind)
    const varDefs = {};
    for (const m of bundle.matchAll(/(?<![\w$])([A-Za-z$_][\w$]*)=\{[^{}]*?t:"(\d{6})"[^{}]*?\}/g)) {
        varDefs[m[1]] ??= m[2];
    }

    const meta = {};
    for (const [chara, varName] of Object.entries(charToVar)) {
        const code = varDefs[varName];
        if (!code) continue;
        meta[chara] = {
            personality: PERSONALITIES[Number(code[0])] ?? null,
            star: Number(code[1]) || null,
        };
    }
    return meta;
}

/** 트리플랩 셸 → index 번들 추적 후 사전/메타 파싱. */
async function loadTripleLab(base) {
    const shell = await fetchText(`${base}/personal`);
    const bundlePath = shell.match(/assets\/index-[\w-]+\.js/)?.[0];
    if (!bundlePath) throw new Error('index 번들을 찾지 못함(셸 HTML 구조 변경 의심)');

    const bundle = await fetchText(`${base}/${bundlePath}`);
    const dict = extractNameDict(bundle);
    return { dict, meta: extractMeta(bundle, Object.keys(dict)) };
}

/** GitHub 로스터 JSON: [{name, race, img, isSkin, base, ...}] */
async function loadGithubRoster() {
    const list = JSON.parse(await fetchText(`${GITHUB_RAW_BASE}/apostles.json`));
    if (!Array.isArray(list) || list.length === 0) throw new Error('apostles.json 이 비어 있음');
    return list;
}

/**
 * 나무위키 캐릭터 문서에서 사도 카드를 파싱한다.
 * 카드 = 성급 아이콘(<img alt='트릭컬 N성'>)을 품은 /w/ 앵커 — 성우·틀 등 잡링크는 아이콘이 없어 걸러진다.
 */
async function loadNamuRoster() {
    const html = await fetchText(NAMU_DOC_URL);

    const cards = new Map(); // nameKey → card (중복 카드 제거)
    for (const m of html.matchAll(/<a[^>]*href=["']\/w\/[^"']*["'][^>]*>([\s\S]*?)<\/a>/g)) {
        const body = m[1];
        const star = body.match(/alt=["']트릭컬 (\d)성["']/)?.[1];
        if (!star) continue;

        const name = body.replace(/<[^>]+>/g, '\n').split('\n').map((t) => t.trim()).find(Boolean);
        if (!name || /^\d성$/.test(name)) continue; // "1성" 같은 분류 카드 제외

        const portrait = body.match(/data-src=["']([^"']+)["']\s+alt=/)?.[1];
        const key = nameKey(name);
        if (!cards.has(key)) {
            cards.set(key, {
                name,
                star: Number(star) || null,
                image: portrait ? absUrl('https://namu.wiki', portrait) : null,
            });
        }
    }

    if (cards.size < 100) throw new Error(`카드 ${cards.size}건뿐 — 마크업 변경 의심`);
    return cards;
}

export async function crawlCharacters(page, base) {
    // 1) 트리플랩 — 기존 키 보존이 최우선이라 실패 시 전체 실패
    const { dict, meta } = await loadTripleLab(base);
    if (Object.keys(dict).length < 100) {
        throw new Error(`트리플랩 사전 ${Object.keys(dict).length}건뿐 — 기존 키 보존 불가`);
    }

    const items = new Map(); // nameKey → item
    for (const [key, name] of Object.entries(dict)) {
        const { personality = null, star = null } = meta[key] ?? {};
        items.set(nameKey(name), {
            external_key: key,
            name,
            rarity: star ? `${star}성` : null,
            traits: personality ? { personality } : null,
            image_url: absUrl(base, `/charas/${key}.png`),
            source_url: `${base}/personal`,
        });
    }
    const tripleLabCount = items.size;

    // 2) GitHub 로스터 — 신규 사도 추가 + 기존 사도 종족 병합 (실패해도 트리플랩분은 유지)
    try {
        for (const entry of await loadGithubRoster()) {
            const key = nameKey(entry.name);
            const hit = items.get(key);
            if (hit) {
                if (entry.race) hit.traits = { ...(hit.traits ?? {}), race: entry.race };
                continue;
            }

            const traits = {
                ...(entry.race ? { race: entry.race } : {}),
                ...(entry.isSkin && entry.base ? { base_character: entry.base } : {}),
            };
            items.set(key, {
                external_key: `kr:${key}`,
                name: displayName(entry.name),
                rarity: null, // 성급은 나무위키에서 보강
                traits: Object.keys(traits).length > 0 ? traits : null,
                image_url: entry.img ? `${GITHUB_RAW_BASE}/${entry.img}` : null,
                source_url: GITHUB_REPO_URL,
            });
        }
    } catch (e) {
        log(`trickcal GitHub 로스터 실패(계속 진행): ${e.message}`);
    }

    // 3) 나무위키 — 성급/초상 보강 + 양쪽에 없는 최신 사도 보충 (실패해도 계속)
    try {
        for (const [key, card] of await loadNamuRoster()) {
            const hit = items.get(key);
            if (hit) {
                if (!hit.rarity && card.star) hit.rarity = `${card.star}성`;
                if (!hit.image_url && card.image) hit.image_url = card.image;
                continue;
            }
            items.set(key, {
                external_key: `kr:${key}`,
                name: displayName(card.name),
                rarity: card.star ? `${card.star}성` : null,
                traits: null,
                image_url: card.image,
                source_url: NAMU_DOC_URL,
            });
        }
    } catch (e) {
        log(`trickcal 나무위키 보강 실패(계속 진행): ${e.message}`);
    }

    log(`trickcal 사도 ${items.size}명 (트리플랩 ${tripleLabCount} + 보강 ${items.size - tripleLabCount})`);

    return [...items.values()];
}

// 트릭컬 레코드(trickcalrecord.pages.dev) — 대충돌/대충돌2.0/프론티어 시즌을 레이드로 수집.
// 시즌 페이지의 기간·규칙과 '인기 조합'(순위 구간별 9인 편성)을 결정적 파싱한다(Gemini 불필요).
const RECORD_TYPE_LABELS = [
    [/^\/clash\/v1\//, '차원 대충돌'],
    [/^\/clash\/v2\//, '대충돌 2.0'],
    [/^\/frontier\//, '프론티어'],
];

export async function crawlRaids(page) {
    await page.goto(RECORD_BASE, { waitUntil: 'networkidle', timeout: 30_000 });

    const seasons = await page.evaluate(() => [...document.querySelectorAll('a[href^="/clash/"], a[href^="/frontier/"]')]
        .map((a) => a.getAttribute('href'))
        .filter((href) => /^\/(clash\/v\d+|frontier)\/\d+$/.test(href))
        .slice(0, 3));

    const items = [];
    for (const href of seasons) {
        try {
            await page.goto(`${RECORD_BASE}${href}`, { waitUntil: 'networkidle', timeout: 30_000 });
            const parsed = await page.evaluate(() => {
                const lines = document.body.innerText.split('\n').map((s) => s.trim()).filter(Boolean);
                const name = document.title.replace(/ 집계 - 트릭컬 레코드$/, '').trim();
                const period = lines.find((l) => /^\d{4}-\d{2}-\d{2} ~/.test(l)) ?? null;
                const bossIdx = lines.indexOf('기간');
                const boss = bossIdx > 0 ? lines[bossIdx - 1] : null; // '기간' 직전 줄 = 보스/시즌명

                // 규칙(있으면): '규칙' 라벨 뒤 ~ '순위 지정' 전
                const rules = [];
                const ri = lines.indexOf('규칙');
                if (ri >= 0) {
                    for (let i = ri + 1; i < lines.length && !lines[i].startsWith('순위'); i++) rules.push(lines[i]);
                }

                // 인기 조합: '1~100위' 류 라벨 뒤 9명
                const parties = [];
                for (let i = 0; i < lines.length; i++) {
                    const m = lines[i].match(/^(\d+~\d+위)$/);
                    if (!m) continue;
                    const members = [];
                    for (let j = i + 1; j < lines.length && members.length < 9; j++) {
                        const l = lines[j];
                        if (/^(\d+~\d+위|조합|성격)$/.test(l) || /%$/.test(l)) break;
                        members.push(l);
                    }
                    if (members.length >= 6) parties.push({ label: m[1], members });
                }
                return { name, period, boss, rules, parties };
            });

            if (!parsed.name || !parsed.period) {
                log(`트릭컬레코드 ${href} 파싱 실패(이름/기간 없음) — 스킵`);
                continue;
            }
            const [start, end] = parsed.period.split('~').map((s) => s.trim());
            const raidType = RECORD_TYPE_LABELS.find(([re]) => re.test(href))?.[1] ?? '레이드';

            items.push({
                external_key: href.slice(1).replaceAll('/', '-'), // clash-v1-46 / frontier-18
                name: parsed.name,
                raid_type: raidType,
                boss_name: parsed.boss && parsed.boss !== parsed.name ? parsed.boss : null,
                tags: parsed.rules.length > 0 ? { 규칙: parsed.rules.join(' · ').slice(0, 120) } : null,
                starts_at: start || null,
                ends_at: end || null,
                source_url: `${RECORD_BASE}${href}`,
                parties: parsed.parties.map((party, i) => ({
                    title: `인기 조합 ${party.label}`,
                    sort: i,
                    source_url: `${RECORD_BASE}${href}`,
                    members: party.members.map((memberName, j) => ({ external_key: '', name: memberName, sort: j })),
                })),
            });
        } catch (e) {
            log(`트릭컬레코드 ${href} 실패(계속 진행): ${e.message}`);
        }
    }

    log(`trickcal 레이드(트릭컬 레코드 시즌) ${items.length}건 수집`);
    return items;
}

// ─────────────────────────────────────────────────────────────────────────────
// 속성(성격)별 추천 조합 — 팀 매니저(trickcal-team-manager.netlify.app/builder)의
// 성격별 추천 사도(전열/중열/후열 + 사이드 페어링) 큐레이션. SPA 라 DOM 크롤.
// (트릭컬 레코드 시즌 실측은 crawlRaids 의 레이드 정보로 분리 — 속성 탭과 별개)
// ─────────────────────────────────────────────────────────────────────────────

const TEAM_MANAGER_URL = 'https://trickcal-team-manager.netlify.app/builder';
const RECORD_BASE = 'https://trickcalrecord.pages.dev';

/** 팀 매니저 — 성격 탭 5개를 순회하며 포지션별 추천 사도(+사이드)를 수집. */
async function crawlTeamManager(page) {
    await page.goto(TEAM_MANAGER_URL, { waitUntil: 'networkidle', timeout: 30_000 });

    // 환영 모달 닫기(있을 때만)
    await page.evaluate(() => {
        const no = [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === '아니요');
        no?.click();
    });
    await page.waitForTimeout(400);

    const parties = [];
    for (const personality of ['Jolly', 'Mad', 'Cool', 'Naive', 'Gloomy']) {
        const clicked = await page.evaluate((code) => {
            const icon = [...document.querySelectorAll('img[alt]')].find((i) => i.alt === code);
            if (!icon) return false;
            (icon.closest('button') ?? icon.parentElement).click();
            return true;
        }, personality);
        if (!clicked) {
            log(`팀매니저 ${personality} 탭을 찾지 못함(마크업 변경 의심)`);
            continue;
        }
        await page.waitForTimeout(500);

        // 추천 영역: 사도 카드 본체 = img.object-cover(alt=이름).
        // 포지션은 카드에서 바깥으로 올라가며 만나는 포지션 아이콘(Common_PositionBack/Middle/Front),
        // 사이드 페어링은 같은 카드의 우상단 absolute 아이콘(alt=이름).
        const members = await page.evaluate(() => {
            const result = [];
            const seen = new Set();

            for (const img of document.querySelectorAll('img[alt]')) {
                if (!img.className.includes('object-cover')) continue;
                const name = img.alt.trim();
                if (!name || seen.has(name)) continue;

                let position = null;
                let node = img.closest('div');
                for (let depth = 0; node && depth < 6; depth++, node = node.parentElement) {
                    const pos = node.querySelector('img[src*="Common_Position"]');
                    if (pos) {
                        position = pos.src.includes('Back') ? 'back' : pos.src.includes('Middle') ? 'middle' : 'front';
                        break;
                    }
                }
                if (position === null) continue; // 보유 사도 목록 등 추천 영역 밖 카드

                const card = img.closest('div')?.parentElement;
                const aside = card?.querySelector('img[class*="absolute"][alt]')?.alt?.trim() || null;

                seen.add(name);
                result.push({ name, position, aside: aside !== name ? aside : null });
            }
            return result;
        });

        if (members.length === 0) {
            log(`팀매니저 ${personality} 추천 사도 0명(마크업 변경 의심)`);
            continue;
        }
        parties.push({
            kind: 'curated',
            attribute: personality,
            source: 'team-manager',
            source_url: TEAM_MANAGER_URL,
            members,
        });
    }

    log(`팀매니저 성격별 추천 ${parties.length}속성 수집`);
    return parties;
}

/** 속성별 추천 조합 수집 진입점 — 팀 매니저 큐레이션. */
export async function crawlAttributeParties(page) {
    const items = await crawlTeamManager(page);
    if (items.length === 0) throw new Error('팀 매니저 성격별 추천 수집 실패');
    return items;
}
