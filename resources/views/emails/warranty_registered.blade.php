@extends('emails.layout')

@section('subject', 'Garansi Terdaftar — ' . $warrantyCode)

@section('content')
<p style="margin:0 0 8px;color:#666666;font-size:13px;text-transform:uppercase;letter-spacing:1px;font-weight:700;">
    Pendaftaran Garansi
</p>
<h1 style="margin:0 0 20px;color:#333333;font-size:22px;font-weight:800;line-height:1.3;">
    Garansi Anda sedang diverifikasi
</h1>

<p style="margin:0 0 24px;color:#666666;font-size:14px;line-height:1.6;">
    Halo <strong>{{ $customerName }}</strong>, pendaftaran garansi produk Ginnva Anda
    telah berhasil diterima dan sedang dalam proses verifikasi oleh tim admin.
</p>

{{-- Kode garansi --}}
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
        <td align="center" style="background:#f2f5fa;border-radius:12px;padding:20px;">
            <p style="margin:0 0 4px;color:#999999;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;">
                Nomor E-Warranty
            </p>
            <p style="margin:0;color:#ed1651;font-size:24px;font-weight:800;letter-spacing:2px;">
                {{ $warrantyCode }}
            </p>
        </td>
    </tr>
</table>

{{-- Detail garansi --}}
<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="margin-bottom:24px;border:1px solid #e6e6e6;border-radius:12px;overflow:hidden;">
    <tr style="background:#f2f5fa;">
        <td style="padding:12px 16px;color:#666666;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;"
            colspan="2">
            Detail Garansi
        </td>
    </tr>
    <tr>
        <td style="padding:12px 16px;color:#999999;font-size:13px;border-top:1px solid #e6e6e6;width:45%;">Produk</td>
        <td style="padding:12px 16px;color:#333333;font-size:13px;font-weight:600;border-top:1px solid #e6e6e6;">{{ $productSeries }}</td>
    </tr>
    <tr>
        <td style="padding:12px 16px;color:#999999;font-size:13px;border-top:1px solid #e6e6e6;">Kendaraan</td>
        <td style="padding:12px 16px;color:#333333;font-size:13px;font-weight:600;border-top:1px solid #e6e6e6;">{{ $carType }} ({{ $carPlate }})</td>
    </tr>
    <tr>
        <td style="padding:12px 16px;color:#999999;font-size:13px;border-top:1px solid #e6e6e6;">Dealer Pemasang</td>
        <td style="padding:12px 16px;color:#333333;font-size:13px;font-weight:600;border-top:1px solid #e6e6e6;">{{ $dealerName }}</td>
    </tr>
    <tr>
        <td style="padding:12px 16px;color:#999999;font-size:13px;border-top:1px solid #e6e6e6;">Tgl. Pemasangan</td>
        <td style="padding:12px 16px;color:#333333;font-size:13px;font-weight:600;border-top:1px solid #e6e6e6;">{{ $installationDate }}</td>
    </tr>
    <tr>
        <td style="padding:12px 16px;color:#999999;font-size:13px;border-top:1px solid #e6e6e6;">Berlaku Hingga</td>
        <td style="padding:12px 16px;color:#ed1651;font-size:13px;font-weight:700;border-top:1px solid #e6e6e6;">{{ $expiryDate }}</td>
    </tr>
</table>

{{-- Status pending --}}
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
        <td style="background:#fef3e2;border-radius:12px;padding:14px 16px;">
            <table cellpadding="0" cellspacing="0" border="0">
                <tr>
                    <td style="padding-right:10px;font-size:18px;vertical-align:top;">⏳</td>
                    <td style="color:#d97706;font-size:13px;font-weight:600;line-height:1.5;">
                        Garansi Anda sedang menunggu persetujuan admin.<br>
                        <span style="font-weight:400;color:#92400e;">
                            Sertifikat PDF akan tersedia untuk diunduh setelah diverifikasi.
                        </span>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<p style="margin:0;color:#999999;font-size:13px;line-height:1.6;">
    Simpan nomor E-Warranty di atas. Anda dapat mengecek status garansi kapan saja
    melalui aplikasi Ginnva Shield Indonesia.
</p>
@endsection