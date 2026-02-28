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
        if (isset($data['message']) && is_string($data['message'])) {
            return trim($data['message']);
        }
        if (isset($data['text']) && is_string($data['text'])) {
            return trim($data['text']);
        }
        return null;
    }
}
