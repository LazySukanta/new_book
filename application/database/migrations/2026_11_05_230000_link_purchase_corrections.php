<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_allocations', fn (Blueprint $table) => $table->foreignId('purchase_revision_id')->nullable()->constrained());
        Schema::table('stock_ledger', fn (Blueprint $table) => $table->foreignId('purchase_revision_item_id')->nullable()->unique()->constrained());
        DB::statement('ALTER TABLE stock_ledger DROP CHECK stock_ledger_one_source');
        DB::statement('ALTER TABLE stock_ledger ADD CONSTRAINT stock_ledger_one_source CHECK ((sale_item_id IS NOT NULL) + (purchase_item_id IS NOT NULL) + (reversal_of_id IS NOT NULL) + (adjustment_item_id IS NOT NULL) + (purchase_revision_item_id IS NOT NULL) = 1)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_ledger DROP CHECK stock_ledger_one_source');
        Schema::table('stock_ledger', fn (Blueprint $table) => $table->dropConstrainedForeignId('purchase_revision_item_id'));
        Schema::table('payment_allocations', fn (Blueprint $table) => $table->dropConstrainedForeignId('purchase_revision_id'));
        DB::statement('ALTER TABLE stock_ledger ADD CONSTRAINT stock_ledger_one_source CHECK ((sale_item_id IS NOT NULL) + (purchase_item_id IS NOT NULL) + (reversal_of_id IS NOT NULL) + (adjustment_item_id IS NOT NULL) = 1)');
    }
};
