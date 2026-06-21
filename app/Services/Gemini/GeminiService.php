<?php

namespace App\Services\Gemini;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    private const DEFAULT_MODEL = 'gemini-3-flash-preview';

    private const MAX_TOKENS = 1000;

    private string $apiKey;

    private string $model;

    public function __construct()
    {
        // config 값이 명시적 null(GEMINI_API_KEY 미설정)일 수 있으므로 문자열로 강제 변환.
        $this->apiKey = (string) config('services.gemini.api_key', '');
        $this->model = (string) config('services.gemini.model') ?: self::DEFAULT_MODEL;
    }

    public function hasApiKey(): bool
    {
        return filled($this->apiKey);
    }

    public function generate(string $prompt, float $temperature = 0.8, bool $json = false, ?int $maxOutputTokens = null): ?string
    {
        $response = $this->call(
            [['parts' => [['text' => $prompt]]]],
            $temperature,
            null,
            $json,
            $maxOutputTokens
        );

        $text = GeminiResponseParser::extractText($response);

        return $text ? trim(preg_replace('/\s+/', ' ', $text) ?? $text) : null;
    }

    public function chat(string $systemPrompt, array $contents, float $temperature = 0.8, bool $json = false, ?int $maxOutputTokens = null): ?string
    {
        $response = $this->call($contents, $temperature, $systemPrompt, $json, $maxOutputTokens);

        return GeminiResponseParser::extractText($response);
    }

    private function call(array $contents, float $temperature, ?string $systemPrompt = null, bool $json = false, ?int $maxOutputTokens = null): array
    {
        $url = self::BASE_URL.'/models/'.$this->model.':generateContent?key='.$this->apiKey;

        $generationConfig = [
            'maxOutputTokens' => $maxOutputTokens ?? self::MAX_TOKENS,
            'temperature' => $temperature,
        ];
        // 구조화 JSON이 필요한 호출은 응답 MIME을 JSON으로 강제해 코드펜스/이스케이프 깨짐을 방지.
        if ($json) {
            $generationConfig['responseMimeType'] = 'application/json';
        }

        $body = [
            'contents' => $contents,
            'generationConfig' => $generationConfig,
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
