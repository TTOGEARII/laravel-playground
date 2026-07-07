<?php

namespace App\Services\Gemini;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    private const DEFAULT_MODEL = 'gemini-3-flash-preview';

    // 사고(thinking) 토큰이 출력 예산을 함께 소비하므로 넉넉히 잡아 대사가 중간에 잘리는 것을 막는다.
    private const MAX_TOKENS = 2048;

    private string $apiKey;

    private string $model;

    private string $thinkingLevel;

    public function __construct()
    {
        // config 값이 명시적 null(GEMINI_API_KEY 미설정)일 수 있으므로 문자열로 강제 변환.
        $this->apiKey = (string) config('services.gemini.api_key', '');
        $this->model = (string) config('services.gemini.model') ?: self::DEFAULT_MODEL;
        // Gemini 3 계열은 thinkingLevel(low/medium/high)로 사고량을 조절한다. 빈 값이면 미지정(모델 기본값).
        $this->thinkingLevel = trim((string) config('services.gemini.thinking_level', ''));
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

    /**
     * 텍스트 + 이미지 멀티모달 생성 — 공략 스크린샷(인포그래픽) 분석용.
     * 별도 OCR 라이브러리 없이 Gemini 가 이미지 속 표·이름 라벨을 직접 읽는다.
     *
     * @param  array<int, array{mime_type: string, data: string}>  $images  base64 인코딩된 이미지 목록
     */
    public function generateWithImages(string $prompt, array $images, float $temperature = 0.8, bool $json = false, ?int $maxOutputTokens = null): ?string
    {
        $parts = [['text' => $prompt]];
        foreach ($images as $image) {
            $parts[] = ['inlineData' => ['mimeType' => $image['mime_type'], 'data' => $image['data']]];
        }

        $response = $this->call([['parts' => $parts]], $temperature, null, $json, $maxOutputTokens);
        $text = GeminiResponseParser::extractText($response);

        return $text ? trim($text) : null;
    }

    private function call(array $contents, float $temperature, ?string $systemPrompt = null, bool $json = false, ?int $maxOutputTokens = null): array
    {
        $url = self::BASE_URL.'/models/'.$this->model.':generateContent?key='.$this->apiKey;

        $generationConfig = [
            'maxOutputTokens' => $maxOutputTokens ?? self::MAX_TOKENS,
            'temperature' => $temperature,
        ];
        // Gemini 3 계열은 사고 토큰이 출력 예산을 잠식한다 → thinkingLevel을 낮춰(low) 실제 대사 분량을 확보.
        if ($this->thinkingLevel !== '') {
            $generationConfig['thinkingConfig'] = ['thinkingLevel' => $this->thinkingLevel];
        }
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

        $json = $response->json() ?? [];

        // 출력 예산 초과로 응답이 잘린 경우를 관측 가능하게 남긴다 (대사 중간 끊김 진단용).
        if (data_get($json, 'candidates.0.finishReason') === 'MAX_TOKENS') {
            Log::warning('Gemini 응답이 MAX_TOKENS로 잘림', [
                'model' => $this->model,
                'maxOutputTokens' => $generationConfig['maxOutputTokens'],
                'usage' => data_get($json, 'usageMetadata'),
            ]);
        }

        return $json;
    }
}
