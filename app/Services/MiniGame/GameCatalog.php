<?php

namespace App\Services\MiniGame;

/**
 * 미니게임 목록의 단일 출처(Single Source of Truth).
 *
 * 앞으로 내가 만드는 게임은 여기 한 항목만 추가하면 목록·랭킹에 자동 반영된다.
 * 'external' => true 인 게임(외부에서 가져온 것, 예: DOOM)은 랭킹 대상에서 제외된다.
 */
class GameCatalog
{
    /**
     * @return array<int, array{key:string,name:string,description:string,icon:string,color:string,tags:array<int,string>,route:string,external:bool}>
     */
    public function all(): array
    {
        return [
            [
                'key' => 'vampire-survival',
                'name' => '뱀파이어 서바이벌',
                'description' => '마지막 까지 살아남아보거라',
                'icon' => '🧛',
                'color' => 'accent-indigo',
                'tags' => ['서바이벌', '액션', '도전'],
                'route' => 'mini-game.vampire-survival.index',
                'external' => false,
            ],
            [
                'key' => 'tetris',
                'name' => '테트리스',
                'description' => '블록을 쌓아 줄을 지우고 점수를 쌓아라. 홀드·티스핀까지!',
                'icon' => '🟦',
                'color' => 'accent-teal',
                'tags' => ['퍼즐', '고전', '중독성'],
                'route' => 'mini-game.tetris.index',
                'external' => false,
            ],
            [
                'key' => 'doom',
                'name' => 'DOOM',
                'description' => 'WebAssembly로 실행되는 오리지널 DOOM (셰어웨어 에피소드 1)',
                'icon' => '🔫',
                'color' => 'accent-pink',
                'tags' => ['FPS', '고전', 'WASM'],
                'route' => 'mini-game.doom.index',
                'external' => true, // 외부 게임 → 랭킹 제외
            ],
        ];
    }

    /**
     * 랭킹 대상 게임(외부 게임 제외).
     *
     * @return array<int, array{key:string,name:string,description:string,icon:string,color:string,tags:array<int,string>,route:string,external:bool}>
     */
    public function rankable(): array
    {
        return array_values(array_filter($this->all(), fn ($game) => ! $game['external']));
    }

    /** 랭킹 대상 게임 key 목록. */
    public function rankableKeys(): array
    {
        return array_map(fn ($game) => $game['key'], $this->rankable());
    }

    /** key 로 게임 1건 조회 (없으면 null). */
    public function find(string $key): ?array
    {
        foreach ($this->all() as $game) {
            if ($game['key'] === $key) {
                return $game;
            }
        }

        return null;
    }

    public function isRankable(string $key): bool
    {
        $game = $this->find($key);

        return $game !== null && ! $game['external'];
    }
}
