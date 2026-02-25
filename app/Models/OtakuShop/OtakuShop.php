<?php

namespace App\Models\OtakuShop;

use Illuminate\Database\Eloquent\Model;

class OtakuShop extends Model
{
    protected $table = 'otaku_shop';

    protected $primaryKey = 'ok_shop_id';

    const CREATED_AT = 'create_dt';
    const UPDATED_AT = 'update_dt';

    protected $fillable = [
        'ok_shop_code',
        'ok_shop_name',
        'ok_shop_url',
        'ok_shop_active_flg',
    ];

    protected $casts = [
        'ok_shop_active_flg' => 'boolean',
    ];

    public function offers()
    {
        return $this->hasMany(OtakuOffer::class, 'ok_offer_shop_id', 'ok_shop_id');
    }
}

