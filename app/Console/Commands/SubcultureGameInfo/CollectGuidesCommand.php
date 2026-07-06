<?php

namespace App\Console\Commands\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Game;
use App\Services\SubcultureGameInfo\CodeSyncService;
use App\Services\SubcultureGameInfo\Raids\GuidePostCollectorService;
use Illuminate\Console\Command;

class CollectGuidesCommand extends Command
{
    protected $signature = 'subculture:collect-guides
        {--game= : 특정 게임 슬러그만 수집(bluearchive/nikke/trickcal/browndust2)}';

    protected $description = '디씨 개념글·아카 추천글에서 게임별 공략글 메타(제목/링크/작성일/조회수)를 수집';

    public function handle(CodeSyncService $codeSync, GuidePostCollectorService $collector): int
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

            $this->info("[{$slug}] 공략글 수집 중...");
            $stats = $collector->collect($game);
            $rows[] = [$slug, $stats['collected'], $stats['created'], $stats['updated'], $stats['matched'], $stats['pruned']];
        }

        $this->table(['게임', '수집', '신규', '갱신', '레이드매칭', '정리'], $rows);
        $this->info('완료.');

        return self::SUCCESS;
    }
}
