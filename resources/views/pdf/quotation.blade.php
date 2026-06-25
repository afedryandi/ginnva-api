<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Quotation {{ $quotation_number }}</title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #333; font-size: 13px; line-height: 1.4; }
        .header { width: 100%; margin-bottom: 30px; border-bottom: 2px solid #222; padding-bottom: 15px; }
        .logo { width: 140px; }
        .title { font-size: 24px; font-weight: bold; text-align: right; color: #111; text-transform: uppercase; }
        .meta-table, .items-table { width: 100%; border-collapse: collapse; margin-bottom: 25px; }
        .meta-table td { padding: 4px 0; vertical-align: top; }
        .items-table th { background-color: #111; color: #fff; text-align: left; padding: 10px; font-weight: bold; text-transform: uppercase; font-size: 12px; }
        .items-table td { padding: 12px 10px; border-bottom: 1px solid #eee; }
        .text-right { text-align: right; }
        .total-box { background-color: #f9f9f9; padding: 15px; border: 1px solid #e4e4e4; font-size: 16px; font-weight: bold; text-align: right; margin-top: 20px; }
        .footer { margin-top: 50px; width: 100%; }
        .qr-section { text-align: right; float: right; }
        .notes-section { float: left; width: 60%; font-size: 11px; color: #666; }
    </style>
</head>
<body>

    <table class="header">
        <tr>
            <td>
                <img src="{{ $logo_url }}" class="logo" alt="Ginnva">
            </td>
            <td class="title">
                Official Quotation
            </td>
        </tr>
    </table>

    <table class="meta-table">
        <tr>
            <td style="width: 15%;"><strong>No. Penawaran</strong></td>
            <td style="width: 35%;">: {{ $quotation_number }}</td>
            <td style="width: 15%;"><strong>Nama Pelanggan</strong></td>
            <td style="width: 35%;">: {{ $customer_name }}</td>
        </tr>
        <tr>
            <td><strong>Tanggal</strong></td>
            <td>: {{ $date }}</td>
            <td><strong>No. Telepon</strong></td>
            <td>: {{ $customer_phone }}</td>
        </tr>
        <tr>
            <td><strong>Kendaraan</strong></td>
            <td>: {{ $vehicle_model }}</td>
            <td><strong>No. Polisi</strong></td>
            <td>: {{ $license_plate }}</td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th>Deskripsi Produk Ginnva</th>
                <th>Bagian Mobil</th>
                <th class="text-right">Harga Dasar</th>
                <th class="text-right">Koefisien</th>
                <th class="text-right">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
            <tr>
                <td><strong>{{ $item['product_name'] }}</strong></td>
                <td>{{ $item['car_part'] }}</td>
                <td class="text-right">Rp {{ number_format($item['base_price'], 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($item['coefficient'], 2) }}x</td>
                <td class="text-right"><strong>Rp {{ number_format($item['calculated_price'], 0, ',', '.') }}</strong></td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="total-box">
        TOTAL ESTIMASI: Rp {{ number_format($total_price, 0, ',', '.') }}
    </div>

    <div class="footer">
        <div class="notes-section">
            <strong>Ketentuan Penawaran:</strong>
            <ul>
                <li>Harga di atas merupakan estimasi awal berdasarkan data tipe ukuran kendaraan standar.</li>
                <li>Quotation resmi ini berlaku selama 14 hari sejak tanggal diterbitkan.</li>
                <li>Pemasangan dilakukan oleh teknisi bersertifikat resmi Ginnva Indonesia.</li>
            </ul>
        </div>
        <div class="qr-section">
            <img src="{{ $qr_code }}" alt="QR Code Verification" style="border: 1px solid #ddd; padding: 3px;"><br>
            <span style="font-size: 10px; color:#888;">Pindai untuk Verifikasi</span>
        </div>
    </div>

</body>
</html>