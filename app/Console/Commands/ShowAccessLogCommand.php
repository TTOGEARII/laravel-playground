<?php

namespace App\Console\Commands;

use App\Models\AccessLog;
use Illuminate\Console\Command;

class ShowAccessLogCommand extends Command
{
    protected $signature = 'access:log
        {--limit=30 : 최근 몇 건}
        {--days=7 : 요약 집계 기간(일)}
        {--device= : pc|mobile|tablet|bot 필터}';

    protected $description = '외부 유저 접속 로그 조회 — 최근 방문 + 기기·유입경로 요약';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $since = now()->subDays($days);
        $base = AccessLog::where('created_at', '>=', $since)
            ->when($this->option('device'), fn ($q, $d) => $q->where('device', $d));

        $this->info("최근 {$days}일 요약");
        $this->line('총 방문: '.(clone $base)->count().'건');

        $this->line("\n기기별:");
        $this->table(['기기', '건수'], (clone $base)
            ->selectRaw('device, count(*) as c')->groupBy('device')->orderByDesc('c')->get()
            ->map(fn ($r) => [$r->device, $r->c]));

        $this->line('외부 유입경로 Top(자기 사이트 제외):');
        $host = parse_url(config('app.url'), PHP_URL_HOST);
        $this->table(['유입경로', '건수'], (clone $base)
            ->whereNotNull('referrer')
            ->when($host, fn ($q) => $q->where('referrer', 'not like', "%{$host}%"))
            ->selectRaw('referrer, count(*) as c')->groupBy('referrer')->orderByDesc('c')->limit(10)->get()
            ->map(fn ($r) => [mb_strimwidth($r->referrer, 0, 60, '…'), $r->c]));

        $this->line("\n최근 방문 {$this->option('limit')}건:");
        $this->table(['시각', '기기', 'IP', '경로', '유입'], (clone $base)
            ->latest('created_at')->limit((int) $this->option('limit'))->get()
            ->map(fn (AccessLog $l) => [
                $l->created_at?->format('m-d H:i'),
                $l->device,
                $l->ip,
                mb_strimwidth((string) $l->path, 0, 34, '…'),
                mb_strimwidth((string) ($l->referrer ?? '-'), 0, 34, '…'),
            ]));

        return self::SUCCESS;
    }
}
