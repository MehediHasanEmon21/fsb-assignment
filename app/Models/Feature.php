<?php

namespace App\Models;

use Database\Factories\FeatureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'key', 'description', 'type'])]
class Feature extends Model
{
    /** @use HasFactory<FeatureFactory> */
    use HasFactory;

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plan_features')
            ->withPivot(['id', 'value'])
            ->withTimestamps();
    }

    public function planFeatures(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(FeatureUsage::class);
    }
}
