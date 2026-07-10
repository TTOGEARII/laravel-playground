<template>
  <div v-if="loaded && stages.length" class="sgr-event-challenges">
    <h3 class="sgr-feed-title">
      🎯 이벤트 챌린지 — {{ event?.name }}
      <small v-if="period" class="sgr-feed-hint">{{ period }}</small>
      <small class="sgr-role-legend"><i class="is-striker" />스트라이커 <i class="is-special" />스페셜</small>
      <button
        v-if="hasPool && anyMissing && !myPartyLoaded"
        type="button"
        class="sgr-challenge-mypool-btn"
        :disabled="myPartyLoading"
        @click="loadMyParties"
      >
        {{ myPartyLoading ? '조합 계산 중…' : '🎒 내 풀로 조합 만들기' }}
      </button>
    </h3>

    <div class="sgr-challenge-grid">
      <article v-for="stage in stages" :key="stage.label" class="sgr-challenge-card">
        <header class="sgr-challenge-head">
          <div class="sgr-challenge-head-main">
            <span class="sgr-challenge-label">{{ stage.label.replace('Challenge ', 'CH ') }}</span>
            <span v-if="stage.name" class="sgr-challenge-map">{{ stage.name }}</span>
          </div>
          <span v-if="stage.condition" class="sgr-challenge-cond">⏱ {{ stage.condition }}</span>
        </header>

        <!-- ① 공략글 정리 추천 조합 — 보유는 강조, 미보유는 흐림. 스트라이커 먼저, 스페셜 뒤 -->
        <div v-if="stage.best_party?.length" class="sgr-challenge-party">
          <span class="sgr-challenge-party-tag">🏆 추천 조합</span>
          <div class="sgr-challenge-chars">
            <template v-for="(member, i) in sortedParty(stage.best_party)" :key="member.key">
              <span v-if="isRoleBoundary(sortedParty(stage.best_party), i)" class="sgr-role-divider" />
              <span
                class="sgr-challenge-char"
                :class="[roleClass(member.key), { 'is-owned': isOwned(member.key), 'is-missing': hasPool && !isOwned(member.key) }]"
              >
                <img v-if="charImage(member.key)" :src="charImage(member.key)" :alt="member.name" loading="lazy" />
                {{ member.name }}
              </span>
            </template>
          </div>
        </div>

        <!-- ② 내 풀 조합 — 미보유를 내 보유에서 Gemini 대체 -->
        <div v-if="myParties[stage.id]" class="sgr-challenge-party">
          <span class="sgr-challenge-party-tag">🎒 내 풀 조합</span>
          <div class="sgr-challenge-chars">
            <template v-for="(member, i) in sortedParty(myParties[stage.id])" :key="`${member.key}-${i}`">
              <span v-if="isRoleBoundary(sortedParty(myParties[stage.id]), i)" class="sgr-role-divider" />
              <span
                class="sgr-challenge-char"
                :class="[roleClass(member.key), { 'is-owned': member.owned, 'is-missing': !member.owned, 'is-sub': member.replaced_from }]"
                :title="member.replaced_from ? `${member.replaced_from} 대신` : (member.owned ? '' : '보유 목록에 적절한 대체 없음')"
              >
                <img v-if="charImage(member.key)" :src="charImage(member.key)" :alt="member.name" loading="lazy" />
                {{ member.name }}<template v-if="member.replaced_from">*</template>
              </span>
            </template>
          </div>
        </div>

        <div v-else-if="stage.mentioned.length && !stage.best_party?.length" class="sgr-challenge-chars">
          <span v-for="name in stage.mentioned" :key="name" class="sgr-challenge-char">{{ name }}</span>
        </div>

        <div class="sgr-challenge-actions">
          <a
            v-if="stage.video_url"
            :href="stage.video_url"
            target="_blank"
            rel="noopener"
            class="sgr-challenge-video"
          >
            ▶ 공략 영상
          </a>
          <button
            v-if="stage.summary"
            type="button"
            class="sgr-challenge-more"
            @click="toggle(stage.label)"
          >
            📝 공략 메모 {{ opened.has(stage.label) ? '▲' : '▼' }}
          </button>
        </div>

        <p v-if="stage.summary && opened.has(stage.label)" class="sgr-challenge-summary">
          {{ stage.summary }}
        </p>

        <ul v-if="stage.extra_videos?.length" class="sgr-challenge-extra">
          <li v-for="video in stage.extra_videos" :key="video.url">
            <a :href="video.url" target="_blank" rel="noopener" class="sgr-challenge-extra-link">
              <span class="sgr-challenge-extra-src" :class="`is-${video.source}`">
                {{ video.source === 'dc' ? '디시' : 'YT' }}
              </span>
              {{ video.title }}
            </a>
          </li>
        </ul>
      </article>
    </div>

    <p class="sgr-challenge-source">
      출처: <a :href="event?.source_url" target="_blank" rel="noopener">아카라이브 이벤트 올인원 공략</a>
      — 조합 세부(스킬 타이밍·배치)는 각 스테이지 영상을 참고하세요
    </p>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { raidApi } from '../api';

const props = defineProps({
  gameSlug: { type: String, required: true },
  pool: { type: Object, default: () => ({}) }, // 내 풀: { [external_key]: { owned, ... } }
});

const event = ref(null);
const stages = ref([]);
const loaded = ref(false);
const opened = ref(new Set());
const cache = new Map();

// === 내 풀 조합 ===
const myParties = ref({}); // stage.id → party[]
const myPartyLoading = ref(false);
const myPartyLoaded = ref(false);

const ownedKeys = computed(() => Object.entries(props.pool)
  .filter(([, v]) => v?.owned)
  .map(([k]) => k));
const hasPool = computed(() => ownedKeys.value.length > 0);
const anyMissing = computed(() => stages.value.some(
  (s) => (s.best_party || []).some((m) => !props.pool[m.key]?.owned),
));

function isOwned(key) {
  return !!props.pool[key]?.owned;
}

// === 캐릭터 마스터 참조(이미지·스트라이커/스페셜) ===
const charMap = ref({});
const charCache = new Map();

async function loadCharacters() {
  try {
    if (!charCache.has(props.gameSlug)) {
      const { data } = await raidApi.getCharacters(props.gameSlug); // { data: [...], meta }
      charCache.set(props.gameSlug, Object.fromEntries((data || []).map((c) => [c.external_key, c])));
    }
    charMap.value = charCache.get(props.gameSlug);
  } catch (e) {
    console.error('캐릭터 목록 로드 실패', e);
    charMap.value = {};
  }
}

function charImage(key) {
  const c = charMap.value[key];
  return c?.display_image_url || c?.image_url || null;
}

function roleOf(key) {
  return charMap.value[key]?.traits?.role || null;
}

function roleClass(key) {
  const role = roleOf(key);
  return role ? `is-role-${role}` : '';
}

// 스트라이커 → 스페셜 → 미상 순 정렬
function sortedParty(party) {
  const order = { striker: 0, special: 1 };
  return [...party].sort((a, b) => (order[roleOf(a.key)] ?? 2) - (order[roleOf(b.key)] ?? 2));
}

// 스트라이커 그룹과 스페셜 그룹 사이 구분선 위치
function isRoleBoundary(sorted, i) {
  return i > 0 && roleOf(sorted[i].key) === 'special' && roleOf(sorted[i - 1].key) === 'striker';
}

async function loadMyParties() {
  myPartyLoading.value = true;
  try {
    const result = {};
    for (const stage of stages.value) {
      if (!stage.best_party?.length) continue;
      result[stage.id] = await raidApi.getEventChallengeMyParty(stage.id, ownedKeys.value);
    }
    myParties.value = result;
    myPartyLoaded.value = true;
  } catch (e) {
    console.error('내 풀 조합 계산 실패', e);
  } finally {
    myPartyLoading.value = false;
  }
}

const period = computed(() => {
  if (!event.value?.starts_at) return '';
  const fmt = (d) => d?.slice(5).replace('-', '.');
  return `${fmt(event.value.starts_at)} ~ ${fmt(event.value.ends_at) ?? ''}`;
});

function toggle(label) {
  opened.value.has(label) ? opened.value.delete(label) : opened.value.add(label);
  opened.value = new Set(opened.value);
}

async function load() {
  loaded.value = false;
  myParties.value = {};
  myPartyLoaded.value = false;
  try {
    if (!cache.has(props.gameSlug)) {
      cache.set(props.gameSlug, await raidApi.getEventChallenges(props.gameSlug));
    }
    const data = cache.get(props.gameSlug);
    event.value = data.event;
    stages.value = data.stages || [];
  } catch (e) {
    console.error('이벤트 챌린지 로드 실패', e);
    stages.value = [];
  } finally {
    loaded.value = true;
  }
}

watch(() => props.gameSlug, () => { load(); loadCharacters(); });
onMounted(() => { load(); loadCharacters(); });
</script>
