@extends('emails.layout')

@section('subject', 'Waktunya Servis Berkala')

@section('content')
<p style="margin:0 0 8px;color:#666666;font-size:13px;text-transform:uppercase;letter-spacing:1px;font-weight:700;">
    Reminder Servis
</p>
<h1 style="margin:0 0 20px;color:#333333;font-size:22px;font-weight:800;line-height:1.3;">
    Waktunya cek kondisi {{ $serviceType }} Anda
</h1>

<p style="margin:0 0 24px;color:#666666;font-size:14px;line-height:1.6;">
    Halo <strong>{{ $customerName }}</strong>, sudah waktunya melakukan servis/pengecekan berkala
    untuk booking <strong>{{ $bookingNumber }}</strong>@if($storeName) di {{ $storeName }} @endif.
    Kunjungi toko Ginnva terdekat untuk pengecekan supaya performa produk Anda tetap optimal.
</p>

<p style="margin:0;color:#999999;font-size:13px;line-height:1.6;">
    Ada pertanyaan? Hubungi toko tempat Anda melakukan instalasi, atau buka aplikasi Ginnva Shield Indonesia.
</p>
@endsection
