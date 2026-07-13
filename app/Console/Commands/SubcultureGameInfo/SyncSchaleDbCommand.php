<?php

namespace App\Console\Commands\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Game;
use App\Services\SubcultureGameInfo\CodeSyncService;
use App\Services\SubcultureGameInfo\SchaleDbSyncService;
use Illuminate\Console\Command;

class SyncSchaleDbCommand extends Command
{
    protected $signature = 'subculture:sync-schaledb
        {--game= : 특정 게임 슬러그만 동기화(현재 bluearchive)}';

    protected $description = 'SchaleDB(정적 JSON)에서 학정보(도감)·모집중 학생(배너)·진행중/미래시 이벤트를 동기화';

    public function handle(CodeSyncService $codeSync, SchaleDbSyncService $sync): int
    {
        $codeSync->ensureGames();

        $slugs = (array) config('subculture-game-info.raids.schaledb.games', []);
        if ($this->option('game') !== null) {
            $slugs = array_values(array_intersect($slugs, [$this->option('game')]));
            if ($slugs === []) {
                $this->error("SchaleDB 대상 게임이 아닙니다: {$this->option('game')}");

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

            $this->info("[{$slug}] SchaleDB 동기화 중...");
            $stats = $sync->sync($game);
            $rows[] = [$slug, $stats['students'], $stats['banners'], $stats['events']];
        }

        $this->table(['게임', '학생', '배너', '이벤트'], $rows);
        $this->info('완료.');

        return self::SUCCESS;
    }
}
