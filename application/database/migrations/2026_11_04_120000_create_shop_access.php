<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('code', 3);
            $t->string('name');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
            $t->unique(['tenant_id', 'id']);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('tenant_id')->constrained();
            $t->boolean('has_all_branches')->default(false);
            $t->boolean('is_active')->default(true);
            $t->unique(['tenant_id', 'id']);
        });
        Schema::create('branch_user', function (Blueprint $t) {
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('branch_id');
            $t->primary(['user_id', 'branch_id']);
            $t->foreign(['tenant_id', 'user_id'])->references(['tenant_id', 'id'])->on('users');
            $t->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_user');
        Schema::table('users', function (Blueprint $t) {
            $t->dropForeign(['tenant_id']);
            $t->dropUnique(['tenant_id', 'id']);
            $t->dropColumn(['tenant_id', 'has_all_branches', 'is_active']);
        });
        Schema::dropIfExists('branches');
        Schema::dropIfExists('tenants');
    }
};
