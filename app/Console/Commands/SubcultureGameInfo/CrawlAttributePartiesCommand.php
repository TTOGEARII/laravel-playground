<?php

namespace App\Console\Commands\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Game;
use App\Services\SubcultureGameInfo\CodeSyncService;
use App\Services\SubcultureGameInfo\Raids\AttributePartyService;
use App\Services\SubcultureGameInfo\Raids\CrawlerScriptRunner;
use Illuminate\Console\Command;

/**
 * 속성(성격)별 추천 조합 수집 — 팀 매니저(큐레이션) + 트릭컬 레코드(시즌 실측).
 * Gemini(토큰) 없이 사이드카 크롤만으로 동작한다.
 */
class CrawlAttributePartiesCommand extends Command
{
    protected $signature = 'subculture:crawl-attribute-parties {--game= : 특정 게임 슬러그만 처리(기본: config attribute_parties.games)}';

    protected $description = '속성(성격)별 추천 조합 크롤·동기화 (트릭컬: 팀 매니저 + 트릭컬 레코드)';

    public function handle(CodeSyncService $codeSync, CrawlerScriptRunner $runner, AttributePartyService $service): int
    {
        $codeSync->ensureGames();

        $slugs = (array) config('subculture-game-info.raids.attribute_parties.games', []);
        if ($this->option('game') !== null) {
            $slugs = array_intersect($slugs, [$this->option('game')]);
            if ($slugs === []) {
                $this->error("속성 조합 대상 게임이 아닙니다: {$this->option('game')}");

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

            $this->info("[{$slug}] 속성별 추천 조합 크롤 중...");
            $result = $runner->run($slug, 'attribute-parties');
            if ($result === null) {
                $rows[] = [$slug, '-', '-', '크롤 실패(기존 데이터 보존)'];

                continue;
            }

            $stats = $service->sync($game, $result['items']);
            $rows[] = [$slug, $stats['parties'], $stats['members'], implode(', ', array_slice($stats['missing'], 0, 5))];
        }

        $this->table(['게임', '조합', '멤버', '미매칭 이름'], $rows);
        $this->info('완료.');

        return self::SUCCESS;
    }
}
