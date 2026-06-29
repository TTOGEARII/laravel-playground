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
 * 동일성 키: (게임, 리전, 코드). 권위 규칙으로 커뮤니티 보조신호가
 * 메인(정리/ API) 데이터를 덮어쓰지 않도록 한다.
 */
class CodeSyncService
{
    /** config 의 게임 정의로 subculture_games 를 upsert. */
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
    public function sync(array $dtos): array
    {
        $gameIds = Game::pluck('id', 'slug');
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $now = Carbon::now();

        foreach ($dtos as $dto) {
            $gameId = $gameIds[$dto->gameSlug] ?? null;
            if ($gameId === null || trim($dto->code) === '') {
                $stats['skipped']++;

                continue;
            }

            $existing = RedeemCode::where('subculture_game_id', $gameId)
                ->where('region', $dto->region->value)
                ->where('code', $dto->code)
                ->first();

            if ($existing === null) {
                RedeemCode::create([
                    'subculture_game_id' => $gameId,
                    'code' => $dto->code,
                    'region' => $dto->region->value,
                    'rewards' => $dto->rewards,
                    'source' => $dto->source,
                    'source_type' => $dto->sourceType->value,
                    'source_url' => $dto->sourceUrl,
                    'status' => $dto->status->value,
                    'found_at' => $now,
                    'expires_at' => $dto->expiresAt,
                    'verified_at' => $dto->status === CodeStatus::Active ? $now : null,
                ]);
                $stats['created']++;

                continue;
            }

            $this->applyUpdate($existing, $dto, $now) ? $stats['updated']++ : $stats['skipped']++;
        }

        return $stats;
    }

    /**
     * 권위 규칙에 따라 기존 레코드를 갱신. 변경이 있으면 true.
     * - status: 확정 상태(active/expired)는 미검증으로 덮지 않음. 확정값이 오면 갱신.
     * - source_type: aggregator(메인) 가 community(보조) 보다 우선.
     * - rewards/expires_at: 비어있으면 채움.
     */
    private function applyUpdate(RedeemCode $code, CollectedCodeDto $dto, Carbon $now): bool
    {
        $dirty = false;

        // 상태: 들어온 값이 확정(active/expired)일 때만 갱신
        if ($dto->status !== CodeStatus::Unverified && $code->status !== $dto->status) {
            $code->status = $dto->status;
            if ($dto->status === CodeStatus::Active) {
                $code->verified_at = $now;
            }
            $dirty = true;
        }

        // 소스: 메인(aggregator)이 보조(community)를 이긴다. 보조→메인 승격만 허용
        $incomingIsMain = $dto->sourceType === SourceType::Aggregator;
        $existingIsCommunity = $code->source_type === SourceType::Community;
        if ($incomingIsMain && $existingIsCommunity) {
            $code->source_type = SourceType::Aggregator;
            $code->source = $dto->source;
            if ($dto->sourceUrl) {
                $code->source_url = $dto->sourceUrl;
            }
            $dirty = true;
        }

        // 보상: 비어있으면 채움
        if (empty($code->rewards) && ! empty($dto->rewards)) {
            $code->rewards = $dto->rewards;
            $dirty = true;
        }

        // 만료시각: 비어있으면 채움
        if ($code->expires_at === null && $dto->expiresAt !== null) {
            $code->expires_at = $dto->expiresAt;
            $dirty = true;
        }

        if ($dirty) {
            $code->save();
        }

        return $dirty;
    }
}
