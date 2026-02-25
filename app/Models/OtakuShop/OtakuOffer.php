<?php

namespace App\Models\OtakuShop;

use Illuminate\Database\Eloquent\Model;

class OtakuOffer extends Model
{
    protected $table = 'otaku_offer';

    protected $primaryKey = 'ok_offer_id';

    const CREATED_AT = 'create_dt';
    const UPDATED_AT = 'update_dt';

    protected $fillable = [
        'ok_offer_product_id',
        'ok_offer_shop_id',
        'ok_offer_currency',
        'ok_offer_price',
        'ok_offer_local_price',
        'ok_offer_shipping_fee',
        'ok_offer_lowest_flg',
        'ok_offer_available_flg',
        'ok_offer_external_url',
        'ok_offer_collected_dt',
    ];

    protected $casts = [
        'ok_offer_price' => 'decimal:2',
        'ok_offer_local_price' => 'decimal:2',
        'ok_offer_shipping_fee' => 'decimal:2',
        'ok_offer_lowest_flg' => 'boolean',
        'ok_offer_available_flg' => 'boolean',
        'ok_offer_collected_dt' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(OtakuProduct::class, 'ok_offer_product_id', 'ok_product_id');
    }

    public function shop()
    {
        return $this->belongsTo(OtakuShop::class, 'ok_offer_shop_id', 'ok_shop_id');
    }
}

