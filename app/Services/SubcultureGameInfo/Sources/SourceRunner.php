<?php

namespace App\Services\SubcultureGameInfo\Sources;

use App\Services\SubcultureGameInfo\Sources\Contracts\SourceDriver;
use App\Services\SubcultureGameInfo\Sources\Drivers\ArcaCommunityDriver;
use App\Services\SubcultureGameInfo\Sources\Drivers\DcCommunityDriver;
use App\Services\SubcultureGameInfo\Sources\Drivers\EnneadApiDriver;
use App\Services\SubcultureGameInfo\Sources\Drivers\HtmlDriver;
use App\Services\SubcultureGameInfo\Sources\Drivers\NaverGameLoungeDriver;
use App\Services\SubcultureGameInfo\Sources\Drivers\SeriaApiDriver;
use Illuminate\Support\Facades\Log;

/**
 * config 의 게임별 소스 스펙을 읽어 드라이버로 디스패치한다.
 * 새 게임/사이트는 config 의 games[slug].sources 에 스펙만 추가하면 된다(드라이버 재사용).
 * 메인(정리/API) 결과를 먼저, 커뮤니티(보조) 결과를 나중에 반환해 동기화 권위 순서를 보장한다.
 */
class SourceRunner
{
    /** @var array<string, SourceDriver> */
    private array $drivers;

    public function __construct(
        EnneadApiDriver $ennead,
        SeriaApiDriver $seria,
        HtmlDriver $html,
        NaverGameLoungeDriver $naver,
        DcCommunityDriver $dc,
        ArcaCommunityDriver $arca,
    ) {
        foreach ([$ennead, $seria, $html, $naver, $dc, $arca] as $driver) {
            $this->drivers[$driver->driverKey()] = $driver;
        }
    }

    /**
     * @return \App\Services\SubcultureGameInfo\Sources\DTO\CollectedCodeDto[]
     */
    public function run(bool $includeCommunity = true): array
    {
        $main = [];
        $community = [];

        foreach (config('subculture-game-info.games', []) as $slug => $game) {
            foreach ($game['sources'] ?? [] as $spec) {
                $driver = $this->drivers[$spec['driver'] ?? ''] ?? null;
                if ($driver === null) {
                    Log::warning('[SGI] 알 수 없는 드라이버', ['spec' => $spec]);

                    continue;
                }
                if ($driver->isCommunity() && ! $includeCommunity) {
                    continue;
                }

                try {
                    $dtos = $driver->collect($slug, $spec);
                } catch (\Throwable $e) {
                    Log::error('[SGI] 드라이버 수집 실패', ['driver' => $driver->driverKey(), 'game' => $slug, 'error' => $e->getMessage()]);

                    continue;
                }

                if ($driver->isCommunity()) {
                    $community = array_merge($community, $dtos);
                } else {
                    $main = array_merge($main, $dtos);
                }
            }
        }

        return array_merge($main, $community);
    }
}
