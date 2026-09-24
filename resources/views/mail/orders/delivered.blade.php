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

<x-mail::button :url="$orderUrl">
Lihat Pesanan
</x-mail::button>

Salam hangat,<br>
{{ config('app.name') }}
</x-mail::message>
