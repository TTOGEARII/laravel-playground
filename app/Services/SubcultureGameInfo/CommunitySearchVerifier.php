<?php

namespace App\Services\SubcultureGameInfo;

use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\RedeemCode;
use App\Services\SubcultureGameInfo\Sources\Contracts\CodeSearchDriver;
use App\Services\SubcultureGameInfo\Sources\Drivers\ArcaCommunityDriver;
use App\Services\SubcultureGameInfo\Sources\Drivers\DcCommunityDriver;
use App\Services\SubcultureGameInfo\Sources\Drivers\NaverGameLoungeDriver;
use Illuminate\Support\Carbon;

/**
 * 수집된 코드를 디씨/아카에서 '검색'해 한 번 더 검증한다.
 * API가 없는 게임(트릭컬/블아/명조)은 정적 사이트의 오래된 코드를 살아있는 것처럼
 * 수집하기 쉬워, 미검증 코드를 커뮤니티에서 직접 검색해 신선도/유효성을 재확인한다.
 *
 * 규칙(보수적):
 *  - 최근(recency_days 내) 글에서 코드를 보면 corroboration(교차검증) +1 → 신뢰도↑
 *  - 글 제목에 '만료/종료' 단서가 함께 보이면 만료 처리(명시적 단서일 때만)
 *  - 아예 안 보이는 건 건드리지 않는다(없다고 만료로 단정하지 않음)
 */
class CommunitySearchVerifier
{
    /** @var CodeSearchDriver[] */
    private array $searchers;

    public function __construct(DcCommunityDriver $dc, ArcaCommunityDriver $arca, NaverGameLoungeDriver $naver)
    {
        // 네이버 라운지(공식)를 먼저 — 라운지 있는 한국게임은 공식 글로 강하게 교차검증/만료 판정.
        $this->searchers = [$naver, $dc, $arca];
    }

    /**
     * @return array{searched:int, corroborated:int, expired:int}
     */
    public function verify(): array
    {
        $cfg = config('subculture-game-info.verify', []);
        $stats = ['searched' => 0, 'corroborated' => 0, 'expired' => 0];

        if (! ($cfg['enabled'] ?? true)) {
            return $stats;
        }

        $maxPerGame = (int) ($cfg['max_codes_per_game'] ?? 20);
        $recencyDays = (int) ($cfg['recency_days'] ?? 45);
        $delayMs = (int) ($cfg['delay_ms'] ?? 400);
        $recentThreshold = Carbon::now()->subDays($recencyDays);

        foreach (Game::where('active_flg', true)->get() as $game) {
            // 검증 대상: 사용 가능하지만 약하게 검증된 코드(API active 아님 + 단일 출처).
            $codes = RedeemCode::where('subculture_game_id', $game->id)
                ->usable()
                ->where('status', '!=', CodeStatus::Active->value)
                ->where('corroboration_count', '<', 2)
                ->orderByDesc('found_at')
                ->limit($maxPerGame)
                ->get();

            foreach ($codes as $code) {
                $this->verifyOne($game, $code, $recentThreshold, $delayMs, $stats);
            }
        }

        return $stats;
    }

    private function verifyOne(Game $game, RedeemCode $code, Carbon $recentThreshold, int $delayMs, array &$stats): void
    {
        $expiredHint = false;
        $recentAt = null;
        $changed = false;

        // 1단계: 모든 검색처(네이버/디씨/아카)에서 코드를 찾아 신호를 모은다.
        //  - 보이면 교차검증 출처를 누적(corroboration↑)
        //  - 가장 최근 관측일·만료 단서를 기록
        foreach ($this->searchers as $searcher) {
            $hit = $searcher->searchCode($game->slug, $code->code);
            $stats['searched']++;
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
            if ($hit === null || ! $hit->found) {
                continue;
            }

            if ($hit->expiredHint) {
                $expiredHint = true;
            }
            if ($hit->recentAt !== null && ($recentAt === null || $hit->recentAt->gt($recentAt))) {
                $recentAt = $hit->recentAt;
            }

            // 교차검증 출처 누적(검색 히트는 목록 스캔과 다른 신호로 본다).
            $seen = $code->seen_sources ?? [];
            if (! in_array($hit->source, $seen, true)) {
                $seen[] = $hit->source;
                $code->seen_sources = $seen;
                $code->corroboration_count = count($seen);
                $changed = true;
            }
        }

        // 2단계: 모은 신호로 상태를 갱신한다(둘 중 하나만 적용).
        // 만료 단서가 명시적으로 보이면 만료 처리.
        if ($expiredHint && $code->status !== CodeStatus::Expired) {
            $code->status = CodeStatus::Expired;
            $stats['expired']++;
            $changed = true;
        } elseif ($recentAt !== null && $recentAt->gt($recentThreshold)) {
            // 최근 활동으로 관측 → 마지막 관측 시각 갱신(2개+ 출처면 is_verified 자동 충족).
            $code->last_seen_at = Carbon::now();
            if (($code->corroboration_count ?? 1) >= 2) {
                $stats['corroborated']++;
            }
            $changed = true;
        }

        if ($changed) {
            $code->save();
        }
    }
}
