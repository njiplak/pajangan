<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            [
                'key' => 'app_name',
                'value' => 'Kawakib',
            ],
            [
                'key' => 'app_version',
                'value' => '1.0.0',
            ],
            [
                'key' => 'storefront_mode',
                'value' => 'checkout',
            ],
            [
                'key' => 'storefront_whatsapp_number',
                'value' => '6281234567890',
            ],
            [
                'key' => 'storefront_title',
                'value' => 'UMKM Papua.id',
            ],
            [
                'key' => 'storefront_subtitle',
                'value' => 'Perjuangan Ekonomi Bermartabat',
            ],
            [
                'key' => 'storefront_footer_description',
                'value' => 'Jembatan yang menghubungkan produk lokal Papua dengan pasar nasional dan global — sebuah gerakan ekonomi lintas batas untuk martabat UMKM Papua.',
            ],
            [
                'key' => 'storefront_footer_copyright',
                'value' => 'Seluruh hak cipta dilindungi.',
            ],
            [
                'key' => 'storefront_hero_title',
                'value' => 'Perjuangan Ekonomi Bermartabat untuk UMKM Papua',
            ],
            [
                'key' => 'storefront_hero_subtitle',
                'value' => 'Jembatan yang menghubungkan produk asli Papua dengan pasar nasional dan global, tanpa kehilangan identitas budaya.',
            ],
            [
                'key' => 'seo_default_description',
                'value' => 'UMKM Papua.id — jembatan yang menghubungkan produk lokal Papua dengan pasar nasional dan global.',
            ],
            [
                'key' => 'seo_og_image_url',
                'value' => '',
            ],
            [
                'key' => 'low_stock_threshold',
                'value' => '10',
            ],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }
}
