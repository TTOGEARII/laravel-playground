// 레이드 크롤러 사이드카 엔트리.
// 사용: node index.mjs --game=<slug> --type=characters|raids|attribute-parties [--base=<url>]
// 데이터(JSON)는 stdout, 로그는 stderr. 실패 시 exit code 1.
import { parseArgs } from 'node:util';
import { launchBrowser, newPage } from './lib/browser.mjs';
import { log } from './lib/normalize.mjs';
import * as bluearchive from './adapters/bluearchive.mjs';
import * as browndust2 from './adapters/browndust2.mjs';
import * as nikke from './adapters/nikke.mjs';
import * as trickcal from './adapters/trickcal.mjs';

const ADAPTERS = { bluearchive, nikke, trickcal, browndust2 };

const DEFAULT_BASES = {
    bluearchive: 'https://mollulog.net',
    nikke: 'https://letsdoro.com',
    trickcal: 'https://tr.triple-lab.com',
    browndust2: 'https://browndust2-db.souseha.com',
};

const { values } = parseArgs({
    options: {
        game: { type: 'string' },
        type: { type: 'string' },
        base: { type: 'string' },
    },
});

const adapter = ADAPTERS[values.game];
if (!adapter || !['characters', 'raids', 'attribute-parties'].includes(values.type ?? '')) {
    log('사용법: node index.mjs --game=bluearchive|nikke|trickcal|browndust2 --type=characters|raids|attribute-parties [--base=URL]');
    process.exit(1);
}
if (values.type === 'attribute-parties' && typeof adapter.crawlAttributeParties !== 'function') {
    log(`${values.game} 는 attribute-parties 를 지원하지 않음(현재 trickcal 전용)`);
    process.exit(1);
}

const base = (values.base ?? DEFAULT_BASES[values.game]).replace(/\/$/, '');
const browser = await launchBrowser();

try {
    const page = await newPage(browser);
    const items = values.type === 'characters'
        ? await adapter.crawlCharacters(page, base)
        : values.type === 'raids'
            ? await adapter.crawlRaids(page, base)
            : await adapter.crawlAttributeParties(page, base);

    process.stdout.write(JSON.stringify({
        game: values.game,
        type: values.type,
        source: adapter.SOURCE,
        crawled_at: new Date().toISOString(),
        items,
    }));
} catch (e) {
    log(`크롤 실패(${values.game}/${values.type}): ${e.message}`);
    process.exitCode = 1;
} finally {
    await browser.close();
}
