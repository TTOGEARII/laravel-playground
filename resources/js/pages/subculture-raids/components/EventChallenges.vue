<template>
  <div v-if="loaded && stages.length" class="sgr-event-challenges">
    <h3 class="sgr-feed-title">
      🎯 이벤트 챌린지 — {{ event?.name }}
      <small v-if="period" class="sgr-feed-hint">{{ period }}</small>
    </h3>

    <div class="sgr-challenge-grid">
      <article v-for="stage in stages" :key="stage.label" class="sgr-challenge-card">
        <header class="sgr-challenge-head">
          <span class="sgr-challenge-label">{{ stage.label }}</span>
          <span v-if="stage.condition" class="sgr-challenge-cond">{{ stage.condition }}</span>
        </header>
        <p v-if="stage.name" class="sgr-challenge-map">{{ stage.name }}</p>

        <p v-if="stage.summary" class="sgr-challenge-summary" :class="{ 'is-open': opened.has(stage.label) }">
          {{ stage.summary }}
        </p>
        <button
          v-if="stage.summary && stage.summary.length > 90"
          type="button"
          class="sgr-challenge-more"
          @click="toggle(stage.label)"
        >
          {{ opened.has(stage.label) ? '접기 ▲' : '더 보기 ▼' }}
        </button>

        <div v-if="stage.mentioned.length" class="sgr-challenge-chars">
          <span v-for="name in stage.mentioned" :key="name" class="sgr-challenge-char">{{ name }}</span>
        </div>

        <a
          v-if="stage.video_url"
          :href="stage.video_url"
          target="_blank"
          rel="noopener"
          class="sgr-challenge-video"
        >
          ▶ 공략 영상 보기
        </a>

        <ul v-if="stage.extra_videos?.length" class="sgr-challenge-extra">
          <li v-for="video in stage.extra_videos" :key="video.url">
            <a :href="video.url" target="_blank" rel="noopener" class="sgr-challenge-extra-link">
              <span class="sgr-challenge-extra-src" :class="`is-${video.source}`">
                {{ video.source === 'dc' ? '디시' : 'YT' }}
              </span>
              {{ video.title }}
            </a>
          </li>
        </ul>
      </article>
    </div>

    <p class="sgr-challenge-source">
      출처: <a :href="event?.source_url" target="_blank" rel="noopener">아카라이브 이벤트 올인원 공략</a>
      — 조합 세부(스킬 타이밍·배치)는 각 스테이지 영상을 참고하세요
    </p>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { raidApi } from '../api';

const props = defineProps({
  gameSlug: { type: String, required: true },
});

const event = ref(null);
const stages = ref([]);
const loaded = ref(false);
const opened = ref(new Set());
const cache = new Map();

const period = computed(() => {
  if (!event.value?.starts_at) return '';
  const fmt = (d) => d?.slice(5).replace('-', '.');
  return `${fmt(event.value.starts_at)} ~ ${fmt(event.value.ends_at) ?? ''}`;
});

function toggle(label) {
  opened.value.has(label) ? opened.value.delete(label) : opened.value.add(label);
  opened.value = new Set(opened.value);
}

async function load() {
  loaded.value = false;
  try {
    if (!cache.has(props.gameSlug)) {
      cache.set(props.gameSlug, await raidApi.getEventChallenges(props.gameSlug));
    }
    const data = cache.get(props.gameSlug);
    event.value = data.event;
    stages.value = data.stages || [];
  } catch (e) {
    console.error('이벤트 챌린지 로드 실패', e);
    stages.value = [];
  } finally {
    loaded.value = true;
  }
}

watch(() => props.gameSlug, load);
onMounted(load);
</script>
