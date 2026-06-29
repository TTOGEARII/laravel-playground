<?php

namespace App\Services\SubcultureGameInfo\Sources\Contracts;

interface CodeSourceInterface
{
    /** 소스 식별 키 (redeem_codes.source 에 저장). */
    public function key(): string;

    /**
     * 코드 수집 실행.
     *
     * @return \App\Services\SubcultureGameInfo\Sources\DTO\CollectedCodeDto[]
     */
    public function fetch(): array;
}
