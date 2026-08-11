<template>
  <div class="otaku-shop-page">
    <!-- 검색 바 (sticky) — 목업 shopbar -->
    <div class="shopbar">
      <div class="shell shopbar-in">
        <form class="searchbar sb-c" @submit.prevent="fetchProducts(1)">
          <input
            type="search"
            v-model="keyword"
            placeholder="상품·작품·캐릭터명으로 검색 (예: 넨도로이드 아스카)"
          />
          <button class="btn btn-sm" type="submit">검색</button>
        </form>
        <div class="seg">
          <a class="seg-b" :class="{ on: region !== 'global' }" href="/otaku-shop">국내관</a>
          <a class="seg-b" :class="{ on: region === 'global' }" href="/otaku-shop/global">해외관 ✈️</a>
        </div>
        <button class="btn btn-soft btn-sm" type="button" @click="showFilters = true">⚙ 필터</button>
      </div>
    </div>

    <section class="shell stack g3" style="padding-top:var(--s4)">
      <!-- 모바일: 굿즈 종류 드롭다운(가로 스크롤 대체). PC 는 아래 catrow 타일 -->
      <div class="cat-select" :class="{ 'is-open': catOpen }" ref="catSelectEl">
        <button type="button" class="cat-select-trigger" @click="catOpen = !catOpen">
          <span class="cat-select-cur"><span class="cat-i">{{ selectedCatEmoji }}</span>{{ selectedCatLabel }}</span>
          <svg viewBox="0 0 24 24" class="cat-select-caret" fill="none" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 9l6 6 6-6" />
          </svg>
        </button>
        <div v-if="catOpen" class="cat-select-panel">
          <button type="button" class="cat-opt" :class="{ 'is-sel': selectedCategoryId === null }" @click="pickCategory(null)">
            <span class="cat-i">🛍️</span> 전체
          </button>
          <button
            v-for="cat in categories"
            :key="cat.ok_category_id"
            type="button"
            class="cat-opt"
            :class="{ 'is-sel': selectedCategoryId === cat.ok_category_id }"
            @click="pickCategory(cat.ok_category_id)"
          >
            <span class="cat-i">{{ categoryEmoji(cat) }}</span> {{ cat.ok_category_label }}
          </button>
        </div>
      </div>

      <!-- 카테고리 타일 — 목업 catrow (PC) -->
      <div class="catrow">
        <a
          class="cat"
          :class="{ 'is-active': selectedCategoryId === null }"
          href="#"
          @click.prevent="selectedCategoryId = null"
        >
          <span class="cat-i">🛍️</span><span class="cat-n">전체</span>
        </a>
        <a
          v-for="cat in categories"
          :key="cat.ok_category_id"
          class="cat"
          :class="{ 'is-active': selectedCategoryId === cat.ok_category_id }"
          href="#"
          @click.prevent="selectedCategoryId = cat.ok_category_id"
        >
          <span class="cat-i">{{ categoryEmoji(cat) }}</span>
          <span class="cat-n">{{ cat.ok_category_label }}</span>
        </a>
      </div>

      <!-- 정렬 + 토글 — 목업 sortbar -->
      <div class="sortbar">
        <div class="chips">
          <button
            v-for="opt in sortOptions"
            :key="opt.value"
            class="chip"
            :class="{ on: sortBy === opt.value }"
            type="button"
            @click="sortBy = opt.value"
          >
            {{ opt.label }}
          </button>
          <button class="chip" :class="{ on: comparedOnly }" type="button" @click="comparedOnly = !comparedOnly">가격비교 가능만</button>
          <button class="chip" :class="{ on: upcomingOnly }" type="button" @click="upcomingOnly = !upcomingOnly">발매예정만</button>
          <button class="chip" :class="{ on: inStockOnly }" type="button" @click="inStockOnly = !inStockOnly">품절 제외</button>
        </div>
        <span class="sort-note">{{ meta.total ? meta.total.toLocaleString() + '개 상품 · 실시간 수집' : '실시간 수집' }}</span>
      </div>
    </section>

    <section class="shell stack g3" style="padding-top:var(--s3)">
      <div class="sec-head" style="margin-bottom:0">
        <div class="stack g1">
          <span class="eyebrow">POPULAR NOW</span>
          <h2>지금 많이 찾는 굿즈</h2>
        </div>
        <span class="sort-cur">{{ currentSortLabel }}</span>
      </div>

      <!-- 페이지 이동 시 이 위치로 스크롤 -->
      <div ref="resultsTopEl" class="results-top-anchor"></div>

      <div v-if="loading" class="empty">불러오는 중...</div>
      <template v-else>
        <!-- 상품 그리드 — 목업 prodgrid -->
        <div v-if="products.length" class="prodgrid">
          <a
            v-for="product in products"
            :key="product.ok_product_id"
            class="prod"
            href="#"
            @click.prevent="openDetail(product)"
          >
            <span class="prod-thumb">
              <img
                v-if="product.ok_product_image_url && !failedImg.has(product.ok_product_id)"
                :src="product.ok_product_image_url"
                :alt="product.ok_product_title || '상품 이미지'"
                loading="lazy"
                @error="markImgFailed(product.ok_product_id)"
              />
              <span v-else class="prod-emo">{{ categoryEmojiForProduct(product) }}</span>
              <span v-if="badgeOf(product)" class="prod-badge">{{ badgeOf(product) }}</span>
              <button
                type="button"
                class="prod-wish"
                :class="{ 'is-on': isWished(product) }"
                :title="isWished(product) ? '찜 해제' : '찜하기 — 재입고되면 알림'"
                @click.prevent.stop="toggleWish(product)"
              >
                {{ isWished(product) ? '♥' : '♡' }}
              </button>
            </span>
            <span class="prod-info">
              <span class="prod-shop">{{ shopSummary(product) }}</span>
              <span class="prod-t">{{ product.ok_product_title }}</span>
              <span class="prod-price">
                <em v-if="discountPct(product)">{{ discountPct(product) }}%</em>
                <span class="prod-amt"><b>{{ lowestAmountStr(product) }}</b>{{ lowestUnit(product) }}</span>
                <s v-if="highestAmountStr(product)">{{ highestAmountStr(product) }}</s>
              </span>
            </span>
          </a>
        </div>
        <p v-else class="empty">등록된 상품이 없습니다.</p>
      </template>

      <!-- 무한 스크롤: 센티넬이 보이면 다음 페이지를 이어 붙인다(append). '더 보기' 폴백 버튼 포함 -->
      <div v-if="hasMore" ref="sentinelEl" class="prod-sentinel">
        <button class="btn btn-soft" type="button" :disabled="loadingMore" @click="loadMore">
          {{ loadingMore ? '불러오는 중…' : '더 보기' }}
        </button>
      </div>
      <p v-else-if="products.length" class="prod-end">모든 상품을 불러왔어요 · 총 {{ meta.total.toLocaleString() }}개</p>

      <!-- 핫 키워드 — 목업 hotkey -->
      <div class="stack g2" style="margin-top:var(--s3)">
        <span class="eyebrow">HOT KEYWORD</span>
        <div class="chips hotkey">
          <button
            v-for="(kw, i) in popularKeywords"
            :key="kw"
            class="chip"
            :class="['pink', 'cyan', 'gold', '', '', ''][i % 6]"
            type="button"
            @click="quickSearch(kw)"
          >
            {{ kw }}
          </button>
        </div>
      </div>

      <p class="shop-note">표시된 가격·재고는 실시간 수집분으로 각 쇼핑몰 사정에 따라 다를 수 있어요. 정확한 정보는 각 쇼핑몰에서 확인해 주세요.</p>
    </section>

    <!-- 상품 상세 모달: 다중몰 가격 비교 (앱 핵심 기능 보존) -->
    <div v-if="detailProduct" class="prod-modal" @click.self="detailProduct = null">
      <div class="prod-modal-box card">
        <button class="prod-modal-x" type="button" aria-label="닫기" @click="detailProduct = null">×</button>
        <div class="prod-modal-head">
          <div class="prod-modal-thumb">
            <img
              v-if="detailProduct.ok_product_image_url && !failedImg.has(detailProduct.ok_product_id)"
              :src="detailProduct.ok_product_image_url"
              :alt="detailProduct.ok_product_title || '상품 이미지'"
              @error="markImgFailed(detailProduct.ok_product_id)"
            />
            <span v-else class="prod-emo">{{ categoryEmojiForProduct(detailProduct) }}</span>
          </div>
          <div class="prod-modal-meta">
            <div class="chips">
              <span v-if="isUpcoming(detailProduct)" class="chip gold">📅 발매예정 · {{ upcomingDDay(detailProduct) }}</span>
              <span v-if="offerCount(detailProduct) >= 2" class="chip cyan">🔥 {{ offerCount(detailProduct) }}개몰 비교<template v-if="savingStr(detailProduct)"> · 최대 {{ savingStr(detailProduct) }} 절약</template></span>
              <span v-if="detailProduct.ok_product_brand_label" class="chip">{{ detailProduct.ok_product_brand_label }}</span>
            </div>
            <h3 class="prod-modal-t">{{ detailProduct.ok_product_title }}</h3>
            <p v-if="detailProduct.ok_product_subtitle" class="prod-modal-sub">{{ detailProduct.ok_product_subtitle }}</p>
            <div class="prod-modal-tags">
              <button v-if="detailProduct.ip" type="button" class="tag" @click="selectedIpId = detailProduct.ip.ok_ip_id; detailProduct = null">
                # {{ detailProduct.ip.ok_ip_label }}
              </button>
              <span v-if="detailProduct.category" class="tag">{{ detailProduct.category.ok_category_label }}</span>
              <span class="prod-modal-rel">발매일: {{ releaseStr(detailProduct) }}</span>
            </div>
            <button
              type="button"
              class="btn btn-soft btn-sm prod-modal-wish"
              :class="{ 'is-on': isWished(detailProduct) }"
              @click="toggleWish(detailProduct)"
            >
              {{ isWished(detailProduct) ? '♥ 찜 해제' : '♡ 재입고 알림 찜' }}
            </button>
          </div>
        </div>
        <div class="prod-shops">
          <div
            v-for="offer in (detailProduct.offers || [])"
            :key="offer.ok_offer_id"
            class="shop-row"
            :class="{ 'is-lowest': isLowestOffer(detailProduct, offer), 'is-soldout': isSoldout(offer) }"
          >
            <div class="shop-info">
              <span class="shop-name">{{ offer.shop?.ok_shop_name || '-' }}</span>
              <span v-if="isSoldout(offer)" class="chip">품절</span>
              <span v-else-if="isLowestOffer(detailProduct, offer)" class="chip pink">최저가</span>
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
              class="btn btn-sm"
              target="_blank"
              rel="noopener"
            >
              보러가기
            </a>
            <span v-else class="btn btn-sm btn-soft is-disabled">품절</span>
          </div>
        </div>
      </div>
    </div>

    <!-- 상세 필터 드로어 (작품·샵·가격대 — 기능 보존) -->
    <div v-if="showFilters" class="filter-drawer-ov" @click.self="showFilters = false">
      <aside class="filter-drawer">
        <div class="filter-drawer-head">
          <h3>상세 필터</h3>
          <button class="prod-modal-x" type="button" aria-label="닫기" @click="showFilters = false">×</button>
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
          <h2 class="filter-title">가격 범위 <span class="filter-unit">₩ 원</span></h2>
          <div class="price-range">
            <div class="price-inputs">
              <div class="price-input">
                <span>최소</span>
                <input type="number" v-model.number="priceMin" min="0" step="1000" placeholder="0"
                  @change="fetchProducts(1)" @keyup.enter="fetchProducts(1)" />
              </div>
              <span class="price-separator">~</span>
              <div class="price-input">
                <span>최대</span>
                <input type="number" v-model.number="priceMax" min="0" step="1000" placeholder="제한 없음"
                  @change="fetchProducts(1)" @keyup.enter="fetchProducts(1)" />
              </div>
            </div>
            <div class="price-hint">{{ priceHint }}</div>
          </div>
        </div>

        <div class="filter-drawer-actions">
          <button class="btn btn-soft" type="button" @click="resetFilters">초기화</button>
          <button class="btn btn-fill" type="button" @click="showFilters = false">적용</button>
        </div>
      </aside>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { otakuShopApi } from './api.js';

const props = defineProps({
  loggedIn: { type: Boolean, default: false },
  region: { type: String, default: 'kr' }, // kr=국내관, global=해외관
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
const loadingMore = ref(false); // 무한 스크롤: 다음 페이지 이어붙이는 중
const keyword = ref('');
const selectedCategoryId = ref(null);
const selectedIpId = ref(null);
const selectedShopIds = ref([]);
const sortBy = ref('created_desc');
// 가격 범위는 원(₩) 기준. 해외관 오퍼(JPY)도 서버가 원화 환산해 비교한다. 빈 값(null)이면 미적용.
const priceMin = ref(null);
const priceMax = ref(null);
const priceHint = computed(() =>
  props.region === 'global' ? '원(₩) 환산 기준으로 검색합니다.' : '원(₩) 기준으로 검색합니다.',
);
const comparedOnly = ref(false);
const upcomingOnly = ref(false);
const inStockOnly = ref(false);
const popularKeywords = ['넨도로이드', '블루아카이브', '원신', '하츠네 미쿠', '피규어'];

// IP(작품) 검색 셀렉트박스 상태
const ipOpen = ref(false);
const ipSearch = ref('');
const ipComboboxEl = ref(null);
const ipSearchInput = ref(null);

// 굿즈 종류 드롭다운(모바일) 상태
const catOpen = ref(false);
const catSelectEl = ref(null);
const selectedCatLabel = computed(() => {
  if (selectedCategoryId.value === null) return '전체';
  return categories.value.find((c) => c.ok_category_id === selectedCategoryId.value)?.ok_category_label ?? '전체';
});
const selectedCatEmoji = computed(() => {
  if (selectedCategoryId.value === null) return '🛍️';
  const cat = categories.value.find((c) => c.ok_category_id === selectedCategoryId.value);
  return cat ? categoryEmoji(cat) : '🛍️';
});
function pickCategory(id) {
  selectedCategoryId.value = id;
  catOpen.value = false;
}

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
  if (catOpen.value && catSelectEl.value && !catSelectEl.value.contains(e.target)) {
    catOpen.value = false;
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
  // 해외 오퍼(JPY 등)는 서버가 실어준 원화 환산가를 병기한다(환율 기준, 배송비 별도).
  if (offer.ok_offer_price_krw != null) {
    return `약 ₩${Math.round(Number(offer.ok_offer_price_krw)).toLocaleString()} · 배송비 별도`;
  }
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

// ── 목업 카드(prod)용 헬퍼 — 마크업 재구성에 필요한 표시값. 기존 로직 재사용. ──
// 카테고리/상품명 키워드 기반 이모지(catrow 타일·이미지 폴백).
const CATEGORY_EMOJI = [
  [/피규|figure|넨도|스케일|프라모/i, '🧸'],
  [/아크릴|스탠드|아크/i, '🪧'],
  [/뱃지|뱆지|캔뱃|버튼/i, '📛'],
  [/타페|천|패브릭/i, '🎏'],
  [/화보|아트북|서적|book|일러|잡지/i, '📚'],
  [/세트|set|박스|패키지/i, '🎁'],
  [/인형|plush|봉제/i, '🧸'],
  [/키링|스트랩|악세|액세|acc|참/i, '🔑'],
  [/의류|티셔츠|후드|apparel|파카/i, '👕'],
  [/컵|머그|식기|텀블러/i, '☕'],
  [/포스터|poster|브로마이드/i, '🖼️'],
  [/카드|포토|트카/i, '🎴'],
];
function emojiFor(label) {
  const s = String(label || '');
  for (const [re, e] of CATEGORY_EMOJI) if (re.test(s)) return e;
  return '🛍️';
}
function categoryEmoji(cat) {
  return emojiFor(cat.ok_category_label);
}
function categoryEmojiForProduct(p) {
  return emojiFor(p.category?.ok_category_label || p.ok_product_title);
}

// 정렬 칩(백엔드 지원값만) + 현재 정렬 라벨
const sortOptions = [
  { value: 'price_asc', label: '최저가순' },
  { value: 'created_desc', label: '최근 등록순' },
  { value: 'price_desc', label: '가격 높은순' },
];
const currentSortLabel = computed(() => {
  const map = {
    price_asc: '가격 낮은 순',
    price_desc: '가격 높은 순',
    created_desc: '최근 등록 순',
    release_desc: '발매일 늦은 순',
    release_asc: '발매일 빠른 순',
  };
  return map[sortBy.value] || '';
});

// 원화 환산 기준 비교가(해외관 JPY 오퍼도 공정 비교)
function comparablePrice(o) {
  return o.ok_offer_price_krw != null ? Number(o.ok_offer_price_krw) : Number(o.ok_offer_price);
}
function lowestOffer(product) {
  const offers = availableOffers(product);
  if (!offers.length) return null;
  return offers.reduce((a, b) => (comparablePrice(b) < comparablePrice(a) ? b : a));
}
function highestOffer(product) {
  const offers = availableOffers(product);
  if (!offers.length) return null;
  return offers.reduce((a, b) => (comparablePrice(b) > comparablePrice(a) ? b : a));
}
function amountOf(offer) {
  return offer ? Number(offer.ok_offer_price).toLocaleString() : '-';
}
function unitOf(offer) {
  return offer && offer.ok_offer_currency && offer.ok_offer_currency !== 'KRW' ? '엔' : '원';
}
function lowestAmountStr(product) {
  return amountOf(lowestOffer(product));
}
function lowestUnit(product) {
  const o = lowestOffer(product);
  return o ? unitOf(o) : '원';
}
function highestAmountStr(product) {
  const lo = lowestOffer(product);
  const hi = highestOffer(product);
  if (!lo || !hi || comparablePrice(hi) <= comparablePrice(lo)) return '';
  return amountOf(hi) + unitOf(hi);
}
function discountPct(product) {
  const lo = lowestOffer(product);
  const hi = highestOffer(product);
  if (!lo || !hi) return 0;
  const min = comparablePrice(lo);
  const max = comparablePrice(hi);
  if (max <= min) return 0;
  return Math.round((1 - min / max) * 100);
}
function shopSummary(product) {
  const lo = lowestOffer(product);
  const n = offerCount(product);
  if (!lo) return product.ok_product_brand_label || '';
  const shop = lo.shop?.ok_shop_name || '';
  return n > 1 ? `${shop} 외 ${n - 1}개몰` : shop;
}
function badgeOf(product) {
  if (isUpcoming(product)) return '발매예정';
  if (offerCount(product) >= 2) return '최저가';
  return '';
}

// 이미지 로드 실패한 상품 id → 이모지 폴백
const failedImg = ref(new Set());
function markImgFailed(id) {
  failedImg.value.add(id);
  failedImg.value = new Set(failedImg.value);
}

// 상품 상세(다중몰 비교) 모달 + 상세 필터 드로어
const detailProduct = ref(null);
function openDetail(product) {
  detailProduct.value = product;
}
const showFilters = ref(false);

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
    const res = await otakuShopApi.getShops(props.region);
    shops.value = res.data || [];
    if (selectedShopIds.value.length === 0 && shops.value.length) {
      selectedShopIds.value = shops.value.map((s) => s.ok_shop_id);
    }
  } catch (e) {
    console.error('shops', e);
    shops.value = [];
  }
}

// append=true 면 다음 페이지를 기존 목록에 이어붙인다(무한 스크롤). 아니면 새로 교체.
async function fetchProducts(page = 1, append = false) {
  if (append) loadingMore.value = true;
  else loading.value = true;
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
      region: props.region,
      price_min: priceMin.value || undefined,
      price_max: priceMax.value || undefined,
    });
    const rows = res.data || [];
    products.value = append ? products.value.concat(rows) : rows;
    meta.value = res.meta || meta.value;
  } catch (e) {
    console.error('products', e);
    if (!append) products.value = [];
  } finally {
    if (append) loadingMore.value = false;
    else loading.value = false;
  }
}

// 무한 스크롤 — 센티넬이 뷰포트 근처에 오면 다음 페이지를 이어붙인다.
const hasMore = computed(() => (meta.value.current_page || 1) < (meta.value.last_page || 1));
const sentinelEl = ref(null);
let productIO = null;
function loadMore() {
  if (hasMore.value && !loading.value && !loadingMore.value) {
    fetchProducts((meta.value.current_page || 1) + 1, true);
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
  priceMin.value = null;
  priceMax.value = null;
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
  // 무한 스크롤 옵저버(센티넬은 v-if 라 mount 시점에 watch 로 관찰 대상 연결)
  productIO = new IntersectionObserver(
    (entries) => { if (entries.some((e) => e.isIntersecting)) loadMore(); },
    { rootMargin: '600px 0px' },
  );
});

// 센티넬 엘리먼트가 나타나면 관찰, 사라지면 해제(다음 페이지 없으면 v-if 로 언마운트)
watch(sentinelEl, (el, prev) => {
  if (!productIO) return;
  if (prev) productIO.unobserve(prev);
  if (el) productIO.observe(el);
});

onBeforeUnmount(() => {
  document.removeEventListener('mousedown', onDocClick);
  if (productIO) productIO.disconnect();
});

watch([selectedCategoryId, selectedIpId, sortBy, comparedOnly, upcomingOnly, inStockOnly], () => fetchProducts(1));
watch(selectedShopIds, () => fetchProducts(1), { deep: true });
</script>
