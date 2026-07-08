<template>
  <div class="sgr-raid-module">
    <p v-if="sorted.length === 0" class="sgr-empty">
      수집된 레이드 일정이 없습니다. 아래 커뮤니티 공략글 피드를 참고해 주세요.
    </p>

    <div v-else class="sgr-raid-grid">
      <button
        v-for="raid in sorted"
        :key="raid.id"
        class="sgr-raid-card"
        :class="`is-${raid.status}`"
        @click="$emit('select', raid)"
      >
        <div class="sgr-raid-top">
          <span class="sgr-status-badge" :class="`is-${raid.status}`">{{ statusLabel(raid) }}</span>
          <span v-if="raid.raid_type" class="sgr-type-badge">{{ raid.raid_type }}</span>
        </div>
        <h3 class="sgr-raid-name">{{ raid.name }}</h3>
        <div class="sgr-tag-row">
          <span v-for="(value, key) in raid.tags ?? {}" :key="key" class="sgr-tag" v-show="value">
            {{ value }}
          </span>
        </div>
        <p class="sgr-raid-period">
          {{ formatPeriod(raid) }}
          <strong v-if="raid.status === 'active' && raid.ends_at" class="sgr-countdown">
            · {{ remaining(raid.ends_at) }} 남음
          </strong>
        </p>
        <p class="sgr-raid-counts">
          추천 편성 {{ raid.parties_count }} · 공략글 {{ raid.guide_posts_count }}
          <span v-if="raid.substitutes_count > 0" class="sgr-sub-count-badge">대체정보 {{ raid.substitutes_count }}</span>
        </p>
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  raids: { type: Array, required: true },
});

defineEmits(['select']);

// 진행 중 → 예정 → 종료(최신순, 최대 3개만)
const sorted = computed(() => {
  const order = { active: 0, upcoming: 1, ended: 2 };
  return [...props.raids]
    .sort((a, b) => order[a.status] - order[b.status])
    .filter((r, i, arr) => r.status !== 'ended' || arr.slice(0, i).filter((x) => x.status === 'ended').length < 3);
});

function statusLabel(raid) {
  return { active: '진행 중', upcoming: '예정', ended: '종료' }[raid.status] ?? raid.status;
}

function formatDate(iso) {
  if (!iso) return '?';
  const d = new Date(iso);
  return `${d.getMonth() + 1}.${String(d.getDate()).padStart(2, '0')}`;
}

function formatPeriod(raid) {
  if (!raid.starts_at && !raid.ends_at) return '기간 미상';
  return `${formatDate(raid.starts_at)} ~ ${formatDate(raid.ends_at)}`;
}

function remaining(endIso) {
  const diff = new Date(endIso) - Date.now();
  if (diff <= 0) return '곧 종료';
  const days = Math.floor(diff / 86400000);
  const hours = Math.floor((diff % 86400000) / 3600000);
  return days > 0 ? `${days}일 ${hours}시간` : `${hours}시간`;
}
</script>
