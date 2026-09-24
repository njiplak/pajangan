<x-mail::message>
# Pesanan dibatalkan

Halo {{ $order->customer_name }}, pesanan **{{ $order->order_number }}**
kami batalkan karena pembayaran belum kami terima sampai batas waktunya.
Stok produknya sudah kami kembalikan agar bisa dibeli pembeli lain.

Tidak ada biaya apa pun yang dikenakan kepada Anda.

@include('mail.orders._summary')

Masih ingin produknya? Silakan pesan kembali — kami dengan senang hati
menyiapkannya.

Salam hangat,<br>
{{ config('app.name') }}
</x-mail::message>
