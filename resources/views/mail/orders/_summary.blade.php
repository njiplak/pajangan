@php
    $rupiah = fn ($amount) => 'Rp '.number_format((int) $amount, 0, ',', '.');
@endphp

<x-mail::table>
| Produk | Jumlah | Subtotal |
|:-------|:------:|---------:|
@foreach ($order->items as $item)
| {{ $item->product_name }} | {{ $item->quantity }} | {{ $rupiah($item->subtotal) }} |
@endforeach
</x-mail::table>

**Subtotal:** {{ $rupiah($order->subtotal) }}
@if ($order->shipping_cost)
<br>**Ongkir{{ $order->courier_name ? ' ('.$order->courier_name.')' : '' }}:** {{ $rupiah($order->shipping_cost) }}
@endif
@if ($order->admin_fee)
<br>**Biaya admin:** {{ $rupiah($order->admin_fee) }}
@endif
<br>**Total: {{ $rupiah($order->total) }}**

**Dikirim ke:**<br>
{{ $order->customer_name }}<br>
{{ $order->shipping_address }}<br>
{{ $order->shipping_city }}, {{ $order->shipping_province }} {{ $order->shipping_postal_code }}
