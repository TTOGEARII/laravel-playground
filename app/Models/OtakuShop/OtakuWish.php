<?php

namespace App\Models\OtakuShop;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 오타쿠샵 찜(로그인 전용). 품절 상품 재입고 시 웹푸시 알림 대상.
 */
class OtakuWish extends Model
{
    protected $table = 'otaku_wish';

    protected $primaryKey = 'ok_wish_id';

    const CREATED_AT = 'create_dt';

    const UPDATED_AT = 'update_dt';

    protected $fillable = [
        'user_id',
        'ok_wish_product_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(OtakuProduct::class, 'ok_wish_product_id', 'ok_product_id');
    }
}
