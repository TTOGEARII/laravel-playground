<?php

namespace App\Console\Commands\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Game;
use App\Services\SubcultureGameInfo\CodeSyncService;
use App\Services\SubcultureGameInfo\Raids\CharacterSyncService;
use App\Services\SubcultureGameInfo\Raids\CrawlerScriptRunner;
use Illuminate\Console\Command;

class CrawlCharactersCommand extends Command
{
    protected $signature = 'subculture:crawl-characters
        {--game= : 특정 게임 슬러그만 크롤(bluearchive/nikke/trickcal/browndust2)}';

    protected $description = '서드파티 사이트에서 게임별 캐릭터 마스터를 Playwright 사이드카로 크롤·동기화';

    public function handle(CodeSyncService $codeSync, CrawlerScriptRunner $runner, CharacterSyncService $sync): int
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

            $this->info("[{$slug}] 캐릭터 크롤 중...");
            $result = $runner->run($slug, 'characters');
            if ($result === null || $result['items'] === []) {
                $this->warn("[{$slug}] 수집 결과 없음 — 동기화 스킵");
                $rows[] = [$slug, 0, '-', '-', '-', '-'];

                continue;
            }

            $stats = $sync->sync($game, $result['source'], $result['items']);
            $rows[] = [$slug, count($result['items']), $stats['created'], $stats['updated'], $stats['deactivated'], $stats['skipped']];
        }

        $this->table(['게임', '수집', '신규', '갱신', '비활성', '스킵'], $rows);
        $this->info('완료.');

        return self::SUCCESS;
    }
}
