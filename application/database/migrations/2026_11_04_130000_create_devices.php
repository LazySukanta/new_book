<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->uuid('device_uuid')->unique();
            $t->string('name');
            $t->string('platform');
            $t->string('channel');
            $t->string('status')->default('active');
            $t->foreignId('registered_by')->constrained('users');
            $t->timestamp('registered_at');
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->foreignId('revoked_by')->nullable()->constrained('users');
            $t->string('pairing_hash', 64)->nullable()->unique();
            $t->timestamp('pairing_expires_at')->nullable();
            $t->timestamp('paired_at')->nullable();
            $t->string('credential_hash', 64)->nullable()->unique();
            $t->unique(['tenant_id', 'id']);
            $t->foreign(['tenant_id', 'registered_by'])->references(['tenant_id', 'id'])->on('users');
        });
        Schema::create('device_branch', function (Blueprint $t) {
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('device_id');
            $t->unsignedBigInteger('branch_id');
            $t->primary(['device_id', 'branch_id']);
            $t->foreign(['tenant_id', 'device_id'])->references(['tenant_id', 'id'])->on('devices');
            $t->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches');
        });
        Schema::table('personal_access_tokens', function (Blueprint $t) {
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('device_id')->constrained();
            $t->foreign(['tenant_id', 'device_id'])->references(['tenant_id', 'id'])->on('devices');
        });
        Schema::table('sessions', function (Blueprint $t) {
            $t->foreignId('tenant_id')->nullable()->constrained();
            $t->foreignId('device_id')->nullable()->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $t) {
            $t->dropConstrainedForeignId('device_id');
            $t->dropConstrainedForeignId('tenant_id');
        });
        Schema::table('personal_access_tokens', function (Blueprint $t) {
            $t->dropForeign(['tenant_id', 'device_id']);
            $t->dropConstrainedForeignId('device_id');
            $t->dropConstrainedForeignId('tenant_id');
        });
        Schema::dropIfExists('device_branch');
        Schema::dropIfExists('devices');
    }
};
