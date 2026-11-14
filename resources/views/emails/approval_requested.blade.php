@extends('emails.layout')

@section('subject', 'Persetujuan menunggu — ' . $typeLabel)

@section('content')
<p style="margin:0 0 8px;color:#666666;font-size:13px;text-transform:uppercase;letter-spacing:1px;font-weight:700;">
    Pengajuan Menunggu Keputusan
</p>
<h1 style="margin:0 0 20px;color:#333333;font-size:22px;font-weight:800;line-height:1.3;">
    {{ $typeLabel }} dari {{ $who }}
</h1>

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
        <td style="background:#f2f5fa;border-radius:12px;padding:16px 20px;">
            <p style="margin:0 0 6px;color:#333333;font-size:15px;line-height:1.5;">{{ $summary }}</p>
            @if ($storeName)
                <p style="margin:0;color:#999999;font-size:13px;">{{ $storeName }}</p>
            @endif
        </td>
    </tr>
</table>

<p style="margin:0 0 24px;color:#666666;font-size:14px;line-height:1.6;">
    Buka Pusat Persetujuan untuk menyetujui atau menolak. Saat ini ada <strong>{{ $pendingCount }}</strong> pengajuan yang menunggu keputusan.
</p>

<table cellpadding="0" cellspacing="0" border="0" style="margin-bottom:8px;">
    <tr>
        <td style="background:#ed1651;border-radius:999px;">
            <a href="{{ $url }}" style="display:inline-block;padding:14px 28px;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;">
                Buka Pusat Persetujuan
            </a>
        </td>
    </tr>
</table>
@endsection
