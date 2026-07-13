<?php

namespace App\Console\Commands\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Game;
use App\Services\SubcultureGameInfo\CodeSyncService;
use App\Services\SubcultureGameInfo\HoyowikiSyncService;
use App\Services\SubcultureGameInfo\WutheringGgSyncService;
use Illuminate\Console\Command;

class SyncWikiCommand extends Command
{
    protected $signature = 'subculture:sync-wiki
        {--game= : 특정 게임 슬러그만(zenless/starrail/wuthering)}';

    protected $description = '게임 위키 동기화 — 호요랩 위키(젠존제·스타레일 전 카테고리) + wuthering.gg(명조 캐릭터·무기), 항목별 상세 포함';

    public function handle(CodeSyncService $codeSync, HoyowikiSyncService $hoyowiki, WutheringGgSyncService $wuthering): int
    {
        $codeSync->ensureGames();

        $targets = array_merge(
            array_keys((array) config('subculture-game-info.raids.hoyowiki.apps', [])),
            ['wuthering'],
        );
        if ($this->option('game') !== null) {
            $targets = array_values(array_intersect($targets, [$this->option('game')]));
            if ($targets === []) {
                $this->error("위키 동기화 대상이 아닙니다: {$this->option('game')}");

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

            $this->info("[{$slug}] 위키 동기화 중... (항목별 상세 포함 — 수 분 걸릴 수 있음)");
            if ($slug === 'wuthering') {
                $stats = $wuthering->sync($game);
                $rows[] = [$slug, '캐릭터 '.$stats['characters'], '무기 '.$stats['weapons'], '-'];
            } else {
                $stats = $hoyowiki->sync($game);
                $rows[] = [$slug, '메뉴 '.$stats['menus'], '항목 '.$stats['entries'], '신규상세 '.$stats['details']];
            }
        }

        $this->table(['게임', '수집1', '수집2', '수집3'], $rows);
        $this->info('완료.');

        return self::SUCCESS;
    }
}
