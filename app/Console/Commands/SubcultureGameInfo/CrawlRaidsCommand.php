<?php

namespace App\Console\Commands\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Game;
use App\Services\SubcultureGameInfo\CodeSyncService;
use App\Services\SubcultureGameInfo\Raids\CrawlerScriptRunner;
use App\Services\SubcultureGameInfo\Raids\EliminationPartyService;
use App\Services\SubcultureGameInfo\Raids\RaidSyncService;
use Illuminate\Console\Command;

class CrawlRaidsCommand extends Command
{
    protected $signature = 'subculture:crawl-raids
        {--game= : 특정 게임 슬러그만 크롤(bluearchive/nikke/trickcal/browndust2)}';

    protected $description = '서드파티 사이트에서 게임별 레이드 일정·추천 편성을 Playwright 사이드카로 크롤·동기화';

    public function handle(CodeSyncService $codeSync, CrawlerScriptRunner $runner, RaidSyncService $sync, EliminationPartyService $elimination): int
    {
        $codeSync->ensureGames();

        $slugs = config('subculture-game-info.raids.games', []);
        if ($this->option('game') !== null) {
            $slugs = array_intersect($slugs, [$this->option('game')]);
            if ($slugs === []) {
                $this->error("레이드 대상 게임이 아닙니다: {$this->option('game')}");

                return self::FAILURE;
            }
        }

        $rows = [];
        foreach ($slugs as $slug) {
            $game = Game::where('slug', $slug)->first();
            if ($game === null) {
                $this->warn("게임 없음(스킵): {$slug}");

                continue;
            }

            $this->info("[{$slug}] 레이드 크롤 중...");
            $result = $runner->run($slug, 'raids');
            if ($result === null || $result['items'] === []) {
                $this->warn("[{$slug}] 수집 결과 없음 — 동기화 스킵");
                $rows[] = [$slug, '-', '-', '-', '-'];

                continue;
            }

            $stats = $sync->sync($game, $result['source'], $result['items']);
            $rows[] = [$slug, $stats['raids'], $stats['parties'], $stats['members'], $stats['missing_members']];

            // 블아 대결전: 장갑 3종별 편성이 필요해 baql 랭킹으로 장갑별 편성으로 갈아끼운다
            if ($slug === 'bluearchive') {
                $n = $elimination->sync($game);
                if ($n > 0) {
                    $this->line("  ↳ 대결전 {$n}건 — 장갑 타입별 편성으로 보정");
                }
            }
        }

        $this->table(['게임', '레이드', '편성', '멤버', '미매칭'], $rows);
        $this->info('완료.');

        return self::SUCCESS;
    }
}
