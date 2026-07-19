<?php

namespace App\Console\Commands;

use App\Models\BlockedIp;
use Illuminate\Console\Command;

/**
 * 차단 IP 목록을 표로 본다. 예: sail artisan ip:blocklist
 */
class ListBlockedIpsCommand extends Command
{
    protected $signature = 'ip:blocklist';

    protected $description = '차단 IP 목록 보기';

    public function handle(): int
    {
        $rows = BlockedIp::orderByDesc('created_at')->get();

        if ($rows->isEmpty()) {
            $this->info('차단된 IP 가 없습니다.');

            return self::SUCCESS;
        }

        $this->table(
            ['IP', '사유', '등록 일시'],
            $rows->map(fn (BlockedIp $b) => [
                $b->ip,
                $b->reason ?? '-',
                (string) $b->created_at,
            ])->all(),
        );

        $this->info('총 '.$rows->count().'개 차단 중');

        return self::SUCCESS;
    }
}
