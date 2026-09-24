<x-mail::message>
# Pesanan dibatalkan

@if ($order->paid_at)
Halo {{ $order->customer_name }}, pesanan **{{ $order->order_number }}**
telah dibatalkan. Karena pembayaran Anda sudah kami terima, tim kami akan
menghubungi Anda untuk proses pengembalian dana.
@else
Halo {{ $order->customer_name }}, pesanan **{{ $order->order_number }}**
kami batalkan karena pembayarannya belum kami terima. Tidak ada biaya apa
pun yang dikenakan kepada Anda.
@endif

@include('mail.orders._summary')

Masih ingin produknya? Silakan pesan kembali — kami dengan senang hati
menyiapkannya.

Salam hangat,<br>
{{ config('app.name') }}
</x-mail::message>
