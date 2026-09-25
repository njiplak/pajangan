@php
    $rupiah = fn ($amount) => 'Rp '.number_format((int) $amount, 0, ',', '.');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $order->order_number }} · {{ config('app.name') }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; color: #111; margin: 0; padding: 24px; font-size: 14px; }
        .sheet { max-width: 760px; margin: 0 auto; }
        header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #111; padding-bottom: 12px; margin-bottom: 16px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .muted { color: #555; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
        .box { border: 1px solid #ccc; border-radius: 6px; padding: 12px; }
        .box h2 { font-size: 12px; text-transform: uppercase; letter-spacing: .05em; margin: 0 0 6px; color: #555; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid #ddd; vertical-align: top; }
        th.num, td.num { text-align: right; }
        .check { width: 28px; }
        .totals { margin-left: auto; width: 280px; }
        .totals td { border: none; padding: 3px 6px; }
        .totals .grand td { font-weight: 700; border-top: 2px solid #111; padding-top: 6px; }
        .actions { margin-bottom: 16px; }
        .actions button { font: inherit; padding: 8px 16px; cursor: pointer; }
        @media (max-width: 600px) { .grid { grid-template-columns: 1fr; } .totals { width: 100%; } }
        @media print { body { padding: 0; } .actions { display: none; } }
    </style>
</head>
<body>
<div class="sheet">
    <div class="actions"><button type="button" onclick="window.print()">Cetak</button></div>

    <header>
        <div>
            <h1>{{ config('app.name') }}</h1>
            <div class="muted">Faktur &amp; slip pengemasan</div>
        </div>
        <div style="text-align:right">
            <strong>{{ $order->order_number }}</strong><br>
            <span class="muted">{{ $order->created_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</span><br>
            <span class="muted">{{ \App\Models\Order::statusLabel($order->status) }}</span>
        </div>
    </header>

    <div class="grid">
        <div class="box">
            <h2>Kirim ke</h2>
            <strong>{{ $order->customer_name }}</strong><br>
            {{ $order->customer_phone }}<br>
            {{ $order->shipping_address }}<br>
            {{ $order->shipping_city }}, {{ $order->shipping_province }} {{ $order->shipping_postal_code }}
        </div>
        <div class="box">
            <h2>Pengiriman</h2>
            Kurir: {{ $order->courier_name ?? $order->courier_code ?? '-' }}{{ $order->courier_service ? ' — '.$order->courier_service : '' }}<br>
            Resi: {{ $order->tracking_number ?? '-' }}<br>
            @if ($order->notes)
                Catatan pelanggan: {{ $order->notes }}
            @endif
        </div>
    </div>

    <table>
        <thead>
        <tr>
            <th class="check">✓</th>
            <th>Produk</th>
            <th class="num">Jumlah</th>
            <th class="num">Harga</th>
            <th class="num">Subtotal</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($order->items as $item)
            <tr>
                <td class="check">☐</td>
                <td>{{ $item->product_name }}</td>
                <td class="num">{{ $item->quantity }}</td>
                <td class="num">{{ $rupiah($item->unit_price) }}</td>
                <td class="num">{{ $rupiah($item->subtotal) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td class="num">{{ $rupiah($order->subtotal) }}</td></tr>
        @if ($order->shipping_cost)
            <tr><td>Ongkir</td><td class="num">{{ $rupiah($order->shipping_cost) }}</td></tr>
        @endif
        @if ($order->shipping_discount)
            <tr><td>Gratis ongkir</td><td class="num">−{{ $rupiah($order->shipping_discount) }}</td></tr>
        @endif
        @if ($order->admin_fee)
            <tr><td>Biaya admin</td><td class="num">{{ $rupiah($order->admin_fee) }}</td></tr>
        @endif
        <tr class="grand"><td>Total</td><td class="num">{{ $rupiah($order->total) }}</td></tr>
    </table>
</div>
</body>
</html>
