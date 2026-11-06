<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->text('address')->nullable();
            $table->char('state_code', 2)->nullable();
            $table->foreign('state_code')->references('code')->on('gst_states');
            $table->string('gstin', 15)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'name']);
        });
        Schema::create('party_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('supplier_id');
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches');
            $table->foreign(['tenant_id', 'supplier_id'])->references(['tenant_id', 'id'])->on('suppliers');
            $table->string('opening_status', 32)->default('not_entered');
            $table->timestamp('opening_status_effective_at')->nullable();
            $table->timestamp('opening_confirmed_at')->nullable();
            $table->foreignId('opening_confirmed_by')->nullable()->constrained('users');
            $table->unique(['branch_id', 'supplier_id']);
        });
        Schema::create('party_opening_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_account_id')->constrained();
            $table->string('entry_type', 32);
            $table->decimal('signed_amount', 18, 2);
            $table->timestamp('effective_at');
            $table->timestamp('created_at');
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('party_opening_entries');
            $table->foreignId('replaces_entry_id')->nullable()->constrained('party_opening_entries');
            $table->string('reason')->nullable();
            $table->string('status', 32)->default('posted');
            $table->unique(['party_account_id', 'reference'], 'opening_reference_unique');
            $table->index(['party_account_id', 'effective_at', 'id'], 'opening_account_effective_index');
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('supplier_id');
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches');
            $table->foreign(['tenant_id', 'supplier_id'])->references(['tenant_id', 'id'])->on('suppliers');
            $table->string('direction', 8);
            $table->string('purpose', 32);
            $table->string('mode', 16);
            $table->decimal('amount', 18, 2);
            $table->string('reference')->nullable();
            $table->timestamp('paid_at');
            $table->timestamp('created_at');
            $table->boolean('historical_entry')->default(false);
            $table->foreignId('included_in_opening_entry_id')->nullable()->constrained('party_opening_entries');
            $table->string('balance_effect', 32)->default('normal');
            $table->string('status', 32)->default('posted');
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('payments');
            $table->foreignId('created_by')->constrained('users');
            $table->string('reason')->nullable();
            $table->index(['tenant_id', 'branch_id', 'supplier_id', 'paid_at'], 'supplier_payment_effective_index');
        });
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained();
            $table->foreignId('party_opening_entry_id')->constrained();
            $table->decimal('amount', 18, 2);
            $table->timestamp('effective_at');
            $table->timestamp('created_at');
            $table->foreignId('created_by')->constrained('users');
            $table->string('application_kind', 16)->default('automatic');
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('payment_allocations');
            $table->index(['party_opening_entry_id', 'effective_at'], 'opening_allocations_effective_index');
        });
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_positive_amount CHECK (amount > 0)');
        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT allocation_nonzero_amount CHECK (amount <> 0)');
    }

    public function down(): void
    {
        foreach (['payment_allocations', 'payments', 'party_opening_entries', 'party_accounts', 'suppliers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
