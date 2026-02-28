<?php

namespace App\Http\Controllers\MyWifeBot\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatCharacter;
use App\Models\ChatMessage;
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

        $session = ChatSession::create(['chat_character_id' => $character->id]);
        $introText = $this->chatService->getIntroMessage($character);

        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'character',
            'content' => $introText,
        ]);

        $initialMessages = $session->messages()->get()->map(fn ($m) => [
            'role' => $m->role,
            'text' => $m->content,
        ])->values()->all();

        return response()->json([
            'data' => [
                'session_id' => (string) $session->id,
                'initial_messages' => $initialMessages,
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

        $character = $session->chatCharacter;
        if (! $character) {
            return response()->json(['message' => '캐릭터를 찾을 수 없습니다.'], 404);
        }

        $content = trim($request->input('content'));
        if ($content === '') {
            return response()->json(['message' => '메시지를 입력하세요.'], 422);
        }

        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'user',
            'content' => $content,
        ]);

        $allMessages = $session->messages()->orderBy('id')->get();
        $summarizedId = $session->summarized_until_message_id;
        $threshold = $this->chatService->getSummaryThreshold();

        $unsummarized = $summarizedId === null
            ? $allMessages
            : $allMessages->filter(fn ($m) => $m->id > $summarizedId)->values();

        if ($unsummarized->count() > $threshold) {
            $chunk = $unsummarized->take($threshold);
            $toSummarize = $chunk->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])->all();
            $newSummary = $this->chatService->summarize($toSummarize, $session->conversation_summary);

            if ($newSummary !== '') {
                $lastId = $chunk->last()->id;
                $session->conversation_summary = trim(
                    ($session->conversation_summary ? $session->conversation_summary . "\n" : '') . $newSummary
                );
                $session->summarized_until_message_id = $lastId;
                $session->save();
            }
        }

        $recentForApi = $summarizedId === null
            ? $allMessages
            : $allMessages->filter(fn ($m) => $m->id > $summarizedId);

        $recentForApi = $recentForApi->values();
        $historyOnly = $recentForApi->count() > 0 ? $recentForApi->take($recentForApi->count() - 1) : $recentForApi;
        $recentMessages = $historyOnly->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])->values()->all();
        $newUserMessage = $content;

        $reply = $this->chatService->chat(
            $character,
            $session->fresh()->conversation_summary,
            $recentMessages,
            $newUserMessage
        );

        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'character',
            'content' => $reply,
        ]);

        return response()->json([
            'data' => [
                'message' => ['role' => 'character', 'text' => $reply],
            ],
        ]);
    }
}
