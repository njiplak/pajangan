<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // The courier's own status as last seen by the webhook. Kept apart
            // from `status` so a repeated problem report alerts staff once.
            $table->string('shipment_status')->nullable()->after('biteship_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('shipment_status');
        });
    }
};
