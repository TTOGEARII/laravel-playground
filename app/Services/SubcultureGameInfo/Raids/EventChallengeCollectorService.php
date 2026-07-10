<?php

namespace App\Services\SubcultureGameInfo\Raids;

use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\EventChallenge;
use App\Models\SubcultureGameInfo\Game;
use App\Services\SubcultureGameInfo\Sources\Concerns\FetchesWebContent;
use App\Services\SubcultureGameInfo\Sources\Drivers\ArcaGuidePostDriver;
use App\Services\SubcultureGameInfo\Sources\DTO\GuidePostData;
use Illuminate\Support\Facades\Log;
use Symfony\Component\CssSelector\CssSelectorConverter;

/**
 * 이벤트 챌린지 공략 수집(블아) — 아카 채널의 이벤트 '올인원' 글을 찾아
 * 챌린지 섹션(>> Challenge 01 …)을 스테이지 단위로 파싱한다(Gemini 불필요, 결정적 파싱).
 *
 * 글 구조 규칙(2026-07 실측):
 *  - ">> Challenge {NN|EX} {맵이름} ({클리어 조건})" 헤더 뒤로 공략 텍스트가 이어지고
 *  - 스테이지마다 유튜브 임베드(iframe)가 붙는다.
 *  - 조합은 표가 아닌 본문 언급이라, 캐릭터 마스터와 이름 매칭해 '언급 캐릭터'로 저장한다.
 */
class EventChallengeCollectorService
{
    use FetchesWebContent;

    public function __construct(private ArcaGuidePostDriver $arca) {}

    /**
     * @return array{event: ?string, stages: int, pruned: int}
     */
    public function collect(Game $game): array
    {
        $cfg = config('subculture-game-info.raids.event_challenges');
        $stats = ['event' => null, 'stages' => 0, 'pruned' => 0];

        // 후보: 제목이 시리즈 형식("저장용 … 올인원")인 최신 글부터 시도
        $requiredWords = (array) ($cfg['require_title_words'] ?? [$cfg['search_keyword']]);
        $candidates = collect($this->arca->searchPosts($game->slug, (string) $cfg['search_keyword']))
            ->filter(fn (GuidePostData $p) => collect($requiredWords)
                ->every(fn (string $word) => mb_stripos($p->title, $word) !== false))
            ->sortByDesc(fn (GuidePostData $p) => $p->postedAt?->getTimestamp() ?? 0)
            ->take(3);

        foreach ($candidates as $post) {
            usleep((int) ((float) $cfg['fetch_delay_seconds'] * 1_000_000));
            $html = $this->getHtml($post->url);
            if ($html === null) {
                continue;
            }

            $stages = $this->parseChallenges($html, $game);
            if ($stages === []) {
                continue; // 챌린지 섹션이 없는 올인원(종전시 등) — 다음 후보
            }

            $eventName = $this->eventNameFromTitle($post->title, (string) $cfg['search_keyword']);
            [$startsAt, $endsAt] = $this->parsePeriod($html);

            foreach ($stages as $stage) {
                EventChallenge::updateOrCreate(
                    [
                        'subculture_game_id' => $game->id,
                        'event_key' => $post->externalId,
                        'stage_label' => $stage['label'],
                    ],
                    [
                        'event_name' => $eventName,
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                        'stage_name' => $stage['name'],
                        'clear_condition' => $stage['condition'],
                        'summary' => $stage['summary'],
                        'video_url' => $stage['video'],
                        'mentioned' => $stage['mentioned'],
                        'source_url' => $post->url,
                    ],
                );
            }

            // 이전 이벤트 스테이지 정리 — 대시보드는 항상 최신 이벤트 하나만 노출
            $stats['pruned'] = EventChallenge::where('subculture_game_id', $game->id)
                ->where('event_key', '!=', $post->externalId)
                ->delete();

            $stats['event'] = $eventName;
            $stats['stages'] = count($stages);

            return $stats;
        }

        Log::info('[SGI-EVENT] 챌린지 공략 올인원 글을 찾지 못함', ['game' => $game->slug]);

        return $stats;
    }

    /** 제목 → 이벤트명: "저장용 게임개발부 청소 대작전! 올인원" → "게임개발부 청소 대작전!" */
    private function eventNameFromTitle(string $title, string $keyword): string
    {
        $name = trim(str_ireplace(['저장용', $keyword], '', $title));

        return $name !== '' ? $name : $title;
    }

    /**
     * 본문에서 한섭 이벤트 기간을 찾는다. ">> 한섭 : 2026-06-30 ~ 2026-07-14"
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function parsePeriod(string $html): array
    {
        $text = $this->stripToText($html);
        if (preg_match('/한섭\s*:?\s*(\d{4}-\d{2}-\d{2})\s*~\s*(\d{4}-\d{2}-\d{2})/u', $text, $m)) {
            return [$m[1], $m[2]];
        }

        return [null, null];
    }

    /**
     * 본문 DOM 을 문서 순서(텍스트/iframe 토큰)로 훑어 챌린지 스테이지들을 뽑는다.
     *
     * @return array<int, array{label: string, name: ?string, condition: ?string, summary: ?string, video: ?string, mentioned: array}>
     */
    private function parseChallenges(string $html, Game $game): array
    {
        $xp = $this->xpath($html);
        $content = $xp->query((new CssSelectorConverter)->toXPath('.article-content'))?->item(0);
        if (! $content instanceof \DOMElement) {
            return [];
        }

        // 문서 순서 토큰화: 텍스트 조각 + iframe(영상)
        $tokens = [];
        foreach ($xp->query('.//text() | .//iframe', $content) as $node) {
            if ($node instanceof \DOMElement && strtolower($node->nodeName) === 'iframe') {
                $tokens[] = ['iframe', (string) $node->getAttribute('src')];
            } else {
                $text = trim((string) $node->textContent);
                if ($text !== '') {
                    $tokens[] = ['text', $text];
                }
            }
        }

        $stages = [];
        $current = null;
        foreach ($tokens as [$type, $value]) {
            if ($type === 'text' && preg_match('/Challenge\s*(EX|\d+)/iu', $value, $m)) {
                if ($current !== null) {
                    $stages[] = $current;
                }
                $current = $this->newStage($value, strtoupper($m[1]));

                continue;
            }
            if ($current === null) {
                continue;
            }
            if ($type === 'iframe') {
                $current['video'] ??= $this->toWatchUrl($value);
            } else {
                $current['lines'][] = $value;
            }
        }
        if ($current !== null) {
            $stages[] = $current;
        }

        if ($stages === []) {
            return [];
        }

        $roster = $this->characterRoster($game);

        return array_map(function (array $stage) use ($roster) {
            // nbsp·빈 줄 정리 후 요약 생성
            $lines = array_values(array_filter(array_map(
                fn (string $line) => trim(str_replace("\u{00A0}", ' ', $line)),
                $stage['lines'],
            ), fn (string $line) => $line !== ''));
            $summary = mb_substr(implode("\n", $lines), 0, 600);

            return [
                'label' => $stage['label'],
                'name' => $stage['name'],
                'condition' => $stage['condition'],
                'summary' => $summary !== '' ? $summary : null,
                'video' => $stage['video'],
                'mentioned' => $this->mentionedCharacters($summary, $roster),
            ];
        }, $stages);
    }

    /** 헤더 한 줄 → 스테이지 뼈대. ">> Challenge 01 工業実習室・別館 (90초 이내 클리어)" */
    private function newStage(string $header, string $no): array
    {
        $name = null;
        $condition = null;
        if (preg_match('/Challenge\s*(?:EX|\d+)\s*(.*)$/iu', $header, $m)) {
            $rest = trim($m[1]);
            if (preg_match('/^(.*?)\s*\(([^)]+)\)\s*$/u', $rest, $mm)) {
                $name = trim($mm[1]) !== '' ? trim($mm[1]) : null;
                $condition = trim($mm[2]);
            } elseif ($rest !== '') {
                $name = $rest;
            }
        }

        return [
            'label' => 'Challenge '.$no,
            'name' => $name,
            'condition' => $condition,
            'video' => null,
            'lines' => [],
        ];
    }

    /** 유튜브 임베드 → 시청 URL (start 파라미터 유지). 유튜브가 아니면 원본 유지. */
    private function toWatchUrl(string $embedUrl): ?string
    {
        if ($embedUrl === '') {
            return null;
        }
        if (preg_match('~youtube(?:-nocookie)?\.com/embed/([A-Za-z0-9_-]{6,})(?:\?[^#]*start=(\d+))?~', $embedUrl, $m)) {
            return 'https://www.youtube.com/watch?v='.$m[1].(isset($m[2]) && $m[2] !== '' ? '&t='.$m[2].'s' : '');
        }

        return $embedUrl;
    }

    /**
     * 캐릭터 사전: 마스터 이름 + 커뮤니티 애칭(종전시 aliases 재사용) → 마스터 이름.
     *
     * @return array<string, string> 표기 → 마스터 이름
     */
    private function characterRoster(Game $game): array
    {
        $roster = [];
        foreach (Character::where('subculture_game_id', $game->id)->where('active_flg', true)->pluck('name') as $name) {
            if (mb_strlen($name) >= 2) {
                $roster[$name] = $name;
            }
        }
        foreach ((array) config('subculture-game-info.raids.jfd.aliases', []) as $alias => $master) {
            $roster[$alias] = $master;
        }

        return $roster;
    }

    /**
     * 요약 텍스트에 언급된 캐릭터를 마스터 이름으로 수집(등장 순서, 최대 10명).
     * 긴 표기(애칭 포함)부터 매칭하고 잡은 구간은 지워서, '드히나' 안의 '히나' 같은 중복 매칭을 막는다.
     * 토큰 앞이 한글이면 다른 단어의 꼬리로 보고 버린다('임토키' 안의 '토키' 등 적 유닛 오인 방지).
     *
     * @param  array<string, string>  $roster
     */
    private function mentionedCharacters(string $text, array $roster): array
    {
        $tokens = array_keys($roster);
        usort($tokens, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        $found = [];
        foreach ($tokens as $token) {
            $offset = 0;
            while (($pos = mb_stripos($text, $token, $offset)) !== false) {
                $prev = $pos > 0 ? mb_substr($text, $pos - 1, 1) : '';
                if ($prev !== '' && preg_match('/[가-힣]/u', $prev)) {
                    $offset = $pos + 1; // 앞이 한글 = 다른 단어의 일부 — 다음 등장 위치 탐색

                    continue;
                }
                $master = $roster[$token];
                $found[$master] = min($found[$master] ?? PHP_INT_MAX, $pos);
                // 잡은 구간은 지워 하위(짧은) 토큰의 중복 매칭 방지
                $text = mb_substr($text, 0, $pos).str_repeat(' ', mb_strlen($token)).mb_substr($text, $pos + mb_strlen($token));
                break;
            }
        }
        asort($found);

        return array_slice(array_keys($found), 0, 10);
    }
}
