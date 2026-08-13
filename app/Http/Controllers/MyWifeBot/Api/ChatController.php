<?php

namespace App\Http\Controllers\MyWifeBot\Api;

use App\Http\Controllers\Controller;
use App\Models\MyWifeBot\ChatCharacter;
use App\Models\MyWifeBot\ChatSession;
use App\Services\Gemini\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public function __construct(
        private ChatService $chatService
    ) {}

    /**
     * 채팅 진입 시 세션 생성 + Gemini 인트로 생성 후 반환.
     * POST /api/my-wife-bot/chat/init { "character_id": "3" }
     */
    public function init(Request $request): JsonResponse
    {
        $characterId = $request->input('character_id') ?? $request->route('characterId');

        if (! $characterId) {
            return response()->json(['message' => 'character_id가 필요합니다.'], 422);
        }

        $character = ChatCharacter::find($characterId);

        if (! $character) {
            return response()->json(['message' => '캐릭터를 찾을 수 없습니다.'], 404);
        }

        // 대화 이어가기: 기존 세션이 있으면 재개, 없으면 새로 생성.
        $userId = auth()->id();
        $existing = $this->chatService->findResumableSession(
            $character,
            $request->input('session_id') ? (int) $request->input('session_id') : null,
            $userId,
        );

        $resumed = $existing !== null;
        $session = $existing ?? $this->chatService->initialize($character, $userId);

        // 게스트 세션은 만든 브라우저(라라벨 세션)에 묶어 둔다 — 순차 id 열거로 남의 대화에
        // 접근하는 IDOR 방지(로그인 세션은 user_id 로 소유 검증).
        if ($userId === null) {
            $this->rememberGuestSession($session->id);
        }

        $initialMessages = $session->messages()->get()->map(fn ($m) => [
            'role' => $m->role,
            'text' => $m->content,
            'narration' => $m->narration,
        ])->values()->all();

        return response()->json([
            'data' => [
                'session_id' => (string) $session->id,
                'initial_messages' => $initialMessages,
                'affinity' => (int) $session->affinity,
                'resumed' => $resumed,
            ],
        ]);
    }

    /**
     * 메시지 전송 → Gemini 응답 후 DB 저장 및 반환.
     * POST /api/my-wife-bot/chat/send { "session_id": "1", "content": "안녕" }
     */
    public function send(Request $request): JsonResponse
    {
        $request->validate([
            'session_id' => ['required', 'string'],
            'content' => ['required', 'string', 'max:2000'],
        ]);

        $session = ChatSession::with('chatCharacter')->find($request->input('session_id'));

        if (! $session) {
            return response()->json(['message' => '세션을 찾을 수 없습니다.'], 404);
        }

        if (! $this->ownsSession($session)) {
            return response()->json(['message' => '이 대화에 접근할 권한이 없습니다.'], 403);
        }

        if (! $session->chatCharacter) {
            return response()->json(['message' => '캐릭터를 찾을 수 없습니다.'], 404);
        }

        $content = trim($request->input('content'));
        if ($content === '') {
            return response()->json(['message' => '메시지를 입력하세요.'], 422);
        }

        $reply = $this->chatService->reply($session, $content);

        return response()->json([
            'data' => [
                'message' => [
                    'role' => 'character',
                    'text' => $reply['message'],
                    'narration' => $reply['narration'],
                ],
                'affinity' => $reply['affinity'],
            ],
        ]);
    }

    /**
     * 메시지 전송 → Gemini 응답을 SSE 로 스트리밍(토큰 단위).
     * POST /api/my-wife-bot/chat/send-stream { "session_id": "1", "content": "안녕" }
     *
     * 이벤트: delta{ text } (대사 조각) · done{ message, narration, affinity } (완료) · error{ message }.
     */
    public function sendStream(Request $request): StreamedResponse|JsonResponse
    {
        $request->validate([
            'session_id' => ['required', 'string'],
            'content' => ['required', 'string', 'max:2000'],
        ]);

        $session = ChatSession::with('chatCharacter')->find($request->input('session_id'));

        if (! $session) {
            return response()->json(['message' => '세션을 찾을 수 없습니다.'], 404);
        }

        if (! $this->ownsSession($session)) {
            return response()->json(['message' => '이 대화에 접근할 권한이 없습니다.'], 403);
        }

        if (! $session->chatCharacter) {
            return response()->json(['message' => '캐릭터를 찾을 수 없습니다.'], 404);
        }

        $content = trim($request->input('content'));
        if ($content === '') {
            return response()->json(['message' => '메시지를 입력하세요.'], 422);
        }

        // 스트리밍은 응답이 오래 걸린다 → 세션 파일 쓰기 잠금을 먼저 풀어(save) 같은 세션의 다른 요청
        // (추천답변·상황묘사 등)이 블로킹되지 않게 한다. 소유권 검증은 위에서 이미 끝냈다.
        session()->save();

        return response()->stream(function () use ($session, $content) {
            $emit = function (string $event, array $data): void {
                echo 'event: '.$event."\n";
                echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
            };

            try {
                $result = $this->chatService->replyStream($session, $content, function (string $delta) use ($emit) {
                    if ($delta !== '') {
                        $emit('delta', ['text' => $delta]);
                    }
                });

                $emit('done', [
                    'message' => $result['message'],
                    'narration' => $result['narration'],
                    'affinity' => $result['affinity'],
                ]);
            } catch (\Throwable $e) {
                report($e);
                $emit('error', ['message' => '응답을 받지 못했어요. 잠시 후 다시 시도해 주세요.']);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            // 운영 nginx 프록시가 응답을 버퍼링하면 스트리밍이 무의미해진다 → 이 응답만 버퍼링 비활성.
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * 유저 추천 답변 생성.
     * POST /api/my-wife-bot/chat/suggest { "session_id": "1" }
     */
    public function suggest(Request $request): JsonResponse
    {
        $session = $this->findSessionOrFail($request);
        if ($session instanceof JsonResponse) {
            return $session;
        }

        return response()->json([
            'data' => ['suggestions' => $this->chatService->suggestReplies($session)],
        ]);
    }

    /**
     * 상황 묘사(지문) 생성.
     * POST /api/my-wife-bot/chat/narrate { "session_id": "1" }
     */
    public function narrate(Request $request): JsonResponse
    {
        $session = $this->findSessionOrFail($request);
        if ($session instanceof JsonResponse) {
            return $session;
        }

        return response()->json([
            'data' => ['narration' => $this->chatService->narrate($session)],
        ]);
    }

    /**
     * session_id 검증 후 세션(캐릭터 포함) 반환. 실패 시 JsonResponse 반환.
     */
    private function findSessionOrFail(Request $request): ChatSession|JsonResponse
    {
        $request->validate(['session_id' => ['required', 'string']]);

        $session = ChatSession::with('chatCharacter')->find($request->input('session_id'));

        if (! $session) {
            return response()->json(['message' => '세션을 찾을 수 없습니다.'], 404);
        }

        if (! $this->ownsSession($session)) {
            return response()->json(['message' => '이 대화에 접근할 권한이 없습니다.'], 403);
        }

        if (! $session->chatCharacter) {
            return response()->json(['message' => '캐릭터를 찾을 수 없습니다.'], 404);
        }

        return $session;
    }

    /**
     * 요청자가 이 세션의 소유자인지 확인한다(IDOR 방지).
     * - 로그인 세션(user_id≠null): 로그인 상태 + user_id 일치.
     * - 게스트 세션(user_id=null): 비로그인 상태 + 이 세션을 만든 브라우저(라라벨 세션에 기록)여야 함.
     *   순차 정수 session_id 를 열거해 남의 게스트 대화에 접근하는 것을 막는다.
     */
    private function ownsSession(ChatSession $session): bool
    {
        if ($session->user_id !== null) {
            return auth()->check() && (int) $session->user_id === (int) auth()->id();
        }

        return ! auth()->check()
            && in_array($session->id, $this->guestSessionIds(), true);
    }

    /** 이 브라우저(라라벨 세션)가 만든 게스트 채팅 세션 id 목록. */
    private function guestSessionIds(): array
    {
        return array_map('intval', (array) session()->get('mwb.guest_sessions', []));
    }

    /** 게스트 세션 id 를 브라우저 세션에 기록(최근 50개만 유지). */
    private function rememberGuestSession(int $id): void
    {
        $ids = $this->guestSessionIds();
        if (! in_array($id, $ids, true)) {
            $ids[] = $id;
            session()->put('mwb.guest_sessions', array_slice($ids, -50));
        }
    }
}
