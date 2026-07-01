<?php

namespace App\Services\MiniGame;

use App\Models\MiniGame\GameScore;

/**
 * 미니게임 점수/랭킹 도메인 로직.
 * 게임 목록은 GameCatalog 가 관리하고, 여기서는 점수 저장과 랭킹 조회를 담당한다.
 */
class GameService
{
    /** 랭킹 상위 노출 개수 기본값. */
    private const TOP_LIMIT = 10;

    public function __construct(
        private GameCatalog $catalog,
    ) {}

    /**
     * 점수를 저장하고 저장 결과 + 해당 게임 랭킹을 돌려준다.
     *
     * @return array{score_id:int, rank:int, nickname:string, score:int, rankings:array<int,array{id:int,rank:int,nickname:string,score:int}>}
     */
    public function submitScore(string $gameKey, string $nickname, int $score, ?int $userId): array
    {
        $entry = GameScore::create([
            'game_key' => $gameKey,
            'user_id' => $userId,
            'nickname' => $nickname,
            'score' => $score,
        ]);

        return [
            'score_id' => $entry->id,
            'rank' => $this->rankOf($gameKey, $score),
            'nickname' => $nickname,
            'score' => $score,
            'rankings' => $this->topRankings($gameKey),
        ];
    }

    /**
     * 같은 게임에서 이 점수의 순위(공동순위: 더 높은 점수 개수 + 1).
     */
    public function rankOf(string $gameKey, int $score): int
    {
        return GameScore::where('game_key', $gameKey)
            ->where('score', '>', $score)
            ->count() + 1;
    }

    /**
     * 게임별 상위 랭킹.
     *
     * @return array<int, array{id:int, rank:int, nickname:string, score:int}>
     */
    public function topRankings(string $gameKey, int $limit = self::TOP_LIMIT): array
    {
        return GameScore::where('game_key', $gameKey)
            ->orderByDesc('score')
            ->orderBy('id') // 동점이면 먼저 기록한 사람이 위로
            ->limit($limit)
            ->get(['id', 'nickname', 'score'])
            ->values()
            ->map(fn ($row, $i) => [
                'id' => $row->id,
                'rank' => $i + 1,
                'nickname' => $row->nickname,
                'score' => (int) $row->score,
            ])
            ->all();
    }

    /**
     * 홈 팝업용 — 랭킹 대상 전체 게임의 상위 랭킹 묶음.
     *
     * @return array<int, array{key:string, name:string, icon:string, rankings:array<int,array{id:int,rank:int,nickname:string,score:int}>}>
     */
    public function allRankings(int $limit = self::TOP_LIMIT): array
    {
        return array_map(fn ($game) => [
            'key' => $game['key'],
            'name' => $game['name'],
            'icon' => $game['icon'],
            'rankings' => $this->topRankings($game['key'], $limit),
        ], $this->catalog->rankable());
    }
}
