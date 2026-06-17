<?php

namespace App\Models\MiniGame;

use Illuminate\Database\Eloquent\Model;

class Game extends Model
{
    protected $fillable = [
        'name',
        'description',
        'score',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
        ];
    }
}
