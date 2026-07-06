<template>
  <div class="sgr-my">
    <!-- 게임 탭 -->
    <nav class="sgr-tabs">
      <button
        v-for="game in games"
        :key="game.slug"
        class="sgr-tab"
        :class="{ 'is-active': currentSlug === game.slug }"
        @click="selectGame(game.slug)"
      >
        <span class="sgr-tab-icon">{{ game.icon }}</span> {{ game.name }}
      </button>
    </nav>

    <!-- 도구줄: 검색 + 보유 필터 + JSON 내보내기/가져오기 -->
    <div class="sgr-toolbar">
      <input v-model="keyword" type="text" class="sgr-search" placeholder="캐릭터 이름 검색" />
      <label class="sgr-owned-filter"><input v-model="ownedOnly" type="checkbox" /> 보유만 보기</label>
      <span class="sgr-owned-count">보유 {{ ownedCount }} / {{ characters.length }}</span>
      <div class="sgr-toolbar-actions">
        <button class="sgr-btn" :disabled="characters.length === 0" @click="setAllOwned(true)">전체 보유</button>
        <button class="sgr-btn" :disabled="ownedCount === 0" @click="setAllOwned(false)">전체 해제</button>
        <button class="sgr-btn" @click="exportJson">JSON 내보내기</button>
        <button class="sgr-btn" @click="fileInput?.click()">JSON 가져오기</button>
        <input ref="fileInput" type="file" accept="application/json" class="hidden" @change="importJson" />
      </div>
    </div>

    <p v-if="loading" class="sgr-empty">캐릭터 목록을 불러오는 중...</p>
    <p v-else-if="characters.length === 0" class="sgr-empty">
      캐릭터 마스터가 비어 있습니다. <code>subculture:crawl-characters</code> 를 먼저 실행하세요.
    </p>

    <!-- 캐릭터 그리드: 클릭 = 보유 토글, 보유 캐릭터 클릭 시 성장도 입력 -->
    <div class="sgr-char-grid">
      <div
        v-for="c in filtered"
        :key="c.id"
        class="sgr-char"
        :class="{ 'is-owned': isOwned(c), 'is-editing': editing?.id === c.id }"
      >
        <button class="sgr-char-body" @click="toggleOwned(c)">
          <img v-if="c.image_url" :src="c.image_url" :alt="c.name" loading="lazy" />
          <span v-else class="sgr-member-placeholder">{{ c.name.slice(0, 2) }}</span>
          <span class="sgr-char-name">{{ c.name }}</span>
          <span v-if="c.rarity" class="sgr-char-rarity">{{ c.rarity }}</span>
        </button>
        <button v-if="isOwned(c)" class="sgr-char-growth-btn" @click="editing = editing?.id === c.id ? null : c">
          성장도
        </button>
      </div>
    </div>

    <!-- 성장도 입력 패널 -->
    <GrowthForm
      v-if="editing && isOwned(editing)"
      :key="editing.id"
      :character="editing"
      :schema="growthSchema"
      :growth="pool[editing.external_key]?.growth ?? {}"
      @save="saveGrowth"
      @close="editing = null"
    />

    <p v-if="message" class="sgr-message">{{ message }}</p>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { raidApi } from '../api';
import GrowthForm from './GrowthForm.vue';

const props = defineProps({
  games: { type: Array, required: true },
  loggedIn: { type: Boolean, default: false },
  store: { type: Object, required: true },
});

const emit = defineEmits(['pool-changed']);

const currentSlug = ref(props.games[0]?.slug);
const characters = ref([]);
const growthSchema = ref([]);
const pool = ref({});
const loading = ref(false);
const keyword = ref('');
const ownedOnly = ref(false);
const editing = ref(null);
const message = ref('');
const fileInput = ref(null);

const filtered = computed(() => characters.value.filter((c) => {
  if (ownedOnly.value && !isOwned(c)) return false;
  return keyword.value === '' || c.name.includes(keyword.value.trim());
}));

const ownedCount = computed(() => characters.value.filter((c) => isOwned(c)).length);

function isOwned(character) {
  return pool.value[character.external_key]?.owned === true;
}

async function selectGame(slug) {
  currentSlug.value = slug;
  editing.value = null;
  loading.value = true;
  try {
    const res = await raidApi.getCharacters(slug);
    characters.value = res.data;
    growthSchema.value = res.meta.growth_schema;
    pool.value = await props.store.load(slug, res.data);
    emit('pool-changed', { gameSlug: slug, pool: pool.value });
  } catch (e) {
    console.error('캐릭터 로드 실패', e);
    flash('캐릭터 목록을 불러오지 못했습니다.');
  } finally {
    loading.value = false;
  }
}

async function toggleOwned(character) {
  const owned = !isOwned(character);
  try {
    if (owned) {
      await props.store.save(currentSlug.value, character, true, pool.value[character.external_key]?.growth ?? null);
      pool.value = { ...pool.value, [character.external_key]: { owned: true, growth: pool.value[character.external_key]?.growth ?? null } };
    } else {
      await props.store.remove(currentSlug.value, character);
      const next = { ...pool.value };
      delete next[character.external_key];
      pool.value = next;
      if (editing.value?.id === character.id) editing.value = null;
    }
    emit('pool-changed', { gameSlug: currentSlug.value, pool: pool.value });
  } catch (e) {
    console.error('보유 저장 실패', e);
    flash('저장에 실패했습니다.');
  }
}

async function saveGrowth(growth) {
  const character = editing.value;
  try {
    await props.store.save(currentSlug.value, character, true, growth);
    pool.value = { ...pool.value, [character.external_key]: { owned: true, growth } };
    emit('pool-changed', { gameSlug: currentSlug.value, pool: pool.value });
    editing.value = null;
    flash(`${character.name} 성장도를 저장했습니다.`);
  } catch (e) {
    console.error('성장도 저장 실패', e);
    flash(e.response?.status === 422 ? '성장도 값이 올바르지 않습니다.' : '저장에 실패했습니다.');
  }
}

/**
 * 전체 보유/해제 일괄 변경 — 요청 1번으로 끝나도록 가져오기(벌크 upsert) 경로를 재사용한다.
 * 해제 시에도 성장도 입력값은 유지된다(owned 플래그만 내림).
 */
async function setAllOwned(owned) {
  if (!owned && !confirm('보유 체크를 모두 해제할까요? 성장도 입력값은 유지됩니다.')) return;
  try {
    await props.store.importData({
      version: 1,
      game: currentSlug.value,
      characters: characters.value.map((c) => ({
        external_key: c.external_key,
        name: c.name,
        owned,
        growth: pool.value[c.external_key]?.growth ?? null,
      })),
    });
    await selectGame(currentSlug.value);
    flash(owned ? `전체 ${characters.value.length}명을 보유로 표시했습니다.` : '보유 체크를 모두 해제했습니다.');
  } catch (e) {
    console.error('일괄 변경 실패', e);
    flash('일괄 변경에 실패했습니다.');
  }
}

async function exportJson() {
  try {
    const payload = await props.store.exportData(currentSlug.value);
    const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `my-characters-${currentSlug.value}.json`;
    a.click();
    URL.revokeObjectURL(a.href);
  } catch (e) {
    console.error('내보내기 실패', e);
    flash('내보내기에 실패했습니다.');
  }
}

async function importJson(event) {
  const file = event.target.files?.[0];
  event.target.value = '';
  if (!file) return;
  try {
    const payload = JSON.parse(await file.text());
    if (payload.game && payload.game !== currentSlug.value) {
      flash(`이 파일은 ${payload.game} 데이터입니다. 해당 게임 탭에서 가져오세요.`);
      return;
    }
    const stats = await props.store.importData({ ...payload, game: currentSlug.value });
    await selectGame(currentSlug.value);
    flash(`가져오기 완료 — ${stats.imported}건 적용${stats.missing ? `, ${stats.missing}건 매칭 실패` : ''}`);
  } catch (e) {
    console.error('가져오기 실패', e);
    flash('JSON 파일을 읽지 못했습니다.');
  }
}

let messageTimer = null;
function flash(text) {
  message.value = text;
  clearTimeout(messageTimer);
  messageTimer = setTimeout(() => (message.value = ''), 4000);
}

onMounted(() => {
  if (currentSlug.value) selectGame(currentSlug.value);
});
</script>
