import axios from 'axios';

const API_BASE = '/api/subculture-game-info';
const POOL_BASE = '/subculture-game-info/my-characters';

/** 공개 API (레이드/캐릭터 조회) */
export const raidApi = {
    async getRaids(params = {}) {
        const { data } = await axios.get(`${API_BASE}/raids`, { params });
        return data;
    },
    async getRaid(id) {
        const { data } = await axios.get(`${API_BASE}/raids/${id}`);
        return data.data;
    },
    async getCharacters(gameSlug) {
        const { data } = await axios.get(`${API_BASE}/characters`, { params: { game: gameSlug } });
        return data; // { data: [...], meta: { growth_schema } }
    },
    /**
     * 미보유 제외 실전 편성 조회 (블아·니케만 supported=true).
     * @param {number} raidId
     * @param {string[]} exclude 미보유 캐릭터 external_key 배열 (max 500)
     * @param {number} page
     */
    async getAlternativeParties(raidId, exclude, page = 1) {
        const { data } = await axios.post(`${API_BASE}/raids/${raidId}/alternative-parties`, { exclude, page });
        return data.data; // { supported, mode, total_count, parties, source, source_url }
    },
};

/**
 * 내 캐릭터 풀 저장소 어댑터.
 * 로그인 = 서버(세션 인증 API), 게스트 = localStorage — 동일 인터페이스/JSON 계약이라
 * 게스트가 내보낸 파일을 로그인 후 가져오기 한 번으로 이전할 수 있다.
 * 풀은 { [external_key]: { owned, growth } } 맵으로 다룬다.
 */
export function createPoolStore(loggedIn) {
    return loggedIn ? serverPoolStore() : localPoolStore();
}

function serverPoolStore() {
    return {
        /** @param {Array} characters external_key ↔ id 매핑용 캐릭터 목록 */
        async load(gameSlug, characters) {
            const { data } = await axios.get(POOL_BASE, { params: { game: gameSlug } });
            const byId = new Map(characters.map((c) => [c.id, c.external_key]));
            const pool = {};
            for (const row of data.data) {
                const key = byId.get(row.character_id);
                if (key) pool[key] = { owned: row.owned, growth: row.growth };
            }
            return pool;
        },
        async save(gameSlug, character, owned, growth) {
            await axios.put(`${POOL_BASE}/${character.id}`, { owned, growth });
        },
        async remove(gameSlug, character) {
            await axios.delete(`${POOL_BASE}/${character.id}`);
        },
        async exportData(gameSlug) {
            const { data } = await axios.get(`${POOL_BASE}/export`, { params: { game: gameSlug } });
            return data;
        },
        async importData(payload) {
            const { data } = await axios.post(`${POOL_BASE}/import`, payload);
            return data.data;
        },
    };
}

function localPoolStore() {
    const storageKey = (gameSlug) => `sgi:my-characters:${gameSlug}`;

    const read = (gameSlug) => {
        try {
            const raw = localStorage.getItem(storageKey(gameSlug));
            const parsed = raw ? JSON.parse(raw) : null;
            return Array.isArray(parsed?.characters) ? parsed.characters : [];
        } catch {
            return [];
        }
    };

    const write = (gameSlug, characters) => {
        localStorage.setItem(storageKey(gameSlug), JSON.stringify({
            version: 1,
            game: gameSlug,
            exported_at: new Date().toISOString(),
            characters,
        }));
    };

    return {
        async load(gameSlug) {
            const pool = {};
            for (const entry of read(gameSlug)) {
                if (entry?.external_key) pool[entry.external_key] = { owned: !!entry.owned, growth: entry.growth ?? null };
            }
            return pool;
        },
        async save(gameSlug, character, owned, growth) {
            const rest = read(gameSlug).filter((e) => e.external_key !== character.external_key);
            rest.push({ external_key: character.external_key, name: character.name, owned, growth });
            write(gameSlug, rest);
        },
        async remove(gameSlug, character) {
            write(gameSlug, read(gameSlug).filter((e) => e.external_key !== character.external_key));
        },
        async exportData(gameSlug) {
            return {
                version: 1,
                game: gameSlug,
                exported_at: new Date().toISOString(),
                characters: read(gameSlug),
            };
        },
        async importData(payload) {
            const entries = Array.isArray(payload?.characters) ? payload.characters : [];
            const valid = entries.filter((e) => e?.external_key || e?.name);
            write(payload.game, valid);
            return { imported: valid.length, missing: entries.length - valid.length };
        },
    };
}
