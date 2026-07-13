<template>
  <div class="sgr-dashboard">
    <!-- 게임 탭 — 한 번에 한 게임만 보여 스크롤 피로를 없앤다 -->
    <nav class="sgr-game-tabs" role="tablist" aria-label="게임 선택">
      <button
        v-for="game in games"
        :key="game.slug"
        type="button"
        role="tab"
        class="sgr-game-tab"
        :class="{ 'is-active': activeGame === game.slug }"
        :aria-selected="activeGame === game.slug"
        @click="$emit('change-game', game.slug)"
      >
        <span class="sgr-game-tab-icon">{{ game.icon }}</span>
        <span class="sgr-game-tab-name">{{ game.name }}</span>
        <span v-if="activeCount(game.slug) > 0" class="sgr-game-tab-badge">{{ activeCount(game.slug) }}</span>
      </button>
    </nav>

    <!-- 복합 질문(조합·추천·요약)은 AI 에이전트가 보조 — 현재 게임 컨텍스트를 갖고 넘어간다 -->
    <div v-if="currentGame" class="sgr-ask-ai">
      <a :href="`/subculture-agent?game=${currentGame.slug}`">
        🤖 클릭으로 못 찾는 건 AI에게 물어보세요 <small>{{ currentGame.name }} 기준</small>
      </a>
    </div>

    <p v-if="loading" class="sgr-empty">레이드 정보를 불러오는 중...</p>

    <!-- 선택된 게임 패널: 서버 config(modules)가 정한 순서대로 정보 모듈을 렌더 -->
    <section v-else-if="currentGame" :key="currentGame.slug" class="sgr-game-panel">
      <template v-for="module in currentGame.modules" :key="module">
        <component
          :is="MODULES[module]"
          v-if="MODULES[module]"
          v-bind="moduleProps(module)"
          @select="$emit('select', $event)"
          @set-substitute="$emit('set-substitute', $event)"
          @clear-substitute="$emit('clear-substitute', $event)"
        />
      </template>
    </section>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import AttributeCompositions from './AttributeCompositions.vue';
import EventChallenges from './EventChallenges.vue';
import GuideFeed from './GuideFeed.vue';
import RaidList from './RaidList.vue';

const props = defineProps({
  games: { type: Array, required: true },
  raids: { type: Array, required: true },
  loading: { type: Boolean, default: false },
  activeGame: { type: String, default: null },
  pool: { type: Object, default: () => ({}) }, // 활성 게임의 내 풀(보유 하이라이트용)
  userSubs: { type: Object, default: () => ({}) }, // 활성 게임의 내 대체 매핑
});

defineEmits(['select', 'change-game', 'set-substitute', 'clear-substitute']);

/**
 * 정보 모듈 레지스트리 — 새 정보 유형 추가 = 컴포넌트 등록 + 서버 config modules 에 키 추가.
 * (게임마다 다른 정보 구성을 서버가 결정하고, 프론트는 키→컴포넌트 매핑만 안다)
 */
const MODULES = {
  'raids': RaidList,
  'attribute-parties': AttributeCompositions,
  'guides': GuideFeed,
  'event-challenges': EventChallenges,
};

const currentGame = computed(() => props.games.find((g) => g.slug === props.activeGame) ?? props.games[0]);

function moduleProps(module) {
  const gameRaids = props.raids.filter((r) => r.game.slug === currentGame.value.slug);
  if (module === 'raids') {
    return { raids: gameRaids };
  }
  if (module === 'attribute-parties') {
    // 보유 하이라이트 + 대체 지정(피커·Gemini 추천은 최신 레이드를 컨텍스트로 사용)
    return { gameSlug: currentGame.value.slug, pool: props.pool, userSubs: props.userSubs, raids: gameRaids };
  }
  if (module === 'event-challenges') {
    // 내 풀 조합(미보유 → 보유 대체) 계산에 보유 풀 사용
    return { gameSlug: currentGame.value.slug, pool: props.pool };
  }
  return { gameSlug: currentGame.value.slug };
}

function activeCount(slug) {
  return props.raids.filter((r) => r.game.slug === slug && r.status === 'active').length;
}
</script>
