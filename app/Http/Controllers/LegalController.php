<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * 약관·정책·라이센스 등 정적 안내 페이지.
 * 내용 갱신일은 한 곳(EFFECTIVE_DATE)에서 관리한다.
 */
class LegalController extends Controller
{
    /** 약관/정책 최종 개정일(표시용). 내용 바꿀 때 함께 갱신. */
    private const EFFECTIVE_DATE = '2026-07-07';

    public function terms(): View
    {
        return view('legal.terms', ['updated' => self::EFFECTIVE_DATE]);
    }

    public function privacy(): View
    {
        return view('legal.privacy', ['updated' => self::EFFECTIVE_DATE]);
    }

    public function license(): View
    {
        return view('legal.license', ['updated' => self::EFFECTIVE_DATE]);
    }
}
