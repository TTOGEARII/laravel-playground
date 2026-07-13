<template>
  <section class="sgr-module sgi-dex">
    <h3 class="sgr-module-title">📖 캐릭터정보 <small class="sgr-feed-hint">도감</small></h3>

    <!-- 검색 + 스키마 기반 동적 필터 -->
    <div class="sgi-dex-controls">
      <input v-model.trim="q" type="search" class="sgi-dex-search" placeholder="캐릭터 이름 검색" />
      <select v-for="f in filterFields" :key="f.key" v-model="filters[f.key]" class="sgi-dex-filter">
        <option value="">{{ f.label }} 전체</option>
        <option v-for="opt in optionsFor(f.key)" :key="opt" :value="String(opt)">
          {{ f.type === 'stars' ? '★' + opt : fieldVal(f, opt) }}
        </option>
      </select>
      <label class="sgi-dex-owned-toggle"><input v-model="ownedOnly" type="checkbox" /> 보유만</label>
    </div>

    <p v-if="loaded" class="sgi-dex-count">{{ filtered.length }}명</p>

    <div class="sgi-dex-grid">
      <button
        v-for="c in filtered"
        :key="c.id"
        type="button"
        class="sgi-dex-card"
        :class="{ 'is-owned': isOwned(c.external_key) }"
        @click="selected = c"
      >
        <span class="sgi-dex-portrait">
          <img :src="c.image_url" :alt="c.name" loading="lazy" />
          <span v-if="isOwned(c.external_key)" class="sgi-dex-owned-badge">보유</span>
        </span>
        <span class="sgi-dex-name">{{ c.name }}</span>
        <span v-if="c.traits.star" class="sgi-dex-stars">{{ '★'.repeat(c.traits.star) }}</span>
        <span v-else-if="c.rarity" class="sgi-dex-rarity">{{ c.rarity }}</span>
      </button>
    </div>

    <p v-if="loaded && !filtered.length" class="sgr-empty">조건에 맞는 캐릭터가 없어요.</p>

    <!-- 상세 모달 -->
    <div v-if="selected" class="sgi-dex-modal" @click.self="selected = null">
      <div class="sgi-dex-modal-card">
        <button type="button" class="sgi-dex-modal-close" @click="selected = null">✕</button>
        <img class="sgi-dex-modal-img" :src="selected.image_url" :alt="selected.name" />
        <h4 class="sgi-dex-modal-name">
          {{ selected.name }}
          <span v-if="selected.traits.star" class="sgi-dex-stars">{{ '★'.repeat(selected.traits.star) }}</span>
          <span v-else-if="selected.rarity" class="sgi-dex-rarity">{{ selected.rarity }}</span>
          <span v-if="isOwned(selected.external_key)" class="sgi-dex-owned-badge">보유</span>
        </h4>
        <dl class="sgi-dex-fields">
          <template v-for="f in schema" :key="f.key">
            <div v-if="f.type !== 'stars' && selected.traits[f.key] != null" class="sgi-dex-field">
              <dt>{{ f.label }}</dt>
              <dd>
                <span v-if="f.type === 'badge'" class="sgi-dex-badge">{{ fieldVal(f, selected.traits[f.key]) }}</span>
                <template v-else>{{ fieldVal(f, selected.traits[f.key]) }}</template>
              </dd>
            </div>
          </template>
        </dl>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { raidApi } from '../api';

const props = defineProps({
  gameSlug: { type: String, required: true },
  pool: { type: Object, default: () => ({}) },
});

const characters = ref([]);
const schema = ref([]);
const loaded = ref(false);
const q = ref('');
const ownedOnly = ref(false);
const filters = reactive({});
const selected = ref(null);
const cache = new Map();

const filterFields = computed(() => schema.value.filter((f) => f.filter));

function isOwned(key) {
  return !!props.pool[key]?.owned;
}

// 스키마 필드의 선택적 labels 맵으로 원시값(WIND/Mad/fire…)을 한글로 표시
function fieldVal(field, raw) {
  return field.labels?.[raw] ?? raw;
}

function optionsFor(key) {
  const set = new Set();
  for (const c of characters.value) {
    const v = c.traits?.[key];
    if (v != null && v !== '') set.add(v);
  }
  // 성급은 숫자 내림차순, 나머지는 사전순
  return [...set].sort((a, b) => (typeof a === 'number' ? b - a : String(a).localeCompare(String(b), 'ko')));
}

const filtered = computed(() => {
  const kw = q.value.toLowerCase();
  return characters.value.filter((c) => {
    if (kw && !c.name.toLowerCase().includes(kw)) return false;
    if (ownedOnly.value && !isOwned(c.external_key)) return false;
    for (const [key, val] of Object.entries(filters)) {
      if (val !== '' && String(c.traits?.[key] ?? '') !== String(val)) return false;
    }
    return true;
  });
});

async function load() {
  loaded.value = false;
  try {
    if (!cache.has(props.gameSlug)) {
      cache.set(props.gameSlug, await raidApi.getCharacters(props.gameSlug));
    }
    const res = cache.get(props.gameSlug);
    // traits 가 null 인 캐릭터가 있어도 안전하게(도감 렌더가 traits.* 를 참조)
    characters.value = res.data.map((c) => ({ ...c, traits: c.traits ?? {} }));
    schema.value = res.meta?.student_schema ?? [];
    // 필터 상태 초기화(게임 전환 시)
    Object.keys(filters).forEach((k) => delete filters[k]);
    for (const f of filterFields.value) filters[f.key] = '';
  } catch (e) {
    console.error('캐릭터정보 로드 실패', e);
    characters.value = [];
    schema.value = [];
  } finally {
    loaded.value = true;
  }
}

watch(() => props.gameSlug, load);
onMounted(load);
</script>
