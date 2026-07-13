<template>
  <section class="sgr-module sgi-future">
    <h3 class="sgr-module-title">🔮 미래시 <small class="sgr-feed-hint">예정 픽업·이벤트</small></h3>
    <p class="sgi-future-note">예정 일정 미리보기예요. 실제 일정·명칭과 다를 수 있어요.</p>

    <ol v-if="loaded && items.length" class="sgi-timeline">
      <li v-for="it in items" :key="`${it.row}-${it.id}`" class="sgi-tl-item" :class="`is-${it.row} is-${it.kind}`">
        <span class="sgi-tl-date">{{ fmtDate(it.starts_at) }}</span>
        <span class="sgi-tl-marker" :class="`is-${rowKind(it)}`" />
        <div class="sgi-tl-body">
          <span class="sgi-tl-kind" :class="`is-${rowKind(it)}`">{{ rowLabel(it) }}</span>
          <span class="sgi-tl-title">{{ it.title }}</span>
          <div v-if="it.featured?.length" class="sgi-tl-students">
            <img v-for="f in it.featured" :key="f.external_key" :src="f.image" :alt="f.name" :title="f.name" loading="lazy" />
          </div>
          <span class="sgi-tl-range">{{ fmtRange(it.starts_at, it.ends_at) }}</span>
        </div>
      </li>
    </ol>
    <p v-else-if="loaded" class="sgr-empty">아직 미래시 정보가 없어요.</p>
  </section>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue';
import { raidApi } from '../api';
import { fmtDate, fmtRange } from '../scheduleFormat';

const props = defineProps({
  gameSlug: { type: String, required: true },
});

const items = ref([]);
const loaded = ref(false);
const cache = new Map();

// 미래시 항목 종류: 배너(픽업) / 이벤트 / 레이드
function rowKind(it) {
  return it.row === 'banner' ? 'banner' : (it.kind === 'raid' ? 'raid' : 'event');
}
function rowLabel(it) {
  return { banner: '픽업', raid: '레이드', event: '이벤트' }[rowKind(it)];
}

async function load() {
  loaded.value = false;
  try {
    if (!cache.has(props.gameSlug)) {
      cache.set(props.gameSlug, await raidApi.getSchedule(props.gameSlug));
    }
    items.value = cache.get(props.gameSlug);
  } catch (e) {
    console.error('미래시 로드 실패', e);
    items.value = [];
  } finally {
    loaded.value = true;
  }
}

watch(() => props.gameSlug, load);
onMounted(load);
</script>
