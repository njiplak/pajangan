<x-mail::message>
# Dana Anda sudah kami kembalikan

Halo {{ $order->customer_name }}, pembayaran untuk pesanan
**{{ $order->order_number }}** yang dibatalkan sudah kami kembalikan.

<x-mail::panel>
**Jumlah:** Rp {{ number_format((int) $order->total, 0, ',', '.') }}
@if ($order->refund_reference)
<br>**Referensi:** {{ $order->refund_reference }}
@endif
</x-mail::panel>

Waktu dana masuk ke rekening Anda tergantung bank atau layanan pembayaran
yang dipakai. Jika belum masuk dalam beberapa hari kerja, balas email ini
dengan menyebut nomor pesanan.

Salam hangat,<br>
{{ config('app.name') }}
</x-mail::message>
