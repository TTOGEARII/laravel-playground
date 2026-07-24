<?php

namespace Tests\Feature\EventCalendar;

use App\Enums\EventCalendar\EventKind;
use App\Services\EventCalendar\Sources\IllustarDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class IllustarDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_parses_sidecar_banner_texts(): void
    {
        // 정찰 실측 형식의 배너 텍스트(이틀 행사·요일 괄호 줄 포함)
        Process::fake([
            '*' => Process::result(json_encode(['source' => 'illustar', 'items' => [
                ['text' => "2026년 8월 1, 2일 (토, 일)\n부산 벡스코 제2전시장 4홀"],
                ['text' => "2026년 10월 10, 11일 (토, 일)\n일산 킨텍스"],
                ['text' => '날짜 없는 잡텍스트 2026'],
            ]], JSON_UNESCAPED_UNICODE)),
        ]);

        $events = app(IllustarDriver::class)->collect();

        $this->assertCount(2, $events, '날짜 파싱 불가 텍스트는 스킵');
        $first = $events[0];
        $this->assertSame('illustar', $first->source);
        $this->assertSame('illustar-2026-08-01', $first->externalKey);
        $this->assertSame(EventKind::Doujin, $first->kind);
        $this->assertSame('일러스타 페스 (부산)', $first->title);
        $this->assertSame('2026-08-01', $first->startsOn);
        $this->assertSame('2026-08-02', $first->endsOn);
        $this->assertSame('부산 벡스코 제2전시장 4홀', $first->venue);

        $this->assertSame('일러스타 페스 (일산)', $events[1]->title);
    }

    public function test_sidecar_failure_returns_empty(): void
    {
        Process::fake(['*' => Process::result('', 'boom', 1)]);

        $this->assertSame([], app(IllustarDriver::class)->collect());
    }
}
