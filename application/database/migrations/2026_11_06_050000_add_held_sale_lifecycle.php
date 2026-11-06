<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->timestamp('issued_at')->nullable()->change();
            $table->json('held_cart_snapshot')->nullable();
            $table->json('held_pricing_snapshot')->nullable();
            $table->char('held_pricing_fingerprint', 64)->nullable();
            foreach (['held', 'completed', 'discarded'] as $stage) {
                $table->timestamp($stage.'_at')->nullable();
                $table->foreignId($stage.'_by')->nullable()->constrained('users');
            }
            $table->string('discard_reason')->nullable();
            $table->index(['tenant_id', 'branch_id', 'status']);
        });
        Schema::table('sale_items', fn (Blueprint $table) => $table->char('pricing_fingerprint', 64)->nullable());
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('sales')->whereNull('issued_at')->exists()) {
            throw new LogicException('Cannot roll back retained held sales.');
        }

        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'branch_id', 'status']);
            foreach (['held', 'completed', 'discarded'] as $stage) {
                $table->dropConstrainedForeignId($stage.'_by');
                $table->dropColumn($stage.'_at');
            }
            $table->dropColumn(['held_cart_snapshot', 'held_pricing_snapshot', 'held_pricing_fingerprint', 'discard_reason']);
            $table->timestamp('issued_at')->change();
        });
        Schema::table('sale_items', fn (Blueprint $table) => $table->dropColumn('pricing_fingerprint'));
    }
};
