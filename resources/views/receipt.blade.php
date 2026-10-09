<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt {{ $order->order_number }}</title>
    <style>
        /* Self-contained so it prints the same everywhere; sized for 80 mm thermal paper. */
        @page { size: 80mm auto; margin: 4mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #eaf1eb; font: 13px/1.45 ui-monospace, 'Courier New', monospace; color: #111; }
        .paper { width: 76mm; margin: 24px auto; background: #fff; padding: 14px 12px 18px; box-shadow: 0 8px 30px -12px rgba(23,58,44,.45); }
        .c { text-align: center; } .r { text-align: right; } .b { font-weight: 700; }
        .big { font-size: 26px; font-weight: 700; letter-spacing: 1px; }
        .muted { color: #555; font-size: 11.5px; }
        hr { border: 0; border-top: 1px dashed #777; margin: 8px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; padding: 1px 0; }
        .stamp { border: 2px solid #b4443a; color: #b4443a; padding: 2px 8px; display: inline-block; font-weight: 700; transform: rotate(-4deg); margin: 6px 0; }
        .actions { width: 76mm; margin: 0 auto 24px; display: flex; gap: 8px; }
        .actions button, .actions a { flex: 1; padding: 10px; border-radius: 10px; border: 1px solid #c4d1c7; background: #fff; font: 600 14px system-ui, sans-serif; cursor: pointer; text-align: center; text-decoration: none; color: #1a2520; }
        .actions .primary { background: #2f6b4f; color: #fff; border-color: #2f6b4f; }
        @media print { body { background: #fff; } .paper { margin: 0; box-shadow: none; width: auto; } .actions { display: none; } }
    </style>
</head>
<body>
<div class="paper">
    <div class="c">
        <div class="b" style="font-size:16px">{{ $store['name'] }}</div>
        <div class="muted">{{ $store['address'] }}</div>
        @if ($store['contact'])<div class="muted">{{ $store['contact'] }}</div>@endif
        @if ($store['vat_registered'] && $store['tin'])<div class="muted">VAT REG TIN: {{ $store['tin'] }}</div>@endif
    </div>
    <hr>
    <div class="c">
        <div class="muted">Queue number</div>
        <div class="big">#{{ $order->queue_number }}</div>
        <div>{{ \App\Models\Order::TYPES[$order->order_type] }}@if ($order->table_number) · Table {{ $order->table_number }}@endif</div>
        @if ($order->customer_name)<div>{{ $order->customer_name }}</div>@endif
        @if ($order->status === 'refunded')<div class="stamp">REFUNDED</div>@endif
        @if ($order->status === 'cancelled')<div class="stamp">CANCELLED</div>@endif
    </div>
    <hr>
    <table>
        <tr><td>Order</td><td class="r">{{ $order->order_number }}</td></tr>
        <tr><td>Date</td><td class="r">{{ $order->created_at->format('M d, Y g:i A') }}</td></tr>
        <tr><td>Cashier</td><td class="r">{{ $order->cashier->name }}</td></tr>
    </table>
    <hr>
    <table>
        @foreach ($order->items as $item)
            <tr><td colspan="2" class="b">{{ $item->quantity }} × {{ $item->product_name }}</td></tr>
            <tr>
                <td class="muted" style="padding-left:12px">{{ $item->variant_name }} @ {{ peso($item->unit_price, false) }}</td>
                <td class="r">{{ peso($item->line_total, false) }}</td>
            </tr>
            @foreach ($item->addons as $addon)
                <tr><td class="muted" style="padding-left:12px" colspan="2">+ {{ $addon->addon_name }} ({{ peso($addon->price, false) }})</td></tr>
            @endforeach
            @if ($item->notes)<tr><td class="muted" style="padding-left:12px" colspan="2">“{{ $item->notes }}”</td></tr>@endif
        @endforeach
    </table>
    <hr>
    <table>
        <tr><td>Subtotal</td><td class="r">{{ peso($order->subtotal, false) }}</td></tr>
        @if ((float) $order->vat_exempt_sales > 0)
            <tr><td>Less: VAT</td><td class="r">-{{ peso((float) $order->subtotal - (float) $order->vat_exempt_sales, false) }}</td></tr>
        @endif
        @if ((float) $order->discount_amount > 0)
            <tr><td>{{ $order->discount_name }} disc.</td><td class="r">-{{ peso($order->discount_amount, false) }}</td></tr>
        @endif
        <tr class="b" style="font-size:15px"><td>TOTAL</td><td class="r">₱{{ peso($order->total_amount, false) }}</td></tr>
        @if ($order->payment)
            <tr><td>{{ $order->payment->methodLabel() }}{{ $order->payment->provider ? ' ('.$order->payment->provider.')' : '' }}</td>
                <td class="r">{{ peso($order->payment->amount_tendered ?? $order->payment->amount, false) }}</td></tr>
            @if ($order->payment->method === 'cash')<tr><td>Change</td><td class="r">{{ peso($order->payment->change_amount, false) }}</td></tr>@endif
            @if ($order->payment->reference_number)<tr><td class="muted">Ref no.</td><td class="r muted">{{ $order->payment->reference_number }}</td></tr>@endif
        @else
            <tr><td colspan="2" class="c b">*** UNPAID ***</td></tr>
        @endif
    </table>
    @if ($store['vat_registered'])
        <hr>
        <table class="muted">
            <tr><td>VATable sales</td><td class="r">{{ peso($order->vatable_sales, false) }}</td></tr>
            <tr><td>VAT ({{ (float) \App\Models\Setting::get('vat_rate') }}%)</td><td class="r">{{ peso($order->vat_amount, false) }}</td></tr>
            <tr><td>VAT-exempt sales</td><td class="r">{{ peso($order->vat_exempt_sales, false) }}</td></tr>
            @if ($order->discount_id_number)<tr><td>{{ $order->discount_name }} ID</td><td class="r">{{ $order->discount_id_number }}</td></tr>@endif
        </table>
    @endif
    <hr>
    <div class="c muted">{{ $store['footer'] }}</div>
    <div class="c muted" style="margin-top:6px">This is not an official receipt.</div>
</div>
<div class="actions">
    <a href="javascript:window.close()">Close</a>
    <button class="primary" onclick="window.print()">Print receipt</button>
</div>
</body>
</html>
