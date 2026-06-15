<?php

namespace App\Services\Gemini;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    private const MODEL = 'gemini-2.5-flash';

    private const MAX_TOKENS = 1000;

    private string $apiKey;

    public function __construct()
    {
        // config 값이 명시적 null(GEMINI_API_KEY 미설정)일 수 있으므로 문자열로 강제 변환.
        $this->apiKey = (string) config('services.gemini.api_key', '');
    }

    public function hasApiKey(): bool
    {
        return filled($this->apiKey);
    }

    public function generate(string $prompt, float $temperature = 0.8): ?string
    {
        $response = $this->call(
            [['parts' => [['text' => $prompt]]]],
            $temperature,
            null
        );

        $text = GeminiResponseParser::extractText($response);

        return $text ? trim(preg_replace('/\s+/', ' ', $text) ?? $text) : null;
    }

    public function chat(string $systemPrompt, array $contents, float $temperature = 0.8): ?string
    {
        $response = $this->call($contents, $temperature, $systemPrompt);

        return GeminiResponseParser::extractText($response);
    }

    private function call(array $contents, float $temperature, ?string $systemPrompt = null): array
    {
        $url = self::BASE_URL.'/models/'.self::MODEL.':generateContent?key='.$this->apiKey;
        $body = [
            'contents' => $contents,
            'generationConfig' => ['maxOutputTokens' => self::MAX_TOKENS, 'temperature' => $temperature],
        ];
        if ($systemPrompt !== null) {
            $body['systemInstruction'] = ['parts' => [['text' => $systemPrompt]]];
        }

        try {
            $response = Http::timeout(30)->post($url, $body);
        } catch (\Throwable $e) {
            // 네트워크/타임아웃 등 연결 단계 실패는 폴백으로 처리 (호출 측에서 graceful degradation).
            Log::warning('Gemini API request failed', ['message' => $e->getMessage()]);

            return [];
        }

        if (! $response->successful()) {
            Log::warning('Gemini API error', ['status' => $response->status(), 'body' => $response->json()]);

            return [];
        }

        return $response->json() ?? [];
    }
}
