<?php

namespace App\Console\Commands;

use App\Models\BlockedIp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * IP 를 차단 목록에 등록한다. BlockExternalBots 미들웨어가 이 IP 를 403 으로 막는다.
 * 예: sail artisan ip:block 64.89.161.83 --reason="LFI·XSS 스캔"
 */
class BlockIpCommand extends Command
{
    protected $signature = 'ip:block {ip : 차단할 IP} {--reason= : 차단 사유}';

    protected $description = '접속 로그에서 찾은 공격 IP 를 차단 목록에 등록';

    public function handle(): int
    {
        $ip = trim((string) $this->argument('ip'));

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $this->error("올바른 IP 가 아닙니다: {$ip}");

            return self::FAILURE;
        }

        $record = BlockedIp::updateOrCreate(
            ['ip' => $ip],
            ['reason' => $this->option('reason') ?: null],
        );

        Cache::forget(BlockedIp::CACHE_KEY); // 미들웨어 캐시 즉시 무효화

        $this->info(($record->wasRecentlyCreated ? '차단 등록' : '차단 갱신')." — {$ip}".
            ($record->reason ? " ({$record->reason})" : ''));

        return self::SUCCESS;
    }
}
