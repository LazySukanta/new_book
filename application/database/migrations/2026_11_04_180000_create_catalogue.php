<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['categories', 'units', 'tax_rates'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->id();
                $table->foreignId('tenant_id')->constrained();
                $table->string('name');
                $table->boolean('is_active')->default(true);
                $table->unique(['tenant_id', 'id']);
                if ($name === 'units') {
                    $table->string('code', 32);
                    $table->boolean('allow_decimal')->default(false);
                    $table->unique(['tenant_id', 'code']);
                } elseif ($name === 'tax_rates') {
                    $table->unsignedSmallInteger('rate_bp');
                } else {
                    $table->unique(['tenant_id', 'name']);
                }
            });
        }

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->string('sku', 100);
            $table->string('barcode', 100)->nullable();
            $table->string('name');
            foreach (['categories' => 'category_id', 'units' => 'unit_id', 'tax_rates' => 'tax_rate_id'] as $parent => $column) {
                $table->unsignedBigInteger($column)->nullable($parent === 'categories');
                $table->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on($parent);
            }
            $table->string('hsn', 8)->nullable();
            $table->boolean('tax_inclusive');
            foreach (['retail_price', 'wholesale_price', 'purchase_price'] as $price) {
                $table->decimal($price, 18, 2)->default(0);
            }
            $table->decimal('reorder_level', 18, 3)->default(0);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
            foreach (['sku', 'barcode', 'id'] as $column) {
                $table->unique(['tenant_id', $column]);
            }
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('product_price_slabs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained();
            $table->decimal('min_qty', 18, 3);
            $table->decimal('price', 18, 2);
            $table->unique(['product_id', 'min_qty']);
        });

        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('product_id');
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches');
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products');
            $table->decimal('qty', 18, 3)->default(0);
            $table->decimal('inventory_value', 18, 2)->default(0);
            $table->decimal('avg_cost', 24, 9)->default(0);
            $table->decimal('last_known_unit_cost', 24, 9)->nullable();
            $table->boolean('valuation_provisional')->default(false);
            $table->unsignedBigInteger('last_ledger_id')->nullable();
            $table->unique(['branch_id', 'product_id']);
        });
    }

    public function down(): void
    {
        foreach (['stock_balances', 'product_price_slabs', 'products', 'tax_rates', 'units', 'categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
