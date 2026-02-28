<?php

namespace App\Http\Controllers\MyWifeBot;

use App\Http\Controllers\Controller;
use App\Models\ChatCharacter;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class MainController extends Controller
{

    /**
     * 캐릭터 모아보기 (crack.wrtn.ai/characters 스타일).
     */
    public function characters(): View
    {
        $characters = ChatCharacter::orderByDesc('created_at')->get()->map(fn ($c) => [
            'id' => (string) $c->id,
            'name' => $c->name,
            'description' => $c->short_intro . ($c->character_detail ? ' ' . \Str::limit($c->character_detail, 80) : ''),
            'image' => $c->image_url ?? '',
            'accent' => $c->accent ?? 'accent-violet',
        ]);

        return view('my-wife-bot.characters', [
            'characters' => $characters,
        ]);
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
