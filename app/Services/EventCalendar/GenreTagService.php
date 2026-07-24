<?php

namespace App\Services\EventCalendar;

use App\Enums\EventCalendar\EventKind;
use App\Models\EventCalendar\Event;
use App\Services\Gemini\GeminiService;
use Illuminate\Support\Facades\Log;

/**
 * 공연 장르 태깅 — festivallife 는 장르 무관 전체 내한공연이라, 미태깅 공연을 Gemini 로
 * jpop(일본 아티스트: J-pop/J-rock/애니송/성우 등) / other 로 배치 분류한다.
 * 캘린더 기본 필터가 J-pop 이므로 이 태그가 UI 필터의 근거.
 * 비용 가드: 텍스트 배치 소량(제목만·수집당 1콜) — 키 없거나 실패 시 null 유지(다음 실행에 재시도).
 */
class GenreTagService
{
    /** 한 번에 분류할 최대 건수(첫 백필도 1~2콜에 끝나는 크기). */
    private const BATCH_LIMIT = 60;

    public function __construct(private GeminiService $gemini) {}

    /**
     * @return array{tagged: int, skipped: bool}
     */
    public function tagUntagged(): array
    {
        if (! $this->gemini->hasApiKey()) {
            return ['tagged' => 0, 'skipped' => true];
        }

        $events = Event::where('kind', EventKind::Concert->value)
            ->whereNull('genre')
            ->orderBy('id')
            ->limit(self::BATCH_LIMIT)
            ->get(['id', 'title']);
        if ($events->isEmpty()) {
            return ['tagged' => 0, 'skipped' => false];
        }

        $list = $events->map(fn (Event $e) => ['id' => $e->id, 'title' => $e->title])->values()->toJson(JSON_UNESCAPED_UNICODE);
        $prompt = <<<PROMPT
            다음은 한국 내한공연 제목 목록이다. 각 공연의 아티스트가 일본 음악가(J-pop·J-rock·일본 밴드·애니송·성우·보컬로이드 등 일본 출신/일본 활동 아티스트)이면 "jpop", 그 외 국가 아티스트면 "other" 로 분류하라.
            확실하지 않으면 "other" 로 두어라. 반드시 JSON 만 출력: {"tags":[{"id":1,"genre":"jpop"}]}

            목록: {$list}
            PROMPT;

        $raw = $this->gemini->generate($prompt, temperature: 0.1, json: true, maxOutputTokens: 4000);
        if ($raw === null) {
            Log::warning('행사 장르 태깅 실패(Gemini 응답 없음) — 다음 실행에 재시도');

            return ['tagged' => 0, 'skipped' => false];
        }

        $tags = json_decode($raw, true)['tags'] ?? null;
        if (! is_array($tags)) {
            Log::warning('행사 장르 태깅 실패(JSON 파싱)', ['raw' => mb_substr($raw, 0, 200)]);

            return ['tagged' => 0, 'skipped' => false];
        }

        $validIds = $events->pluck('id')->flip();
        $tagged = 0;
        foreach ($tags as $tag) {
            $id = (int) ($tag['id'] ?? 0);
            $genre = (string) ($tag['genre'] ?? '');
            if (! isset($validIds[$id]) || ! in_array($genre, ['jpop', 'other'], true)) {
                continue; // 닫힌 어휘 밖·요청 밖 id 는 무시
            }
            Event::where('id', $id)->whereNull('genre')->update(['genre' => $genre]);
            $tagged++;
        }

        return ['tagged' => $tagged, 'skipped' => false];
    }
}
