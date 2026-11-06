<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            foreach (['branch_id' => 'branches', 'product_id' => 'products', 'created_by' => 'users'] as $column => $parent) {
                $table->unsignedBigInteger($column);
                $table->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on($parent);
            }
            $table->string('type', 32);
            $table->decimal('qty_change', 18, 3);
            $table->decimal('qty_after', 18, 3);
            $table->decimal('value_change', 18, 2);
            $table->decimal('value_after', 18, 2);
            $table->decimal('valuation_variance', 18, 2)->default(0);
            $table->decimal('unit_cost', 24, 9);
            $table->boolean('cost_estimated')->default(false);
            $table->decimal('estimated_qty', 18, 3)->default(0);
            $table->decimal('estimated_unit_cost', 24, 9)->nullable();
            $table->string('cost_source', 32);
            $table->unsignedBigInteger('sale_item_id')->nullable();
            $table->unsignedBigInteger('purchase_item_id')->nullable();
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('stock_ledger');
            $table->timestamp('posted_at');
            $table->index(['tenant_id', 'branch_id', 'product_id', 'id'], 'stock_ledger_scope_index');
            $table->unique(['sale_item_id', 'branch_id', 'product_id', 'type'], 'stock_ledger_sale_event_unique');
            $table->unique(['purchase_item_id', 'branch_id', 'product_id', 'type'], 'stock_ledger_purchase_event_unique');
        });
        DB::statement('ALTER TABLE stock_ledger ADD CONSTRAINT stock_ledger_one_source CHECK ((sale_item_id IS NOT NULL) + (purchase_item_id IS NOT NULL) + (reversal_of_id IS NOT NULL) = 1)');
        Schema::table('stock_balances', fn (Blueprint $table) => $table->foreign('last_ledger_id')->references('id')->on('stock_ledger'));
    }

    public function down(): void
    {
        Schema::table('stock_balances', fn (Blueprint $table) => $table->dropForeign(['last_ledger_id']));
        Schema::dropIfExists('stock_ledger');
    }
};
