<?php

namespace App\Services\SubcultureGameInfo\Sources\DTO;

use App\Enums\SubcultureGameInfo\CodeRegion;
use App\Enums\SubcultureGameInfo\CodeStatus;
use App\Enums\SubcultureGameInfo\SourceType;
use Carbon\CarbonInterface;

/**
 * 소스에서 수집한 코드 1건을 전달하는 객체. (정규화 전 원시 수집물)
 */
final class CollectedCodeDto
{
    public function __construct(
        public string $gameSlug,
        public string $code,
        public SourceType $sourceType,
        public string $source,
        public CodeRegion $region = CodeRegion::Global,
        public ?string $rewards = null,
        public CodeStatus $status = CodeStatus::Unverified,
        public ?string $sourceUrl = null,
        public ?CarbonInterface $expiresAt = null,
    ) {}
}
