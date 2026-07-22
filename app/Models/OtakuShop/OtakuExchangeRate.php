<?php

namespace App\Models\OtakuShop;

use Illuminate\Database\Eloquent\Model;

/**
 * 해외 샵 가격 원화 환산용 환율(통화당 1행, 일 1회 갱신).
 */
class OtakuExchangeRate extends Model
{
    protected $table = 'otaku_exchange_rate';

    protected $primaryKey = 'ok_rate_id';

    const CREATED_AT = 'create_dt';

    const UPDATED_AT = 'update_dt';

    protected $fillable = [
        'ok_rate_currency',
        'ok_rate_krw',
    ];

    protected function casts(): array
    {
        return [
            'ok_rate_krw' => 'float',
        ];
    }
}
