<?php

namespace App\Services\Gemini;

use App\Models\ChatCharacter;
use Illuminate\Support\Str;

class PromptBuilder
{
    /**
     * 저장된 인트로 문구 기반 첫 인사 생성 프롬프트
     */
    public static function introFromStored(ChatCharacter $character, string $introMessage): string
    {
        $lines = [
            '다음 인트로 문구를 바탕으로, 이 캐릭터가 대화창에서 유저에게 하는 "첫 인사" 한 마디를 한국어로 자연스럽게 작성해주세요.',
            "캐릭터 이름: {$character->name}",
            "인트로: {$introMessage}",
        ];

        if (filled($character->speech_style)) {
            $lines[] = "말투: {$character->speech_style}";
        }

        return self::appendJsonInstruction($lines, '{"intro": "여기에 첫 인사 한 문장만 넣으세요"}');
    }

    /**
     * 캐릭터 설정 기반 첫 인사 생성 프롬프트
     */
    public static function greeting(ChatCharacter $character): string
    {
        $lines = [
            '다음 설정의 캐릭터가 대화창에서 유저에게 하는 "첫 인사" 한 마디를 한국어로 한 문장만 작성해주세요.',
            "캐릭터 이름: {$character->name}",
            '한 줄 소개: '.($character->short_intro ?? ''),
        ];

        if (filled($character->character_detail)) {
            $lines[] = '상세 설정: '.Str::limit($character->character_detail, 300);
        }

        if (filled($character->speech_style)) {
            $lines[] = "말투: {$character->speech_style}";
        }

        return self::appendJsonInstruction($lines, '{"intro": "여기에 첫 인사 한 문장만 넣으세요"}');
    }

    /**
     * 대화 요약 프롬프트
     */
    public static function summarize(array $messages, ?string $previousSummary = null): string
    {
        $lines = array_map(function ($m) {
            $who = ($m['role'] ?? '') === 'user' ? '유저' : '캐릭터';

            return "{$who}: ".trim((string) ($m['content'] ?? ''));
        }, $messages);

        $conv = implode("\n", $lines);

        if (filled($previousSummary)) {
            return "이전까지 요약: {$previousSummary}\n\n아래 대화를 위 맥락에 이어서 2~3문장으로 요약해 주세요. 한국어로만 출력.\n\n대화:\n{$conv}";
        }

        return "다음 대화를 2~3문장으로 요약해 주세요. 한국어로만 출력하고, 따옴표나 설명 없이 요약문만 출력하세요.\n\n대화:\n{$conv}";
    }

    /**
     * 캐릭터 시스템 프롬프트
     */
    public static function characterSystem(ChatCharacter $character): string
    {
        $lines = [
            "당신은 캐릭터 \"{$character->name}\"입니다. 아래 설정을 철저히 지키며 그 인물로서 일관되게 대화하세요.",
            '한 줄 소개: '.($character->short_intro ?? ''),
        ];

        if (filled($character->world_setting)) {
            $lines[] = "[세계관/배경]\n".Str::limit($character->world_setting, 800);
        }

        if (filled($character->character_detail)) {
            $lines[] = '상세 설정: '.Str::limit($character->character_detail, 400);
        }

        if (filled($character->personality)) {
            $lines[] = '성격: '.Str::limit($character->personality, 400);
        }

        if (filled($character->appearance)) {
            $lines[] = '외모: '.Str::limit($character->appearance, 400);
        }

        if (filled($character->likes)) {
            $lines[] = "좋아하는 것: {$character->likes}";
        }

        if (filled($character->dislikes)) {
            $lines[] = "싫어하는 것: {$character->dislikes}";
        }

        if (filled($character->user_alias)) {
            $lines[] = "유저를 부를 때는 \"{$character->user_alias}\"(이)라고 부르세요.";
        }

        if (filled($character->speech_style)) {
            $lines[] = "말투: {$character->speech_style}";
        }

        if (filled($character->example_dialogue)) {
            $lines[] = "[예시 대화] — 아래의 말투와 성격을 참고하되 그대로 복사하지는 마세요.\n".Str::limit($character->example_dialogue, 1200);
        }

        $lines[] = "\n한국어로만 답하세요. 응답은 다음 두 요소로 구성합니다.";
        $lines[] = '- narration: 캐릭터의 행동·표정·주변 상황을 3인칭으로 묘사한 지문 (1~2문장, 없으면 빈 문자열)';
        $lines[] = '- message: 캐릭터가 직접 내뱉는 대사 (따옴표 없이 내용만, 짧고 자연스럽게)';
        $lines[] = '또한 유저와의 대화 흐름을 반영한 현재 호감도를 affinity(0~100 정수)로 함께 출력하세요.';

        return self::appendJsonInstruction(
            $lines,
            '{"narration": "캐릭터의 행동/상황 묘사 (없으면 빈 문자열)", "message": "캐릭터의 대사 한두 문장", "affinity": 50}'
        );
    }

    /**
     * 유저 추천 답변 생성 프롬프트 (유저 입장에서 캐릭터에게 할 만한 답변 2~3개)
     */
    public static function suggestReplies(ChatCharacter $character, array $recentMessages): string
    {
        $lines = [
            "아래는 \"{$character->name}\" 캐릭터와 유저의 최근 대화입니다.",
            '이 대화의 흐름을 이어가기 위해 "유저"가 캐릭터에게 할 만한 자연스러운 답변 3개를 한국어로 제안하세요.',
            '각 답변은 한 문장으로 짧게, 서로 다른 방향(질문/공감/장난 등)으로 작성하세요.',
            '',
            '최근 대화:',
            self::formatConversation($recentMessages),
        ];

        return self::appendJsonInstruction($lines, '{"suggestions": ["추천 답변 1", "추천 답변 2", "추천 답변 3"]}');
    }

    /**
     * 상황 묘사(지문) 생성 프롬프트 — 현재 장면을 한 단락 이어서 묘사
     */
    public static function narrate(ChatCharacter $character, array $recentMessages): string
    {
        $lines = [
            "아래는 \"{$character->name}\" 캐릭터와 유저의 최근 대화입니다.",
            '이 장면을 이어서, 캐릭터의 행동과 주변 분위기를 3인칭 지문으로 한 단락(2~3문장) 묘사하세요.',
            '대사는 넣지 말고 상황 묘사만, 한국어로 작성하세요.',
            '',
            '최근 대화:',
            self::formatConversation($recentMessages),
        ];

        return self::appendJsonInstruction($lines, '{"narration": "여기에 상황 묘사 한 단락을 넣으세요"}');
    }

    /**
     * 소설/작품 정보를 분석해 캐릭터 페르소나 필드를 채우는 프롬프트.
     *
     * @param  array<int, string>  $genreValues  허용 genre 값
     * @param  array<int, string>  $targetValues  허용 target 값
     */
    public static function analyzePersona(string $source, array $genreValues, array $targetValues): string
    {
        $genres = implode(', ', $genreValues);
        $targets = implode(', ', $targetValues);

        $lines = [
            '아래는 어떤 소설/작품의 정보(시놉시스, 캐릭터 설명, 세계관 설정 등)입니다.',
            '이 정보를 분석해, 작품을 대표하는 캐릭터 한 명을 AI 채팅봇으로 만들기 위한 "페르소나"를 한국어로 가공해 작성하세요.',
            '정보에 없는 항목은 작품의 분위기에 맞게 자연스럽게 추론해 채우되, 원작 설정과 모순되지 않게 하세요.',
            'example_dialogue에는 그 캐릭터의 말투가 잘 드러나는 짧은 대화 2~3개를 직접 창작해 넣으세요. "유저:"와 "캐릭터:" 형식으로, 줄바꿈으로 구분합니다.',
            "genre는 반드시 다음 값 중 하나만 사용: {$genres}",
            "target은 반드시 다음 값 중 하나만 사용: {$targets}",
            'short_intro는 50자 이내로 작성하세요.',
            '',
            '작품 정보:',
            Str::limit($source, 4000),
        ];

        $example = '{"name":"캐릭터 이름","short_intro":"한 줄 소개","character_detail":"상세 설정","personality":"성격","appearance":"외모","likes":"좋아하는 것","dislikes":"싫어하는 것","user_alias":"유저를 부르는 호칭","speech_style":"말투 특징","example_dialogue":"유저: 안녕\\n캐릭터: ...","world_setting":"세계관/배경 설정","genre":"'.($genreValues[0] ?? 'romance').'","target":"'.($targetValues[0] ?? 'all').'"}';

        return self::appendJsonInstruction($lines, $example);
    }

    /**
     * 대화 배열을 "유저:/캐릭터:" 형태 텍스트로 변환
     */
    private static function formatConversation(array $messages): string
    {
        $lines = array_map(function ($m) {
            $who = ($m['role'] ?? '') === 'user' ? '유저' : '캐릭터';

            return "{$who}: ".trim((string) ($m['content'] ?? ''));
        }, $messages);

        return implode("\n", $lines);
    }

    /**
     * JSON 응답 형식 안내 문구 추가
     */
    private static function appendJsonInstruction(array $lines, string $jsonExample): string
    {
        $lines[] = '';
        $lines[] = '응답은 반드시 아래 JSON 구조만 출력하세요. 다른 설명이나 마크다운 없이 JSON만 출력합니다.';
        $lines[] = $jsonExample;

        return implode("\n", $lines);
    }
}
