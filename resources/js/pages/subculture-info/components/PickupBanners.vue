<template>
  <section v-if="loaded" class="sgr-module sgi-banners">
    <h3 class="sgr-module-title">🎟️ 모집중 학생 <small class="sgr-feed-hint">픽업 배너 · 현재 서버 기준</small></h3>
    <p v-if="!banners.length" class="sgr-empty">현재 진행 중인 픽업이 없어요.</p>
    <div v-else class="sgi-banner-list">
      <article v-for="b in banners" :key="b.id" class="sgi-banner" :class="`is-${b.status}`">
        <div class="sgi-banner-head">
          <span class="sgi-banner-title">{{ b.title }}</span>
          <span class="sgi-chip" :class="`is-${b.status}`">{{ dday(b) }}</span>
        </div>
        <div class="sgi-pickup-list">
          <div
            v-for="f in b.featured"
            :key="f.external_key"
            class="sgi-pickup"
            :class="{ 'is-owned': isOwned(f.external_key) }"
          >
            <div class="sgi-pickup-portrait">
              <img :src="f.image" :alt="f.name" loading="lazy" />
              <span v-if="isOwned(f.external_key)" class="sgi-pickup-owned">보유</span>
            </div>
            <span v-if="costumeOf(f.name)" class="sgi-pickup-costume">{{ costumeOf(f.name) }}</span>
            <span class="sgi-pickup-name">{{ baseName(f.name) }}</span>
            <span v-if="f.rarity" class="sgi-pickup-star">{{ '★'.repeat(f.rarity) }}</span>
            <div v-if="f.attributes?.length" class="sgi-pickup-attrs">
              <span v-for="a in f.attributes" :key="a" class="sgi-attr" :class="attrClass(a)">{{ attrLabel(a) }}</span>
            </div>
          </div>
        </div>
        <span class="sgi-banner-range">{{ fmtRange(b.starts_at, b.ends_at) }}</span>
      </article>
    </div>
  </section>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue';
import { raidApi } from '../api';
import { fmtRange, dday } from '../scheduleFormat';

const props = defineProps({
  gameSlug: { type: String, required: true },
  pool: { type: Object, default: () => ({}) }, // 보유 하이라이트용
});

const banners = ref([]);
const loaded = ref(false);
const cache = new Map();

// BA 게임 표준 색: 공격(폭발/관통/신비/진동)·방어(경장/중장/특수/탄력) — 인게임 상성 색과 동일
const ATTR_COLOR = {
  폭발: 'is-red', 경장갑: 'is-red',
  관통: 'is-amber', 중장갑: 'is-amber',
  신비: 'is-blue', 특수장갑: 'is-blue',
  진동: 'is-purple', 탄력장갑: 'is-purple',
  STRIKER: 'is-red', SPECIAL: 'is-blue',
};
const ATTR_LABEL = { STRIKER: '스트라이커', SPECIAL: '스페셜' };

function attrClass(v) {
  return ATTR_COLOR[v] ?? '';
}
function attrLabel(v) {
  return ATTR_LABEL[v] ?? v;
}
function costumeOf(name) {
  return name?.match(/\(([^)]+)\)/)?.[1] ?? null;
}
function baseName(name) {
  return (name ?? '').replace(/\s*\([^)]+\)/, '');
}
function isOwned(key) {
  return !!props.pool[key]?.owned;
}

async function load() {
  loaded.value = false;
  try {
    if (!cache.has(props.gameSlug)) {
      cache.set(props.gameSlug, await raidApi.getBanners(props.gameSlug));
    }
    banners.value = cache.get(props.gameSlug);
  } catch (e) {
    console.error('모집중 학생 로드 실패', e);
    banners.value = [];
  } finally {
    loaded.value = true;
  }
}

watch(() => props.gameSlug, load);
onMounted(load);
</script>
