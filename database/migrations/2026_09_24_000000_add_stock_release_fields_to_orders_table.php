<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Exactly what this order took out of stock, as
            // [product_id => units]. Recorded rather than recomputed so a
            // release puts back what was actually taken, even if a bundle's
            // composition was edited in the meantime.
            $table->json('stock_draw')->nullable()->after('notes');
            // Set the moment stock goes back, so no path can return it twice.
            $table->timestamp('stock_released_at')->nullable()->after('stock_draw');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['stock_draw', 'stock_released_at']);
        });
    }
};
