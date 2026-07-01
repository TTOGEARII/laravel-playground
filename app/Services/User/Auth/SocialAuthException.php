<?php

namespace App\Services\User\Auth;

use RuntimeException;

/**
 * 소셜 로그인 처리 중 사용자에게 노출 가능한 실패(토큰 발급 실패·프로필 조회 실패 등).
 * 컨트롤러가 이 예외를 잡아 로그인 화면으로 안전하게 되돌린다.
 */
class SocialAuthException extends RuntimeException {}
