<?php

namespace Tests\Feature\EventCalendar;

use App\Models\EventCalendar\Event;
use App\Services\EventCalendar\GenreTagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GenreTagServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedEvents(): array
    {
        $a = Event::create(['source' => 'festivallife', 'external_key' => '1', 'kind' => 'concert', 'title' => 'YOASOBI 내한공연', 'starts_on' => '2026-09-01']);
        $b = Event::create(['source' => 'festivallife', 'external_key' => '2', 'kind' => 'concert', 'title' => 'Deep Purple 내한공연', 'starts_on' => '2026-09-02']);
        $c = Event::create(['source' => 'comicworld', 'external_key' => 'comic-1', 'kind' => 'doujin', 'title' => '코믹월드 335', 'starts_on' => '2026-08-15']);

        return [$a, $b, $c];
    }

    private function fakeGemini(array $tags): void
    {
        config(['services.gemini.api_key' => 'test-key']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode(['tags' => $tags])]]]]],
            ]),
        ]);
    }

    public function test_tags_untagged_concerts_with_closed_vocabulary(): void
    {
        [$a, $b, $c] = $this->seedEvents();
        $this->fakeGemini([
            ['id' => $a->id, 'genre' => 'jpop'],
            ['id' => $b->id, 'genre' => 'other'],
            ['id' => $c->id, 'genre' => 'jpop'],   // 요청 밖(doujin) id — 무시돼야 함
            ['id' => $a->id + 999, 'genre' => 'jpop'], // 존재하지 않는 id — 무시
        ]);

        $result = app(GenreTagService::class)->tagUntagged();

        $this->assertSame(2, $result['tagged']);
        $this->assertSame('jpop', $a->fresh()->genre);
        $this->assertSame('other', $b->fresh()->genre);
        $this->assertNull($c->fresh()->genre, '공연이 아닌 행사는 태깅 대상 아님');
    }

    public function test_skips_without_api_key(): void
    {
        config(['services.gemini.api_key' => null]);
        $this->seedEvents();

        $result = app(GenreTagService::class)->tagUntagged();

        $this->assertTrue($result['skipped']);
        $this->assertNull(Event::where('kind', 'concert')->first()->genre);
    }

    public function test_invalid_genre_values_are_ignored(): void
    {
        [$a] = $this->seedEvents();
        $this->fakeGemini([['id' => $a->id, 'genre' => 'kpop']]); // 닫힌 어휘 밖

        $result = app(GenreTagService::class)->tagUntagged();

        $this->assertSame(0, $result['tagged']);
        $this->assertNull($a->fresh()->genre, '어휘 밖 값은 무시 — 다음 실행에 재시도');
    }
}
