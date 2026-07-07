<?php

namespace App\Services\SubcultureGameInfo\Raids;

use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\GuidePost;
use App\Models\SubcultureGameInfo\Raid;
use App\Services\SubcultureGameInfo\Sources\Contracts\GuidePostDriver;
use App\Services\SubcultureGameInfo\Sources\Drivers\ArcaGuidePostDriver;
use App\Services\SubcultureGameInfo\Sources\Drivers\DcGuidePostDriver;
use Illuminate\Support\Collection;

/**
 * 게임별 커뮤니티 공략글(디씨 개념글·아카 추천글) 메타를 수집해 DB 에 동기화한다.
 * 제목이 보스명·레이드 통칭 키워드와 매칭되면 해당 레이드에 연결하고,
 * 보관일(keep_days)을 넘긴 글은 정리한다.
 */
class GuidePostCollectorService
{
    /** @var GuidePostDriver[] */
    private array $drivers;

    public function __construct(DcGuidePostDriver $dc, ArcaGuidePostDriver $arca)
    {
        $this->drivers = [$dc, $arca];
    }

    /**
     * @return array{collected: int, created: int, updated: int, matched: int, pruned: int}
     */
    public function collect(Game $game): array
    {
        $cfg = config('subculture-game-info.raids.guides');
        $stats = ['collected' => 0, 'created' => 0, 'updated' => 0, 'matched' => 0, 'pruned' => 0];

        // 매칭 후보: 최근 레이드(종료 후에도 keep_days 내 글은 연결 가치가 있다)
        $raids = $game->raids()
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()->subDays((int) $cfg['keep_days'])))
            ->orderByDesc('starts_at')
            ->get();
        $keywords = $cfg['raid_keywords'][$game->slug] ?? [];
        $cutoff = now()->subDays((int) $cfg['keep_days']);

        foreach ($this->drivers as $driver) {
            // 1) 개념글/추천글·공략 카테고리 목록
            $posts = array_slice($driver->fetchPosts($game->slug), 0, (int) $cfg['max_posts_per_source']);
            $this->ingest($driver, $posts, $game, $raids, $keywords, $cfg, $cutoff, $stats);

            // 2) 보스명 제목 검색("비나 공략" 식) — 개념글에 안 올라오는 레이드 공략을 보강한다.
            foreach ($this->searchQueries($raids, $cfg) as $query) {
                usleep(1_000_000); // 연속 요청 차단 방지 1초 간격
                $posts = array_slice($driver->searchPosts($game->slug, $query), 0, (int) $cfg['max_posts_per_source']);
                $this->ingest($driver, $posts, $game, $raids, $keywords, $cfg, $cutoff, $stats);
            }
        }

        // 보관일 초과 정리(작성일 없는 글은 수집일 기준)
        $stats['pruned'] = $game->guidePosts()
            ->where(fn ($q) => $q->where('posted_at', '<', $cutoff)
                ->orWhere(fn ($q2) => $q2->whereNull('posted_at')->where('created_at', '<', $cutoff)))
            ->delete();

        return $stats;
    }

    /**
     * 수집된 글 목록을 공통 파이프라인(보관일 컷 → 레이드 매칭/공략 필터 → 저장)으로 처리한다.
     *
     * @param  \App\Services\SubcultureGameInfo\Sources\DTO\GuidePostData[]  $posts
     * @param  Collection<int, Raid>  $raids
     */
    private function ingest(GuidePostDriver $driver, array $posts, Game $game, Collection $raids, array $keywords, array $cfg, \Carbon\CarbonInterface $cutoff, array &$stats): void
    {
        $stats['collected'] += count($posts);

        foreach ($posts as $data) {
            // 보관일을 이미 넘긴 옛글은 저장하지 않는다(저장 직후 정리되는 낭비 방지).
            if ($data->postedAt !== null && $data->postedAt->lt($cutoff)) {
                continue;
            }

            // 잡담·질문글 컷 — 검색 수집은 보스명+공략이 제목에 있어도 유머 글이 많다.
            if ($this->looksLikeChatter($cfg['exclude_title_keywords'] ?? [], $data->title)) {
                continue;
            }

            [$raid, $matchedKeyword] = $this->matchRaid($raids, $keywords, $data->title);

            // 개념글/추천글 대부분은 팬아트·유머 — 공략 키워드나 레이드 매칭이 없으면 버린다.
            if ($raid === null && ! $this->looksLikeGuide($cfg['title_keywords'] ?? [], $data->title)) {
                continue;
            }

            $post = GuidePost::updateOrCreate(
                [
                    'subculture_game_id' => $game->id,
                    'source' => $driver->source()->value,
                    'external_id' => $data->externalId,
                ],
                [
                    'title' => $data->title,
                    'url' => $data->url,
                    'posted_at' => $data->postedAt,
                    'views' => $data->views,
                    'subculture_raid_id' => $raid?->id,
                    'matched_keyword' => $matchedKeyword,
                ],
            );

            $post->wasRecentlyCreated ? $stats['created']++ : ($post->wasChanged() ? $stats['updated']++ : null);
            if ($raid !== null) {
                $stats['matched']++;
            }
        }
    }

    /**
     * 검색어 목록: 최근 레이드 보스명 × 검색 접미사("공략", "대체").
     * 같은 보스가 여러 회차면 중복 제거.
     *
     * @param  Collection<int, Raid>  $raids
     * @return string[]
     */
    private function searchQueries(Collection $raids, array $cfg): array
    {
        $suffixes = collect($cfg['search_suffixes'] ?? ['공략'])
            ->map(fn ($suffix) => trim((string) $suffix))
            ->filter();

        return $raids
            ->pluck('boss_name')
            ->filter(fn (?string $name) => $name !== null && $name !== '')
            ->unique()
            ->crossJoin($suffixes)
            ->map(fn (array $pair) => trim($pair[0].' '.$pair[1]))
            ->values()
            ->all();
    }

    /** 제목에 공략 키워드가 하나라도 있으면 공략글로 본다. */
    private function looksLikeGuide(array $titleKeywords, string $title): bool
    {
        foreach ($titleKeywords as $keyword) {
            if (mb_stripos($title, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /** 잡담/질문글 판정 — 배제 표현 포함 또는 '?'로 끝나는 제목. */
    private function looksLikeChatter(array $excludeKeywords, string $title): bool
    {
        $trimmed = rtrim(trim($title), '.,~ ');
        if (str_ends_with($trimmed, '?')) {
            return true;
        }

        foreach ($excludeKeywords as $keyword) {
            if (mb_stripos($title, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 제목 → 레이드 매칭. 보스명(특정 회차)이 우선, 다음이 통칭 키워드(진행 중 → 최신 회차 순).
     *
     * @param  Collection<int, Raid>  $raids
     * @return array{0: ?Raid, 1: ?string}
     */
    private function matchRaid(Collection $raids, array $keywords, string $title): array
    {
        foreach ($raids as $raid) {
            if ($raid->boss_name !== null && $raid->boss_name !== '' && mb_stripos($title, $raid->boss_name) !== false) {
                return [$raid, $raid->boss_name];
            }
        }

        foreach ($keywords as $keyword) {
            if (mb_stripos($title, $keyword) === false) {
                continue;
            }
            // 통칭 키워드는 특정 회차를 못 가리키므로 진행 중인 레이드, 없으면 최신 회차에 붙인다.
            $target = $raids->first(fn (Raid $r) => $r->status === 'active') ?? $raids->first();
            if ($target !== null) {
                return [$target, $keyword];
            }
        }

        return [null, null];
    }
}
