<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('branch_id')->constrained();
            $table->char('financial_year', 5);
            $table->string('document_type', 32);
            $table->string('owning_channel', 32);
            $table->char('prefix', 1);
            $table->unsignedInteger('next_number')->default(1);
            $table->unsignedSmallInteger('rollover_order');
            $table->string('status', 16)->default('ready');
            $table->timestamp('first_issued_at')->nullable();
            $table->timestamp('exhausted_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->unique(['branch_id', 'financial_year', 'prefix']);
            $table->unique(['branch_id', 'financial_year', 'document_type', 'owning_channel', 'rollover_order'], 'document_series_rollover_unique');
        });
        Schema::create('issued_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_series_id')->constrained();
            $table->foreignId('device_id')->constrained();
            $table->foreignId('created_by')->constrained('users');
            $table->string('document_no', 16)->unique();
            $table->unsignedInteger('serial');
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->timestamp('issued_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->string('cancellation_reason')->nullable();
            $table->unique(['source_type', 'source_id']);
            $table->unique(['document_series_id', 'serial']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issued_documents');
        Schema::dropIfExists('document_series');
    }
};
