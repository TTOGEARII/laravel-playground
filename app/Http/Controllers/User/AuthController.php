<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Services\User\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function __construct(
        private AuthService $authService
    ) {}

    /**
     * 로그인 폼 (Vue)
     */
    public function showLoginForm()
    {
        if (Auth::check()) {
            return redirect()->route('user.index');
        }
        return view('auth.login');
    }

    /**
     * 로그인 처리
     */
    public function login(Request $request)
    {
        $request->validate(AuthService::loginValidationRules());

        $result = $this->authService->attemptLogin(
            $request->only('email', 'password'),
            (bool) $request->boolean('remember'),
            $request
        );

        if ($result['ok']) {
            return response()->json($result);
        }
        return response()->json($result, 422);
    }

    /**
     * 회원가입 폼 (Vue)
     */
    public function showRegisterForm()
    {
        if (Auth::check()) {
            return redirect()->route('user.index');
        }
        return view('auth.register');
    }

    /**
     * 회원가입 처리
     */
    public function register(Request $request)
    {
        $data = $request->validate(AuthService::registerValidationRules());

        $this->authService->register($data, $request);

        return response()->json(['ok' => true, 'redirect' => url('/')]);
    }

    /**
     * 로그아웃
     */
    public function logout(Request $request)
    {
        $this->authService->logout($request);
        return response()->json(['ok' => true, 'redirect' => url('/')]);
    }
}
