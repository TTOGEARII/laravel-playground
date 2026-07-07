<template>
  <div class="sgr-detail">
    <button class="sgr-back" @click="$emit('back')">← 대시보드로</button>

    <!-- 보스/회차 정보 -->
    <header class="sgr-detail-head">
      <span class="sgr-game-icon">{{ raid.game.icon }}</span>
      <div>
        <h2 class="sgr-detail-title">{{ raid.name }}</h2>
        <div class="sgr-tag-row">
          <span v-if="raid.raid_type" class="sgr-type-badge">{{ raid.raid_type }}</span>
          <span v-for="(value, key) in raid.tags ?? {}" :key="key" class="sgr-tag" v-show="value">{{ value }}</span>
          <span class="sgr-status-badge" :class="`is-${raid.status}`">{{ statusLabel }}</span>
        </div>
        <p class="sgr-raid-period">
          {{ period }}
          <a v-if="raid.source_url" :href="raid.source_url" target="_blank" rel="noopener" class="sgr-source-link">
            출처 ↗
          </a>
        </p>
      </div>
    </header>

    <!-- 세그먼트 탭 -->
    <nav class="sgr-tabbar" role="tablist">
      <button
        v-for="t in tabs"
        :key="t.key"
        type="button"
        role="tab"
        class="sgr-tabbar-btn"
        :class="{ 'is-active': activeTab === t.key }"
        :aria-selected="activeTab === t.key"
        @click="activeTab = t.key"
      >
        {{ t.label }}
        <span v-if="t.count != null" class="sgr-tab-count">{{ t.count }}</span>
      </button>
    </nav>

    <!-- 추천 편성 -->
    <section v-show="activeTab === 'parties'" class="sgr-section">
      <div class="sgr-section-bar">
        <p class="sgr-legend">
          <span class="sgr-legend-owned">■</span> 보유 ·
          <span class="sgr-legend-missing">■</span> 미보유
          <template v-if="composeMode"> · <span class="sgr-legend-sub">■</span> 내 대체 캐릭터로 채움</template>
        </p>
        <button
          v-if="raid.parties.length > 0"
          type="button"
          class="sgr-compose-toggle"
          :class="{ 'is-on': composeMode }"
          :aria-pressed="composeMode"
          @click="composeMode = !composeMode"
        >
          🧩 대체로 채우기
        </button>
      </div>
      <p v-if="raid.parties.length === 0" class="sgr-empty">
        수집된 추천 편성이 없습니다. <b>공략글</b> 탭을 참고하세요.
      </p>
      <div class="sgr-party-list">
        <PartyCard
          v-for="party in raid.parties"
          :key="party.id"
          :party="party"
          :pool="pool"
          :compose-mode="composeMode"
          :usage="usage"
        />
      </div>
    </section>

    <!-- 내 풀 조합 — 핵심 캐릭터 요약 + 미보유 제외 실전 편성 (블아·니케) -->
    <section v-if="hasAltParties" v-show="activeTab === 'compose'" class="sgr-section">
      <CoreCharacters
        :characters="coreCharacters"
        :max-count="maxCount"
        :pool="pool"
        :required="requiredKeys"
        @toggle="toggleRequired"
      />
      <AlternativeParties :raid="raid" :pool="pool" :include="requiredKeys" />
    </section>

    <!-- 커뮤니티 공략글 -->
    <section v-show="activeTab === 'guides'" class="sgr-section">
      <p v-if="raid.guide_posts.length === 0" class="sgr-empty">이 레이드에 연결된 공략글이 아직 없습니다.</p>
      <ul class="sgr-guide-list">
        <li v-for="post in raid.guide_posts" :key="post.url">
          <a :href="post.url" target="_blank" rel="noopener" class="sgr-guide-item">
            <span class="sgr-guide-source" :class="`is-${post.source}`">
              {{ post.source === 'dc' ? '디시 개념글' : '아카 추천글' }}
            </span>
            <span class="sgr-guide-title">{{ post.title }}</span>
            <span class="sgr-guide-meta">{{ formatDateTime(post.posted_at) }} · 조회 {{ post.views.toLocaleString() }}</span>
          </a>
        </li>
      </ul>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { raidApi } from '../api';
import AlternativeParties from './AlternativeParties.vue';
import CoreCharacters from './CoreCharacters.vue';
import PartyCard from './PartyCard.vue';

const props = defineProps({
  raid: { type: Object, required: true },
  pool: { type: Object, default: () => ({}) },
});

defineEmits(['back']);

// 실전 편성(원본 랭킹 프록시)이 있는 게임 = 블아·니케
const hasAltParties = computed(() => ['bluearchive', 'nikke'].includes(props.raid.game?.slug));

// 세그먼트 탭: 추천 편성 / 내 풀 조합(블아·니케) / 공략글
const tabs = computed(() => [
  { key: 'parties', label: '추천 편성', count: props.raid.parties.length },
  ...(hasAltParties.value ? [{ key: 'compose', label: '내 풀 조합', count: null }] : []),
  { key: 'guides', label: '공략글', count: props.raid.guide_posts.length },
]);
const activeTab = ref('parties');

// "대체로 채우기" 토글 — 추천 편성 카드에서 미보유 슬롯을 보유 대체 캐릭터로 치환해 보여준다
const composeMode = ref(false);

// 학생별 출전 통계(블아 전용) — 대체 후보 팝오버 빈도 + 핵심 캐릭터 요약 카드
const usage = ref({});
const coreCharacters = ref([]);
const maxCount = ref(0);
// 실전 편성에 "꼭 포함"할 캐릭터 external_key (핵심 캐릭터 카드에서 토글)
const requiredKeys = ref([]);

function toggleRequired(key) {
  requiredKeys.value = requiredKeys.value.includes(key)
    ? requiredKeys.value.filter((k) => k !== key)
    : [...requiredKeys.value, key].slice(0, 6); // 파티 슬롯 상 6명 상한
}

onMounted(async () => {
  if (props.raid.game?.slug !== 'bluearchive') return;
  try {
    const res = await raidApi.getStudentUsage(props.raid.id);
    if (res.supported) {
      usage.value = res.usage ?? {};
      coreCharacters.value = res.characters ?? [];
      maxCount.value = res.max_count ?? 0;
    }
  } catch (e) {
    console.warn('출전 통계 조회 실패 — 표시 생략', e);
  }
});

const statusLabel = computed(() => ({ active: '진행 중', upcoming: '예정', ended: '종료' }[props.raid.status]));

const period = computed(() => {
  const fmt = (iso) => {
    if (!iso) return '?';
    const d = new Date(iso);
    return `${d.getFullYear()}.${String(d.getMonth() + 1).padStart(2, '0')}.${String(d.getDate()).padStart(2, '0')}`;
  };
  if (!props.raid.starts_at && !props.raid.ends_at) return '기간 미상';
  return `${fmt(props.raid.starts_at)} ~ ${fmt(props.raid.ends_at)}`;
});

function formatDateTime(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  return `${d.getMonth() + 1}.${String(d.getDate()).padStart(2, '0')} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}
</script>
