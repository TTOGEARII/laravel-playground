<template>
  <div class="sgr-raid-module">
    <p v-if="raids.length === 0" class="sgr-empty">
      수집된 레이드 일정이 없습니다. 아래 커뮤니티 공략글 피드를 참고해 주세요.
    </p>

    <!-- 종류를 나누지 않고 진행중 → 다음 회차 → 최근 종료를 한 목록으로(몰루로그 홈 방식) -->
    <div class="sgr-raid-list">
      <button
        v-for="raid in visibleRaids"
        :key="raid.id"
        class="sgr-raid-hero"
        :class="[`is-${raid.status}`, { 'has-image': !!bossImage(raid) }]"
        :style="heroStyle(raid)"
        @click="$emit('select', raid)"
      >
        <span class="sgr-rh-status" :class="`is-${raid.status}`">{{ statusLabel(raid) }}</span>

        <div class="sgr-rh-bottom">
          <div class="sgr-rh-left">
            <span class="sgr-rh-meta">{{ metaLine(raid) }}</span>
            <span class="sgr-rh-name">{{ raid.boss_name || raid.name }}</span>
            <span class="sgr-rh-sub">
              {{ formatPeriod(raid) }}
              <template v-if="raid.parties_count || raid.guide_posts_count">
                · 편성 {{ raid.parties_count }} · 공략 {{ raid.guide_posts_count }}</template>
              <template v-if="raid.substitutes_count > 0"> · 대체 {{ raid.substitutes_count }}</template>
            </span>
          </div>

          <!-- 장갑별 난이도(몰루로그 카드 스타일) — 없으면 기존 태그 pill 로 폴백 -->
          <div v-if="armors(raid).length" class="sgr-rh-armors">
            <span v-for="(a, i) in armors(raid)" :key="i" class="sgr-rh-armor">
              <b class="sgr-rh-armor-pill" :class="armorClass(a.type)">{{ a.type }}</b>
              <i v-if="a.difficulty" class="sgr-rh-difficulty">{{ a.difficulty }}</i>
            </span>
          </div>
          <div v-else class="sgr-tag-row sgr-rh-tags">
            <span v-for="(value, key) in scalarTags(raid)" :key="key" class="sgr-tag">{{ value }}</span>
          </div>
        </div>
      </button>
    </div>

    <!-- 지난 회차·먼 미래 회차 열람 — 기본은 핵심만, 펼치면 전체 -->
    <button
      v-if="hiddenCount > 0 || expanded"
      type="button"
      class="sgr-btn sgr-raid-more"
      @click="expanded = !expanded"
    >
      {{ expanded ? '접기' : `예정·지난 회차 ${hiddenCount}개 보기` }}
    </button>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
  raids: { type: Array, required: true },
});

defineEmits(['select']);

const expanded = ref(false);

const TYPE_LABELS = { 종합전술시험: '종전시' };

// 진행중(종료 임박순) → 예정(임박순) → 종료(최근순)
const orderedRaids = computed(() => {
  const rank = { active: 0, upcoming: 1, ended: 2 };
  return [...props.raids].sort((a, b) => {
    if (rank[a.status] !== rank[b.status]) return rank[a.status] - rank[b.status];
    if (a.status === 'ended') return new Date(b.starts_at ?? 0) - new Date(a.starts_at ?? 0);
    return new Date(a.starts_at ?? 0) - new Date(b.starts_at ?? 0);
  });
});

// 기본 노출 = 진행 중인 레이드만. 진행 중이 하나도 없으면 임박한 다음 회차 1개만 보여준다.
const defaultRaids = computed(() => {
  const active = orderedRaids.value.filter((r) => r.status === 'active');
  if (active.length) return active;
  const next = orderedRaids.value.find((r) => r.status === 'upcoming');
  return next ? [next] : [];
});

const hiddenCount = computed(() => orderedRaids.value.length - defaultRaids.value.length);

const visibleRaids = computed(() => (expanded.value ? orderedRaids.value : defaultRaids.value));

/* ---- 카드 데이터 헬퍼 ---- */

function ml(raid) {
  return raid.tags?.mollulog ?? null;
}

function bossImage(raid) {
  return ml(raid)?.boss_image ?? null;
}

function armors(raid) {
  return ml(raid)?.armors ?? [];
}

const ARMOR_CLASSES = { 경장갑: 'is-red', 중장갑: 'is-amber', 특수장갑: 'is-blue', 탄력장갑: 'is-purple' };
function armorClass(type) {
  return ARMOR_CLASSES[type] ?? '';
}

function heroStyle(raid) {
  const img = bossImage(raid);
  if (!img) return null;
  // 우측 보스 아트 + 좌측 가독성용 어두운 그라데이션(몰루로그 카드)
  return {
    backgroundImage: `linear-gradient(90deg, rgba(10,10,12,0.92) 18%, rgba(10,10,12,0.45) 55%, rgba(10,10,12,0.25)), url('${img}')`,
  };
}

/** "대결전 #31 · 시가지" — 회차·지형 메타 라인 */
function metaLine(raid) {
  const type = TYPE_LABELS[raid.raid_type] ?? raid.raid_type ?? '';
  const season = ml(raid)?.season_index ?? raid.name?.match(/#(\d+)/)?.[1] ?? null;
  const terrain = raid.tags?.terrain ?? raid.tags?.['지형'] ?? null;
  return [type + (season ? ` #${season}` : ''), terrain].filter(Boolean).join(' · ');
}

/** 몰루로그식 스칼라 태그만(중첩 mollulog 블록·긴 설명 제외) */
function scalarTags(raid) {
  const out = {};
  for (const [k, v] of Object.entries(raid.tags ?? {})) {
    if (v && typeof v !== 'object' && String(v).length <= 20) out[k] = v;
  }
  return out;
}

/* ---- 상태·기간 라벨 ---- */

function daysUntil(iso) {
  return Math.ceil((new Date(iso) - Date.now()) / 86400000);
}

function statusLabel(raid) {
  if (raid.status === 'upcoming' && raid.starts_at) {
    const d = daysUntil(raid.starts_at);
    return d <= 0 ? '오늘 시작' : d === 1 ? '내일 시작' : `${d}일 후 시작`;
  }
  if (raid.status === 'active') {
    if (!raid.ends_at) return '진행 중';
    const d = daysUntil(raid.ends_at);
    return d <= 0 ? '오늘 종료' : d === 1 ? '내일 종료' : `${d}일 후 종료`;
  }
  return { upcoming: '예정', ended: '종료' }[raid.status] ?? raid.status;
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
</script>
