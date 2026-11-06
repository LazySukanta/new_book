<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained();
            $table->foreignId('issued_document_id')->nullable()->unique()->constrained();
            $table->foreignId('refund_payment_id')->nullable()->unique()->constrained('payments');
            $table->foreignId('created_by')->constrained('users');
            $table->string('credit_note_no', 16)->nullable()->unique();
            $table->string('status', 16)->default('issued');
            $table->timestamp('issued_at');
            $table->string('reason', 255);
            foreach (['total', 'credit_adjusted', 'refund_amount'] as $field) {
                $table->decimal($field, 18, 2);
            }
            $table->json('invoice_snapshot');
        });
        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->constrained();
            $table->foreignId('sale_item_id')->constrained();
            $table->decimal('qty', 18, 3);
            $table->json('line_snapshot');
            $table->unique(['sale_return_id', 'sale_item_id']);
        });
        DB::statement('ALTER TABLE stock_ledger DROP CHECK stock_ledger_one_source');
        Schema::table('stock_ledger', fn (Blueprint $table) => $table->foreignId('sale_return_item_id')->nullable()->unique()->constrained());
        DB::statement('ALTER TABLE stock_ledger ADD CONSTRAINT stock_ledger_one_source CHECK ((sale_item_id IS NOT NULL) + (purchase_item_id IS NOT NULL) + (reversal_of_id IS NOT NULL) + (adjustment_item_id IS NOT NULL) + (purchase_revision_item_id IS NOT NULL) + (sale_return_item_id IS NOT NULL) = 1)');
    }

    public function down(): void
    {
        if (DB::table('sale_returns')->exists()) {
            throw new LogicException('Cannot roll back posted sale returns.');
        }

        DB::statement('ALTER TABLE stock_ledger DROP CHECK stock_ledger_one_source');
        Schema::table('stock_ledger', function (Blueprint $table) {
            $table->dropForeign(['sale_return_item_id']);
            $table->dropUnique(['sale_return_item_id']);
            $table->dropColumn('sale_return_item_id');
        });
        DB::statement('ALTER TABLE stock_ledger ADD CONSTRAINT stock_ledger_one_source CHECK ((sale_item_id IS NOT NULL) + (purchase_item_id IS NOT NULL) + (reversal_of_id IS NOT NULL) + (adjustment_item_id IS NOT NULL) + (purchase_revision_item_id IS NOT NULL) = 1)');
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
    }
};
