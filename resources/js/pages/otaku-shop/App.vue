<template>
  <div class="otaku-shop-page">
    <section class="otaku-search-hero">
      <h2 class="search-hero-title">어떤 굿즈를 찾으세요?</h2>
      <p class="search-hero-sub">여러 쇼핑몰의 <strong>같은 상품 가격</strong>을 한 번에 검색·비교하세요.</p>
      <div class="search-hero-box">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="search-hero-icon">
          <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M21 21l-4.35-4.35M11 18a7 7 0 1 1 0-14 7 7 0 0 1 0 14z"/>
        </svg>
        <input
          type="text"
          v-model="keyword"
          placeholder="상품명·작품명·캐릭터명으로 검색 (예: 넨도로이드 아스카)"
          @keyup.enter="fetchProducts(1)"
        />
        <button class="search-hero-button" @click="fetchProducts(1)">검색</button>
      </div>
      <div class="search-hero-tags">
        <span class="hero-tags-label">인기 검색어</span>
        <button
          v-for="kw in popularKeywords"
          :key="kw"
          class="hero-tag"
          @click="quickSearch(kw)"
        >
          {{ kw }}
        </button>
      </div>
    </section>

    <div class="otaku-shop-layout">
    <aside class="otaku-filter-panel">
      <div class="filter-section">
        <h2 class="filter-title">카테고리</h2>
        <ul class="filter-list">
          <li>
            <button
              class="filter-chip"
              :class="{ 'is-active': selectedCategoryId === null }"
              @click="selectedCategoryId = null"
            >
              전체
            </button>
          </li>
          <li v-for="cat in categories" :key="cat.ok_category_id">
            <button
              class="filter-chip"
              :class="{ 'is-active': selectedCategoryId === cat.ok_category_id }"
              @click="selectedCategoryId = cat.ok_category_id"
            >
              {{ cat.ok_category_label }}
            </button>
          </li>
        </ul>
      </div>

      <div class="filter-section">
        <h2 class="filter-title">작품 (IP)</h2>
        <div class="ip-combobox" :class="{ 'is-open': ipOpen }" ref="ipComboboxEl">
          <button type="button" class="ip-combobox-trigger" @click="toggleIpDropdown">
            <span class="ip-combobox-value">{{ selectedIpLabel }}</span>
            <svg viewBox="0 0 24 24" class="ip-combobox-caret" fill="none" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 9l6 6 6-6" />
            </svg>
          </button>
          <div v-if="ipOpen" class="ip-combobox-panel">
            <div class="ip-combobox-search">
              <input
                ref="ipSearchInput"
                type="text"
                v-model="ipSearch"
                placeholder="작품명 검색..."
                @keydown.esc="closeIpDropdown"
              />
            </div>
            <ul class="ip-combobox-list">
              <li>
                <button
                  type="button"
                  class="ip-option"
                  :class="{ 'is-selected': selectedIpId === null }"
                  @click="selectIp(null)"
                >
                  전체 작품
                </button>
              </li>
              <li v-for="ip in filteredIps" :key="ip.ok_ip_id">
                <button
                  type="button"
                  class="ip-option"
                  :class="{ 'is-selected': selectedIpId === ip.ok_ip_id }"
                  @click="selectIp(ip.ok_ip_id)"
                >
                  <span class="ip-option-label">{{ ip.ok_ip_label }}</span>
                  <span class="ip-option-count">{{ ip.products_count }}</span>
                </button>
              </li>
              <li v-if="!filteredIps.length" class="ip-option-empty">검색 결과가 없습니다.</li>
            </ul>
          </div>
        </div>
      </div>

      <div class="filter-section">
        <h2 class="filter-title">브랜드 / 샵</h2>
        <template v-if="shops.length">
          <label
            v-for="shop in shops"
            :key="shop.ok_shop_id"
            class="filter-checkbox"
          >
            <input
              type="checkbox"
              :value="shop.ok_shop_id"
              v-model="selectedShopIds"
            />
            <span>{{ shop.ok_shop_name }}</span>
          </label>
        </template>
        <p v-else class="filter-empty">등록된 샵이 없습니다.</p>
      </div>

      <div class="filter-section">
        <h2 class="filter-title">가격 범위</h2>
        <div class="price-range">
          <div class="price-inputs">
            <div class="price-input">
              <span>최소</span>
              <input type="number" v-model.number="priceMin" min="0" />
            </div>
            <span class="price-separator">~</span>
            <div class="price-input">
              <span>최대</span>
              <input type="number" v-model.number="priceMax" min="0" />
            </div>
          </div>
          <div class="price-hint">엔(¥) 기준 예시 값입니다.</div>
        </div>
      </div>

      <button class="filter-reset-button" @click="resetFilters">필터 초기화</button>
    </aside>

    <section class="otaku-content">
      <div class="otaku-toolbar">
        <div class="search-box">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="search-icon">
            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M21 21l-4.35-4.35M11 18a7 7 0 1 1 0-14 7 7 0 0 1 0 14z"/>
          </svg>
          <input
            type="text"
            v-model="keyword"
            placeholder="상품명, 작품명, 캐릭터명으로 검색"
            @keyup.enter="fetchProducts(1)"
          />
        </div>
        <div class="toolbar-right">
          <button
            class="compare-only-toggle"
            :class="{ 'is-active': comparedOnly }"
            @click="comparedOnly = !comparedOnly"
          >
            <span class="toggle-dot"></span>
            가격비교 가능만
          </button>
          <button
            class="compare-only-toggle"
            :class="{ 'is-active': upcomingOnly }"
            @click="upcomingOnly = !upcomingOnly"
          >
            <span class="toggle-dot"></span>
            발매예정만
          </button>
          <button
            class="compare-only-toggle"
            :class="{ 'is-active': inStockOnly }"
            @click="inStockOnly = !inStockOnly"
          >
            <span class="toggle-dot"></span>
            재고 있는 상품만
          </button>
          <div class="sort-select">
            <label for="sort">정렬</label>
            <select id="sort" v-model="sortBy">
              <option value="created_desc">최근 등록순</option>
              <option value="price_asc">최저가 순</option>
              <option value="price_desc">가격 높은 순</option>
              <option value="release_desc">발매일 늦은 순</option>
              <option value="release_asc">발매일 빠른 순</option>
            </select>
          </div>
        </div>
      </div>

      <!-- 페이지 이동 시 이 위치로 스크롤(하단 페이지네이션 눌러도 새 목록을 위에서부터 보게) -->
      <div ref="resultsTopEl" class="results-top-anchor"></div>

      <div class="compare-summary-bar" v-if="products.length">
        <div class="compare-summary-info">
          <span class="summary-label">현재 상품</span>
          <span class="summary-value">{{ meta.total }}개</span>
        </div>
      </div>

      <div v-if="loading" class="product-list-loading">로딩 중...</div>
      <div v-else class="product-list">
        <template v-if="products.length">
          <article
            v-for="(product, index) in products"
            :key="product.ok_product_id"
            class="product-card-row"
            :class="{ 'is-featured': index === 0, 'is-upcoming': isUpcoming(product) }"
          >
            <span v-if="isUpcoming(product)" class="upcoming-ribbon">발매예정</span>
            <button
              type="button"
              class="wish-btn"
              :class="{ 'is-on': isWished(product) }"
              :title="isWished(product) ? '찜 해제' : '찜하기 — 품절 상품이 재입고되면 푸시로 알려드려요'"
              :disabled="wishBusy.has(product.ok_product_id)"
              @click="toggleWish(product)"
            >
              {{ isWished(product) ? '♥' : '♡' }}
            </button>
            <div class="product-thumbnail">
              <div
                v-if="product.ok_product_image_url"
                class="thumb-image thumb-has-image"
              >
                <img
                  :src="product.ok_product_image_url"
                  :alt="product.ok_product_title || '상품 이미지'"
                  loading="lazy"
                />
              </div>
              <div
                v-else
                class="thumb-image thumb-placeholder"
              >
                {{ productLabelLetter(product) }}
              </div>
            </div>
            <div class="product-main">
              <div class="product-meta">
                <span v-if="isUpcoming(product)" class="badge badge-upcoming">
                  📅 발매예정 · {{ upcomingDDay(product) }}
                </span>
                <span v-if="offerCount(product) >= 2" class="badge badge-compare">
                  🔥 {{ offerCount(product) }}개 쇼핑몰 비교<template v-if="savingStr(product)"> · 최대 {{ savingStr(product) }} 절약</template>
                </span>
                <span v-if="product.ok_product_brand_label" class="badge badge-brand">
                  {{ product.ok_product_brand_label }}
                </span>
              </div>
              <h2 class="product-title">{{ product.ok_product_title }}</h2>
              <p v-if="product.ok_product_subtitle" class="product-subtitle">
                {{ product.ok_product_subtitle }}
              </p>
              <div class="product-tags">
                <button
                  v-if="product.ip"
                  type="button"
                  class="tag tag-ip"
                  @click="selectedIpId = product.ip.ok_ip_id"
                >
                  # {{ product.ip.ok_ip_label }}
                </button>
                <span v-if="product.category" class="tag tag-cat">
                  {{ product.category.ok_category_label }}
                </span>
                <span v-if="product.ok_product_brand_label" class="tag">
                  {{ product.ok_product_brand_label }}
                </span>
              </div>
              <div class="product-meta-detail">
                <span>발매일: {{ releaseStr(product) }}</span>
                <span>총 {{ (product.offers || []).length }}개 샵</span>
              </div>
            </div>
            <div class="product-shops">
              <div
                v-for="offer in (product.offers || [])"
                :key="offer.ok_offer_id"
                class="shop-row"
                :class="{ 'is-lowest': isLowestOffer(product, offer), 'is-soldout': isSoldout(offer) }"
              >
                <div class="shop-info">
                  <span class="shop-name">{{ offer.shop?.ok_shop_name || '-' }}</span>
                  <span v-if="isSoldout(offer)" class="shop-badge shop-badge-soldout">품절</span>
                  <span v-else-if="isLowestOffer(product, offer)" class="shop-badge">최저가</span>
                </div>
                <div class="shop-price">
                  <template v-if="isSoldout(offer)">
                    <div class="price-main price-soldout">품절</div>
                  </template>
                  <template v-else>
                    <div class="price-main">{{ formatPrice(offer) }}</div>
                    <div class="price-sub">{{ priceSub(offer) }}</div>
                  </template>
                </div>
                <a
                  v-if="!isSoldout(offer)"
                  :href="offer.ok_offer_external_url || '#'"
                  class="shop-link"
                  target="_blank"
                  rel="noopener"
                >
                  보러가기
                </a>
                <span v-else class="shop-link shop-link-disabled">품절</span>
              </div>
            </div>
          </article>
        </template>
        <p v-else class="product-list-empty">등록된 상품이 없습니다.</p>
      </div>

      <div v-if="meta.last_page > 1" class="product-pagination">
        <button
          class="pagination-btn"
          :disabled="meta.current_page <= 1"
          @click="goToPage(meta.current_page - 1)"
        >
          이전
        </button>
        <span class="pagination-info">
          {{ meta.current_page }} / {{ meta.last_page }}
        </span>
        <button
          class="pagination-btn"
          :disabled="meta.current_page >= meta.last_page"
          @click="goToPage(meta.current_page + 1)"
        >
          다음
        </button>
      </div>

      <div v-if="comparableProducts.length" class="compare-table-wrapper">
        <h3 class="compare-title">빠른 가격 비교 · {{ comparableProducts.length }}개 상품</h3>
        <div class="compare-table">
          <div class="compare-row compare-header">
            <div class="compare-cell">상품명</div>
            <div class="compare-cell">최저가</div>
            <div class="compare-cell">평균가</div>
            <div class="compare-cell">최고가</div>
            <div class="compare-cell">가격 차이</div>
          </div>
          <div
            v-for="p in comparableProducts"
            :key="p.ok_product_id"
            class="compare-row"
          >
            <div class="compare-cell compare-name">{{ compareTitle(p) }}</div>
            <div class="compare-cell">{{ compareMin(p) }}</div>
            <div class="compare-cell">{{ compareAvg(p) }}</div>
            <div class="compare-cell">{{ compareMax(p) }}</div>
            <div class="compare-cell compare-diff" :class="compareDiffClass(p)">
              {{ compareDiff(p) }}
            </div>
          </div>
        </div>
      </div>
    </section>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { otakuShopApi } from './api.js';

const props = defineProps({
  loggedIn: { type: Boolean, default: false },
});

const categories = ref([]);
const ips = ref([]);
const shops = ref([]);
const products = ref([]);
const meta = ref({
  current_page: 1,
  last_page: 1,
  per_page: 15,
  total: 0,
});
const loading = ref(false);
const keyword = ref('');
const selectedCategoryId = ref(null);
const selectedIpId = ref(null);
const selectedShopIds = ref([]);
const sortBy = ref('created_desc');
const priceMin = ref(0);
const priceMax = ref(200000);
const comparedOnly = ref(false);
const upcomingOnly = ref(false);
const inStockOnly = ref(false);
const popularKeywords = ['넨도로이드', '블루아카이브', '원신', '하츠네 미쿠', '피규어'];

// IP(작품) 검색 셀렉트박스 상태
const ipOpen = ref(false);
const ipSearch = ref('');
const ipComboboxEl = ref(null);
const ipSearchInput = ref(null);

const filteredIps = computed(() => {
  const q = ipSearch.value.trim().toLowerCase();
  if (!q) return ips.value;
  return ips.value.filter((ip) => (ip.ok_ip_label || '').toLowerCase().includes(q));
});

const selectedIpLabel = computed(() => {
  if (selectedIpId.value === null) return '전체 작품';
  const ip = ips.value.find((i) => i.ok_ip_id === selectedIpId.value);
  return ip ? `${ip.ok_ip_label} (${ip.products_count})` : '전체 작품';
});

function toggleIpDropdown() {
  ipOpen.value = !ipOpen.value;
  if (ipOpen.value) {
    ipSearch.value = '';
    nextTick(() => ipSearchInput.value?.focus());
  }
}
function closeIpDropdown() {
  ipOpen.value = false;
}
function selectIp(id) {
  selectedIpId.value = id;
  closeIpDropdown();
}
function onDocClick(e) {
  if (ipOpen.value && ipComboboxEl.value && !ipComboboxEl.value.contains(e.target)) {
    closeIpDropdown();
  }
}

// 품절 여부 (백엔드 ok_offer_available_flg=false → 품절). 값이 없으면 판매중으로 간주.
function isSoldout(offer) {
  return offer?.ok_offer_available_flg === false;
}

// 판매중(가격 비교에 쓰는) 오퍼만 추린다.
function availableOffers(product) {
  return (product.offers || []).filter((o) => !isSoldout(o));
}

// 빠른 가격 비교 표는 판매중 오퍼가 2개 이상이라 실제로 가격 비교가 되는 상품만 노출.
const comparableProducts = computed(() => products.value.filter((p) => availableOffers(p).length >= 2));

function quickSearch(kw) {
  keyword.value = kw;
  fetchProducts(1);
}

// 비교 배지는 '판매중' 오퍼 수 기준 (품절은 가격 비교 대상이 아님).
function offerCount(product) {
  return availableOffers(product).length;
}

function savingStr(product) {
  const offers = availableOffers(product);
  if (offers.length < 2) return '';
  const prices = offers.map((o) => Number(o.ok_offer_price));
  const diff = Math.max(...prices) - Math.min(...prices);
  return diff > 0 ? `₩${diff.toLocaleString()}` : '';
}

function productLabelLetter(product) {
  const label = product.ok_product_brand_label || 'P';
  return label.charAt ? label.charAt(0) : 'P';
}

function releaseStr(product) {
  const d = product.ok_product_release_date;
  if (!d) return '-';
  if (typeof d === 'string') {
    try {
      return d.slice(0, 7).replace(/-/, '.');
    } catch {
      return '-';
    }
  }
  return '-';
}

// 발매일이 오늘 이후면 발매예정.
function releaseDateOf(product) {
  const d = product.ok_product_release_date;
  if (!d || typeof d !== 'string') return null;
  const date = new Date(d.slice(0, 10) + 'T00:00:00');
  return isNaN(date.getTime()) ? null : date;
}

function isUpcoming(product) {
  const date = releaseDateOf(product);
  if (!date) return false;
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  return date >= today;
}

function upcomingDDay(product) {
  const date = releaseDateOf(product);
  if (!date) return '';
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const diff = Math.ceil((date - today) / 86400000);
  return diff <= 0 ? 'D-DAY' : `D-${diff}`;
}

function minOfferPrice(product) {
  const offers = availableOffers(product);
  if (!offers.length) return null;
  return Math.min(...offers.map((o) => Number(o.ok_offer_price)));
}

function isLowestOffer(product, offer) {
  if (isSoldout(offer)) return false;
  const min = minOfferPrice(product);
  return min !== null && Number(offer.ok_offer_price) === min;
}

function formatPrice(offer) {
  const n = Number(offer.ok_offer_price);
  return offer.ok_offer_currency === 'KRW' ? `₩${n.toLocaleString()}` : `¥${n.toLocaleString()}`;
}

function priceSub(offer) {
  const fee = offer.ok_offer_shipping_fee;
  return fee ? `배송비 ${Number(fee).toLocaleString()}원` : '배송비 별도';
}

function compareTitle(p) {
  const t = p.ok_product_title || '';
  return t.length > 30 ? t.slice(0, 30) + '…' : t;
}

function compareMin(p) {
  const offers = availableOffers(p);
  if (!offers.length) return '-';
  const min = Math.min(...offers.map((o) => Number(o.ok_offer_price)));
  const curr = offers[0]?.ok_offer_currency || 'KRW';
  const sym = curr === 'KRW' ? '₩' : '¥';
  return sym + min.toLocaleString();
}

function compareAvg(p) {
  const offers = availableOffers(p);
  if (!offers.length) return '-';
  const avg = offers.reduce((s, o) => s + Number(o.ok_offer_price), 0) / offers.length;
  const curr = offers[0]?.ok_offer_currency || 'KRW';
  const sym = curr === 'KRW' ? '₩' : '¥';
  return sym + Math.round(avg).toLocaleString();
}

function compareMax(p) {
  const offers = availableOffers(p);
  if (!offers.length) return '-';
  const max = Math.max(...offers.map((o) => Number(o.ok_offer_price)));
  const curr = offers[0]?.ok_offer_currency || 'KRW';
  const sym = curr === 'KRW' ? '₩' : '¥';
  return sym + max.toLocaleString();
}

function compareDiff(p) {
  const offers = availableOffers(p);
  if (offers.length < 2) return '-';
  const min = Math.min(...offers.map((o) => Number(o.ok_offer_price)));
  const max = Math.max(...offers.map((o) => Number(o.ok_offer_price)));
  const diff = max - min;
  const curr = offers[0]?.ok_offer_currency || 'KRW';
  return diff > 0 ? `+ ${diff.toLocaleString()}${curr === 'KRW' ? '원' : ''}` : '-';
}

function compareDiffClass(p) {
  const offers = availableOffers(p);
  if (offers.length < 2) return 'neutral';
  const min = Math.min(...offers.map((o) => Number(o.ok_offer_price)));
  const max = Math.max(...offers.map((o) => Number(o.ok_offer_price)));
  return max - min > 0 ? 'positive' : 'neutral';
}

async function fetchCategories() {
  try {
    const res = await otakuShopApi.getCategories();
    categories.value = res.data || [];
  } catch (e) {
    console.error('categories', e);
    categories.value = [];
  }
}

async function fetchIps() {
  try {
    const res = await otakuShopApi.getIps();
    ips.value = res.data || [];
  } catch (e) {
    console.error('ips', e);
    ips.value = [];
  }
}

async function fetchShops() {
  try {
    const res = await otakuShopApi.getShops();
    shops.value = res.data || [];
    if (selectedShopIds.value.length === 0 && shops.value.length) {
      selectedShopIds.value = shops.value.map((s) => s.ok_shop_id);
    }
  } catch (e) {
    console.error('shops', e);
    shops.value = [];
  }
}

async function fetchProducts(page = 1) {
  loading.value = true;
  try {
    const res = await otakuShopApi.getProducts({
      page,
      per_page: 15,
      keyword: keyword.value || undefined,
      category_id: selectedCategoryId.value ?? undefined,
      ip_id: selectedIpId.value ?? undefined,
      shop_id: selectedShopIds.value,
      sort: sortBy.value,
      compared_only: comparedOnly.value,
      upcoming: upcomingOnly.value,
      in_stock_only: inStockOnly.value,
    });
    products.value = res.data || [];
    meta.value = res.meta || meta.value;
  } catch (e) {
    console.error('products', e);
    products.value = [];
  } finally {
    loading.value = false;
  }
}

// 결과 목록 최상단 앵커 — 페이지 이동 시 여기로 스크롤한다.
const resultsTopEl = ref(null);

function scrollToResultsTop() {
  const el = resultsTopEl.value;
  if (!el) return;
  const y = el.getBoundingClientRect().top + window.scrollY - 12; // 살짝 여백
  window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
}

/** 페이지 이동: 결과 상단으로 스크롤 후 해당 페이지를 불러온다(하단에 머무르는 불편 해소). */
function goToPage(page) {
  scrollToResultsTop();
  fetchProducts(page);
}

function resetFilters() {
  keyword.value = '';
  selectedCategoryId.value = null;
  selectedIpId.value = null;
  selectedShopIds.value = shops.value.length ? shops.value.map((s) => s.ok_shop_id) : [];
  priceMin.value = 0;
  priceMax.value = 200000;
  comparedOnly.value = false;
  upcomingOnly.value = false;
  inStockOnly.value = false;
  fetchProducts(1);
}

// === 찜(재입고 알림) — 로그인 전용. 품절 상품이 다시 입고되면 웹푸시로 알려준다. ===
const wishedIds = ref(new Set());
const wishBusy = ref(new Set());

function isWished(product) {
  return wishedIds.value.has(product.ok_product_id);
}

async function loadWishes() {
  if (!props.loggedIn) return;
  try {
    wishedIds.value = new Set(await otakuShopApi.getWishes());
  } catch (e) {
    console.error('찜 목록 로드 실패', e);
  }
}

async function toggleWish(product) {
  if (!props.loggedIn) {
    if (window.confirm('찜(재입고 알림)은 로그인이 필요해요. 로그인 페이지로 이동할까요?')) {
      window.location.href = '/login';
    }
    return;
  }

  const id = product.ok_product_id;
  wishBusy.value.add(id);
  wishBusy.value = new Set(wishBusy.value);
  try {
    if (wishedIds.value.has(id)) {
      await otakuShopApi.removeWish(id);
      wishedIds.value.delete(id);
    } else {
      await otakuShopApi.addWish(id);
      wishedIds.value.add(id);
    }
    wishedIds.value = new Set(wishedIds.value); // Set 은 반응형 감지가 안 돼 재할당
  } catch (e) {
    console.error('찜 처리 실패', e);
  } finally {
    wishBusy.value.delete(id);
    wishBusy.value = new Set(wishBusy.value);
  }
}

onMounted(() => {
  fetchCategories();
  fetchIps();
  fetchShops().then(() => fetchProducts(1));
  loadWishes();
  document.addEventListener('mousedown', onDocClick);
});

onBeforeUnmount(() => {
  document.removeEventListener('mousedown', onDocClick);
});

watch([selectedCategoryId, selectedIpId, sortBy, comparedOnly, upcomingOnly, inStockOnly], () => fetchProducts(1));
watch(selectedShopIds, () => fetchProducts(1), { deep: true });
</script>
