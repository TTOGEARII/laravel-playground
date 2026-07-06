// 브라운더스트2 — BD2DB(browndust2-db.souseha.com, Vue SPA)
// 브더2는 코스튬이 사실상 별개 전투 단위(코스튬마다 다른 스킬)라 "코스튬당 1행"으로 수집한다.
// DOM 파싱 대신 사이트의 Vite 데이터 번들(assets/db-characters-*.js)을 직접 파싱한다:
//   셸 HTML → main-*.js → db-characters-*.js 순으로 청크명을 추적(빌드 해시 변동 대응)하고,
//   순수 ES 데이터 모듈이므로 임시 파일로 저장 후 import() 하면 구조화된 객체가 나온다.
// external_key = costumeId(`{characterId}_{costumeCode}`, 예: Refithea_3).
// 기존 캐릭터 단위 키(예: Refithea)의 하위 세분화라 충돌 없고, 구행은 sync 가드가 소프트 비활성한다.
// 레이드 일정은 이 사이트가 제공하지 않아 crawlRaids 는 빈 배열.
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { log } from '../lib/normalize.mjs';

export const SOURCE = 'souseha';

const IMAGE_BASE = 'https://image-bd2db.souseha.com/characters';

// 번들의 attribute 는 중국어 번체 — 기존 traits.element 값(영문)으로 매핑한다.
const ELEMENT_MAP = { 火: 'fire', 水: 'water', 風: 'wind', 光: 'light', 暗: 'dark' };

async function fetchText(url) {
    const headers = {};
    if (process.env.SGI_CRAWLER_UA) headers['User-Agent'] = process.env.SGI_CRAWLER_UA;
    const res = await fetch(url, { headers });
    if (!res.ok) throw new Error(`GET ${url} 실패: ${res.status}`);
    return res.text();
}

/** 셸 HTML → main 청크 → db-characters 청크를 순서대로 추적해 데이터 모듈을 import 한다. */
async function loadDataBundle(base) {
    const shell = await fetchText(`${base}/ko/characters`);
    const mainPath = shell.match(/assets\/main-[\w-]+\.js/)?.[0];
    if (!mainPath) throw new Error('main 청크를 찾지 못함(셸 HTML 구조 변경 의심)');

    const mainJs = await fetchText(`${base}/${mainPath}`);
    const chunkName = mainJs.match(/db-characters-[\w-]+\.js/)?.[0];
    if (!chunkName) throw new Error('db-characters 청크를 찾지 못함(빌드 구조 변경 의심)');

    const bundle = await fetchText(`${base}/assets/${chunkName}`);
    const dir = await mkdtemp(join(tmpdir(), 'bd2db-'));
    try {
        const file = join(dir, 'db-characters.mjs');
        await writeFile(file, bundle);

        return await import(pathToFileURL(file).href);
    } finally {
        await rm(dir, { recursive: true, force: true });
    }
}

/**
 * 번들 export 는 빌드마다 이름(c/e/f 등)이 바뀔 수 있으므로 구조 시그니처로 찾는다.
 * - 캐릭터 배열: characterId + costumes[] 를 가진 배열
 * - 코스튬 맵: 값에 costumeName_ko 가 있는 객체 맵
 * - 캐릭터 로컬라이즈 맵: 값에 character_ko 가 있는 객체 맵
 */
function detectExports(mod) {
    let characters = null;
    let costumes = null;
    let locales = null;

    for (const value of Object.values(mod)) {
        if (Array.isArray(value)) {
            if (value[0]?.characterId && Array.isArray(value[0]?.costumes)) characters = value;
            continue;
        }
        if (value === null || typeof value !== 'object') continue;

        const first = Object.values(value)[0];
        if (first?.costumeName_ko !== undefined) costumes = value;
        else if (first?.character_ko !== undefined) locales = value;
    }

    if (!characters || !costumes || !locales) {
        throw new Error('번들 export 시그니처 탐지 실패(캐릭터/코스튬/로컬라이즈 중 누락)');
    }

    return { characters, costumes, locales };
}

/**
 * 스킬 설명의 {VALUE*} 플레이스홀더를 최고 강화 단계(+5) 수치로 치환한다.
 * level 값 형식: "SP:7\nCD:7\nVALUE1:50\n..." (강화 단계 키 "0"~"5")
 */
function renderSkill(costume) {
    const levels = costume.level ?? {};
    const maxKey = Object.keys(levels).sort((a, b) => Number(a) - Number(b)).pop();

    const params = {};
    for (const line of String(levels[maxKey] ?? '').split('\n')) {
        const idx = line.indexOf(':');
        if (idx > 0) params[line.slice(0, idx).trim()] = line.slice(idx + 1).trim();
    }

    const desc = (costume.skill_ko ?? [])
        .map((line) => line.replace(/\{(\w+)\}/g, (raw, key) => params[key] ?? raw))
        .join(' ');

    return { desc: desc || null, sp: params.SP ?? null, cd: params.CD ?? null };
}

export async function crawlCharacters(page, base) {
    const mod = await loadDataBundle(base);
    const { characters, costumes, locales } = detectExports(mod);

    const items = [];
    for (const ch of characters) {
        const charKo = locales[ch.characterId]?.character_ko ?? ch.enName ?? ch.characterId;

        for (const co of ch.costumes ?? []) {
            const detail = costumes[co.costumeId];
            if (!detail?.costumeName_ko) continue;

            const skill = renderSkill(detail);
            items.push({
                external_key: co.costumeId,
                name: `${charKo} - ${detail.costumeName_ko}`,
                rarity: ch.star ? `${ch.star}★` : null,
                traits: {
                    base_character_key: ch.characterId,
                    base_character: charKo,
                    costume: detail.costumeName_ko,
                    element: ELEMENT_MAP[ch.attribute] ?? null,
                    skill_name: detail.skillName_ko ?? null,
                    skill_desc: skill.desc,
                    sp: skill.sp,
                    cd: skill.cd,
                },
                image_url: `${IMAGE_BASE}/${co.costumeId}.webp`,
                source_url: `${base}/ko/characters`,
            });
        }
    }
    log(`browndust2 코스튬 ${items.length}건 (캐릭터 ${characters.length}명)`);

    return items;
}

export async function crawlRaids() {
    log('browndust2 레이드 일정 소스 없음(수동 입력/공략글로 커버)');
    return [];
}
