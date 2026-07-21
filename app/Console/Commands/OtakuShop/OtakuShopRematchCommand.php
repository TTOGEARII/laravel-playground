<?php

namespace App\Console\Commands\OtakuShop;

use App\Models\OtakuShop\OtakuCategory;
use App\Models\OtakuShop\OtakuIp;
use App\Models\OtakuShop\OtakuOffer;
use App\Models\OtakuShop\OtakuProduct;
use App\Services\OtakuShop\Crawler\CrawlSyncService;
use App\Services\OtakuShop\Crawler\ProductNormalizer;
use Illuminate\Console\Command;

/**
 * 기존 적재 상품을 재분류(고유값/시그니처/IP/발매일)한 뒤, 같은 고유값 또는 같은 ip+카테고리
 * 안에서 이름 토큰이 포함관계인 상품들을 하나로 병합한다(가격비교 소급 적용).
 *
 *   --dry-run : 병합하지 않고 그룹/건수만 출력
 *   --force   : 이미 값이 있는 IP/발매일도 다시 분류해 덮어쓴다
 */
class OtakuShopRematchCommand extends Command
{
    protected $signature = 'otaku-shop:rematch
                            {--dry-run : 실제 병합/삭제 없이 대상 건수만 출력}
                            {--force : IP/발매일이 이미 있어도 다시 분류해 덮어쓴다}
                            {--cleanup-excluded : exclude_title_keywords(잔금/예약금결제 등)에 걸리는 기존 상품·오퍼만 삭제하고 종료}';

    protected $description = '기존 상품을 재분류·재매칭해 동일상품을 병합(가격비교 소급 적용)';

    public function handle(CrawlSyncService $sync, ProductNormalizer $normalizer, \App\Services\OtakuShop\Crawler\ImageHasher $imageHasher): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        // 정리 전용 모드: 제외 키워드(분할결제 등) 상품만 삭제하고 종료(무거운 재분류 생략).
        if ($this->option('cleanup-excluded')) {
            return $this->cleanupExcluded($sync, $dryRun);
        }

        // config 에 새로 추가된 IP(작품)를 otaku_ip 에 먼저 등록해야 재분류에서 ip_id 가 붙는다.
        // (rematch 는 크롤과 달리 syncIps 를 안 거쳐, 새 IP 별칭이 반영되지 않던 것을 보강)
        $sync->syncIps();

        $this->info('1. 재분류(고유값·시그니처·IP·발매일)...');
        $ipIdByCode = OtakuIp::pluck('ok_ip_id', 'ok_ip_code')->all();
        $this->reclassify($normalizer, $ipIdByCode, $force);

        $this->info('2. 제목 기반 병합 그룹 산출...');
        $groups = array_merge(
            $this->makerCodeGroups(),
            $this->sigEqualityGroups(),
            $this->containmentGroups(),
        );
        $mergeCount = array_sum(array_map(fn ($g) => count($g) - 1, $groups));
        $this->line('  제목 기반 병합 그룹: '.count($groups).'개 · 사라질 중복 상품: '.$mergeCount.'개');

        if ($dryRun) {
            $this->previewGroups($groups);
            // 이미지 확증 병합 후보도 미리보기(해시는 캐시 계산하되 실제 병합은 하지 않음).
            $this->imageMergePass($sync, $imageHasher, dryRun: true);
            $this->warn('[dry-run] 실제 병합은 수행하지 않았습니다.');

            return self::SUCCESS;
        }

        $this->info('3. 제목 기반 병합 실행...');
        $this->mergeGroups($sync, $groups);

        // 4. 이미지 dHash 확증 자동 병합 — 제목이 갈려 안 묶인 동일 피규어(논스케일/단일 토큰)를 회수한다.
        //    제목 병합 후 남은 상품 집합에 대해 수행(중복 처리 방지).
        $this->info('4. 이미지 확증 자동 병합...');
        $this->imageMergePass($sync, $imageHasher, dryRun: false);

        $this->info('5. 최저가 플래그 재계산...');
        $sync->refreshLowestPriceFlags();

        $this->info("완료: 제목 {$mergeCount}개 + 이미지 병합으로 중복 상품을 정리했습니다.");

        return self::SUCCESS;
    }

    /**
     * 병합 그룹을 실제로 병합한다(오퍼 최다 상품을 canonical 로).
     *
     * @param  array<int, array<int, int>>  $groups
     */
    private function mergeGroups(CrawlSyncService $sync, array $groups): void
    {
        $bar = $this->output->createProgressBar(count($groups));
        $bar->start();
        foreach ($groups as $ids) {
            $products = OtakuProduct::whereIn('ok_product_id', $ids)->withCount('offers')->get();
            if ($products->count() < 2) {
                $bar->advance();

                continue;
            }
            $canonical = $products->sortBy([['offers_count', 'desc'], ['ok_product_id', 'asc']])->first();
            $others = $products->reject(fn ($p) => $p->ok_product_id === $canonical->ok_product_id)->all();
            $sync->mergeProducts($canonical, array_values($others));
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);
    }

    /**
     * config(exclude_title_keywords)에 걸리는 기존 상품과 그 오퍼를 삭제한다.
     * (분할결제 전용 listing 등은 실제 상품가가 아니라 비교 대상이 아니므로 소급 제거.)
     * 크롤은 앞으로 이런 제목을 수집하지 않지만, 이미 적재된 것은 이 명령으로 정리한다.
     */
    private function cleanupExcluded(CrawlSyncService $sync, bool $dryRun): int
    {
        $keywords = array_values(array_filter(
            (array) config('otaku-crawler.exclude_title_keywords', []),
            fn ($kw) => is_string($kw) && $kw !== '',
        ));

        if ($keywords === []) {
            $this->warn('exclude_title_keywords 가 비어 있어 정리할 대상이 없습니다.');

            return self::SUCCESS;
        }

        $this->info('제외 키워드 상품 정리: "'.implode('", "', $keywords).'"');

        $matched = OtakuProduct::query()->where(function ($query) use ($keywords) {
            foreach ($keywords as $keyword) {
                $query->orWhere('ok_product_title', 'like', '%'.$keyword.'%');
            }
        });

        $ids = $matched->pluck('ok_product_id');
        $this->line('  대상 상품: '.$ids->count().'건');

        if ($ids->isEmpty()) {
            return self::SUCCESS;
        }

        if ($dryRun) {
            OtakuProduct::whereIn('ok_product_id', $ids)
                ->limit(15)->pluck('ok_product_title')
                ->each(fn ($title) => $this->line('   - '.mb_substr((string) $title, 0, 64)));
            if ($ids->count() > 15) {
                $this->line('   ... 외 '.($ids->count() - 15).'건');
            }
            $this->warn('[dry-run] 삭제하지 않았습니다.');

            return self::SUCCESS;
        }

        $deletedOffers = OtakuOffer::whereIn('ok_offer_product_id', $ids)->delete();
        $deletedProducts = OtakuProduct::whereIn('ok_product_id', $ids)->delete();
        $this->line("  삭제: 오퍼 {$deletedOffers}개 · 상품 {$deletedProducts}개");

        $this->info('최저가 플래그 재계산...');
        $sync->refreshLowestPriceFlags();

        $this->info('완료: 제외 키워드 상품을 정리했습니다.');

        return self::SUCCESS;
    }

    /**
     * 전 상품의 고유값/시그니처를 다시 계산하고(고유값은 새로 뽑히면 갱신),
     * IP/발매일은 비어 있으면(또는 --force) 채운다.
     *
     * @param  array<string, int>  $ipIdByCode
     */
    private function reclassify(ProductNormalizer $normalizer, array $ipIdByCode, bool $force): void
    {
        $total = OtakuProduct::count();
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        OtakuProduct::query()->chunkById(1000, function ($products) use ($normalizer, $ipIdByCode, $force, $bar) {
            foreach ($products as $product) {
                $title = (string) $product->ok_product_title;

                // 부속품(전용 케이스 등) 제목의 라인넘버형 품번은 본체 품번이라 버린다(sanitizeMakerCode).
                // → 아래 elseif 에서 기존 비-JAN 코드도 함께 제거돼 makerCodeGroups 병합 대상에서 빠진다(소급).
                $maker = CrawlSyncService::sanitizeMakerCode($normalizer->extractMakerCode($title), $title);
                if ($maker !== null) {
                    $product->ok_product_maker_code = $maker;  // 개선된 정규식으로 새로 뽑히면 갱신
                } elseif ($product->ok_product_maker_code !== null && ! str_starts_with($product->ok_product_maker_code, 'jan_')) {
                    // 제목에서 더는 안 뽑히는(또는 부속품이라 버려진) 비-JAN 코드는 오탐 → 제거.
                    // JAN은 상세 크롤에서 오므로 제목에 없어도 유지한다.
                    $product->ok_product_maker_code = null;
                }
                $product->ok_product_match_sig = implode(' ', $normalizer->signatureTokens($title)) ?: null;

                if ($force || $product->ok_product_release_date === null) {
                    $date = $normalizer->extractReleaseDate($title);
                    if ($date !== null) {
                        $product->ok_product_release_date = $date;
                    }
                }
                if ($force || $product->ok_product_ip_id === null) {
                    $code = $normalizer->extractIpCode($title);
                    $ipId = $code !== null ? ($ipIdByCode[$code] ?? null) : null;
                    if ($ipId !== null) {
                        $product->ok_product_ip_id = $ipId;
                    }
                }

                if ($product->isDirty()) {
                    $product->save();
                }
                $bar->advance();
            }
        }, 'ok_product_id');

        $bar->finish();
        $this->newLine(2);
    }

    /**
     * 같은 고유값(maker_code)을 가진 상품 그룹(2건 이상).
     * 품번 번호는 제조사가 다르면 겹칠 수 있어(예: 넨도 No.3057 미쿠 vs 니케 라피),
     * 같은 품번 그룹이라도 IP(작품)가 다르면 별도 그룹으로 분할해 오병합을 막는다.
     *
     * @return array<int, array<int, int>>
     */
    private function makerCodeGroups(): array
    {
        $byMakerCode = OtakuProduct::query()
            ->whereNotNull('ok_product_maker_code')
            ->get(['ok_product_id', 'ok_product_maker_code', 'ok_product_ip_id'])
            ->groupBy('ok_product_maker_code');

        $groups = [];
        foreach ($byMakerCode as $products) {
            if ($products->count() < 2) {
                continue;
            }
            foreach ($this->splitByIp($products) as $ids) {
                if (count($ids) > 1) {
                    $groups[] = $ids;
                }
            }
        }

        return $groups;
    }

    /**
     * 같은 품번 그룹을 IP(작품)별로 분할한다.
     * non-null IP가 1종 이하면 기존처럼 전부 한 그룹(IP 미추출 행 포함 — 분리 회귀 방지),
     * 2종 이상이면 IP별로 나누고 IP 미추출(null) 행은 어느 작품인지 알 수 없어 병합에서 제외한다.
     *
     * @param  \Illuminate\Support\Collection<int, OtakuProduct>  $products
     * @return array<int, array<int, int>>
     */
    private function splitByIp($products): array
    {
        $byIp = [];
        $nullIpIds = [];
        foreach ($products as $product) {
            if ($product->ok_product_ip_id === null) {
                $nullIpIds[] = (int) $product->ok_product_id;
            } else {
                $byIp[(int) $product->ok_product_ip_id][] = (int) $product->ok_product_id;
            }
        }

        if (count($byIp) <= 1) {
            $onlyIpIds = $byIp === [] ? [] : reset($byIp);

            return [array_merge($onlyIpIds, $nullIpIds)];
        }

        return array_values($byIp);
    }

    /** 이미지 dHash 확증 자동 병합: 해밍 거리 허용치(64bit 중 다른 비트 수). 리포트 시절(6)보다 타이트하게. */
    private const IMAGE_AUTO_MERGE_HAMMING_MAX = 5;

    /** 회당 신규 이미지 해시 다운로드 상한(장시간 실행 방지 — 해시는 저장돼 다음 회차가 이어간다). */
    private const IMAGE_HASH_BUDGET = 3000;

    /**
     * 이미지 dHash 확증 자동 병합 패스 — 제목이 갈려 안 묶인 동일 피규어(논스케일/단일 토큰)를 회수한다.
     *  1) 같은 IP 피규어 버킷에 2건 이상인, 해시 미계산 상품의 dHash 를 지연 계산(예산 내, 실패는 '' 마커).
     *  2) CrawlSyncService::imageMergeGroups 가 스케일·부속품·변형·캐릭터 가드 + 해밍거리로 병합 그룹 산출.
     *  3) dry-run 이면 미리보기만, 아니면 실제 병합.
     * 이미지 없거나 해시 실패면 병합하지 않는다(제목 폴백 유지). 이미지 불일치는 분리 증거로 쓰지 않는다.
     */
    private function imageMergePass(CrawlSyncService $sync, \App\Services\OtakuShop\Crawler\ImageHasher $hasher, bool $dryRun): void
    {
        $hashed = $this->hashFigureProductsForMerge($hasher);
        $groups = $sync->imageMergeGroups(self::IMAGE_AUTO_MERGE_HAMMING_MAX);
        $mergeCount = array_sum(array_map(fn ($g) => count($g) - 1, $groups));
        $this->line('  이미지 병합 그룹: '.count($groups).'개 · 신규 해시 '.$hashed.'개 · 사라질 중복 상품: '.$mergeCount.'개');

        if ($dryRun) {
            $this->previewGroups($groups);

            return;
        }

        $this->mergeGroups($sync, $groups);
    }

    /**
     * 이미지 병합 후보 피규어의 dHash 를 지연 계산해 저장한다(유일한 네트워크 구간).
     * 같은 IP 버킷에 2건 이상(=쌍이 될 수 있는) 피규어 중 아직 해시가 없는 것만 예산 내에서 다운로드한다.
     * 해시는 영속되므로 예산을 넘긴 나머지는 다음 회차가 이어서 계산한다(점진 커버리지).
     *
     * @return int 이번 회차에 새로 계산한 해시 수
     */
    private function hashFigureProductsForMerge(\App\Services\OtakuShop\Crawler\ImageHasher $hasher): int
    {
        $figureCateId = OtakuCategory::where('ok_category_code', 'figure')->value('ok_category_id');
        if ($figureCateId === null) {
            return 0;
        }

        $rows = OtakuProduct::query()
            ->whereNotNull('ok_product_ip_id')
            ->where('ok_product_cate_id', $figureCateId)
            ->whereNotNull('ok_product_image_url')
            ->where('ok_product_image_url', '!=', '')
            ->get(['ok_product_id', 'ok_product_ip_id', 'ok_product_image_url', 'ok_product_image_hash']);

        $countByIp = $rows->groupBy('ok_product_ip_id')->map->count();
        // 이미 해시가 채워진(거의 완성된) IP 버킷을 먼저 처리한다. 그러지 않으면 product_id 순 예산
        // 소진으로 최근 추가된 고ID 상품이 매 회차 뒤로 밀려 영영 미계산 → 짝(같은 상품)이 있어도
        // 병합되지 못한다. 완성 임박 버킷을 먼저 끝내 잔량이 남지 않게 한다.
        $hashedByIp = $rows
            ->filter(fn ($p) => $p->ok_product_image_hash !== null && $p->ok_product_image_hash !== '')
            ->groupBy('ok_product_ip_id')->map->count();
        $needHash = $rows->filter(fn ($p) => $p->ok_product_image_hash === null
            && ($countByIp[$p->ok_product_ip_id] ?? 0) >= 2)
            ->sortByDesc(fn ($p) => $hashedByIp[$p->ok_product_ip_id] ?? 0)
            ->values();

        $hashed = 0;
        foreach ($needHash as $product) {
            if ($hashed >= self::IMAGE_HASH_BUDGET) {
                break;
            }
            $hash = $hasher->hashFromUrl((string) $product->ok_product_image_url);
            $product->ok_product_image_hash = $hash ?? '';  // 실패는 '' 로 저장해 다음 회차 재시도 안 함
            $product->save();
            $hashed++;
        }

        return $hashed;
    }

    /**
     * 시그니처가 완전히 동일한 상품 병합 — 카테고리 무관(같은 상품이 샵마다 다른 카테고리로
     * 보정돼 갈라지는 사례 대응). 동일성이 정렬 토큰 전체 일치라 오병합 위험이 매우 낮고,
     * IP(작품)가 서로 다르게 판정된 행은 묶지 않는다.
     *
     * @return array<int, array<int, int>>
     */
    private function sigEqualityGroups(): array
    {
        $groups = [];

        OtakuProduct::query()
            ->whereNotNull('ok_product_match_sig')
            ->where('ok_product_match_sig', '!=', '')
            ->get(['ok_product_id', 'ok_product_ip_id', 'ok_product_match_sig', 'ok_product_title'])
            // 세트 식별자("세트 D" / "세트 2.0 & 2.1")·변별 번호(아크릴스탠드 "03"/"04", "5탄" 등)는
            // 시그니처에서 탈락(1글자·단독 숫자)한다 — 다르면 다른 구성/회차이므로 그룹 키에 포함해 분리한다.
            ->groupBy(fn (OtakuProduct $p) => ($p->ok_product_ip_id ?? 'x').'|'.$p->ok_product_match_sig
                .'|'.self::setVariantOf((string) $p->ok_product_title)
                .'|'.self::numberVariantOf((string) $p->ok_product_title))
            ->each(function ($products) use (&$groups) {
                if ($products->count() > 1) {
                    $groups[] = $products->pluck('ok_product_id')->map(fn ($id) => (int) $id)->all();
                }
            });

        return $groups;
    }

    /** 제목에서 세트 식별자("세트 D", "세트 2.0 & 2.1")를 뽑는다. 없으면 빈 문자열. */
    private static function setVariantOf(string $title): string
    {
        if (preg_match('/세트\s*([a-z0-9][a-z0-9.&\s]{0,12})/iu', $title, $m)) {
            return mb_strtolower(preg_replace('/\s+/', '', $m[1]) ?? '');
        }

        return '';
    }

    /**
     * 제목에서 변별 번호 집합(정렬)을 뽑는다 — 시그니처에서 빠지는 단독 숫자가 상품을 가르는 경우 대비.
     * (예: 아크릴스탠드 "03" vs "04", "5탄" — 다른 회차/장면인데 시그니처가 같아지는 것을 막는다.)
     * 발매정보 말머리(【】·[]·())와 스케일(1/7)은 상품 구분이 아니라 제거한 뒤 남는 숫자만 본다.
     */
    private static function numberVariantOf(string $title): string
    {
        $t = mb_strtolower($title);
        $t = preg_replace(['/【.*?】/u', '/\[.*?\]/u', '/\(.*?\)/u'], ' ', $t) ?? $t;
        $t = preg_replace('#\d+\s*/\s*\d+#', ' ', $t) ?? $t; // 스케일 1/7·1/4 제거
        preg_match_all('/\b\d{1,4}\b/u', $t, $m);
        $nums = array_values(array_unique($m[0] ?? []));
        sort($nums, SORT_STRING);

        return implode('.', $nums);
    }

    /**
     * maker_code 없는 상품을 (ip,카테고리) 버킷 안에서 포함관계로 묶은 그룹(2건 이상).
     * 큰(구체적인) 상품을 시드로 두고, 작은 상품이 시드의 부분집합(토큰 차이 ≤ 2)이면 그 시드에 합류시킨다.
     * 서로 부분집합이 아닌 두 시드는 합쳐지지 않아 다른 상품의 오병합을 막는다.
     *
     * @return array<int, array<int, int>>
     */
    private function containmentGroups(): array
    {
        // 굿즈류 오병합을 막기 위해 이름이 변별적인 '피규어' 카테고리에만 적용.
        $figureCateId = OtakuCategory::where('ok_category_code', 'figure')->value('ok_category_id');
        if ($figureCateId === null) {
            return [];
        }

        $groups = [];

        OtakuProduct::query()
            ->whereNull('ok_product_maker_code')
            ->whereNotNull('ok_product_ip_id')
            ->where('ok_product_cate_id', $figureCateId)
            ->whereNotNull('ok_product_match_sig')
            ->orderBy('ok_product_ip_id')
            ->orderBy('ok_product_cate_id')
            ->select(['ok_product_id', 'ok_product_ip_id', 'ok_product_cate_id', 'ok_product_match_sig', 'ok_product_title'])
            ->chunk(2000, function ($rows) use (&$groups) {
                // 스케일 없는 상품(넨도/프라이즈/케이스 등)은 제조사·라인명이 제거돼 구분이 안 돼 과병합되므로,
                // 이름 유사 매칭은 스케일 피규어(1/N)에만 적용한다.
                $rows = $rows->filter(fn ($p) => CrawlSyncService::looksLikeScaleFigure((string) $p->ok_product_title));
                $byBucket = $rows->groupBy(fn ($p) => $p->ok_product_ip_id.':'.$p->ok_product_cate_id);
                foreach ($byBucket as $bucket) {
                    foreach ($this->clusterBySimilarity($bucket) as $cluster) {
                        if (count($cluster) > 1) {
                            $groups[] = $cluster;
                        }
                    }
                }
            });

        return $groups;
    }

    /**
     * 한 (ip,카테고리) 버킷을 이름 유사도로 전이적 클러스터링한다(union-find).
     * A~B, A~C 가 유사하면 B~C 직접 유사가 아니어도 한 그룹({A,B,C})으로 묶인다.
     * 유사도 판정(공통코어·분할결합·같은단어 변형·스케일 호환)은 CrawlSyncService 로직을 공유한다.
     *
     * @param  \Illuminate\Support\Collection<int, OtakuProduct>  $bucket
     * @return array<int, array<int, int>>
     */
    private function clusterBySimilarity($bucket): array
    {
        $items = $bucket
            ->map(fn ($p) => [
                'id' => (int) $p->ok_product_id,
                'scale' => CrawlSyncService::extractScale((string) $p->ok_product_title),
                'tokens' => explode(' ', (string) $p->ok_product_match_sig),
            ])
            ->filter(fn ($it) => count($it['tokens']) >= CrawlSyncService::FUZZY_MIN_SHARED)
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
                if (! CrawlSyncService::scalesCompatible($items[$i]['scale'], $items[$j]['scale'])) {
                    continue;
                }
                if (! CrawlSyncService::tokensSimilar($items[$i]['tokens'], $items[$j]['tokens'])) {
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

    /**
     * dry-run 시 그룹 일부를 사람이 확인할 수 있게 출력.
     *
     * @param  array<int, array<int, int>>  $groups
     */
    private function previewGroups(array $groups): void
    {
        foreach (array_slice($groups, 0, 15) as $ids) {
            $titles = OtakuProduct::whereIn('ok_product_id', $ids)
                ->pluck('ok_product_title')->map(fn ($t) => '   - '.mb_substr($t, 0, 60))->implode("\n");
            $this->line('  ['.count($ids).'건]');
            $this->line($titles);
        }
        if (count($groups) > 15) {
            $this->line('  ... 외 '.(count($groups) - 15).'개 그룹');
        }
    }
}
