<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $t) {
            $t->timestamp('expires_at')->nullable(false)->change();
        });
        Schema::table('sessions', function (Blueprint $t) {
            $t->foreign(['tenant_id', 'device_id'])->references(['tenant_id', 'id'])->on('devices');
        });
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $t) {
            $t->dropForeign(['tenant_id', 'device_id']);
        });
        Schema::table('personal_access_tokens', function (Blueprint $t) {
            $t->timestamp('expires_at')->nullable()->change();
        });
    }
};
