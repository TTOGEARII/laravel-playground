<template>
  <section v-if="loaded && events.length" class="sgr-module sgi-ongoing">
    <h3 class="sgr-module-title">🎪 진행중 컨텐츠 <small class="sgr-feed-hint">이벤트</small></h3>
    <ul class="sgi-event-list">
      <li v-for="e in events" :key="e.id" class="sgi-event" :class="`is-${e.status}`">
        <span class="sgi-event-icon">{{ e.status === 'upcoming' ? '🗓️' : '🎉' }}</span>
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
import { onMounted, ref, watch } from 'vue';
import { raidApi } from '../api';
import { fmtRange, dday } from '../scheduleFormat';

const props = defineProps({
  gameSlug: { type: String, required: true },
});

const events = ref([]);
const loaded = ref(false);
const cache = new Map();

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
