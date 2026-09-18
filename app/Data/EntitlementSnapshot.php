<?php

namespace App\Data;

use App\Models\Subscription;
use Illuminate\Support\Collection;

final readonly class EntitlementSnapshot
{
    /**
     * @param  Collection<int, FeatureEntitlement>  $features
     */
    public function __construct(
        public ?Subscription $subscription,
        public Collection $features,
    ) {}
}
