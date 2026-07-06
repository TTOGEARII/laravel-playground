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

    <!-- 추천 편성 -->
    <section class="sgr-section">
      <h3 class="sgr-section-title">
        추천 편성 <span class="sgr-count">{{ raid.parties.length }}</span>
        <button
          v-if="raid.parties.length > 0"
          type="button"
          class="sgr-compose-toggle"
          :class="{ 'is-on': composeMode }"
          :aria-pressed="composeMode"
          @click="composeMode = !composeMode"
        >
          🧩 내 풀로 조합
        </button>
      </h3>
      <p v-if="raid.parties.length === 0" class="sgr-empty">
        수집된 추천 편성이 없습니다. 아래 커뮤니티 공략글을 참고하세요.
      </p>
      <div class="sgr-party-list">
        <PartyCard
          v-for="party in raid.parties"
          :key="party.id"
          :party="party"
          :pool="pool"
          :compose-mode="composeMode"
        />
      </div>
      <p v-if="raid.parties.length > 0" class="sgr-legend">
        <span class="sgr-legend-owned">■</span> 보유 ·
        <span class="sgr-legend-missing">■</span> 미보유 (내 캐릭터에서 보유 등록 시 반영)
        <template v-if="composeMode">
          · <span class="sgr-legend-sub">■</span> 대체 투입 — 미보유 슬롯을 내가 보유한 대체 캐릭터로 치환해 표시
        </template>
      </p>
    </section>

    <!-- 커뮤니티 공략글 -->
    <section class="sgr-section">
      <h3 class="sgr-section-title">커뮤니티 공략글 <span class="sgr-count">{{ raid.guide_posts.length }}</span></h3>
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
import { computed, ref } from 'vue';
import PartyCard from './PartyCard.vue';

const props = defineProps({
  raid: { type: Object, required: true },
  pool: { type: Object, default: () => ({}) },
});

defineEmits(['back']);

// "내 풀로 조합" 토글 — 편성 카드에서 미보유 슬롯을 보유 대체 캐릭터로 치환해 보여준다
const composeMode = ref(false);

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
