@extends('emails.layout')

@section('subject', 'Booking Baru — ' . $bookingNumber)

@section('content')
<p style="margin:0 0 8px;color:#666666;font-size:13px;text-transform:uppercase;letter-spacing:1px;font-weight:700;">
    Booking Instalasi Baru
</p>
<h1 style="margin:0 0 20px;color:#333333;font-size:22px;font-weight:800;line-height:1.3;">
    Ada booking baru masuk untuk {{ $storeName }}
</h1>

<p style="margin:0 0 24px;color:#666666;font-size:14px;line-height:1.6;">
    Segera cek detail booking berikut dan hubungi customer untuk konfirmasi jadwal.
</p>

{{-- Nomor referensi --}}
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
        <td style="background:#f2f5fa;border-radius:12px;padding:16px 20px;">
            <p style="margin:0 0 4px;color:#999999;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;">
                Nomor Booking
            </p>
            <p style="margin:0;color:#ed1651;font-size:20px;font-weight:800;letter-spacing:1px;">
                {{ $bookingNumber }}
            </p>
        </td>
    </tr>
</table>

{{-- Detail booking --}}
<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="margin-bottom:24px;border:1px solid #e6e6e6;border-radius:12px;overflow:hidden;">
    <tr style="background:#f2f5fa;">
        <td style="padding:12px 16px;color:#666666;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;"
            colspan="2">
            Detail Booking
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
        <td style="padding:12px 16px;color:#999999;font-size:13px;border-top:1px solid #e6e6e6;">Jenis Layanan</td>
        <td style="padding:12px 16px;color:#333333;font-size:13px;font-weight:600;border-top:1px solid #e6e6e6;">{{ $serviceType }}</td>
    </tr>
    <tr>
        <td style="padding:12px 16px;color:#999999;font-size:13px;border-top:1px solid #e6e6e6;">Tanggal Diinginkan</td>
        <td style="padding:12px 16px;color:#333333;font-size:13px;font-weight:600;border-top:1px solid #e6e6e6;">
            {{ $preferredDate }}@if($preferredTime) &middot; {{ $preferredTime }}@endif
        </td>
    </tr>
    <tr>
        <td style="padding:12px 16px;color:#999999;font-size:13px;border-top:1px solid #e6e6e6;">Sumber Booking</td>
        <td style="padding:12px 16px;color:#333333;font-size:13px;font-weight:600;border-top:1px solid #e6e6e6;">{{ $source }}</td>
    </tr>
    @if($notes)
    <tr>
        <td style="padding:12px 16px;color:#999999;font-size:13px;border-top:1px solid #e6e6e6;">Catatan</td>
        <td style="padding:12px 16px;color:#333333;font-size:13px;font-weight:600;border-top:1px solid #e6e6e6;">{{ $notes }}</td>
    </tr>
    @endif
</table>

<p style="margin:0;color:#999999;font-size:13px;line-height:1.6;">
    Buka Filament admin panel untuk mengelola booking ini lebih lanjut.
</p>
@endsection
