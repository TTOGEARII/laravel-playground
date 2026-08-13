<?php

namespace App\Services\Gemini;

use App\Enums\MyWifeBot\Genre;
use App\Enums\MyWifeBot\Target;
use App\Models\MyWifeBot\ChatCharacter;
use App\Models\MyWifeBot\ChatMemory;
use App\Models\MyWifeBot\ChatMessage;
use App\Models\MyWifeBot\ChatSession;
use Illuminate\Database\Eloquent\Collection;

class ChatService
{
    private const MESSAGES_BEFORE_SUMMARY = 20;

    /** 장기기억(RAG) 검색 임계치 — 코사인 유사도가 이보다 낮으면 무관한 기억으로 보고 버린다. */
    private const MEMORY_SIMILARITY_MIN = 0.6;

    private bool $longTermMemory;

    public function __construct(
        private GeminiService $gemini
    ) {
        $this->longTermMemory = (bool) config('services.gemini.long_term_memory', false);
    }

    /**
     * 채팅 세션 생성 + 캐릭터 인트로 메시지 저장.
     */
    public function initialize(ChatCharacter $character, ?int $userId = null): ChatSession
    {
        $session = ChatSession::create([
            'chat_character_id' => $character->id,
            'user_id' => $userId,
        ]);

        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'character',
            'content' => $this->getIntroMessage($character),
        ]);

        return $session;
    }

    /**
     * 이어할 수 있는 기존 세션을 찾는다 (대화 이어가기).
     * - session_id가 주어지면 해당 캐릭터의 그 세션을(소유 일치 시) 우선 사용.
     * - 없으면 로그인 사용자의 가장 최근 세션을 사용.
     * 게스트(userId=null)는 session_id로만 재개한다(브라우저 보관 ID 신뢰).
     */
    public function findResumableSession(ChatCharacter $character, ?int $sessionId, ?int $userId): ?ChatSession
    {
        // 게스트는 대화 내용을 저장/복원하지 않는다 (항상 새 대화).
        if ($userId === null) {
            return null;
        }

        if ($sessionId) {
            $candidate = ChatSession::where('chat_character_id', $character->id)->find($sessionId);
            if ($candidate && (int) $candidate->user_id === $userId) {
                return $candidate;
            }
        }

        return ChatSession::where('chat_character_id', $character->id)
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * 유저 메시지 저장 → (임계치 초과 시 이전 대화 요약) → Gemini 응답 저장 후 반환.
     * 요약/히스토리 구성 등 대화 오케스트레이션을 모두 담당해 컨트롤러를 얇게 유지한다.
     *
     * @return array{message: string, narration: ?string, affinity: int}
     */
    public function reply(ChatSession $session, string $content): array
    {
        [$character, $recentMessages, $summary] = $this->persistAndPrepare($session, $content);
        $memories = $this->retrieveMemories($session, $content);

        $reply = $this->chat($character, $summary, $recentMessages, $content, $memories);

        $affinity = $this->persistCharacterReply($session, $reply['message'], $reply['narration'], $reply['affinity']);

        return [
            'message' => $reply['message'],
            'narration' => $reply['narration'],
            'affinity' => $affinity,
        ];
    }

    /**
     * 스트리밍 채팅 응답. 대사를 토큰 단위로 $onDelta 콜백에 흘리고, 완료 후 최종 결과를 저장·반환한다.
     * 스트리밍 실패(키 없음/네트워크/HTTP 에러)는 비스트리밍 chat()으로 자동 폴백한다.
     *
     * @param  callable(string):void  $onDelta  대사 조각이 도착할 때마다 호출
     * @return array{message: string, narration: ?string, affinity: int}
     */
    public function replyStream(ChatSession $session, string $content, callable $onDelta): array
    {
        $character = $session->chatCharacter;

        if (! $this->gemini->hasApiKey()) {
            $message = ($character->name ?? '캐릭터').'입니다. (API 설정 후 이용해 주세요.)';
            $onDelta($message);
            $affinity = $this->persistCharacterReply($session, $message, null, null);

            return ['message' => $message, 'narration' => null, 'affinity' => $affinity];
        }

        [$character, $recentMessages, $summary] = $this->persistAndPrepare($session, $content);
        $memories = $this->retrieveMemories($session, $content);

        $systemPrompt = PromptBuilder::characterSystemStream($character, $memories);
        if (filled($summary)) {
            $systemPrompt .= "\n\n[이전 대화 요약]\n".trim($summary);
        }

        $contents = $this->buildContents($recentMessages, $content);

        // 스트리밍 중에는 대사(메타 마커 이전)만 델타로 흘린다. 마커 이후(narration/affinity)는 버퍼링해
        // 스트림 종료 후 파싱한다 — 부분 JSON 파싱을 피하는 노벨챗식 이벤트 분리 방식.
        $marker = PromptBuilder::STREAM_META_MARKER;
        $markerLen = strlen($marker);
        $full = '';
        $emitted = 0;
        $metaReached = false;

        $ok = $this->gemini->streamChat($systemPrompt, $contents, function (string $text) use (&$full, &$emitted, &$metaReached, $marker, $markerLen, $onDelta) {
            $full .= $text;
            if ($metaReached) {
                return;
            }
            $pos = strpos($full, $marker);
            if ($pos !== false) {
                $msgPart = substr($full, 0, $pos);
                if (strlen($msgPart) > $emitted) {
                    $onDelta(substr($msgPart, $emitted));
                    $emitted = strlen($msgPart);
                }
                $metaReached = true;

                return;
            }
            // 마커가 청크 경계에 걸릴 수 있어 마지막 (markerLen-1) 바이트는 보류한다.
            // 보류분은 완료 시 done 이벤트의 최종 message 로 클라이언트가 정합화한다.
            $safe = max(0, strlen($full) - ($markerLen - 1));
            // 멀티바이트(한글 등) 문자가 델타 경계에서 쪼개져 깨진 UTF-8이 나가지 않도록,
            // 안전 지점을 UTF-8 문자 시작 경계까지 뒤로 물린다(연속 바이트 0b10xxxxxx 는 건너뜀).
            while ($safe > $emitted && $safe < strlen($full) && (ord($full[$safe]) & 0xC0) === 0x80) {
                $safe--;
            }
            if ($safe > $emitted) {
                $onDelta(substr($full, $emitted, $safe - $emitted));
                $emitted = $safe;
            }
        }, 0.8, 2048);

        // 최종 대사/메타 분리.
        $pos = strpos($full, $marker);
        $message = trim($pos !== false ? substr($full, 0, $pos) : $full);
        $narration = null;
        $affinity = null;
        if ($pos !== false) {
            [$narration, $affinity] = $this->parseStreamMeta(substr($full, $pos + $markerLen));
        }

        // 스트리밍이 아무것도 못 받았으면 비스트리밍으로 폴백.
        if (! $ok || $message === '') {
            $reply = $this->chat($character, $summary, $recentMessages, $content, $memories);
            $message = $reply['message'];
            $narration = $reply['narration'];
            $affinity = $reply['affinity'];
            if ($message !== '') {
                $onDelta($message);
            }
        }

        $finalAffinity = $this->persistCharacterReply($session, $message, $narration, $affinity);

        return ['message' => $message, 'narration' => $narration, 'affinity' => $finalAffinity];
    }

    /**
     * 유저 메시지 저장 + (임계치 초과 시) 요약 압축 후, 모델에 넘길 최근 히스토리/요약을 준비한다.
     * reply()·replyStream() 공용. 요약이 새로 생기면 장기기억(RAG)에도 적재한다.
     *
     * @return array{0: ChatCharacter, 1: array<int, array{role: string, content: string}>, 2: ?string}
     */
    private function persistAndPrepare(ChatSession $session, string $content): array
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

                // 새 요약을 장기기억(RAG)으로 임베딩 저장 — 이후 관련 대화에서 검색 주입된다.
                $this->storeMemory($session, $newSummary);
            }
        }

        // 모델에 넘길 직전 히스토리: 미요약 메시지에서 방금 저장한 유저 메시지(마지막)는 제외.
        $recent = $this->unsummarizedAfter($messages, $summarizedId);
        $history = $recent->count() > 0 ? $recent->take($recent->count() - 1) : $recent;
        $recentMessages = $history->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])->values()->all();

        return [$character, $recentMessages, $session->conversation_summary];
    }

    /**
     * 캐릭터 응답을 저장하고 호감도를 반영한다(reply/replyStream 공용).
     */
    private function persistCharacterReply(ChatSession $session, string $message, ?string $narration, ?int $affinity): int
    {
        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'character',
            'content' => $message,
            'narration' => $narration,
        ]);

        // 모델이 호감도를 제시하면 세션에 반영 (없으면 기존값 유지)
        if ($affinity !== null) {
            $session->affinity = $affinity;
            $session->save();
        }

        return (int) $session->affinity;
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
     * 대화 히스토리 기반 채팅 응답 생성. 지문(narration)/대사(message)/호감도(affinity)로 구조화 반환.
     *
     * @return array{message: string, narration: ?string, affinity: ?int}
     */
    /**
     * @param  array<int, string>  $memories  장기기억(RAG)으로 검색된 관련 기억
     */
    public function chat(ChatCharacter $character, ?string $summary, array $recentMessages, string $userMessage, array $memories = []): array
    {
        if (! $this->gemini->hasApiKey()) {
            return [
                'message' => ($character->name ?? '캐릭터').'입니다. (API 설정 후 이용해 주세요.)',
                'narration' => null,
                'affinity' => null,
            ];
        }

        $systemPrompt = PromptBuilder::characterSystem($character, $memories);
        if (filled($summary)) {
            $systemPrompt .= "\n\n[이전 대화 요약]\n".trim($summary);
        }

        $contents = $this->buildContents($recentMessages, $userMessage);

        // JSON 모드 + 넉넉한 토큰으로 응답 잘림/코드펜스 깨짐 방지.
        $text = $this->gemini->chat($systemPrompt, $contents, json: true, maxOutputTokens: 2048);

        if ($text) {
            return GeminiResponseParser::parseReply($text);
        }

        return ['message' => '잠시 후 다시 말 걸어 주세요.', 'narration' => null, 'affinity' => null];
    }

    /**
     * 최근 히스토리 + 이번 유저 발화를 Gemini contents 배열로 변환한다.
     *
     * @param  array<int, array{role: string, content: string}>  $recentMessages
     * @return array<int, array{role: string, parts: array}>
     */
    private function buildContents(array $recentMessages, string $userMessage): array
    {
        return collect($recentMessages)
            ->map(fn ($m) => [
                'role' => ($m['role'] ?? '') === 'character' ? 'model' : 'user',
                'parts' => [['text' => trim((string) ($m['content'] ?? ''))]],
            ])
            ->push(['role' => 'user', 'parts' => [['text' => $userMessage]]])
            ->values()
            ->all();
    }

    /**
     * 스트리밍 메타 마커 뒤 문자열에서 narration/affinity 파싱. 실패 시 [null, null].
     *
     * @return array{0: ?string, 1: ?int}
     */
    private function parseStreamMeta(string $raw): array
    {
        // 잡텍스트/코드펜스 방어: 첫 '{' ~ 마지막 '}' 구간만 JSON 으로 파싱.
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end < $start) {
            return [null, null];
        }

        $data = json_decode(substr($raw, $start, $end - $start + 1), true);
        if (! is_array($data)) {
            return [null, null];
        }

        $narration = isset($data['narration']) && is_string($data['narration']) && trim($data['narration']) !== ''
            ? trim($data['narration'])
            : null;
        $affinity = isset($data['affinity']) && is_numeric($data['affinity'])
            ? max(0, min(100, (int) $data['affinity']))
            : null;

        return [$narration, $affinity];
    }

    /**
     * 요약을 임베딩해 장기기억(chat_memories)으로 저장. 장기기억 비활성이면 아무것도 안 한다.
     */
    private function storeMemory(ChatSession $session, string $summary): void
    {
        if (! $this->longTermMemory) {
            return;
        }

        ChatMemory::create([
            'chat_session_id' => $session->id,
            'chat_character_id' => $session->chat_character_id,
            'kind' => 'summary',
            'content' => $summary,
            'embedding' => $this->gemini->embed($summary, 'RETRIEVAL_DOCUMENT'),
        ]);
    }

    /**
     * 유저 발화와 관련된 장기기억을 코사인 유사도로 검색해 상위 K개 문장을 반환한다.
     * 장기기억 비활성/임베딩 실패 시 빈 배열(그냥 기억 없이 대화).
     *
     * @return array<int, string>
     */
    private function retrieveMemories(ChatSession $session, string $query, int $k = 3): array
    {
        if (! $this->longTermMemory) {
            return [];
        }

        $queryVec = $this->gemini->embed($query, 'RETRIEVAL_QUERY');
        if ($queryVec === null) {
            return [];
        }

        $rows = ChatMemory::query()
            ->where('chat_session_id', $session->id)
            ->whereNotNull('embedding')
            ->latest('id')
            ->limit(50)
            ->get(['content', 'embedding']);

        $scored = [];
        foreach ($rows as $row) {
            $vec = $row->embedding;
            if (! is_array($vec) || $vec === []) {
                continue;
            }
            $sim = $this->cosineSimilarity($queryVec, $vec);
            if ($sim >= self::MEMORY_SIMILARITY_MIN) {
                $scored[] = ['sim' => $sim, 'content' => (string) $row->content];
            }
        }

        usort($scored, fn ($a, $b) => $b['sim'] <=> $a['sim']);

        return array_map(fn ($x) => $x['content'], array_slice($scored, 0, $k));
    }

    /**
     * 코사인 유사도. 길이가 다르면 겹치는 앞부분만 비교하고, 0 벡터는 0을 반환한다.
     *
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $x = (float) $a[$i];
            $y = (float) $b[$i];
            $dot += $x * $y;
            $normA += $x * $x;
            $normB += $y * $y;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /**
     * 유저 추천 답변 생성 (최근 대화 흐름 기반).
     *
     * @return array<int, string>
     */
    public function suggestReplies(ChatSession $session): array
    {
        if (! $this->gemini->hasApiKey()) {
            return [];
        }

        $character = $session->chatCharacter;
        if (! $character) {
            return [];
        }

        $recent = $this->recentHistory($session);
        $text = $this->gemini->generate(PromptBuilder::suggestReplies($character, $recent), temperature: 0.9);

        return $text ? GeminiResponseParser::parseSuggestions($text) : [];
    }

    /**
     * 상황 묘사(지문) 생성 — 현재 장면을 이어 묘사하고 메시지로 저장 후 반환.
     */
    public function narrate(ChatSession $session): string
    {
        $character = $session->chatCharacter;
        if (! $character || ! $this->gemini->hasApiKey()) {
            return '';
        }

        $recent = $this->recentHistory($session);
        $text = $this->gemini->generate(PromptBuilder::narrate($character, $recent), temperature: 0.9);
        $narration = $text ? (GeminiResponseParser::parseNarration($text) ?? '') : '';

        if ($narration !== '') {
            ChatMessage::create([
                'chat_session_id' => $session->id,
                'role' => 'character',
                'content' => '',
                'narration' => $narration,
            ]);
        }

        return $narration;
    }

    /**
     * 추천답변/상황묘사 프롬프트에 넘길 최근 대화(요약 이후 미요약분).
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function recentHistory(ChatSession $session, int $limit = 10): array
    {
        return $session->messages()
            ->reorder('id', 'desc')
            ->take($limit)
            ->get()
            ->reverse()
            ->map(fn ($m) => ['role' => $m->role, 'content' => trim((string) ($m->content ?: $m->narration))])
            ->filter(fn ($m) => $m['content'] !== '')
            ->values()
            ->all();
    }

    /**
     * 소설/작품 정보를 분석해 캐릭터 페르소나 필드를 추출한다 (폼 자동 채우기용).
     *
     * @return array<string, string>
     */
    public function analyzePersona(string $source): array
    {
        if (! $this->gemini->hasApiKey()) {
            return [];
        }

        $prompt = PromptBuilder::analyzePersona(
            $source,
            array_keys(Genre::options()),
            array_keys(Target::options()),
        );

        // 페르소나 JSON은 항목이 많아 길다 → JSON 모드 + 넉넉한 토큰으로 잘림/깨짐 방지.
        $text = $this->gemini->generate($prompt, temperature: 0.8, json: true, maxOutputTokens: 3000);

        return $text ? GeminiResponseParser::parsePersona($text) : [];
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
