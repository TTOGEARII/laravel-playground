<?php

namespace App\Services\OtakuShop;

use App\Models\OtakuShop\OtakuCategory;
use App\Models\OtakuShop\OtakuIp;
use App\Models\OtakuShop\OtakuOffer;
use App\Models\OtakuShop\OtakuProduct;
use App\Models\OtakuShop\OtakuShop;
use App\Services\OtakuShop\Crawler\ProductNormalizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ProductService
{
    public function __construct(
        private ProductNormalizer $normalizer,
        private ExchangeRateService $exchangeRates,
    ) {}

    /**
     * 필터용 카테고리 목록 (정렬 순).
     */
    public function getCategoriesForFilter(): Collection
    {
        return OtakuCategory::orderBy('ok_category_sort')->get();
    }

    /**
     * 필터용 사용 중인 샵 목록. region(kr/global)을 주면 해당 지역 샵만(해외관 필터용).
     */
    public function getShopsForFilter(?string $region = null): Collection
    {
        return OtakuShop::where('ok_shop_active_flg', true)
            ->when($region !== null, fn ($q) => $q->where('ok_shop_region', $region))
            ->orderBy('ok_shop_name')
            ->get();
    }

    /**
     * 필터용 IP(작품) 목록. 실제 상품이 1개 이상 붙은 IP만, 상품 많은 순으로.
     */
    public function getIpsForFilter(): Collection
    {
        return OtakuIp::query()
            ->withCount('products')
            ->whereHas('products')  // 상품이 1개 이상 붙은 IP만
            ->orderByDesc('products_count')
            ->get();
    }

    /**
     * 상품 목록 조회 (간단 필터 포함).
     */
    public function listProducts(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = OtakuProduct::query()->with([
            'category',
            'ip',
            // 품절 오퍼도 화면에 '품절'로 노출하기 위해 함께 로드한다.
            // (판매중 → 품절 순, 그 안에서 가격 오름차순)
            'offers' => function ($q) {
                $q->with('shop')
                    ->orderByDesc('ok_offer_available_flg')
                    ->orderBy('ok_offer_price');
            },
        ]);

        if (! empty($filters['keyword'])) {
            $this->applyKeyword($query, (string) $filters['keyword']);
        }

        // 지역 필터: 해당 지역 샵의 오퍼가 1개 이상인 상품(국내관 kr / 해외관 global).
        // 상품은 공통이라, 교차 매칭된 상품은 양쪽 페이지에 다 뜨고 오퍼 목록에 두 지역 가격이 함께 보인다.
        if (! empty($filters['region'])) {
            $region = $filters['region'];
            $query->whereHas('offers.shop', fn ($q) => $q->where('ok_shop_region', $region));
        }

        if (! empty($filters['brand'])) {
            $query->where('ok_product_brand_label', $filters['brand']);
        }

        if (! empty($filters['shop_ids']) && is_array($filters['shop_ids'])) {
            $shopIds = array_filter(array_map('intval', $filters['shop_ids']));
            if ($shopIds !== []) {
                $query->whereHas('offers', function ($q) use ($shopIds) {
                    $q->whereIn('ok_offer_shop_id', $shopIds);
                });
            }
        }

        if (! empty($filters['active_only'])) {
            $query->where('ok_product_active_flg', true);
        }

        // 가격비교 가능(2개 이상 쇼핑몰에 오퍼가 있는) 상품만.
        // 한쪽이 품절이어도 '가격 vs 품절'로 비교 노출되므로 오퍼 수 기준으로 센다.
        if (! empty($filters['compared_only'])) {
            $query->has('offers', '>=', 2);
        }

        // 재고 있는 상품만 (어느 한 샵이라도 판매중 오퍼가 있는 상품).
        if (! empty($filters['in_stock_only'])) {
            $query->whereHas('offers', function ($q) {
                $q->where('ok_offer_available_flg', true);
            });
        }

        // 가격 범위(원화 기준). 해외 오퍼(JPY 등)는 환율로 원화 환산해 비교하므로 국내·해외관 모두
        // 항상 원(₩) 기준으로 일관되게 걸린다. 판매중 오퍼 중 범위에 드는 게 하나라도 있으면 매칭.
        // ※ 경계값은 반드시 int 로 바인딩한다 — float 은 PDO(PARAM_STR)로 텍스트 바인딩돼 SQLite 에서
        //   "숫자 < 텍스트" 규칙 때문에 비교가 항상 거짓이 된다(원화 금액은 정수라 int 로 충분).
        $priceMin = isset($filters['price_min']) ? (int) $filters['price_min'] : 0;
        $priceMax = isset($filters['price_max']) ? (int) $filters['price_max'] : 0;
        if ($priceMin > 0 || $priceMax > 0) {
            $krw = 'ok_offer_price * '.$this->currencyToKrwCaseSql();
            $query->whereHas('offers', function ($q) use ($priceMin, $priceMax, $krw) {
                $q->where('ok_offer_available_flg', true);
                if ($priceMin > 0) {
                    $q->whereRaw("{$krw} >= ?", [$priceMin]);
                }
                if ($priceMax > 0) {
                    $q->whereRaw("{$krw} <= ?", [$priceMax]);
                }
            });
        }

        if (isset($filters['category_id']) && $filters['category_id'] !== '' && $filters['category_id'] !== null) {
            $categoryId = (int) $filters['category_id'];
            if ($categoryId > 0) {
                $query->where('ok_product_cate_id', $categoryId);
            }
        }

        if (isset($filters['ip_id']) && $filters['ip_id'] !== '' && $filters['ip_id'] !== null) {
            $ipId = (int) $filters['ip_id'];
            if ($ipId > 0) {
                $query->where('ok_product_ip_id', $ipId);
            }
        }

        // 발매(예정)일이 있는 상품만(예: 예약/발매예정 모아보기).
        if (! empty($filters['has_release'])) {
            $query->whereNotNull('ok_product_release_date');
        }

        // 발매예정(오늘 이후 발매일) 상품만.
        if (! empty($filters['upcoming'])) {
            $query->whereNotNull('ok_product_release_date')
                ->whereDate('ok_product_release_date', '>=', now()->toDateString());
        }

        // sort: price_asc|price_desc(판매중 오퍼 최저가 기준)|release_desc(최신 발매순)|release_asc(발매 임박순)|created_desc(최근 등록순)
        $sort = $filters['sort'] ?? 'created_desc';
        if ($sort === 'price_asc' || $sort === 'price_desc') {
            // 가격은 offer 테이블에 있으므로 판매중 오퍼의 최저가를 상관 서브쿼리로 끌어와 정렬한다.
            // 해외 오퍼(JPY 등)는 원화 환산 기준으로 비교해야 하므로 통화별 환산율 CASE 를 곱한다.
            $minPriceSub = OtakuOffer::query()
                ->selectRaw('MIN(ok_offer_price * '.$this->currencyToKrwCaseSql().')')
                ->whereColumn('ok_offer_product_id', 'otaku_product.ok_product_id')
                ->where('ok_offer_available_flg', true);

            $query->select('otaku_product.*')
                ->selectSub($minPriceSub, 'min_offer_price')
                ->orderByRaw('min_offer_price IS NULL')  // 판매중 오퍼 없는 상품(가격 없음)은 뒤로
                ->orderBy('min_offer_price', $sort === 'price_asc' ? 'asc' : 'desc')
                ->orderByDesc('ok_product_id');
        } elseif ($sort === 'release_desc') {
            $query->orderByRaw('ok_product_release_date IS NULL')  // 발매일 없는 건 뒤로
                ->orderByDesc('ok_product_release_date')->orderByDesc('ok_product_id');
        } elseif ($sort === 'release_asc') {
            $query->orderByRaw('ok_product_release_date IS NULL')
                ->orderBy('ok_product_release_date')->orderByDesc('ok_product_id');
        } elseif ($sort === 'created_desc') {
            // 최근 등록순: 등록일(create_dt) 최신순, 동일하면 id 내림차순.
            $query->orderByDesc('create_dt')->orderByDesc('ok_product_id');
        } else {
            $query->orderByDesc('ok_product_id');
        }

        $paginator = $query->paginate($perPage);

        // 해외 오퍼(KRW 외 통화)에 원화 환산가를 실어준다(표시용 — ¥12,800 (약 ₩116,000)).
        foreach ($paginator->items() as $product) {
            foreach ($product->offers as $offer) {
                $currency = (string) ($offer->ok_offer_currency ?? 'KRW');
                if ($currency !== '' && strtoupper($currency) !== 'KRW') {
                    $offer->setAttribute('ok_offer_price_krw', $this->exchangeRates->toKrw((float) $offer->ok_offer_price, $currency));
                }
            }
        }

        return $paginator;
    }

    /**
     * 통화 코드 → 원화 환산율 CASE SQL(가격 정렬용). 환율 미보유 통화는 1로 두어 정렬만 흔들리지 않게 한다.
     * 환산율은 상수로 인라인되는 소수라 SQL 인젝션 여지가 없다(모두 float 캐스팅).
     */
    private function currencyToKrwCaseSql(): string
    {
        $cases = '';
        foreach (\App\Models\OtakuShop\OtakuExchangeRate::pluck('ok_rate_krw', 'ok_rate_currency') as $currency => $rate) {
            $cases .= " WHEN '".preg_replace('/[^A-Z]/', '', strtoupper((string) $currency))."' THEN ".(float) $rate;
        }
        if ($cases === '') {
            return '1'; // 환율 미수집 상태 — WHEN 없는 CASE 는 SQL 문법 오류라 환산 없이 원값 비교
        }

        return '(CASE ok_offer_currency'.$cases.' ELSE 1 END)';
    }

    /**
     * 키워드 검색: 공백으로 나눈 토큰별 AND — 각 토큰이 제목/부제/브랜드 어딘가에 있으면 매칭되므로
     * 어순·붙여쓰기가 달라도 찾아진다("블루 아카이브 프라나" ↔ "블루아카이브 ... 프라나").
     * IP(작품)명은 여러 토큰에 걸쳐 있어도 인식한다: 연속 토큰을 붙여 ip_aliases 사전과 대조해
     * ("블루"+"아카이브" → 블루아카이브) 띄어쓴 검색과 붙여쓴 검색의 결과가 갈리지 않게 하고,
     * 해당 IP로 분류된 상품(ok_product_ip_id)과 별칭 표기(블아 등) 제목도 함께 매칭한다.
     */
    private function applyKeyword(Builder $query, string $keyword): void
    {
        $tokens = preg_split('/\s+/u', trim($keyword)) ?: [];
        $tokens = array_slice(array_values(array_filter($tokens, fn ($t) => $t !== '')), 0, 8); // 토큰 폭주 방어
        if ($tokens === []) {
            return;
        }

        // 공백 제거·소문자 표기 → IP 코드 (여러 토큰에 걸친 IP명 인식용)
        $aliases = (array) config('otaku-crawler.product_match.ip_aliases', []);
        $codeByCompact = [];
        foreach ($aliases as $code => $variants) {
            foreach (array_merge([$code], (array) $variants) as $v) {
                $codeByCompact[str_replace(' ', '', mb_strtolower($v))] = $code;
            }
        }

        $ipIdByCode = null; // 필요할 때 1회만 로드
        $total = count($tokens);

        for ($i = 0; $i < $total; $i += $consumed) {
            // 연속 토큰(긴 결합 우선, 최대 3개)을 붙여 IP명/별칭과 대조.
            $ipCode = null;
            $consumed = 1;
            for ($len = min(3, $total - $i); $len >= 1; $len--) {
                $compact = str_replace(' ', '', mb_strtolower(implode('', array_slice($tokens, $i, $len))));
                if (isset($codeByCompact[$compact])) {
                    $ipCode = $codeByCompact[$compact];
                    $consumed = $len;
                    break;
                }
            }
            // 단일 토큰 안에 별칭이 섞인 표기("블아굿즈" 등)도 기존처럼 인식
            $ipCode ??= $this->normalizer->extractIpCode($tokens[$i]);

            $phrase = implode(' ', array_slice($tokens, $i, $consumed));
            $rawLike = '%'.addcslashes($phrase, '%_\\').'%';
            // 붙여쓴 검색어 ↔ 띄어쓴 제목 흡수("카가미네린" → "카가미네 린 …"): 공백 제거한 제목과도 대조.
            // 캐릭터명은 IP 와 달리 별칭 사전이 없어, 이 일반 규칙이 붙여쓰기 차이를 담당한다.
            $compactLike = '%'.addcslashes(str_replace(' ', '', $phrase), '%_\\').'%';

            if ($ipCode !== null) {
                $ipIdByCode ??= OtakuIp::pluck('ok_ip_id', 'ok_ip_code')->all();
                $ipId = $ipIdByCode[$ipCode] ?? null;
                $variants = array_merge([$ipCode], (array) ($aliases[$ipCode] ?? []));
                $query->where(function ($q) use ($rawLike, $compactLike, $ipId, $variants) {
                    $q->where('ok_product_title', 'like', $rawLike)
                        ->orWhere('ok_product_subtitle', 'like', $rawLike)
                        ->orWhere('ok_product_brand_label', 'like', $rawLike)
                        ->orWhereRaw("REPLACE(ok_product_title, ' ', '') like ?", [$compactLike]);
                    if ($ipId !== null) {
                        $q->orWhere('ok_product_ip_id', $ipId);
                    }
                    foreach ($variants as $variant) {
                        $q->orWhere('ok_product_title', 'like', '%'.addcslashes($variant, '%_\\').'%');
                    }
                });
            } else {
                $query->where(function ($q) use ($rawLike, $compactLike) {
                    $q->where('ok_product_title', 'like', $rawLike)
                        ->orWhere('ok_product_subtitle', 'like', $rawLike)
                        ->orWhere('ok_product_brand_label', 'like', $rawLike)
                        ->orWhereRaw("REPLACE(ok_product_title, ' ', '') like ?", [$compactLike]);
                });
            }
        }
    }

    /**
     * 단일 상품 조회.
     */
    public function getProduct(int $id): ?OtakuProduct
    {
        return OtakuProduct::with(['category', 'offers'])->find($id);
    }

    /**
     * 상품 생성.
     */
    public function createProduct(array $data): OtakuProduct
    {
        return OtakuProduct::create($data);
    }

    /**
     * 상품 수정.
     */
    public function updateProduct(int $id, array $data): ?OtakuProduct
    {
        $product = OtakuProduct::find($id);

        if (! $product) {
            return null;
        }

        $product->fill($data);
        $product->save();

        return $product;
    }

    /**
     * 상품 삭제.
     */
    public function deleteProduct(int $id): bool
    {
        $product = OtakuProduct::find($id);

        if (! $product) {
            return false;
        }

        return (bool) $product->delete();
    }

    /**
     * 특정 상품의 최저가 Offer 목록 조회.
     */
    public function getLowestOffersForProduct(int $productId): Collection
    {
        return OtakuOffer::query()
            ->where('ok_offer_product_id', $productId)
            ->where('ok_offer_available_flg', true)
            ->orderBy('ok_offer_price')
            ->get();
    }
}
