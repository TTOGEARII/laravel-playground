<?php

namespace App\Services\Gemini;

use App\Models\ChatCharacter;

class PromptBuilder
{
    /**
     * 저장된 인트로 문구 기반 첫 인사 생성 프롬프트
     */
    public static function introFromStored(ChatCharacter $character, string $introMessage): string
    {
        $lines = [
            "다음 인트로 문구를 바탕으로, 이 캐릭터가 대화창에서 유저에게 하는 \"첫 인사\" 한 마디를 한국어로 자연스럽게 작성해주세요.",
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
            "다음 설정의 캐릭터가 대화창에서 유저에게 하는 \"첫 인사\" 한 마디를 한국어로 한 문장만 작성해주세요.",
            "캐릭터 이름: {$character->name}",
            "한 줄 소개: " . ($character->short_intro ?? ''),
        ];

        if (filled($character->character_detail)) {
            $lines[] = "상세 설정: " . \Str::limit($character->character_detail, 300);
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
            return "{$who}: " . trim((string) ($m['content'] ?? ''));
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
            "당신은 캐릭터 \"{$character->name}\"입니다. 설정에 맞게 대화하세요.",
            "한 줄 소개: " . ($character->short_intro ?? ''),
        ];

        if (filled($character->character_detail)) {
            $lines[] = "상세: " . \Str::limit($character->character_detail, 400);
        }

        if (filled($character->speech_style)) {
            $lines[] = "말투: {$character->speech_style}";
        }

        $lines[] = "\n한국어로만 답하고, 캐릭터 한 사람으로 짧고 자연스럽게 말하세요.";

        return self::appendJsonInstruction($lines, '{"message": "여기에 캐릭터의 대답 한 문장만 넣으세요"}');
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
