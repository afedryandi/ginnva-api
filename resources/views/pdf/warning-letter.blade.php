<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Surat Peringatan {{ $letter->warning_number }}</title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #222; font-size: 13px; line-height: 1.55; }
        .header { width: 100%; border-bottom: 2px solid #222; padding-bottom: 12px; margin-bottom: 22px; }
        .brand { font-size: 20px; font-weight: bold; color: #111; }
        .doc-type { font-size: 11px; text-align: right; color: #555; text-transform: uppercase; letter-spacing: 1px; }
        .title { text-align: center; margin: 6px 0 2px; font-size: 16px; font-weight: bold; text-transform: uppercase; text-decoration: underline; }
        .number { text-align: center; margin-bottom: 22px; color: #444; }
        .cancelled { border: 2px solid #b91c1c; color: #b91c1c; padding: 8px 12px; margin-bottom: 18px; font-weight: bold; }
        .meta { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .meta td { padding: 3px 0; vertical-align: top; }
        .meta td.label { width: 28%; font-weight: bold; }
        .section-title { font-weight: bold; margin: 14px 0 4px; }
        .reason { border: 1px solid #ccc; padding: 10px 12px; background: #fafafa; white-space: pre-wrap; }
        ul.history { margin: 4px 0 0 18px; padding: 0; }
        .signatures { width: 100%; margin-top: 46px; }
        .signatures td { width: 50%; text-align: center; vertical-align: top; }
        .sign-space { height: 70px; }
        .sign-line { border-top: 1px solid #333; display: inline-block; min-width: 190px; padding-top: 4px; }
        .footer { margin-top: 36px; font-size: 10px; color: #888; }
    </style>
</head>
<body>

    <table class="header">
        <tr>
            <td><div class="brand">GINNVA</div></td>
            <td class="doc-type">Dokumen Kepegawaian<br>Rahasia</td>
        </tr>
    </table>

    @if ($letter->isCancelled())
        <div class="cancelled">
            SURAT INI DIBATALKAN pada {{ $letter->cancelled_at->translatedFormat('d F Y') }}.<br>
            Alasan: {{ $letter->cancel_reason }}
        </div>
    @endif

    <div class="title">Surat Peringatan {{ $levelNumber }}</div>
    <div class="number">Nomor: {{ $letter->warning_number }}</div>

    <p>Dengan surat ini perusahaan memberikan {{ $levelWords }} kepada:</p>

    <table class="meta">
        <tr><td class="label">Nama</td><td>: {{ $letter->user?->name }}</td></tr>
        @if ($letter->user?->employee_number)
            <tr><td class="label">No. Karyawan</td><td>: {{ $letter->user->employee_number }}</td></tr>
        @endif
        @if ($roleLabel)
            <tr><td class="label">Jabatan</td><td>: {{ $roleLabel }}</td></tr>
        @endif
        <tr><td class="label">Toko / Penempatan</td><td>: {{ $letter->store?->name ?? '—' }}</td></tr>
        <tr><td class="label">Tanggal Diterbitkan</td><td>: {{ $letter->issued_date->translatedFormat('d F Y') }}</td></tr>
        <tr><td class="label">Berlaku Sampai</td><td>: {{ $letter->valid_until?->translatedFormat('d F Y') ?? 'Tidak ada batas waktu' }}</td></tr>
    </table>

    <div class="section-title">Pelanggaran yang menjadi dasar surat ini</div>
    <div class="reason">{{ $letter->reason }}</div>

    @if ($previous->isNotEmpty())
        <div class="section-title">Surat peringatan sebelumnya</div>
        <ul class="history">
            @foreach ($previous as $earlier)
                <li>{{ strtoupper(str_replace('sp', 'SP ', $earlier->level)) }}, nomor {{ $earlier->warning_number }}, diterbitkan {{ $earlier->issued_date->translatedFormat('d F Y') }}</li>
            @endforeach
        </ul>
    @endif

    <p style="margin-top: 16px;">
        Karyawan yang bersangkutan diminta memperbaiki sikap dan kinerjanya. Apabila dalam masa berlaku surat ini kembali terjadi pelanggaran,
        perusahaan dapat menerbitkan surat peringatan berikutnya atau mengambil tindakan lain sesuai Peraturan Perusahaan dan peraturan
        perundang-undangan yang berlaku.
    </p>

    <table class="signatures">
        <tr>
            <td>
                Diterbitkan oleh,
                <div class="sign-space"></div>
                <span class="sign-line">{{ $letter->issuer?->name ?? '………………………' }}</span>
            </td>
            <td>
                Diterima oleh karyawan,
                <div class="sign-space"></div>
                <span class="sign-line">{{ $letter->user?->name }}</span>
                @if ($letter->acknowledged_at)
                    <div style="font-size: 10px; color: #555; margin-top: 4px;">Dibaca di aplikasi {{ $letter->acknowledged_at->translatedFormat('d F Y H:i') }}</div>
                @endif
            </td>
        </tr>
    </table>

    <div class="footer">Dicetak {{ now()->translatedFormat('d F Y H:i') }}. Dokumen ini dicetak dari sistem Ginnva; keabsahan mengikuti tanda tangan di atas.</div>

</body>
</html>
