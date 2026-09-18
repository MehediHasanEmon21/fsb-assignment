<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'price' => $this->price,
            'billing_interval' => $this->billing_interval,
            'status' => $this->status,
            'features' => $this->whenLoaded(
                'features',
                fn () => $this->features->map(fn ($feature): array => [
                    'key' => $feature->key,
                    'name' => $feature->name,
                    'type' => $feature->type,
                    'value' => $feature->pivot->value,
                ])->values()->all(),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
