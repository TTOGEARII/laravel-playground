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
            <div v-for="m in row.members" :key="m.external_key" class="sgr-attr-member" :title="m.name">
              <img v-if="m.image_url" :src="m.image_url" :alt="m.name" loading="lazy" />
              <span v-else class="sgr-member-placeholder">{{ m.name.slice(0, 2) }}</span>
              <span class="sgr-attr-member-name">{{ m.name }}</span>
              <span v-if="m.meta?.usage_pct != null" class="sgr-attr-usage">{{ m.meta.usage_pct }}%</span>
              <span v-else-if="m.meta?.aside" class="sgr-attr-aside">+{{ m.meta.aside }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { raidApi } from '../api';

const props = defineProps({
  gameSlug: { type: String, required: true },
});

const groups = ref([]);
const active = ref(null);

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
  } catch (e) {
    console.error('속성별 조합 로드 실패', e);
  }
});
</script>
