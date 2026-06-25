<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>E-Warranty Card - {{ $warranty->code }}</title>
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
        .status-badge { display: inline-block; padding: 4px 12px; background-color: #2f855a; color: #fff; font-size: 12px; font-weight: bold; border-radius: 4px; text-transform: uppercase; }
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
                <div class="value" style="color: #2b6cb0; font-size: 18px; font-weight: bold;">{{ $warranty->code }}</div>
            </td>
            <td width="50%">
                <div class="label">Nama Pemilik (Owner)</div>
                <div class="value">{{ $warranty->owner }}</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="label">ID Produk Terpasang</div>
                <div class="value">{{ $warranty->product_id }}</div>
            </td>
            <td>
                <div class="label">Informasi Kendaraan</div>
                <div class="value">
                    {{ $carInfo['brand_type'] ?? ($carInfo['type'] ?? '-') }} 
                    @if(isset($carInfo['plate'])) (Plat: {{ $carInfo['plate'] }}) @endif
                </div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="label">Tanggal Pemasangan</div>
                <div class="value">{{ \Carbon\Carbon::parse($warranty->install_date)->translatedFormat('d F Y') }}</div>
            </td>
            <td>
                <div class="label">Authorized Dealer ID</div>
                <div class="value">{{ $warranty->store_id }}</div>
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <div class="label">Status Validasi Sistem</div>
                <div class="value" style="margin-top: 6px;">
                    <span class="status-badge">{{ $warranty->status }}</span>
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