<?php

namespace App\Services\User;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

class UserService
{
    /**
     * 현재 로그인 사용자 반환 (미인증 시 null)
     */
    public function getCurrentUser(): ?Authenticatable
    {
        return Auth::user();
    }

    /**
     * 마이페이지 등에 노출할 사용자 정보 배열
     * 모델의 toProfileArray() 사용
     */
    public function getProfileForView(): ?array
    {
        $user = $this->getCurrentUser();
        if (! $user instanceof User) {
            return null;
        }
        return $user->toProfileArray();
    }
}
