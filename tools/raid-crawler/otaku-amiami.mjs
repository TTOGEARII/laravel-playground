// 오타쿠샵 해외관 — 아미아미(AmiAmi) 예약 상품 수집 사이드카.
//
// 아미아미는 Cloudflare 가 일반 HTTP 클라이언트를 TLS 지문 레벨에서 403 차단하므로,
// 실브라우저(Playwright)로 사이트를 1회 연 뒤 "페이지 컨텍스트 안에서" fetch() 로
// 공식 목록 API 를 호출한다(니케 letsdoro 어댑터와 동일 패턴 — adapters/nikke.mjs 참고).
// 목록 API 에 jancode 가 포함돼 상세 방문이 불필요하다.
//
// 사용: node otaku-amiami.mjs --base=<url> --api-base=<url> --categories=459,1298
//        [--filters=k=v,k=v] [--page-size=50] [--max-pages=0] [--delay-ms=1500] [--retries=3]
// 데이터(JSON)는 stdout, 로그는 stderr. 실패 시 exit code 1.
// stdout 계약: { shop: 'amiami', source, crawled_at, items: [{ gcode, title, price_jpy,
//               jancode, image_url, release_date, available, preorder }] }
import { parseArgs } from 'node:util';
import { launchBrowser, newPage } from './lib/browser.mjs';
import { log } from './lib/normalize.mjs';

const { values } = parseArgs({
    options: {
        base: { type: 'string' },
        'api-base': { type: 'string' },
        categories: { type: 'string' },
        filters: { type: 'string' },
        'page-size': { type: 'string' },
        'max-pages': { type: 'string' },
        'delay-ms': { type: 'string' },
        retries: { type: 'string' },
    },
});

const BASE = (values.base ?? 'https://www.amiami.com/eng/').replace(/\/$/, '');
const API_BASE = (values['api-base'] ?? 'https://api.amiami.com/api/v1.0').replace(/\/$/, '');
const CATEGORIES = (values.categories ?? '459,1298').split(',').map((c) => c.trim()).filter(Boolean);
const PAGE_SIZE = Math.max(1, Number(values['page-size'] ?? 50));
const MAX_PAGES = Math.max(0, Number(values['max-pages'] ?? 0)); // 0 = 카테고리 끝까지
const DELAY_MS = Math.max(0, Number(values['delay-ms'] ?? 1500));
const RETRIES = Math.max(0, Number(values.retries ?? 3));
// 추가 쿼리 필터(k=v,k=v). MVP 는 예약 가능 상품(s_st_list_preorder_available=1)만 수집한다.
const FILTERS = (values.filters ?? '')
    .split(',')
    .map((pair) => pair.trim())
    .filter((pair) => pair.includes('='))
    .map((pair) => pair.split('=', 2));

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * 페이지 컨텍스트(fetch)로 아미아미 목록 API 호출.
 * x-user-key 는 사이트 프론트가 쓰는 공개 키(amiami_dev)로, 없으면 401 이 난다.
 */
async function apiGet(page, url) {
    return page.evaluate(async (u) => {
        const res = await fetch(u, {
            headers: { 'x-user-key': 'amiami_dev', Accept: 'application/json' },
        });
        if (!res.ok) throw new Error(`API ${res.status}`);
        return res.json();
    }, url);
}

/** 간헐 503 대응: 실패 시 지수 백오프로 재시도한다. */
async function apiGetWithRetry(page, url) {
    let lastError = null;
    for (let attempt = 0; attempt <= RETRIES; attempt++) {
        if (attempt > 0) {
            const backoffMs = 2000 * 2 ** (attempt - 1); // 2s → 4s → 8s
            log(`재시도 ${attempt}/${RETRIES} (${backoffMs}ms 대기): ${lastError?.message}`);
            await sleep(backoffMs);
        }
        try {
            return await apiGet(page, url);
        } catch (e) {
            lastError = e;
        }
    }
    throw lastError;
}

function listUrl(category, pageNo) {
    const params = new URLSearchParams({
        lang: 'eng',
        pagemax: String(PAGE_SIZE),
        pagecnt: String(pageNo),
        s_cate2: String(category),
    });
    for (const [key, value] of FILTERS) params.set(key, value);
    return `${API_BASE}/items?${params.toString()}`;
}

/**
 * 중고 판별: gcode 접미 '-R'(중고 재고 코드) 또는 condition_flg(0=신품, 1=중고).
 * 실측: 중고 필터(s_st_condition_flg=1) 응답의 상품은 gcode 가 '-R' 로 끝나고
 * condition_flg=1, 신품(예약) 응답은 전부 condition_flg=0 이었다.
 */
function isUsedItem(item) {
    return String(item.gcode ?? '').toUpperCase().endsWith('-R')
        || Number(item.condition_flg ?? 0) > 0;
}

/** API 상품 1건 → stdout 계약 아이템. 수집 불가(필수값 없음)면 null. */
function toItem(item) {
    const gcode = String(item.gcode ?? '').trim();
    const title = String(item.gname ?? '').trim();
    // min_price = 실판매가(세후 JPY). 없으면 정가(c_price_taxed) 폴백.
    const price = Number(item.min_price ?? item.c_price_taxed ?? 0);
    if (gcode === '' || title === '' || !(price > 0)) return null;

    const jancode = String(item.jancode ?? '').trim();
    return {
        gcode,
        title,
        price_jpy: price,
        jancode: jancode !== '' ? jancode : null,
        image_url: item.thumb_url ? `https://img.amiami.com${item.thumb_url}` : null,
        release_date: item.releasedate ? String(item.releasedate) : null,
        // 예약 가능 목록이라 기본 구매 가능. instock_flg(재고 있음)/preorderitem(예약 중)
        // 어느 쪽도 아니면 주문 마감으로 본다.
        available: Number(item.instock_flg ?? 0) === 1 || Number(item.preorderitem ?? 0) === 1,
        preorder: Number(item.preorderitem ?? 0) === 1,
    };
}

const browser = await launchBrowser();

try {
    const page = await newPage(browser);
    // Cloudflare 통과용 실브라우저 컨텍스트 확보(이후 API 는 페이지 안 fetch 로 호출).
    await page.goto(BASE, { waitUntil: 'domcontentloaded', timeout: 60000 });

    const byGcode = new Map(); // 카테고리 간 중복 상품 제거
    const stats = { fetched: 0, used: 0, noJan: 0, invalid: 0 };

    for (const category of CATEGORIES) {
        let total = null;
        for (let pageNo = 1; MAX_PAGES === 0 || pageNo <= MAX_PAGES; pageNo++) {
            if (stats.fetched > 0 || pageNo > 1) await sleep(DELAY_MS);

            const data = await apiGetWithRetry(page, listUrl(category, pageNo));
            const items = Array.isArray(data?.items) ? data.items : [];
            total ??= Number(data?.search_result?.total_results ?? 0);
            if (items.length === 0) break;

            for (const raw of items) {
                stats.fetched++;
                if (isUsedItem(raw)) {
                    stats.used++;
                    continue; // 중고(-R/condition_flg)는 신품 가격비교 대상이 아니다
                }
                const item = toItem(raw);
                if (item === null) {
                    stats.invalid++;
                    continue;
                }
                if (item.jancode === null) {
                    stats.noJan++;
                    continue; // 영문 제목은 정규화 매칭 불가 — JAN 없으면 매칭 키가 없어 스킵
                }
                if (!byGcode.has(item.gcode)) byGcode.set(item.gcode, item);
            }

            log(`카테고리 ${category} p${pageNo}: ${items.length}건 (누적 ${byGcode.size}/전체 ${total})`);
            if (pageNo * PAGE_SIZE >= total) break; // 끝 페이지
        }
    }

    log(`수집 완료: 채택 ${byGcode.size} / 원본 ${stats.fetched} (중고 ${stats.used} · JAN없음 ${stats.noJan} · 무효 ${stats.invalid})`);
    process.stdout.write(JSON.stringify({
        shop: 'amiami',
        source: 'amiami-api',
        crawled_at: new Date().toISOString(),
        items: [...byGcode.values()],
    }));
} catch (e) {
    log(`아미아미 크롤 실패: ${e.message}`);
    process.exitCode = 1;
} finally {
    await browser.close();
}
