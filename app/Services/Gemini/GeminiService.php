<?php

namespace App\Services\Gemini;

use Illuminate\Support\Facades\Cache;
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

    private bool $cacheEnabled;

    private int $cacheTtl;

    private string $embedModel;

    public function __construct()
    {
        // config 값이 명시적 null(GEMINI_API_KEY 미설정)일 수 있으므로 문자열로 강제 변환.
        $this->apiKey = (string) config('services.gemini.api_key', '');
        $this->model = (string) config('services.gemini.model') ?: self::DEFAULT_MODEL;
        // Gemini 3 계열은 thinkingLevel(low/medium/high)로 사고량을 조절한다. 빈 값이면 미지정(모델 기본값).
        $this->thinkingLevel = trim((string) config('services.gemini.thinking_level', ''));
        $this->cacheEnabled = (bool) config('services.gemini.context_cache', false);
        $this->cacheTtl = max(60, (int) config('services.gemini.context_cache_ttl', 1800));
        $this->embedModel = (string) config('services.gemini.embed_model', 'gemini-embedding-001');
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
     * 스트리밍 채팅. streamGenerateContent(alt=sse)로 응답을 토큰 단위로 받아 $onText 콜백에 흘린다.
     * 응답 텍스트 조각이 도착할 때마다 $onText($delta) 가 호출된다. 성공하면 true.
     * 실패(키 없음/네트워크/HTTP 에러)는 로그만 남기고 false 를 반환 — 호출 측에서 폴백 처리.
     */
    public function streamChat(string $systemPrompt, array $contents, callable $onText, float $temperature = 0.8, ?int $maxOutputTokens = null): bool
    {
        if (! $this->hasApiKey()) {
            return false;
        }

        $url = self::BASE_URL.'/models/'.$this->model.':streamGenerateContent?alt=sse&key='.$this->apiKey;
        $body = $this->buildBody($contents, $temperature, $systemPrompt, false, $maxOutputTokens);

        try {
            // withOptions(stream:true) 로 응답 바디를 스트림으로 받아 청크 단위로 읽는다.
            $response = Http::timeout(120)->withOptions(['stream' => true])->post($url, $body);
        } catch (\Throwable $e) {
            Log::warning('Gemini stream request failed', ['message' => $e->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('Gemini stream error', ['status' => $response->status()]);

            return false;
        }

        $stream = $response->toPsrResponse()->getBody();
        $buffer = '';
        $got = false;

        // SSE 프레임: 각 이벤트가 "data: {GenerateContentResponse 조각}\n\n" 로 온다. 줄 단위로 파싱.
        while (! $stream->eof()) {
            $chunk = $stream->read(4096);
            if ($chunk === '') {
                continue;
            }
            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $pos), "\r");
                $buffer = substr($buffer, $pos + 1);

                if ($line === '' || ! str_starts_with($line, 'data:')) {
                    continue;
                }
                $payload = trim(substr($line, 5));
                if ($payload === '' || $payload === '[DONE]') {
                    continue;
                }

                $data = json_decode($payload, true);
                if (! is_array($data)) {
                    continue;
                }
                $text = data_get($data, 'candidates.0.content.parts.0.text');
                if (is_string($text) && $text !== '') {
                    $got = true;
                    $onText($text);
                }
            }
        }

        return $got;
    }

    /**
     * 텍스트 임베딩(장기기억 RAG 용). 실패 시 null.
     *
     * @return array<int, float>|null
     */
    public function embed(string $text, string $taskType = 'RETRIEVAL_DOCUMENT'): ?array
    {
        $text = trim($text);
        if (! $this->hasApiKey() || $text === '') {
            return null;
        }

        $url = self::BASE_URL.'/models/'.$this->embedModel.':embedContent?key='.$this->apiKey;

        try {
            $res = Http::timeout(20)->post($url, [
                'content' => ['parts' => [['text' => mb_substr($text, 0, 8000)]]],
                'taskType' => $taskType,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Gemini] 임베딩 요청 실패', ['message' => $e->getMessage()]);

            return null;
        }

        if (! $res->successful()) {
            Log::warning('[Gemini] 임베딩 오류', ['status' => $res->status()]);

            return null;
        }

        $values = data_get($res->json(), 'embedding.values');

        return is_array($values) && $values !== [] ? array_map('floatval', $values) : null;
    }

    private function call(array $contents, float $temperature, ?string $systemPrompt = null, bool $json = false, ?int $maxOutputTokens = null): array
    {
        $url = self::BASE_URL.'/models/'.$this->model.':generateContent?key='.$this->apiKey;
        $body = $this->buildBody($contents, $temperature, $systemPrompt, $json, $maxOutputTokens);

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

        $result = $response->json() ?? [];

        $this->logUsage($result, $body['generationConfig']['maxOutputTokens'] ?? self::MAX_TOKENS);

        return $result;
    }

    /**
     * 요청 바디 조립(generateContent/streamGenerateContent 공용).
     * 컨텍스트 캐싱이 켜져 있고 캐시가 확보되면 systemInstruction 대신 cachedContent 를 참조한다.
     */
    private function buildBody(array $contents, float $temperature, ?string $systemPrompt, bool $json, ?int $maxOutputTokens): array
    {
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
            $cacheName = $this->cachedContentName($systemPrompt);
            if ($cacheName !== null) {
                $body['cachedContent'] = $cacheName;
            } else {
                $body['systemInstruction'] = ['parts' => [['text' => $systemPrompt]]];
            }
        }

        return $body;
    }

    /**
     * systemInstruction(페르소나)에 대한 캐시 리소스 이름을 확보한다(get-or-create).
     * - 캐싱 비활성/키 없음/짧은 프롬프트: null(인라인 systemInstruction 로 폴백).
     * - 생성 실패(모델 미지원·최소 토큰 미달 등)는 'unsupported' 로 표시해 재시도를 막는다.
     * 캐시 이름은 로컬 캐시(Cache)에 TTL 보다 약간 짧게 보관한다.
     */
    private function cachedContentName(?string $systemPrompt): ?string
    {
        if (! $this->cacheEnabled || $systemPrompt === null || trim($systemPrompt) === '') {
            return null;
        }

        $key = 'gemini:ctxcache:'.$this->model.':'.sha1($systemPrompt);
        $cached = Cache::get($key);
        if ($cached === 'unsupported') {
            return null;
        }
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $res = Http::timeout(20)->post(self::BASE_URL.'/cachedContents?key='.$this->apiKey, [
                'model' => 'models/'.$this->model,
                'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
                'ttl' => $this->cacheTtl.'s',
            ]);
        } catch (\Throwable $e) {
            Log::info('[Gemini] 컨텍스트 캐시 예외(인라인 폴백)', ['error' => $e->getMessage()]);
            Cache::put($key, 'unsupported', now()->addHour());

            return null;
        }

        $name = $res->successful() ? data_get($res->json(), 'name') : null;
        if (! is_string($name) || $name === '') {
            // 최소 토큰 미달·모델 미지원 등 → 일정 시간 동안 재시도 안 함.
            Log::info('[Gemini] 컨텍스트 캐시 생성 실패(인라인 폴백)', ['status' => $res->status()]);
            Cache::put($key, 'unsupported', now()->addHours(6));

            return null;
        }

        Cache::put($key, $name, now()->addSeconds($this->cacheTtl - 60));

        return $name;
    }

    private function logUsage(array $json, int $maxOutputTokens): void
    {
        // 토큰 사용량 관측 — 비용 추적용(입력/사고/출력). grep '[Gemini] usage' 로 집계할 수 있다.
        if ($usage = data_get($json, 'usageMetadata')) {
            Log::info('[Gemini] usage', [
                'model' => $this->model,
                'prompt' => data_get($usage, 'promptTokenCount'),
                'cached' => data_get($usage, 'cachedContentTokenCount'),
                'thoughts' => data_get($usage, 'thoughtsTokenCount'),
                'output' => data_get($usage, 'candidatesTokenCount'),
                'total' => data_get($usage, 'totalTokenCount'),
            ]);
        }

        // 출력 예산 초과로 응답이 잘린 경우를 관측 가능하게 남긴다 (대사 중간 끊김 진단용).
        if (data_get($json, 'candidates.0.finishReason') === 'MAX_TOKENS') {
            Log::warning('Gemini 응답이 MAX_TOKENS로 잘림', [
                'model' => $this->model,
                'maxOutputTokens' => $maxOutputTokens,
                'usage' => data_get($json, 'usageMetadata'),
            ]);
        }
    }
}
