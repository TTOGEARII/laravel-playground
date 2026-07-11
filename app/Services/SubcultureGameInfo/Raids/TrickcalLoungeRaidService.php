<?php

namespace App\Services\SubcultureGameInfo\Raids;

use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\Raid;
use App\Services\SubcultureGameInfo\Sources\Drivers\NaverGameLoungeDriver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 트릭컬 레이드 일정 보완 — 네이버 라운지 업데이트 공지에서 진행 중 시즌 일정을 파싱한다.
 *
 * 트릭컬 레코드(실측 통계 사이트)는 시즌이 끝난 뒤에야 게시되는 특성이 있어,
 * 진행 중 회차가 대시보드에 비는 공백을 공지 일정("엘리아스 프론티어 7월 정규 시즌…
 * 07/09 ~ 07/16")으로 메운다. 편성·통계는 없고 일정 카드만 만들며,
 * 이후 트릭컬 레코드에 정식 회차가 올라오면 기간이 겹치는 라운지 레이드를 정리한다.
 */
class TrickcalLoungeRaidService
{
    private const LOUNGE_ID = 'Trickcal';

    private const UPDATE_BOARD_ID = 11; // ⭐️업데이트 게시판

    /** 공지에서 찾을 콘텐츠: 키워드 → [raid_type, external_key 접두] */
    private const CONTENTS = [
        '엘리아스 프론티어' => ['프론티어', 'frontier-lounge'],
        '차원 대충돌' => ['차원 대충돌', 'clash-lounge'],
    ];

    public function __construct(private NaverGameLoungeDriver $lounge) {}

    /** @return int 생성/갱신한 레이드 수 */
    public function sync(Game $game): int
    {
        $posts = $this->lounge->boardPosts(self::LOUNGE_ID, self::UPDATE_BOARD_ID, 15);
        if ($posts === []) {
            Log::info('[SGI-RAID] 트릭컬 라운지 공지 조회 결과 없음');

            return 0;
        }

        $synced = 0;
        foreach ($posts as $post) {
            foreach (self::CONTENTS as $keyword => [$raidType, $keyPrefix]) {
                $schedule = $this->parseSchedule($post['body'], $keyword, $post['date']);
                if ($schedule === null) {
                    continue;
                }
                [$startsAt, $endsAt] = $schedule;

                // 이미 종료된 시즌 공지(과거 글)는 스킵
                if ($endsAt->isPast()) {
                    continue;
                }

                // 같은 기간을 덮는 정식(트릭컬 레코드) 레이드가 이미 있으면 만들지 않는다
                $covered = Raid::where('subculture_game_id', $game->id)
                    ->where('source', '!=', 'naver-lounge')
                    ->where('raid_type', $raidType)
                    ->whereDate('starts_at', '<=', $endsAt)
                    ->whereDate('ends_at', '>=', $startsAt)
                    ->exists();
                if ($covered) {
                    continue;
                }

                Raid::updateOrCreate(
                    [
                        'subculture_game_id' => $game->id,
                        'external_key' => $keyPrefix.'-'.$startsAt->format('Y-m'),
                    ],
                    [
                        'name' => $keyword.' ('.$startsAt->format('n').'월 시즌)',
                        'boss_name' => null,
                        'raid_type' => $raidType,
                        'tags' => ['source_note' => '라운지 공지 일정 — 편성·통계는 시즌 집계 후 반영'],
                        'starts_at' => $startsAt->toDateString(),
                        'ends_at' => $endsAt->toDateString(),
                        'source' => 'naver-lounge',
                        'source_url' => 'https://game.naver.com/lounge/'.self::LOUNGE_ID.'/board/'.self::UPDATE_BOARD_ID,
                    ],
                );
                $synced++;
            }
        }

        return $synced;
    }

    /**
     * 트릭컬 레코드 정식 회차가 올라온 뒤, 기간이 겹치는 라운지 일정 레이드를 정리한다.
     *
     * @return int 정리한 수
     */
    public function pruneCovered(Game $game): int
    {
        $pruned = 0;
        $loungeRaids = Raid::where('subculture_game_id', $game->id)->where('source', 'naver-lounge')->get();
        foreach ($loungeRaids as $raid) {
            $covered = Raid::where('subculture_game_id', $game->id)
                ->where('source', '!=', 'naver-lounge')
                ->where('raid_type', $raid->raid_type)
                ->whereDate('starts_at', '<=', $raid->ends_at)
                ->whereDate('ends_at', '>=', $raid->starts_at)
                ->exists();
            if ($covered) {
                $raid->delete();
                $pruned++;
            }
        }

        return $pruned;
    }

    /**
     * 공지 본문에서 "{키워드} … 진행 기간 … MM/DD(…) ~ MM/DD(…)"를 파싱한다.
     * 연도는 공지 작성일 기준(월 역전 시 이듬해 보정).
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function parseSchedule(string $body, string $keyword, string $postedAt): ?array
    {
        $idx = mb_stripos($body, $keyword);
        if ($idx === false) {
            return null;
        }

        // 키워드 뒤 일정 구간(진행 기간 표)만 본다 — 다른 콘텐츠 일정과 섞이지 않게 700자 한도
        $section = mb_substr($body, $idx, 700);
        if (! preg_match_all('~(\d{1,2})\s*/\s*(\d{1,2})\s*\([월화수목금토일]\)~u', $section, $m, PREG_SET_ORDER) || count($m) < 2) {
            return null;
        }

        $baseYear = (int) (Carbon::parse($postedAt ?: 'now')->format('Y'));
        $toDate = function (array $match) use ($baseYear): Carbon {
            return Carbon::createFromDate($baseYear, (int) $match[1], (int) $match[2]);
        };

        $start = $toDate($m[0]);
        // 종료일은 구간 내 가장 늦은 날짜(성격 구역/보스 구역 등 여러 기간 중 최종 종료)
        $end = collect($m)->map($toDate)->max();
        if ($end->lt($start)) {
            $end = $end->addYear(); // 연말 걸침 보정
        }

        return [$start, $end];
    }
}
