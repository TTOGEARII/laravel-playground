<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Services\User\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    public function __construct(
        private UserService $userService
    ) {}

    /**
     * 로그인한 사용자 전용 페이지 (Vue)
     */
    public function index(Request $request)
    {
        if (! Auth::check()) {
            return redirect()->route('login');
        }

        $user = $this->userService->getProfileForView();
        if ($user === null) {
            return redirect()->route('login');
        }

        return view('user.index', ['user' => $user]);
    }
}
