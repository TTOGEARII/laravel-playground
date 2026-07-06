<?php

namespace App\Models\SubcultureGameInfo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    protected $table = 'subculture_games';

    protected $fillable = [
        'slug',
        'name',
        'publisher',
        'icon',
        'color',
        'redeem_url_template',
        'redeem_note',
        'region_default',
        'sort',
        'active_flg',
    ];

    protected function casts(): array
    {
        return [
            'active_flg' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function codes(): HasMany
    {
        return $this->hasMany(RedeemCode::class, 'subculture_game_id');
    }

    public function characters(): HasMany
    {
        return $this->hasMany(Character::class, 'subculture_game_id');
    }

    public function raids(): HasMany
    {
        return $this->hasMany(Raid::class, 'subculture_game_id');
    }

    public function guidePosts(): HasMany
    {
        return $this->hasMany(GuidePost::class, 'subculture_game_id');
    }

    /**
     * 원클릭 교환 직링크. 템플릿에 {code} 가 있으면 치환, 없으면 그대로 반환.
     * 인게임 전용(템플릿 null)이면 null.
     */
    public function redeemUrlFor(string $code): ?string
    {
        if (empty($this->redeem_url_template)) {
            return null;
        }

        return str_contains($this->redeem_url_template, '{code}')
            ? str_replace('{code}', rawurlencode($code), $this->redeem_url_template)
            : $this->redeem_url_template;
    }
}
