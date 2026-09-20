<x-mail::message>
# Pesanan Anda sedang dalam perjalanan

Halo {{ $order->customer_name }}, pesanan **{{ $order->order_number }}**
sudah kami serahkan ke kurir.

<x-mail::panel>
**Kurir:** {{ $order->courier_name ?? '-' }}{{ $order->courier_service ? ' — '.$order->courier_service : '' }}
@if ($order->tracking_number)
<br>**Nomor resi:** {{ $order->tracking_number }}
@endif
</x-mail::panel>

@include('mail.orders._summary')

<x-mail::button :url="$orderUrl">
Lihat Status Pesanan
</x-mail::button>

Salam hangat,<br>
{{ config('app.name') }}
</x-mail::message>
