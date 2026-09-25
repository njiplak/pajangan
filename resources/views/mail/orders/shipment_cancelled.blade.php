<x-mail::message>
# Pengiriman pesanan Anda diatur ulang

Halo {{ $order->customer_name }}, pengiriman untuk pesanan
**{{ $order->order_number }}** kami batalkan di kurir dan akan kami atur
ulang. Pesanan Anda tetap berjalan.

@if ($voidTrackingNumber)
<x-mail::panel>
Nomor resi **{{ $voidTrackingNumber }}** sudah tidak berlaku. Kami akan
mengirim nomor resi yang baru begitu paket diserahkan ke kurir.
</x-mail::panel>
@else
Kami akan mengirim nomor resi begitu paket diserahkan ke kurir.
@endif

<x-mail::button :url="$orderUrl">
Lihat Status Pesanan
</x-mail::button>

Salam hangat,<br>
{{ config('app.name') }}
</x-mail::message>
