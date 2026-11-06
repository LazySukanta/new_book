<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gst_states', function (Blueprint $table) {
            $table->char('code', 2)->primary();
            $table->string('name');
        });
        DB::table('gst_states')->insert([
            ['code' => '01', 'name' => 'Jammu and Kashmir'],
            ['code' => '02', 'name' => 'Himachal Pradesh'],
            ['code' => '03', 'name' => 'Punjab'],
            ['code' => '04', 'name' => 'Chandigarh'],
            ['code' => '05', 'name' => 'Uttarakhand'],
            ['code' => '06', 'name' => 'Haryana'],
            ['code' => '07', 'name' => 'Delhi'],
            ['code' => '08', 'name' => 'Rajasthan'],
            ['code' => '09', 'name' => 'Uttar Pradesh'],
            ['code' => '10', 'name' => 'Bihar'],
            ['code' => '11', 'name' => 'Sikkim'],
            ['code' => '12', 'name' => 'Arunachal Pradesh'],
            ['code' => '13', 'name' => 'Nagaland'],
            ['code' => '14', 'name' => 'Manipur'],
            ['code' => '15', 'name' => 'Mizoram'],
            ['code' => '16', 'name' => 'Tripura'],
            ['code' => '17', 'name' => 'Meghalaya'],
            ['code' => '18', 'name' => 'Assam'],
            ['code' => '19', 'name' => 'West Bengal'],
            ['code' => '20', 'name' => 'Jharkhand'],
            ['code' => '21', 'name' => 'Odisha'],
            ['code' => '22', 'name' => 'Chhattisgarh'],
            ['code' => '23', 'name' => 'Madhya Pradesh'],
            ['code' => '24', 'name' => 'Gujarat'],
            ['code' => '26', 'name' => 'Dadra and Nagar Haveli and Daman and Diu'],
            ['code' => '27', 'name' => 'Maharashtra'],
            ['code' => '29', 'name' => 'Karnataka'],
            ['code' => '30', 'name' => 'Goa'],
            ['code' => '31', 'name' => 'Lakshadweep'],
            ['code' => '32', 'name' => 'Kerala'],
            ['code' => '33', 'name' => 'Tamil Nadu'],
            ['code' => '34', 'name' => 'Puducherry'],
            ['code' => '35', 'name' => 'Andaman and Nicobar Islands'],
            ['code' => '36', 'name' => 'Telangana'],
            ['code' => '37', 'name' => 'Andhra Pradesh'],
            ['code' => '38', 'name' => 'Ladakh'],
        ]);
        Schema::table('branches', function (Blueprint $table) {
            $table->text('address')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('gstin', 15)->nullable();
            $table->char('state_code', 2)->nullable();
            $table->foreign('state_code')->references('code')->on('gst_states');
        });
        Schema::create('shop_settings', function (Blueprint $table) {
            $table->foreignId('tenant_id')->constrained();
            $table->primary('tenant_id');
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('logo_path')->nullable();
            $table->text('footer')->nullable();
            $table->string('gst_registration_type')->default('regular');
            $table->boolean('price_inclusive_default')->default(true);
            $table->unsignedSmallInteger('max_cashier_discount_bp')->default(500);
            $table->unsignedSmallInteger('manager_cancel_window_days')->default(0);
            $table->boolean('hsn_required')->default(false);
            $table->unsignedSmallInteger('receipt_width_mm')->default(80);
            $table->boolean('allow_negative_stock')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_settings');
        Schema::table('branches', function (Blueprint $table) {
            $table->dropForeign(['state_code']);
            $table->dropColumn(['address', 'phone', 'gstin', 'state_code']);
        });
        Schema::dropIfExists('gst_states');
    }
};
