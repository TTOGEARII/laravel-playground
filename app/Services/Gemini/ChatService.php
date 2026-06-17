<?php

namespace App\Services\Gemini;

use App\Models\ChatCharacter;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use Illuminate\Database\Eloquent\Collection;

class ChatService
{
    private const MESSAGES_BEFORE_SUMMARY = 20;

    public function __construct(
        private GeminiService $gemini
    ) {}

    /**
     * 채팅 세션 생성 + 캐릭터 인트로 메시지 저장.
     */
    public function initialize(ChatCharacter $character): ChatSession
    {
        $session = ChatSession::create(['chat_character_id' => $character->id]);

        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'character',
            'content' => $this->getIntroMessage($character),
        ]);

        return $session;
    }

    /**
     * 유저 메시지 저장 → (임계치 초과 시 이전 대화 요약) → Gemini 응답 저장 후 반환.
     * 요약/히스토리 구성 등 대화 오케스트레이션을 모두 담당해 컨트롤러를 얇게 유지한다.
     */
    public function reply(ChatSession $session, string $content): string
    {
        $character = $session->chatCharacter;

        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'user',
            'content' => $content,
        ]);

        $messages = $session->messages()->orderBy('id')->get();
        $summarizedId = $session->summarized_until_message_id;

        // 미요약 메시지가 임계치를 넘으면 가장 오래된 한 묶음을 요약해 컨텍스트를 압축한다.
        $unsummarized = $this->unsummarizedAfter($messages, $summarizedId);
        if ($unsummarized->count() > self::MESSAGES_BEFORE_SUMMARY) {
            $chunk = $unsummarized->take(self::MESSAGES_BEFORE_SUMMARY);
            $toSummarize = $chunk->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])->all();
            $newSummary = $this->summarize($toSummarize, $session->conversation_summary);

            if ($newSummary !== '') {
                $session->conversation_summary = trim(
                    ($session->conversation_summary ? $session->conversation_summary."\n" : '').$newSummary
                );
                $session->summarized_until_message_id = $chunk->last()->id;
                $session->save();
                // 요약 후에는 갱신된 포인터 기준으로 히스토리를 다시 잡아 요약된 구간을 중복 전송하지 않는다.
                $summarizedId = $session->summarized_until_message_id;
            }
        }

        // 모델에 넘길 직전 히스토리: 미요약 메시지에서 방금 저장한 유저 메시지(마지막)는 제외.
        $recent = $this->unsummarizedAfter($messages, $summarizedId);
        $history = $recent->count() > 0 ? $recent->take($recent->count() - 1) : $recent;
        $recentMessages = $history->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])->values()->all();

        $reply = $this->chat($character, $session->conversation_summary, $recentMessages, $content);

        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'character',
            'content' => $reply,
        ]);

        return $reply;
    }

    /**
     * 요약 포인터(이 ID 이하) 이후의 미요약 메시지만 추린다.
     *
     * @param  Collection<int, ChatMessage>  $messages
     * @return Collection<int, ChatMessage>
     */
    private function unsummarizedAfter(Collection $messages, ?int $summarizedId): Collection
    {
        return $summarizedId === null
            ? $messages
            : $messages->filter(fn ($m) => $m->id > $summarizedId)->values();
    }

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
            return ($character->name ?? '캐릭터').'입니다. (API 설정 후 이용해 주세요.)';
        }

        $systemPrompt = PromptBuilder::characterSystem($character);
        if (filled($summary)) {
            $systemPrompt .= "\n\n[이전 대화 요약]\n".trim($summary);
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

        return $text ? trim(preg_replace('/\s+/', ' ', $text) ?? $text) : '';
    }

    private function fallbackGreeting(ChatCharacter $character): string
    {
        return '안녕하세요, '.$character->name.'이에요. 편하게 이야기해 주세요.';
    }
}
