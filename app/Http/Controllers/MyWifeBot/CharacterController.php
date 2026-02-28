<?php

namespace App\Http\Controllers\MyWifeBot;

use App\Http\Controllers\Controller;
use App\Models\ChatCharacter;
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
    public function add(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'character_name' => ['required', 'string', 'min:2', 'max:30'],
            'short_intro' => ['required', 'string', 'max:50'],
            'character_detail' => ['nullable', 'string', 'max:1000'],
            'speech_style' => ['nullable', 'string'],
            'intro_message' => ['nullable', 'string', 'max:1000'],
            'genre' => ['required', 'string', 'in:romance,fantasy,action,slice_of_life,otaku'],
            'target' => ['required', 'string', 'in:all,male,female,teen'],
            'character_image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ], [
            'character_name.required' => '캐릭터 이름을 입력하세요.',
            'character_name.min' => '캐릭터 이름은 2자 이상이어야 합니다.',
            'short_intro.required' => '한 줄 소개를 입력하세요.',
        ]);

        $this->characterService->add($validated, $request->file('character_image'));

        return redirect()->route('my-wife-bot.characters')
            ->with('message', '캐릭터가 추가되었습니다.');
    }

    /**
     * 챗봇 수정 폼 표시.
     */
    public function editForm(ChatCharacter $character): View
    {
        return view('my-wife-bot.character-form', [
            'character' => $character,
            'genres' => $this->characterService->getGenres(),
            'targets' => $this->characterService->getTargets(),
        ]);
    }

    /**
     * 캐릭터 페르소나 기반 첫 인사 생성 (Gemini API).
     */
    public function generateGreeting(ChatCharacter $character): JsonResponse
    {
        $greeting = $this->chatService->generateGreeting($character);

        return response()->json(['intro' => $greeting]);
    }

    /**
     * 챗봇 수정 저장.
     */
    public function save(Request $request, ChatCharacter $character): RedirectResponse
    {
        $validated = $request->validate([
            'character_name' => ['required', 'string', 'min:2', 'max:30'],
            'short_intro' => ['required', 'string', 'max:50'],
            'character_detail' => ['nullable', 'string', 'max:1000'],
            'speech_style' => ['nullable', 'string'],
            'intro_message' => ['nullable', 'string', 'max:1000'],
            'genre' => ['required', 'string', 'in:romance,fantasy,action,slice_of_life,otaku'],
            'target' => ['required', 'string', 'in:all,male,female,teen'],
            'character_image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $this->characterService->update($character, $validated, $request->file('character_image'));

        return redirect()->route('my-wife-bot.characters')
            ->with('message', '캐릭터가 수정되었습니다.');
    }

    /**
     * 챗봇 삭제.
     */
    public function remove(ChatCharacter $character): RedirectResponse
    {
        $this->characterService->remove($character);

        return redirect()->route('my-wife-bot.characters')
            ->with('message', '캐릭터가 삭제되었습니다.');
    }
}
