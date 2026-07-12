@extends('emails.layout')

@section('subject', 'Permintaan Penawaran Diterima — ' . $quotationNumber)

@section('content')
<p style="margin:0 0 8px;color:#666666;font-size:13px;text-transform:uppercase;letter-spacing:1px;font-weight:700;">
    Konfirmasi Permintaan Penawaran
</p>
<h1 style="margin:0 0 20px;color:#333333;font-size:22px;font-weight:800;line-height:1.3;">
    Permintaan Anda telah kami terima
</h1>

<p style="margin:0 0 24px;color:#666666;font-size:14px;line-height:1.6;">
    Halo <strong>{{ $customerName }}</strong>, tim sales Ginnva Shield Indonesia akan segera
    menghubungi Anda melalui WhatsApp untuk mendiskusikan penawaran lebih lanjut.
</p>

{{-- Nomor referensi --}}
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
        <td style="background:#f2f5fa;border-radius:12px;padding:16px 20px;">
            <p style="margin:0 0 4px;color:#999999;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;">
                Nomor Referensi
            </p>
            <p style="margin:0;color:#ed1651;font-size:20px;font-weight:800;letter-spacing:1px;">
                {{ $quotationNumber }}
            </p>
        </td>
    </tr>
</table>

{{-- Detail ringkasan --}}
<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="margin-bottom:24px;border:1px solid #e6e6e6;border-radius:12px;overflow:hidden;">
    <tr style="background:#f2f5fa;">
        <td style="padding:12px 16px;color:#666666;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;"
            colspan="2">
            Detail Permintaan
        </td>
    </tr>
    <tr>
        <td style="padding:12px 16px;color:#999999;font-size:13px;border-top:1px solid #e6e6e6;width:40%;">Kendaraan</td>
        <td style="padding:12px 16px;color:#333333;font-size:13px;font-weight:600;border-top:1px solid #e6e6e6;">{{ $vehicle }}</td>
    </tr>
    @if($licensePlate)
    <tr>
        <td style="padding:12px 16px;color:#999999;font-size:13px;border-top:1px solid #e6e6e6;">Plat Nomor</td>
        <td style="padding:12px 16px;color:#333333;font-size:13px;font-weight:600;border-top:1px solid #e6e6e6;">{{ $licensePlate }}</td>
    </tr>
    @endif
    <tr>
        <td style="padding:12px 16px;color:#999999;font-size:13px;border-top:1px solid #e6e6e6;">Produk Diminati</td>
        <td style="padding:12px 16px;color:#333333;font-size:13px;font-weight:600;border-top:1px solid #e6e6e6;">
            {{ implode(', ', $products) }}
        </td>
    </tr>
    <tr>
        <td style="padding:12px 16px;color:#999999;font-size:13px;border-top:1px solid #e6e6e6;">No. WhatsApp</td>
        <td style="padding:12px 16px;color:#333333;font-size:13px;font-weight:600;border-top:1px solid #e6e6e6;">{{ $customerPhone }}</td>
    </tr>
</table>

<p style="margin:0;color:#999999;font-size:13px;line-height:1.6;">
    Simpan nomor referensi di atas jika perlu menghubungi kami. Tim kami akan membalas
    dalam waktu <strong>1×24 jam kerja</strong>.
</p>
@endsection