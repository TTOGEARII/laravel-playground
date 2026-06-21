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

        $session = $this->chatService->initialize($character);

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

        if (! $session->chatCharacter) {
            return response()->json(['message' => '캐릭터를 찾을 수 없습니다.'], 404);
        }

        return $session;
    }
}
