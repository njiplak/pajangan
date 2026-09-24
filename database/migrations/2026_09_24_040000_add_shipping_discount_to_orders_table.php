<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Ongkir the store absorbed under the free-shipping rule.
            // Separate from shipping_cost, which stays the courier's price
            // (and is what the courier webhook later updates).
            $table->unsignedBigInteger('shipping_discount')->default(0)->after('shipping_cost');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('shipping_discount');
        });
    }
};
