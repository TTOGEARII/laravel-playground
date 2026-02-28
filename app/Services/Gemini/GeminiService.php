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
        $this->apiKey = config('services.gemini.api_key', '');
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
        return $text ? trim(preg_replace('/\s+/', ' ', $text)) : null;
    }

    public function chat(string $systemPrompt, array $contents, float $temperature = 0.8): ?string
    {
        $response = $this->call($contents, $temperature, $systemPrompt);
        return GeminiResponseParser::extractText($response);
    }

    private function call(array $contents, float $temperature, ?string $systemPrompt = null): array
    {
        $url = self::BASE_URL . '/models/' . self::MODEL . ':generateContent?key=' . $this->apiKey;
        $body = [
            'contents' => $contents,
            'generationConfig' => ['maxOutputTokens' => self::MAX_TOKENS, 'temperature' => $temperature],
        ];
        if ($systemPrompt !== null) {
            $body['systemInstruction'] = ['parts' => [['text' => $systemPrompt]]];
        }
        $response = Http::timeout(30)->post($url, $body);
        if (! $response->successful()) {
            Log::warning('Gemini API error', ['status' => $response->status(), 'body' => $response->json()]);
            return [];
        }
        return $response->json() ?? [];
    }
}
