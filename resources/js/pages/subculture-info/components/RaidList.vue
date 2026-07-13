<template>
  <div class="sgr-raid-module">
    <p v-if="raids.length === 0" class="sgr-empty">
      수집된 레이드 일정이 없습니다. 아래 커뮤니티 공략글 피드를 참고해 주세요.
    </p>

    <!-- 레이드 종류가 여럿(블아: 총력전/대결전/종전시)이면 종류별 섹션으로 나눠 보여준다 -->
    <section v-for="group in groups" :key="group.type" class="sgr-raid-type">
      <h3 v-if="groups.length > 1" class="sgr-raid-type-head">
        {{ group.label }}
        <span v-if="group.activeCount > 0" class="sgr-raid-type-badge">진행 중 {{ group.activeCount }}</span>
        <span class="sgr-raid-type-count">{{ group.total }}회차</span>
      </h3>

      <div class="sgr-raid-grid">
        <button
          v-for="raid in group.visible"
          :key="raid.id"
          class="sgr-raid-card"
          :class="`is-${raid.status}`"
          @click="$emit('select', raid)"
        >
          <div class="sgr-raid-top">
            <span class="sgr-status-badge" :class="`is-${raid.status}`">{{ statusLabel(raid) }}</span>
            <span v-if="raid.raid_type && groups.length === 1" class="sgr-type-badge">{{ raid.raid_type }}</span>
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

      <!-- 종류별 역대 회차 열람 — 기본은 최근만, 펼치면 전체 -->
      <button
        v-if="group.hiddenCount > 0 || expanded[group.type]"
        type="button"
        class="sgr-btn sgr-raid-more"
        @click="toggle(group.type)"
      >
        {{ expanded[group.type] ? '지난 회차 접기' : `지난 회차 ${group.hiddenCount}개 더 보기` }}
      </button>
    </section>
  </div>
</template>

<script setup>
import { computed, reactive } from 'vue';

const props = defineProps({
  raids: { type: Array, required: true },
});

defineEmits(['select']);

const ENDED_PREVIEW = 3;

// 종류별 "지난 회차" 펼침 상태
const expanded = reactive({});

function toggle(type) {
  expanded[type] = !expanded[type];
}

// 화면 표기용 짧은 라벨 + 섹션 순서(알려진 종류 우선, 나머지는 이름순)
const TYPE_LABELS = { '종합전술시험': '종합전술시험(종전시)' };
const TYPE_ORDER = ['총력전', '대결전', '제약해제결전', '종합전술시험', '솔로 레이드', '프론티어'];

const groups = computed(() => {
  const order = { active: 0, upcoming: 1, ended: 2 };
  const byType = new Map();
  for (const raid of props.raids) {
    const type = raid.raid_type ?? '기타';
    if (!byType.has(type)) byType.set(type, []);
    byType.get(type).push(raid);
  }

  return [...byType.entries()]
    .sort(([a], [b]) => {
      const ai = TYPE_ORDER.indexOf(a);
      const bi = TYPE_ORDER.indexOf(b);
      return (ai === -1 ? 99 : ai) - (bi === -1 ? 99 : bi) || a.localeCompare(b, 'ko');
    })
    .map(([type, list]) => {
      const ordered = [...list].sort((a, b) => (order[a.status] - order[b.status])
        || new Date(b.starts_at ?? 0) - new Date(a.starts_at ?? 0));

      let visible = ordered;
      const totalEnded = ordered.filter((r) => r.status === 'ended').length;
      if (!expanded[type]) {
        let ended = 0;
        visible = ordered.filter((r) => r.status !== 'ended' || ++ended <= ENDED_PREVIEW);
      }

      return {
        type,
        label: TYPE_LABELS[type] ?? type,
        total: list.length,
        activeCount: list.filter((r) => r.status === 'active').length,
        hiddenCount: Math.max(0, totalEnded - ENDED_PREVIEW),
        visible,
      };
    });
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
