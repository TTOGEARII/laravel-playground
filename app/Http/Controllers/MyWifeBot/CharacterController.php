<?php

namespace App\Http\Controllers\MyWifeBot;

use App\Http\Controllers\Controller;
use App\Http\Requests\MyWifeBot\CharacterRequest;
use App\Models\MyWifeBot\ChatCharacter;
use App\Services\Gemini\ChatService;
use App\Services\MyWifeBot\CharacterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CharacterController extends Controller
{
    public function __construct(
        private CharacterService $characterService,
        private ChatService $chatService
    ) {}

    /**
     * 챗봇 추가 폼 표시.
     */
    public function addForm(): View
    {
        return view('my-wife-bot.character-form', [
            'character' => null,
            'genres' => $this->characterService->getGenres(),
            'targets' => $this->characterService->getTargets(),
        ]);
    }

    /**
     * 챗봇 추가 처리.
     */
    public function add(CharacterRequest $request): RedirectResponse
    {
        $this->characterService->add($request->validated(), $request->file('character_image'));

        if (auth()->check()) {
            return redirect()->route('my-wife-bot.my-characters')->with('message', '캐릭터가 추가되었습니다.');
        }

        return redirect()->route('my-wife-bot.characters')->with('message', '캐릭터가 추가되었습니다.');
    }

    /**
     * 챗봇 수정 폼 표시. (본인 캐릭터만)
     */
    public function editForm(ChatCharacter $character): View|RedirectResponse
    {
        if (! $this->canManageCharacter($character)) {
            return redirect()->route('my-wife-bot.characters')->with('message', '수정 권한이 없습니다.');
        }

        return view('my-wife-bot.character-form', [
            'character' => $character,
            'genres' => $this->characterService->getGenres(),
            'targets' => $this->characterService->getTargets(),
        ]);
    }

    /**
     * 소설/작품 정보를 AI로 분석해 페르소나 폼 필드를 자동 채운다.
     * POST /my-wife-bot/characters/analyze { "source": "..." }
     */
    public function analyze(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'min:10', 'max:6000'],
        ]);

        return response()->json(['persona' => $this->chatService->analyzePersona($data['source'])]);
    }

    /**
     * 캐릭터 페르소나 기반 첫 인사 생성 (Gemini API). (본인 캐릭터만)
     */
    public function generateGreeting(ChatCharacter $character): JsonResponse
    {
        if (! $this->canManageCharacter($character)) {
            return response()->json(['intro' => ''], 403);
        }
        $greeting = $this->chatService->generateGreeting($character);

        return response()->json(['intro' => $greeting]);
    }

    /**
     * 챗봇 수정 저장. (본인 캐릭터만)
     */
    public function save(CharacterRequest $request, ChatCharacter $character): RedirectResponse
    {
        if (! $this->canManageCharacter($character)) {
            return redirect()->route('my-wife-bot.characters')->with('message', '수정 권한이 없습니다.');
        }

        $this->characterService->update($character, $request->validated(), $request->file('character_image'));

        return redirect()->route('my-wife-bot.my-characters')
            ->with('message', '캐릭터가 수정되었습니다.');
    }

    /**
     * 챗봇 삭제. (본인 캐릭터만)
     */
    public function remove(ChatCharacter $character): RedirectResponse
    {
        if (! $this->canManageCharacter($character)) {
            return redirect()->route('my-wife-bot.characters')->with('message', '삭제 권한이 없습니다.');
        }
        $this->characterService->remove($character);

        return redirect()->route('my-wife-bot.my-characters')
            ->with('message', '캐릭터가 삭제되었습니다.');
    }

    /**
     * 현재 사용자가 해당 캐릭터를 수정/삭제할 수 있는지 (소유자만).
     */
    private function canManageCharacter(ChatCharacter $character): bool
    {
        $userId = auth()->id();
        if ($userId === null) {
            return false;
        }

        return (int) $character->user_id === (int) $userId;
    }
}
