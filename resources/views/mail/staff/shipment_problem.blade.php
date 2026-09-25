<x-mail::message>
# Kurir melaporkan masalah pengiriman

Biteship melaporkan status **{{ $courierStatus }}** untuk pesanan
**{{ $order->order_number }}**. Status pesanan tidak diubah otomatis —
silakan cek dan tentukan langkah selanjutnya.

<x-mail::panel>
**Pelanggan:** {{ $order->customer_name }} ({{ $order->customer_phone }})<br>
**Kurir:** {{ $order->courier_name ?? $order->courier_code ?? '-' }}{{ $order->courier_service ? ' — '.$order->courier_service : '' }}<br>
**Nomor resi:** {{ $order->tracking_number ?? '-' }}
</x-mail::panel>

<x-mail::button :url="$backofficeUrl">
Buka Pesanan
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
