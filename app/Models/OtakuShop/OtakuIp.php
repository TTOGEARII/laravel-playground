<?php

namespace App\Models\OtakuShop;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OtakuIp extends Model
{
    protected $table = 'otaku_ip';

    protected $primaryKey = 'ok_ip_id';

    const CREATED_AT = 'create_dt';

    const UPDATED_AT = 'update_dt';

    protected $fillable = [
        'ok_ip_code',
        'ok_ip_label',
        'ok_ip_sort',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(OtakuProduct::class, 'ok_product_ip_id', 'ok_ip_id');
    }
}
