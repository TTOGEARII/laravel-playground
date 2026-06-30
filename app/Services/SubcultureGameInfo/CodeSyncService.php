<?php

namespace App\Services\SubcultureGameInfo;

use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Enums\SubcultureGameInfo\SourceType;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\RedeemCode;
use App\Services\SubcultureGameInfo\Sources\DTO\CollectedCodeDto;
use Illuminate\Support\Carbon;

/**
 * 수집한 CollectedCodeDto[] 를 DB에 동기화한다.
 * - 동일성 키: (게임, 코드)  ※ 리전은 식별에 쓰지 않는다. 같은 코드를 출처마다 다른 리전
 *   (예: API=global / 정리사이트=asia)으로 넣어 같은 코드가 두 번 적재되던 문제를 막는다.
 * - 교차검증: 여러 출처에서 본 코드는 seen_sources/corroboration_count 로 신뢰도 누적
 * - 권위 규칙: 메인(aggregator) > 커뮤니티, 확정상태(active/expired)는 미검증으로 안 덮음
 * - 사용가능만: 만료(만료일 경과/expired)된 코드는 신규 저장하지 않음(기존은 만료로 갱신)
 */
class CodeSyncService
{
    public function ensureGames(): void
    {
        foreach (config('subculture-game-info.games', []) as $slug => $g) {
            Game::updateOrCreate(['slug' => $slug], [
                'name' => $g['name'],
                'publisher' => $g['publisher'] ?? null,
                'icon' => $g['icon'] ?? null,
                'color' => $g['color'] ?? null,
                'redeem_url_template' => $g['redeem_url_template'] ?? null,
                'redeem_note' => $g['redeem_note'] ?? null,
                'region_default' => $g['region_default'] ?? 'global',
                'sort' => $g['sort'] ?? 0,
                'active_flg' => true,
            ]);
        }
    }

    /**
     * @param  CollectedCodeDto[]  $dtos
     * @return array{created:int, updated:int, skipped:int}
     */
    public function sync(array $dtos, ?bool $usableOnly = null): array
    {
        $usableOnly ??= (bool) config('subculture-game-info.store_usable_only', true);
        $gameIds = Game::pluck('id', 'slug');
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $now = Carbon::now();

        foreach ($dtos as $dto) {
            $gameId = $gameIds[$dto->gameSlug] ?? null;
            if ($gameId === null || trim($dto->code) === '') {
                $stats['skipped']++;

                continue;
            }

            $effectiveStatus = $this->effectiveStatus($dto);

            // 동일성은 (게임, 코드)로 본다. 리전은 식별 키에서 제외(같은 코드의 리전 중복 방지).
            $existing = RedeemCode::where('subculture_game_id', $gameId)
                ->where('code', $dto->code)
                ->first();

            if ($existing === null) {
                // 사용 불가(만료) 코드는 신규 저장하지 않음
                if ($usableOnly && $effectiveStatus === CodeStatus::Expired) {
                    $stats['skipped']++;

                    continue;
                }
                RedeemCode::create([
                    'subculture_game_id' => $gameId,
                    'code' => $dto->code,
                    'region' => $dto->region->value,
                    'rewards' => $dto->rewards,
                    'source' => $dto->source,
                    'source_type' => $dto->sourceType->value,
                    'source_url' => $dto->sourceUrl,
                    'seen_sources' => [$dto->source],
                    'corroboration_count' => 1,
                    'status' => $effectiveStatus->value,
                    'found_at' => $now,
                    'last_seen_at' => $now,
                    'expires_at' => $dto->expiresAt,
                    'verified_at' => $effectiveStatus === CodeStatus::Active ? $now : null,
                ]);
                $stats['created']++;

                continue;
            }

            $this->applyUpdate($existing, $dto, $effectiveStatus, $now);
            $stats['updated']++;
        }

        return $stats;
    }

    /** 만료일이 지난(아직 expired 아닌) 코드를 만료로 일괄 처리. 변경 건수 반환. */
    public function markExpiredPastDue(): int
    {
        return RedeemCode::query()
            ->where('status', '!=', CodeStatus::Expired->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', Carbon::now())
            ->update(['status' => CodeStatus::Expired->value, 'updated_at' => Carbon::now()]);
    }

    private function effectiveStatus(CollectedCodeDto $dto): CodeStatus
    {
        if ($dto->expiresAt !== null && $dto->expiresAt->isPast()) {
            return CodeStatus::Expired;
        }

        return $dto->status;
    }

    private function applyUpdate(RedeemCode $code, CollectedCodeDto $dto, CodeStatus $effectiveStatus, Carbon $now): void
    {
        // 교차검증: 출처 누적
        $seen = $code->seen_sources ?? [];
        if (! in_array($dto->source, $seen, true)) {
            $seen[] = $dto->source;
        }
        $code->seen_sources = $seen;
        $code->corroboration_count = count($seen);
        $code->last_seen_at = $now;

        // 상태: 들어온 값이 확정(active/expired)일 때만 갱신
        if ($effectiveStatus !== CodeStatus::Unverified && $code->status !== $effectiveStatus) {
            $code->status = $effectiveStatus;
            if ($effectiveStatus === CodeStatus::Active) {
                $code->verified_at = $now;
            }
        }

        // 소스 타입: 메인(aggregator)이 커뮤니티를 이긴다
        if ($dto->sourceType === SourceType::Aggregator && $code->source_type === SourceType::Community) {
            $code->source_type = SourceType::Aggregator;
            $code->source = $dto->source;
            if ($dto->sourceUrl) {
                $code->source_url = $dto->sourceUrl;
            }
        }

        // 보상/만료일: 비어있으면 채움
        if (empty($code->rewards) && ! empty($dto->rewards)) {
            $code->rewards = $dto->rewards;
        }
        if ($code->expires_at === null && $dto->expiresAt !== null) {
            $code->expires_at = $dto->expiresAt;
        }

        $code->save();
    }
}
