<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->foreignId('received_by')->nullable()->constrained('users');
            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_device_id')->nullable()->constrained('devices');
        });
        Schema::table('stock_transfer_items', function (Blueprint $table) {
            foreach (['qty_received', 'qty_recovered', 'qty_written_off'] as $field) {
                $table->decimal($field, 18, 3)->default(0);
            }
            foreach (['received_value', 'recovered_value', 'written_off_value'] as $field) {
                $table->decimal($field, 18, 2)->default(0);
            }
        });
        DB::statement('ALTER TABLE stock_transfer_items ADD CONSTRAINT transfer_resolved_quantities CHECK (qty_received >= 0 AND qty_recovered >= 0 AND qty_written_off >= 0 AND qty_received + qty_recovered + qty_written_off <= qty_dispatched)');
        Schema::create('transfer_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_item_id')->constrained('stock_transfer_items');
            $table->string('resolution', 16);
            $table->decimal('qty', 18, 3);
            $table->decimal('value', 18, 2);
            $table->string('reason');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('device_id')->constrained();
            $table->timestamp('resolved_at');
        });
        DB::statement("ALTER TABLE transfer_resolutions ADD CHECK (qty > 0 AND value >= 0 AND resolution IN ('recovered', 'written_off'))");
        DB::statement('ALTER TABLE stock_ledger DROP CHECK stock_ledger_one_source');
        Schema::table('stock_ledger', fn (Blueprint $table) => $table->foreignId('transfer_resolution_id')->nullable()->unique()->constrained());
        DB::statement('ALTER TABLE stock_ledger ADD CONSTRAINT stock_ledger_one_source CHECK ((sale_item_id IS NOT NULL) + (purchase_item_id IS NOT NULL) + (reversal_of_id IS NOT NULL) + (adjustment_item_id IS NOT NULL) + (purchase_revision_item_id IS NOT NULL) + (sale_return_item_id IS NOT NULL) + (purchase_return_item_id IS NOT NULL) + (transfer_item_id IS NOT NULL) + (transfer_resolution_id IS NOT NULL) = 1)');
    }

    public function down(): void
    {
        if (DB::table('stock_transfers')->whereNotNull('received_at')->exists() || DB::table('transfer_resolutions')->exists()) {
            throw new LogicException('Cannot roll back received transfers or resolutions.');
        }
        DB::statement('ALTER TABLE stock_ledger DROP CHECK stock_ledger_one_source');
        Schema::table('stock_ledger', fn (Blueprint $table) => $table->dropConstrainedForeignId('transfer_resolution_id'));
        DB::statement('ALTER TABLE stock_ledger ADD CONSTRAINT stock_ledger_one_source CHECK ((sale_item_id IS NOT NULL) + (purchase_item_id IS NOT NULL) + (reversal_of_id IS NOT NULL) + (adjustment_item_id IS NOT NULL) + (purchase_revision_item_id IS NOT NULL) + (sale_return_item_id IS NOT NULL) + (purchase_return_item_id IS NOT NULL) + (transfer_item_id IS NOT NULL) = 1)');
        Schema::dropIfExists('transfer_resolutions');
        DB::statement('ALTER TABLE stock_transfer_items DROP CHECK transfer_resolved_quantities');
        Schema::table('stock_transfer_items', fn (Blueprint $table) => $table->dropColumn(['qty_received', 'qty_recovered', 'qty_written_off', 'received_value', 'recovered_value', 'written_off_value']));
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('received_by');
            $table->dropConstrainedForeignId('received_device_id');
            $table->dropColumn('received_at');
        });
    }
};
