<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    {{-- Tata letak mengikuti contoh invoice yang dipakai perusahaan (2026-12-16): judul dan status di atas, data perusahaan di kiri dan nomor
         di kanan, kolom Ditagihkan kepada / Dikirimkan ke, tabel barang, total di kanan, lalu Keterangan dan Syarat dan Ketentuan.
         Tidak ada kolom Pajak karena modul Invoice belum menyimpan pajak per barang (lihat catatan di model Invoice). --}}
    @php
        $invoice->loadMissing(['items.filmProduct', 'booking', 'store']);
        $company = \App\Models\FinanceSetting::companyProfile();
        $money = fn ($value) => 'Rp ' . number_format((float) $value, 0, ',', '.');
        $remaining = max(0, $invoice->remainingAmount());

        if ($invoice->status === 'void') {
            $statusText = 'Void';
            $statusColor = '#991b1b';
        } elseif ($invoice->remainingAmount() <= 0) {
            $statusText = 'Lunas';
            $statusColor = '#166534';
        } else {
            $statusText = 'Belum Lunas';
            $statusColor = '#92400e';
        }

        $termin = '-';

        if ($invoice->due_date) {
            $days = $invoice->issue_date ? (int) $invoice->issue_date->diffInDays($invoice->due_date, false) : null;
            $termin = ($days !== null && $days >= 0 ? $days . ' Hari (' : '(') . $invoice->due_date->translatedFormat('j F Y') . ')';
        }

        $transactionDiscount = (float) $invoice->transaction_discount_value;

        if ($invoice->transaction_discount_type === 'percent') {
            $transactionDiscount = round((float) $invoice->subtotal * $transactionDiscount / 100, 2);
        }

        $shippingTo = $invoice->shipping_address ?: $invoice->billing_address;
    @endphp
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #222; font-size: 11px; line-height: 1.45; }
        table { border-collapse: collapse; }
        .top { width: 100%; margin-bottom: 14px; }
        .doc-title { font-size: 24px; font-weight: bold; color: #111; }
        .doc-status { font-size: 18px; font-weight: bold; text-align: right; color: {{ $statusColor }}; }
        .info { width: 100%; margin-bottom: 16px; }
        .info td { vertical-align: top; }
        .company-name { font-weight: bold; font-size: 12px; margin-bottom: 2px; }
        .meta td { padding: 1px 0; }
        .meta .k { width: 38%; }
        .parties { width: 100%; margin-bottom: 14px; }
        .parties td { width: 50%; vertical-align: top; padding-right: 12px; }
        .party-title { font-weight: bold; margin-bottom: 2px; }
        .items { width: 100%; margin-bottom: 8px; }
        .items th { white-space: nowrap; border-top: 1px solid #222; border-bottom: 1px solid #222; padding: 6px 5px; text-align: left; font-size: 10px; }
        .items td { border-bottom: 1px solid #ddd; padding: 7px 5px; vertical-align: top; }
        .right { text-align: right; }
        .center { text-align: center; }
        .totals { width: 52%; margin-left: 48%; margin-top: 6px; }
        .totals td { padding: 3px 5px; text-align: right; white-space: nowrap; }
        .totals .strong td { font-weight: bold; }
        .block { margin-top: 18px; }
        .block-title { font-weight: bold; margin-bottom: 2px; }
        .block-body { white-space: pre-line; }
        .footer { margin-top: 28px; border-top: 1px solid #ddd; padding-top: 6px; font-size: 9px; color: #777; }
    </style>
</head>
<body>

    <table class="top">
        <tr>
            <td class="doc-title">Invoice</td>
            <td class="doc-status">{{ $statusText }}</td>
        </tr>
    </table>

    <table class="info">
        <tr>
            <td style="width: 52%; padding-right: 16px;">
                <div class="company-name">{{ $company['name'] }}</div>
                @if ($company['address'])
                    <div>{{ $company['address'] }}</div>
                @elseif ($invoice->store?->address)
                    <div>{{ $invoice->store->address }}@if ($invoice->store?->city), {{ $invoice->store->city }}@endif</div>
                @endif
                @if ($invoice->store?->phone)
                    <div>{{ $invoice->store->phone }}</div>
                @endif
            </td>
            <td style="width: 48%;">
                <table class="meta" style="width: 100%;">
                    <tr><td class="k">No. Pesanan</td><td>: {{ $invoice->booking?->booking_number ?? '-' }}</td></tr>
                    <tr><td class="k">No. Invoice</td><td>: {{ $invoice->invoice_number }}</td></tr>
                    <tr><td class="k">Tanggal Dibuat</td><td>: {{ $invoice->issue_date?->translatedFormat('j F Y') ?? '-' }}</td></tr>
                    <tr><td class="k">Termin Pembayaran</td><td>: {{ $termin }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="parties">
        <tr>
            <td>
                <div class="party-title">Ditagihkan kepada:</div>
                <div>{{ $invoice->customer_name }}</div>
                @if ($invoice->billing_address)<div>{{ $invoice->billing_address }}</div>@endif
                @if ($invoice->customer_phone)<div>{{ $invoice->customer_phone }}</div>@endif
            </td>
            <td>
                <div class="party-title">Dikirimkan ke Alamat:</div>
                <div>{{ $invoice->customer_name }}</div>
                @if ($shippingTo)<div>{{ $shippingTo }}</div>@endif
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 4%;">No.</th>
                <th style="width: 12%;">SKU</th>
                <th>Produk</th>
                <th class="right" style="width: 7%;">Jumlah</th>
                <th style="width: 8%;">Satuan</th>
                <th class="right" style="width: 13%;">Harga</th>
                <th class="right" style="width: 9%;">Diskon (%)</th>
                <th class="right" style="width: 12%;">Diskon (Rp)</th>
                <th class="right" style="width: 14%;">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                @php
                    $discountRp = round((float) $item->price * (float) $item->quantity * (float) $item->discount_percent / 100, 2);
                @endphp
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $item->filmProduct?->sku ?? '' }}</td>
                    <td>{{ $item->name }}@if ($item->note)<br><span style="color: #777;">{{ $item->note }}</span>@endif</td>
                    <td class="right">{{ rtrim(rtrim(number_format($item->quantity, 2, ',', '.'), '0'), ',') }}</td>
                    <td>{{ $item->unit }}</td>
                    <td class="right">{{ $money($item->price) }}</td>
                    <td class="right">{{ $item->discount_percent > 0 ? rtrim(rtrim(number_format($item->discount_percent, 2, ',', '.'), '0'), ',') . '%' : '' }}</td>
                    <td class="right">{{ $discountRp > 0 ? $money($discountRp) : '' }}</td>
                    <td class="right">{{ $money($item->total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal {{ $invoice->items->count() }} Barang</td><td style="width: 38%;">{{ $money($invoice->subtotal) }}</td></tr>
        @if ($transactionDiscount > 0)
            <tr><td>Diskon Transaksi</td><td>{{ $money($transactionDiscount) }}</td></tr>
        @endif
        @if ($invoice->shipping_cost > 0)
            <tr><td>Biaya Pengiriman</td><td>{{ $money($invoice->shipping_cost) }}</td></tr>
        @endif
        @if ($invoice->other_cost > 0)
            <tr><td>Biaya Lainnya</td><td>{{ $money($invoice->other_cost) }}</td></tr>
        @endif
        <tr class="strong"><td style="padding-top: 8px;">Total Tagihan:</td><td style="padding-top: 8px;">{{ $money($invoice->total) }}</td></tr>
        <tr><td>Total Terbayar:</td><td>{{ $money($invoice->amount_paid) }}</td></tr>
        <tr class="strong"><td>Sisa Tagihan:</td><td>{{ $money($remaining) }}</td></tr>
    </table>

    @if ($invoice->notes)
        <div class="block">
            <div class="block-title">Keterangan :</div>
            <div class="block-body">{{ $invoice->notes }}</div>
        </div>
    @endif

    @if ($invoice->terms_conditions)
        <div class="block">
            <div class="block-title">Syarat dan Ketentuan :</div>
            <div class="block-body">{{ $invoice->terms_conditions }}</div>
        </div>
    @endif

    <div class="footer">
        <strong>{{ $company['name'] }}</strong>@if ($company['npwp']) &middot; NPWP {{ $company['npwp'] }}@endif @if ($company['klu']) &middot; KLU {{ $company['klu'] }}@endif<br>
        Dicetak {{ now()->translatedFormat('d F Y H:i') }} &mdash; dokumen ini bukan Faktur Pajak.
    </div>

</body>
</html>
