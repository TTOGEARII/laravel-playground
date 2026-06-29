<?php

namespace App\Console\Commands\SubcultureGameInfo;

use App\Services\SubcultureGameInfo\CodeCollectorService;
use Illuminate\Console\Command;

class CollectCodesCommand extends Command
{
    protected $signature = 'subculture:collect
        {--no-community : 커뮤니티(디씨 등 보조 신호) 소스를 제외하고 메인 소스만 수집}';

    protected $description = '서브컬쳐 게임 리딤코드를 소스(호요버스 API/정리 사이트/커뮤니티)에서 수집·동기화';

    public function handle(CodeCollectorService $collector): int
    {
        $includeCommunity = ! $this->option('no-community');
        $this->info('리딤코드 수집 시작'.($includeCommunity ? ' (커뮤니티 포함)' : ' (메인 소스만)'));

        $stats = $collector->collect($includeCommunity);

        $this->table(
            ['수집(raw)', '신규', '갱신', '스킵'],
            [[$stats['collected'], $stats['created'], $stats['updated'], $stats['skipped']]]
        );
        $this->info('완료.');

        return self::SUCCESS;
    }
}
