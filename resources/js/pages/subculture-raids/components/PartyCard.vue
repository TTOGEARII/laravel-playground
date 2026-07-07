<template>
  <article class="sgr-party">
    <header class="sgr-party-head">
      <strong class="sgr-party-title">{{ party.title ?? '편성' }}</strong>
      <span v-if="party.difficulty" class="sgr-tag">{{ party.difficulty }}</span>
      <span v-if="party.source === 'manual'" class="sgr-tag is-manual">수동 입력</span>
      <span v-if="party.note" class="sgr-party-note">{{ party.note }}</span>
    </header>

    <div class="sgr-member-row">
      <div
        v-for="(slot, idx) in slots"
        :key="idx"
        class="sgr-member"
        :class="{
          'is-missing': slot.state === 'missing' || slot.state === 'vacant',
          'is-substituted': slot.state === 'substituted',
        }"
        :title="slotTitle(slot)"
      >
        <div class="sgr-member-figure">
          <img
            v-if="slot.character?.image_url"
            :src="slot.character.image_url"
            :alt="slot.character?.name"
            loading="lazy"
          />
          <span v-else class="sgr-member-placeholder">{{ slot.character?.name?.slice(0, 2) ?? '?' }}</span>

          <!-- 조합 모드 상태 배지 -->
          <span v-if="slot.state === 'substituted'" class="sgr-member-flag is-sub">대체</span>
          <span v-else-if="slot.state === 'vacant'" class="sgr-member-flag is-vacant">미보유</span>

          <!-- 대체 후보 인디케이터 (토글과 무관하게 항상 표시, 클릭 토글 팝오버) -->
          <button
            v-if="slot.substitutes.length > 0"
            type="button"
            class="sgr-sub-indicator"
            :class="{ 'is-open': openPopover === idx }"
            :aria-label="`대체 후보 ${slot.substitutes.length}명 보기`"
            @click.stop="togglePopover(idx)"
          >
            ↻ {{ slot.substitutes.length }}
          </button>
        </div>

        <span class="sgr-member-name">{{ slot.character?.name ?? '미확인' }}</span>
        <span v-if="slot.state === 'substituted'" class="sgr-member-caption is-sub">
          {{ slot.original?.name }} 대신
        </span>
        <span v-if="slot.state === 'substituted' && slot.note" class="sgr-member-caption">{{ slot.note }}</span>
        <span v-if="slot.state === 'vacant' && slot.candidateNames" class="sgr-member-caption">
          후보: {{ slot.candidateNames }}
        </span>
        <span v-if="slot.slot_type" class="sgr-member-slot">{{ slotLabel(slot.slot_type) }}</span>

        <!-- 대체 후보 팝오버 -->
        <div v-if="openPopover === idx" class="sgr-sub-popover" @click.stop>
          <p class="sgr-sub-popover-title">{{ slot.member.character?.name ?? '미확인' }} 대체 후보</p>
          <ul class="sgr-sub-popover-list">
            <li v-for="(sub, sIdx) in sortedSubstitutes(slot.substitutes)" :key="sIdx" class="sgr-sub-popover-item">
              <span class="sgr-sub-cand-name">{{ sub.character?.name ?? '미확인' }}</span>
              <span class="sgr-sub-owned-badge" :class="{ 'is-owned': isOwnedKey(sub.character?.external_key) }">
                {{ isOwnedKey(sub.character?.external_key) ? '보유' : '미보유' }}
              </span>
              <span v-if="usageCount(sub) !== null" class="sgr-sub-cand-usage" title="이 레이드 랭킹에서의 편성 횟수 (몰루로그 통계)">
                출전 {{ usageCount(sub).toLocaleString() }}회
              </span>
              <span v-if="sub.note" class="sgr-sub-cand-note">{{ sub.note }}</span>
              <a
                v-if="safeSourceUrl(sub.source_url)"
                :href="safeSourceUrl(sub.source_url)"
                target="_blank"
                rel="noopener"
                class="sgr-sub-cand-source"
              >출처 ↗</a>
            </li>
          </ul>
        </div>
      </div>
    </div>

    <!-- 조합 모드 요약 -->
    <p v-if="composeMode" class="sgr-compose-summary">
      보유 {{ summary.owned }} · 대체 {{ summary.substituted }} · 공석 {{ summary.vacant }}
    </p>
  </article>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
  party: { type: Object, required: true },
  // { external_key: { owned, growth } }
  pool: { type: Object, default: () => ({}) },
  // "내 풀로 조합" 토글 상태 — 켜면 미보유 슬롯을 보유한 대체 캐릭터로 치환해 보여준다
  composeMode: { type: Boolean, default: false },
  // 학생별 출전 횟수(블아 전용) — { external_key: { count, assist_count } }
  usage: { type: Object, default: () => ({}) },
});

const openPopover = ref(null);

function isOwnedKey(externalKey) {
  return externalKey ? props.pool[externalKey]?.owned === true : false;
}

/** 대체 후보의 이 레이드 출전 횟수(몰루로그 통계). 없으면 null. */
function usageCount(sub) {
  return props.usage[sub.character?.external_key]?.count ?? null;
}

/** 대체 후보를 실전 채용 빈도(출전 횟수) 내림차순으로 정렬해 보여준다. */
function sortedSubstitutes(substitutes) {
  return [...substitutes].sort((a, b) => (usageCount(b) ?? -1) - (usageCount(a) ?? -1));
}

/** 출처 링크는 http(s)만 허용 — 외부/수동 입력 값이 href 로 흐르는 경로라 스킴을 가드한다. */
function safeSourceUrl(url) {
  return typeof url === 'string' && /^https?:\/\//i.test(url) ? url : null;
}

/**
 * 멤버별 표시 슬롯 계산.
 * 조합 모드: ①원 캐릭터 보유 → 그대로 ②대체 후보 중 보유 있음 → 첫 보유 후보로 치환
 * ③둘 다 없음 → 공석(원 캐릭터를 흑백+점선으로 유지). 조합 모드 꺼짐 = 기존 보유/미보유 표시.
 */
const slots = computed(() => props.party.members.map((member) => {
  const substitutes = member.substitutes ?? [];
  const ownedOriginal = isOwnedKey(member.character?.external_key);
  const base = { member, slot_type: member.slot_type, substitutes };

  if (!props.composeMode || ownedOriginal) {
    return { ...base, state: ownedOriginal ? 'owned' : 'missing', character: member.character };
  }

  const ownedSub = substitutes.find((sub) => isOwnedKey(sub.character?.external_key));
  if (ownedSub) {
    return {
      ...base,
      state: 'substituted',
      character: ownedSub.character,
      original: member.character,
      note: ownedSub.note,
    };
  }

  return {
    ...base,
    state: 'vacant',
    character: member.character,
    candidateNames: substitutes.map((sub) => sub.character?.name).filter(Boolean).join(', '),
  };
}));

// 조합 모드 파티 요약: 보유 n · 대체 n · 공석 n
const summary = computed(() => slots.value.reduce(
  (acc, slot) => {
    if (slot.state === 'owned') acc.owned += 1;
    else if (slot.state === 'substituted') acc.substituted += 1;
    else acc.vacant += 1;
    return acc;
  },
  { owned: 0, substituted: 0, vacant: 0 },
));

function slotTitle(slot) {
  const name = slot.character?.name ?? '미확인';
  if (slot.state === 'substituted') {
    return `${name} — ${slot.original?.name ?? '?'} 대신 투입 (보유)${slot.note ? ` · ${slot.note}` : ''}`;
  }
  if (slot.state === 'vacant') {
    return `${name} — 미보유 (보유한 대체 후보 없음)`;
  }
  const entry = slot.character ? props.pool[slot.character.external_key] : null;
  if (!entry?.owned) return `${name} — 미보유`;
  const growth = entry.growth
    ? Object.entries(entry.growth).map(([k, v]) => `${k}: ${v}`).join(', ')
    : '성장도 미입력';
  return `${name} — 보유 (${growth})`;
}

function slotLabel(slot) {
  return { striker: 'STRIKER', special: 'SPECIAL' }[slot] ?? slot;
}

function togglePopover(idx) {
  openPopover.value = openPopover.value === idx ? null : idx;
}

// 팝오버 바깥 클릭 시 닫기 (모바일 클릭 토글 대응)
function closePopover() {
  openPopover.value = null;
}

onMounted(() => document.addEventListener('click', closePopover));
onBeforeUnmount(() => document.removeEventListener('click', closePopover));
</script>
