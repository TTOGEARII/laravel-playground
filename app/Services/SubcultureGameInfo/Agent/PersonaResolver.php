<?php

namespace App\Services\SubcultureGameInfo\Agent;

use App\Models\MyWifeBot\ChatCharacter;
use App\Models\SubcultureGameInfo\Agent\AgentSession;
use Illuminate\Support\Str;

/**
 * 에이전트 페르소나(말투) 해석 + 시스템 프롬프트 조립.
 * 프리셋(config personas) 또는 내 챗봇 캐릭터(ChatCharacter) 둘 다 지원.
 * 가드레일(서브컬쳐 게임 정보만)은 페르소나와 무관하게 항상 붙는다.
 */
class PersonaResolver
{
    /** 세션의 페르소나로 시스템 프롬프트를 만든다. $game = 사용자가 보던 게임 컨텍스트(슬러그). */
    public function systemPrompt(AgentSession $session, ?string $game = null): string
    {
        $voice = $this->voice($session);
        $context = $this->gameContext($game);

        return implode("\n", [
            '너는 서브컬쳐 게임 정보 전문 AI 에이전트다.',
            '',
            '## 역할',
            '- 리딤코드, 레이드 일정·추천 편성, 캐릭터 정보, 이벤트 챌린지 공략, 커뮤니티 공략글 등 '
                .'서브컬쳐(수집형/가챠) 게임 정보를 도와준다.',
            '- 다루는 게임: 원신, 붕괴 스타레일, 젠레스 존 제로, 블루 아카이브, 명조, 트릭컬 리바이브, 승리의 여신 니케, 브라운더스트2.',
            '',
            '## 도구 사용',
            '- 정보가 필요하면 반드시 제공된 도구(툴)를 먼저 호출해 실제 데이터를 확인하고 답한다. 추측으로 지어내지 않는다.',
            '- 도구 결과가 비어 있으면 "현재 수집된 정보가 없다"고 솔직히 말한다.',
            '- 게임 이름은 한국어 통칭(블아, 스레 등)이어도 알아듣고 적절한 게임으로 매핑한다.',
            '- 공략 영상·가이드 영상을 찾아달라면 search_youtube_videos 로 검색해 영상 링크를 제공한다(검색어에 게임명 포함).',
            '- "내 캐릭터", "내가 가진", "내 보유로 조합" 등 사용자의 보유 캐릭터가 필요하면 get_my_characters 로 내 캐릭터 풀을 조회해 활용한다.',
            ...$context,
            '',
            '## 이름·명칭 (해외 게임 오역 방지)',
            '- 원신·스타레일·젠존제 등 해외 게임의 무기·엔진·광추·세트·아이템 이름은 반드시 공식 한국어 명칭을 쓴다. '
                .'영문/중문을 직역(기계번역)하지 말고, 확실하지 않으면 영문 원명을 병기한다(예: 헬파이어 기어(Hellfire Gear)).',
            ...$this->glossaryLines(),
            '',
            '## 가드레일',
            '- 서브컬쳐 게임과 무관한 질문(일반 상식, 코딩, 시사 등)은 정중히 거절하고 서브컬쳐 게임 주제로 유도한다.',
            '- 도구가 돌려준 카드 데이터는 화면에 예쁘게 렌더되니, 답변 텍스트에서 표를 장황하게 반복하지 말고 핵심만 요약한다.',
            '',
            '## 말투',
            $voice,
        ]);
    }

    /**
     * 명칭 교정표·개별 사실을 프롬프트 라인으로. config 로 관리(오역 발견 시 한 줄 추가).
     *
     * @return list<string>
     */
    private function glossaryLines(): array
    {
        $lines = [];
        foreach ((array) config('subculture-agent.term_glossary', []) as $wrong => $right) {
            $lines[] = "- \"{$wrong}\" 이 아니라 \"{$right}\" 라고 쓴다.";
        }
        foreach ((array) config('subculture-agent.term_facts', []) as $fact) {
            $lines[] = "- {$fact}";
        }

        return $lines;
    }

    /**
     * 게임 컨텍스트 지시문 — 사용자가 SGI 페이지에서 특정 게임을 보다가 넘어온 경우,
     * 게임을 명시하지 않은 질문("리딤코드 알려줘")을 그 게임 기준으로 해석하게 한다.
     *
     * @return list<string>
     */
    private function gameContext(?string $game): array
    {
        $name = $game !== null ? config("subculture-game-info.games.{$game}.name") : null;
        if ($name === null) {
            return [];
        }

        return [
            "- 사용자는 지금 '{$name}' 정보 화면을 보다가 질문하러 왔다. "
                .'질문에 게임이 명시되지 않았다면 이 게임 기준으로 답한다(다른 게임을 명시하면 그 게임을 따른다).',
        ];
    }

    /** 페르소나 말투 지시문. */
    private function voice(AgentSession $session): string
    {
        if ($session->persona_kind === 'character' && $session->persona_ref !== null) {
            $character = ChatCharacter::find($session->persona_ref);
            if ($character !== null) {
                $lines = ["'{$character->name}' 캐릭터로서 대답한다."];
                if (filled($character->speech_style)) {
                    $lines[] = "말투: {$character->speech_style}";
                }
                if (filled($character->short_intro)) {
                    $lines[] = '설정: '.$character->short_intro;
                }
                if (filled($character->character_detail)) {
                    $lines[] = '상세: '.Str::limit($character->character_detail, 200);
                }
                $lines[] = '단, 캐릭터 연기를 하더라도 정보는 반드시 도구 결과에 근거해 정확히 전한다.';

                return implode("\n", $lines);
            }
        }

        $preset = config('subculture-agent.personas.'.($session->persona_ref ?? 'guide'))
            ?? config('subculture-agent.personas.guide');

        return $preset['speech'];
    }

    /**
     * 페르소나 선택지 = 챗봇 캐릭터 전체(MyWifeBot 캐릭터 모아보기와 동일하게 모든 사용자 공개).
     * 내 캐릭터를 앞에 두고, 카드 UI용 설명·이미지·내 챗봇 여부를 포함한다.
     */
    public function options(?int $userId): array
    {
        $characters = ChatCharacter::latest('created_at')->limit(60)->get()
            ->map(fn (ChatCharacter $c) => [
                'kind' => 'character',
                'ref' => (string) $c->id,
                'name' => $c->name,
                'emoji' => '💬',
                'description' => (string) ($c->short_intro ?? ''),
                'image' => $c->image_url,
                'is_mine' => $userId !== null && $c->user_id === $userId,
            ])
            ->sortByDesc('is_mine') // 내 챗봇 먼저(안정 정렬이라 나머지는 최신순 유지)
            ->values()
            ->all();

        return ['presets' => [], 'characters' => $characters];
    }
}
