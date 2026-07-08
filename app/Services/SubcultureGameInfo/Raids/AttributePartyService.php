<?php

namespace App\Services\SubcultureGameInfo\Raids;

use App\Models\SubcultureGameInfo\AttributeParty;
use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Game;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 속성(성격)별 추천 조합 — 사이드카 수집물(JSON)을 DB 에 동기화하고 API 응답을 조립한다.
 * Gemini(토큰) 없이 동작. curated: 팀 매니저의 성격별 추천 사도(포지션+사이드).
 * (트릭컬 레코드 시즌 실측은 crawlRaids 의 레이드 정보로 분리 — 여기서 다루지 않는다)
 */
class AttributePartyService
{
    /** 화면·정렬용 포지션 순서 */
    private const POSITIONS = ['front', 'middle', 'back'];

    /**
     * @param  array<int, array>  $items  사이드카 items(kind: curated|usage)
     * @return array{parties: int, members: int, missing: list<string>}
     */
    public function sync(Game $game, array $items): array
    {
        $characters = Character::query()
            ->where('subculture_game_id', $game->id)
            ->active()
            ->get(['id', 'name', 'traits']);
        $nameIndex = $characters->keyBy(fn (Character $c) => $this->nameKey($c->name));
        $resolve = function (string $name) use ($nameIndex): ?Character {
            $exact = $nameIndex->get($this->nameKey($name));
            if ($exact !== null) {
                return $exact;
            }
            // 성격 전환형 표기 폴백: "우로스(광기)" → "우로스" (마스터는 단일 행)
            $base = preg_replace('/\((우울|활발|순수|냉정|광기)\)\s*$/u', '', $name);
            if ($base !== $name) {
                return $nameIndex->get($this->nameKey($base));
            }
            // 접두 폴백: "시온" → "시온 더 다크불릿" (마스터가 풀네임일 때, 유일 매칭만 인정)
            $prefix = $nameIndex->filter(fn ($c, $key) => str_starts_with($key, $this->nameKey($name)));

            return $prefix->count() === 1 ? $prefix->first() : null;
        };

        $attributes = array_keys((array) config("subculture-game-info.raids.attribute_parties.attributes.{$game->slug}", []));

        $stats = ['parties' => 0, 'members' => 0, 'missing' => []];
        $rows = []; // [party 속성, member 목록] 튜플

        foreach ($items as $sort => $item) {
            if (! is_array($item)) {
                continue;
            }

            if (($item['kind'] ?? null) === 'curated') {
                $members = [];
                foreach ((array) ($item['members'] ?? []) as $i => $member) {
                    $character = $resolve((string) ($member['name'] ?? ''));
                    if ($character === null) {
                        $stats['missing'][] = (string) ($member['name'] ?? '?');

                        continue;
                    }
                    $members[] = [
                        'subculture_character_id' => $character->id,
                        'position' => in_array($member['position'] ?? null, self::POSITIONS, true) ? $member['position'] : null,
                        'sort' => $i,
                        'meta' => filled($member['aside'] ?? null) ? ['aside' => $member['aside']] : null,
                    ];
                }
                if ($members === [] || ! in_array($item['attribute'] ?? null, $attributes, true)) {
                    continue;
                }
                $rows[] = [
                    'party' => [
                        'attribute' => $item['attribute'],
                        'kind' => 'curated',
                        'source' => (string) ($item['source'] ?? 'team-manager'),
                        'title' => '추천 편성',
                        'source_url' => $item['source_url'] ?? null,
                        'period' => null,
                        'sort' => $sort,
                    ],
                    'members' => $members,
                ];

                continue;
            }

        }

        // 수집 0건이면 기존 데이터를 지우지 않는다(수집량 가드와 동일 원칙)
        if ($rows === []) {
            Log::warning('[SGI-ATTR] 동기화할 조합 0건 — 기존 데이터 보존', ['game' => $game->slug]);

            return $stats;
        }

        DB::transaction(function () use ($game, $rows, &$stats) {
            AttributeParty::query()->where('subculture_game_id', $game->id)->delete();

            foreach ($rows as $row) {
                $party = AttributeParty::create($row['party'] + ['subculture_game_id' => $game->id]);
                $party->members()->createMany($row['members']);
                $stats['parties']++;
                $stats['members'] += count($row['members']);
            }
        });

        $stats['missing'] = array_values(array_unique($stats['missing']));

        return $stats;
    }

    /**
     * API 응답 — config 라벨 순서대로 속성별 그룹, 멤버는 캐릭터 조인(일괄 조회, N+1 없음).
     *
     * @return Collection<int, array{attribute: string, label: string, parties: list<array>}>
     */
    public function list(Game $game): Collection
    {
        $labels = (array) config("subculture-game-info.raids.attribute_parties.attributes.{$game->slug}", []);

        $parties = AttributeParty::query()
            ->where('subculture_game_id', $game->id)
            ->with(['members.character'])
            ->orderBy('sort')
            ->get()
            ->groupBy('attribute');

        return collect($labels)
            ->map(fn (string $label, string $attribute) => [
                'attribute' => $attribute,
                'label' => $label,
                'parties' => ($parties->get($attribute) ?? collect())
                    ->map(fn (AttributeParty $party) => [
                        'kind' => $party->kind,
                        'title' => $party->title,
                        'source' => $party->source,
                        'source_url' => $party->source_url,
                        'period' => $party->period,
                        'members' => $party->members
                            ->filter(fn ($member) => $member->character !== null)
                            ->map(fn ($member) => [
                                'external_key' => $member->character->external_key,
                                'name' => $member->character->name,
                                'image_url' => $member->character->display_image_url,
                                'position' => $member->position,
                                'meta' => $member->meta,
                            ])
                            ->values()
                            ->all(),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values();
    }

    /** 이름 동일성 키 — 사이드카 nameKey 와 동일(공백 제거), 콜론·대소문자 차이도 흡수. */
    private function nameKey(string $name): string
    {
        return mb_strtolower((string) preg_replace('/[\s:：]+/u', '', trim($name)));
    }
}
