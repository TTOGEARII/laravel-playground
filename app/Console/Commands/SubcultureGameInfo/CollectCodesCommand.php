<?php

namespace App\Console\Commands\SubcultureGameInfo;

use App\Services\SubcultureGameInfo\CodeCollectorService;
use Illuminate\Console\Command;

class CollectCodesCommand extends Command
{
    protected $signature = 'subculture:collect
        {--no-community : 커뮤니티(디씨 등 보조 신호) 소스를 제외하고 메인 소스만 수집}
        {--no-verify : 커뮤니티 검색 교차검증 단계를 건너뛴다(빠른 수집)}';

    protected $description = '서브컬쳐 게임 리딤코드를 소스(호요버스 API/정리 사이트/커뮤니티)에서 수집·동기화';

    public function handle(CodeCollectorService $collector): int
    {
        $includeCommunity = ! $this->option('no-community');
        $verify = ! $this->option('no-verify');
        $this->info('리딤코드 수집 시작'
            .($includeCommunity ? ' (커뮤니티 포함)' : ' (메인 소스만)')
            .($verify ? ' · 검색 교차검증 ON' : ' · 검색 교차검증 OFF'));

        $stats = $collector->collect($includeCommunity, $verify);

        $this->table(
            ['수집(raw)', '신규', '갱신', '스킵', '교차검증', '검색만료', '만료정리', '삭제'],
            [[
                $stats['collected'], $stats['created'], $stats['updated'], $stats['skipped'],
                $stats['corroborated'], $stats['search_expired'], $stats['expired'], $stats['pruned'],
            ]]
        );
        $this->info('완료.');

        return self::SUCCESS;
    }
}
