<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 수동 IP 차단 목록 1건. BlockExternalBots 미들웨어가 이 IP 를 403 으로 막는다.
 * 목록은 미들웨어에서 캐시(security:blocked_ips)로 읽으므로, 변경 시 캐시를 무효화한다.
 */
class BlockedIp extends Model
{
    public const CACHE_KEY = 'security:blocked_ips';

    protected $fillable = ['ip', 'reason'];
}
