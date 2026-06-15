<?php

namespace Tests\Unit\Gemini;

use App\Services\Gemini\GeminiResponseParser;
use PHPUnit\Framework\TestCase;

class GeminiResponseParserTest extends TestCase
{
    public function test_extract_text_reads_candidate_text(): void
    {
        $json = ['candidates' => [['content' => ['parts' => [['text' => '  안녕하세요  ']]]]]];

        $this->assertSame('안녕하세요', GeminiResponseParser::extractText($json));
    }

    public function test_extract_text_returns_null_for_empty_response(): void
    {
        $this->assertNull(GeminiResponseParser::extractText([]));
        $this->assertNull(GeminiResponseParser::extractText(['candidates' => [['content' => ['parts' => [['text' => '   ']]]]]]));
    }

    public function test_parse_json_strips_code_fence(): void
    {
        $text = "```json\n{\"message\": \"테스트\"}\n```";

        $this->assertSame(['message' => '테스트'], GeminiResponseParser::parseJson($text));
    }

    public function test_parse_message_from_well_formed_json(): void
    {
        $this->assertSame('잘 지냈어요', GeminiResponseParser::parseMessage('{"message": "잘 지냈어요"}'));
        $this->assertSame('텍스트 키도 지원', GeminiResponseParser::parseMessage('{"text": "텍스트 키도 지원"}'));
    }

    public function test_parse_message_falls_back_to_regex_for_broken_json(): void
    {
        // 닫는 중괄호 누락 등으로 json_decode 실패 시 정규식 추출 폴백
        $broken = '{"message": "줄바꿈\n포함 응답"';

        $this->assertSame("줄바꿈\n포함 응답", GeminiResponseParser::parseMessage($broken));
    }

    public function test_parse_message_returns_null_when_unparseable(): void
    {
        $this->assertNull(GeminiResponseParser::parseMessage('완전히 깨진 응답'));
    }

    public function test_parse_intro_extracts_intro_field(): void
    {
        $this->assertSame('처음 뵙겠습니다', GeminiResponseParser::parseIntro('{"intro": "처음   뵙겠습니다"}'));
        $this->assertNull(GeminiResponseParser::parseIntro('{"message": "intro 아님"}'));
    }
}
