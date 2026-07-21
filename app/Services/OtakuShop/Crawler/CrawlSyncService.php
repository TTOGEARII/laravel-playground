<?php

namespace App\Services\OtakuShop\Crawler;

use App\Models\OtakuShop\OtakuCategory;
use App\Models\OtakuShop\OtakuIp;
use App\Models\OtakuShop\OtakuOffer;
use App\Models\OtakuShop\OtakuProduct;
use App\Models\OtakuShop\OtakuShop;
use App\Models\OtakuShop\OtakuWish;
use App\Services\OtakuShop\Crawler\DTO\CrawledProductDto;
use Illuminate\Support\Carbon;

/**
 * 크롤 결과를 otaku_shop, otaku_category, otaku_product, otaku_offer 에 동기화.
 * MVC: Model 사용, 비즈니스 로직은 서비스에 두고, Console에서 이 서비스만 호출.
 */
class CrawlSyncService
{
    /** 이름 유사(포함관계) 매칭을 허용하는 카테고리 코드 (변별적 이름이 보장되는 피규어만). */
    private const FUZZY_CATEGORY = 'figure';

    /** 이름 유사 매칭에 필요한 최소 공통 코어 토큰 수(과병합 방지). */
    public const FUZZY_MIN_SHARED = 3;

    /** 이번 동기화에서 품절→구매가능으로 바뀐 상품 ID (찜 재입고 알림용). */
    private array $restockedProductIds = [];

    /** 찜이 하나라도 있는지 (없으면 재입고 감지 자체를 생략 — 전량 적재 시 불필요한 쿼리 방지). */
    private ?bool $anyWishExists = null;

    public function __construct(
        private ProductNormalizer $normalizer
    ) {}

    /**
     * 재입고 상품 ID 를 꺼내고 비운다. 크롤 커맨드가 동기화 후 호출해 찜 알림을 보낸다.
     *
     * @return int[]
     */
    public function pullRestockedProductIds(): array
    {
        $ids = array_keys($this->restockedProductIds);
        $this->restockedProductIds = [];

        return $ids;
    }

    /**
     * 1. config 의 샵 정보를 otaku_shop 에 insert (이미 있으면 스킵).
     */
    public function syncShops(): void
    {
        $shops = config('otaku-crawler.shops', []);
        foreach ($shops as $row) {
            OtakuShop::firstOrCreate(
                ['ok_shop_code' => $row['ok_shop_code']],
                [
                    'ok_shop_name' => $row['ok_shop_name'],
                    'ok_shop_url' => $row['ok_shop_url'] ?? null,
                    'ok_shop_active_flg' => true,
                ]
            );
        }
    }

    /**
     * 2. 공통 카테고리를 otaku_category 에 insert (이미 있으면 스킵).
     */
    public function syncCategories(): void
    {
        $categories = config('otaku-crawler.categories', []);
        foreach ($categories as $row) {
            OtakuCategory::firstOrCreate(
                ['ok_category_code' => $row['ok_category_code']],
                [
                    'ok_category_label' => $row['ok_category_label'],
                    'ok_category_sort' => $row['ok_category_sort'] ?? 0,
                ]
            );
        }
    }

    /**
     * 2-2. IP(작품) 사전을 otaku_ip 에 insert (이미 있으면 스킵).
     * config 의 ip_aliases 표준토큰을 코드로 쓴다. 표시 이름(label)은 별도 지정이 없으면 코드와 동일.
     */
    public function syncIps(): void
    {
        $labels = config('otaku-crawler.product_match.ip_labels', []);
        $sort = 0;
        foreach (array_keys(config('otaku-crawler.product_match.ip_aliases', [])) as $code) {
            $sort += 10;
            OtakuIp::firstOrCreate(
                ['ok_ip_code' => $code],
                [
                    'ok_ip_label' => $labels[$code] ?? $code,
                    'ok_ip_sort' => $sort,
                ]
            );
        }
    }

    /**
     * 3·4. 크롤된 상품 목록을 정규화 키로 묶어 otaku_product/otaku_offer 에 동기화한다.
     *
     * 가격비교의 핵심: 같은 정규화 키 = 동일 상품. 한 상품에 대해 쇼핑몰별로 오퍼는 "정확히 1건"이며
     * 같은 샵이 (일반/특전 등) 여러 변형을 올린 경우 그 중 최저가만 그 샵의 오퍼로 남긴다.
     * 이렇게 해야 쇼핑몰 간 가격비교가 같은 기준(샵별 최저가)으로 이뤄진다.
     *
     * @param  array<int, CrawledProductDto>  $crawledProducts
     * @param  bool  $incremental  현재 오퍼는 (샵, external_id) 단위로 항상 upsert 되므로 동작 차이는 없고,
     *                             증분/전체 호출 호환을 위해 시그니처만 유지한다.
     * @return array{products_created:int, products_matched:int, offers_created:int, offers_updated:int}
     */
    public function syncProductsAndOffers(array $crawledProducts, bool $incremental = true): array
    {
        $stats = ['products_created' => 0, 'products_matched' => 0, 'offers_created' => 0, 'offers_updated' => 0];
        $shopIds = OtakuShop::pluck('ok_shop_id', 'ok_shop_code')->all();
        $categoryByCode = OtakuCategory::pluck('ok_category_id', 'ok_category_code')->all();
        $ipIdByCode = OtakuIp::pluck('ok_ip_id', 'ok_ip_code')->all();
        $now = Carbon::now();

        foreach ($this->groupByProduct($crawledProducts, $shopIds) as $bundle) {
            $product = $this->findOrCreateProduct($bundle, $categoryByCode, $ipIdByCode, $stats);

            foreach ($bundle['offers'] as $shopId => $dto) {
                $this->upsertOffer($product, (int) $shopId, $dto, $now, $stats);
            }
        }

        $this->updateLowestPriceFlags();

        return $stats;
    }

    /**
     * 크롤 결과를 정규화 키(동일 상품)로 묶는다.
     * 같은 (상품, 샵)이면 최저가 DTO 하나만 유지해 샵별 오퍼 중복을 제거한다.
     *
     * @param  array<int, CrawledProductDto>  $crawledProducts
     * @param  array<string, int>  $shopIds  shop_code => ok_shop_id
     * @return array<string, array{key: string, base: string, makerCode: ?string, ipCode: ?string, dto: CrawledProductDto, offers: array<int, CrawledProductDto>}>
     */
    private function groupByProduct(array $crawledProducts, array $shopIds): array
    {
        $bundles = [];

        foreach ($crawledProducts as $dto) {
            $shopId = $shopIds[$dto->shopCode] ?? null;
            if ($shopId === null) {
                continue;
            }

            // 고유값(품번/JAN)이 있으면 그것을 매칭 키로 우선 사용한다. 제목 표기가 쇼핑몰마다 달라도
            // 같은 코드가 나와 동일상품으로 정확히 묶인다. 없으면 기존 제목 정규화 키로 폴백.
            // 단, 부속품(전용 케이스 등) 제목에는 본체 품번이 그대로 들어가므로 라인넘버형 품번은
            // 버린다(sanitizeMakerCode) — 본체와 같은 키로 병합돼 가격비교가 오염되는 것을 방지.
            // 또 품번 번호는 제조사가 다르면 겹칠 수 있고(예: 넨도 No.3057 미쿠 vs 니케 라피),
            // 정규화 키도 말머리([은혼]/[주술회전] 등)가 제거돼 다른 작품의 동일 제목 굿즈가 같은 키가
            // 될 수 있어 — 베이스 키에 IP(작품) 접미를 붙여 서로 다른 작품이 같은 번들로 묶이지 않게 한다.
            $makerCode = self::sanitizeMakerCode(
                $dto->makerCode ?? $this->normalizer->extractMakerCode($dto->title),
                $dto->title,
            );
            $ipCode = $this->normalizer->extractIpCode($dto->title);
            $base = $makerCode !== null
                ? 'mkr_'.md5($makerCode)
                : $this->normalizer->normalizeKey($dto->title, $dto->brandLabel);
            $key = $ipCode !== null ? $base.'-'.substr(md5($ipCode), 0, 8) : $base;

            $bundles[$key] ??= ['key' => $key, 'base' => $base, 'makerCode' => $makerCode, 'ipCode' => $ipCode, 'dto' => $dto, 'offers' => []];

            $existing = $bundles[$key]['offers'][$shopId] ?? null;
            if ($existing === null || $this->preferOffer($dto, $existing)) {
                $bundles[$key]['offers'][$shopId] = $dto;
            }
        }

        return $this->absorbIplessBundles($bundles);
    }

    /**
     * 2차 패스: 제목에서 IP를 못 뽑은(null) 번들을, 같은 베이스 키(품번/정규화 제목)의
     * IP付 번들이 "정확히 1개"일 때만 그쪽으로 흡수한다. 제목에 작품명이 없는 쇼핑몰 표기가
     * 같은 상품인데도 분리 적재되는 회귀를 막되, IP付 번들이 2개 이상이면
     * 어느 작품인지 판단할 수 없으므로 흡수하지 않는다(과병합 방지).
     *
     * @param  array<string, array{key: string, base: string, makerCode: ?string, ipCode: ?string, dto: CrawledProductDto, offers: array<int, CrawledProductDto>}>  $bundles
     * @return array<string, array{key: string, base: string, makerCode: ?string, ipCode: ?string, dto: CrawledProductDto, offers: array<int, CrawledProductDto>}>
     */
    private function absorbIplessBundles(array $bundles): array
    {
        $ipBundleKeysByBase = [];
        foreach ($bundles as $key => $bundle) {
            if ($bundle['ipCode'] !== null) {
                $ipBundleKeysByBase[$bundle['base']][] = $key;
            }
        }

        foreach ($bundles as $key => $bundle) {
            if ($bundle['ipCode'] !== null) {
                continue;
            }
            $targets = $ipBundleKeysByBase[$bundle['base']] ?? [];
            if (count($targets) !== 1) {
                continue;
            }
            foreach ($bundle['offers'] as $shopId => $dto) {
                $existing = $bundles[$targets[0]]['offers'][$shopId] ?? null;
                if ($existing === null || $this->preferOffer($dto, $existing)) {
                    $bundles[$targets[0]]['offers'][$shopId] = $dto;
                }
            }
            unset($bundles[$key]);
        }

        return $bundles;
    }

    /**
     * 같은 (상품,샵)에서 대표 오퍼를 고를 때 $candidate 가 $current 보다 나으면 true.
     * 재고 있는 쪽을 우선하고(품절 변형이 최저가여도 구매 가능한 가격이 비교 기준이 되도록),
     * 재고 상태가 같으면 더 싼 쪽을 택한다.
     */
    private function preferOffer(CrawledProductDto $candidate, CrawledProductDto $current): bool
    {
        if ($candidate->available !== $current->available) {
            return $candidate->available;
        }

        return $candidate->price < $current->price;
    }

    /**
     * 상품 동일성을 정해 상품을 찾거나 생성. 기존 상품엔 비어 있는 분류값을 채워준다.
     *
     * 우선순위:
     *  1) 기존 listing 앵커 — bundle 안의 (샵, external_id)로 이미 오퍼가 있으면 그 상품을 재사용한다.
     *     매칭 사전(정규화)이 바뀌어 키가 달라져도 같은 listing은 같은 상품으로 유지된다.
     *     (키를 식별자로 쓰면 사전 변경 시 동일 상품이 대량 재생성되고, 옛 오퍼가 '사라짐=품절'로 오인된다.)
     *  2) 정규화 키 매칭 — 쇼핑몰 간 동일상품 묶기(신규 listing용).
     *
     * @param  array{key: string, base: string, makerCode: ?string, ipCode: ?string, dto: CrawledProductDto, offers: array<int, CrawledProductDto>}  $bundle
     * @param  array<string, int>  $categoryByCode
     */
    private function findOrCreateProduct(array $bundle, array $categoryByCode, array $ipIdByCode, array &$stats): OtakuProduct
    {
        $dto = $bundle['dto'];
        $makerCode = $bundle['makerCode'] ?? null;
        // 발매(예정)일·IP는 제목에서 파싱한다(크롤 단계가 아니라 여기서 분류).
        $releaseDate = $dto->releaseDate ?? $this->normalizer->extractReleaseDate($dto->title);
        $ipCode = $this->normalizer->extractIpCode($dto->title);
        $ipId = $ipCode !== null ? ($ipIdByCode[$ipCode] ?? null) : null;
        $categoryId = $dto->categoryCode ? ($categoryByCode[$dto->categoryCode] ?? null) : null;
        $tokens = $this->normalizer->signatureTokens($dto->title);
        $matchSig = $tokens === [] ? null : implode(' ', $tokens);

        $product = $this->resolveProductByExistingOffers($bundle['offers'])
            ?? OtakuProduct::where('ok_product_code', $bundle['key'])->first();

        // IP 충돌 가드: 앵커/키로 찾은 기존 상품이라도 IP(작품)가 둘 다 있고 서로 다르면 재사용하지 않는다.
        // 품번 번호는 제조사가 다르면 겹칠 수 있어(넨도 No.3057 미쿠 vs 니케 라피), 같은 품번이라도 다른 작품이면
        // 별도 상품으로 만든다(키가 IP 포함이라 새 키로 생성해도 유니크 충돌 없음). 단 이번 키를 이미 가진
        // 다른 상품(같은 IP로 앞서 분리 생성된 상품)이 있으면 그쪽을 재사용해 유니크 충돌을 막는다.
        if ($product !== null && self::ipConflicts($product->ok_product_ip_id, $ipId)) {
            $keyOwner = OtakuProduct::where('ok_product_code', $bundle['key'])
                ->where('ok_product_id', '!=', $product->ok_product_id)
                ->first();
            $product = ($keyOwner !== null && ! self::ipConflicts($keyOwner->ok_product_ip_id, $ipId))
                ? $keyOwner
                : null;
        }

        // 부속품 앵커 가드(자가치유): 과거 매칭 오염으로 부속품(전용 케이스 등) listing 오퍼가 본체 상품에
        // 붙어 있으면(또는 그 반대), 앵커를 버리고 별도 상품으로 분리한다. upsertOffer 가 오퍼의
        // product_id 를 재지정하므로 다음 크롤에서 오염된 기존 데이터가 자동으로 풀린다.
        if ($product !== null
            && self::looksLikeAccessory($dto->title) !== self::looksLikeAccessory((string) $product->ok_product_title)) {
            if ($product->ok_product_code === $bundle['key']) {
                // 키 드리프트로 상대 상품이 이번 번들 키를 점유 중이면, 자기 제목 기준 키로 되돌려 키를 비운다.
                // 폴백 키가 이미 점유돼 비울 수 없으면 유니크 충돌을 피해 이번 런은 현상 유지한다.
                $fallbackKey = $this->normalizer->normalizeKey(
                    (string) $product->ok_product_title,
                    $product->ok_product_brand_label,
                );
                if ($fallbackKey !== $bundle['key']
                    && ! OtakuProduct::where('ok_product_code', $fallbackKey)->exists()) {
                    $product->ok_product_code = $fallbackKey;
                    $product->save();
                    $product = null;
                }
            } else {
                // 이번 번들 키의 올바른 소유자가 따로 있으면 그쪽을 재사용, 없으면 신규 생성으로 분리.
                $product = OtakuProduct::where('ok_product_code', $bundle['key'])
                    ->where('ok_product_id', '!=', $product->ok_product_id)
                    ->first();
            }
        }

        // 정확 키로 못 묶었고 고유값(maker code)도 없으면, 같은 ip+카테고리 안에서
        // 이름 토큰이 포함관계인 기존 상품을 찾아 묶는다(보수적 이름 유사 매칭).
        // 굿즈류(클리어파일/스티커/케이스 등)는 캐릭터·번호만 다른 변형이 많아 오병합 위험이 커,
        // 이름이 충분히 변별적인 '피규어' 카테고리에만 적용한다.
        $scale = self::extractScale($dto->title);
        if ($product === null && $makerCode === null && $ipId !== null && $categoryId !== null
            && $dto->categoryCode === self::FUZZY_CATEGORY && self::looksLikeScaleFigure($dto->title)
            && count($tokens) >= self::FUZZY_MIN_SHARED) {
            $product = $this->fuzzyMatchProduct($ipId, (int) $categoryId, $tokens, $scale);
        }

        // 신규 생성 직전 유니크 방어: 가드(IP 충돌·부속품)가 기존 키 소유자 재사용을 거부한 경우
        // 같은 키로 insert 하면 유니크 충돌(SQLSTATE 23000)로 크롤이 중단된다.
        // IP id 접두 키로 구분해 생성하고, 같은 조건으로 이미 분리 생성된 상품이 있으면 재사용한다.
        $createCode = $bundle['key'];
        if ($product === null && OtakuProduct::where('ok_product_code', $createCode)->exists()) {
            $createCode = mb_substr('ip'.($ipId ?? 0).'_'.$bundle['key'], 0, 50);
            $product = OtakuProduct::where('ok_product_code', $createCode)->first();
        }

        if ($product === null) {
            $stats['products_created']++;
            $product = OtakuProduct::create([
                'ok_product_code' => $createCode,
                'ok_product_title' => $dto->title,
                'ok_product_subtitle' => $dto->subtitle,
                'ok_product_brand_label' => $dto->brandLabel,
                'ok_product_maker_code' => $makerCode,
                'ok_product_maker_name' => $dto->maker,
                'ok_product_match_sig' => $matchSig,
                'ok_product_release_date' => $releaseDate,
                'ok_product_active_flg' => true,
                'ok_product_cate_id' => $categoryId,
                'ok_product_ip_id' => $ipId,
                'ok_product_image_url' => $dto->imageUrl,
            ]);
            $this->indexProduct($product, $tokens);

            return $product;
        }

        // 기존 상품: 키가 바뀌었으면 최신 정규화 키로 갱신하고, 비어 있는 분류값을 채운다(재크롤 점진 보강).
        if ($product->ok_product_code !== $bundle['key']) {
            // 그 키(maker code 등)를 이미 다른 상품이 점유 중이면, 같은 실물 상품이 두 행으로
            // 갈라진 것이므로 덮어쓰기(유니크 키 충돌, SQLSTATE 23000) 대신 병합한다.
            // 키를 가진 쪽을 canonical 로 두고 현재 상품을 그쪽으로 합친다.
            // 단 두 상품의 IP(작품)가 둘 다 있고 서로 다르면 다른 상품이므로 병합하지 않고
            // 현재 상품의 기존 키를 유지한다(다른 작품끼리 강제 병합 방지).
            $keyOwner = OtakuProduct::where('ok_product_code', $bundle['key'])
                ->where('ok_product_id', '!=', $product->ok_product_id)
                ->first();
            if ($keyOwner !== null && ! self::ipConflicts($keyOwner->ok_product_ip_id, $product->ok_product_ip_id)) {
                $this->mergeProducts($keyOwner, [$product]);
                $product = $keyOwner;
            } elseif ($keyOwner === null) {
                $product->ok_product_code = $bundle['key'];
            }
        }
        if (! $product->ok_product_image_url && $dto->imageUrl) {
            $product->ok_product_image_url = $dto->imageUrl;
        }
        if ($product->ok_product_release_date === null && $releaseDate !== null) {
            $product->ok_product_release_date = $releaseDate;
        }
        if ($product->ok_product_ip_id === null && $ipId !== null) {
            $product->ok_product_ip_id = $ipId;
        }
        if ($product->ok_product_maker_code === null && $makerCode !== null) {
            $product->ok_product_maker_code = $makerCode;
        }
        if ($product->ok_product_maker_name === null && $dto->maker !== null) {
            $product->ok_product_maker_name = $dto->maker;
        }
        if ($product->ok_product_match_sig === null && $matchSig !== null) {
            $product->ok_product_match_sig = $matchSig;
        }
        if ($product->isDirty()) {
            $product->save();
        }
        $stats['products_matched']++;

        return $product;
    }

    /** 두 IP id가 둘 다 있고 서로 다르면 true — 다른 작품 = 다른 상품(병합/재사용 금지). */
    private static function ipConflicts(int|string|null $a, int|string|null $b): bool
    {
        return $a !== null && $b !== null && (int) $a !== (int) $b;
    }

    /** @var array<string, array<int, array{id:int, tokens:array<int,string>}>> (ip:cate) 버킷 후보 캐시 */
    private array $bucketCache = [];

    /**
     * 같은 ip+카테고리 안에서 이름 토큰이 포함관계(작은 집합 ⊆ 큰 집합)인 기존 상품을 찾는다.
     * 보수적 규칙: 양쪽 토큰 ≥ 3, 한쪽이 다른쪽에 완전 포함될 때만 동일상품으로 본다.
     * (대칭차가 가장 작은 후보를 고른다.)
     *
     * @param  array<int, string>  $tokens
     */
    public function fuzzyMatchProduct(int $ipId, int $cateId, array $tokens, ?string $scale = null): ?OtakuProduct
    {
        if (count($tokens) < self::FUZZY_MIN_SHARED) {
            return null;
        }

        $best = null;
        $bestShared = -1;
        foreach ($this->bucketCandidates($ipId, $cateId) as $cand) {
            if (! self::scalesCompatible($cand['scale'], $scale)) {  // 스케일(1/7 vs 1/6)이 다르면 다른 상품
                continue;
            }
            $ct = $cand['tokens'];
            // 버킷은 이미 같은 IP(ok_product_ip_id)로 필터돼 모든 후보가 IP를 공통으로 갖는다.
            // IP명은 변별 시그니처에서 제외되므로(별도 축, "IP 유무로 안 갈림" 이점), 그 "보장된 공통 1개"를
            // 여기서 되살려 변별 토큰은 FUZZY_MIN_SHARED-1 개만 겹쳐도 동일 상품으로 본다.
            // (이게 없으면 '각성하라' vs '각성입니다' 같은 어미차이가 공통을 3→2로 떨어뜨려 동일 상품이 미병합.)
            if (count($ct) < self::FUZZY_MIN_SHARED || ! self::tokensSimilar($tokens, $ct, self::FUZZY_MIN_SHARED - 1)) {
                continue;
            }
            // 가장 많이 겹치는(공통 토큰 최다) 후보를 동일 상품으로 선택
            [$shared] = self::tokenDiff($tokens, $ct);
            if ($shared > $bestShared) {
                $bestShared = $shared;
                $best = $cand['id'];
            }
        }

        return $best !== null ? OtakuProduct::find($best) : null;
    }

    /**
     * (ip,cate) 버킷의 후보 상품 목록(id + 토큰). 런 1회만 DB에서 로드해 캐시한다.
     *
     * @return array<int, array{id:int, tokens:array<int,string>}>
     */
    private function bucketCandidates(int $ipId, int $cateId): array
    {
        $key = $ipId.':'.$cateId;
        if (! isset($this->bucketCache[$key])) {
            $this->bucketCache[$key] = OtakuProduct::query()
                ->where('ok_product_ip_id', $ipId)
                ->where('ok_product_cate_id', $cateId)
                ->whereNull('ok_product_maker_code')
                ->get(['ok_product_id', 'ok_product_match_sig', 'ok_product_title'])
                ->filter(fn ($p) => self::looksLikeScaleFigure((string) $p->ok_product_title))
                ->map(fn ($p) => [
                    'id' => (int) $p->ok_product_id,
                    'scale' => self::extractScale((string) $p->ok_product_title),
                    'tokens' => $p->ok_product_match_sig
                        ? explode(' ', $p->ok_product_match_sig)
                        : $this->normalizer->signatureTokens((string) $p->ok_product_title),
                ])
                ->values()
                ->all();
        }

        return $this->bucketCache[$key];
    }

    /** 스케일 표기(1/7, 1/8 등)를 정규화해 반환. 없으면 null. */
    public static function extractScale(string $title): ?string
    {
        return preg_match('#(\d)\s*/\s*(\d{1,2})#u', $title, $m) ? $m[1].'/'.$m[2] : null;
    }

    /**
     * 스케일 피규어(본체)인지 — 굿즈류 오병합을 막기 위한 추가 신호.
     * 1/N 스케일이 있어야 하고, 케이스/스탠드/태피스트리 등 '부속품'은 본체 스케일을 제목에 적어도 제외한다
     * (예: "1/7스케일 … LED 디스플레이 케이스"는 피규어 본체가 아님 → 본체와 병합하면 안 됨).
     */
    public static function looksLikeScaleFigure(string $title): bool
    {
        if (self::extractScale($title) === null) {
            return false;
        }

        return ! self::looksLikeAccessory($title);
    }

    /**
     * 부속품(본체 전용 케이스/스탠드/태피스트리 등) 제목인지 — 본체와의 오병합을 막는 공용 판별.
     * 부속품 제목에는 본체 품번·스케일이 그대로 들어가는 경우가 많아, 품번 매칭·퍼지 매칭 양쪽에서
     * 이 판별로 걸러야 한다. 키워드는 config(accessory_keywords)로 관리한다.
     */
    public static function looksLikeAccessory(string $title): bool
    {
        $keywords = (array) config('otaku-crawler.product_match.accessory_keywords', [
            '케이스', '디스플레이', '아크릴', '태피스트리', '쿠션', '포스터', '스탠드', '받침', '진열',
        ]);

        foreach ($keywords as $keyword) {
            if (is_string($keyword) && $keyword !== '' && mb_stripos($title, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 부속품 제목이면 라인넘버형 품번(nendo_/figma_ 등)을 버린다(null).
     * 부속품 제목의 라인넘버는 '본체'의 품번이라(예: "넨도로이드 No.1955 … 전용 아크릴 케이스")
     * 그대로 쓰면 본체와 같은 상품으로 병합된다. 반면 jan_ 바코드는 부속품 자체의 고유값이므로 유지.
     */
    public static function sanitizeMakerCode(?string $makerCode, string $title): ?string
    {
        if ($makerCode === null || str_starts_with($makerCode, 'jan_')) {
            return $makerCode;
        }

        return self::looksLikeAccessory($title) ? null : $makerCode;
    }

    /**
     * 스케일 일치: 둘 다 스케일(1/N)이 있고 같아야 한다.
     * (제조사/프라이즈 라인명이 정규화에서 제거돼, 스케일 없는 상품끼리는 넨도/프라이즈/케이스 등을
     *  구분할 수 없어 과병합된다. 그래서 이름 유사 매칭은 '같은 스케일의 스케일 피규어'로만 제한한다.)
     */
    public static function scalesCompatible(?string $a, ?string $b): bool
    {
        return $a !== null && $b !== null && $a === $b;
    }

    /**
     * 이미지 확증 병합의 스케일 가드 — 둘 다 논스케일(null)이거나 같은 스케일이면 병합 가능.
     * scalesCompatible 과 달리 '둘 다 null'(논스케일 피규어끼리)도 호환으로 본다(이미지가 동일성을 확증).
     * 단 한쪽만 스케일이 있으면(1/7 스케일판 vs 논스케일 프라이즈) 다른 상품이므로 불호환.
     */
    public static function scalesEqual(?string $a, ?string $b): bool
    {
        return $a === $b;
    }

    /**
     * 제목에 등장하는 의상/버전 변형 키워드 집합(정렬·중복제거). 이미지 확증 병합에서 두 상품의
     * 변형 키워드 집합이 정확히 같아야(둘 다 메이드, 둘 다 없음 등) 병합 대상이 된다.
     *
     * @return array<int, string>
     */
    public static function variantKeywordSet(string $title): array
    {
        $found = [];
        foreach (self::variantKeywords() as $keyword) {
            if (mb_stripos($title, $keyword) !== false) {
                $found[$keyword] = true;
            }
        }
        $keys = array_keys($found);
        sort($keys, SORT_STRING);

        return $keys;
    }

    /** 두 제목의 의상/버전 변형 키워드 집합이 정확히 일치하는지(기본판 vs 수영복판 오병합 차단). */
    public static function variantKeywordsMatch(string $a, string $b): bool
    {
        return self::variantKeywordSet($a) === self::variantKeywordSet($b);
    }

    /**
     * 두 변별 토큰 집합이 '다른 캐릭터/구성'으로 충돌하는지 — 이미지 확증 병합의 캐릭터 가드.
     * tokensSimilar 와 달리 최소 공유 수를 요구하지 않는다(이미지가 동일성을 확증하므로 토큰은
     * '충돌만 아니면' 통과). 양쪽 모두 상대에 없는 고유 토큰이 남고 그것들이 서로 '같은 단어의 다른
     * 표기'로 화해되지 않으면(아스나 vs 시로코, 프라나 vs 호시노) 충돌 = 병합 금지.
     * 한쪽이 완전 포함(고유 0)이거나 양쪽 다 토큰이 없으면 충돌 아님.
     *
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     */
    public static function tokensConflict(array $a, array $b): bool
    {
        [, $uniqA, $uniqB] = self::tokenDiff(array_values(array_unique($a)), array_values(array_unique($b)));
        if ($uniqA === [] || $uniqB === []) {
            return false;  // 포함관계(또는 양쪽 공백) — 충돌 아님
        }

        return ! self::uniquesReconcilable($uniqA, $uniqB);
    }

    /**
     * 두 토큰 집합이 동일 상품으로 볼 만큼 유사한가.
     * 규칙: 공통 코어 ≥ FUZZY_MIN_SHARED(정확일치 + 분할결합 흡수) 이고,
     *   - 한쪽이 완전 포함(고유 토큰 0)이거나
     *   - 남는 고유 토큰들이 서로 '같은 단어의 다른 표기'(각성하라 vs 각성입니다)일 때만 동일 상품.
     * 무관한 단어(아스나 vs 시로코)면 다른 상품으로 본다.
     *
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     */
    public static function tokensSimilar(array $a, array $b, ?int $minShared = null): bool
    {
        [$shared, $uniqA, $uniqB] = self::tokenDiff(array_values(array_unique($a)), array_values(array_unique($b)));
        if ($shared < ($minShared ?? self::FUZZY_MIN_SHARED)) {
            return false;
        }
        // 의상 변형 가드: 공유로 소화되지 못한 고유 토큰에 변형 키워드(수영복/교복 등)가 남아 있으면
        // 기본판 vs 의상 변형판이므로 부분집합(포함관계)이라도 병합하지 않는다.
        // ({아리스} ⊆ {아리스, 수영복} 같은 케이스 차단. 양쪽 다 있는 키워드는 shared로 소화돼 통과.)
        if (self::hasVariantKeyword($uniqA) || self::hasVariantKeyword($uniqB)) {
            return false;
        }
        if ($uniqA === [] || $uniqB === []) {
            return true;  // 부분집합(한쪽 완전 포함)
        }

        return self::uniquesReconcilable($uniqA, $uniqB);
    }

    /**
     * 고유 토큰 목록에 의상/버전 변형 키워드가 포함돼 있는지.
     *
     * @param  array<int, string>  $uniques
     */
    private static function hasVariantKeyword(array $uniques): bool
    {
        foreach ($uniques as $token) {
            if (self::containsVariantKeyword($token)) {
                return true;
            }
        }

        return false;
    }

    /** 토큰에 변형 키워드가 부분 문자열로라도 들어 있는지("수영복ver" 같은 결합 토큰 대응). */
    private static function containsVariantKeyword(string $token): bool
    {
        foreach (self::variantKeywords() as $keyword) {
            if (mb_strpos($token, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 의상/버전 변형 키워드 목록(config). static 매칭 로직에서 쓰므로 config 헬퍼로 읽는다.
     *
     * @return array<int, string>
     */
    private static function variantKeywords(): array
    {
        return array_values(array_filter(
            (array) config('otaku-crawler.product_match.variant_keywords', []),
            fn ($keyword) => is_string($keyword) && $keyword !== '',
        ));
    }

    /**
     * 정확 일치 + 분할결합(한쪽 한 토큰 == 다른쪽 두 토큰 결합, 예: 슈퍼노바 == 슈퍼+노바)을 흡수해
     * [공통 토큰 수, A 고유, B 고유] 를 반환한다.
     *
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     * @return array{0:int, 1:array<int,string>, 2:array<int,string>}
     */
    private static function tokenDiff(array $a, array $b): array
    {
        $shared = 0;
        foreach ($a as $i => $t) {  // 1) 정확 일치
            $j = array_search($t, $b, true);
            if ($j !== false) {
                unset($a[$i], $b[$j]);
                $shared++;
            }
        }
        $a = array_values($a);
        $b = array_values($b);
        // 2) 분할 결합(양방향)
        $shared += self::consumeSplits($a, $b);
        $shared += self::consumeSplits($b, $a);

        return [$shared, array_values($a), array_values($b)];
    }

    /** $whole 의 각 토큰이 $parts 의 서로 다른 두 토큰 결합과 같으면 양쪽에서 소거하고 개수 반환(참조 수정). */
    private static function consumeSplits(array &$whole, array &$parts): int
    {
        $n = 0;
        foreach ($whole as $wi => $t) {
            $pair = self::findSplitPair($t, $parts);
            if ($pair !== null) {
                unset($whole[$wi], $parts[$pair[0]], $parts[$pair[1]]);
                $parts = array_values($parts);
                $n++;
            }
        }
        $whole = array_values($whole);

        return $n;
    }

    /** $parts 의 서로 다른 두 인덱스를 결합해 $t 가 되면 [i,j], 없으면 null. */
    private static function findSplitPair(string $t, array $parts): ?array
    {
        $c = count($parts);
        for ($i = 0; $i < $c; $i++) {
            for ($j = 0; $j < $c; $j++) {
                if ($i !== $j && $t === $parts[$i].$parts[$j]) {
                    return [$i, $j];
                }
            }
        }

        return null;
    }

    /**
     * 양쪽 고유 토큰이 서로 '같은 단어의 다른 표기'인지(각성하라 vs 각성입니다 = O, 아스나 vs 시로코 = X).
     * 각 고유 토큰이 상대 쪽 고유 토큰 중 하나와 (포함 또는 2자 이상 공통 접두)로 짝지어져야 한다.
     *
     * @param  array<int, string>  $ua
     * @param  array<int, string>  $ub
     */
    private static function uniquesReconcilable(array $ua, array $ub): bool
    {
        $hasPartner = function (string $x, array $others): bool {
            foreach ($others as $y) {
                if (self::wordVariant($x, $y)) {
                    return true;
                }
            }

            return false;
        };
        foreach ($ua as $x) {
            if (! $hasPartner($x, $ub)) {
                return false;
            }
        }
        foreach ($ub as $y) {
            if (! $hasPartner($y, $ua)) {
                return false;
            }
        }

        return true;
    }

    /** 두 단어가 같은 단어의 다른 표기인지: 동일/포함/2자 이상 공통 접두. */
    private static function wordVariant(string $x, string $y): bool
    {
        if ($x === $y) {
            return true;
        }
        if ($x !== '' && $y !== '' && (mb_strpos($x, $y) !== false || mb_strpos($y, $x) !== false)) {
            return true;
        }
        // 의상 변형 키워드는 접두 완화(2자 공통) 대상에서 제외한다 — '수영복' vs '수영부'처럼
        // 접두만 같은 서로 다른 의상이 '같은 단어의 변형'으로 짝지어지는 오결합을 막는다.
        if (self::containsVariantKeyword($x) || self::containsVariantKeyword($y)) {
            return false;
        }
        $n = min(mb_strlen($x), mb_strlen($y));
        $prefix = 0;
        for ($i = 0; $i < $n; $i++) {
            if (mb_substr($x, $i, 1) === mb_substr($y, $i, 1)) {
                $prefix++;
            } else {
                break;
            }
        }

        return $prefix >= 2;
    }

    /**
     * 이미지 dHash 확증 자동 병합 그룹을 산출한다(네트워크 미사용 — 저장된 ok_product_image_hash 만 읽음).
     *
     * 대상: 같은 IP(ok_product_ip_id) + 피규어 카테고리. 각 쌍에 대해
     *   1) 스케일 동일(scalesEqual — 논스케일끼리=둘 다 null 포함)
     *   2) 부속품 여부 일치(looksLikeAccessory)
     *   3) 의상 변형 키워드 집합 일치(variantKeywordsMatch)
     *   4) 변별 토큰 비충돌(tokensConflict — 다른 캐릭터면 이미지가 같아도 제외)
     *   5) dHash 해밍거리 ≤ $hammingMax
     * 를 모두 통과하면 union-find 로 같은 그룹으로 묶는다. 교량 상품('유즈 메이드')이 있으면
     * 제목 조각이 갈린 상품들(하나오카 유즈 / 메유즈)도 전이적으로 한 상품으로 이어진다.
     *
     * 굿즈류는 캐릭터 일러스트를 공유해 이미지가 비슷한 변형이 많아(과병합 위험) 피규어로 제한한다.
     * 이미지가 없거나 해시가 실패('')/형식 오류(16자 아님)인 상품은 병합에 참여하지 않는다.
     *
     * @return array<int, array<int, int>> 병합 그룹(각 2건 이상)
     */
    public function imageMergeGroups(int $hammingMax): array
    {
        $figureCateId = OtakuCategory::where('ok_category_code', self::FUZZY_CATEGORY)->value('ok_category_id');
        if ($figureCateId === null) {
            return [];
        }

        $groups = [];
        OtakuProduct::query()
            ->whereNotNull('ok_product_ip_id')
            ->where('ok_product_cate_id', $figureCateId)
            ->whereNotNull('ok_product_image_hash')
            ->where('ok_product_image_hash', '!=', '')
            ->orderBy('ok_product_ip_id')
            ->get(['ok_product_id', 'ok_product_ip_id', 'ok_product_image_hash', 'ok_product_title'])
            ->groupBy('ok_product_ip_id')
            ->each(function ($bucket) use ($hammingMax, &$groups) {
                foreach ($this->clusterByImage($bucket, $hammingMax) as $cluster) {
                    if (count($cluster) > 1) {
                        $groups[] = $cluster;
                    }
                }
            });

        return $groups;
    }

    /**
     * 한 IP 버킷의 피규어 상품들을 이미지 확증 가드로 union-find 클러스터링한다.
     *
     * @param  \Illuminate\Support\Collection<int, OtakuProduct>  $bucket
     * @return array<int, array<int, int>>
     */
    private function clusterByImage($bucket, int $hammingMax): array
    {
        $items = $bucket
            ->map(fn ($p) => [
                'id' => (int) $p->ok_product_id,
                'title' => (string) $p->ok_product_title,
                'scale' => self::extractScale((string) $p->ok_product_title),
                'hash' => (string) $p->ok_product_image_hash,
                'tokens' => $this->normalizer->primaryTokens((string) $p->ok_product_title),
            ])
            ->filter(fn ($it) => strlen($it['hash']) === 16)  // 실패 마커('')·형식 오류 제외
            ->values()
            ->all();

        $n = count($items);
        if ($n < 2) {
            return [];
        }

        $parent = range(0, $n - 1);
        $find = function (int $x) use (&$parent): int {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }

            return $x;
        };

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if (! self::scalesEqual($items[$i]['scale'], $items[$j]['scale'])) {
                    continue;
                }
                if (self::looksLikeAccessory($items[$i]['title']) !== self::looksLikeAccessory($items[$j]['title'])) {
                    continue;
                }
                if (! self::variantKeywordsMatch($items[$i]['title'], $items[$j]['title'])) {
                    continue;
                }
                if (self::tokensConflict($items[$i]['tokens'], $items[$j]['tokens'])) {
                    continue;
                }
                if (ImageHasher::distance($items[$i]['hash'], $items[$j]['hash']) > $hammingMax) {
                    continue;
                }
                $pi = $find($i);
                $pj = $find($j);
                if ($pi !== $pj) {
                    $parent[$pi] = $pj;
                }
            }
        }

        $clusters = [];
        for ($i = 0; $i < $n; $i++) {
            $clusters[$find($i)][] = $items[$i]['id'];
        }

        return array_values($clusters);
    }

    /** 새로/매칭된 상품을 버킷 캐시에 반영해 같은 런의 후속 상품이 이어서 묶이도록 한다. */
    private function indexProduct(OtakuProduct $product, array $tokens): void
    {
        if ($product->ok_product_ip_id === null || $product->ok_product_cate_id === null || $tokens === []) {
            return;
        }
        $key = $product->ok_product_ip_id.':'.$product->ok_product_cate_id;
        if (isset($this->bucketCache[$key])) {
            $this->bucketCache[$key][] = [
                'id' => (int) $product->ok_product_id,
                'scale' => self::extractScale((string) $product->ok_product_title),
                'tokens' => $tokens,
            ];
        }
    }

    /**
     * bundle의 (샵, external_id) 조합으로 이미 존재하는 오퍼가 있으면 그 오퍼가 가리키는 상품을 돌려준다.
     * 같은 실물 listing을 회차/매칭사전 변경과 무관하게 같은 상품으로 유지하기 위한 앵커.
     *
     * @param  array<int, CrawledProductDto>  $offers  shopId => dto
     */
    private function resolveProductByExistingOffers(array $offers): ?OtakuProduct
    {
        foreach ($offers as $shopId => $dto) {
            if ($dto->externalId === null || $dto->externalId === '') {
                continue;
            }
            $offer = OtakuOffer::where('ok_offer_shop_id', (int) $shopId)
                ->where('ok_offer_external_id', $dto->externalId)
                ->first();
            if ($offer !== null) {
                $product = OtakuProduct::find($offer->ok_offer_product_id);
                if ($product !== null) {
                    return $product;
                }
            }
        }

        return null;
    }

    /**
     * 오퍼를 upsert. 동일성은 (샵, external_id=샵 내부 상품ID) 기준이다.
     * external_id는 매칭 사전 변경과 무관하게 회차 간 불변이라, 같은 listing이 새 오퍼로 중복
     * 생성되거나 '사라짐=품절'로 오인되지 않는다. 매칭으로 상품 키가 바뀌면 product_id를 재지정한다.
     */
    private function upsertOffer(OtakuProduct $product, int $shopId, CrawledProductDto $dto, Carbon $now, array &$stats): void
    {
        $offerData = [
            'ok_offer_product_id' => $product->ok_product_id,
            'ok_offer_shop_id' => $shopId,
            'ok_offer_external_id' => $dto->externalId,
            'ok_offer_currency' => $dto->currency,
            'ok_offer_price' => $dto->price,
            // 현지가: KRW 샵이라 판매가와 동일. 배송비는 리스트에 없으면 null(상세 보강 시 채워짐).
            'ok_offer_local_price' => $dto->price,
            'ok_offer_shipping_fee' => $dto->shippingFee,
            'ok_offer_available_flg' => $dto->available,
            'ok_offer_external_url' => $dto->productUrl,
            'ok_offer_collected_dt' => $now,
        ];

        $offer = OtakuOffer::where('ok_offer_shop_id', $shopId)
            ->where('ok_offer_external_id', $dto->externalId)
            ->first();

        $this->detectRestock($product, $offer, $dto);

        if ($offer !== null) {
            $offer->update($offerData);
            $stats['offers_updated']++;
        } else {
            OtakuOffer::create($offerData);
            $stats['offers_created']++;
        }
    }

    /**
     * 품절→구매가능 전환 감지(찜 재입고 알림용). 오퍼가 새로 재고 있음이 되는 시점에,
     * 상품에 다른 구매가능 오퍼가 하나도 없었다면 "상품 재입고"로 본다.
     * 찜이 없으면 감지 자체를 생략하고, 방금 생성된 상품(찜 불가능)도 건너뛴다.
     */
    private function detectRestock(OtakuProduct $product, ?OtakuOffer $offer, CrawledProductDto $dto): void
    {
        $becameAvailable = $dto->available && ($offer === null || ! $offer->ok_offer_available_flg);
        if (! $becameAvailable || $product->wasRecentlyCreated) {
            return;
        }

        $this->anyWishExists ??= OtakuWish::exists();
        if (! $this->anyWishExists) {
            return;
        }

        $hadStock = OtakuOffer::where('ok_offer_product_id', $product->ok_product_id)
            ->where('ok_offer_available_flg', true)
            ->when($offer !== null, fn ($q) => $q->where('ok_offer_id', '!=', $offer->ok_offer_id))
            ->exists();

        if (! $hadStock) {
            $this->restockedProductIds[(int) $product->ok_product_id] = true;
        }
    }

    /**
     * 전량 크롤 마무리: 이번 회차에 다시 수집되지 않은 오퍼를 품절 처리한다.
     *
     * 일부 쇼핑몰(예: 애니메이트/godo)은 품절 상품을 리스트에서 숨겨, 품절을 마크업으로
     * 읽는 대신 "사라짐"으로 판단해야 한다. 이번 크롤에서 본 오퍼는 collected_dt 가 갱신되므로
     * (run 시작 시각 이후), 그보다 오래된 오퍼 = 이번에 못 본 오퍼 = 품절로 간주한다.
     * 오퍼 동일성이 (샵, external_id)로 안정적이라, 키가 바뀌어도 같은 listing은 collected_dt가
     * 갱신되어 오인 품절되지 않는다.
     *
     * 주의: 카테고리 전체를 도는 전량 크롤(crawl-full)에서만 안전하다. 일부 카테고리만 도는
     * 일반/증분 크롤에서는 "안 봤다 ≠ 사라졌다"이므로 호출하지 않는다. 또 크롤이 1건도 못 한
     * 쇼핑몰(Selenium 실패 등)은 대상에서 제외해 전체 품절 오인을 막는다.
     *
     * @param  array<int, string>  $crawledShopCodes  이번에 실제로 상품을 수집한 샵 코드들
     * @return int 품절로 전환된 오퍼 수
     */
    public function markUnseenOffersUnavailable(array $crawledShopCodes, Carbon $runStartedAt): int
    {
        $shopIds = OtakuShop::whereIn('ok_shop_code', array_values(array_unique($crawledShopCodes)))
            ->pluck('ok_shop_id')->all();
        if ($shopIds === []) {
            return 0;
        }

        $affected = OtakuOffer::query()
            ->whereIn('ok_offer_shop_id', $shopIds)
            ->where('ok_offer_available_flg', true)
            ->where(function ($q) use ($runStartedAt) {
                $q->where('ok_offer_collected_dt', '<', $runStartedAt)
                    ->orWhereNull('ok_offer_collected_dt');
            })
            ->update(['ok_offer_available_flg' => false, 'update_dt' => Carbon::now()]);

        if ($affected > 0) {
            $this->updateLowestPriceFlags();
        }

        return $affected;
    }

    /**
     * 중복으로 적재된 상품들을 canonical 하나로 병합한다(재매칭 소급 적용).
     * 나머지 상품의 오퍼를 canonical로 옮기고, 샵당 오퍼가 중복되면 최선(재고>가격) 1건만 남긴다.
     * 비어 있는 분류값은 canonical로 채운다. 최저가 플래그 재계산은 호출 측에서 일괄 수행한다.
     *
     * @param  array<int, OtakuProduct>  $others
     */
    public function mergeProducts(OtakuProduct $canonical, array $others): void
    {
        foreach ($others as $other) {
            if ((int) $other->ok_product_id === (int) $canonical->ok_product_id) {
                continue;
            }

            OtakuOffer::where('ok_offer_product_id', $other->ok_product_id)
                ->update(['ok_offer_product_id' => $canonical->ok_product_id]);

            $this->fillMissingClassification($canonical, $other);
            $other->delete();
        }

        $this->dedupeOffersPerShop($canonical);

        if ($canonical->isDirty()) {
            $canonical->save();
        }
    }

    /** 최저가 플래그 재계산 (재매칭 커맨드에서 일괄 호출용). */
    public function refreshLowestPriceFlags(): void
    {
        $this->updateLowestPriceFlags();
    }

    /** canonical 상품의 비어 있는 분류값을 병합 대상에서 채운다. */
    private function fillMissingClassification(OtakuProduct $canonical, OtakuProduct $other): void
    {
        foreach ([
            'ok_product_ip_id', 'ok_product_cate_id', 'ok_product_release_date',
            'ok_product_maker_code', 'ok_product_maker_name', 'ok_product_image_url',
            'ok_product_match_sig',
        ] as $col) {
            if ($canonical->{$col} === null && $other->{$col} !== null) {
                $canonical->{$col} = $other->{$col};
            }
        }
    }

    /** 한 상품에 같은 샵 오퍼가 여러 건이면 최선(재고>가격) 1건만 남기고 삭제한다. */
    private function dedupeOffersPerShop(OtakuProduct $product): void
    {
        $byShop = OtakuOffer::where('ok_offer_product_id', $product->ok_product_id)
            ->get()
            ->groupBy('ok_offer_shop_id');

        foreach ($byShop as $shopOffers) {
            if ($shopOffers->count() < 2) {
                continue;
            }
            $best = $shopOffers->sort(function ($a, $b) {
                if ($a->ok_offer_available_flg !== $b->ok_offer_available_flg) {
                    return $b->ok_offer_available_flg <=> $a->ok_offer_available_flg;  // 재고 있는 쪽 우선
                }

                return $a->ok_offer_price <=> $b->ok_offer_price;  // 더 싼 쪽 우선
            })->first();

            foreach ($shopOffers as $offer) {
                if ((int) $offer->ok_offer_id !== (int) $best->ok_offer_id) {
                    $offer->delete();
                }
            }
        }
    }

    /**
     * 상품별 최저가 플래그 갱신.
     */
    private function updateLowestPriceFlags(): void
    {
        OtakuOffer::query()->update(['ok_offer_lowest_flg' => false]);
        $minPrices = OtakuOffer::query()
            ->where('ok_offer_available_flg', true)
            ->selectRaw('ok_offer_product_id, MIN(ok_offer_price) as min_price')
            ->groupBy('ok_offer_product_id')
            ->pluck('min_price', 'ok_offer_product_id');

        foreach ($minPrices as $productId => $minPrice) {
            OtakuOffer::where('ok_offer_product_id', $productId)
                ->where('ok_offer_price', $minPrice)
                ->update(['ok_offer_lowest_flg' => true]);
        }
    }
}
