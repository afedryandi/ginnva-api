@extends('emails.layout')

@section('subject', 'Anda Ditugaskan Memantau Booking — ' . $bookingNumber)

@section('content')
<p style="margin:0 0 8px;color:#666666;font-size:13px;text-transform:uppercase;letter-spacing:1px;font-weight:700;">
    Penunjukan Pemantau Booking
</p>
<h1 style="margin:0 0 20px;color:#333333;font-size:22px;font-weight:800;line-height:1.3;">
    Anda ditugaskan memantau booking di {{ $storeName }}
</h1>

<p style="margin:0 0 24px;color:#666666;font-size:14px;line-height:1.6;">
    {{ $assignedByName }} menunjuk Anda untuk memantau chat & progress booking berikut. Anda akan menerima notifikasi setiap ada pesan atau update baru pada booking ini.
</p>

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

<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="margin-bottom:24px;border:1px solid #e6e6e6;border-radius:12px;overflow:hidden;">
    <tr>
        <td style="padding:12px 16px;color:#999999;font-size:13px;width:40%;">Nama Customer</td>
        <td style="padding:12px 16px;color:#333333;font-size:13px;font-weight:600;">{{ $customerName }}</td>
    </tr>
    <tr>
        <td style="padding:12px 16px;color:#999999;font-size:13px;border-top:1px solid #e6e6e6;">Jenis Layanan</td>
        <td style="padding:12px 16px;color:#333333;font-size:13px;font-weight:600;border-top:1px solid #e6e6e6;">{{ $serviceType }}</td>
    </tr>
    <tr>
        <td style="padding:12px 16px;color:#999999;font-size:13px;border-top:1px solid #e6e6e6;">Toko</td>
        <td style="padding:12px 16px;color:#333333;font-size:13px;font-weight:600;border-top:1px solid #e6e6e6;">{{ $storeName }}</td>
    </tr>
</table>

<p style="margin:0;color:#999999;font-size:13px;line-height:1.6;">
    Buka Filament admin panel atau aplikasi mobile untuk melihat detail booking ini.
</p>
@endsection
