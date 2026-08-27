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
            $table->string('payment_gateway')->nullable()->after('total');
            $table->string('payment_channel')->nullable()->after('payment_gateway');
            $table->string('payment_reference')->nullable()->unique()->after('payment_channel');
            $table->string('payment_status')->nullable()->after('payment_reference');
            $table->unsignedBigInteger('admin_fee')->default(0)->after('payment_status');
            $table->string('fee_borne_by')->nullable()->after('admin_fee');
            $table->timestamp('paid_at')->nullable()->after('fee_borne_by');
            $table->json('payment_payload')->nullable()->after('paid_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'payment_gateway',
                'payment_channel',
                'payment_reference',
                'payment_status',
                'admin_fee',
                'fee_borne_by',
                'paid_at',
                'payment_payload',
            ]);
        });
    }
};
