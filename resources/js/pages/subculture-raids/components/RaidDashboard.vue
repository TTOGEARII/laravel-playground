<template>
  <div class="sgr-dashboard">
    <p v-if="loading" class="sgr-empty">레이드 정보를 불러오는 중...</p>

    <section v-for="game in games" :key="game.slug" class="sgr-game-section">
      <header class="sgr-game-head">
        <h2 class="sgr-game-title">
          <span class="sgr-game-icon">{{ game.icon }}</span> {{ game.name }}
        </h2>
      </header>

      <p v-if="!loading && raidsOf(game.slug).length === 0" class="sgr-empty">
        수집된 레이드 일정이 없습니다. 커뮤니티 공략글은 진행 중 레이드에 연결되면 표시됩니다.
      </p>

      <div class="sgr-raid-grid">
        <button
          v-for="raid in raidsOf(game.slug)"
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
          <p class="sgr-raid-counts">추천 편성 {{ raid.parties_count }} · 공략글 {{ raid.guide_posts_count }}</p>
        </button>
      </div>
    </section>
  </div>
</template>

<script setup>
const props = defineProps({
  games: { type: Array, required: true },
  raids: { type: Array, required: true },
  loading: { type: Boolean, default: false },
});

defineEmits(['select']);

function raidsOf(slug) {
  // 진행 중 → 예정 → 종료(최신순, 최대 3개만)
  const list = props.raids.filter((r) => r.game.slug === slug);
  const order = { active: 0, upcoming: 1, ended: 2 };
  return list
    .sort((a, b) => order[a.status] - order[b.status])
    .filter((r, i, arr) => r.status !== 'ended' || arr.slice(0, i).filter((x) => x.status === 'ended').length < 3);
}

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
