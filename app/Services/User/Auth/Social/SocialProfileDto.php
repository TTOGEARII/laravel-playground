<?php

namespace App\Services\User\Auth\Social;

use Carbon\CarbonInterface;

/**
 * 제공자(카카오/구글)마다 다른 응답을 표준화한 프로필. find-or-create 에 이 형태로만 넘긴다.
 */
class SocialProfileDto
{
    public function __construct(
        public readonly string $provider,
        public readonly string $providerUserId,
        public readonly ?string $email = null,
        public readonly ?string $nickname = null,
        public readonly ?string $profileImage = null,
        public readonly ?string $accessToken = null,
        public readonly ?string $refreshToken = null,
        public readonly ?CarbonInterface $tokenExpiresAt = null,
    ) {}
}
