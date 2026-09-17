<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('usage')->default(0);
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'feature_id', 'period_start', 'period_end'],
                'feature_usages_tenant_feature_period_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_usages');
    }
};
