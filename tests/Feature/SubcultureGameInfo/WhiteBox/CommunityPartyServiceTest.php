<?php

namespace Tests\Feature\SubcultureGameInfo\WhiteBox;

use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\Raid;
use App\Services\SubcultureGameInfo\Raids\CommunityPartyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 화이트박스: 공략글 본문 → Gemini 편성 추출(닫힌 어휘·최소인원·중복제거·manual 보존·0건 가드).
 * 제약해제결전처럼 랭킹 소스가 없는 레이드의 추천 편성을 커뮤니티에서 채운다.
 */
class CommunityPartyServiceTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    private Raid $raid;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.api_key' => 'test-key']);
        $this->game = Game::create(['slug' => 'bluearchive', 'name' => '블루 아카이브', 'icon' => '💙', 'sort' => 1, 'active_flg' => true]);
        $this->raid = Raid::create([
            'subculture_game_id' => $this->game->id,
            'external_key' => 'unlimit-25',
            'name' => '제약해제결전 #25 - 티페레트',
            'boss_name' => '티페레트',
            'raid_type' => '제약해제결전',
            'tags' => ['mollulog' => ['armors' => [['type' => '탄력장갑', 'difficulty' => '루나틱']]]],
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(10),
            'source' => 'mollulog',
        ]);
        foreach (['1' => '히후미', '2' => '이즈나', '3' => '사오리', '4' => '히마리', '5' => '아루', '6' => '하나코', '7' => '유우카'] as $k => $n) {
            Character::create(['subculture_game_id' => $this->game->id, 'external_key' => $k, 'name' => $n, 'source' => 'mollulog', 'active_flg' => true]);
        }
    }

    private function fakeGemini(array $parties): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode($parties, JSON_UNESCAPED_UNICODE)]]]]],
            ]),
        ]);
    }

    private function body(string $source = 'arca'): array
    {
        return ['source' => $source, 'url' => 'https://arca.live/b/bluearchive/1', 'text' => '티페레트 99층 오토 공략 본문'];
    }

    public function test_extracts_parties_with_closed_vocabulary(): void
    {
        $this->fakeGemini([
            ['title' => '탄력장갑 99층 오토', 'members' => ['히후미', '이즈나', '사오리', '히마리', '아루', '하나코']],
            ['title' => '9인팟', 'members' => ['히후미', '유우카', '사오리', '없는캐릭터', '아루']], // 없는캐릭터 제거 → 4명 유효
        ]);

        $stats = app(CommunityPartyService::class)->extractAndSync($this->raid, [$this->body()]);

        $this->assertSame(2, $stats['parties']);
        $this->raid->refresh()->load('parties.members.character');
        $this->assertSame(2, $this->raid->parties->count());
        $p1 = $this->raid->parties->firstWhere('title', '탄력장갑 99층 오토');
        $this->assertSame('community', $p1->source);
        $this->assertSame('커뮤니티 공략 발췌', $p1->note);
        $this->assertSame('루나틱', $p1->difficulty, 'tags 의 난이도 반영');
        $this->assertSame(6, $p1->members->count());
        $p2 = $this->raid->parties->firstWhere('title', '9인팟');
        $this->assertSame(4, $p2->members->count(), '목록에 없는 이름은 닫힌 어휘로 제거');
        $this->assertFalse($p2->members->pluck('character.name')->contains('없는캐릭터'));
    }

    public function test_drops_parties_below_min_members(): void
    {
        $this->fakeGemini([
            ['title' => '단편 언급', 'members' => ['히후미', '이즈나']], // 2명 → 버림
            ['title' => '정상', 'members' => ['히후미', '이즈나', '사오리', '히마리']],
        ]);

        $stats = app(CommunityPartyService::class)->extractAndSync($this->raid, [$this->body()]);

        $this->assertSame(1, $stats['parties']);
        $this->assertGreaterThanOrEqual(1, $stats['dropped']);
        $this->assertSame('정상', $this->raid->parties()->first()->title);
    }

    public function test_deduplicates_identical_member_sets(): void
    {
        $this->fakeGemini([
            ['title' => 'A글 편성', 'members' => ['히후미', '이즈나', '사오리', '히마리']],
            ['title' => 'B글 같은편성', 'members' => ['히마리', '사오리', '이즈나', '히후미']], // 순서만 다름 → 중복
        ]);

        app(CommunityPartyService::class)->extractAndSync($this->raid, [$this->body()]);

        $this->assertSame(1, $this->raid->parties()->count(), '같은 멤버 구성은 하나만');
    }

    public function test_preserves_manual_and_replaces_community(): void
    {
        $this->raid->parties()->create(['title' => '내 수동편성', 'source' => 'manual', 'sort' => 0]);
        $this->raid->parties()->create(['title' => '옛 커뮤니티', 'source' => 'community', 'sort' => 1]);
        $this->fakeGemini([['title' => '새 편성', 'members' => ['히후미', '이즈나', '사오리', '히마리']]]);

        app(CommunityPartyService::class)->extractAndSync($this->raid, [$this->body()]);

        $titles = $this->raid->parties()->pluck('title')->all();
        $this->assertContains('내 수동편성', $titles, 'manual 보존');
        $this->assertContains('새 편성', $titles);
        $this->assertNotContains('옛 커뮤니티', $titles, '기존 community 편성은 갈아끼움');
    }

    public function test_zero_extraction_keeps_existing(): void
    {
        $this->raid->parties()->create(['title' => '기존 편성', 'source' => 'community', 'sort' => 0]);
        $this->fakeGemini([]); // 빈 응답

        $stats = app(CommunityPartyService::class)->extractAndSync($this->raid, [$this->body()]);

        $this->assertSame(0, $stats['parties']);
        $this->assertSame(1, $this->raid->parties()->count(), '0건이면 기존 편성 보존');
        $this->assertSame('기존 편성', $this->raid->parties()->first()->title);
    }

    public function test_no_api_key_skips(): void
    {
        config(['services.gemini.api_key' => '']);

        $stats = app(CommunityPartyService::class)->extractAndSync($this->raid, [$this->body()]);

        $this->assertSame(['parties' => 0, 'dropped' => 0], $stats);
    }
}
