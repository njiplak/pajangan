<x-mail::message>
# Pesanan dibayar telah dibatalkan

Pesanan **{{ $order->order_number }}** dibatalkan setelah pembayaran
diterima. Pelanggan sudah diberi tahu bahwa tim kita akan menghubungi
mereka untuk pengembalian dana.

<x-mail::panel>
**Pelanggan:** {{ $order->customer_name }}<br>
**Email:** {{ $order->customer_email }}<br>
**Telepon:** {{ $order->customer_phone }}<br>
**Dibayar melalui:** {{ $order->payment_gateway ?? '-' }}{{ $order->payment_reference ? ' ('.$order->payment_reference.')' : '' }}
</x-mail::panel>

@include('mail.orders._summary')

<x-mail::button :url="$backofficeUrl">
Buka Pesanan
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
