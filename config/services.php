<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // 웹푸시(VAPID) — PWA 새 리딤코드 알림. 키가 비어 있으면 푸시 기능 전체 비활성(graceful).
    'webpush' => [
        'subject' => env('VAPID_SUBJECT', 'mailto:cagameku3842@gmail.com'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3-flash-preview'),
        // Gemini 3 계열 사고(thinking) 강도. low/minimal 로 낮추면 사고 토큰 소비가 줄어
        // 같은 출력 예산 안에서 실제 대사가 잘리는 현상을 막는다. (2.5 계열은 thinkingBudget 사용 → 빈 값으로)
        'thinking_level' => env('GEMINI_THINKING_LEVEL', 'low'),
    ],

    // 소셜 로그인(OAuth). client_id 가 비어 있으면 해당 제공자 로그인은 비활성으로 처리된다.
    // redirect 는 상대경로여도 되며, 인증자에서 url() 로 절대 URL 로 변환해 제공자 콘솔 등록값과 맞춘다.
    'kakao' => [
        'client_id' => env('KAKAO_REST_API_KEY'),
        'client_secret' => env('KAKAO_CLIENT_SECRET'),
        'redirect' => env('KAKAO_REDIRECT_URI', '/auth/kakao/callback'),
        // 이메일은 수집 권한이 없어(그리고 식별 코드로 대체하므로) 요청하지 않는다.
        'scope' => env('KAKAO_SCOPE', 'profile_nickname,profile_image'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
        'scope' => env('GOOGLE_SCOPE', 'openid email profile'),
    ],

];
