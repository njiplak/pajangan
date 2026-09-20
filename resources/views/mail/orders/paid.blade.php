<x-mail::message>
# Pembayaran diterima

Halo {{ $order->customer_name }}, pembayaran untuk pesanan
**{{ $order->order_number }}** sudah kami terima. Pesanan Anda akan kami
siapkan dan kirim segera.

@include('mail.orders._summary')

<x-mail::button :url="$orderUrl">
Lihat Status Pesanan
</x-mail::button>

Salam hangat,<br>
{{ config('app.name') }}
</x-mail::message>
