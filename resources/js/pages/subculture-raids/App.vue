<template>
  <div class="sgr-page">
    <!-- 뷰 전환: 레이드 대시보드 / 내 캐릭터 -->
    <nav class="sgr-nav">
      <button class="sgr-nav-tab" :class="{ 'is-active': view === 'dashboard' }" @click="showDashboard">
        ⚔️ 레이드 대시보드
      </button>
      <button class="sgr-nav-tab" :class="{ 'is-active': view === 'my' }" @click="view = 'my'">
        🎒 내 캐릭터
      </button>
      <span v-if="!loggedIn" class="sgr-guest-hint">
        비로그인 상태 — 내 캐릭터는 이 브라우저(localStorage)에만 저장됩니다.
      </span>
    </nav>

    <!-- 레이드 상세 -->
    <RaidDetail
      v-if="view === 'detail' && detail"
      :raid="detail"
      :pool="poolFor(detail.game.slug)"
      @back="showDashboard"
    />

    <!-- 레이드 대시보드 -->
    <RaidDashboard
      v-else-if="view === 'dashboard'"
      :games="games"
      :raids="raids"
      :loading="loadingRaids"
      @select="openDetail"
    />

    <!-- 내 캐릭터 관리 -->
    <MyCharacters
      v-else-if="view === 'my'"
      :games="games"
      :logged-in="loggedIn"
      :store="store"
      @pool-changed="onPoolChanged"
    />
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { createPoolStore, raidApi } from './api';
import RaidDashboard from './components/RaidDashboard.vue';
import RaidDetail from './components/RaidDetail.vue';
import MyCharacters from './components/MyCharacters.vue';

const props = defineProps({
  games: { type: Array, required: true },
  loggedIn: { type: Boolean, default: false },
});

const store = createPoolStore(props.loggedIn);

const view = ref('dashboard');
const raids = ref([]);
const loadingRaids = ref(false);
const detail = ref(null);
// 게임별 내 풀({ external_key: { owned, growth } }) — 편성 보유 매칭 하이라이트용
const pools = reactive({});

function poolFor(gameSlug) {
  return pools[gameSlug] ?? {};
}

async function loadRaids() {
  loadingRaids.value = true;
  try {
    const res = await raidApi.getRaids();
    raids.value = res.data;
  } catch (e) {
    console.error('레이드 목록 로드 실패', e);
  } finally {
    loadingRaids.value = false;
  }
}

async function loadPool(gameSlug) {
  try {
    const res = await raidApi.getCharacters(gameSlug);
    pools[gameSlug] = await store.load(gameSlug, res.data);
  } catch (e) {
    console.error('내 풀 로드 실패', e);
  }
}

async function openDetail(raid) {
  view.value = 'detail';
  detail.value = null;
  try {
    const [full] = await Promise.all([
      raidApi.getRaid(raid.id),
      pools[raid.game.slug] ? Promise.resolve() : loadPool(raid.game.slug),
    ]);
    detail.value = full;
    history.replaceState(null, '', `?raid=${raid.id}`);
  } catch (e) {
    console.error('레이드 상세 로드 실패', e);
    view.value = 'dashboard';
  }
}

function showDashboard() {
  view.value = 'dashboard';
  detail.value = null;
  history.replaceState(null, '', location.pathname);
}

function onPoolChanged({ gameSlug, pool }) {
  pools[gameSlug] = pool;
}

onMounted(async () => {
  await loadRaids();
  // ?raid= 딥링크
  const raidId = new URLSearchParams(location.search).get('raid');
  const target = raidId ? raids.value.find((r) => r.id === Number(raidId)) : null;
  if (target) openDetail(target);
});
</script>
