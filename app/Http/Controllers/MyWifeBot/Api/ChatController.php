<?php

namespace App\Http\Controllers\MyWifeBot\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatCharacter;
use App\Models\ChatSession;
use App\Services\Gemini\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
     * 로그인 세션은 user_id 가 일치해야 하고, 게스트 세션(user_id=null)은 게스트 요청자만 접근한다.
     * ((int) null === (int) null → 0 === 0 이므로 게스트끼리는 통과, 로그인/게스트 교차는 차단)
     */
    private function ownsSession(ChatSession $session): bool
    {
        return (int) $session->user_id === (int) auth()->id();
    }
}
