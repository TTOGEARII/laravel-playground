<?php

namespace App\Console\Commands\OtakuShop;

use App\Models\OtakuShop\OtakuProduct;
use App\Services\OtakuShop\Crawler\CrawlSyncService;
use Illuminate\Console\Command;

/**
 * 지정한 상품들을 하나로 수동 병합한다.
 *
 * 자동 매칭(정규화 키·품번·퍼지·이미지 dHash)으로는 못 묶는 하드 케이스 —
 * 쇼핑몰이 서로 다른 상품 사진을 쓰고(이미지 해밍거리가 커 이미지 병합 불가) 제목도
 * 조각나(단일 캐릭터명·성 붙음·축약) 안전한 신호가 없는 동일 상품 — 을 사람이 확인 후 병합할 때 쓴다.
 *
 *   otaku-shop:merge 45835 106315            # 두 상품을 하나로
 *   otaku-shop:merge 134173 135542 135300    # 셋 이상도 가능
 *   otaku-shop:merge 45835 106315 --dry-run  # 대상만 확인
 *
 * canonical(남길 상품)은 오퍼가 가장 많은(동률이면 id 작은) 상품으로 자동 선택하고,
 * 나머지 상품의 오퍼를 canonical 로 옮긴 뒤 나머지 상품을 삭제한다(rematch 병합과 동일 로직).
 */
class OtakuShopMergeCommand extends Command
{
    protected $signature = 'otaku-shop:merge
                            {ids* : 하나로 병합할 상품 ID(2개 이상)}
                            {--dry-run : 실제 병합 없이 대상만 출력}';

    protected $description = '지정한 상품들을 하나로 수동 병합(자동 매칭 불가 케이스용)';

    public function handle(CrawlSyncService $sync): int
    {
        $ids = array_values(array_unique(array_map('intval', (array) $this->argument('ids'))));
        if (count($ids) < 2) {
            $this->error('병합하려면 상품 ID 가 2개 이상 필요합니다.');

            return self::INVALID;
        }

        $products = OtakuProduct::whereIn('ok_product_id', $ids)->withCount('offers')->get();
        $missing = array_diff($ids, $products->pluck('ok_product_id')->all());
        if ($missing !== []) {
            $this->error('존재하지 않는 상품 ID: '.implode(', ', $missing));

            return self::FAILURE;
        }

        // 서로 다른 작품(IP)이 섞이면 오병합 위험이 크므로 경고한다(강행은 가능 — 사람 판단 전제).
        $ips = $products->pluck('ok_product_ip_id')->filter()->unique();
        if ($ips->count() > 1) {
            $this->warn('경고: 서로 다른 IP(작품)의 상품이 섞여 있습니다. 정말 같은 상품인지 확인하세요.');
        }

        $canonical = $products->sortBy([['offers_count', 'desc'], ['ok_product_id', 'asc']])->first();
        $others = $products->reject(fn ($p) => $p->ok_product_id === $canonical->ok_product_id)->values();

        $this->info('병합 대상:');
        $this->line('  ✓ 남김 #'.$canonical->ok_product_id.' (오퍼 '.$canonical->offers_count.') | '.mb_substr((string) $canonical->ok_product_title, 0, 60));
        foreach ($others as $p) {
            $this->line('   → 흡수 #'.$p->ok_product_id.' (오퍼 '.$p->offers_count.') | '.mb_substr((string) $p->ok_product_title, 0, 60));
        }

        if ($this->option('dry-run')) {
            $this->warn('[dry-run] 실제 병합하지 않았습니다.');

            return self::SUCCESS;
        }

        $sync->mergeProducts($canonical, $others->all());
        $sync->refreshLowestPriceFlags();

        $this->info('완료: '.$others->count().'개 상품을 #'.$canonical->ok_product_id.' 로 병합했습니다.');

        return self::SUCCESS;
    }
}
