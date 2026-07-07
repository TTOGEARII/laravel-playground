<?php

namespace App\Console\Commands\SubcultureGameInfo;

use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\Raid;
use App\Services\SubcultureGameInfo\CodeSyncService;
use App\Services\SubcultureGameInfo\Raids\GuideBodyFetcher;
use App\Services\SubcultureGameInfo\Raids\SubstituteExtractionService;
use Illuminate\Console\Command;

class ExtractSubstitutesCommand extends Command
{
    protected $signature = 'subculture:extract-substitutes
        {--game= : 특정 게임 슬러그만 처리(bluearchive/nikke/trickcal/browndust2)}
        {--raid= : 특정 레이드 id 만 처리}';

    protected $description = '레이드 공략글 본문에서 Gemini 로 대체 캐릭터 관계를 추출·저장 (진행 중·예정 + 최근 종료 레이드 대상)';

    public function handle(CodeSyncService $codeSync, GuideBodyFetcher $fetcher, SubstituteExtractionService $extractor): int
    {
        $codeSync->ensureGames();

        $slugs = config('subculture-game-info.raids.games', []);
        if ($this->option('game') !== null) {
            $slugs = array_intersect($slugs, [$this->option('game')]);
            if ($slugs === []) {
                $this->error("레이드 대상 게임이 아닙니다: {$this->option('game')}");

                return self::FAILURE;
            }
        }

        $rows = [];
        foreach ($slugs as $slug) {
            $game = Game::where('slug', $slug)->first();
            if ($game === null) {
                $this->warn("게임 없음(스킵): {$slug}");

                continue;
            }

            // 진행 중·예정 + 최근 종료(include_ended_days 이내) — 종료 직후에도
            // 대체 정보는 다음 회차·미보유 사용자에게 유효하다
            $endedWindow = now()->subDays((int) config('subculture-game-info.raids.substitutes.include_ended_days', 14));
            $raids = $game->raids()
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $endedWindow))
                ->when($this->option('raid'), fn ($q, $raidId) => $q->whereKey($raidId))
                ->with(['guidePosts' => fn ($q) => $q->orderByDesc('posted_at')])
                ->get();

            foreach ($raids as $raid) {
                /** @var Raid $raid */
                if ($raid->guidePosts->isEmpty()) {
                    $rows[] = [$slug, $raid->name, 0, 0, '-', '-'];

                    continue;
                }

                $this->info("[{$slug}] {$raid->name} — 공략글 본문 수집·추출 중...");
                // 상한 + 요청 간 딜레이 — 커뮤니티 대량 요청(차단)·Gemini 비용 폭주 방지
                $maxPosts = (int) config('subculture-game-info.raids.substitutes.max_posts_per_raid', 6);
                $delayMicros = (int) (config('subculture-game-info.raids.substitutes.fetch_delay_seconds', 1.0) * 1_000_000);

                // 본문 상한이 빡빡하므로 점수 인증글보다 공략성 제목(공략/편성/대체 등)을 먼저 뽑는다
                $guideKeywords = array_merge(
                    (array) config('subculture-game-info.raids.guides.title_keywords', []),
                    ['대체'],
                );
                $isGuideTitle = fn ($post): int => collect($guideKeywords)
                    ->contains(fn (string $kw) => mb_stripos($post->title, $kw) !== false) ? 1 : 0;
                $prioritized = $raid->guidePosts
                    ->sort(fn ($a, $b) => [$isGuideTitle($b), $b->posted_at?->getTimestamp() ?? 0]
                        <=> [$isGuideTitle($a), $a->posted_at?->getTimestamp() ?? 0])
                    ->values();

                $bodies = [];
                foreach ($prioritized->take($maxPosts)->values() as $i => $post) {
                    if ($i > 0 && $delayMicros > 0) {
                        usleep($delayMicros);
                    }
                    $text = $fetcher->fetch($post);
                    if ($text !== null) {
                        $bodies[] = ['source' => $post->source, 'url' => $post->url, 'text' => $text];
                    }
                }

                // 본문을 하나도 못 가져오면(삭제된 글·셀렉터 깨짐 등) 기존 데이터를 지우지 않고 스킵
                if ($bodies === []) {
                    $this->warn("[{$slug}] {$raid->name} — 본문 수집 실패, 스킵");
                    $rows[] = [$slug, $raid->name, $raid->guidePosts->count(), 0, '-', '-'];

                    continue;
                }

                $stats = $extractor->extractAndSync($raid, $bodies);
                $rows[] = [$slug, $raid->name, $raid->guidePosts->count(), count($bodies), $stats['relations'], $stats['saved']];
            }
        }

        $this->table(['게임', '레이드', '공략글', '본문', '관계', '저장'], $rows);
        $this->info('완료.');

        return self::SUCCESS;
    }
}
