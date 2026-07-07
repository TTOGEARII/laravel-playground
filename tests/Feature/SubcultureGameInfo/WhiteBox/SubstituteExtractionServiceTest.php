<?php

namespace Tests\Feature\SubcultureGameInfo\WhiteBox;

use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\Raid;
use App\Models\SubcultureGameInfo\RaidSubstitute;
use App\Services\SubcultureGameInfo\Raids\SubstituteExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 화이트박스: 대체 캐릭터 추출 서비스의 이름 매칭·닫힌 어휘·manual 보존·graceful degradation 동작.
 * Gemini REST 호출은 Http::fake 로 모킹한다.
 */
class SubstituteExtractionServiceTest extends TestCase
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
            'external_key' => 'total-assault-83',
            'name' => '총력전 #83 - 비나',
            'boss_name' => '비나',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(5),
            'source' => 'mollulog',
        ]);
    }

    private function character(string $key, string $name, array $traits = [], ?Game $game = null): Character
    {
        return Character::create([
            'subculture_game_id' => ($game ?? $this->game)->id, 'external_key' => $key, 'name' => $name,
            'rarity' => '3성', 'traits' => $traits ?: null, 'source' => 'mollulog', 'active_flg' => true,
        ]);
    }

    /** Gemini 가 주어진 관계 배열을 JSON 으로 응답하도록 모킹. */
    private function fakeGemini(array $relations): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode($relations, JSON_UNESCAPED_UNICODE)]]]]],
            ]),
        ]);
    }

    private function body(string $source = 'dc', ?string $url = 'https://gall.dcinside.com/view/1'): array
    {
        return ['source' => $source, 'url' => $url, 'text' => '비나 공략 본문'];
    }

    public function test_정상_추출하면_대체_관계를_저장한다(): void
    {
        $mika = $this->character('1', '미카');
        $saki = $this->character('2', '사키');
        $this->fakeGemini([['primary' => '미카', 'substitutes' => ['사키'], 'note' => '풀돌 기준']]);

        $stats = app(SubstituteExtractionService::class)->extractAndSync($this->raid, [$this->body()]);

        $this->assertSame(['relations' => 1, 'saved' => 1, 'dropped' => 0], $stats);
        $this->assertDatabaseHas('subculture_raid_substitutes', [
            'raid_id' => $this->raid->id,
            'character_id' => $mika->id,
            'substitute_character_id' => $saki->id,
            'note' => '풀돌 기준',
            'source' => 'dc',
            'source_url' => 'https://gall.dcinside.com/view/1',
            'sort' => 0,
        ]);
    }

    public function test_캐릭터_목록에_없는_이름은_버린다(): void
    {
        $this->character('1', '미카');
        $this->character('2', '사키');
        $this->fakeGemini([
            ['primary' => '미카', 'substitutes' => ['없는캐릭', '사키']],
            ['primary' => '유령캐릭', 'substitutes' => ['사키']],
        ]);

        $stats = app(SubstituteExtractionService::class)->extractAndSync($this->raid, [$this->body()]);

        // 미카→사키 만 저장, 목록 밖 이름(없는캐릭 1건 + 유령캐릭 primary 관계 1건)은 dropped
        $this->assertSame(['relations' => 2, 'saved' => 1, 'dropped' => 2], $stats);
        $this->assertSame(1, RaidSubstitute::count());
    }

    public function test_ap_i_키가_없으면_호출_없이_스킵한다(): void
    {
        config(['services.gemini.api_key' => '']);
        $this->character('1', '미카');
        Http::fake();

        $stats = app(SubstituteExtractionService::class)->extractAndSync($this->raid, [$this->body()]);

        $this->assertSame(['relations' => 0, 'saved' => 0, 'dropped' => 0], $stats);
        $this->assertSame(0, RaidSubstitute::count());
        Http::assertNothingSent();
    }

    public function test_커뮤니티_행만_갈아끼우고_manual_행은_보존한다(): void
    {
        $mika = $this->character('1', '미카');
        $saki = $this->character('2', '사키');
        $hoshino = $this->character('3', '호시노');
        $aru = $this->character('4', '아루');

        // 수동 등록(보존 대상) + 이전 추출분(갈아끼움 대상)
        RaidSubstitute::create([
            'raid_id' => $this->raid->id, 'character_id' => $mika->id,
            'substitute_character_id' => $hoshino->id, 'source' => 'manual',
        ]);
        RaidSubstitute::create([
            'raid_id' => $this->raid->id, 'character_id' => $mika->id,
            'substitute_character_id' => $aru->id, 'source' => 'dc',
        ]);

        // 새 추출 결과에 manual 과 같은 쌍(미카→호시노)이 와도 unique 충돌 없이 스킵돼야 한다
        $this->fakeGemini([['primary' => '미카', 'substitutes' => ['사키', '호시노']]]);
        app(SubstituteExtractionService::class)->extractAndSync($this->raid, [$this->body()]);

        $this->assertSame(1, RaidSubstitute::where('source', 'manual')->count());
        $this->assertSame(0, RaidSubstitute::where('substitute_character_id', $aru->id)->count()); // 이전 dc 행 제거
        $this->assertSame(1, RaidSubstitute::where('source', 'dc')->where('substitute_character_id', $saki->id)->count());
    }

    public function test_추출_결과가_비면_기존_커뮤니티_행을_지우지_않는다(): void
    {
        $mika = $this->character('1', '미카');
        $saki = $this->character('2', '사키');

        // 이전에 추출해 둔 양질의 dc 행 — 모델 히컵(빈 응답)에도 보존돼야 한다
        RaidSubstitute::create([
            'raid_id' => $this->raid->id, 'character_id' => $mika->id,
            'substitute_character_id' => $saki->id, 'source' => 'dc',
        ]);

        $this->fakeGemini([]); // 본문은 있으나 관계 추출 0건

        $stats = app(SubstituteExtractionService::class)->extractAndSync($this->raid, [$this->body()]);

        $this->assertSame(0, $stats['saved']);
        $this->assertSame(1, RaidSubstitute::where('source', 'dc')->count());
    }

    public function test_캐릭터당_대체_수_상한을_적용한다(): void
    {
        config(['subculture-game-info.raids.substitutes.max_substitutes_per_character' => 2]);
        $this->character('1', '미카');
        $this->character('2', '사키');
        $this->character('3', '호시노');
        $this->character('4', '아루');
        $this->fakeGemini([['primary' => '미카', 'substitutes' => ['사키', '호시노', '아루']]]);

        $stats = app(SubstituteExtractionService::class)->extractAndSync($this->raid, [$this->body()]);

        $this->assertSame(2, $stats['saved']);
        $this->assertSame(1, $stats['dropped']);
    }

    public function test_브더2_원캐릭터명은_편성에_등장하는_코스튬만_매칭한다(): void
    {
        $bd2 = Game::create(['slug' => 'browndust2', 'name' => '브라운더스트2', 'icon' => '🟤', 'sort' => 2, 'active_flg' => true]);
        $raid = Raid::create([
            'subculture_game_id' => $bd2->id, 'external_key' => 'guild-raid-1', 'name' => '길드 레이드',
            'source' => 'souseha',
        ]);

        $celia = $this->character('c1', '셀리아', [], $bd2);
        $this->character('y1', '유리 - 수영복', ['base_character' => '유리'], $bd2);
        $yuriB = $this->character('y2', '유리 - 학생회장', ['base_character' => '유리'], $bd2);
        $this->character('l1', '라티스 - 기사단장', ['base_character' => '라티스'], $bd2);

        // 편성에는 '유리 - 학생회장' 만 등장 → 원캐릭터명 '유리' 는 이 행으로만 매칭
        $party = $raid->parties()->create(['title' => '추천 편성', 'sort' => 0, 'source' => 'souseha']);
        $party->members()->create(['subculture_character_id' => $yuriB->id, 'sort' => 0]);

        $this->fakeGemini([['primary' => '셀리아', 'substitutes' => ['유리', '라티스']]]);
        $stats = app(SubstituteExtractionService::class)->extractAndSync($raid, [$this->body('arca', 'https://arca.live/b/browndust/1')]);

        // 유리 → 편성 등장 코스튬(학생회장) 매칭, 라티스 → 편성 미등장이라 보수적으로 스킵
        $this->assertSame(1, $stats['saved']);
        $this->assertSame(1, $stats['dropped']);
        $this->assertDatabaseHas('subculture_raid_substitutes', [
            'raid_id' => $raid->id,
            'character_id' => $celia->id,
            'substitute_character_id' => $yuriB->id,
            'source' => 'arca',
        ]);
    }
}
