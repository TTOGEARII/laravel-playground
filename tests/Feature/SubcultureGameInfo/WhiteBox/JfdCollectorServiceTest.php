<?php

namespace Tests\Feature\SubcultureGameInfo\WhiteBox;

use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\Raid;
use App\Services\SubcultureGameInfo\Raids\JfdCollectorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 화이트박스: 종합전술시험 수집 — 모음글 메타 파싱·파티 표 파싱·애칭 해석(Gemini 없음).
 */
class JfdCollectorServiceTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'subculture-game-info.raids.jfd.archive_url' => 'https://arca.live/b/bluearchive/113654108',
            'subculture-game-info.raids.jfd.fetch_delay_seconds' => 0,
            'subculture-game-info.raids.jfd.top_entries' => 4,
            'subculture-game-info.raids.jfd.aliases' => ['수기사' => '키사키(수영복)'],
        ]);
        $this->game = Game::create(['slug' => 'bluearchive', 'name' => '블루 아카이브', 'icon' => '💙', 'sort' => 1, 'active_flg' => true]);
        foreach ([['Toki', '토키'], ['HinaD', '히나(드레스)'], ['KisakiS', '키사키(수영복)'], ['Kisaki', '키사키'], ['Aru', '아루'], ['Shun', '슌']] as [$key, $name]) {
            Character::create([
                'subculture_game_id' => $this->game->id, 'external_key' => $key, 'name' => $name,
                'rarity' => '3성', 'source' => 'mollulog', 'active_flg' => true,
            ]);
        }
    }

    private function archiveHtml(): string
    {
        return <<<'HTML'
<div class="article-content">
<p>49차(26.06.30.~26.07.07.)</p>
<p>사격 / 경장갑 / 시가지</p>
<p>3단계 : 적의 받는 대미지 100% 증가</p>
<p>4단계 : AR, SR 기본기 숙련 200% 증가(제한 시간 1:40)</p>
<p><a href="/b/bluearchive/175463374">접대를 도둑맞은 49차 종합전술시험을 알아보자</a></p>
</div><div class="article-menu"></div>
HTML;
    }

    private function postHtml(): string
    {
        return <<<'HTML'
<div class="article-content">
<table><tr><td>점수</td><td>256,138</td><td>https://www.youtube.com/watch?v=abc</td></tr></table>
<p>※중복 없음</p>
<table>
<tr><td>1파티</td><td>85,613</td></tr>
<tr><td>토키</td><td>드히나</td><td>수기사</td><td>키사키</td><td>아루(A)</td><td>슌(탱)</td></tr>
<tr><td>전4</td><td>전4</td><td>전2</td><td>전3</td><td>전4</td><td>3성</td></tr>
</table>
</div><div class="article-menu"></div>
HTML;
    }

    public function test_모음글_메타와_파티_표를_파싱해_레이드로_저장한다(): void
    {
        Http::fake([
            'arca.live/b/bluearchive/113654108*' => Http::response($this->archiveHtml()),
            'arca.live/b/bluearchive/175463374*' => Http::response($this->postHtml()),
        ]);

        $stats = app(JfdCollectorService::class)->collect($this->game);

        $this->assertSame(1, $stats['sessions']);
        $this->assertSame(1, $stats['parties']);
        $this->assertSame(6, $stats['members']);
        $this->assertSame([], $stats['unresolved']);

        $raid = Raid::where('external_key', 'jfd-49')->with('parties.members.character')->firstOrFail();
        $this->assertSame('제49차 종합전술시험 — 사격', $raid->name);
        $this->assertSame('종합전술시험', $raid->raid_type);
        $this->assertSame('2026-06-30', $raid->starts_at->toDateString());
        $this->assertSame('경장갑', $raid->tags['장갑']);
        $this->assertStringContainsString('100% 증가', $raid->tags['3단계']);
        $this->assertSame('arca-jfd', $raid->source);

        $party = $raid->parties->first();
        $this->assertSame('총점 256,138 · 1파티(85,613)', $party->title);
        $this->assertSame('https://www.youtube.com/watch?v=abc', $party->source_url);
        $this->assertStringContainsString('중복 없음', $party->note);

        $members = $party->members;
        // 애칭 해석: 드히나(접두 규칙) → 히나(드레스), 수기사(alias) → 키사키(수영복)
        $this->assertSame('히나(드레스)', $members[1]->character->name);
        $this->assertSame('전4', $members[1]->note);
        $this->assertSame('키사키(수영복)', $members[2]->character->name);
        // "(A)" = 조력자 슬롯, "(탱)" = 운용 메모(스펙과 합침)
        $this->assertSame('assist', $members[4]->slot_type);
        $this->assertSame('아루', $members[4]->character->name);
        $this->assertSame('슌', $members[5]->character->name);
        $this->assertSame('탱 3성', $members[5]->note);
    }

    public function test_모음글_요청_실패는_기존_데이터를_보존한다(): void
    {
        Raid::create([
            'subculture_game_id' => $this->game->id, 'external_key' => 'jfd-48',
            'name' => '제48차 종합전술시험', 'raid_type' => '종합전술시험',
            'starts_at' => now()->subMonth(), 'ends_at' => now()->subMonth()->addWeek(), 'source' => 'arca-jfd',
        ]);
        Http::fake(['arca.live/*' => Http::response('', 500)]);

        $stats = app(JfdCollectorService::class)->collect($this->game);

        $this->assertSame(0, $stats['sessions']);
        $this->assertSame(1, Raid::where('external_key', 'like', 'jfd-%')->count());
    }

    public function test_이미_저장된_차수는_스킵하고_최신_차수만_재수집한다(): void
    {
        Http::fake([
            'arca.live/b/bluearchive/113654108*' => Http::response($this->archiveHtml()),
            'arca.live/b/bluearchive/175463374*' => Http::response($this->postHtml()),
        ]);
        $service = app(JfdCollectorService::class);
        $service->collect($this->game);

        // 두 번째 실행: 49차가 최신이므로 재수집(갱신 허용) — 요청은 모음글+공략글 2회씩
        $stats = $service->collect($this->game);

        $this->assertSame(1, $stats['sessions']);
        $this->assertSame(1, Raid::where('external_key', 'jfd-49')->count()); // 중복 없음
    }
}
