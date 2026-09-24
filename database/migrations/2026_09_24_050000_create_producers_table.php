<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('producers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('region')->nullable();
            $table->text('story')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('producer_id')->nullable()->after('weight_gram')
                ->constrained('producers')->nullOnDelete();
        });

        // Promote the free-text producer on each product into a real row,
        // one per distinct name+region, and link the products to it. The
        // text columns are kept: they are now a cache of the producer.
        $pairs = DB::table('products')
            ->whereNotNull('producer_name')
            ->where('producer_name', '!=', '')
            ->select('producer_name', 'producer_region')
            ->distinct()
            ->get();

        $used = [];

        foreach ($pairs as $pair) {
            $base = Str::slug($pair->producer_name) ?: 'produsen';
            $slug = $base;

            for ($i = 2; in_array($slug, $used, true); $i++) {
                $slug = "{$base}-{$i}";
            }

            $used[] = $slug;

            $id = DB::table('producers')->insertGetId([
                'name' => $pair->producer_name,
                'slug' => $slug,
                'region' => $pair->producer_region,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('products')
                ->where('producer_name', $pair->producer_name)
                ->where(function ($query) use ($pair) {
                    $pair->producer_region === null
                        ? $query->whereNull('producer_region')
                        : $query->where('producer_region', $pair->producer_region);
                })
                ->update(['producer_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('producer_id');
        });

        Schema::dropIfExists('producers');
    }
};
