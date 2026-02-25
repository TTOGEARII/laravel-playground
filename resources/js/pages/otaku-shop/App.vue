<template>
  <div class="otaku-shop-page">
    <header class="otaku-shop-header-bar">
      <a href="/" class="back-button">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
        돌아가기
      </a>
      <span class="otaku-shop-header-title">🛒 오타쿠 굿즈 가격비교</span>
    </header>
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
          <div class="sort-select">
            <label for="sort">정렬</label>
            <select id="sort" v-model="sortBy">
              <option value="price_asc">최저가 순</option>
              <option value="price_desc">가격 높은 순</option>
              <option value="release_desc">발매일 최신 순</option>
            </select>
          </div>
          <button class="compare-toggle-button">
            비교함 보기
            <span class="compare-badge">0</span>
          </button>
        </div>
      </div>

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
            :class="{ 'is-featured': index === 0 }"
          >
            <div class="product-thumbnail">
              <div class="thumb-image thumb-placeholder">
                {{ productLabelLetter(product) }}
              </div>
            </div>
            <div class="product-main">
              <div class="product-meta">
                <span v-if="product.ok_product_brand_label" class="badge badge-brand">
                  {{ product.ok_product_brand_label }}
                </span>
              </div>
              <h2 class="product-title">{{ product.ok_product_title }}</h2>
              <p v-if="product.ok_product_subtitle" class="product-subtitle">
                {{ product.ok_product_subtitle }}
              </p>
              <div class="product-tags">
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
                :class="{ 'is-lowest': isLowestOffer(product, offer) }"
              >
                <div class="shop-info">
                  <span class="shop-name">{{ offer.shop?.ok_shop_name || '-' }}</span>
                  <span v-if="isLowestOffer(product, offer)" class="shop-badge">최저가</span>
                </div>
                <div class="shop-price">
                  <div class="price-main">{{ formatPrice(offer) }}</div>
                  <div class="price-sub">{{ priceSub(offer) }}</div>
                </div>
                <a
                  :href="offer.ok_offer_external_url || '#'"
                  class="shop-link"
                  target="_blank"
                  rel="noopener"
                >
                  보러가기
                </a>
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
          @click="fetchProducts(meta.current_page - 1)"
        >
          이전
        </button>
        <span class="pagination-info">
          {{ meta.current_page }} / {{ meta.last_page }}
        </span>
        <button
          class="pagination-btn"
          :disabled="meta.current_page >= meta.last_page"
          @click="fetchProducts(meta.current_page + 1)"
        >
          다음
        </button>
      </div>

      <div v-if="products.length" class="compare-table-wrapper">
        <h3 class="compare-title">빠른 가격 비교</h3>
        <div class="compare-table">
          <div class="compare-row compare-header">
            <div class="compare-cell">상품명</div>
            <div class="compare-cell">최저가</div>
            <div class="compare-cell">평균가</div>
            <div class="compare-cell">최고가</div>
            <div class="compare-cell">가격 차이</div>
          </div>
          <div
            v-for="p in products"
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
import { ref, watch, onMounted } from 'vue';
import { otakuShopApi } from './api.js';

const categories = ref([]);
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
const selectedShopIds = ref([]);
const sortBy = ref('price_asc');
const priceMin = ref(0);
const priceMax = ref(200000);

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

function minOfferPrice(product) {
  const offers = product.offers || [];
  if (!offers.length) return null;
  return Math.min(...offers.map((o) => Number(o.ok_offer_price)));
}

function isLowestOffer(product, offer) {
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
  const offers = p.offers || [];
  if (!offers.length) return '-';
  const min = Math.min(...offers.map((o) => Number(o.ok_offer_price)));
  const curr = offers[0]?.ok_offer_currency || 'KRW';
  const sym = curr === 'KRW' ? '₩' : '¥';
  return sym + min.toLocaleString();
}

function compareAvg(p) {
  const offers = p.offers || [];
  if (!offers.length) return '-';
  const avg = offers.reduce((s, o) => s + Number(o.ok_offer_price), 0) / offers.length;
  const curr = offers[0]?.ok_offer_currency || 'KRW';
  const sym = curr === 'KRW' ? '₩' : '¥';
  return sym + Math.round(avg).toLocaleString();
}

function compareMax(p) {
  const offers = p.offers || [];
  if (!offers.length) return '-';
  const max = Math.max(...offers.map((o) => Number(o.ok_offer_price)));
  const curr = offers[0]?.ok_offer_currency || 'KRW';
  const sym = curr === 'KRW' ? '₩' : '¥';
  return sym + max.toLocaleString();
}

function compareDiff(p) {
  const offers = p.offers || [];
  if (offers.length < 2) return '-';
  const min = Math.min(...offers.map((o) => Number(o.ok_offer_price)));
  const max = Math.max(...offers.map((o) => Number(o.ok_offer_price)));
  const diff = max - min;
  const curr = offers[0]?.ok_offer_currency || 'KRW';
  return diff > 0 ? `+ ${diff.toLocaleString()}${curr === 'KRW' ? '원' : ''}` : '-';
}

function compareDiffClass(p) {
  const offers = p.offers || [];
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
      shop_id: selectedShopIds.value,
      sort: sortBy.value,
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

function resetFilters() {
  keyword.value = '';
  selectedCategoryId.value = null;
  selectedShopIds.value = shops.value.length ? shops.value.map((s) => s.ok_shop_id) : [];
  priceMin.value = 0;
  priceMax.value = 200000;
  fetchProducts(1);
}

onMounted(() => {
  fetchCategories();
  fetchShops().then(() => fetchProducts(1));
});

watch([selectedCategoryId, sortBy], () => fetchProducts(1));
watch(selectedShopIds, () => fetchProducts(1), { deep: true });
</script>
