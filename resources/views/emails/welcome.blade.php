@extends('emails.layout')

@section('subject', 'Selamat datang di Ginnva Shield Indonesia!')

@section('content')
<p style="margin:0 0 8px;color:#666666;font-size:13px;text-transform:uppercase;letter-spacing:1px;font-weight:700;">
    Akun Berhasil Dibuat
</p>
<h1 style="margin:0 0 20px;color:#333333;font-size:22px;font-weight:800;line-height:1.3;">
    Selamat datang, {{ $name ?? 'Pelanggan Ginnva' }}!
</h1>

<p style="margin:0 0 16px;color:#666666;font-size:14px;line-height:1.6;">
    Akun Anda di Ginnva Shield Indonesia telah berhasil dibuat dengan email
    <strong>{{ $email }}</strong>.
</p>

<p style="margin:0 0 16px;color:#666666;font-size:14px;line-height:1.6;">
    Dengan akun ini Anda bisa:
</p>

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    @foreach([
        ['icon' => '🛡️', 'text' => 'Cek status garansi produk Ginnva Anda'],
        ['icon' => '📋', 'text' => 'Lihat riwayat garansi dan booking'],
        ['icon' => '📄', 'text' => 'Unduh sertifikat garansi digital (PDF)'],
        ['icon' => '🔔', 'text' => 'Terima notifikasi layanan after-sales'],
    ] as $item)
    <tr>
        <td style="padding:10px 0;border-bottom:1px solid #f2f5fa;">
            <table cellpadding="0" cellspacing="0" border="0">
                <tr>
                    <td style="padding-right:12px;font-size:18px;vertical-align:middle;">{{ $item['icon'] }}</td>
                    <td style="color:#333333;font-size:14px;vertical-align:middle;">{{ $item['text'] }}</td>
                </tr>
            </table>
        </td>
    </tr>
    @endforeach
</table>

<p style="margin:0;color:#999999;font-size:13px;line-height:1.6;">
    Pertanyaan? Hubungi kami lewat aplikasi Ginnva Shield Indonesia.
</p>
@endsection