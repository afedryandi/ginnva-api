@extends('emails.layout')

@section('subject', 'Kode Verifikasi Ginnva Shield Indonesia')

@section('content')
<p style="margin:0 0 8px;color:#666666;font-size:13px;text-transform:uppercase;letter-spacing:1px;font-weight:700;">
    Verifikasi Login
</p>
<h1 style="margin:0 0 20px;color:#333333;font-size:22px;font-weight:800;line-height:1.3;">
    Masukkan kode verifikasi Anda
</h1>

<p style="margin:0 0 24px;color:#666666;font-size:14px;line-height:1.6;">
    Gunakan kode berikut untuk masuk ke akun Ginnva Shield Indonesia Anda.
    Kode ini berlaku selama <strong>10 menit</strong>.
</p>

<table width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr>
        <td align="center" style="padding:24px 0;">
            <div style="display:inline-block;background:#f2f5fa;border-radius:12px;padding:20px 40px;border:2px dashed #e6e6e6;">
                <span style="font-size:40px;font-weight:800;letter-spacing:12px;color:#ed1651;">
                    {{ $code }}
                </span>
            </div>
        </td>
    </tr>
</table>

<p style="margin:0;color:#999999;font-size:13px;line-height:1.6;text-align:center;">
    Jangan bagikan kode ini kepada siapapun, termasuk tim Ginnva.
</p>
@endsection