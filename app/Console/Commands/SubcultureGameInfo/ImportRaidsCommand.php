<?php

namespace App\Console\Commands\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Game;
use App\Services\SubcultureGameInfo\Raids\RaidSyncService;
use Illuminate\Console\Command;

/**
 * 크롤 소스가 없는 게임(트릭컬/브더2 등)의 레이드·편성을 수동 JSON 으로 입력한다.
 * 파일 계약은 사이드카 출력과 동일: { "game": "slug", "items": [ {레이드...} ] }
 * 예시: database/data/raids/trickcal.json
 */
class ImportRaidsCommand extends Command
{
    protected $signature = 'subculture:import-raids {file : 레이드 JSON 파일 경로}';

    protected $description = '레이드·추천 편성 수동 JSON 파일을 DB 에 동기화(source=manual)';

    public function handle(RaidSyncService $sync): int
    {
        $path = $this->argument('file');
        if (! is_file($path)) {
            $this->error("파일 없음: {$path}");

            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded) || ! is_string($decoded['game'] ?? null) || ! is_array($decoded['items'] ?? null)) {
            $this->error('JSON 계약 불일치 — { "game": "slug", "items": [...] } 형식이어야 합니다.');

            return self::FAILURE;
        }

        $game = Game::where('slug', $decoded['game'])->first();
        if ($game === null) {
            $this->error("게임 없음: {$decoded['game']}");

            return self::FAILURE;
        }

        $stats = $sync->sync($game, 'manual', $decoded['items']);
        $this->table(['레이드', '편성', '멤버', '미매칭', '스킵'], [[
            $stats['raids'], $stats['parties'], $stats['members'], $stats['missing_members'], $stats['skipped'],
        ]]);
        $this->info('완료.');

        return self::SUCCESS;
    }
}
