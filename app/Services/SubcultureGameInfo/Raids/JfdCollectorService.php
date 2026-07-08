<?php

namespace App\Services\SubcultureGameInfo\Raids;

use App\Models\SubcultureGameInfo\Character;
use App\Models\SubcultureGameInfo\Game;
use App\Models\SubcultureGameInfo\Raid;
use App\Services\SubcultureGameInfo\Sources\Concerns\FetchesWebContent;
use Illuminate\Support\Facades\Log;

/**
 * 블루 아카이브 종합전술시험(종전시) 수집 — 아카라이브 ZiWu 시리즈 글을 결정적 파싱한다(Gemini 불필요).
 *
 *  - 모음글(archive_url): 차수별 기간·시험종류/장갑/지형·단계 기믹 + 각 차수 공략글 링크.
 *  - 차수별 공략글: 본문 하단의 참고 영상 표(총점/영상URL/3파티×6명+성장스펙)를 파싱.
 *  - 멤버는 커뮤니티 애칭(드히나·수기사 등) → 마스터 이름으로 해석:
 *    ①정확 일치 ②config aliases ③접두 변형 규칙(수→수영복 등, 유일 매칭만).
 *  - 저장은 RaidSyncService(source='arca-jfd') 재사용 — 레이드는 (game, jfd-{차수}) upsert,
 *    편성은 같은 source 만 갈아끼워 총력전(mollulog)·수동 데이터와 공존한다.
 */
class JfdCollectorService
{
    use FetchesWebContent;

    /** 접두 애칭의 의상 변형 규칙(마스터 표기 "이름(변형)" 과 대응, 유일 매칭일 때만 적용) */
    private const VARIANT_PREFIXES = [
        '수' => '수영복',
        '드' => '드레스',
        '바' => '바니걸',
        '캠' => '캠핑',
        '온' => '온천',
        '뉴' => '새해',
        '정' => '정월',
        '체' => '체육복',
        '운' => '체육복',   // 커뮤니티는 '운동복'으로 부른다
        '무' => '무장',
        '임' => '무장',     // 임무/무장 표기 혼용
        '메' => '메이드',
        '교' => '교복',
        '라' => '라이딩',
        '아' => '아이돌',
        '치' => '응원단',   // 치어리더
        '클' => '크리스마스',
        '밴' => '밴드',
        '파' => '파자마',
        '사' => '사복',
        '매' => '매지컬',
        '알' => '아르바이트',
    ];

    public function __construct(private RaidSyncService $sync) {}

    /**
     * @param  bool  $all  true 면 이미 저장된 차수도 다시 수집(백필/보정)
     * @return array{sessions: int, raids: int, parties: int, members: int, missing_members: int, unresolved: list<string>}
     */
    public function collect(Game $game, bool $all = false): array
    {
        $cfg = (array) config('subculture-game-info.raids.jfd', []);
        $stats = ['sessions' => 0, 'raids' => 0, 'parties' => 0, 'members' => 0, 'missing_members' => 0, 'unresolved' => []];

        $archiveHtml = $this->getHtml($cfg['archive_url']);
        if ($archiveHtml === null) {
            Log::warning('[SGI-JFD] 모음글 요청 실패 — 기존 데이터 보존');

            return $stats;
        }

        $sessions = $this->parseArchive($archiveHtml);
        if ($sessions === []) {
            Log::warning('[SGI-JFD] 모음글에서 차수를 못 찾음(마크업 변경 의심) — 보존');

            return $stats;
        }

        // 이미 저장된 차수는 스킵(--all 제외). 글이 수정될 수 있는 최신 차수는 항상 재수집.
        $latestNo = max(array_keys($sessions));
        $existing = Raid::query()
            ->where('subculture_game_id', $game->id)
            ->where('external_key', 'like', 'jfd-%')
            ->pluck('external_key')
            ->map(fn (string $key) => (int) substr($key, 4))
            ->flip();

        $resolver = $this->buildResolver($game, $cfg);
        $delayMicros = (int) ((float) ($cfg['fetch_delay_seconds'] ?? 1.0) * 1_000_000);

        $items = [];
        foreach ($sessions as $no => $session) {
            if (! $all && $existing->has($no) && $no !== $latestNo) {
                continue;
            }

            $parties = [];
            if ($session['post_url'] !== null) {
                usleep($delayMicros);
                $postHtml = $this->getHtml($session['post_url']);
                if ($postHtml !== null) {
                    $parties = $this->parseParties($postHtml, $session['post_url'], $resolver, (int) ($cfg['top_entries'] ?? 6), $stats['unresolved']);
                }
            }

            $items[] = [
                'external_key' => "jfd-{$no}",
                'name' => "제{$no}차 종합전술시험".($session['type'] ? " — {$session['type']}" : ''),
                'raid_type' => '종합전술시험',
                'boss_name' => null,
                'tags' => array_filter([
                    '종류' => $session['type'],
                    '장갑' => $session['armor'],
                    '지형' => $session['terrain'],
                    '3단계' => $session['gimmick3'],
                    '4단계' => $session['gimmick4'],
                ]),
                'starts_at' => $session['starts_at'],
                'ends_at' => $session['ends_at'],
                'source_url' => $session['post_url'] ?? $cfg['archive_url'],
                'parties' => $parties,
            ];
            $stats['sessions']++;
        }

        if ($items === []) {
            return $stats;
        }

        $syncStats = $this->sync->sync($game, (string) ($cfg['source'] ?? 'arca-jfd'), $items);
        $stats['raids'] = $syncStats['raids'];
        $stats['parties'] = $syncStats['parties'];
        $stats['members'] = $syncStats['members'];
        $stats['missing_members'] = $syncStats['missing_members'];
        $stats['unresolved'] = array_values(array_unique($stats['unresolved']));

        return $stats;
    }

    /**
     * 모음글 파싱 — 차수별 메타(기간·종류/장갑/지형·기믹)와 공략글 링크.
     * 링크에 차수가 없는 초기 글("이번 종합전술시험")은 등장 순서(5차부터)로 보정한다.
     *
     * @return array<int, array{starts_at: ?string, ends_at: ?string, type: ?string, armor: ?string, terrain: ?string, gimmick3: ?string, gimmick4: ?string, post_url: ?string}>
     */
    private function parseArchive(string $html): array
    {
        $body = $this->articleBody($html);
        if ($body === null) {
            return [];
        }

        $sessions = [];
        $current = null;
        foreach ($this->htmlToLines($body) as $line) {
            // "49차(26.06.30.~26.07.07.)" — 기간이 없는 예고 행도 허용
            if (preg_match('/^(\d{1,3})차\((\d{2})\.(\d{2})\.(\d{2})\.?\s*~\s*(\d{2})\.(\d{2})\.(\d{2})\.?\)/u', $line, $m) === 1) {
                $current = (int) $m[1];
                $sessions[$current] = [
                    'starts_at' => "20{$m[2]}-{$m[3]}-{$m[4]}",
                    'ends_at' => "20{$m[5]}-{$m[6]}-{$m[7]}",
                    'type' => null, 'armor' => null, 'terrain' => null,
                    'gimmick3' => null, 'gimmick4' => null, 'post_url' => null,
                ];

                continue;
            }
            if ($current === null) {
                continue;
            }
            // "사격 / 경장갑 / 시가지"
            if ($sessions[$current]['type'] === null
                && preg_match('/^(\S{2,6})\s*\/\s*(\S{2,6})\s*\/\s*(\S{2,6})$/u', $line, $m) === 1) {
                [$sessions[$current]['type'], $sessions[$current]['armor'], $sessions[$current]['terrain']] = [$m[1], $m[2], $m[3]];

                continue;
            }
            if (preg_match('/^3단계\s*:\s*(.+)$/u', $line, $m) === 1) {
                $sessions[$current]['gimmick3'] = mb_substr(trim($m[1]), 0, 120);
            }
            if (preg_match('/^4단계\s*:\s*(.+)$/u', $line, $m) === 1) {
                $sessions[$current]['gimmick4'] = mb_substr(trim($m[1]), 0, 120);
            }
        }

        // 공략글 링크: 제목의 "n차" 우선, 없으면 등장 순서(작성 시작 = 5차)로 배정
        $order = 5;
        foreach ($this->extractPostLinks($body) as $link) {
            $no = preg_match('/(\d{1,3})차/u', $link['text'], $m) === 1 ? (int) $m[1] : $order;
            $order = max($order, $no) + 1;
            if (isset($sessions[$no])) {
                $sessions[$no]['post_url'] = $link['href'];
            }
        }

        ksort($sessions);

        return $sessions;
    }

    /**
     * 차수 공략글 파싱 — "점수 {총점} {영상URL}" 행 뒤로 이어지는 1~3파티 표를 읽는다.
     * 파티 행 = 파티 점수 다음 6개 멤버명 행 + 6개 스펙(전N/성급) 행.
     *
     * @param  \Closure(string): array{name: ?string, slot_type: ?string, note: ?string}  $resolver
     * @param  list<string>  $unresolved  해석 실패 애칭 수집(참조)
     * @return array<int, array> CrawledPartyData 계약 배열
     */
    private function parseParties(string $html, string $postUrl, \Closure $resolver, int $topEntries, array &$unresolved): array
    {
        $body = $this->articleBody($html);
        if ($body === null) {
            return [];
        }
        $lines = $this->htmlToLines($body);

        $parties = [];
        $entry = null;      // ['total' => .., 'video' => .., 'note' => ..]
        $entryIndex = 0;
        $sort = 0;

        for ($i = 0; $i < count($lines); $i++) {
            $cells = array_values(array_filter(array_map('trim', explode("\t", $lines[$i])), fn ($c) => $c !== ''));
            if ($cells === []) {
                continue;
            }

            // 새 참고 영상 엔트리: "점수 \t 256,138 \t https://..."
            if ($cells[0] === '점수' && isset($cells[1]) && preg_match('/^[\d,]+$/', $cells[1]) === 1) {
                if ($entryIndex >= $topEntries) {
                    break;
                }
                $entryIndex++;
                $entry = [
                    'total' => $cells[1],
                    'video' => collect($cells)->first(fn ($c) => str_starts_with($c, 'http')),
                    'note' => null,
                ];
                // 다음 줄의 ※메모(있으면)
                $next = trim($lines[$i + 1] ?? '');
                if (str_starts_with($next, '※')) {
                    $entry['note'] = mb_substr($next, 0, 120);
                }

                continue;
            }
            if ($entry === null) {
                continue;
            }

            // 파티 블록: "n파티" 셀 이후 [파티점수] → [멤버 6] → [스펙 6] 행이 이어진다.
            // 구형 글은 라벨·점수·멤버가 한 행에 섞이기도 해서, 라벨/숫자 셀은 걸러내고 이름만 취한다.
            $labelIdx = collect($cells)->search(fn ($c) => preg_match('/^[1-3]파티$/u', $c) === 1);
            if ($labelIdx !== false) {
                $partyNo = (int) $cells[$labelIdx][0];
                $score = null;
                $names = [];
                $specs = [];

                // 같은 행의 나머지 셀에서 점수/이름을 먼저 수확
                [$score, $names] = $this->harvestRow(array_slice($cells, 0), $score, $names);

                for ($j = $i + 1; $j < min($i + 7, count($lines)) && $specs === []; $j++) {
                    $row = array_values(array_filter(array_map('trim', explode("\t", $lines[$j])), fn ($c) => $c !== ''));
                    if ($row === []) {
                        continue;
                    }
                    // 다음 파티/엔트리 시작이면 중단
                    if (preg_match('/^([1-3]파티|점수)$/u', $row[0]) === 1) {
                        break;
                    }
                    $isSpecRow = collect($row)->every(fn ($c) => preg_match('/^(전[1-4]|[1-5]성|명함|LV\.?\s?\d+)$/ui', $c) === 1);
                    if ($names !== [] && $isSpecRow) {
                        $specs = $row;
                        $i = $j; // 소비한 행까지 진행

                        break;
                    }
                    [$score, $names] = $this->harvestRow($row, $score, $names);
                }
                if ($names === []) {
                    continue;
                }

                $members = [];
                foreach ($names as $k => $nickname) {
                    $resolved = $resolver($nickname);
                    if ($resolved['name'] === null) {
                        $unresolved[] = $nickname;
                    }
                    $members[] = [
                        'external_key' => '',
                        // 미해석 애칭은 원문 그대로 넘겨 sync 의 missing 카운트로 드러낸다
                        'name' => $resolved['name'] ?? $nickname,
                        'slot_type' => $resolved['slot_type'],
                        'sort' => $k,
                        'note' => trim(implode(' ', array_filter([$resolved['note'], $specs[$k] ?? null]))) ?: null,
                    ];
                }

                $parties[] = [
                    'title' => "총점 {$entry['total']} · {$partyNo}파티".($score ? "({$score})" : ''),
                    'sort' => $sort++,
                    'source_url' => $entry['video'],
                    'note' => $partyNo === 1 ? $entry['note'] : null, // 엔트리 메모는 1파티에만
                    'members' => $members,
                ];
            }
        }

        if ($parties === []) {
            Log::info('[SGI-JFD] 파티 표를 찾지 못함', ['url' => $postUrl]);
        }

        return $parties;
    }

    /**
     * 파티 행에서 점수(첫 숫자 셀)와 멤버명 셀을 수확한다.
     * 라벨("n파티")·숫자·URL 셀은 이름이 아니므로 거른다.
     *
     * @param  list<string>  $row
     * @param  list<string>  $names
     * @return array{0: ?string, 1: list<string>}
     */
    private function harvestRow(array $row, ?string $score, array $names): array
    {
        foreach ($row as $cell) {
            if (preg_match('/^[1-3]파티$/u', $cell) === 1 || str_starts_with($cell, 'http')) {
                continue;
            }
            if (preg_match('/^[\d,]+$/', $cell) === 1) {
                $score ??= $cell;

                continue;
            }
            // 스펙 토큰(전4/3성 등)이 이름 행에 섞여 있어도 이름으로 오인하지 않는다
            if (preg_match('/^(전[1-4]|[1-5]성|명함|LV\.?\s?\d+)$/ui', $cell) === 1) {
                continue;
            }
            if (count($names) < 6) {
                $names[] = $cell;
            }
        }

        return [$score, $names];
    }

    /**
     * 커뮤니티 애칭 해석기 — "(A)"=조력자, "(탱)" 등 접미는 note 로 분리한 뒤
     * ①정확 일치 ②config aliases ③접두 변형 규칙(유일 매칭만) 순서로 마스터 이름을 찾는다.
     *
     * @return \Closure(string): array{name: ?string, slot_type: ?string, note: ?string}
     */
    private function buildResolver(Game $game, array $cfg): \Closure
    {
        $names = Character::query()
            ->where('subculture_game_id', $game->id)
            ->active()
            ->pluck('name');
        $nameIndex = $names->keyBy(fn (string $name) => $this->nameKey($name));
        // 변형 캐릭터 목록: [변형키워드, 베이스 nameKey, 원본 이름]
        $variants = $names
            ->map(function (string $name) {
                if (preg_match('/^(.+)\((.+)\)$/u', $name, $m) !== 1) {
                    return null;
                }

                return ['variant' => $m[2], 'base' => $this->nameKey($m[1]), 'name' => $name];
            })
            ->filter()
            ->values();
        $aliases = collect((array) ($cfg['aliases'] ?? []))
            ->mapWithKeys(fn ($master, $nickname) => [$this->nameKey((string) $nickname) => (string) $master]);

        return function (string $raw) use ($nameIndex, $variants, $aliases): array {
            $nickname = trim($raw);
            $slotType = null;
            $note = null;

            // "(A)"=조력자 / "(탱)" 등 짧은 접미=운용 메모 — "임시노(탱)(A)"처럼 겹칠 수 있어 반복 분리
            for ($pass = 0; $pass < 2; $pass++) {
                if (preg_match('/^(.*)\((A|어시스트)\)$/u', $nickname, $m) === 1) {
                    $nickname = trim($m[1]);
                    $slotType = 'assist';

                    continue;
                }
                if (preg_match('/^(.+)\((.{1,4})\)$/u', $nickname, $m) === 1
                    && $nameIndex->get($this->nameKey($nickname)) === null) {
                    // 마스터에 그대로 있는 "아루(드레스)" 류는 건드리지 않는다
                    $nickname = trim($m[1]);
                    $note = trim($m[2].' '.($note ?? '')) ?: null;

                    continue;
                }

                break;
            }

            $key = $this->nameKey($nickname);

            $found = $nameIndex->get($key)
                ?? ($aliases->has($key) ? $aliases->get($key) : null);

            // 접두 변형 규칙: 드히나 → 변형 '드레스' + 베이스에 '히나' 포함(유일 매칭만)
            if ($found === null && mb_strlen($nickname) >= 2) {
                $variantWord = self::VARIANT_PREFIXES[mb_substr($nickname, 0, 1)] ?? null;
                $rest = $this->nameKey(mb_substr($nickname, 1));
                if ($variantWord !== null && $rest !== '') {
                    $hits = $variants->filter(fn (array $v) => str_starts_with($v['variant'], $variantWord)
                        && str_contains($v['base'], $rest));
                    if ($hits->count() === 1) {
                        $found = $hits->first()['name'];
                    }
                }
            }

            return ['name' => $found, 'slot_type' => $slotType, 'note' => $note];
        };
    }

    /** article-content 본문 HTML 만 잘라낸다(댓글·추천글 목록 오염 방지). */
    private function articleBody(string $html): ?string
    {
        if (preg_match('/<div[^>]*class="[^"]*article-content[^"]*"[^>]*>(.*?)<div[^>]*class="[^"]*article-(?:menu|bottom)/is', $html, $m) === 1) {
            return $m[1];
        }
        // 폴백: article-content 이후 전부 (푸터 잡음은 라인 파서가 걸러낸다)
        if (preg_match('/<div[^>]*class="[^"]*article-content[^"]*"[^>]*>(.*)$/is', $html, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * HTML → 라인 배열. 표 구조 보존: 셀(td/th)은 탭, 행(tr)·블록 태그는 개행으로.
     *
     * @return list<string>
     */
    private function htmlToLines(string $html): array
    {
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $text = preg_replace('/<\/(td|th)>/i', "\t", $text) ?? $text;
        $text = preg_replace('/<\/(tr|p|div|h[1-6]|li|table)>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return collect(explode("\n", $text))
            ->map(fn (string $line) => trim($line, " \r\0\x0B\u{00A0}"))
            ->filter(fn (string $line) => trim($line, "\t ") !== '')
            ->values()
            ->all();
    }

    /**
     * 모음글 본문의 차수 공략글 링크 목록(본문 등장 순서).
     *
     * @return list<array{text: string, href: string}>
     */
    private function extractPostLinks(string $body): array
    {
        $links = [];
        foreach ((array) preg_match_all('/<a[^>]*href="(https?:\/\/arca\.live)?(\/b\/bluearchive\/\d+)[^"]*"[^>]*>(.*?)<\/a>/is', $body, $matches, PREG_SET_ORDER) ? $matches : [] as $m) {
            $text = trim(strip_tags($m[3]));
            if ($text === '') {
                continue;
            }
            $links[] = ['text' => $text, 'href' => 'https://arca.live'.$m[2]];
        }

        return $links;
    }

    /** 이름 동일성 키 — 공백·콜론 제거 + 소문자화(다른 서비스와 동일 규칙). */
    private function nameKey(string $name): string
    {
        return mb_strtolower((string) preg_replace('/[\s:：]+/u', '', trim($name)));
    }
}
