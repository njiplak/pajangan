<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            // The completed order that entitled this review; kept for
            // provenance, and survives the order being removed.
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('body')->nullable();
            // Staff moderation. Editing a review never flips this back on.
            $table->boolean('is_visible')->default(true);
            $table->timestamps();

            // One review per customer per product: buying again updates
            // it rather than stacking votes.
            $table->unique(['customer_id', 'product_id']);
            $table->index(['product_id', 'is_visible']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
    }
};
