<?php

namespace App\Console\Commands\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\RedeemCode;
use App\Services\Push\WebPushService;
use App\Services\SubcultureGameInfo\CodeCollectorService;
use Illuminate\Console\Command;

class CollectCodesCommand extends Command
{
    protected $signature = 'subculture:collect
        {--no-community : 커뮤니티(디씨 등 보조 신호) 소스를 제외하고 메인 소스만 수집}
        {--no-verify : 커뮤니티 검색 교차검증 단계를 건너뛴다(빠른 수집)}
        {--no-push : 신규 코드 웹푸시 알림을 보내지 않는다(백필·테스트용)}';

    protected $description = '서브컬쳐 게임 리딤코드를 소스(호요버스 API/정리 사이트/커뮤니티)에서 수집·동기화';

    public function handle(CodeCollectorService $collector, WebPushService $push): int
    {
        $includeCommunity = ! $this->option('no-community');
        $verify = ! $this->option('no-verify');
        $this->info('리딤코드 수집 시작'
            .($includeCommunity ? ' (커뮤니티 포함)' : ' (메인 소스만)')
            .($verify ? ' · 검색 교차검증 ON' : ' · 검색 교차검증 OFF'));

        $startedAt = now();
        $stats = $collector->collect($includeCommunity, $verify);

        $this->table(
            ['수집(raw)', '신규', '갱신', '스킵', '교차검증', '검색만료', '만료정리', '삭제'],
            [[
                $stats['collected'], $stats['created'], $stats['updated'], $stats['skipped'],
                $stats['corroborated'], $stats['search_expired'], $stats['expired'], $stats['pruned'],
            ]]
        );

        // 신규 코드가 생겼으면 구독자에게 웹푸시 — 새 코드는 정의상 아직 안 쓴 코드다.
        if ($stats['created'] > 0 && ! $this->option('no-push')) {
            $this->notifyNewCodes($push, $startedAt);
        }

        $this->info('완료.');

        return self::SUCCESS;
    }

    /** 이번 실행에서 생성된 코드를 게임별로 집계해 알림 문구를 만든다. */
    private function notifyNewCodes(WebPushService $push, \Carbon\CarbonInterface $startedAt): void
    {
        $byGame = RedeemCode::query()
            ->where('created_at', '>=', $startedAt)
            ->with('game:id,name')
            ->get()
            ->groupBy(fn (RedeemCode $code) => $code->game?->name ?? '기타')
            ->map(fn ($codes) => $codes->count());

        if ($byGame->isEmpty()) {
            return;
        }

        $total = $byGame->sum();
        $summary = $byGame->map(fn (int $count, string $name) => "{$name} {$count}")->implode(' · ');

        $result = $push->broadcast(
            "새 리딤코드 {$total}개 등록",
            $summary.' — 지금 받으러 가기',
            '/subculture-game-info/codes',
        );
        $this->info("웹푸시: 발송 {$result['sent']} · 정리 {$result['pruned']} · 실패 {$result['failed']}");
    }
}
