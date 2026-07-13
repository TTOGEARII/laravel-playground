<?php

namespace App\Console\Commands\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Game;
use App\Services\SubcultureGameInfo\CodeSyncService;
use App\Services\SubcultureGameInfo\EnkaZzzSyncService;
use Illuminate\Console\Command;

class SyncZenlessCommand extends Command
{
    protected $signature = 'subculture:sync-zenless';

    protected $description = '젠레스 존 제로 캐릭터(에이전트) 도감(학정보)을 Enka 스토어에서 동기화';

    public function handle(CodeSyncService $codeSync, EnkaZzzSyncService $sync): int
    {
        $codeSync->ensureGames();

        $slugs = array_keys((array) config('subculture-game-info.raids.enka.games', []));
        $rows = [];
        foreach ($slugs as $slug) {
            $game = Game::where('slug', $slug)->first();
            if ($game === null) {
                $this->warn("게임 없음(스킵): {$slug}");

                continue;
            }

            $this->info("[{$slug}] Enka 동기화 중...");
            $rows[] = [$slug, $sync->sync($game)];
        }

        $this->table(['게임', '에이전트'], $rows);
        $this->info('완료.');

        return self::SUCCESS;
    }
}
