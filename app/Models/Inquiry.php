<?php

namespace App\Models;

use App\Enums\InquiryCategory;
use App\Enums\InquiryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inquiry extends Model
{
    protected $fillable = [
        'category',
        'name',
        'contact',
        'subject',
        'message',
        'status',
        'user_id',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'category' => InquiryCategory::class,
            'status' => InquiryStatus::class,
        ];
    }

    /** 로그인 상태로 남긴 문의면 작성자. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
