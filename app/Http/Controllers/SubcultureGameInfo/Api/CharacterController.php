<?php

namespace App\Http\Controllers\SubcultureGameInfo\Api;

use App\Http\Controllers\Controller;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\WikiEntry;
use App\Services\SubcultureGameInfo\Raids\RaidQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class CharacterController extends Controller
{
    public function __construct(private RaidQueryService $query) {}

    /**
     * 게임별 캐릭터 마스터 목록 + 성장도 입력 스키마.
     * 쿼리: game(slug, 필수)
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['game' => ['required', 'string']]);

        $game = Game::where('slug', $request->query('game'))->firstOrFail();
        $data = $this->attachWikiEntries($game, $this->query->listCharacters($game->id));

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $data->count(),
                'growth_schema' => config("subculture-game-info.raids.growth_fields.{$game->slug}", []),
                // 학정보(도감) 렌더 스키마 — StudentDex 가 이 정의대로 traits 필드를 동적 표시
                'student_schema' => config("subculture-game-info.raids.student_schema.{$game->slug}", []),
            ],
        ]);
    }

    /**
     * 호요랩 위키 캐릭터 항목을 이름으로 매칭해 wiki_entry_id 를 붙인다(도감 모달의 스킬·이야기 상세용).
     * 정확 일치 → 정규화(공백·중점 제거) 일치 → 포함 유일 매칭(위키는 풀네임: '엘렌 조' ⊃ '엘렌') 순.
     */
    private function attachWikiEntries(Game $game, Collection $data): Collection
    {
        $menu = config("subculture-game-info.raids.hoyowiki.apps.{$game->slug}.character_menu");
        if ($menu === null) {
            return $data;
        }

        $entries = WikiEntry::forGame($game->id)->where('menu_key', (string) $menu)->get(['id', 'name']);
        if ($entries->isEmpty()) {
            return $data;
        }

        $norm = fn (string $s) => mb_strtolower(preg_replace('/[\s\x{00A0}·•\-_.「」]+/u', '', $s));
        $byNorm = $entries->keyBy(fn (WikiEntry $e) => $norm($e->name));

        return $data->map(function (array $c) use ($byNorm, $entries, $norm) {
            $n = $norm($c['name']);
            $hit = $n !== '' ? $byNorm->get($n) : null;
            if ($hit === null && $n !== '') {
                $candidates = $entries->filter(fn (WikiEntry $e) => str_contains($norm($e->name), $n));
                $hit = $candidates->count() === 1 ? $candidates->first() : null;
            }
            $c['wiki_entry_id'] = $hit?->id;

            return $c;
        });
    }
}
