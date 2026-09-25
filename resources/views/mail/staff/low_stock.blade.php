<x-mail::message>
# Stok menipis

Stok **{{ $product->name }}** tinggal **{{ $product->stock }}**, sudah di
bawah batas {{ $threshold }}. Paket yang memakai produk ini ikut terbatas.

<x-mail::button :url="$backofficeUrl">
Buka Produk
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
