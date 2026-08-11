<template>
  <div class="sgr-dashboard">
    <!-- 게임 탭(gamebar) — 한 번에 한 게임만 보여 스크롤 피로를 없앤다. full-bleed 스티키 바 -->
    <div class="gamebar">
      <div class="shell gamebar-in">
        <div class="gpick" role="tablist" aria-label="게임 선택">
          <button
            v-for="game in games"
            :key="game.slug"
            type="button"
            role="tab"
            class="gp"
            :class="{ on: activeGame === game.slug }"
            :aria-selected="activeGame === game.slug"
            @click="$emit('change-game', game.slug)"
          >
            <span class="gp-i">{{ game.icon }}</span>
            <span class="gp-n">{{ game.name }}</span>
            <span v-if="activeCount(game.slug) > 0" class="sgr-game-tab-badge">{{ activeCount(game.slug) }}</span>
          </button>
        </div>
      </div>
    </div>

    <section class="shell stack g3" style="padding-top:var(--s4)">
      <!-- 검색(목업 셸 구조 — 게임별 정보 탐색 진입점) -->
      <form class="searchbar" @submit.prevent>
        <input
          type="search"
          aria-label="정보 검색"
          placeholder="캐릭터 · 컨텐츠 · 공략 검색 — 예: 총력전 편성, 미래시"
        />
        <button class="btn btn-sm" type="submit">검색</button>
      </form>

      <p v-if="loading" class="sgr-empty">정보를 불러오는 중...</p>

      <template v-else-if="currentGame">
        <!-- 서브탭(메인 / 미래시 / 캐릭터정보 …) — tabs 모듈이 있는 게임만 노출 -->
        <nav v-if="tabModules.length" class="tabs" aria-label="정보 종류">
          <button
            type="button"
            class="tab"
            :class="{ on: activeTab === 'main' }"
            @click="activeTab = 'main'"
          >🏠 메인</button>
          <button
            v-for="t in tabModules"
            :key="t"
            type="button"
            class="tab"
            :class="{ on: activeTab === t }"
            @click="activeTab = t"
          >{{ tabLabel(t) }}</button>
        </nav>

        <!-- 2열 분할: 좌 메인 모듈 리스트 / 우 요약·바로가기 사이드 -->
        <div class="isplit">
          <!-- 좌: 메인 모듈(핀 고정 세로 나열) -->
          <section v-if="activeTab === 'main'" :key="`${currentGame.slug}-main`" class="sgr-game-panel">
            <template v-for="module in mainModules" :key="module">
              <component
                :is="MODULES[module]"
                v-if="MODULES[module]"
                v-bind="moduleProps(module)"
                @select="$emit('select', $event)"
                @set-substitute="$emit('set-substitute', $event)"
                @clear-substitute="$emit('clear-substitute', $event)"
                @pool-changed="$emit('pool-changed', $event)"
              />
            </template>
          </section>

          <!-- 좌: 서브탭 뷰(선택한 tab 모듈 하나) -->
          <section v-else :key="`${currentGame.slug}-${activeTab}`" class="sgr-game-panel">
            <component
              :is="MODULES[activeTab]"
              v-if="MODULES[activeTab]"
              v-bind="moduleProps(activeTab)"
              @pool-changed="$emit('pool-changed', $event)"
            />
          </section>

          <!-- 우: SUMMARY + 바로가기(리딤코드 · AI 에이전트) -->
          <aside class="iside">
            <div
              v-if="raids.some((r) => r.game.slug === currentGame.slug)"
              class="card istat"
            >
              <span class="eyebrow">SUMMARY</span>
              <div class="istat-row">
                <div class="hud-stat">
                  <b>{{ activeCount(currentGame.slug) }}</b><small>진행중</small>
                </div>
                <div class="hud-stat">
                  <b>{{ raids.filter((r) => r.game.slug === currentGame.slug && r.status === 'upcoming').length }}</b><small>예정</small>
                </div>
                <div class="hud-stat">
                  <b>{{ raids.filter((r) => r.game.slug === currentGame.slug).length }}</b><small>레이드</small>
                </div>
              </div>
            </div>
            <a class="card cyan icard iside-card" href="/subculture-game-info/codes">
              <span class="card-ico">🎁</span>
              <div class="card-body">
                <h3>리딤코드</h3>
                <p>지금 쓸 수 있는 코드 확인</p>
              </div>
              <span class="enter">코드 보기 →</span>
            </a>
            <a class="card gold icard iside-card" :href="`/subculture-agent?game=${currentGame.slug}`">
              <span class="card-ico">🤖</span>
              <div class="card-body">
                <h3>AI에게 묻기</h3>
                <p>클릭으로 못 찾는 편성 · 공략을 대화로</p>
              </div>
              <span class="enter">{{ currentGame.name }} 기준 대화 →</span>
            </a>
          </aside>
        </div>
      </template>
    </section>
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
import WikiDex from './WikiDex.vue';

const props = defineProps({
  games: { type: Array, required: true },
  raids: { type: Array, required: true },
  loading: { type: Boolean, default: false },
  activeGame: { type: String, default: null },
  pool: { type: Object, default: () => ({}) }, // 활성 게임의 내 풀(보유 하이라이트·편집)
  userSubs: { type: Object, default: () => ({}) }, // 활성 게임의 내 대체 매핑
  store: { type: Object, default: null }, // 내 보유 저장소(캐릭터정보 도감 편집용)
  loggedIn: { type: Boolean, default: false },
});

defineEmits(['select', 'change-game', 'set-substitute', 'clear-substitute', 'pool-changed']);

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
  'wiki-dex': WikiDex,
};

// 서브탭 라벨(아이콘 포함)
const TAB_META = {
  'future-timeline': '🔮 미래시',
  'student-dex': '📖 캐릭터정보',
  'wiki-dex': '📚 위키 정보',
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
      return { gameSlug: slug, pool: props.pool }; // 보유 하이라이트
    case 'student-dex':
      // 도감에서 보유·성장도 편집(내 캐릭터 통합) — store 로 저장, pool-changed 로 상위 동기화
      return { gameSlug: slug, pool: props.pool, store: props.store, loggedIn: props.loggedIn };
    default:
      return { gameSlug: slug };
  }
}

function activeCount(slug) {
  return props.raids.filter((r) => r.game.slug === slug && r.status === 'active').length;
}
</script>
