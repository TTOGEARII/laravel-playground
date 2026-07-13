<?php

namespace App\Console\Commands\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Game;
use App\Services\SubcultureGameInfo\CodeSyncService;
use App\Services\SubcultureGameInfo\MollulogFuturesSyncService;
use Illuminate\Console\Command;

class SyncMollulogFuturesCommand extends Command
{
    protected $signature = 'subculture:sync-mollulog-futures';

    protected $description = '몰루로그 미래시(/futures)에서 블아 KR 픽업·이벤트·레이드 일정을 동기화';

    public function handle(CodeSyncService $codeSync, MollulogFuturesSyncService $sync): int
    {
        $codeSync->ensureGames();

        $slugs = (array) config('subculture-game-info.raids.mollulog_futures.games', []);
        $rows = [];
        foreach ($slugs as $slug) {
            $game = Game::where('slug', $slug)->first();
            if ($game === null) {
                $this->warn("게임 없음(스킵): {$slug}");

                continue;
            }

            $this->info("[{$slug}] 몰루로그 미래시 동기화 중...");
            $stats = $sync->sync($game);
            $rows[] = [$slug, $stats['banners'], $stats['events'], $stats['raids']];
        }

        $this->table(['게임', '배너', '이벤트', '레이드'], $rows);
        $this->info('완료.');

        return self::SUCCESS;
    }
}
