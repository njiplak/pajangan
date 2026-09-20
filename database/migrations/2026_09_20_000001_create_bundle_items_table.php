<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bundle_items', function (Blueprint $table) {
            $table->id();
            // Deleting the bundle discards its composition.
            $table->foreignId('bundle_id')->constrained('products')->cascadeOnDelete();
            // A component may not be deleted while a bundle still sells it;
            // ProductService turns the attempt into a readable error first.
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['bundle_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bundle_items');
    }
};
