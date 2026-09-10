<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $booking->booking_number }}</title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #333; font-size: 13px; line-height: 1.45; }
        .header { width: 100%; margin-bottom: 22px; border-bottom: 2px solid #222; padding-bottom: 14px; }
        .brand { font-size: 20px; font-weight: bold; color: #111; }
        .brand-sub { font-size: 10px; color: #666; margin-top: 2px; }
        .title { font-size: 18px; font-weight: bold; text-align: right; color: #111; text-transform: uppercase; }
        .title-sub { font-size: 11px; color: #666; font-weight: normal; text-transform: none; }
        .paid-badge { display: inline-block; background: #dcfce7; color: #166534; padding: 3px 10px; border-radius: 4px; font-size: 11px; font-weight: bold; margin-top: 4px; }
        .due-badge { display: inline-block; background: #fef3c7; color: #92400e; padding: 3px 10px; border-radius: 4px; font-size: 11px; font-weight: bold; margin-top: 4px; }
        .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .meta-table td { padding: 3px 0; vertical-align: top; }
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        .items-table th { background-color: #111; color: #fff; text-align: left; padding: 8px 10px; font-weight: bold; text-transform: uppercase; font-size: 11px; }
        .items-table td { padding: 10px; border-bottom: 1px solid #eee; vertical-align: top; }
        .item-note { font-size: 11px; color: #666; margin-top: 3px; }
        .text-right { text-align: right; }
        .negative { color: #b91c1c; }
        .totals { width: 55%; float: right; border-collapse: collapse; margin-top: 6px; }
        .totals td { padding: 5px 10px; }
        .totals .grand { border-top: 2px solid #222; font-size: 15px; font-weight: bold; }
        .totals .rowline td { border-top: 1px solid #e4e4e4; }
        .footer { clear: both; margin-top: 60px; width: 100%; font-size: 10px; color: #888; border-top: 1px solid #e4e4e4; padding-top: 8px; }
        .signature-box { width: 42%; float: right; text-align: center; margin-top: 40px; }
        .signature-line { border-top: 1px solid #333; margin-top: 55px; padding-top: 4px; }
    </style>
</head>
<body>

    <table class="header">
        <tr>
            <td>
                <div class="brand">GINNVA</div>
                <div class="brand-sub">
                    {{ $booking->store?->name ?? 'Ginnva Shield Indonesia' }}<br>
                    @if ($booking->store?->address){{ $booking->store->address }}<br>@endif
                    @if ($booking->store?->city){{ $booking->store->city }}@endif
                    @if ($booking->store?->phone) &middot; {{ $booking->store->phone }}@endif
                </div>
            </td>
            <td class="title">
                Invoice
                <div class="title-sub">No. {{ $invoiceNumber }}</div>
                @if ($outstanding <= 0)
                    <br><span class="paid-badge">LUNAS</span>
                @else
                    <br><span class="due-badge">SISA Rp {{ number_format($outstanding, 0, ',', '.') }}</span>
                @endif
            </td>
        </tr>
    </table>

    <table class="meta-table">
        <tr>
            <td style="width: 16%;"><strong>Ditagihkan kepada</strong></td>
            <td style="width: 40%;">: {{ $customerName }}</td>
            <td style="width: 16%;"><strong>No. Booking</strong></td>
            <td style="width: 28%;">: {{ $booking->booking_number }}</td>
        </tr>
        <tr>
            <td><strong>Telepon</strong></td>
            <td>: {{ $customerPhone ?: '—' }}</td>
            <td><strong>Tanggal Order</strong></td>
            <td>: {{ $booking->created_at?->translatedFormat('d F Y') ?? '—' }}</td>
        </tr>
        <tr>
            <td><strong>Alamat</strong></td>
            <td>: {{ $booking->customer?->address ?: '—' }}</td>
            <td><strong>Tanggal Layanan</strong></td>
            <td>: {{ $booking->preferred_date?->translatedFormat('d F Y') ?? '—' }}</td>
        </tr>
        <tr>
            <td><strong>Kendaraan</strong></td>
            <td>: {{ $booking->vehicle_info ?: '—' }}</td>
            <td><strong>Tanggal Cetak</strong></td>
            <td>: {{ now()->translatedFormat('d F Y') }}</td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th>Deskripsi Layanan</th>
                <th class="text-right" style="width: 30%;">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    Pemasangan {{ $booking->service_type }}
                    <div class="item-note">
                        @if ($booking->filmProduct)
                            Varian produk: {{ $booking->filmProduct->sku }} — {{ $booking->filmProduct->name }}<br>
                        @endif
                        @if ($booking->product_detailing)
                            Termasuk Jasa Detailing<br>
                        @endif
                        Kendaraan: {{ $booking->vehicle_info ?: '—' }}
                    </div>
                </td>
                <td class="text-right">Rp {{ number_format($gross, 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>Subtotal</td>
            <td class="text-right">Rp {{ number_format($gross, 0, ',', '.') }}</td>
        </tr>
        @if ($discount > 0)
        <tr>
            <td>Potongan Promo{{ $promoName ? ' (' . $promoName . ')' : '' }}</td>
            <td class="text-right negative">- Rp {{ number_format($discount, 0, ',', '.') }}</td>
        </tr>
        @endif
        <tr class="grand">
            <td>TOTAL</td>
            <td class="text-right">Rp {{ number_format($net, 0, ',', '.') }}</td>
        </tr>
        <tr class="rowline">
            <td>Dibayar</td>
            <td class="text-right">Rp {{ number_format($received, 0, ',', '.') }}</td>
        </tr>
        @if ($outstanding > 0)
        <tr>
            <td><strong>Sisa Tagihan (Piutang)</strong></td>
            <td class="text-right negative"><strong>Rp {{ number_format($outstanding, 0, ',', '.') }}</strong></td>
        </tr>
        @endif
    </table>

    <div class="signature-box">
        <div>Ginnva Shield Indonesia</div>
        <div class="signature-line">Hormat Kami</div>
    </div>

    <div class="footer">
        Dokumen ini digenerate otomatis oleh sistem Ginnva pada {{ now()->translatedFormat('d F Y H:i') }}.
        Ini adalah invoice/bukti transaksi internal — <strong>bukan Faktur Pajak</strong>.
    </div>

</body>
</html>
