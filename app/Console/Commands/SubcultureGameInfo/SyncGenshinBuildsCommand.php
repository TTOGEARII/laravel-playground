<?php

namespace App\Console\Commands\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Game;
use App\Services\SubcultureGameInfo\GenshinBuildsSyncService;
use Illuminate\Console\Command;

class SyncGenshinBuildsCommand extends Command
{
    protected $signature = 'subculture:sync-genshin-builds {--game= : 특정 게임만(genshin/starrail/zenless)}';

    protected $description = 'genshin-builds.com(한국어)에서 호요버스 추천 무기·세트를 캐릭터에 추가';

    public function handle(GenshinBuildsSyncService $sync): int
    {
        $targets = array_keys((array) config('subculture-game-info.raids.genshin_builds.games', []));
        if ($this->option('game') !== null) {
            $targets = array_values(array_intersect($targets, [$this->option('game')]));
            if ($targets === []) {
                $this->error("빌드 수집 대상이 아닙니다: {$this->option('game')}");

                return self::FAILURE;
            }
        }

        $rows = [];
        foreach ($targets as $slug) {
            $game = Game::where('slug', $slug)->first();
            if ($game === null) {
                $this->warn("게임 없음(스킵): {$slug}");

                continue;
            }
            $this->info("[{$slug}] genshin-builds 추천 무기·세트 수집 중... (캐릭터별 페이지 — 수 분 걸릴 수 있음)");
            $stats = $sync->sync($game);
            $rows[] = [$slug, $stats['characters'], $stats['weapons'], $stats['sets']];
        }

        $this->table(['게임', '캐릭터', '추천무기', '추천세트'], $rows);
        $this->info('완료.');

        return self::SUCCESS;
    }
}
