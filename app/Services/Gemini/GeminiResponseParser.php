<?php

namespace App\Services\Gemini;

class GeminiResponseParser
{
    public static function extractText(array $responseJson): ?string
    {
        $text = data_get($responseJson, 'candidates.0.content.parts.0.text');
        if ($text === null || trim((string) $text) === '') {
            return null;
        }

        return trim((string) $text);
    }

    public static function parseJson(string $text): ?array
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        $stripped = trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $normalized) ?? $normalized);
        $decoded = json_decode($stripped, true);

        return is_array($decoded) ? $decoded : null;
    }

    public static function parseIntro(string $text): ?string
    {
        $data = self::parseJson($text);
        if (isset($data['intro']) && is_string($data['intro'])) {
            return trim(preg_replace('/\s+/', ' ', $data['intro']) ?? $data['intro']);
        }

        // 파싱 실패(코드펜스/잘린 JSON 등) 시 "intro" 값만 추출
        return self::extractFieldFromBrokenJson($text, 'intro');
    }

    /**
     * json_decode가 안 되는 응답에서 특정 키의 문자열 값만 최대한 복구한다.
     * 1) 정상 "key": "..." 매칭 → 2) 닫는 따옴표가 잘린 경우 끝까지 추출 후 잔여 스캐폴딩(따옴표/중괄호/코드펜스) 제거.
     */
    public static function extractFieldFromBrokenJson(string $text, string $key): ?string
    {
        $pattern = '/"'.preg_quote($key, '/').'"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s';
        if (preg_match($pattern, $text, $m)) {
            return trim(stripcslashes($m[1]));
        }

        // 값 도중에 잘린 경우: 키 이후 끝까지 가져와 꼬리 정리
        $partial = '/"'.preg_quote($key, '/').'"\s*:\s*"(.+)$/s';
        if (preg_match($partial, $text, $m)) {
            $value = preg_replace('/["}\s`]+$/u', '', $m[1]) ?? $m[1];

            return trim(stripcslashes($value)) ?: null;
        }

        return null;
    }

    /**
     * 캐릭터 응답을 지문(narration)/대사(message)/호감도(affinity)로 파싱.
     * JSON 파싱 실패 시 message만이라도 폴백 추출한다.
     *
     * @return array{message: string, narration: ?string, affinity: ?int}
     */
    public static function parseReply(string $text): array
    {
        $data = self::parseJson($text);

        if (is_array($data)) {
            $message = '';
            if (isset($data['message']) && is_string($data['message'])) {
                $message = trim($data['message']);
            } elseif (isset($data['text']) && is_string($data['text'])) {
                $message = trim($data['text']);
            }

            $narration = isset($data['narration']) && is_string($data['narration'])
                ? trim($data['narration'])
                : null;

            $affinity = isset($data['affinity']) && is_numeric($data['affinity'])
                ? max(0, min(100, (int) $data['affinity']))
                : null;

            if ($message !== '' || ($narration !== null && $narration !== '')) {
                return [
                    'message' => $message,
                    'narration' => ($narration === '' ? null : $narration),
                    'affinity' => $affinity,
                ];
            }
        }

        // JSON 파싱 실패 폴백: message/text 값만 추출
        $fallback = self::parseMessage($text);

        return ['message' => $fallback ?? trim($text), 'narration' => null, 'affinity' => null];
    }

    /**
     * 추천 답변 배열 파싱
     *
     * @return array<int, string>
     */
    public static function parseSuggestions(string $text): array
    {
        $data = self::parseJson($text);
        $list = is_array($data) && isset($data['suggestions']) && is_array($data['suggestions'])
            ? $data['suggestions']
            : [];

        return collect($list)
            ->filter(fn ($s) => is_string($s) && trim($s) !== '')
            ->map(fn ($s) => trim($s))
            ->values()
            ->all();
    }

    /**
     * 소설 분석 결과(페르소나 필드)를 파싱. 알려진 키의 비어있지 않은 문자열만 추린다.
     *
     * @return array<string, string>
     */
    public static function parsePersona(string $text): array
    {
        $keys = [
            'name', 'short_intro', 'character_detail', 'personality', 'appearance',
            'likes', 'dislikes', 'user_alias', 'speech_style', 'example_dialogue',
            'world_setting', 'genre', 'target',
        ];

        $data = self::parseJson($text);
        if (is_array($data)) {
            $out = [];
            foreach ($keys as $key) {
                if (isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '') {
                    $out[$key] = trim($data[$key]);
                }
            }

            if ($out !== []) {
                return $out;
            }
        }

        // JSON 파싱 실패(따옴표·줄바꿈 등으로 깨진 경우) → 키별 정규식으로 최대한 복구
        $out = [];
        foreach ($keys as $key) {
            if (preg_match('/"'.$key.'"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $text, $m)) {
                $value = trim(stripcslashes($m[1]));
                if ($value !== '') {
                    $out[$key] = $value;
                }
            }
        }

        return $out;
    }

    public static function parseNarration(string $text): ?string
    {
        $data = self::parseJson($text);
        if (is_array($data) && isset($data['narration']) && is_string($data['narration'])) {
            $narration = trim($data['narration']);

            return $narration === '' ? null : $narration;
        }

        return null;
    }

    public static function parseMessage(string $text): ?string
    {
        $data = self::parseJson($text);
        if (is_array($data)) {
            if (isset($data['message']) && is_string($data['message'])) {
                return trim($data['message']);
            }
            if (isset($data['text']) && is_string($data['text'])) {
                return trim($data['text']);
            }
        }

        // 파싱 실패 시 JSON 형태 문자열에서 "message" 또는 "text" 값 추출 (이스케이프·줄바꿈 등 고려)
        $extracted = self::extractMessageFromJsonString($text);
        if ($extracted !== null) {
            return $extracted;
        }

        return null;
    }

    /**
     * JSON 파싱이 안 되는 문자열에서 "message": "..." 또는 "text": "..." 값만 추출
     * (값 내부 줄바꿈·이스케이프 유지)
     */
    protected static function extractMessageFromJsonString(string $text): ?string
    {
        $trimmed = trim($text);
        // "message":"..." 또는 "message": "..." 패턴 (값 내부 \"·\n 등 허용)
        if (preg_match('/"message"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $trimmed, $m)) {
            return trim(stripcslashes($m[1]));
        }
        if (preg_match('/"text"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $trimmed, $m)) {
            return trim(stripcslashes($m[1]));
        }

        return null;
    }
}
