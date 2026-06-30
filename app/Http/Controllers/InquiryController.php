<?php

namespace App\Http\Controllers;

use App\Enums\InquiryCategory;
use App\Http\Requests\StoreInquiryRequest;
use App\Models\Inquiry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * 사이트 공통 문의(1:1 문의/버그 제보/기능 요청). 누구나 남길 수 있다.
 */
class InquiryController extends Controller
{
    public function create(): View
    {
        return view('inquiry.create', [
            'categories' => InquiryCategory::cases(),
        ]);
    }

    public function store(StoreInquiryRequest $request): RedirectResponse
    {
        Inquiry::create([
            ...$request->validated(),
            'user_id' => Auth::id(),
            'ip_address' => $request->ip(),
        ]);

        return redirect()
            ->route('inquiry.create')
            ->with('inquiry_success', '문의가 정상적으로 접수되었습니다. 소중한 의견 감사합니다!');
    }
}
