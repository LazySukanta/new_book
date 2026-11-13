<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained();
            $table->foreignId('issued_document_id')->nullable()->unique()->constrained();
            $table->foreignId('refund_payment_id')->nullable()->unique()->constrained('payments');
            $table->foreignId('created_by')->constrained('users');
            $table->string('document_no', 16)->unique();
            $table->timestamp('issued_at');
            $table->string('reason');
            $table->string('supplier_document_no')->nullable();
            $table->date('supplier_document_date')->nullable();
            foreach (['total', 'taxable', 'cgst', 'sgst', 'igst', 'credit_adjusted', 'refund_amount'] as $field) {
                $table->decimal($field, 18, 2);
            }
            $table->json('snapshot');
        });
        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained();
            $table->foreignId('purchase_item_id')->constrained();
            $table->decimal('qty', 18, 3);
            $table->json('line_snapshot');
            $table->unique(['purchase_return_id', 'purchase_item_id']);
        });
        DB::statement('ALTER TABLE stock_ledger DROP CHECK stock_ledger_one_source');
        Schema::table('stock_ledger', fn (Blueprint $table) => $table->foreignId('purchase_return_item_id')->nullable()->unique()->constrained());
        DB::statement('ALTER TABLE stock_ledger ADD CONSTRAINT stock_ledger_one_source CHECK ((sale_item_id IS NOT NULL) + (purchase_item_id IS NOT NULL) + (reversal_of_id IS NOT NULL) + (adjustment_item_id IS NOT NULL) + (purchase_revision_item_id IS NOT NULL) + (sale_return_item_id IS NOT NULL) + (purchase_return_item_id IS NOT NULL) = 1)');
    }

    public function down(): void
    {
        if (DB::table('purchase_returns')->exists()) {
            throw new LogicException('Cannot roll back posted supplier returns.');
        }
        DB::statement('ALTER TABLE stock_ledger DROP CHECK stock_ledger_one_source');
        Schema::table('stock_ledger', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_return_item_id');
        });
        DB::statement('ALTER TABLE stock_ledger ADD CONSTRAINT stock_ledger_one_source CHECK ((sale_item_id IS NOT NULL) + (purchase_item_id IS NOT NULL) + (reversal_of_id IS NOT NULL) + (adjustment_item_id IS NOT NULL) + (purchase_revision_item_id IS NOT NULL) + (sale_return_item_id IS NOT NULL) = 1)');
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
    }
};
