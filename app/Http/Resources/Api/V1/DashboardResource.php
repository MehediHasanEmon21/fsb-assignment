<?php

namespace App\Http\Resources\Api\V1;

use App\Data\DashboardData;
use App\Enums\FeatureType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DashboardData */
class DashboardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $subscription = $this->entitlements->subscription;

        return [
            'tenant' => [
                'id' => $this->tenant->id,
                'name' => $this->tenant->name,
                'status' => $this->tenant->status,
            ],
            'metrics' => [
                'users' => $this->users,
                'customers' => $this->customers,
            ],
            'subscription' => $subscription === null ? null : [
                'id' => $subscription->id,
                'status' => $subscription->status->value,
                'starts_at' => $subscription->starts_at,
                'ends_at' => $subscription->ends_at,
                'plan' => [
                    'id' => $subscription->plan->id,
                    'name' => $subscription->plan->name,
                    'slug' => $subscription->plan->slug,
                    'price' => $subscription->plan->price,
                    'billing_interval' => $subscription->plan->billing_interval,
                ],
            ],
            'features' => $this->entitlements->features
                ->map(fn ($entitlement): array => [
                    'key' => $entitlement->key,
                    'name' => $entitlement->name,
                    'type' => $entitlement->type->value,
                    'enabled' => $entitlement->enabled,
                    'available' => $entitlement->canUse(),
                    'usage' => $entitlement->type === FeatureType::Limit
                        ? $entitlement->usage
                        : null,
                    'limit' => $entitlement->limit,
                    'remaining' => $entitlement->remaining,
                ])
                ->values()
                ->all(),
        ];
    }
}
