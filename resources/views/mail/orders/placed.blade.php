<x-mail::message>
# Terima kasih, {{ $order->customer_name }}!

Pesanan Anda **{{ $order->order_number }}** sudah kami terima dan sedang
menunggu pembayaran.

@include('mail.orders._summary')

<x-mail::button :url="$orderUrl">
Lihat Status Pesanan
</x-mail::button>

Simpan email ini — tautan di atas adalah cara Anda memantau pesanan ini
kapan saja.

Salam hangat,<br>
{{ config('app.name') }}
</x-mail::message>
