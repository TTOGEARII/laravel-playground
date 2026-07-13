<template>
  <div v-if="loaded && posts.length" class="sgr-feed">
    <h3 class="sgr-feed-title">📰 커뮤니티 공략글 <small class="sgr-feed-hint">최근 레이드·추천순</small></h3>
    <ul class="sgr-guide-list">
      <li v-for="post in posts" :key="post.url">
        <a :href="post.url" target="_blank" rel="noopener" class="sgr-guide-item">
          <span class="sgr-guide-source" :class="`is-${post.source}`">
            {{ post.source === 'dc' ? '디시' : '아카' }}
          </span>
          <span class="sgr-guide-title">{{ post.title }}</span>
          <span class="sgr-guide-meta">
            <template v-if="post.raid_name">{{ post.raid_name }} · </template>
            {{ formatDate(post.posted_at) }}<template v-if="post.rate"> · 추천 {{ post.rate.toLocaleString() }}</template> · 조회 {{ (post.views ?? 0).toLocaleString() }}
          </span>
        </a>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue';
import { raidApi } from '../api';

const props = defineProps({
  gameSlug: { type: String, required: true },
});

const posts = ref([]);
const loaded = ref(false);
const cache = new Map(); // 게임 전환 시 재요청 방지

async function load() {
  loaded.value = false;
  try {
    if (!cache.has(props.gameSlug)) {
      cache.set(props.gameSlug, await raidApi.getGuidePosts(props.gameSlug, 8));
    }
    posts.value = cache.get(props.gameSlug);
  } catch (e) {
    console.error('공략글 피드 로드 실패', e);
    posts.value = [];
  } finally {
    loaded.value = true;
  }
}

function formatDate(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  return `${d.getMonth() + 1}.${String(d.getDate()).padStart(2, '0')}`;
}

watch(() => props.gameSlug, load);
onMounted(load);
</script>
