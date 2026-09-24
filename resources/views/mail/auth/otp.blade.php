<x-mail::message>
# Kode verifikasi Anda

Gunakan kode berikut untuk melanjutkan:

<x-mail::panel>
<span style="font-size: 24px; font-weight: 700; letter-spacing: 6px;">{{ $otp }}</span>
</x-mail::panel>

Kode ini berlaku selama {{ $expiresInMinutes }} menit. Jangan bagikan kode
ini kepada siapa pun, termasuk yang mengaku dari {{ config('app.name') }}.

Jika Anda tidak meminta kode ini, abaikan saja email ini.

Salam,<br>
{{ config('app.name') }}
</x-mail::message>
