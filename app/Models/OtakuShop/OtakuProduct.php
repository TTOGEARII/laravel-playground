<?php

namespace App\Models\OtakuShop;

use Illuminate\Database\Eloquent\Model;

class OtakuProduct extends Model
{
    protected $table = 'otaku_product';

    protected $primaryKey = 'ok_product_id';

    const CREATED_AT = 'create_dt';
    const UPDATED_AT = 'update_dt';

    protected $fillable = [
        'ok_product_code',
        'ok_product_title',
        'ok_product_subtitle',
        'ok_product_brand_label',
        'ok_product_release_date',
        'ok_product_active_flg',
    ];

    protected $casts = [
        'ok_product_release_date' => 'date',
        'ok_product_active_flg' => 'boolean',
    ];

    public function offers()
    {
        return $this->hasMany(OtakuOffer::class, 'ok_offer_product_id', 'ok_product_id');
    }
}
