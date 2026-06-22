<?php

namespace App\Models\OtakuShop;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'ok_product_maker_code',
        'ok_product_maker_name',
        'ok_product_release_date',
        'ok_product_active_flg',
        'ok_product_cate_id',
        'ok_product_ip_id',
        'ok_product_image_url',
        'ok_product_match_sig',
    ];

    protected function casts(): array
    {
        return [
            'ok_product_release_date' => 'date',
            'ok_product_active_flg' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(OtakuCategory::class, 'ok_product_cate_id', 'ok_category_id');
    }

    public function ip(): BelongsTo
    {
        return $this->belongsTo(OtakuIp::class, 'ok_product_ip_id', 'ok_ip_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(OtakuOffer::class, 'ok_offer_product_id', 'ok_product_id');
    }
}
