<?php

namespace App\Services\SubcultureGameInfo\Raids;

use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\EventChallenge;
use App\Services\Gemini\GeminiResponseParser;
use App\Services\Gemini\GeminiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 이벤트 챌린지 '내 풀 조합' — 추천 조합(best_party)에서 미보유 캐릭터를
 * 사용자 보유 목록 안에서 Gemini 로 대체해 실전 가능한 조합을 만든다.
 * 스테이지당 Gemini 1회(미보유 전원 일괄), (스테이지, 조합, 보유 풀) 단위 1일 캐시로 토큰 가드.
 */
class EventChallengePartyService
{
    private const CACHE_TTL = 86400;

    public function __construct(private GeminiService $gemini) {}

    /**
     * @param  string[]  $ownedKeys  보유 캐릭터 external_key 목록
     * @return array<int, array{name: string, key: string, owned: bool, replaced_from: ?string}>
     */
    public function myParty(EventChallenge $challenge, array $ownedKeys): array
    {
        $best = collect($challenge->best_party ?? []);
        if ($best->isEmpty()) {
            return [];
        }

        $keyToName = Character::where('subculture_game_id', $challenge->subculture_game_id)
            ->where('active_flg', true)
            ->pluck('name', 'external_key')
            ->all();
        $ownedKeys = array_values(array_intersect($ownedKeys, array_keys($keyToName)));
        $ownedSet = array_flip($ownedKeys);

        $missing = $best->filter(fn (array $m) => ! isset($ownedSet[$m['key']]))->values();
        $substitutions = $missing->isEmpty()
            ? []
            : $this->recommendSubstitutes($challenge, $best->all(), $missing->all(), $ownedKeys, $keyToName);

        $nameToKey = array_flip($keyToName);

        return $best->map(function (array $member) use ($ownedSet, $substitutions, $nameToKey) {
            if (isset($ownedSet[$member['key']])) {
                return $member + ['owned' => true, 'replaced_from' => null];
            }
            $replacement = $substitutions[$member['name']] ?? null;
            if ($replacement !== null) {
                return [
                    'name' => $replacement,
                    'key' => $nameToKey[$replacement],
                    'owned' => true,
                    'replaced_from' => $member['name'],
                ];
            }

            return $member + ['owned' => false, 'replaced_from' => null];
        })->all();
    }

    /**
     * 미보유 전원을 한 번의 Gemini 호출로 대체 추천. 후보는 보유 목록으로 강제(닫힌 어휘).
     *
     * @return array<string, string> 미보유 이름 => 대체 이름
     */
    private function recommendSubstitutes(EventChallenge $challenge, array $party, array $missing, array $ownedKeys, array $keyToName): array
    {
        if (! $this->gemini->hasApiKey() || $ownedKeys === []) {
            return [];
        }

        $missingNames = array_column($missing, 'name');
        $ownedNames = array_values(array_intersect_key($keyToName, array_flip($ownedKeys)));
        sort($ownedKeys);

        $cacheKey = 'sgi:ec-party:'.md5($challenge->id.'|'.implode(',', array_column($party, 'key')).'|'.implode(',', $ownedKeys));

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($challenge, $party, $missingNames, $ownedNames) {
            $prompt = "블루 아카이브 이벤트 챌린지 편성에서 미보유 캐릭터의 대체를 추천하라.\n"
                ."스테이지: {$challenge->stage_label} ({$challenge->clear_condition})\n"
                .'공략 요약: '.mb_substr((string) $challenge->summary, 0, 300)."\n"
                .'추천 편성: '.implode(', ', array_column($party, 'name'))."\n"
                .'미보유(대체 필요): '.implode(', ', $missingNames)."\n"
                .'보유 목록(이 안에서만 선택, 표기 그대로): '.implode(', ', $ownedNames)."\n"
                ."규칙: 역할(딜/탱/힐/버프)과 기믹 적합성이 비슷한 순. 적절한 대체가 없으면 null.\n"
                .'응답은 JSON 객체만: {"미보유이름": "대체이름 또는 null", ...}';

            // maxOutputTokens 를 걸면 thinking 토큰까지 포함돼 JSON 이 중간에 잘린다 — 모델 기본값 사용
            $raw = $this->gemini->generate($prompt, temperature: 0.3, json: true);
            $parsed = $raw !== null ? GeminiResponseParser::parseJson($raw) : null;
            if (! is_array($parsed)) {
                Log::info('[SGI-EC-PARTY] Gemini 대체 추천 실패 — 빈 추천 폴백', ['challenge' => $challenge->id, 'raw' => mb_substr((string) $raw, 0, 200)]);

                return [];
            }

            // 닫힌 어휘 검증: 미보유 키만, 값은 보유 목록 안에서만
            $ownedSet = array_flip($ownedNames);

            return collect($parsed)
                ->filter(fn ($v, $k) => in_array($k, $missingNames, true) && is_string($v) && isset($ownedSet[$v]))
                ->all();
        });
    }
}
