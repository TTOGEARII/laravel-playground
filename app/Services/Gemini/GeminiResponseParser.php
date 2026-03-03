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
        $normalized = trim(preg_replace('/\s+/', ' ', $text));
        $stripped = trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $normalized));
        $decoded = json_decode($stripped, true);
        return is_array($decoded) ? $decoded : null;
    }

    public static function parseIntro(string $text): ?string
    {
        $data = self::parseJson($text);
        if (isset($data['intro']) && is_string($data['intro'])) {
            return trim(preg_replace('/\s+/', ' ', $data['intro']));
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
