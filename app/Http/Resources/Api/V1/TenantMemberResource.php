<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'membership_status' => $this->pivot?->status,
            'roles' => $this->whenLoaded(
                'roles',
                fn (): array => $this->roles->pluck('name')->values()->all(),
            ),
            'created_at' => $this->created_at,
        ];
    }
}
