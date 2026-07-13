<template>
  <section v-if="loaded && banners.length" class="sgr-module sgi-banners">
    <h3 class="sgr-module-title">🎟️ 모집중 학생 <small class="sgr-feed-hint">픽업 배너 · 현재 서버 기준</small></h3>
    <div class="sgi-banner-list">
      <article v-for="b in banners" :key="b.id" class="sgi-banner" :class="`is-${b.status}`">
        <div class="sgi-banner-head">
          <span class="sgi-banner-title">{{ b.title }}</span>
          <span class="sgi-chip" :class="`is-${b.status}`">{{ dday(b) }}</span>
        </div>
        <div class="sgi-banner-students">
          <div
            v-for="f in b.featured"
            :key="f.external_key"
            class="sgi-banner-student"
            :class="{ 'is-owned': isOwned(f.external_key) }"
            :title="f.name"
          >
            <img :src="f.image" :alt="f.name" loading="lazy" />
            <span class="sgi-banner-student-name">{{ f.name }}</span>
            <span v-if="f.rarity" class="sgi-banner-star">{{ '★'.repeat(f.rarity) }}</span>
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
