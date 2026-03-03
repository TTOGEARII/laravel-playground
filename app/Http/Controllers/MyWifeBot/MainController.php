<?php

namespace App\Http\Controllers\MyWifeBot;

use App\Http\Controllers\Controller;
use App\Models\ChatCharacter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class MainController extends Controller
{
    /**
     * 캐릭터 모아보기 (전체 리스트). 수정/삭제 버튼 없음.
     */
    public function characters(): View
    {
        $characters = ChatCharacter::orderByDesc('created_at')->get()->map(fn ($c) => $this->characterToRow($c));

        return view('my-wife-bot.characters', [
            'characters' => $characters,
            'showActions' => false,
            'title' => '캐릭터 모아보기',
            'lead' => '대화하고 싶은 캐릭터를 골라 보세요.',
        ]);
    }

    /**
     * 내 챗봇 관리 (로그인 사용자 본인 캐릭터만). 수정/삭제 버튼 표시.
     */
    public function myCharacters(): View|RedirectResponse
    {
        $user = Auth::user();
        if (! $user) {
            return redirect()->route('my-wife-bot.characters');
        }

        $characters = ChatCharacter::ownedBy($user->id)->orderByDesc('created_at')->get()->map(fn ($c) => $this->characterToRow($c));

        return view('my-wife-bot.characters', [
            'characters' => $characters,
            'showActions' => true,
            'title' => '내 챗봇 관리',
            'lead' => '내가 만든 챗봇을 수정하거나 삭제할 수 있어요.',
        ]);
    }

    private function characterToRow(ChatCharacter $c): array
    {
        return [
            'id' => (string) $c->id,
            'name' => $c->name,
            'description' => $c->short_intro . ($c->character_detail ? ' ' . \Str::limit($c->character_detail, 80) : ''),
            'image' => $c->image_url ?? '',
            'accent' => $c->accent ?? 'accent-violet',
        ];
    }

    /**
     * 대화하기. 캐릭터 정보만 전달. 인트로는 Vue에서 API(/api/my-wife-bot/chat/init) 호출로 조회.
     */
    public function chat(string $characterId): View|RedirectResponse
    {
        $character = ChatCharacter::find($characterId);

        if (! $character) {
            return redirect()->route('my-wife-bot.characters');
        }

        $characterArray = $character->toCharacterArray();
        $imgUrl = $character->image_url;
        $characterArray['image'] = $imgUrl ? (str_starts_with($imgUrl, 'http') ? $imgUrl : url($imgUrl)) : '';

        return view('my-wife-bot.chat', [
            'character' => $characterArray,
        ]);
    }
}
