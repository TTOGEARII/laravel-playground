<template>
  <div class="sgr-subpicker-backdrop" @click.self="$emit('close')">
    <div class="sgr-subpicker" role="dialog" aria-modal="true">
      <div class="sgr-subpicker-head">
        <strong>{{ target.name }}</strong> 대신 쓸 내 캐릭터
        <button type="button" class="sgr-subpicker-close" aria-label="닫기" @click="$emit('close')">×</button>
      </div>
      <input
        v-model="query"
        type="search"
        class="sgr-subpicker-search"
        placeholder="보유 캐릭터 검색"
      />

      <!-- Gemini 대체 추천 — 내 보유 중에서 골라준다(클릭하면 바로 지정) -->
      <div class="sgr-subpicker-ai">
        <button
          v-if="aiRecs === null && !aiLoading"
          type="button"
          class="sgr-btn sgr-subpicker-ai-btn"
          :disabled="ownedCandidates.length === 0"
          @click="askGemini"
        >✨ Gemini에게 대체 추천받기</button>
        <p v-if="aiLoading" class="sgr-subpicker-ai-loading">Gemini가 내 보유 캐릭터에서 대체 후보를 고르는 중…</p>
        <p v-else-if="aiError" class="sgr-subpicker-ai-error">{{ aiError }}</p>
        <template v-else-if="aiRecs !== null">
          <p v-if="aiRecs.length === 0" class="sgr-subpicker-ai-empty">
            내 보유 캐릭터 중에는 마땅한 대체 후보를 찾지 못했어요.
          </p>
          <div v-else class="sgr-subpicker-ai-list">
            <button
              v-for="rec in aiRecs"
              :key="rec.external_key"
              type="button"
              class="sgr-subpicker-ai-item"
              @click="$emit('pick', rec.external_key)"
            >
              <img v-if="rec.image_url" :src="rec.image_url" :alt="rec.name" loading="lazy" />
              <span v-else class="sgr-member-placeholder">{{ rec.name.slice(0, 2) }}</span>
              <span class="sgr-subpicker-ai-body">
                <span class="sgr-subpicker-ai-name">✨ {{ rec.name }}</span>
                <span v-if="rec.reason" class="sgr-subpicker-ai-reason">{{ rec.reason }}</span>
              </span>
            </button>
          </div>
        </template>
      </div>

      <p v-if="ownedCandidates.length === 0" class="sgr-empty">
        {{ query ? '검색 결과가 없어요.' : '내 캐릭터 탭에서 보유 캐릭터를 먼저 체크해 주세요.' }}
      </p>
      <div class="sgr-subpicker-grid">
        <button
          v-for="c in ownedCandidates"
          :key="c.external_key"
          type="button"
          class="sgr-subpicker-item"
          :class="{ 'is-current': currentKey === c.external_key }"
          @click="$emit('pick', c.external_key)"
        >
          <img v-if="c.image_url" :src="c.image_url" :alt="c.name" loading="lazy" />
          <span v-else class="sgr-member-placeholder">{{ c.name.slice(0, 2) }}</span>
          <span class="sgr-subpicker-name">{{ c.name }}</span>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { raidApi } from '../api';

/**
 * 대체 캐릭터 지정 피커(공유) — 내 풀 조합(니케)과 추천 편성 카드(블아 종전시 등)가 함께 쓴다.
 * 캐릭터 목록은 자체 로드(게임별 모듈 캐시), Gemini 추천은 대상 캐릭터별 세션 캐시.
 */
const props = defineProps({
  raidId: { type: Number, required: true },
  gameSlug: { type: String, required: true },
  target: { type: Object, required: true }, // 대체할 미보유 캐릭터 { external_key, name }
  pool: { type: Object, default: () => ({}) },
  currentKey: { type: String, default: null }, // 현재 지정된 대체 external_key(하이라이트)
});

defineEmits(['pick', 'close']);

// 게임별 캐릭터 목록 / 캐릭터별 Gemini 추천 — 모듈 레벨 캐시(피커 재오픈·컴포넌트 간 공유)
const characterCache = new Map();
const aiCache = new Map();

const characters = ref([]);
const query = ref('');
const aiRecs = ref(null);
const aiLoading = ref(false);
const aiError = ref('');

const ownedCandidates = computed(() => {
  const q = query.value.trim().toLowerCase();
  return characters.value
    .filter((c) => props.pool[c.external_key]?.owned === true && c.external_key !== props.target.external_key)
    .filter((c) => !q || c.name.toLowerCase().includes(q))
    .sort((a, b) => a.name.localeCompare(b.name, 'ko'));
});

async function askGemini() {
  if (aiLoading.value) return;
  const cacheKey = `${props.raidId}:${props.target.external_key}`;
  if (aiCache.has(cacheKey)) {
    aiRecs.value = aiCache.get(cacheKey);
    return;
  }
  aiLoading.value = true;
  aiError.value = '';
  try {
    const ownedKeys = Object.keys(props.pool).filter((k) => props.pool[k]?.owned).slice(0, 500);
    const res = await raidApi.getSubstituteRecommendations(props.raidId, props.target.external_key, ownedKeys);
    if (res.supported === false) {
      aiError.value = 'AI 추천을 사용할 수 없어요(서버에 API 키 미설정).';
      return;
    }
    aiRecs.value = res.recommendations ?? [];
    aiCache.set(cacheKey, aiRecs.value);
  } catch (e) {
    aiError.value = e.response?.status === 429
      ? '추천 요청이 너무 잦아요. 잠시 후 다시 시도해 주세요.'
      : 'AI 추천을 불러오지 못했어요. 잠시 후 다시 시도해 주세요.';
    console.error('대체 추천 실패', e);
  } finally {
    aiLoading.value = false;
  }
}

onMounted(async () => {
  try {
    if (!characterCache.has(props.gameSlug)) {
      const res = await raidApi.getCharacters(props.gameSlug);
      characterCache.set(props.gameSlug, res.data);
    }
    characters.value = characterCache.get(props.gameSlug);
  } catch (e) {
    console.error('캐릭터 목록 로드 실패', e);
  }
});
</script>
