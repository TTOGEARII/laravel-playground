<?php

namespace App\Http\Controllers\OtakuShop;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class MainController extends Controller
{
    /** 국내관(기본) — 국내 샵 오퍼 보유 상품. */
    public function index(): View
    {
        return view('otaku-shop.index', ['region' => 'kr']);
    }

    /** 해외관 — 해외 샵(아미아미 등) 오퍼 보유 상품. ¥원가+₩환산 병기. */
    public function global(): View
    {
        return view('otaku-shop.index', ['region' => 'global']);
    }
}
