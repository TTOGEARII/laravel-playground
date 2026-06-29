<?php

namespace App\Services\SubcultureGameInfo\Sources\Drivers;

use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Enums\SubcultureGameInfo\SourceType;
use App\Services\SubcultureGameInfo\Sources\AbstractSourceDriver;
use App\Services\SubcultureGameInfo\Sources\DTO\CollectedCodeDto;

/**
 * 정리 사이트(HTML) 공용 드라이버.
 *  1) 표(table) 행에서 코드+보상+만료일+만료여부를 추출(가능한 경우)
 *  2) 강조 요소(<b>,<strong>,<code>,<td>,<mark>,<h3~4>) 토큰에서 코드 추가 수집(폴백)
 * 표에서 못 얻은 코드는 토큰 스캔으로 보강하고, 표에서 얻은 풍부한 정보(만료/보상)는 유지한다.
 * 정확도는 여러 사이트 교차검증(corroboration)으로 보완한다.
 */
class HtmlDriver extends AbstractSourceDriver
{
    private const EXPIRED_HINTS = ['만료', '종료', 'expired', 'inactive', 'ended'];

    public function driverKey(): string
    {
        return 'html';
    }

    public function collect(string $gameSlug, array $spec): array
    {
        $url = $spec['url'] ?? null;
        if (! $url) {
            return [];
        }
        $html = $this->getHtml($url);
        if ($html === null) {
            return [];
        }

        $region = $this->regionFor($gameSlug);
        $source = $this->hostLabel($url);

        // 1) 표 기반 풍부한 추출
        $byCode = [];
        foreach ($this->parseRows($html) as $row) {
            $byCode[strtoupper($row['code'])] = new CollectedCodeDto(
                gameSlug: $gameSlug,
                code: $row['code'],
                sourceType: SourceType::Aggregator,
                source: $source,
                region: $region,
                rewards: $row['rewards'],
                status: $row['status'],
                sourceUrl: $url,
                expiresAt: $row['expiresAt'],
            );
        }

        // 2) 토큰 스캔으로 누락 코드 보강(코드만, 미검증)
        foreach ($this->scanTokens($html) as $code) {
            $key = strtoupper($code);
            if (! isset($byCode[$key])) {
                $byCode[$key] = new CollectedCodeDto(
                    gameSlug: $gameSlug,
                    code: $code,
                    sourceType: SourceType::Aggregator,
                    source: $source,
                    region: $region,
                    status: CodeStatus::Unverified,
                    sourceUrl: $url,
                );
            }
        }

        return array_values($byCode);
    }

    /** 표 행에서 code/rewards/expiresAt/status 추출. @return array<int,array> */
    public function parseRows(string $html): array
    {
        $xp = $this->xpath($html);
        $out = [];

        foreach ($xp->query('//table//tr') ?: [] as $row) {
            $cells = [];
            foreach ($xp->query('.//th|.//td', $row) ?: [] as $cell) {
                $cells[] = trim(preg_replace('/\s+/u', ' ', $cell->textContent) ?? '');
            }
            if ($cells === []) {
                continue;
            }

            $code = null;
            $codeIdx = -1;
            foreach ($cells as $i => $text) {
                foreach ($this->extractCodeTokensFromText($text) as $tok) {
                    if (mb_strlen($text) <= 40) {
                        $code = $tok;
                        $codeIdx = $i;
                        break 2;
                    }
                }
            }
            if ($code === null) {
                continue;
            }

            $expiresAt = null;
            $expiryIdx = -1;
            foreach ($cells as $i => $text) {
                if ($i === $codeIdx) {
                    continue;
                }
                if (($d = $this->parseExpiry($text)) !== null) {
                    $expiresAt = $d;
                    $expiryIdx = $i;
                    break;
                }
            }

            $rewards = null;
            $best = 0;
            foreach ($cells as $i => $text) {
                if ($i === $codeIdx || $i === $expiryIdx) {
                    continue;
                }
                if (mb_strlen($text) > $best && mb_strlen($text) > 3) {
                    $best = mb_strlen($text);
                    $rewards = $text;
                }
            }

            $rowText = mb_strtolower(implode(' ', $cells));
            $expired = $expiresAt !== null && $expiresAt->isPast();
            foreach (self::EXPIRED_HINTS as $h) {
                if (mb_strpos($rowText, $h) !== false) {
                    $expired = true;
                    break;
                }
            }
            $status = $expired
                ? CodeStatus::Expired
                : ($expiresAt !== null ? CodeStatus::Active : CodeStatus::Unverified);

            $key = strtoupper($code);
            if (! isset($out[$key]) || ($rewards && empty($out[$key]['rewards']))) {
                $out[$key] = compact('code', 'rewards', 'expiresAt', 'status');
            }
        }

        return array_values($out);
    }

    // 토큰 스캔 상한: '모든 코드' 아카이브 페이지가 만료 코드 수백 개를 쏟아내는 노이즈 방지.
    // 활성 코드는 보통 페이지 상단에 있어 앞쪽 N개만 취해도 최신/활성이 대부분 포함된다.
    private const TOKEN_SCAN_LIMIT = 25;

    /** 강조 요소 텍스트에서 코드 토큰 스캔(문서 순서, 상한 적용). @return string[] */
    public function scanTokens(string $html): array
    {
        $found = [];
        foreach ($this->xpath($html)->query('//strong|//b|//code|//td|//mark|//h3|//h4') ?: [] as $node) {
            $token = trim($node->textContent);
            if ($this->looksLikeCode($token)) {
                $found[strtoupper($token)] = $token;
                if (count($found) >= self::TOKEN_SCAN_LIMIT) {
                    break;
                }
            }
        }

        return array_values($found);
    }
}
