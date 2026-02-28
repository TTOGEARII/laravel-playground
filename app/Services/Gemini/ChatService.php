<?php

namespace App\Services\Gemini;

use App\Models\ChatCharacter;

class ChatService
{
    private const MESSAGES_BEFORE_SUMMARY = 20;

    public function __construct(
        private GeminiService $gemini
    ) {}

    /**
     * 채팅 입장 시 인트로 메시지 반환
     */
    public function getIntroMessage(ChatCharacter $character): string
    {
        if (! $this->gemini->hasApiKey()) {
            return $this->fallbackGreeting($character);
        }

        $storedIntro = filled($character->intro_message) ? trim($character->intro_message) : null;
        $prompt = $storedIntro
            ? PromptBuilder::introFromStored($character, $storedIntro)
            : PromptBuilder::greeting($character);

        $text = $this->gemini->generate($prompt);

        if ($text) {
            $parsed = GeminiResponseParser::parseIntro($text);
            if ($parsed) {
                return $parsed;
            }
            return $text;
        }

        return $storedIntro ? trim($storedIntro) : $this->fallbackGreeting($character);
    }

    /**
     * 캐릭터 폼용 인사말 생성
     */
    public function generateGreeting(ChatCharacter $character): string
    {
        if (! $this->gemini->hasApiKey()) {
            return $this->fallbackGreeting($character);
        }

        $text = $this->gemini->generate(PromptBuilder::greeting($character));

        if ($text) {
            return GeminiResponseParser::parseIntro($text) ?? $text;
        }

        return $this->fallbackGreeting($character);
    }

    /**
     * 대화 히스토리 기반 채팅 응답 생성
     */
    public function chat(ChatCharacter $character, ?string $summary, array $recentMessages, string $userMessage): string
    {
        if (! $this->gemini->hasApiKey()) {
            return ($character->name ?? '캐릭터') . '입니다. (API 설정 후 이용해 주세요.)';
        }

        $systemPrompt = PromptBuilder::characterSystem($character);
        if (filled($summary)) {
            $systemPrompt .= "\n\n[이전 대화 요약]\n" . trim($summary);
        }

        $contents = collect($recentMessages)
            ->map(fn ($m) => [
                'role' => ($m['role'] ?? '') === 'character' ? 'model' : 'user',
                'parts' => [['text' => trim((string) ($m['content'] ?? ''))]],
            ])
            ->push(['role' => 'user', 'parts' => [['text' => $userMessage]]])
            ->values()
            ->all();

        $text = $this->gemini->chat($systemPrompt, $contents);

        if ($text) {
            return GeminiResponseParser::parseMessage($text) ?? trim($text);
        }

        return '잠시 후 다시 말 걸어 주세요.';
    }

    /**
     * 대화 요약
     */
    public function summarize(array $messages, ?string $previousSummary = null): string
    {
        if (! $this->gemini->hasApiKey()) {
            return '';
        }

        $text = $this->gemini->generate(
            PromptBuilder::summarize($messages, $previousSummary),
            temperature: 0.3,
        );

        return $text ? trim(preg_replace('/\s+/', ' ', $text)) : '';
    }

    public function getSummaryThreshold(): int
    {
        return self::MESSAGES_BEFORE_SUMMARY;
    }

    private function fallbackGreeting(ChatCharacter $character): string
    {
        return '안녕하세요, ' . $character->name . '이에요. 편하게 이야기해 주세요.';
    }
}
