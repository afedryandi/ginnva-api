<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #333; font-size: 13px; line-height: 1.45; }
        .header { width: 100%; margin-bottom: 22px; border-bottom: 2px solid #222; padding-bottom: 14px; }
        .brand { font-size: 20px; font-weight: bold; color: #111; }
        .brand-sub { font-size: 10px; color: #666; margin-top: 2px; }
        .title { font-size: 18px; font-weight: bold; text-align: right; color: #111; text-transform: uppercase; }
        .title-sub { font-size: 11px; color: #666; font-weight: normal; text-transform: none; }
        .paid-badge { display: inline-block; background: #dcfce7; color: #166534; padding: 3px 10px; border-radius: 4px; font-size: 11px; font-weight: bold; margin-top: 4px; }
        .due-badge { display: inline-block; background: #fef3c7; color: #92400e; padding: 3px 10px; border-radius: 4px; font-size: 11px; font-weight: bold; margin-top: 4px; }
        .void-badge { display: inline-block; background: #fee2e2; color: #991b1b; padding: 3px 10px; border-radius: 4px; font-size: 11px; font-weight: bold; margin-top: 4px; }
        .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .meta-table td { padding: 3px 0; vertical-align: top; }
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .items-table th { background-color: #111; color: #fff; text-align: left; padding: 8px 10px; font-weight: bold; text-transform: uppercase; font-size: 11px; }
        .items-table td { padding: 10px; border-bottom: 1px solid #eee; vertical-align: top; }
        .text-right { text-align: right; }
        .totals { width: 55%; float: right; border-collapse: collapse; margin-top: 6px; }
        .totals td { padding: 5px 10px; }
        .totals .grand { border-top: 2px solid #222; font-size: 15px; font-weight: bold; }
        .footer { clear: both; margin-top: 60px; width: 100%; font-size: 10px; color: #888; border-top: 1px solid #e4e4e4; padding-top: 8px; }
    </style>
</head>
<body>

    <table class="header">
        <tr>
            <td>
                <div class="brand">GINNVA</div>
                <div class="brand-sub">
                    {{ $invoice->store?->name ?? 'Ginnva Shield Indonesia' }}<br>
                    @if ($invoice->store?->address){{ $invoice->store->address }}<br>@endif
                    @if ($invoice->store?->city){{ $invoice->store->city }}@endif
                    @if ($invoice->store?->phone) &middot; {{ $invoice->store->phone }}@endif
                </div>
            </td>
            <td class="title">
                Invoice
                <div class="title-sub">No. {{ $invoice->invoice_number }}</div>
                @if ($invoice->status === 'void')
                    <br><span class="void-badge">VOID</span>
                @elseif ($invoice->remainingAmount() <= 0)
                    <br><span class="paid-badge">LUNAS</span>
                @else
                    <br><span class="due-badge">SISA Rp {{ number_format($invoice->remainingAmount(), 0, ',', '.') }}</span>
                @endif
            </td>
        </tr>
    </table>

    <table class="meta-table">
        <tr>
            <td style="width: 16%;"><strong>Ditagihkan kepada</strong></td>
            <td style="width: 40%;">: {{ $invoice->customer_name }}</td>
            <td style="width: 16%;"><strong>Tanggal Invoice</strong></td>
            <td style="width: 28%;">: {{ $invoice->issue_date?->translatedFormat('d F Y') }}</td>
        </tr>
        <tr>
            <td><strong>Telepon</strong></td>
            <td>: {{ $invoice->customer_phone ?: '—' }}</td>
            <td><strong>Jatuh Tempo</strong></td>
            <td>: {{ $invoice->due_date?->translatedFormat('d F Y') ?? '—' }}</td>
        </tr>
        <tr>
            <td><strong>Alamat</strong></td>
            <td>: {{ $invoice->billing_address ?: '—' }}</td>
            <td><strong>No. Booking</strong></td>
            <td>: {{ $invoice->booking?->booking_number ?? '—' }}</td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 40%;">Produk</th>
                <th class="text-right">Qty</th>
                <th class="text-right">Harga</th>
                <th class="text-right">Diskon</th>
                <th class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr>
                    <td>{{ $item->name }}</td>
                    <td class="text-right">{{ rtrim(rtrim(number_format($item->quantity, 2), '0'), '.') }} {{ $item->unit }}</td>
                    <td class="text-right">Rp{{ number_format($item->price, 0, ',', '.') }}</td>
                    <td class="text-right">{{ $item->discount_percent > 0 ? $item->discount_percent . '%' : '—' }}</td>
                    <td class="text-right">Rp{{ number_format($item->total, 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>Subtotal</td>
            <td class="text-right">Rp{{ number_format($invoice->subtotal, 0, ',', '.') }}</td>
        </tr>
        @if ($invoice->transaction_discount_value > 0)
            <tr>
                <td>Diskon Transaksi</td>
                <td class="text-right">
                    ({{ $invoice->transaction_discount_type === 'percent' ? $invoice->transaction_discount_value . '%' : 'Rp' . number_format($invoice->transaction_discount_value, 0, ',', '.') }})
                </td>
            </tr>
        @endif
        @if ($invoice->shipping_cost > 0)
            <tr>
                <td>Biaya Pengiriman</td>
                <td class="text-right">Rp{{ number_format($invoice->shipping_cost, 0, ',', '.') }}</td>
            </tr>
        @endif
        @if ($invoice->other_cost > 0)
            <tr>
                <td>Biaya Lainnya</td>
                <td class="text-right">Rp{{ number_format($invoice->other_cost, 0, ',', '.') }}</td>
            </tr>
        @endif
        <tr class="grand">
            <td>Total Tagihan</td>
            <td class="text-right">Rp{{ number_format($invoice->total, 0, ',', '.') }}</td>
        </tr>
        @if ($invoice->amount_paid > 0)
            <tr>
                <td>Sudah Dibayar</td>
                <td class="text-right">Rp{{ number_format($invoice->amount_paid, 0, ',', '.') }}</td>
            </tr>
        @endif
    </table>

    @if ($invoice->notes)
        <div style="clear: both; padding-top: 30px;">
            <strong>Keterangan</strong><br>{{ $invoice->notes }}
        </div>
    @endif

    @if ($invoice->terms_conditions)
        <div style="padding-top: 12px;">
            <strong>Syarat dan Ketentuan</strong><br>{{ $invoice->terms_conditions }}
        </div>
    @endif

    <div class="footer">
        Dicetak {{ now()->translatedFormat('d F Y H:i') }} — dokumen ini bukan Faktur Pajak.
    </div>

</body>
</html>
