<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->text('address')->nullable();
            $table->char('state_code', 2)->nullable();
            $table->foreign('state_code')->references('code')->on('gst_states');
            $table->string('gstin', 15)->nullable();
            $table->decimal('credit_limit', 18, 2)->default(0);
            $table->string('preferred_sale_type', 16)->default('retail');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->string('active_phone', 32)->storedAs('IF(is_active, phone, NULL)');
            $table->unsignedTinyInteger('walk_in_key')->storedAs('IF(is_system, 1, NULL)');
            $table->unique(['tenant_id', 'active_phone']);
            $table->unique(['tenant_id', 'walk_in_key']);
            $table->unique(['tenant_id', 'id']);
            $table->timestamps();
        });
        foreach (['party_accounts', 'payments'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('supplier_id')->nullable()->change();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers');
            });
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_one_party CHECK ((supplier_id IS NOT NULL) + (customer_id IS NOT NULL) = 1)");
        }
        Schema::table('party_accounts', fn (Blueprint $table) => $table->unique(['branch_id', 'customer_id']));
        DB::table('tenants')->select('id')->eachById(fn ($tenant) => DB::table('customers')->insert([
            'tenant_id' => $tenant->id, 'name' => 'Walk-in', 'is_system' => true, 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function down(): void
    {
        if (DB::table('party_accounts')->whereNotNull('customer_id')->exists()
            || DB::table('payments')->whereNotNull('customer_id')->exists()
            || DB::table('customers')->where('is_system', false)->exists()) {
            throw new LogicException('Cannot roll back the customer schema while customer records exist.');
        }
        Schema::table('party_accounts', fn (Blueprint $table) => $table->dropUnique(['branch_id', 'customer_id']));
        foreach (['payments', 'party_accounts'] as $name) {
            DB::statement("ALTER TABLE {$name} DROP CHECK {$name}_one_party");
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['tenant_id', 'customer_id']);
                $table->dropColumn('customer_id');
                $table->unsignedBigInteger('supplier_id')->nullable(false)->change();
            });
        }
        Schema::drop('customers');
    }
};
