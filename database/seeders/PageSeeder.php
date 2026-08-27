<?php

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

class PageSeeder extends Seeder
{
    public function run(): void
    {
        Page::updateOrCreate(
            ['slug' => 'tentang-kami'],
            [
                'title' => 'Tentang Kami',
                'meta_description' => 'Kisah, visi, dan misi UMKM Papua.id — gerakan ekonomi lintas batas untuk martabat UMKM Papua.',
                'is_active' => true,
                'body' => <<<'TEXT'
🌋 Sejarah UMKM Papua.id

UMKM Papua.id lahir dari suara seorang aktivis yang melihat Papua bukan sekadar tanah kaya sumber daya, tetapi juga tanah yang sering diperlakukan tidak adil dalam arus ekonomi nasional.

Lex Wu, Direktur PASTI Indonesia, menyaksikan bagaimana pelaku UMKM di Papua berdiri sendiri tanpa dukungan sistematis, berjuang menembus pasar yang seakan tertutup rapat.

Ia menyoroti fakta bahwa ketimpangan ekonomi di Papua bukan karena kurangnya kreativitas atau kerja keras, melainkan karena akses pasar dan kebijakan yang timpang. Dari keresahan itu, lahirlah UMKM Papua.id sebagai platform perjuangan ekonomi bermartabat—sebuah jembatan yang menghubungkan produk lokal Papua dengan pasar nasional dan global.

🌿 Visi

Menjadi gerakan ekonomi lintas batas yang memperjuangkan keadilan bagi UMKM Papua, dengan menghubungkan mereka ke pasar yang lebih luas tanpa kehilangan identitas budaya.

🔥 Misi

- Mengangkat isu ketimpangan ekonomi sebagai agenda publik agar UMKM Papua tidak lagi berjalan sendiri.
- Membangun akses pasar yang adil melalui teknologi dan jejaring kolaboratif.
- Mendorong solidaritas lintas daerah agar perjuangan UMKM Papua menjadi perjuangan bersama bangsa.
- Memperkuat kapasitas pelaku usaha dengan pelatihan, pendampingan, dan dukungan berkelanjutan.
- Menjadikan produk Papua sebagai simbol martabat yang dihargai di pasar nasional maupun global.

🌺 Makna Gerakan

UMKM Papua.id bukan sekadar wadah dagang, melainkan manifesto solidaritas.

Ia menolak logika "Papua berjalan sendiri" dan menggantinya dengan logika kebersamaan.

Ia mengubah keresahan aktivis menjadi gerakan ekonomi yang nyata, di mana setiap produk Papua adalah pesan tentang keadilan dan martabat.
TEXT,
            ]
        );
    }
}
