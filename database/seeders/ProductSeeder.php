<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            [
                'name' => 'Kopi Arabika Wamena',
                'description' => 'Kopi arabika premium hasil panen petani dataran tinggi Wamena, disangrai medium untuk cita rasa yang seimbang.',
                'price' => 85000,
                'stock' => 50,
                'producer_name' => 'Koperasi Tani Kopi Wamena',
                'producer_region' => 'Wamena, Papua Pegunungan',
            ],
            [
                'name' => 'Noken Rajut Tradisional',
                'description' => 'Tas noken rajutan tangan dari serat kulit kayu, dibuat oleh perajin perempuan Papua secara turun-temurun.',
                'price' => 150000,
                'stock' => 25,
                'producer_name' => 'Kelompok Perajin Noken Jayawijaya',
                'producer_region' => 'Jayawijaya, Papua Pegunungan',
            ],
            [
                'name' => 'Sagu Bakar Khas Papua',
                'description' => 'Olahan sagu bakar siap konsumsi, makanan pokok tradisional masyarakat Papua.',
                'price' => 35000,
                'stock' => 40,
                'producer_name' => 'UMKM Sagu Sentani',
                'producer_region' => 'Sentani, Papua',
            ],
            [
                'name' => 'Minyak Buah Merah Asli',
                'description' => 'Minyak buah merah murni tanpa campuran, kaya antioksidan, diproses secara tradisional.',
                'price' => 120000,
                'stock' => 30,
                'producer_name' => 'Koperasi Buah Merah Paniai',
                'producer_region' => 'Paniai, Papua Tengah',
            ],
            [
                'name' => 'Kain Tenun Motif Papua',
                'description' => 'Kain tenun dengan motif khas Papua, ditenun manual menggunakan alat tenun tradisional.',
                'price' => 275000,
                'stock' => 15,
                'producer_name' => 'Sanggar Tenun Mimika',
                'producer_region' => 'Mimika, Papua Tengah',
            ],
            [
                'name' => 'Ukiran Kayu Khas Papua',
                'description' => 'Ukiran kayu bermotif tradisional Papua, dipahat langsung oleh perajin lokal.',
                'price' => 350000,
                'stock' => 10,
                'producer_name' => 'Kelompok Perajin Ukir Kamoro',
                'producer_region' => 'Mimika, Papua Tengah',
            ],
            [
                'name' => 'Madu Hutan Papua',
                'description' => 'Madu murni hasil panen hutan Papua, tanpa proses pemanasan, langsung dari peternak lebah lokal.',
                'price' => 95000,
                'stock' => 45,
                'producer_name' => 'Kelompok Tani Hutan Arfak',
                'producer_region' => 'Manokwari, Papua Barat',
            ],
            [
                'name' => 'Keripik Keladi Papua',
                'description' => 'Keripik renyah berbahan dasar keladi lokal Papua, cocok sebagai camilan sehat.',
                'price' => 25000,
                'stock' => 60,
                'producer_name' => 'UMKM Olahan Pangan Nabire',
                'producer_region' => 'Nabire, Papua Tengah',
            ],
        ];

        foreach ($products as $product) {
            Product::updateOrCreate(
                ['name' => $product['name']],
                array_merge($product, ['is_active' => true])
            );
        }
    }
}
