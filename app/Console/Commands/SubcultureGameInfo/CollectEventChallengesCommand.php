<?php

namespace App\Console\Commands\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Game;
use App\Services\SubcultureGameInfo\Raids\EventChallengeCollectorService;
use Illuminate\Console\Command;

class CollectEventChallengesCommand extends Command
{
    protected $signature = 'subculture:collect-event-challenges
        {--game= : 특정 게임 slug 만 수집 (기본: config event_challenges.games 전체)}';

    protected $description = '이벤트 챌린지 공략 수집 — 아카 올인원 글에서 챌린지 조합·영상 파싱 (블아)';

    public function handle(EventChallengeCollectorService $collector): int
    {
        $slugs = $this->option('game')
            ? [(string) $this->option('game')]
            : (array) config('subculture-game-info.raids.event_challenges.games', []);

        $rows = [];
        foreach (Game::whereIn('slug', $slugs)->get() as $game) {
            $stats = $collector->collect($game);
            $rows[] = [$game->slug, $stats['event'] ?? '(못 찾음)', $stats['stages'], $stats['pruned']];
        }

        $this->table(['게임', '이벤트', '스테이지', '정리'], $rows);
        $this->info('완료.');

        return self::SUCCESS;
    }
}
