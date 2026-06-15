<?php

namespace App\Services\MiniGame;

use App\Models\MiniGame\Game;
use Illuminate\Database\Eloquent\Collection;

class GameService
{
    /**
     * 게임 목록 조회
     */
    public function getGames(): Collection
    {
        return Game::all();
    }

    /**
     * 게임 점수 저장
     */
    public function saveScore(string $gameName, int $score): Game
    {
        return Game::create([
            'name' => $gameName,
            'score' => $score,
            'status' => 'completed',
        ]);
    }

    /**
     * 최고 점수 조회
     */
    public function getHighScore(?string $gameName = null): int
    {
        $query = Game::query();

        if ($gameName) {
            $query->where('name', $gameName);
        }

        return (int) ($query->max('score') ?? 0);
    }

    /**
     * 게임 랭킹 조회
     */
    public function getRankings(?string $gameName = null, int $limit = 10): Collection
    {
        $query = Game::query()
            ->orderBy('score', 'desc')
            ->limit($limit);

        if ($gameName) {
            $query->where('name', $gameName);
        }

        return $query->get();
    }
}
