<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>E-Warranty Card - {{ $warranty->warranty_code }}</title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #111; margin: 0; padding: 0; background: #fff; }
        .wrapper { width: 100%; border: 12px solid #111; padding: 40px; box-sizing: border-box; }
        .header { border-bottom: 3px solid #111; padding-bottom: 20px; margin-bottom: 30px; }
        .brand { font-size: 30px; font-weight: bold; letter-spacing: 2px; float: left; }
        .title { float: right; font-size: 14px; color: #777; font-weight: bold; margin-top: 12px; letter-spacing: 1px; }
        .clear { clear: both; }
        .grid-table { width: 100%; margin-bottom: 40px; }
        .grid-table td { padding: 12px 0; vertical-align: top; }
        .label { color: #718096; font-weight: bold; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
        .value { font-size: 15px; font-weight: 600; color: #111; margin-top: 4px; }
        .status-badge { display: inline-block; padding: 4px 12px; color: #fff; font-size: 12px; font-weight: bold; border-radius: 4px; text-transform: uppercase; }
        .status-active { background-color: #2f855a; }
        .status-expired { background-color: #c53030; }
        .status-pending { background-color: #b7791f; }
        .footer { border-top: 1px solid #e2e8f0; padding-top: 20px; font-size: 11px; color: #a0aec0; text-align: center; line-height: 1.6; }
    </style>
</head>
<body>

<div class="wrapper">
    <div class="header">
        <div class="brand">GINNVA SHIELD</div>
        <div class="title">OFFICIAL E-WARRANTY CERTIFICATE</div>
        <div class="clear"></div>
    </div>

    <table class="grid-table" cellspacing="0" cellpadding="0">
        <tr>
            <td width="50%">
                <div class="label">Kode E-Warranty</div>
                <div class="value" style="color: #2b6cb0; font-size: 18px; font-weight: bold;">{{ $warranty->warranty_code }}</div>
            </td>
            <td width="50%">
                <div class="label">Nama Pemilik</div>
                <div class="value">{{ $warranty->customer_name }}</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="label">Produk Terpasang</div>
                <div class="value">{{ $warranty->product_series }}</div>
            </td>
            <td>
                <div class="label">Informasi Kendaraan</div>
                <div class="value">{{ $warranty->car_type }} (Plat: {{ $warranty->car_plate }})</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="label">Tanggal Pemasangan</div>
                <div class="value">{{ \Carbon\Carbon::parse($warranty->installation_date)->translatedFormat('d F Y') }}</div>
            </td>
            <td>
                <div class="label">Berlaku Hingga</div>
                <div class="value">{{ \Carbon\Carbon::parse($warranty->expiry_date)->translatedFormat('d F Y') }}</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="label">Dealer Pelaksana</div>
                <div class="value">{{ $warranty->dealer_name }}</div>
            </td>
            <td>
                <div class="label">Sisa Masa Berlaku</div>
                <div class="value">{{ $warranty->remaining_days }} Hari</div>
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <div class="label">Status Validasi Sistem</div>
                <div class="value" style="margin-top: 6px;">
                    @php
                        $statusClass = match($warranty->status) {
                            'expired' => 'status-expired',
                            'pending' => 'status-pending',
                            default => 'status-active',
                        };
                    @endphp
                    <span class="status-badge {{ $statusClass }}">{{ $warranty->status }}</span>
                </div>
            </td>
        </tr>
    </table>

    <div class="footer">
        Sertifikat digital ini diterbitkan secara sah oleh sistem manajemen garansi terpusat Ginnva Shield Indonesia.<br>
        Segala bentuk pemalsuan atau manipulasi data akan diverifikasi langsung melalui basis data server utama kami.
    </div>
</div>

</body>
</html>