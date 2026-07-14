<?php

namespace App\Console\Commands\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Game;
use App\Services\SubcultureGameInfo\HoyoBuildSyncService;
use Illuminate\Console\Command;

class SyncHoyoBuildCommand extends Command
{
    protected $signature = 'subculture:sync-hoyo-build {--game= : 특정 게임만(zenless/starrail/genshin)}';

    protected $description = '호요버스 캐릭터 빌드 보강 — 티어(젠존제)·추천 조합 영상(유튜브)을 캐릭터에 추가';

    public function handle(HoyoBuildSyncService $sync): int
    {
        $targets = array_keys((array) config('subculture-game-info.raids.hoyo_build.comps', []));
        if ($this->option('game') !== null) {
            $targets = array_values(array_intersect($targets, [$this->option('game')]));
            if ($targets === []) {
                $this->error("호요버스 빌드 대상이 아닙니다: {$this->option('game')}");

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
            $this->info("[{$slug}] 빌드 보강 중... (조합 유튜브 검색 포함 — 수 분 걸릴 수 있음)");
            $stats = $sync->sync($game);
            $rows[] = [$slug, $stats['characters'], $stats['tiers'], $stats['materials'], $stats['comps']];
        }

        $this->table(['게임', '캐릭터', '티어', '재료', '조합영상'], $rows);
        $this->info('완료.');

        return self::SUCCESS;
    }
}
