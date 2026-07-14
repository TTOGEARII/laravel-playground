<template>
  <section class="sgr-module sgi-dex">
    <h3 class="sgr-module-title">📖 캐릭터정보 <small class="sgr-feed-hint">도감 · 내 보유 관리</small></h3>

    <!-- 도구줄: 검색 + 스키마 필터 + 보유 현황/관리 -->
    <div class="sgi-dex-controls">
      <input v-model.trim="q" type="search" class="sgi-dex-search" placeholder="캐릭터 이름 검색" />
      <select v-for="f in filterFields" :key="f.key" v-model="filters[f.key]" class="sgi-dex-filter">
        <option value="">{{ f.label }} 전체</option>
        <option v-for="opt in optionsFor(f.key)" :key="opt" :value="String(opt)">
          {{ f.type === 'stars' ? '★' + opt : fieldVal(f, opt) }}
        </option>
      </select>
      <label class="sgi-dex-owned-toggle"><input v-model="ownedOnly" type="checkbox" /> 보유만</label>
    </div>

    <div class="sgi-dex-status">
      <span class="sgi-dex-count">보유 <strong>{{ ownedCount }}</strong> / {{ characters.length }}</span>
      <div class="sgi-dex-manage">
        <button type="button" class="sgi-dex-mini" :disabled="!characters.length" @click="setAllOwned(true)">전체 보유</button>
        <button type="button" class="sgi-dex-mini" :disabled="!ownedCount" @click="setAllOwned(false)">전체 해제</button>
        <button type="button" class="sgi-dex-mini" @click="exportJson">내보내기</button>
        <button type="button" class="sgi-dex-mini" @click="fileInput?.click()">가져오기</button>
        <input ref="fileInput" type="file" accept="application/json" class="sgi-dex-file" @change="importJson" />
      </div>
    </div>
    <p v-if="!loggedIn" class="sgi-dex-guest">비로그인 상태 — 내 보유는 이 브라우저(localStorage)에만 저장돼요. 로그인 후 가져오기로 옮길 수 있어요.</p>

    <div class="sgi-dex-grid">
      <div
        v-for="c in filtered"
        :key="c.id"
        class="sgi-dex-card"
        :class="{ 'is-owned': isOwned(c) }"
      >
        <button type="button" class="sgi-dex-portrait" @click="selected = c">
          <img :src="c.image_url" :alt="c.name" loading="lazy" />
        </button>
        <!-- 빠른 보유 토글(모달 열지 않고) -->
        <button
          type="button"
          class="sgi-dex-own"
          :class="{ 'is-on': isOwned(c) }"
          :title="isOwned(c) ? '보유 해제' : '보유 표시'"
          @click="toggleOwned(c)"
        >{{ isOwned(c) ? '✓' : '＋' }}</button>
        <button type="button" class="sgi-dex-name" @click="selected = c">{{ c.name }}</button>
        <span v-if="c.traits.star" class="sgi-dex-stars">{{ '★'.repeat(c.traits.star) }}</span>
        <span v-else-if="c.rarity" class="sgi-dex-rarity">{{ c.rarity }}</span>
      </div>
    </div>

    <p v-if="loaded && !filtered.length" class="sgr-empty">조건에 맞는 캐릭터가 없어요.</p>
    <p v-if="message" class="sgi-dex-message">{{ message }}</p>

    <!-- 상세 모달: 큰 일러 + 속성 배지 + 보유/성장도 편집 -->
    <div v-if="selected" class="sgi-dex-modal" @click.self="closeModal">
      <div class="sgi-dex-modal-card">
        <button type="button" class="sgi-dex-modal-close" @click="closeModal">✕</button>
        <img class="sgi-dex-modal-img" :src="selected.image_url" :alt="selected.name" />
        <h4 class="sgi-dex-modal-name">
          {{ selected.name }}
          <span v-if="selected.traits.star" class="sgi-dex-stars">{{ '★'.repeat(selected.traits.star) }}</span>
          <span v-else-if="selected.rarity" class="sgi-dex-rarity">{{ selected.rarity }}</span>
        </h4>

        <div class="sgi-dex-badges">
          <template v-for="f in schema" :key="f.key">
            <span v-if="f.type !== 'stars' && selected.traits[f.key] != null"
              class="sgi-dex-badge" :class="{ 'is-tier': f.key === 'tier' }">
              {{ f.key === 'tier' ? selected.traits[f.key] + '티어' : fieldVal(f, selected.traits[f.key]) }}
            </span>
          </template>
        </div>

        <!-- 빌드 상세(명조 등: 에코세트·재료·최고무기·추천스탯·조합 영상) -->
        <div v-if="hasBuild" class="sgi-build">
          <section v-if="selected.traits.echo_sets?.length" class="sgi-build-sec">
            <h5 class="sgi-build-title">🎴 에코 세트</h5>
            <div class="sgi-build-pills">
              <span v-for="(e, i) in selected.traits.echo_sets" :key="i" class="sgi-build-pill is-echo">
                {{ e.sonata }} <b>{{ e.count }}</b>
              </span>
            </div>
          </section>

          <!-- 추천 무기(호요버스: 랭킹순 다수) -->
          <section v-if="selected.traits.rec_weapons?.length" class="sgi-build-sec">
            <h5 class="sgi-build-title">⚔️ 추천 무기 <small>추천순</small></h5>
            <div class="sgi-build-pills">
              <span v-for="(w, i) in selected.traits.rec_weapons" :key="i" class="sgi-build-pill" :class="{ 'is-echo': i === 0 }">
                <b v-if="i === 0">1</b> {{ w }}
              </span>
            </div>
          </section>

          <!-- 추천 세트(성유물/유물/디스크) -->
          <section v-if="selected.traits.rec_sets?.length" class="sgi-build-sec">
            <h5 class="sgi-build-title">🎴 추천 세트</h5>
            <div class="sgi-build-pills">
              <span v-for="(s, i) in selected.traits.rec_sets" :key="i" class="sgi-build-pill">{{ s }}</span>
            </div>
          </section>

          <section v-if="selected.traits.best_weapon?.name" class="sgi-build-sec">
            <h5 class="sgi-build-title">⚔️ 최고 무기</h5>
            <div class="sgi-build-weapon">
              <b>{{ selected.traits.best_weapon.name }}</b>
              <span v-for="(s, i) in selected.traits.best_weapon.stats ?? []" :key="i" class="sgi-build-stat">
                {{ s.k }} {{ s.v }}
              </span>
            </div>
            <p v-if="selected.traits.best_weapon.ability" class="sgi-build-ability">{{ selected.traits.best_weapon.ability }}</p>
          </section>

          <section v-if="selected.traits.best_stats?.length" class="sgi-build-sec">
            <h5 class="sgi-build-title">📊 추천 스탯</h5>
            <div class="sgi-build-pills">
              <span v-for="(s, i) in selected.traits.best_stats" :key="i" class="sgi-build-pill">{{ s }}</span>
            </div>
          </section>

          <section v-if="selected.traits.materials?.length" class="sgi-build-sec">
            <h5 class="sgi-build-title">🧪 강화 재료</h5>
            <div class="sgi-build-pills">
              <span v-for="(m, i) in selected.traits.materials" :key="i" class="sgi-build-pill">
                {{ m.name }}<b v-if="m.cost"> ×{{ m.cost }}</b>
              </span>
            </div>
          </section>

          <section v-if="selected.traits.comps?.length" class="sgi-build-sec">
            <h5 class="sgi-build-title">🎬 추천 조합 <small>유튜브</small></h5>
            <ul class="sgi-build-videos">
              <li v-for="v in selected.traits.comps" :key="v.url">
                <a :href="v.url" target="_blank" rel="noopener" class="sgi-build-video">
                  <img v-if="v.thumbnail" :src="v.thumbnail" :alt="v.title" loading="lazy" />
                  <span>{{ v.title }}</span>
                </a>
              </li>
            </ul>
          </section>
        </div>

        <!-- 내 보유 -->
        <button
          type="button"
          class="sgi-dex-own-btn"
          :class="{ 'is-on': isOwned(selected) }"
          @click="toggleOwned(selected)"
        >{{ isOwned(selected) ? '✓ 보유 중' : '＋ 미보유 (탭해서 보유 표시)' }}</button>

        <!-- 성장도(성장 필드 있는 게임 + 보유 시) -->
        <GrowthForm
          v-if="isOwned(selected) && growthSchema.length"
          :key="selected.id"
          :character="selected"
          :schema="growthSchema"
          :growth="pool[selected.external_key]?.growth ?? {}"
          @save="saveGrowth"
          @close="closeModal"
        />

        <!-- 위키 상세(호요랩 위키 캐릭터 매칭 시 — 스킬·이야기 등) -->
        <p v-if="wikiLoading" class="sgr-empty">위키 상세 불러오는 중…</p>
        <template v-else-if="wikiDetail">
          <section v-for="(s, i) in wikiDetail" :key="i" class="sgi-wiki-section">
            <h5 v-if="s.title" class="sgi-wiki-section-title">{{ s.title }}</h5>
            <dl v-if="s.rows?.length" class="sgi-dex-fields">
              <div v-for="(r, ri) in s.rows" :key="ri" class="sgi-dex-field">
                <dt>{{ r.label }}</dt>
                <dd>{{ r.value }}</dd>
              </div>
            </dl>
            <p v-for="(p, pi) in s.paragraphs ?? []" :key="pi" class="sgi-wiki-para">{{ p }}</p>
          </section>
        </template>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { raidApi } from '../api';
import GrowthForm from './GrowthForm.vue';

// 위키 상세(호요랩 위키 매칭 캐릭터) 상태
const wikiDetail = ref(null);
const wikiLoading = ref(false);
const wikiCache = new Map();

const props = defineProps({
  gameSlug: { type: String, required: true },
  pool: { type: Object, default: () => ({}) }, // App 이 소유(보유 맵) — 편집은 emit 으로 반영
  store: { type: Object, default: null }, // createPoolStore(loggedIn)
  loggedIn: { type: Boolean, default: false },
});

const emit = defineEmits(['pool-changed']);

const characters = ref([]);
const schema = ref([]);
const growthSchema = ref([]);
const loaded = ref(false);
const q = ref('');
const ownedOnly = ref(false);
const filters = reactive({});
const selected = ref(null);
const message = ref('');
const fileInput = ref(null);
const cache = new Map();

const filterFields = computed(() => schema.value.filter((f) => f.filter));

// 빌드 상세(에코세트·재료·무기·스탯·조합)를 가진 캐릭터인지 — 명조 등
const hasBuild = computed(() => {
  const t = selected.value?.traits ?? {};
  return !!(t.echo_sets?.length || t.best_weapon?.name || t.materials?.length || t.best_stats?.length
    || t.comps?.length || t.rec_weapons?.length || t.rec_sets?.length);
});

function isOwned(c) {
  return props.pool[c.external_key]?.owned === true;
}
const ownedCount = computed(() => characters.value.filter(isOwned).length);

// 스키마 필드의 선택적 labels 맵으로 원시값(WIND/Mad/fire…)을 한글로 표시
function fieldVal(field, raw) {
  return field.labels?.[raw] ?? raw;
}

function optionsFor(key) {
  const set = new Set();
  for (const c of characters.value) {
    const v = c.traits?.[key];
    if (v != null && v !== '') set.add(v);
  }
  return [...set].sort((a, b) => (typeof a === 'number' ? b - a : String(a).localeCompare(String(b), 'ko')));
}

const filtered = computed(() => {
  const kw = q.value.toLowerCase();
  return characters.value.filter((c) => {
    if (kw && !c.name.toLowerCase().includes(kw)) return false;
    if (ownedOnly.value && !isOwned(c)) return false;
    for (const [key, val] of Object.entries(filters)) {
      if (val !== '' && String(c.traits?.[key] ?? '') !== String(val)) return false;
    }
    return true;
  });
});

function closeModal() {
  selected.value = null;
}

// 선택 캐릭터가 위키에 매칭돼 있으면 상세(스킬·이야기)를 지연 로드
watch(selected, async (c) => {
  wikiDetail.value = null;
  if (!c?.wiki_entry_id) return;
  wikiLoading.value = true;
  try {
    if (!wikiCache.has(c.wiki_entry_id)) {
      wikiCache.set(c.wiki_entry_id, (await raidApi.getWikiEntry(c.wiki_entry_id)).detail ?? []);
    }
    wikiDetail.value = wikiCache.get(c.wiki_entry_id);
  } catch (e) {
    console.error('위키 상세 로드 실패', e);
  } finally {
    wikiLoading.value = false;
  }
});

/** 보유 토글 — 낙관적 반영(emit) 후 저장, 실패 시 롤백. */
async function toggleOwned(c) {
  if (!props.store) return;
  const owned = !isOwned(c);
  const prev = props.pool;
  const next = { ...prev };
  if (owned) next[c.external_key] = { owned: true, growth: prev[c.external_key]?.growth ?? null };
  else delete next[c.external_key];
  emit('pool-changed', { gameSlug: props.gameSlug, pool: next });

  try {
    if (owned) await props.store.save(props.gameSlug, c, true, next[c.external_key].growth);
    else await props.store.remove(props.gameSlug, c);
  } catch (e) {
    console.error('보유 저장 실패', e);
    emit('pool-changed', { gameSlug: props.gameSlug, pool: prev });
    flash('저장에 실패했어요.');
  }
}

async function saveGrowth(growth) {
  const c = selected.value;
  if (!props.store || !c) return;
  try {
    await props.store.save(props.gameSlug, c, true, growth);
    emit('pool-changed', { gameSlug: props.gameSlug, pool: { ...props.pool, [c.external_key]: { owned: true, growth } } });
    flash(`${c.name} 성장도를 저장했어요.`);
  } catch (e) {
    console.error('성장도 저장 실패', e);
    flash(e.response?.status === 422 ? '성장도 값이 올바르지 않아요.' : '저장에 실패했어요.');
  }
}

async function setAllOwned(owned) {
  if (!props.store) return;
  if (!owned && !confirm('보유 체크를 모두 해제할까요? (성장도 입력값은 유지됩니다)')) return;
  try {
    await props.store.importData({
      version: 1,
      game: props.gameSlug,
      characters: characters.value.map((c) => ({
        external_key: c.external_key,
        name: c.name,
        owned,
        growth: props.pool[c.external_key]?.growth ?? null,
      })),
    });
    const pool = await props.store.load(props.gameSlug, characters.value);
    emit('pool-changed', { gameSlug: props.gameSlug, pool });
    flash(owned ? `전체 ${characters.value.length}명 보유 표시` : '보유 전체 해제');
  } catch (e) {
    console.error('일괄 변경 실패', e);
    flash('일괄 변경에 실패했어요.');
  }
}

async function exportJson() {
  if (!props.store) return;
  try {
    const payload = await props.store.exportData(props.gameSlug);
    const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `my-characters-${props.gameSlug}.json`;
    a.click();
    URL.revokeObjectURL(a.href);
  } catch (e) {
    console.error('내보내기 실패', e);
    flash('내보내기에 실패했어요.');
  }
}

async function importJson(event) {
  const file = event.target.files?.[0];
  event.target.value = '';
  if (!file || !props.store) return;
  try {
    const payload = JSON.parse(await file.text());
    if (payload.game && payload.game !== props.gameSlug) {
      flash(`이 파일은 ${payload.game} 데이터예요. 해당 게임 탭에서 가져오세요.`);
      return;
    }
    const stats = await props.store.importData({ ...payload, game: props.gameSlug });
    const pool = await props.store.load(props.gameSlug, characters.value);
    emit('pool-changed', { gameSlug: props.gameSlug, pool });
    flash(`가져오기 완료 — ${stats.imported}건 적용${stats.missing ? `, ${stats.missing}건 매칭 실패` : ''}`);
  } catch (e) {
    console.error('가져오기 실패', e);
    flash('JSON 파일을 읽지 못했어요.');
  }
}

let messageTimer = null;
function flash(text) {
  message.value = text;
  clearTimeout(messageTimer);
  messageTimer = setTimeout(() => (message.value = ''), 4000);
}

async function load() {
  loaded.value = false;
  selected.value = null;
  try {
    if (!cache.has(props.gameSlug)) {
      cache.set(props.gameSlug, await raidApi.getCharacters(props.gameSlug));
    }
    const res = cache.get(props.gameSlug);
    characters.value = res.data.map((c) => ({ ...c, traits: c.traits ?? {} }));
    schema.value = res.meta?.student_schema ?? [];
    growthSchema.value = res.meta?.growth_schema ?? [];
    Object.keys(filters).forEach((k) => delete filters[k]);
    for (const f of filterFields.value) filters[f.key] = '';
  } catch (e) {
    console.error('캐릭터정보 로드 실패', e);
    characters.value = [];
    schema.value = [];
    growthSchema.value = [];
  } finally {
    loaded.value = true;
  }
}

watch(() => props.gameSlug, load);
onMounted(load);
</script>
