<?php

namespace App\Http\Controllers\MyWifeBot;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class MainController extends Controller
{
    /**
     * 캐릭터 모아보기 (crack.wrtn.ai/characters 스타일).
     */
    public function characters(): View
    {
        $characters = [
            [
                'id' => 'tomoro',
                'name' => '에비즈카 토모',
                'description' => '본래 유명한 상류층 집안의 영애이지만, 뭔가 사정이 생겨 현재는 독립해 룸 셰어 아파트에서 살고 있다. 갈등으로 인해 부모님과의 사이가 틀어져 버린 듯.이러한 사정에 영향을 받아서인지 고슴도치처럼 경계심이 강해 마음을 잘 열지 않는다.',
                'image' => 'images/131544476_p0.jpg',
                'accent' => 'accent-pink',
            ],
        ];

        return view('my-wife-bot.characters', [
            'characters' => $characters,
        ]);
    }
}
