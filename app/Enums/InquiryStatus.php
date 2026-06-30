<?php

namespace App\Enums;

/** 문의 처리 상태(운영자 관리용). */
enum InquiryStatus: string
{
    case Received = 'received';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Received => '접수됨',
            self::InProgress => '확인 중',
            self::Resolved => '처리 완료',
        };
    }
}
