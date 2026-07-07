<?php

namespace App\Console\Commands\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Game;
use App\Services\SubcultureGameInfo\Raids\SubstituteExtractionService;
use Illuminate\Console\Command;

/**
 * 대체 캐릭터 관계를 수동 JSON 으로 입력한다(니케처럼 커뮤니티 추출 재료가 빈약한 게임용).
 * 캐릭터는 이름으로 적는다(공백·콜론 차이는 자동 정규화, 마스터에 없는 이름은 미매칭 보고).
 * 재실행 멱등 — 해당 레이드의 manual 행을 파일 내용으로 갈아끼운다.
 *
 * 파일 계약 예시(database/data/substitutes/nikke-soloraid-6.json):
 * {
 *   "game": "nikke",
 *   "raid": "soloraid-6",
 *   "substitutes": [
 *     { "character": "나유타", "substitutes": ["신데렐라", "홍련 : 흑영"], "note": "풀돌 기준" }
 *   ]
 * }
 */
class ImportSubstitutesCommand extends Command
{
    protected $signature = 'subculture:import-substitutes {file : 대체 관계 JSON 파일 경로}';

    protected $description = '대체 캐릭터 관계 수동 JSON 파일을 DB 에 동기화(source=manual, 레이드 단위 갈아끼움)';

    public function handle(SubstituteExtractionService $service): int
    {
        $path = $this->argument('file');
        if (! is_file($path)) {
            $this->error("파일 없음: {$path}");

            return self::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)
            || ! is_string($decoded['game'] ?? null)
            || ! is_string($decoded['raid'] ?? null)
            || ! is_array($decoded['substitutes'] ?? null)) {
            $this->error('JSON 계약 불일치 — { "game": "slug", "raid": "external_key", "substitutes": [...] } 형식이어야 합니다.');

            return self::FAILURE;
        }

        $game = Game::where('slug', $decoded['game'])->first();
        if ($game === null) {
            $this->error("게임 없음: {$decoded['game']}");

            return self::FAILURE;
        }

        // 레이드는 external_key 우선, 숫자면 id 로도 찾는다
        $raid = $game->raids()->where('external_key', $decoded['raid'])->first()
            ?? (ctype_digit($decoded['raid']) ? $game->raids()->whereKey((int) $decoded['raid'])->first() : null);
        if ($raid === null) {
            $this->error("레이드 없음: {$decoded['raid']} (external_key 또는 id)");
            $this->line('사용 가능한 레이드:');
            foreach ($game->raids()->orderByDesc('starts_at')->limit(10)->get() as $candidate) {
                $this->line("  - {$candidate->external_key} (#{$candidate->id}) {$candidate->name}");
            }

            return self::FAILURE;
        }

        $result = $service->importManual($raid, $decoded['substitutes']);

        $this->table(['레이드', '저장', '미매칭'], [[
            $raid->name, $result['saved'], count($result['missing']),
        ]]);
        foreach ($result['missing'] as $name) {
            $this->warn("미매칭 이름(마스터에 없음): {$name}");
        }
        $this->info('완료.');

        return self::SUCCESS;
    }
}
