<?php

namespace App\Models\OtakuShop;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OtakuCategory extends Model
{
    protected $table = 'otaku_category';

    protected $primaryKey = 'ok_category_id';

    const CREATED_AT = 'create_dt';

    const UPDATED_AT = 'update_dt';

    protected $fillable = [
        'ok_category_code',
        'ok_category_label',
        'ok_category_sort',
    ];

    protected function casts(): array
    {
        return [
            'ok_category_sort' => 'integer',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(OtakuProduct::class, 'ok_product_cate_id', 'ok_category_id');
    }
}
