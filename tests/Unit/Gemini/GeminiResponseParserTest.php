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

    public function test_parse_intro_recovers_from_code_fenced_json(): void
    {
        // 코드펜스로 감싼 정상 JSON
        $fenced = "```json\n{\"intro\": \"왔군, 그대. 후후\"}\n```";
        $this->assertSame('왔군, 그대. 후후', GeminiResponseParser::parseIntro($fenced));
    }

    public function test_parse_intro_recovers_from_truncated_json(): void
    {
        // 닫는 따옴표/중괄호 없이 잘린 응답 — 코드펜스 + intro 값 일부
        $truncated = '```json {"intro": "왔군, 그대. 뭐, 딱히 궁금한 건 아닌데... 후후';
        $this->assertSame('왔군, 그대. 뭐, 딱히 궁금한 건 아닌데... 후후', GeminiResponseParser::parseIntro($truncated));
    }

    public function test_parse_reply_extracts_narration_message_affinity(): void
    {
        $reply = GeminiResponseParser::parseReply('{"narration": "고개를 든다", "message": "안녕!", "affinity": 80}');

        $this->assertSame('안녕!', $reply['message']);
        $this->assertSame('고개를 든다', $reply['narration']);
        $this->assertSame(80, $reply['affinity']);
    }

    public function test_parse_reply_clamps_affinity_and_allows_empty_narration(): void
    {
        $reply = GeminiResponseParser::parseReply('{"narration": "", "message": "응", "affinity": 250}');

        $this->assertSame('응', $reply['message']);
        $this->assertNull($reply['narration']);
        $this->assertSame(100, $reply['affinity']);
    }

    public function test_parse_reply_falls_back_to_message_on_broken_json(): void
    {
        $reply = GeminiResponseParser::parseReply('{"message": "깨진\n응답"');

        $this->assertSame("깨진\n응답", $reply['message']);
        $this->assertNull($reply['narration']);
        $this->assertNull($reply['affinity']);
    }

    public function test_parse_suggestions_returns_filtered_list(): void
    {
        $list = GeminiResponseParser::parseSuggestions('{"suggestions": ["하나", "  ", "둘"]}');

        $this->assertSame(['하나', '둘'], $list);
        $this->assertSame([], GeminiResponseParser::parseSuggestions('깨진 응답'));
    }

    public function test_parse_persona_extracts_known_fields_only(): void
    {
        $json = '{"name":"호로","short_intro":"현랑","personality":"도도함","likes":"사과","unknown":"무시","empty":""}';
        $persona = GeminiResponseParser::parsePersona($json);

        $this->assertSame('호로', $persona['name']);
        $this->assertSame('현랑', $persona['short_intro']);
        $this->assertSame('도도함', $persona['personality']);
        $this->assertSame('사과', $persona['likes']);
        $this->assertArrayNotHasKey('unknown', $persona);
        $this->assertArrayNotHasKey('empty', $persona);
        $this->assertSame([], GeminiResponseParser::parsePersona('깨진 응답'));
    }

    public function test_parse_persona_recovers_fields_from_broken_json(): void
    {
        // example_dialogue 안의 따옴표가 이스케이프되지 않아 json_decode가 실패하는 흔한 케이스
        $broken = '{"name":"호로","personality":"도도함","example_dialogue":"유저: 안녕 캐릭터: "후후, 나는 호로다""}';
        $persona = GeminiResponseParser::parsePersona($broken);

        $this->assertSame('호로', $persona['name']);
        $this->assertSame('도도함', $persona['personality']);
    }

    public function test_parse_narration_extracts_field(): void
    {
        $this->assertSame('비가 내린다', GeminiResponseParser::parseNarration('{"narration": "비가 내린다"}'));
        $this->assertNull(GeminiResponseParser::parseNarration('{"narration": ""}'));
        $this->assertNull(GeminiResponseParser::parseNarration('{"message": "지문 아님"}'));
    }
}
