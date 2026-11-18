<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Booking {{ $booking->booking_number }}</title>
    {{-- Gaya mengikuti cetakan SPK (pdf.spk): kop dengan logo dan alamat, judul rata kanan, kotak bergaris dengan bilah judul hitam. --}}
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #222; font-size: 11px; line-height: 1.4; }
        .header { width: 100%; margin-bottom: 14px; border-bottom: 2px solid #222; padding-bottom: 10px; }
        .brand { font-size: 20px; font-weight: bold; color: #ED1651; }
        .brand img { height: 24px; vertical-align: middle; }
        .brand-sub { font-size: 9px; color: #666; margin-top: 2px; }
        .title { font-size: 15px; font-weight: bold; text-align: right; color: #111; text-transform: uppercase; }
        .title-sub { font-size: 10px; color: #666; font-weight: normal; text-transform: none; }
        table.grid { width: 100%; border-collapse: collapse; border: 1px solid #222; margin-bottom: 10px; }
        table.grid > tbody > tr > td { border: 1px solid #222; padding: 8px; vertical-align: top; }
        .section-title { background: #111; color: #fff; font-weight: bold; text-transform: uppercase; font-size: 10px; padding: 4px 8px; }
        .field-table td { padding: 2px 0; vertical-align: top; }
        .field-label { width: 34%; }
        table.sub { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.sub th { text-align: left; background: #f1f1f1; border: 1px solid #bbb; padding: 3px 5px; font-size: 9px; text-transform: uppercase; }
        table.sub td { border: 1px solid #ddd; padding: 3px 5px; font-size: 10px; }
        .notes { margin-top: 6px; white-space: pre-line; }
        .footer-note { margin-top: 14px; font-size: 9px; color: #555; }
    </style>
</head>
<body>

    <table class="header">
        <tr>
            <td style="width: 60%;">
                <div class="brand">
                    @if ($logo)
                        <img src="{{ $logo }}" alt="Ginnva">
                    @else
                        GINNVA
                    @endif
                </div>
                <div class="brand-sub">
                    Thamrin Business Center, Jl. M.H Thamrin Blok 1 No. 52, PIK 2, Kosambi, Selembaran, Tangerang, Banten 15210<br>
                    www.ginnva.id
                </div>
            </td>
            <td class="title" style="width: 40%;">
                Booking
                <div class="title-sub">Nomor: {{ $booking->booking_number }}</div>
            </td>
        </tr>
    </table>

    <table class="grid">
        <tr>
            <td style="width: 45%;">
                <div class="section-title">Data Pelanggan</div>
                <table class="field-table" style="width: 100%; margin-top: 6px;">
                    <tr><td class="field-label">Nama</td><td>: {{ $customerName }}</td></tr>
                    <tr><td class="field-label">Telepon</td><td>: {{ $customerPhone }}</td></tr>
                    <tr><td class="field-label">Kendaraan</td><td>: {{ $vehicle }}</td></tr>
                    <tr><td class="field-label">No. Polisi</td><td>: {{ $booking->vehicle_plate ?: '-' }}</td></tr>
                    <tr><td class="field-label">Warna</td><td>: {{ $booking->vehicle_color ?: '-' }}</td></tr>
                    <tr><td class="field-label">Ukuran</td><td>: {{ $booking->vehicle_size ?: '-' }}</td></tr>
                </table>
            </td>
            <td style="width: 55%;">
                <div class="section-title">Jadwal &amp; Layanan</div>
                <table class="field-table" style="width: 100%; margin-top: 6px;">
                    <tr><td class="field-label">Status</td><td>: {{ $statusLabel }}</td></tr>
                    <tr><td class="field-label">Layanan</td><td>: {{ $services }}</td></tr>
                    <tr><td class="field-label">Tanggal</td><td>: {{ $dateLine }}</td></tr>
                    <tr><td class="field-label">Jam</td><td>: {{ $booking->preferred_time ?: '-' }}</td></tr>
                    <tr><td class="field-label">Lama Pengerjaan</td><td>: {{ $durationLine }}</td></tr>
                    <tr><td class="field-label">Toko</td><td>: {{ $booking->store?->name ?? '-' }}</td></tr>
                    <tr><td class="field-label">Installer</td><td>: {{ $installerNames !== '' ? $installerNames : '-' }}</td></tr>
                    @if ($stageLine !== '')
                        <tr><td class="field-label">Tahap Saat Ini</td><td>: {{ $stageLine }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    @if (count($products) > 0)
        <table class="grid">
            <tr>
                <td>
                    <div class="section-title">Produk yang Dipasang</div>
                    <table class="sub">
                        <tr><th>Produk</th><th style="width: 30%;">Bagian</th></tr>
                        @foreach ($products as $product)
                            <tr><td>{{ $product['name'] }}</td><td>{{ $product['position'] }}</td></tr>
                        @endforeach
                    </table>
                </td>
            </tr>
        </table>
    @endif

    @if (filled($booking->notes) || ($booking->status === 'cancelled' && filled($booking->cancel_reason)))
        <table class="grid">
            <tr>
                <td>
                    <div class="section-title">Catatan</div>
                    @if (filled($booking->notes))
                        <div class="notes">{{ $booking->notes }}</div>
                    @endif
                    @if ($booking->status === 'cancelled' && filled($booking->cancel_reason))
                        <div class="notes"><strong>Alasan pembatalan:</strong> {{ $booking->cancel_reason }}</div>
                    @endif
                </td>
            </tr>
        </table>
    @endif

    <div class="footer-note">
        Dicetak {{ $printedAt }}@if ($printedBy) oleh {{ $printedBy }}@endif &middot; Ginnva Shield Indonesia
    </div>
</body>
</html>
