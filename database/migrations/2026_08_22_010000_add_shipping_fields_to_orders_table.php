<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('shipping_cost')->nullable()->after('shipping_postal_code');
            $table->string('shipping_area_id')->nullable()->after('shipping_cost');
            $table->string('shipping_area_name')->nullable()->after('shipping_area_id');
            $table->string('courier_code')->nullable()->after('shipping_area_name');
            $table->string('courier_name')->nullable()->after('courier_code');
            $table->string('courier_service')->nullable()->after('courier_name');
            $table->string('courier_etd')->nullable()->after('courier_service');
            $table->string('tracking_number')->nullable()->after('courier_etd');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'shipping_cost',
                'shipping_area_id',
                'shipping_area_name',
                'courier_code',
                'courier_name',
                'courier_service',
                'courier_etd',
                'tracking_number',
            ]);
        });
    }
};
