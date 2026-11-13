<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('source_branch_id')->constrained('branches');
            $table->foreignId('destination_branch_id')->constrained('branches');
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->string('transfer_no', 16)->nullable()->unique();
            $table->foreignId('issued_document_id')->nullable()->unique()->constrained();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('dispatched_by')->nullable()->constrained('users');
            $table->foreignId('device_id')->nullable()->constrained();
            $table->timestamp('created_at');
            $table->timestamp('dispatched_at')->nullable();
            $table->string('note', 1000)->nullable();
            $table->json('dispatch_snapshot')->nullable();
            $table->index(['tenant_id', 'source_branch_id', 'id']);
            $table->index(['tenant_id', 'destination_branch_id', 'id']);
            $table->foreign(['tenant_id', 'source_branch_id'])->references(['tenant_id', 'id'])->on('branches');
            $table->foreign(['tenant_id', 'destination_branch_id'])->references(['tenant_id', 'id'])->on('branches');
        });
        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('stock_transfers');
            $table->foreignId('product_id')->constrained();
            $table->decimal('qty_requested', 18, 3);
            $table->decimal('qty_dispatched', 18, 3)->default(0);
            $table->decimal('unit_cost', 24, 9)->default(0);
            $table->decimal('dispatched_value', 18, 2)->default(0);
            $table->unique(['transfer_id', 'product_id']);
        });
        DB::statement('ALTER TABLE stock_transfers ADD CHECK (source_branch_id <> destination_branch_id)');
        DB::statement('ALTER TABLE stock_transfer_items ADD CHECK (qty_requested > 0 AND qty_dispatched >= 0)');
        DB::statement('ALTER TABLE stock_ledger DROP CHECK stock_ledger_one_source');
        Schema::table('stock_ledger', function (Blueprint $table) {
            $table->foreignId('transfer_item_id')->nullable()->constrained('stock_transfer_items');
            $table->unique(['transfer_item_id', 'branch_id', 'type'], 'stock_ledger_transfer_event_unique');
        });
        DB::statement('ALTER TABLE stock_ledger ADD CONSTRAINT stock_ledger_one_source CHECK ((sale_item_id IS NOT NULL) + (purchase_item_id IS NOT NULL) + (reversal_of_id IS NOT NULL) + (adjustment_item_id IS NOT NULL) + (purchase_revision_item_id IS NOT NULL) + (sale_return_item_id IS NOT NULL) + (purchase_return_item_id IS NOT NULL) + (transfer_item_id IS NOT NULL) = 1)');
    }

    public function down(): void
    {
        if (DB::table('stock_transfers')->where('status', '!=', 'draft')->exists()) {
            throw new LogicException('Cannot roll back dispatched transfers.');
        }
        DB::statement('ALTER TABLE stock_ledger DROP CHECK stock_ledger_one_source');
        Schema::table('stock_ledger', function (Blueprint $table) {
            $table->dropForeign(['transfer_item_id']);
            $table->dropUnique('stock_ledger_transfer_event_unique');
            $table->dropColumn('transfer_item_id');
        });
        DB::statement('ALTER TABLE stock_ledger ADD CONSTRAINT stock_ledger_one_source CHECK ((sale_item_id IS NOT NULL) + (purchase_item_id IS NOT NULL) + (reversal_of_id IS NOT NULL) + (adjustment_item_id IS NOT NULL) + (purchase_revision_item_id IS NOT NULL) + (sale_return_item_id IS NOT NULL) + (purchase_return_item_id IS NOT NULL) = 1)');
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
    }
};
