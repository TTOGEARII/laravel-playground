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

export async function crawlRaids() {
    log('trickcal 레이드 일정 소스 없음(수동 입력/공략글로 커버)');
    return [];
}
