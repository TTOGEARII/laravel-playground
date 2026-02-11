<?php

namespace App\Services\MiniGame;

use App\Models\MiniGame\Game;
use Illuminate\Support\Facades\DB;

class GameService
{
    /**
     * 게임 목록 조회
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getGames()
    {
        return Game::all();
    }

    /**
     * 게임 점수 저장
     *
     * @param string $gameName
     * @param int $score
     * @return Game
     */
    public function saveScore(string $gameName, int $score)
    {
        return Game::create([
            'name' => $gameName,
            'score' => $score,
            'status' => 'completed',
        ]);
    }

    /**
     * 최고 점수 조회
     *
     * @param string|null $gameName
     * @return int
     */
    public function getHighScore(?string $gameName = null)
    {
        $query = Game::query();

        if ($gameName) {
            $query->where('name', $gameName);
        }

        return $query->max('score') ?? 0;
    }

    /**
     * 게임 랭킹 조회
     *
     * @param string|null $gameName
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getRankings(?string $gameName = null, int $limit = 10)
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
