<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->unsignedBigInteger('purchase_id');
            $table->unsignedInteger('revision_no');
            $table->string('action', 32);
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot');
            $table->string('reason');
            $table->unsignedBigInteger('created_by');
            $table->foreign(['tenant_id', 'created_by'])->references(['tenant_id', 'id'])->on('users');
            $table->foreignId('device_id')->nullable()->constrained();
            $table->timestamp('correction_posted_at');
            $table->timestamp('financial_effective_at');
            $table->decimal('supplier_balance_delta', 18, 2);
            $table->unsignedBigInteger('previous_supplier_id')->nullable();
            $table->unsignedBigInteger('resulting_supplier_id')->nullable();
            $table->json('allocation_changes_snapshot');
            $table->foreignId('idempotency_request_id')->nullable()->constrained();
            $table->unique(['tenant_id', 'purchase_id', 'revision_no'], 'purchase_revision_version_unique');
        });
        Schema::create('purchase_revision_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_revision_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->unsignedBigInteger('purchase_item_id')->nullable();
            foreach (['qty_before', 'qty_after', 'qty_change', 'confirmed_remaining'] as $column) {
                $table->decimal($column, 18, 3);
            }
            foreach (['value_before', 'value_after', 'inventory_value_change', 'valuation_variance'] as $column) {
                $table->decimal($column, 18, 2);
            }
            $table->decimal('unit_cost', 24, 9);
            $table->boolean('cost_estimated')->default(false);
            $table->string('cost_source', 32);
            $table->string('reason');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_revision_items');
        Schema::dropIfExists('purchase_revisions');
    }
};
