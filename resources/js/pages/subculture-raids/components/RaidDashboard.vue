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

    <p v-if="loading" class="sgr-empty">레이드 정보를 불러오는 중...</p>

    <!-- 선택된 게임 패널: 서버 config(modules)가 정한 순서대로 정보 모듈을 렌더 -->
    <section v-else-if="currentGame" :key="currentGame.slug" class="sgr-game-panel">
      <template v-for="module in currentGame.modules" :key="module">
        <component
          :is="MODULES[module]"
          v-if="MODULES[module]"
          v-bind="moduleProps(module)"
          @select="$emit('select', $event)"
        />
      </template>
    </section>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import AttributeCompositions from './AttributeCompositions.vue';
import GuideFeed from './GuideFeed.vue';
import RaidList from './RaidList.vue';

const props = defineProps({
  games: { type: Array, required: true },
  raids: { type: Array, required: true },
  loading: { type: Boolean, default: false },
  activeGame: { type: String, default: null },
});

defineEmits(['select', 'change-game']);

/**
 * 정보 모듈 레지스트리 — 새 정보 유형 추가 = 컴포넌트 등록 + 서버 config modules 에 키 추가.
 * (게임마다 다른 정보 구성을 서버가 결정하고, 프론트는 키→컴포넌트 매핑만 안다)
 */
const MODULES = {
  'raids': RaidList,
  'attribute-parties': AttributeCompositions,
  'guides': GuideFeed,
};

const currentGame = computed(() => props.games.find((g) => g.slug === props.activeGame) ?? props.games[0]);

function moduleProps(module) {
  if (module === 'raids') {
    return { raids: props.raids.filter((r) => r.game.slug === currentGame.value.slug) };
  }
  return { gameSlug: currentGame.value.slug };
}

function activeCount(slug) {
  return props.raids.filter((r) => r.game.slug === slug && r.status === 'active').length;
}
</script>
