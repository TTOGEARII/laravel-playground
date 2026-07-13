<?php

namespace App\Console\Commands\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Game;
use App\Services\SubcultureGameInfo\CodeSyncService;
use App\Services\SubcultureGameInfo\YattaSyncService;
use Illuminate\Console\Command;

class SyncYattaCommand extends Command
{
    protected $signature = 'subculture:sync-yatta
        {--game= : 특정 게임 슬러그만 동기화(genshin/starrail)}';

    protected $description = 'Project Yatta(호요버스)에서 캐릭터 도감(학정보)을 동기화 — 원신·스타레일';

    public function handle(CodeSyncService $codeSync, YattaSyncService $sync): int
    {
        $codeSync->ensureGames();

        $slugs = array_keys((array) config('subculture-game-info.raids.yatta.games', []));
        if ($this->option('game') !== null) {
            $slugs = array_values(array_intersect($slugs, [$this->option('game')]));
            if ($slugs === []) {
                $this->error("Yatta 대상 게임이 아닙니다: {$this->option('game')}");

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

            $this->info("[{$slug}] Yatta 동기화 중...");
            $rows[] = [$slug, $sync->sync($game)];
        }

        $this->table(['게임', '캐릭터'], $rows);
        $this->info('완료.');

        return self::SUCCESS;
    }
}
