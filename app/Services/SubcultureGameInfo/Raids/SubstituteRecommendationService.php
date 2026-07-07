<?php

namespace App\Services\SubcultureGameInfo\Raids;

use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Raid;
use App\Services\Gemini\GeminiResponseParser;
use App\Services\Gemini\GeminiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 미보유 캐릭터의 대체 후보를 Gemini 에게 추천받는다 — 내 풀 조합의 수동 대체 지정 보조.
 * 후보는 사용자의 "보유 캐릭터 목록"(닫힌 어휘)으로 강제하고, 목록 밖 이름은 버린다.
 * API 키 없음/외부 실패는 빈 추천으로 폴백(500 금지), 같은 질문은 캐시로 토큰 절약.
 */
class SubstituteRecommendationService
{
    private const MAX_RECOMMENDATIONS = 3;

    private const CACHE_TTL = 86400; // 같은 (레이드, 캐릭터, 보유 풀) 질문은 1일 캐시 — Gemini 토큰 가드

    public function __construct(private GeminiService $gemini) {}

    /**
     * @param  string  $characterKey  대체가 필요한 미보유 캐릭터 external_key
     * @param  list<string>  $ownedKeys  사용자 보유 캐릭터 external_key 목록
     * @return array{supported: bool, target: ?array, recommendations: list<array{external_key: string, name: string, image_url: ?string, reason: ?string}>}
     */
    public function recommend(Raid $raid, string $characterKey, array $ownedKeys): array
    {
        $empty = ['supported' => true, 'target' => null, 'recommendations' => []];

        if (! $this->gemini->hasApiKey()) {
            return ['supported' => false, 'target' => null, 'recommendations' => []];
        }

        $raid->loadMissing('game');

        $target = Character::query()
            ->where('subculture_game_id', $raid->subculture_game_id)
            ->where('external_key', $characterKey)
            ->first();
        if ($target === null) {
            return $empty;
        }
        $empty['target'] = ['external_key' => $target->external_key, 'name' => $target->name];

        $owned = Character::query()
            ->where('subculture_game_id', $raid->subculture_game_id)
            ->whereIn('external_key', collect($ownedKeys)->map(fn ($k) => (string) $k)->unique()->take(500))
            ->where('external_key', '!=', $characterKey)
            ->active()
            ->get();
        if ($owned->isEmpty()) {
            return $empty;
        }

        $cacheKey = 'sgi:sub-rec:'.md5($raid->id.'|'.$characterKey.'|'.$owned->pluck('external_key')->sort()->implode(','));

        $recommendations = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($raid, $target, $owned) {
            $raw = $this->gemini->generate(
                $this->buildPrompt($raid, $target, $owned->all()),
                temperature: 0.3,
                json: true,
                maxOutputTokens: 2048,
            );
            if ($raw === null) {
                Log::warning('[SGI-SUBREC] Gemini 응답 없음', ['raid_id' => $raid->id, 'target' => $target->external_key]);

                return [];
            }

            $parsed = GeminiResponseParser::parseJson($raw);
            if (! is_array($parsed)) {
                Log::warning('[SGI-SUBREC] Gemini JSON 파싱 실패', ['raid_id' => $raid->id, 'target' => $target->external_key]);

                return [];
            }

            // 닫힌 어휘: 보유 목록의 이름만 인정(정규화 매칭), 중복 제거, 상한 적용
            $nameIndex = $owned->keyBy(fn (Character $c) => $this->normalizeName($c->name));
            $seen = [];
            $result = [];
            foreach ($parsed as $item) {
                if (! is_array($item) || ! is_string($item['name'] ?? null)) {
                    continue;
                }
                $candidate = $nameIndex->get($this->normalizeName($item['name']));
                if ($candidate === null || isset($seen[$candidate->id])) {
                    continue;
                }
                $seen[$candidate->id] = true;
                $result[] = [
                    'external_key' => $candidate->external_key,
                    'name' => $candidate->name,
                    'image_url' => $candidate->display_image_url,
                    'reason' => isset($item['reason']) && is_string($item['reason'])
                        ? mb_substr(trim($item['reason']), 0, 120)
                        : null,
                ];
                if (count($result) >= self::MAX_RECOMMENDATIONS) {
                    break;
                }
            }

            return $result;
        });

        return ['supported' => true, 'target' => $empty['target'], 'recommendations' => $recommendations];
    }

    /**
     * 추천 프롬프트 — 보유 목록(닫힌 어휘)과 JSON 형식을 강제한다.
     *
     * @param  Character[]  $owned
     */
    private function buildPrompt(Raid $raid, Character $target, array $owned): string
    {
        $gameName = $raid->game?->name ?? '';
        $bossName = $raid->boss_name ?: '-';
        $targetTraits = $target->traits ? json_encode($target->traits, JSON_UNESCAPED_UNICODE) : '-';
        $ownedLines = collect($owned)
            ->map(fn (Character $c) => '- '.$c->name.($c->traits ? ' '.json_encode($c->traits, JSON_UNESCAPED_UNICODE) : ''))
            ->implode("\n");

        return <<<PROMPT
너는 서브컬쳐 게임 "{$gameName}"의 레이드 편성 전문가다.

[상황]
- 레이드: {$raid->name} (보스: {$bossName})
- 사용자가 미보유한 캐릭터: {$target->name} (특성: {$targetTraits})
- 사용자는 이 캐릭터 자리를 자신의 보유 캐릭터로 채우려 한다.

[규칙]
1. 아래 [보유 캐릭터 목록]에서만 대체 후보를 고른다. 목록에 없는 이름은 절대 쓰지 않는다.
2. 역할·속성·시너지가 비슷한 순으로 최대 3명. 마땅한 후보가 없으면 빈 배열을 출력한다.
3. reason 은 왜 대체가 되는지 한 문장(80자 이내, 한국어)으로 쓴다.
4. 응답은 아래 형식의 JSON 배열만 출력한다. 다른 텍스트를 붙이지 않는다.

[응답 형식]
[{"name": "캐릭터명", "reason": "추천 이유"}]

[보유 캐릭터 목록]
{$ownedLines}
PROMPT;
    }

    /** 이름 비교용 정규화 — 공백/콜론(반각·전각) 제거 + 소문자화(추출 서비스와 동일 규칙). */
    private function normalizeName(string $name): string
    {
        return mb_strtolower((string) preg_replace('/[\s:：]+/u', '', trim($name)));
    }
}
