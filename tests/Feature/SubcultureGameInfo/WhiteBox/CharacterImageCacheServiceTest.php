<?php

namespace Tests\Feature\SubcultureGameInfo\WhiteBox;

use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Game;
use App\Services\SubcultureGameInfo\Raids\CharacterImageCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 화이트박스: 캐릭터 이미지 로컬 캐시 — 다운로드·멱등·비이미지 응답 방어·폴백 접근자.
 */
class CharacterImageCacheServiceTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->game = Game::create(['slug' => 'nikke', 'name' => '니케', 'icon' => '🔫', 'sort' => 1, 'active_flg' => true]);
    }

    private function makeCharacter(array $overrides = []): Character
    {
        return Character::create(array_merge([
            'subculture_game_id' => $this->game->id,
            'external_key' => '5129',
            'name' => '테스트',
            'image_url' => 'https://img.example.com/si/5129.webp',
            'source' => 'letsdoro',
            'active_flg' => true,
        ], $overrides))->setRelation('game', $this->game);
    }

    public function test_이미지를_다운로드해_public_디스크에_저장하고_경로를_기록한다(): void
    {
        Http::fake(['img.example.com/*' => Http::response('웹피바이너리', 200, ['Content-Type' => 'image/webp'])]);
        $character = $this->makeCharacter();

        app(CharacterImageCacheService::class)->cache($character);

        $this->assertSame('subculture/characters/nikke/5129.webp', $character->fresh()->image_path);
        Storage::disk('public')->assertExists('subculture/characters/nikke/5129.webp');
    }

    public function test_이미_캐시된_경우_재다운로드하지_않는다(): void
    {
        Storage::disk('public')->put('subculture/characters/nikke/5129.webp', 'x');
        Http::fake();
        $character = $this->makeCharacter(['image_path' => 'subculture/characters/nikke/5129.webp']);

        app(CharacterImageCacheService::class)->cache($character);

        Http::assertNothingSent();
    }

    public function test_force_면_기존_캐시가_있어도_재다운로드한다(): void
    {
        Storage::disk('public')->put('subculture/characters/nikke/5129.webp', 'old');
        Http::fake(['img.example.com/*' => Http::response('new', 200, ['Content-Type' => 'image/webp'])]);
        $character = $this->makeCharacter(['image_path' => 'subculture/characters/nikke/5129.webp']);

        app(CharacterImageCacheService::class)->cache($character, force: true);

        $this->assertSame('new', Storage::disk('public')->get('subculture/characters/nikke/5129.webp'));
    }

    public function test_비이미지_응답은_캐시하지_않는다(): void
    {
        Http::fake(['img.example.com/*' => Http::response('<html>404</html>', 404, ['Content-Type' => 'text/html'])]);
        $character = $this->makeCharacter();

        app(CharacterImageCacheService::class)->cache($character);

        $this->assertNull($character->fresh()->image_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_image_url_이_외부주소가_아니면_아무것도_하지_않는다(): void
    {
        Http::fake();
        $character = $this->makeCharacter(['image_url' => null]);

        app(CharacterImageCacheService::class)->cache($character);

        Http::assertNothingSent();
    }

    public function test_display_image_url_은_캐시_우선_원본_폴백이다(): void
    {
        $cached = $this->makeCharacter(['image_path' => 'subculture/characters/nikke/5129.webp']);
        $remote = $this->makeCharacter(['external_key' => '1007']);

        $this->assertStringContainsString('storage/subculture/characters/nikke/5129.webp', $cached->display_image_url);
        $this->assertSame('https://img.example.com/si/5129.webp', $remote->display_image_url);
    }
}
