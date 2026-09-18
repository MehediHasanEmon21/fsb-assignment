<?php

namespace App\Services;

use App\Models\Plan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PlanService
{
    /**
     * @param  array{search?: ?string, billing_interval?: ?string, sort?: ?string, direction?: ?string, per_page?: ?int}  $filters
     */
    public function paginateAvailable(array $filters): LengthAwarePaginator
    {
        $query = Plan::query()
            ->where('status', 'active')
            ->with('features');

        if ($search = $filters['search'] ?? null) {
            $query->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if ($billingInterval = $filters['billing_interval'] ?? null) {
            $query->where('billing_interval', $billingInterval);
        }

        $sort = $filters['sort'] ?? 'price';
        $direction = $filters['direction'] ?? 'asc';

        return $query
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();
    }

    public function findAvailable(int $planId): Plan
    {
        return Plan::query()
            ->where('status', 'active')
            ->with('features')
            ->findOrFail($planId);
    }
}
