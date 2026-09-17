<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\FeatureUsageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'feature_id', 'usage', 'period_start', 'period_end'])]
class FeatureUsage extends Model
{
    /** @use HasFactory<FeatureUsageFactory> */
    use BelongsToTenant, HasFactory;

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    protected function casts(): array
    {
        return [
            'usage' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
        ];
    }
}
