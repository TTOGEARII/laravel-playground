<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * 로그인하지 않은 방문자를 테트리스 대전의 참가자(presence 채널 멤버·컨트롤러 신원)로
 * 다루기 위한 경량 신원 객체. DB의 User 가 아니라 세션에 저장된 게스트 id/이름만 감싼다.
 *
 * id 는 음수를 써서 실제 회원(양수 PK)과 절대 충돌하지 않게 하고,
 * 프론트의 숫자 정렬(입장순 host 판별)과도 호환되게 한다.
 */
class GuestParticipant implements Authenticatable
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): int
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void
    {
        // 게스트는 remember token 이 없다(no-op).
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
