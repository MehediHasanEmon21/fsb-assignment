<?php

namespace App\Http\Resources\Api\V1;

use App\Data\DashboardData;
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
                'id' => $this->tenant['id'],
                'name' => $this->tenant['name'],
                'status' => $this->tenant['status'],
            ],
            'metrics' => [
                'users' => $this->users,
                'customers' => $this->customers,
            ],
            'subscription' => $subscription === null ? null : [
                'id' => $subscription['id'],
                'status' => $subscription['status'],
                'starts_at' => $subscription['starts_at'],
                'ends_at' => $subscription['ends_at'],
                'plan' => $subscription['plan'],
            ],
            'features' => $this->entitlements->features,
        ];
    }
}
