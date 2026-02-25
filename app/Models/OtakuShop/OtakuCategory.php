<?php

namespace App\Models\OtakuShop;

use Illuminate\Database\Eloquent\Model;

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

    protected $casts = [
        'ok_category_sort' => 'integer',
    ];
}

