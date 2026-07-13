<?php

namespace App\Http\Controllers\SubcultureGameInfo;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * 서브컬쳐 게임 허브 — 진입 랜딩.
 * 리딤코드 / 정보검색 두 갈래로 나눠 들어가는 선택 화면.
 * (AI 에이전트는 정보검색 안의 '물어보기'로 임베드되므로 여기서는 2선택.)
 */
class HubController extends Controller
{
    public function index(): View
    {
        return view('subculture-game-info.hub');
    }
}
