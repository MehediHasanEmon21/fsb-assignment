<?php

namespace App\Data;

final readonly class EntitlementSnapshot
{
    /**
     * @param  null|array<string, mixed>  $subscription
     * @param  array<int, array<string, mixed>>  $features
     */
    public function __construct(
        public ?array $subscription,
        public array $features,
    ) {}

    /** @param array{subscription: null|array<string, mixed>, features: array<int, array<string, mixed>>} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['subscription'], $data['features']);
    }

    /** @return array{subscription: null|array<string, mixed>, features: array<int, array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'subscription' => $this->subscription,
            'features' => $this->features,
        ];
    }
}
