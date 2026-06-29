<?php

namespace App\Services\SubcultureGameInfo\Sources\Contracts;

/**
 * 단일 수집 드라이버. config 의 게임별 source 스펙을 받아 해당 게임 코드를 수집한다.
 * 새 사이트/게임은 config 에 스펙만 추가하면 되도록(드라이버 재사용) 설계한다.
 */
interface SourceDriver
{
    /** 드라이버 식별 키 (config 의 'driver' 값과 매칭). */
    public function driverKey(): string;

    /** 커뮤니티(보조 신호) 드라이버 여부. */
    public function isCommunity(): bool;

    /**
     * @param  string  $gameSlug  대상 게임 슬러그
     * @param  array  $spec  config 의 소스 스펙(예: ['driver'=>'table','url'=>'...'])
     * @return \App\Services\SubcultureGameInfo\Sources\DTO\CollectedCodeDto[]
     */
    public function collect(string $gameSlug, array $spec): array;
}
