<template>
  <section v-if="visible" class="sgr-alt">
    <h4 class="sgr-alt-title">
      🏆 미보유 제외 실전 편성
      <span v-if="result" class="sgr-count">{{ result.total_count.toLocaleString() }}</span>
    </h4>

    <p v-if="ownedCount === 0" class="sgr-empty">
      내 캐릭터 탭에서 보유 캐릭터를 먼저 체크해 주세요.
    </p>

    <template v-else>
      <!-- 난이도 필터 (블아 총력전/대결전 전용) -->
      <div v-if="isBlueArchive" class="sgr-alt-difficulties" role="tablist" aria-label="난이도">
        <button
          v-for="d in DIFFICULTIES"
          :key="d.value"
          type="button"
          class="sgr-alt-diff"
          :class="{ 'is-active': difficulty === d.value }"
          @click="selectDifficulty(d.value)"
        >{{ d.label }}</button>
      </div>

      <p v-if="includeNames.length" class="sgr-alt-notice sgr-alt-include">
        필수 포함: <b>{{ includeNames.join(', ') }}</b> — 이 캐릭터를 넣은 편성만 보여드려요.
      </p>
      <p v-if="result && result.mode === 'partial'" class="sgr-alt-notice">
        미보유 없이 풀클리어한 랭커가 적어, 미보유가 적은 랭커의 전체 편성(1~5부대)을 보여드려요.
        미보유 니케는 흐리게 표시돼요.
      </p>

      <p v-if="error" class="sgr-empty">{{ error }}</p>
      <p v-else-if="result && result.parties.length === 0" class="sgr-empty">
        미보유 캐릭터를 제외하고 클리어한 편성이 없어요.
      </p>

      <div class="sgr-party-list">
        <article v-for="(party, i) in parties" :key="i" class="sgr-party sgr-alt-party">
          <div class="sgr-party-head">
            <strong class="sgr-party-title">{{ party.title }}</strong>
            <span
              class="sgr-alt-match"
              :class="matchInfo(party).cls"
              :title="'상위 랭커보다 내 풀과 잘 맞는 편성을 먼저 보여드려요'"
            >{{ matchInfo(party).label }}</span>
            <span v-if="party.score" class="sgr-alt-score">{{ Number(party.score).toLocaleString() }}</span>
          </div>
          <div class="sgr-alt-members">
            <template v-for="(m, j) in party.members" :key="j">
              <!-- 미보유 + 내가 지정한 대체가 있으면 대체 캐릭터로 표시 -->
              <div
                v-if="m.is_excluded && substituteFor(m)"
                class="sgr-alt-member is-user-sub"
                :title="`${m.name} 대신 ${substituteFor(m).name} (클릭해서 변경)`"
                @click="openPicker(m)"
              >
                <button type="button" class="sgr-alt-sub-clear" title="대체 해제" @click.stop="clearSubstitute(m)">×</button>
                <img v-if="substituteFor(m).image_url" :src="substituteFor(m).image_url" :alt="substituteFor(m).name" loading="lazy" />
                <span v-else class="sgr-member-placeholder">{{ (substituteFor(m).name || '?').slice(0, 2) }}</span>
                <span class="sgr-alt-member-name">{{ substituteFor(m).name }}</span>
                <span class="sgr-alt-caption sgr-alt-sub-caption">{{ m.name }} 대신</span>
              </div>
              <!-- 미보유(대체 미지정): 클릭하면 내 보유에서 대체 지정 -->
              <div
                v-else
                class="sgr-alt-member"
                :class="{ 'is-unowned': m.is_excluded, 'is-clickable': m.is_excluded }"
                :title="m.is_excluded ? `${m.name} — 미보유 (클릭해서 대체 지정)` : undefined"
                @click="m.is_excluded && openPicker(m)"
              >
                <img v-if="m.image_url" :src="m.image_url" :alt="m.name" loading="lazy" />
                <span v-else class="sgr-member-placeholder">{{ (m.name || '?').slice(0, 2) }}</span>
                <span class="sgr-alt-member-name">{{ m.name }}</span>
                <span v-if="m.is_excluded" class="sgr-alt-caption">미보유 · 대체+</span>
                <span v-else-if="m.meta?.is_assist" class="sgr-alt-assist">조력</span>
                <span v-else-if="memberCaption(m)" class="sgr-alt-caption">{{ memberCaption(m) }}</span>
              </div>
            </template>
          </div>
        </article>
      </div>

      <p v-if="loading" class="sgr-empty">실전 편성을 불러오는 중…</p>

      <!-- 대체 캐릭터 지정 피커(공유 컴포넌트) -->
      <SubstitutePicker
        v-if="picker"
        :raid-id="raid.id"
        :game-slug="raid.game.slug"
        :target="picker"
        :pool="pool"
        :current-key="userSubs[picker.external_key] ?? null"
        @pick="pickSubstitute"
        @close="closePicker"
      />

      <div class="sgr-alt-foot">
        <button
          v-if="result && result.has_more && !loading"
          type="button"
          class="sgr-btn"
          @click="loadMore"
        >더 보기</button>
        <a
          v-if="sourceHref"
          :href="sourceHref"
          target="_blank"
          rel="noopener"
          class="sgr-source-link"
        >{{ sourceName }} 출처 ↗</a>
      </div>
    </template>
  </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { raidApi } from '../api';
import SubstitutePicker from './SubstitutePicker.vue';

const props = defineProps({
  raid: { type: Object, required: true },
  pool: { type: Object, default: () => ({}) },
  include: { type: Array, default: () => [] }, // 반드시 포함할 external_key (핵심 캐릭터 카드에서 지정)
  userSubs: { type: Object, default: () => ({}) }, // 내 대체 매핑 { 미보유 key: 보유 key }
});

const emit = defineEmits(['set-substitute', 'clear-substitute']);

const characters = ref(null); // 게임 전체 활성 캐릭터 (미보유 계산용)
const result = ref(null);
const parties = ref([]);
const page = ref(1);
const loading = ref(false);
const error = ref('');
const unsupported = ref(false);

// 블아 전용 난이도 필터 — 편성 참고 가치가 있는 상위 3개 난이도만 노출
const DIFFICULTIES = [
  { value: 'insane', label: '인세인' },
  { value: 'torment', label: '토먼트' },
  { value: 'lunatic', label: '루나틱' },
];
const isBlueArchive = computed(() => props.raid.game?.slug === 'bluearchive');
const difficulty = ref('insane');

function selectDifficulty(value) {
  if (difficulty.value === value) return;
  difficulty.value = value;
  parties.value = [];
  result.value = null;
  load(1);
}

// 동일 조건(레이드+보유 상태) 재호출 방지용 세션 캐시
const sessionCache = new Map();

const ownedCount = computed(() => Object.values(props.pool).filter((e) => e?.owned).length);
const visible = computed(() => !unsupported.value);
const sourceName = computed(() => ({ mollulog: '몰루로그', letsdoro: '레츠도로' }[result.value?.source] ?? result.value?.source));
const sourceHref = computed(() => {
  const url = result.value?.source_url;
  return typeof url === 'string' && /^https?:\/\//i.test(url) ? url : null;
});
// 필수 포함 캐릭터 이름 (전체 캐릭터 목록에서 매핑, 로드 전이면 external_key 노출)
const includeNames = computed(() => props.include.map((key) => {
  const c = (characters.value ?? []).find((x) => x.external_key === key);
  return c?.name ?? key;
}));

function memberCaption(m) {
  const tier = m.meta?.tier;
  const weapon = m.meta?.weapon_tier;
  if (!tier) return '';
  return `★${tier}${weapon ? ` · 전${weapon}` : ''}`;
}

/**
 * 카드별 내 풀 매칭 뱃지 — "왜 상위 랭커보다 이 편성이 먼저 오는가"를 설명한다.
 * 미보유여도 내가 대체를 지정했으면 채워진 것으로 계산.
 */
function matchInfo(party) {
  const missing = (party.members ?? []).filter((m) => m.is_excluded);
  if (missing.length === 0) return { label: '내 풀 완성', cls: 'is-full' };
  const uncovered = missing.filter((m) => !props.userSubs[m.external_key]);
  if (uncovered.length === 0) return { label: `대체로 완성 ${missing.length}`, cls: 'is-subbed' };
  return { label: `미보유 ${uncovered.length}명`, cls: 'is-missing' };
}

// ── 미보유 대체 지정(피커 UI 는 SubstitutePicker 공유 컴포넌트) ────
const picker = ref(null); // 대체를 지정할 미보유 멤버 { external_key, name }

/** 멤버에 내가 지정한 대체 캐릭터(전체 캐릭터 목록에서 해석). 없으면 null */
function substituteFor(m) {
  const key = props.userSubs[m.external_key];
  if (!key) return null;
  return (characters.value ?? []).find((c) => c.external_key === key) ?? null;
}

function openPicker(member) {
  picker.value = { external_key: member.external_key, name: member.name };
}

function closePicker() {
  picker.value = null;
}

function pickSubstitute(substituteKey) {
  emit('set-substitute', {
    gameSlug: props.raid.game.slug,
    characterKey: picker.value.external_key,
    substituteKey,
  });
  closePicker();
}

function clearSubstitute(member) {
  emit('clear-substitute', { gameSlug: props.raid.game.slug, characterKey: member.external_key });
}

/** 미보유 external_key 목록 (API 상한 500개) */
function excludeKeys() {
  return characters.value
    .filter((c) => props.pool[c.external_key]?.owned !== true)
    .map((c) => c.external_key)
    .slice(0, 500);
}

async function load(nextPage = 1) {
  if (loading.value) return;
  loading.value = true;
  error.value = '';
  try {
    if (characters.value === null) {
      const res = await raidApi.getCharacters(props.raid.game.slug);
      characters.value = res.data;
    }
    if (ownedCount.value === 0) return; // 안내 문구만 표시

    const diff = isBlueArchive.value ? difficulty.value : null;
    const include = [...props.include].sort();
    const ownedKey = Object.keys(props.pool).filter((k) => props.pool[k]?.owned).sort().join(',');
    const cacheKey = `${props.raid.id}:${nextPage}:${diff ?? 'all'}:inc=${include.join(',')}:${ownedKey}`;
    let data = sessionCache.get(cacheKey);
    if (!data) {
      data = await raidApi.getAlternativeParties(props.raid.id, excludeKeys(), { page: nextPage, difficulty: diff, include });
      sessionCache.set(cacheKey, data);
    }

    if (data.supported === false) {
      unsupported.value = true;
      return;
    }
    result.value = data;
    parties.value = nextPage === 1 ? data.parties : [...parties.value, ...data.parties];
    page.value = nextPage;
  } catch (e) {
    // 보유 체크 연타 등으로 스로틀(429)에 걸리면 직전 결과를 유지하고 조용히 넘어간다
    if (e.response?.status === 429) {
      console.warn('실전 편성 조회 스로틀 — 직전 결과 유지');
      return;
    }
    console.error('실전 편성 조회 실패', e);
    error.value = '실전 편성을 불러오지 못했습니다. 잠시 후 다시 시도해 주세요.';
  } finally {
    loading.value = false;
  }
}

function loadMore() {
  load(page.value + 1);
}

// 마운트 시 + 보유 풀이 바뀌면 1페이지부터 다시.
// 보유 체크를 연달아 바꾸는 동안 매번 쏘지 않도록 디바운스(500ms) 후 최종 상태로 1회 조회.
let reloadTimer = null;
watch(
  () => [props.raid.id, ownedCount.value, props.include.join(',')],
  ([raidId], [prevRaidId] = []) => {
    if (raidId !== prevRaidId) sessionCache.clear(); // 레이드가 바뀌면 캐시도 비워 메모리 증가 방지
    clearTimeout(reloadTimer);
    reloadTimer = setTimeout(() => { parties.value = []; result.value = null; load(1); }, 500);
  },
  { immediate: true },
);
</script>
