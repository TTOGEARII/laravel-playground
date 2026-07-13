<?php

namespace App\Services\SubcultureAgent;

use App\Models\SubcultureAgent\AgentMessage;
use App\Models\SubcultureAgent\AgentSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * 서브컬쳐 게임 AI 에이전트 — Prism(Gemini) 툴콜링 루프.
 * 페르소나 시스템 프롬프트 + 도구 + 최근 대화 이력으로 답변을 만들고,
 * 도구가 누적한 카드(프론트 렌더용)를 함께 돌려준다. 대화는 세션에 영속화한다.
 */
class SubcultureAgentService
{
    /** 프롬프트에 실을 최근 대화 턴 수(토큰 관리). */
    private const HISTORY_LIMIT = 12;

    /** 툴 이름 → 진행 표시용 한글 라벨(스트리밍 칩). */
    private const TOOL_LABELS = [
        'search_redeem_codes' => '🎁 리딤코드 찾는 중…',
        'get_raids' => '⚔️ 레이드 정보 조회 중…',
        'search_characters' => '👤 캐릭터 검색 중…',
        'get_event_challenges' => '🎯 이벤트 챌린지 확인 중…',
        'get_guide_posts' => '📰 커뮤니티 공략글 수집 중…',
        'get_attribute_parties' => '🎭 속성별 조합 조회 중…',
        'search_community' => '🔍 커뮤니티 실시간 검색 중…',
        'fetch_live_page' => '🌐 최신 페이지 확인 중…',
    ];

    public function __construct(
        private PersonaResolver $persona,
        private AgentTools $tools,
    ) {}

    public function enabled(): bool
    {
        return filled(config('services.gemini.api_key'));
    }

    /**
     * 논스트리밍 대화(테스트·폴백용). 유저 메시지 저장 → 응답 생성 → 어시스턴트 메시지 저장.
     *
     * @return array{text: string, cards: array, tool_calls: array}
     */
    public function chat(AgentSession $session, string $userMessage, ?string $game = null): array
    {
        $this->rememberUserMessage($session, $userMessage);
        $result = $this->respond($session, $userMessage, $game);
        $this->rememberAssistantMessage($session, $result);

        return $result;
    }

    /**
     * SSE 스트리밍 대화. ['event' => tool|delta|done, 'data' => …] 를 순서대로 yield 한다.
     * 완료 시 어시스턴트 메시지를 영속화하고 done 이벤트에 카드·툴 기록을 싣는다.
     *
     * @return \Generator<int, array{event: string, data: array}>
     */
    public function stream(AgentSession $session, string $userMessage, ?string $game = null): \Generator
    {
        $this->rememberUserMessage($session, $userMessage);

        if (! $this->enabled()) {
            $result = $this->disabledResult();
            $this->rememberAssistantMessage($session, $result);
            yield ['event' => 'done', 'data' => $result];

            return;
        }

        $this->tools->cards = [];
        $this->tools->toolCalls = [];
        $text = '';

        try {
            $stream = $this->pendingRequest($session, $userMessage, $game)->asStream();

            foreach ($stream as $event) {
                if ($event instanceof ToolCallEvent) {
                    $name = $event->toolCall->name;
                    yield ['event' => 'tool', 'data' => [
                        'name' => $name,
                        'label' => self::TOOL_LABELS[$name] ?? "🔧 {$name} 실행 중…",
                    ]];
                } elseif ($event instanceof TextDeltaEvent) {
                    $text .= $event->delta;
                    yield ['event' => 'delta', 'data' => ['text' => $event->delta]];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[SGA] 스트리밍 응답 실패', ['error' => $e->getMessage(), 'session' => $session->id]);
        }

        if (trim($text) === '') {
            $text = '앗, 답변을 만들다 문제가 생겼어요. 잠시 후 다시 물어봐 주세요.';
            yield ['event' => 'delta', 'data' => ['text' => $text]];
        }

        $result = [
            'text' => trim($text),
            'cards' => $this->dedupeCards($this->tools->cards),
            'tool_calls' => $this->tools->toolCalls,
        ];
        $this->rememberAssistantMessage($session, $result);

        yield ['event' => 'done', 'data' => ['cards' => $result['cards'], 'tool_calls' => $result['tool_calls']]];
    }

    /**
     * @return array{text: string, cards: array, tool_calls: array}
     */
    private function respond(AgentSession $session, string $userMessage, ?string $game = null): array
    {
        if (! $this->enabled()) {
            return $this->disabledResult();
        }

        $this->tools->cards = [];
        $this->tools->toolCalls = [];

        try {
            $response = $this->pendingRequest($session, $userMessage, $game)->asText();
            $text = trim($response->text);
            if ($text === '') {
                $text = '음… 지금은 답변을 만들지 못했어요. 질문을 조금 더 구체적으로 해주실 수 있을까요?';
            }
        } catch (\Throwable $e) {
            Log::warning('[SGA] 에이전트 응답 실패', ['error' => $e->getMessage(), 'session' => $session->id]);
            $text = '앗, 정보를 가져오다 문제가 생겼어요. 잠시 후 다시 물어봐 주세요.';
        }

        return [
            'text' => $text,
            'cards' => $this->dedupeCards($this->tools->cards),
            'tool_calls' => $this->tools->toolCalls,
        ];
    }

    private function pendingRequest(AgentSession $session, string $userMessage, ?string $game = null): \Prism\Prism\Text\PendingRequest
    {
        $request = Prism::text()
            ->using('gemini', (string) config('services.gemini.model', 'gemini-3-flash-preview'))
            ->withSystemPrompt($this->persona->systemPrompt($session, $game))
            ->withMessages($this->history($session, $userMessage))
            ->withTools($this->tools->all())
            ->withMaxSteps((int) config('subculture-agent.max_steps', 5));

        // 기존 GeminiService 와 동일하게 사고(thinking) 강도를 낮춰 비용·지연을 관리
        $thinking = trim((string) config('services.gemini.thinking_level', ''));
        if ($thinking !== '') {
            $request->withProviderOptions(['thinkingLevel' => $thinking]);
        }

        return $request;
    }

    private function rememberUserMessage(AgentSession $session, string $message): void
    {
        $session->messages()->create(['role' => 'user', 'content' => $message]);
        if ($session->title === null) {
            $session->update(['title' => Str::limit($message, 40)]);
        } else {
            $session->touch();
        }
    }

    /** @param array{text: string, cards: array, tool_calls: array} $result */
    private function rememberAssistantMessage(AgentSession $session, array $result): AgentMessage
    {
        return $session->messages()->create([
            'role' => 'assistant',
            'content' => $result['text'],
            'tool_calls' => $result['tool_calls'],
            'cards' => $result['cards'],
        ]);
    }

    /** 같은 툴 재호출로 쌓인 동일 카드를 제거한다. */
    private function dedupeCards(array $cards): array
    {
        return collect($cards)
            ->unique(fn (array $c) => $c['type'].'|'.md5(json_encode($c['data'])))
            ->values()
            ->all();
    }

    private function disabledResult(): array
    {
        return [
            'text' => '지금은 AI 기능이 꺼져 있어요(서버에 Gemini 키 미설정). 잠시 후 다시 시도해 주세요.',
            'cards' => [],
            'tool_calls' => [],
        ];
    }

    /**
     * 저장된 최근 메시지(이번 유저 메시지 제외)를 Prism 메시지 배열로.
     *
     * @return array<int, UserMessage|AssistantMessage>
     */
    private function history(AgentSession $session, string $userMessage): array
    {
        // messages() 관계에 기본 orderBy(id asc)가 걸려 있어 latest()가 무시된다 — reorder 로 교체
        $messages = $session->messages()
            ->reorder('id', 'desc')->limit(self::HISTORY_LIMIT)->get()
            ->sortBy('id')
            ->map(fn (AgentMessage $m) => $m->role === 'assistant'
                ? new AssistantMessage((string) $m->content)
                : new UserMessage((string) $m->content))
            ->values()
            ->all();

        // rememberUserMessage 로 방금 저장된 마지막 유저 메시지가 이미 포함돼 있으면 그대로,
        // (respond 를 단독 호출한 테스트 경로처럼) 없으면 덧붙인다.
        $last = end($messages);
        if (! $last instanceof UserMessage || $last->text() !== $userMessage) {
            $messages[] = new UserMessage($userMessage);
        }

        return $messages;
    }
}
