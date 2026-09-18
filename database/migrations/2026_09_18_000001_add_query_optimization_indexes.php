<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_user', function (Blueprint $table) {
            $table->index(
                ['tenant_id', 'status'],
                'tenant_user_tenant_status_index',
            );
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->index(
                ['tenant_id', 'status', 'starts_at'],
                'subscriptions_tenant_status_starts_index',
            );
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->index(
                ['tenant_id', 'name', 'id'],
                'customers_tenant_name_id_index',
            );
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->index(
                ['status', 'price', 'id'],
                'plans_status_price_id_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropIndex('plans_status_price_id_index');
            $table->index('status');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_tenant_name_id_index');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex('subscriptions_tenant_status_starts_index');
        });

        Schema::table('tenant_user', function (Blueprint $table) {
            $table->dropIndex('tenant_user_tenant_status_index');
        });
    }
};
