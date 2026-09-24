<x-mail::message>
# Pesanan Anda sudah sampai

Halo {{ $order->customer_name }}, menurut kurir, pesanan
**{{ $order->order_number }}** sudah sampai di tujuan.

@if ($order->tracking_number)
<x-mail::panel>
**Nomor resi:** {{ $order->tracking_number }}
</x-mail::panel>
@endif

Terima kasih sudah mendukung UMKM Papua. Kalau ada yang kurang berkenan,
balas email ini — kami akan bantu.

@php
    // Only products still in the catalogue can be reviewed.
    $reviewable = $order->items->filter(fn ($item) => $item->product?->is_active)->unique('product_id');
@endphp
@if ($reviewable->isNotEmpty())
**Bagaimana produknya?** Ulasan Anda membantu pembeli lain dan para
pelaku UMKM. Masuk dengan akun Google yang memakai email ini, lalu beri
ulasan di halaman produknya:

@foreach ($reviewable as $item)
- [{{ $item->product_name }}]({{ route('products.show', $item->product->slug) }})
@endforeach
@endif

<x-mail::button :url="$orderUrl">
Lihat Pesanan
</x-mail::button>

Salam hangat,<br>
{{ config('app.name') }}
</x-mail::message>
