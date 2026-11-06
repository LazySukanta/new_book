<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            foreach (['branch_id' => 'branches', 'supplier_id' => 'suppliers', 'created_by' => 'users'] as $column => $parent) {
                $table->unsignedBigInteger($column);
                $table->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on($parent);
            }
            $table->foreignId('device_id')->nullable()->constrained();
            $table->string('supplier_invoice_no', 120);
            $table->string('normalized_invoice_no', 120);
            $table->date('supplier_invoice_date');
            $table->timestamp('received_at');
            $table->boolean('cost_includes_tax');
            $table->boolean('input_tax_recoverable')->default(false);
            $table->string('tax_type', 8);
            $table->string('status', 16)->default('posted');
            $table->unsignedInteger('current_revision')->default(1);
            $table->timestamp('deleted_at')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users');
            $table->string('deletion_reason')->nullable();
            foreach (['discount_amount', 'taxable', 'cgst', 'sgst', 'igst', 'unrounded_total', 'round_off', 'total', 'paid_amount', 'balance_amount'] as $column) {
                $table->decimal($column, 18, 2)->default(0);
            }
            $table->json('supplier_snapshot');
            $table->json('branch_snapshot');
            $table->unique(['supplier_id', 'normalized_invoice_no'], 'supplier_invoice_number_unique');
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'branch_id', 'received_at']);
        });
        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->decimal('qty', 18, 3);
            $table->decimal('unit_cost', 18, 2);
            $table->boolean('price_includes_tax');
            $table->unsignedSmallInteger('rate_bp');
            $table->boolean('is_removed')->default(false);
            foreach (['discount_share', 'taxable', 'cgst', 'sgst', 'igst', 'line_total', 'inventory_value'] as $column) {
                $table->decimal($column, 18, 2)->default(0);
            }
            $table->decimal('inventory_unit_cost', 24, 9);
            $table->json('product_snapshot');
            $table->unique(['purchase_id', 'product_id']);
        });
        Schema::table('purchase_revisions', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'purchase_id'])->references(['tenant_id', 'id'])->on('purchases');
            $table->foreign('previous_supplier_id')->references('id')->on('suppliers');
            $table->foreign('resulting_supplier_id')->references('id')->on('suppliers');
        });
        Schema::table('purchase_revision_items', fn (Blueprint $table) => $table->foreign('purchase_item_id')->references('id')->on('purchase_items'));
        Schema::table('stock_ledger', fn (Blueprint $table) => $table->foreign('purchase_item_id')->references('id')->on('purchase_items'));
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->unsignedBigInteger('party_opening_entry_id')->nullable()->change();
            $table->foreignId('purchase_id')->nullable()->constrained();
        });
        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT allocation_one_target CHECK ((party_opening_entry_id IS NOT NULL) + (purchase_id IS NOT NULL) = 1)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payment_allocations DROP CHECK allocation_one_target');
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_id');
            $table->unsignedBigInteger('party_opening_entry_id')->nullable(false)->change();
        });
        Schema::table('stock_ledger', fn (Blueprint $table) => $table->dropForeign(['purchase_item_id']));
        Schema::table('purchase_revision_items', fn (Blueprint $table) => $table->dropForeign(['purchase_item_id']));
        Schema::table('purchase_revisions', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'purchase_id']);
            $table->dropForeign(['previous_supplier_id']);
            $table->dropForeign(['resulting_supplier_id']);
        });
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
    }
};
