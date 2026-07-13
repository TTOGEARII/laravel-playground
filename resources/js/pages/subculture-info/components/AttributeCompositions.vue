<template>
  <div v-if="groups.length" class="sgr-attr">
    <h3 class="sgr-attr-title">🎭 속성별 추천 조합</h3>
    <p class="sgr-attr-desc">
      팀 매니저 큐레이션 + 트릭컬 레코드 시즌 실측(사용률)을 성격별로 모았어요.
    </p>

    <!-- 속성 탭: 우울 · 활발 · 순수 · 냉정 · 광기 -->
    <div class="sgr-attr-tabs" role="tablist" aria-label="성격">
      <button
        v-for="g in groups"
        :key="g.attribute"
        type="button"
        role="tab"
        class="sgr-attr-tab"
        :class="[`is-${g.attribute.toLowerCase()}`, { 'is-active': active === g.attribute }]"
        :aria-selected="active === g.attribute"
        @click="active = g.attribute"
      >{{ g.label }}</button>
    </div>

    <div v-for="party in activeParties" :key="`${party.kind}-${party.title}`" class="sgr-attr-party">
      <div class="sgr-attr-party-head">
        <span class="sgr-attr-kind" :class="`is-${party.kind}`">
          {{ party.kind === 'curated' ? '추천' : '실측' }}
        </span>
        <strong class="sgr-attr-party-title">{{ party.title }}</strong>
        <span v-if="party.period" class="sgr-attr-period">{{ party.period }}</span>
        <a
          v-if="party.source_url"
          :href="party.source_url"
          target="_blank"
          rel="noopener"
          class="sgr-source-link"
        >{{ sourceName(party.source) }} ↗</a>
      </div>

      <!-- 포지션 그룹: 전열 → 중열 → 후열 -->
      <div class="sgr-attr-rows">
        <div v-for="row in positionRows(party)" :key="row.key" class="sgr-attr-row">
          <span class="sgr-attr-pos">{{ row.label }}</span>
          <div class="sgr-attr-members">
            <template v-for="m in row.members" :key="m.external_key">
              <!-- 미보유 + 내가 지정한 대체 → 대체 캐릭터로 표시 -->
              <div
                v-if="isUnowned(m) && substituteFor(m)"
                class="sgr-attr-member is-user-sub"
                :title="`${m.name} 대신 ${substituteFor(m).name} (클릭해서 변경)`"
                @click="openPicker(m)"
              >
                <button type="button" class="sgr-alt-sub-clear" title="대체 해제" @click.stop="clearSubstitute(m)">×</button>
                <img v-if="substituteFor(m).image_url" :src="substituteFor(m).image_url" :alt="substituteFor(m).name" loading="lazy" />
                <span v-else class="sgr-member-placeholder">{{ substituteFor(m).name.slice(0, 2) }}</span>
                <span class="sgr-attr-member-name">{{ substituteFor(m).name }}</span>
                <span class="sgr-attr-aside">{{ m.name }} 대신</span>
              </div>
              <div
                v-else
                class="sgr-attr-member"
                :class="{ 'is-unowned': isUnowned(m), 'is-clickable': canPick(m) }"
                :title="isUnowned(m) ? `${m.name} — 미보유${canPick(m) ? ' (클릭해서 대체 지정)' : ''}` : m.name"
                @click="canPick(m) && openPicker(m)"
              >
                <img v-if="m.image_url" :src="m.image_url" :alt="m.name" loading="lazy" />
                <span v-else class="sgr-member-placeholder">{{ m.name.slice(0, 2) }}</span>
                <span class="sgr-attr-member-name">{{ m.name }}</span>
                <span v-if="isUnowned(m)" class="sgr-attr-aside">미보유{{ canPick(m) ? ' · 대체+' : '' }}</span>
                <span v-else-if="m.meta?.usage_pct != null" class="sgr-attr-usage">{{ m.meta.usage_pct }}%</span>
                <span v-else-if="m.meta?.aside" class="sgr-attr-aside">+{{ m.meta.aside }}</span>
              </div>
            </template>
          </div>
        </div>
      </div>
    </div>

    <!-- 대체 지정 피커(공유) — Gemini 추천 컨텍스트는 최신 레이드 -->
    <SubstitutePicker
      v-if="picker && contextRaidId"
      :raid-id="contextRaidId"
      :game-slug="gameSlug"
      :target="picker"
      :pool="pool"
      :current-key="userSubs[picker.external_key] ?? null"
      @pick="pickSubstitute"
      @close="picker = null"
    />
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { raidApi } from '../api';
import SubstitutePicker from './SubstitutePicker.vue';

const props = defineProps({
  gameSlug: { type: String, required: true },
  pool: { type: Object, default: () => ({}) }, // 내 풀(보유 하이라이트)
  userSubs: { type: Object, default: () => ({}) }, // 내 대체 매핑
  raids: { type: Array, default: () => [] }, // Gemini 추천 컨텍스트용(최신 레이드)
});

const emit = defineEmits(['set-substitute', 'clear-substitute']);

const groups = ref([]);
const active = ref(null);
const picker = ref(null);
const characters = ref([]); // 대체 캐릭터 표시용 마스터(이미지·이름)

// 풀을 아직 안 만든 사용자에겐 하이라이트를 끈다(전부 흑백이 되는 역효과 방지)
const poolReady = computed(() => Object.values(props.pool).some((e) => e?.owned));

// Gemini 추천 컨텍스트: 가장 최근 시작한 레이드
const contextRaidId = computed(() => [...props.raids]
  .sort((a, b) => new Date(b.starts_at ?? 0) - new Date(a.starts_at ?? 0))[0]?.id ?? null);

function isUnowned(m) {
  return poolReady.value && props.pool[m.external_key]?.owned !== true;
}

function canPick(m) {
  return isUnowned(m) && contextRaidId.value !== null;
}

function substituteFor(m) {
  const key = props.userSubs[m.external_key];
  if (!key) return null;
  return characters.value.find((c) => c.external_key === key) ?? null;
}

function openPicker(m) {
  picker.value = { external_key: m.external_key, name: m.name };
}

function pickSubstitute(substituteKey) {
  emit('set-substitute', { gameSlug: props.gameSlug, characterKey: picker.value.external_key, substituteKey });
  picker.value = null;
}

function clearSubstitute(m) {
  emit('clear-substitute', { gameSlug: props.gameSlug, characterKey: m.external_key });
}

const activeParties = computed(() => groups.value.find((g) => g.attribute === active.value)?.parties ?? []);

const POSITION_LABELS = { front: '전열', middle: '중열', back: '후열' };

function positionRows(party) {
  return Object.entries(POSITION_LABELS)
    .map(([key, label]) => ({ key, label, members: party.members.filter((m) => m.position === key) }))
    .concat([{ key: 'etc', label: '기타', members: party.members.filter((m) => !POSITION_LABELS[m.position]) }])
    .filter((row) => row.members.length > 0);
}

function sourceName(source) {
  return { 'team-manager': '팀 매니저', trickcalrecord: '트릭컬 레코드' }[source] ?? source;
}

onMounted(async () => {
  try {
    const res = await raidApi.getAttributeParties(props.gameSlug);
    if (res.supported === false) return;
    groups.value = (res.groups ?? []).filter((g) => g.parties.length > 0);
    active.value = groups.value[0]?.attribute ?? null;
    // 대체 지정 캐릭터의 이미지·이름 해석용 마스터(그룹이 있을 때만)
    if (groups.value.length > 0) {
      const chars = await raidApi.getCharacters(props.gameSlug);
      characters.value = chars.data ?? [];
    }
  } catch (e) {
    console.error('속성별 조합 로드 실패', e);
  }
});
</script>
