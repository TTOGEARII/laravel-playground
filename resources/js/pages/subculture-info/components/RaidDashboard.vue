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

    <p v-if="loading" class="sgr-empty">정보를 불러오는 중...</p>

    <template v-else-if="currentGame">
      <!-- 서브탭(메인 / 미래시 / 학정보 …) — tabs 모듈이 있는 게임만 노출 -->
      <nav v-if="tabModules.length" class="sgr-subtabs">
        <button
          type="button"
          class="sgr-subtab"
          :class="{ 'is-active': activeTab === 'main' }"
          @click="activeTab = 'main'"
        >🏠 메인</button>
        <button
          v-for="t in tabModules"
          :key="t"
          type="button"
          class="sgr-subtab"
          :class="{ 'is-active': activeTab === t }"
          @click="activeTab = t"
        >{{ tabLabel(t) }}</button>
      </nav>

      <!-- 메인: 핀 고정 모듈 세로 나열 -->
      <section v-if="activeTab === 'main'" :key="`${currentGame.slug}-main`" class="sgr-game-panel">
        <template v-for="module in mainModules" :key="module">
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

      <!-- 서브탭 뷰: 선택한 tab 모듈 하나 -->
      <section v-else :key="`${currentGame.slug}-${activeTab}`" class="sgr-game-panel">
        <component :is="MODULES[activeTab]" v-if="MODULES[activeTab]" v-bind="moduleProps(activeTab)" />
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import AttributeCompositions from './AttributeCompositions.vue';
import EventChallenges from './EventChallenges.vue';
import FutureTimeline from './FutureTimeline.vue';
import GuideFeed from './GuideFeed.vue';
import OngoingContent from './OngoingContent.vue';
import PickupBanners from './PickupBanners.vue';
import RaidList from './RaidList.vue';
import StudentDex from './StudentDex.vue';

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
  'ongoing-content': OngoingContent,
  'pickup-banners': PickupBanners,
  'raids': RaidList,
  'attribute-parties': AttributeCompositions,
  'event-challenges': EventChallenges,
  'guides': GuideFeed,
  'future-timeline': FutureTimeline,
  'student-dex': StudentDex,
};

// 서브탭 라벨(아이콘 포함)
const TAB_META = {
  'future-timeline': '🔮 미래시',
  'student-dex': '📖 학정보',
};
function tabLabel(key) {
  return TAB_META[key] ?? key;
}

const currentGame = computed(() => props.games.find((g) => g.slug === props.activeGame) ?? props.games[0]);

// modules 는 평면 배열(전부 메인) 또는 { main:[...], tabs:[...] } 두 형태를 지원
const mainModules = computed(() => {
  const m = currentGame.value?.modules;
  return Array.isArray(m) ? m : (m?.main ?? []);
});
const tabModules = computed(() => {
  const m = currentGame.value?.modules;
  return Array.isArray(m) ? [] : (m?.tabs ?? []);
});

const activeTab = ref('main');
// 게임 전환 시 항상 메인 뷰로 복귀
watch(() => currentGame.value?.slug, () => { activeTab.value = 'main'; });

function moduleProps(module) {
  const slug = currentGame.value.slug;
  const gameRaids = props.raids.filter((r) => r.game.slug === slug);
  switch (module) {
    case 'raids':
      return { raids: gameRaids };
    case 'attribute-parties':
      return { gameSlug: slug, pool: props.pool, userSubs: props.userSubs, raids: gameRaids };
    case 'event-challenges':
      return { gameSlug: slug, pool: props.pool };
    case 'pickup-banners':
    case 'student-dex':
      return { gameSlug: slug, pool: props.pool }; // 보유 하이라이트
    default:
      return { gameSlug: slug };
  }
}

function activeCount(slug) {
  return props.raids.filter((r) => r.game.slug === slug && r.status === 'active').length;
}
</script>
