<?php

namespace App\Console\Commands\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\GuidePost;
use App\Models\SubcultureGameInfo\Raid;
use App\Services\SubcultureGameInfo\CodeSyncService;
use App\Services\SubcultureGameInfo\Raids\CommunityPartyService;
use App\Services\SubcultureGameInfo\Raids\GuideBodyFetcher;
use Illuminate\Console\Command;

/**
 * 랭킹 소스가 없는 레이드(블아 제약해제결전 등)의 추천 편성을 커뮤니티 공략글에서 추출한다.
 * 랭킹으로 편성이 채워지는 레이드(총력전·대결전=mollulog)는 건너뛰어 커뮤니티 추출을 아끼고
 * 양질의 랭킹 편성을 덮지 않는다.
 */
class ExtractPartiesCommand extends Command
{
    protected $signature = 'subculture:extract-parties
        {--game= : 특정 게임 슬러그만 처리(기본 bluearchive)}
        {--raid= : 특정 레이드 id 만 처리}
        {--force : 랭킹(mollulog) 편성이 있어도 커뮤니티 추출 실행}';

    protected $description = '랭킹 소스가 없는 레이드(제약해제결전 등)의 추천 편성을 공략글에서 Gemini 로 추출·저장';

    public function handle(CodeSyncService $codeSync, GuideBodyFetcher $fetcher, CommunityPartyService $extractor): int
    {
        $codeSync->ensureGames();

        $slug = $this->option('game') ?? 'bluearchive';
        $game = Game::where('slug', $slug)->first();
        if ($game === null) {
            $this->error("게임 없음: {$slug}");

            return self::FAILURE;
        }

        // 진행 중·예정 + 최근 종료(대체 추출과 같은 창) — 종료 직후에도 다음 회차·미보유 유저에게 유효
        $endedWindow = now()->subDays((int) config('subculture-game-info.raids.substitutes.include_ended_days', 14));
        $raids = $game->raids()
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $endedWindow))
            ->whereDate('starts_at', '<=', now())
            ->when($this->option('raid'), fn ($q, $raidId) => $q->whereKey($raidId))
            ->with(['game', 'parties'])
            ->get();

        $maxPosts = (int) config('subculture-game-info.raids.substitutes.max_posts_per_raid', 6);
        $delayMicros = (int) (config('subculture-game-info.raids.substitutes.fetch_delay_seconds', 1.0) * 1_000_000);
        $guideKeywords = array_merge((array) config('subculture-game-info.raids.guides.title_keywords', []), ['편성', '조합', '팟']);

        $rows = [];
        foreach ($raids as $raid) {
            /** @var Raid $raid */
            // 랭킹(mollulog) 편성이 이미 있으면 커뮤니티 추출 불필요(--force 로 강제 가능)
            $hasRankingParties = $raid->parties->where('source', 'mollulog')->isNotEmpty();
            if ($hasRankingParties && ! $this->option('force')) {
                continue;
            }

            // 이 레이드의 공략글: 명시적 연결(subculture_raid_id) + 보스명 제목 매칭(연결이 얇아도
            // 이미 수집된 커뮤니티 글을 폭넓게 활용 — 라이브 검색 없음). 회차 시작 3일 전부터.
            $posts = $this->postsForRaid($game, $raid);
            if ($posts->isEmpty()) {
                $rows[] = [$raid->name, 0, 0, '공략글 없음'];

                continue;
            }

            $this->info("[{$slug}] {$raid->name} — 공략글 본문 수집·편성 추출 중...");
            // 공략성 제목(공략/편성/팟 등) 우선, 그다음 최신순
            $isGuideTitle = fn ($post): int => collect($guideKeywords)
                ->contains(fn (string $kw) => mb_stripos($post->title, $kw) !== false) ? 1 : 0;
            $prioritized = $posts
                ->sort(fn ($a, $b) => [$isGuideTitle($b), $b->posted_at?->getTimestamp() ?? 0]
                    <=> [$isGuideTitle($a), $a->posted_at?->getTimestamp() ?? 0])
                ->values();

            $bodies = [];
            foreach ($prioritized->take($maxPosts)->values() as $i => $post) {
                if ($i > 0 && $delayMicros > 0) {
                    usleep($delayMicros);
                }
                $text = $fetcher->fetch($post);
                if ($text !== null && mb_strlen($text) > 80) {
                    $bodies[] = ['source' => $post->source, 'url' => $post->url, 'text' => $text];
                }
            }
            if ($bodies === []) {
                $rows[] = [$raid->name, $posts->count(), 0, '본문 수집 실패'];

                continue;
            }

            $stats = $extractor->extractAndSync($raid, $bodies);
            $rows[] = [$raid->name, $posts->count(), count($bodies), "편성 {$stats['parties']} · 버림 {$stats['dropped']}"];
        }

        if ($rows === []) {
            $this->info('대상 레이드 없음(랭킹 편성이 이미 채워졌거나 대상 회차 없음).');

            return self::SUCCESS;
        }
        $this->table(['레이드', '공략글', '본문', '결과'], $rows);
        $this->info('완료.');

        return self::SUCCESS;
    }

    /**
     * 레이드의 공략글 = 명시적 연결(subculture_raid_id) + 보스명 제목 매칭(회차 시작 3일 전부터).
     * 보스명은 전체명과 첫 토큰("세트의 분노"→"세트")을 함께 써 표기 편차를 흡수한다.
     *
     * @return \Illuminate\Support\Collection<int, GuidePost>
     */
    private function postsForRaid(Game $game, Raid $raid): \Illuminate\Support\Collection
    {
        $keywords = collect([$raid->boss_name, preg_split('/[\s의]/u', (string) $raid->boss_name)[0] ?? null])
            ->filter(fn ($k) => is_string($k) && mb_strlen($k) >= 2)
            ->unique();
        $since = $raid->starts_at?->copy()->subDays(3);

        return GuidePost::where('subculture_game_id', $game->id)
            ->where(function ($q) use ($raid, $keywords) {
                $q->where('subculture_raid_id', $raid->id);
                foreach ($keywords as $kw) {
                    $q->orWhere('title', 'like', '%'.$kw.'%');
                }
            })
            ->when($since, fn ($q) => $q->where(fn ($q2) => $q2->whereNull('posted_at')->orWhere('posted_at', '>=', $since)))
            ->orderByDesc('posted_at')
            ->limit(40)
            ->get();
    }
}
