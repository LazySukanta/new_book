<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('created_by');
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches');
            $table->foreign(['tenant_id', 'created_by'])->references(['tenant_id', 'id'])->on('users');
            $table->foreignId('device_id')->nullable()->constrained();
            $table->foreignId('corrects_adjustment_id')->nullable()->constrained('stock_adjustments');
            $table->string('kind', 32);
            $table->string('reason', 255);
            $table->text('note')->nullable();
            $table->timestamp('posted_at');
            $table->index(['tenant_id', 'branch_id', 'id']);
        });
        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adjustment_id')->constrained('stock_adjustments');
            $table->foreignId('product_id')->constrained();
            foreach (['qty_before', 'qty_change', 'qty_after'] as $column) {
                $table->decimal($column, 18, 3);
            }
            $table->decimal('counted_qty', 18, 3)->nullable();
            foreach (['value_before', 'value_change', 'value_after', 'valuation_variance'] as $column) {
                $table->decimal($column, 18, 2);
            }
            $table->decimal('unit_cost', 24, 9);
            $table->boolean('cost_estimated')->default(false);
            $table->string('cost_source', 32);
            $table->unique(['adjustment_id', 'product_id']);
        });
        Schema::table('stock_ledger', function (Blueprint $table) {
            $table->foreignId('adjustment_item_id')->nullable()->unique()->constrained('stock_adjustment_items');
        });
        DB::statement('ALTER TABLE stock_ledger DROP CHECK stock_ledger_one_source');
        DB::statement('ALTER TABLE stock_ledger ADD CONSTRAINT stock_ledger_one_source CHECK ((sale_item_id IS NOT NULL) + (purchase_item_id IS NOT NULL) + (reversal_of_id IS NOT NULL) + (adjustment_item_id IS NOT NULL) = 1)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_ledger DROP CHECK stock_ledger_one_source');
        Schema::table('stock_ledger', function (Blueprint $table) {
            $table->dropConstrainedForeignId('adjustment_item_id');
        });
        DB::statement('ALTER TABLE stock_ledger ADD CONSTRAINT stock_ledger_one_source CHECK ((sale_item_id IS NOT NULL) + (purchase_item_id IS NOT NULL) + (reversal_of_id IS NOT NULL) = 1)');
        Schema::dropIfExists('stock_adjustment_items');
        Schema::dropIfExists('stock_adjustments');
    }
};
