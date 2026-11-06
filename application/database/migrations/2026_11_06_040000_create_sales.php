<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('cashier_id')->constrained('users');
            $table->foreignId('device_id')->constrained();
            $table->foreignId('issued_document_id')->nullable()->unique()->constrained();
            $table->string('invoice_no', 16)->nullable()->unique();
            $table->char('financial_year', 5);
            $table->string('document_type', 32);
            $table->string('sale_type', 16);
            $table->string('status', 16)->default('completed');
            $table->timestamp('issued_at');
            $table->char('pricing_fingerprint', 64);
            $table->json('invoice_snapshot');
            $table->text('note')->nullable();
            foreach (['total', 'paid_amount', 'balance_amount'] as $field) {
                $table->decimal($field, 18, 2);
            }
            $table->index(['tenant_id', 'customer_id', 'branch_id', 'issued_at']);
        });
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->decimal('qty', 18, 3);
            $table->json('line_snapshot');
            $table->json('cost_snapshot')->nullable();
            $table->unique(['sale_id', 'product_id']);
        });
        DB::statement('ALTER TABLE payment_allocations DROP CHECK allocation_one_target');
        Schema::table('payment_allocations', fn (Blueprint $table) => $table->foreignId('sale_id')->nullable()->constrained());
        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT allocation_one_target CHECK ((party_opening_entry_id IS NOT NULL) + (purchase_id IS NOT NULL) + (sale_id IS NOT NULL) = 1)');
        Schema::table('party_credit_allocations', function (Blueprint $table) {
            $table->unsignedBigInteger('opening_due_id')->nullable()->change();
            $table->foreignId('sale_id')->nullable()->constrained();
        });
        DB::statement('ALTER TABLE party_credit_allocations ADD CONSTRAINT credit_one_target CHECK ((opening_due_id IS NOT NULL) + (sale_id IS NOT NULL) = 1)');
    }

    public function down(): void
    {
        if (DB::table('sales')->exists()) {
            throw new LogicException('Cannot roll back posted sales.');
        }
        DB::statement('ALTER TABLE payment_allocations DROP CHECK allocation_one_target');
        Schema::table('payment_allocations', fn (Blueprint $table) => $table->dropConstrainedForeignId('sale_id'));
        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT allocation_one_target CHECK ((party_opening_entry_id IS NOT NULL) + (purchase_id IS NOT NULL) = 1)');
        DB::statement('ALTER TABLE party_credit_allocations DROP CHECK credit_one_target');
        Schema::table('party_credit_allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_id');
            $table->unsignedBigInteger('opening_due_id')->nullable(false)->change();
        });
        Schema::drop('sale_items');
        Schema::drop('sales');
    }
};
