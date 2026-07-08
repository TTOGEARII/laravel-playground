<?php

namespace App\Console\Commands\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Game;
use App\Services\SubcultureGameInfo\CodeSyncService;
use App\Services\SubcultureGameInfo\Raids\JfdCollectorService;
use Illuminate\Console\Command;

/**
 * 블아 종합전술시험(종전시) 수집 — 아카 시리즈 글 결정적 파싱(Gemini 불필요).
 * 평소엔 새 차수(+최신 차수 갱신)만 가져오고, --all 은 역대 전체 백필.
 */
class CollectJfdCommand extends Command
{
    protected $signature = 'subculture:collect-jfd {--all : 이미 저장된 차수도 전부 재수집(백필)}';

    protected $description = '블아 종합전술시험 회차·편성 수집 (아카 모음글 + 차수별 공략글 파싱)';

    public function handle(CodeSyncService $codeSync, JfdCollectorService $collector): int
    {
        $codeSync->ensureGames();

        $game = Game::where('slug', 'bluearchive')->first();
        if ($game === null) {
            $this->error('블루 아카이브 게임 행이 없습니다.');

            return self::FAILURE;
        }

        $this->info('종합전술시험 수집 중...'.($this->option('all') ? ' (전체 백필)' : ''));
        $stats = $collector->collect($game, (bool) $this->option('all'));

        $this->table(
            ['차수', '레이드', '편성', '멤버', '미매칭'],
            [[$stats['sessions'], $stats['raids'], $stats['parties'], $stats['members'], $stats['missing_members']]],
        );
        if ($stats['unresolved'] !== []) {
            $this->warn('미해석 애칭(config jfd.aliases 에 추가 필요): '.implode(', ', $stats['unresolved']));
        }
        $this->info('완료.');

        return self::SUCCESS;
    }
}
