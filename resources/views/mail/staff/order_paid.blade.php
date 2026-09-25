<x-mail::message>
# Pesanan dibayar — siap dikemas

Pembayaran untuk pesanan **{{ $order->order_number }}** dari
{{ $order->customer_name }} sudah diterima melalui
{{ $order->payment_gateway ?? 'payment gateway' }}.

@include('mail.orders._summary')

<x-mail::button :url="$backofficeUrl">
Buka Pesanan
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
