<template>
  <section class="sgr-module sgi-wiki">
    <h3 class="sgr-module-title">📚 위키 정보 <small class="sgr-feed-hint">{{ sourceHint }}</small></h3>

    <!-- 카테고리(메뉴) 탭 -->
    <nav v-if="menus.length" class="sgi-wiki-menus">
      <button
        v-for="m in menus"
        :key="m.key"
        type="button"
        class="sgi-wiki-menu"
        :class="{ 'is-active': menu === m.key }"
        @click="selectMenu(m.key)"
      >{{ m.label }} <small>{{ m.count }}</small></button>
    </nav>

    <input v-model.trim="q" type="search" class="sgi-dex-search sgi-wiki-search" placeholder="이름 검색" />

    <div class="sgi-wiki-grid">
      <button v-for="e in filtered" :key="e.id" type="button" class="sgi-wiki-card" @click="open(e)">
        <img v-if="e.icon_url" :src="e.icon_url" :alt="e.name" loading="lazy" />
        <span v-else class="sgi-wiki-noimg">📄</span>
        <span class="sgi-wiki-name">{{ e.name }}</span>
        <span v-if="e.filters?.length" class="sgi-wiki-badges">
          <i v-for="f in e.filters.slice(0, 3)" :key="f.value">{{ f.value }}</i>
        </span>
      </button>
    </div>

    <p v-if="loaded && !filtered.length" class="sgr-empty">
      {{ menus.length ? '조건에 맞는 항목이 없어요.' : '아직 수집된 위키 정보가 없어요.' }}
    </p>

    <!-- 상세 모달 -->
    <div v-if="selected" class="sgi-dex-modal" @click.self="selected = null">
      <div class="sgi-dex-modal-card sgi-wiki-modal">
        <button type="button" class="sgi-dex-modal-close" @click="selected = null">✕</button>
        <img v-if="selected.icon_url" class="sgi-dex-modal-img" :src="selected.icon_url" :alt="selected.name" />
        <h4 class="sgi-dex-modal-name">{{ selected.name }}</h4>
        <div v-if="selected.filters?.length" class="sgi-dex-badges">
          <span v-for="f in selected.filters" :key="f.value" class="sgi-dex-badge">{{ f.value }}</span>
        </div>

        <p v-if="detailLoading" class="sgr-empty">상세 불러오는 중…</p>
        <template v-else>
          <section v-for="(s, i) in selected.detail" :key="i" class="sgi-wiki-section">
            <h5 v-if="s.title" class="sgi-wiki-section-title">{{ s.title }}</h5>
            <dl v-if="s.rows?.length" class="sgi-dex-fields">
              <div v-for="(r, ri) in s.rows" :key="ri" class="sgi-dex-field">
                <dt>{{ r.label }}</dt>
                <dd>{{ r.value }}</dd>
              </div>
            </dl>
            <p v-for="(p, pi) in s.paragraphs ?? []" :key="pi" class="sgi-wiki-para">{{ p }}</p>
          </section>
          <p v-if="!selected.detail?.length" class="sgr-empty">상세 정보가 아직 없어요.</p>
        </template>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { raidApi } from '../api';

const props = defineProps({
  gameSlug: { type: String, required: true },
});

const menus = ref([]);
const menu = ref('');
const entries = ref([]);
const loaded = ref(false);
const q = ref('');
const selected = ref(null);
const detailLoading = ref(false);
const cache = new Map(); // `${game}:${menu}` → { entries, menus }
const detailCache = new Map();

const SOURCE_HINTS = { wuthering: 'wuthering.gg', starrail: '호요랩 공식 위키', zenless: '호요랩 공식 위키' };
const sourceHint = computed(() => SOURCE_HINTS[props.gameSlug] ?? '공식 위키');

const filtered = computed(() => {
  const kw = q.value.toLowerCase();
  return kw ? entries.value.filter((e) => e.name.toLowerCase().includes(kw)) : entries.value;
});

async function load(menuKey = '') {
  loaded.value = false;
  try {
    const key = `${props.gameSlug}:${menuKey}`;
    if (!cache.has(key)) {
      cache.set(key, await raidApi.getWikiEntries(props.gameSlug, menuKey || undefined));
    }
    const res = cache.get(key);
    entries.value = res.data;
    menus.value = res.meta?.menus ?? [];
    menu.value = res.meta?.menu ?? '';
  } catch (e) {
    console.error('위키 정보 로드 실패', e);
    entries.value = [];
    menus.value = [];
  } finally {
    loaded.value = true;
  }
}

function selectMenu(key) {
  if (menu.value === key) return;
  q.value = '';
  load(key);
}

async function open(entry) {
  selected.value = { ...entry, detail: null };
  detailLoading.value = true;
  try {
    if (!detailCache.has(entry.id)) {
      detailCache.set(entry.id, await raidApi.getWikiEntry(entry.id));
    }
    selected.value = detailCache.get(entry.id);
  } catch (e) {
    console.error('위키 상세 로드 실패', e);
    selected.value = { ...entry, detail: [] };
  } finally {
    detailLoading.value = false;
  }
}

watch(() => props.gameSlug, () => { q.value = ''; load(); });
onMounted(() => load());
</script>
