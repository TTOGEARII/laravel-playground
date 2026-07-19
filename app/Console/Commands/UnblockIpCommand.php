<?php

namespace App\Console\Commands;

use App\Models\BlockedIp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * IP 를 차단 목록에서 해제한다. 예: sail artisan ip:unblock 64.89.161.83
 */
class UnblockIpCommand extends Command
{
    protected $signature = 'ip:unblock {ip : 해제할 IP}';

    protected $description = '차단 목록에서 IP 를 제거';

    public function handle(): int
    {
        $ip = trim((string) $this->argument('ip'));
        $deleted = BlockedIp::where('ip', $ip)->delete();

        Cache::forget(BlockedIp::CACHE_KEY);

        if ($deleted === 0) {
            $this->warn("차단 목록에 없는 IP 입니다: {$ip}");

            return self::SUCCESS;
        }

        $this->info("차단 해제 — {$ip}");

        return self::SUCCESS;
    }
}
