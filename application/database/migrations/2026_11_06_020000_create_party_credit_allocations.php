<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('party_credit_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_opening_entry_id')->constrained();
            $table->foreignId('opening_due_id')->constrained('party_opening_entries');
            $table->decimal('amount', 18, 2);
            $table->timestamp('effective_at');
            $table->timestamp('created_at');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('party_credit_allocations');
        });
        DB::statement('ALTER TABLE party_credit_allocations ADD CHECK (amount <> 0)');
    }

    public function down(): void
    {
        if (DB::table('party_credit_allocations')->exists()) {
            throw new LogicException('Cannot discard credit allocation history.');
        }
        Schema::drop('party_credit_allocations');
    }
};
