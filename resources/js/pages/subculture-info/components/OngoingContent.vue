<template>
  <section v-if="loaded && events.length" class="sgr-module sgi-ongoing">
    <h3 class="sgr-module-title">🎪 진행중인 컨텐츠 <small class="sgr-feed-hint">이벤트</small></h3>

    <!-- 진행 중 이벤트는 상단에 크게(무엇을 하는지 한눈에), 예정/종료는 아래 리스트로 -->
    <a
      v-for="e in heroes"
      :key="e.id"
      class="sgi-hero"
      :class="`is-${e.status}`"
      :href="e.link_url || undefined"
      :target="e.link_url ? '_blank' : undefined"
      rel="noopener"
    >
      <img v-if="e.image_url" class="sgi-hero-img" :src="e.image_url" :alt="e.title" loading="lazy" />
      <div class="sgi-hero-body">
        <span class="sgi-hero-kind">이벤트</span>
        <span class="sgi-hero-name">{{ e.title }}</span>
        <span class="sgi-hero-range">{{ fmtRange(e.starts_at, e.ends_at) }}</span>
      </div>
      <span class="sgi-chip" :class="`is-${e.status}`">{{ dday(e) }}</span>
    </a>

    <ul v-if="others.length" class="sgi-event-list">
      <li v-for="e in others" :key="e.id" class="sgi-event" :class="`is-${e.status}`">
        <span class="sgi-event-icon">🗓️</span>
        <div class="sgi-event-info">
          <span class="sgi-event-name">{{ e.title }}</span>
          <span class="sgi-event-range">{{ fmtRange(e.starts_at, e.ends_at) }}</span>
        </div>
        <span class="sgi-chip" :class="`is-${e.status}`">{{ dday(e) }}</span>
      </li>
    </ul>
  </section>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { raidApi } from '../api';
import { fmtRange, dday } from '../scheduleFormat';

const props = defineProps({
  gameSlug: { type: String, required: true },
});

const events = ref([]);
const loaded = ref(false);
const cache = new Map();

// 진행 중은 히어로(크게), 나머지(예정/종료)는 리스트
const heroes = computed(() => events.value.filter((e) => e.status === 'active'));
const others = computed(() => events.value.filter((e) => e.status !== 'active'));

async function load() {
  loaded.value = false;
  try {
    if (!cache.has(props.gameSlug)) {
      cache.set(props.gameSlug, await raidApi.getEvents(props.gameSlug));
    }
    events.value = cache.get(props.gameSlug);
  } catch (e) {
    console.error('진행중 컨텐츠 로드 실패', e);
    events.value = [];
  } finally {
    loaded.value = true;
  }
}

watch(() => props.gameSlug, load);
onMounted(load);
</script>
