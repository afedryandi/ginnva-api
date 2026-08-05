@extends('emails.layout')

@section('subject', 'Quotation Baru — ' . $quotationNumber)

@section('content')
<p style="margin:0 0 8px;color:#666666;font-size:13px;text-transform:uppercase;letter-spacing:1px;font-weight:700;">
    Permintaan Penawaran Baru
</p>
<h1 style="margin:0 0 20px;color:#333333;font-size:22px;font-weight:800;line-height:1.3;">
    Ada lead baru masuk untuk {{ $storeName }}
</h1>

<p style="margin:0 0 24px;color:#666666;font-size:14px;line-height:1.6;">
    Segera hubungi customer berikut untuk membahas harga dan jadwal pemasangan.
</p>

{{-- Nomor referensi --}}
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
        <td style="background:#f2f5fa;border-radius:12px;padding:16px 20px;">
            <p style="margin:0 0 4px;color:#999999;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;">
                Nomor Quotation
            </p>
            <p style="margin:0;color:#ed1651;font-size:20px;font-weight:800;letter-spacing:1px;">
                {{ $quotationNumber }}
            </p>
        </td>
    </tr>
</table>

{{-- Detail quotation --}}
<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="margin-bottom:24px;border:1px solid #e6e6e6;border-radius:12px;overflow:hidden;">
    <tr style="background:#f2f5fa;">
        <td style="padding:12px 16px;color:#666666;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;"
            colspan="2">
            Detail Permintaan
        </td>
    </tr>
    <tr>
        <td style="padding:12px 16px;color:#999999;font-size:13px;border-top:1px solid #e6e6e6;width:40%;">Nama Customer</td>
        <td style="padding:12px 16px;color:#333333;font-size:13px;font-weight:600;border-top:1px solid #e6e6e6;">{{ $customerName }}</td>
    </tr>
    <tr>
        <td style="padding:12px 16px;color:#999999;font-size:13px;border-top:1px solid #e6e6e6;">No. WhatsApp</td>
        <td style="padding:12px 16px;color:#333333;font-size:13px;font-weight:600;border-top:1px solid #e6e6e6;">{{ $phoneNumber }}</td>
    </tr>
    <tr>
        <td style="padding:12px 16px;color:#999999;font-size:13px;border-top:1px solid #e6e6e6;">Email</td>
        <td style="padding:12px 16px;color:#333333;font-size:13px;font-weight:600;border-top:1px solid #e6e6e6;">{{ $email }}</td>
    </tr>
    <tr>
        <td style="padding:12px 16px;color:#999999;font-size:13px;border-top:1px solid #e6e6e6;">Kendaraan</td>
        <td style="padding:12px 16px;color:#333333;font-size:13px;font-weight:600;border-top:1px solid #e6e6e6;">
            {{ $vehicleLabel }}@if($licensePlate) &middot; {{ $licensePlate }}@endif
        </td>
    </tr>
    <tr>
        <td style="padding:12px 16px;color:#999999;font-size:13px;border-top:1px solid #e6e6e6;">Produk Diminati</td>
        <td style="padding:12px 16px;color:#333333;font-size:13px;font-weight:600;border-top:1px solid #e6e6e6;">{{ $products }}</td>
    </tr>
    @if($message)
    <tr>
        <td style="padding:12px 16px;color:#999999;font-size:13px;border-top:1px solid #e6e6e6;">Catatan</td>
        <td style="padding:12px 16px;color:#333333;font-size:13px;font-weight:600;border-top:1px solid #e6e6e6;">{{ $message }}</td>
    </tr>
    @endif
</table>

<p style="margin:0;color:#999999;font-size:13px;line-height:1.6;">
    Buka Filament admin panel untuk mengelola quotation ini lebih lanjut.
</p>
@endsection
