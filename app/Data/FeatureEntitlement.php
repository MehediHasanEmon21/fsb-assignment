<?php

namespace App\Data;

use App\Enums\FeatureType;
use Carbon\CarbonImmutable;

final readonly class FeatureEntitlement
{
    public function __construct(
        public int $featureId,
        public string $key,
        public string $name,
        public FeatureType $type,
        public bool $enabled,
        public ?int $limit,
        public int $usage,
        public ?int $remaining,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
    ) {}

    public function canUse(): bool
    {
        return $this->enabled
            && ($this->type === FeatureType::Boolean || $this->remaining > 0);
    }
}
