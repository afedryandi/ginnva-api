<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>E-Warranty Card - {{ $warranty->warranty_code }}</title>
    {{--
        Sertifikat E-Warranty (didesain ulang 2026-11-24): pita merah di atas, logo Ginnva, kartu utama berisi produk + QR, pita kode
        garansi, kartu info berpasangan, dan bar sisa masa berlaku. dompdf tidak mendukung flex/grid/gradient/bayangan, jadi tata letak
        memakai tabel dan blok berlatar solid. Data yang ditampilkan sama seperti sebelumnya.
    --}}
    @php
        $wordmarkPath = public_path('images/ginnva-wordmark-merah.png');
        $wordmark = file_exists($wordmarkPath)
            ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($wordmarkPath))
            : null;

        $start = \Carbon\Carbon::parse($warranty->installation_date)->startOfDay();
        $end = \Carbon\Carbon::parse($warranty->expiry_date)->startOfDay();
        $totalDays = max(1, $start->diffInDays($end));
        $leftDays = max(0, min($totalDays, (int) now()->startOfDay()->diffInDays($end, false)));
        $leftPercent = (int) round($leftDays / $totalDays * 100);

        [$statusLabel, $statusBg] = match ($warranty->status) {
            'expired' => ['Kedaluwarsa', '#c53030'],
            'pending', 'pending_review' => ['Menunggu', '#b7791f'],
            default => ['Aktif', '#2f855a'],
        };

        $categoryLabel = match ($warranty->product_category) {
            'window_film' => 'Window Film',
            'ppf' => 'Paint Protection Film (PPF)',
            'color_change' => 'Color Change Film',
            default => '',
        };
    @endphp
    <style>
        @page { margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: 'Helvetica', Arial, sans-serif; color: #1f2430; background: #ffffff; }

        .topbar { height: 14px; background-color: #ed1651; }
        .page { padding: 26px 40px 30px 40px; }

        .label { font-size: 8px; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; color: #8a91a0; }
        .value { font-size: 13px; font-weight: bold; color: #1f2430; margin-top: 4px; }
        .sub { font-size: 10px; color: #8a91a0; margin-top: 3px; }

        .head { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: middle; }
        .doc-kicker { font-size: 8px; letter-spacing: 1.6px; font-weight: bold; color: #ed1651; text-align: right; }
        .doc-title { font-size: 15px; font-weight: bold; color: #1f2430; text-align: right; margin-top: 3px; }

        .hero { background-color: #f2f5fa; border-radius: 16px; padding: 22px 24px; margin-top: 22px; }
        .hero-table { width: 100%; border-collapse: collapse; }
        .hero-table td { vertical-align: top; }
        .badge { display: inline-block; padding: 4px 12px; border-radius: 12px; color: #ffffff; font-size: 9px; font-weight: bold; letter-spacing: 0.8px; text-transform: uppercase; }
        .product { font-size: 24px; font-weight: bold; color: #1f2430; line-height: 1.2; margin-top: 12px; }
        .category { font-size: 11px; color: #6b7280; margin-top: 4px; }
        .car { font-size: 12px; color: #1f2430; margin-top: 14px; }
        .plate { display: inline-block; border: 1.5px solid #1f2430; border-radius: 4px; padding: 1px 7px; margin-left: 6px; font-size: 11px; font-weight: bold; letter-spacing: 1px; background: #ffffff; }
        .qr { text-align: center; }
        .qr-frame { display: inline-block; background: #ffffff; border-radius: 12px; padding: 8px; }
        .qr img { width: 92px; height: 92px; }
        .qr-caption { font-size: 7px; letter-spacing: 0.8px; font-weight: bold; color: #8a91a0; margin-top: 6px; }

        .codebar { background-color: #ed1651; border-radius: 12px; padding: 14px 20px; margin-top: 14px; }
        .codebar table { width: 100%; border-collapse: collapse; }
        .codebar td { vertical-align: middle; }
        .code-label { font-size: 8px; font-weight: bold; letter-spacing: 1.2px; text-transform: uppercase; color: #ffd0dd; }
        .code { font-size: 22px; font-weight: bold; letter-spacing: 3px; color: #ffffff; text-align: right; }

        .grid { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .grid td { width: 50%; padding: 5px; vertical-align: top; }
        .cell { background-color: #f7f8fb; border-radius: 12px; padding: 13px 16px; }

        .section { font-size: 9px; font-weight: bold; letter-spacing: 1.2px; text-transform: uppercase; color: #1f2430; margin: 20px 0 6px 5px; }
        .section .tag { background-color: #fde3ea; color: #ed1651; font-size: 7px; padding: 2px 7px; border-radius: 8px; margin-left: 6px; letter-spacing: 0.8px; }

        .validity { background-color: #f7f8fb; border-radius: 12px; padding: 14px 16px; margin: 15px 5px 0 5px; }
        .validity table { width: 100%; border-collapse: collapse; }
        .validity td { vertical-align: bottom; }
        .bar { height: 8px; border-radius: 4px; background-color: #e3e7ef; margin-top: 10px; }
        .bar-fill { height: 8px; border-radius: 4px; background-color: #ed1651; }

        .footer { margin-top: 24px; padding-top: 14px; border-top: 1px solid #e3e7ef; text-align: center; font-size: 8px; line-height: 1.6; color: #8a91a0; }
        .footer strong { color: #1f2430; }
    </style>
</head>
<body>

<div class="topbar"></div>

<div class="page">

    <table class="head" cellspacing="0" cellpadding="0">
        <tr>
            <td width="55%">
                @if($wordmark)
                    <img src="{{ $wordmark }}" alt="Ginnva" style="height: 34px;">
                @else
                    <div style="font-size: 22px; font-weight: bold; color: #ed1651;">GINNVA</div>
                @endif
            </td>
            <td width="45%">
                <div class="doc-kicker">OFFICIAL E-WARRANTY</div>
                <div class="doc-title">Sertifikat Garansi</div>
            </td>
        </tr>
    </table>

    {{-- ============ KARTU UTAMA ============ --}}
    <div class="hero">
        <table class="hero-table" cellspacing="0" cellpadding="0">
            <tr>
                <td width="70%">
                    <span class="badge" style="background-color: {{ $statusBg }};">{{ $statusLabel }}</span>
                    <div class="product">{{ $warranty->product_series }}</div>
                    @if($categoryLabel)
                        <div class="category">{{ $categoryLabel }}</div>
                    @endif
                    <div class="car">
                        {{ $warranty->car_type }}
                        @if($warranty->car_plate)<span class="plate">{{ $warranty->car_plate }}</span>@endif
                    </div>
                    @if($warranty->vin)
                        <div class="sub" style="margin-top: 6px;">VIN: {{ $warranty->vin }}</div>
                    @endif
                </td>
                <td width="30%" class="qr">
                    {{-- QR code mengarah ke halaman cek garansi publik
                         (ginnva.id/warranty?code=...), supaya siapapun yang
                         scan pakai kamera HP biasa (tidak harus app Ginnva)
                         langsung melihat hasil verifikasi resmi. --}}
                    <div class="qr-frame"><img src="{{ $qrCodeDataUri }}" alt="QR Verifikasi"></div>
                    <div class="qr-caption">SCAN UNTUK VERIFIKASI</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="codebar">
        <table cellspacing="0" cellpadding="0">
            <tr>
                <td width="40%"><div class="code-label">Kode E-Warranty</div></td>
                <td width="60%"><div class="code">{{ $warranty->warranty_code }}</div></td>
            </tr>
        </table>
    </div>

    {{-- ============ INFO UTAMA ============ --}}
    <table class="grid" cellspacing="0" cellpadding="0">
        <tr>
            <td>
                <div class="cell">
                    <div class="label">Nama Pemilik</div>
                    <div class="value">{{ $warranty->customer_name }}</div>
                </div>
            </td>
            <td>
                <div class="cell">
                    <div class="label">Dealer Pelaksana</div>
                    <div class="value">{{ $warranty->dealer_name }}</div>
                </div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="cell">
                    <div class="label">Tanggal Pemasangan</div>
                    <div class="value">{{ $start->translatedFormat('d F Y') }}</div>
                </div>
            </td>
            <td>
                <div class="cell">
                    <div class="label">Berlaku Hingga</div>
                    <div class="value" style="color: #ed1651;">{{ $end->translatedFormat('d F Y') }}</div>
                </div>
            </td>
        </tr>
    </table>

    <div class="validity">
        <table cellspacing="0" cellpadding="0">
            <tr>
                <td width="60%"><div class="label">Sisa Masa Berlaku</div></td>
                <td width="40%" align="right"><div class="value" style="margin-top: 0;">{{ $warranty->remaining_days }} Hari</div></td>
            </tr>
        </table>
        <div class="bar"><div class="bar-fill" style="width: {{ $leftPercent }}%;"></div></div>
    </div>

    {{-- ============ SPESIFIKASI TEKNIS (PPF) ============
         Card terpisah, hanya muncul kalau ada minimal 1 data teknis
         terisi. Dipisah dari grid info utama supaya PDF tetap rapi
         walau field-nya bertambah banyak (VIN, posisi, roll number,
         dst) — dan supaya jelas beda "info umum" vs "data spesifik
         kategori produk". --}}
    @if($warranty->product_category === 'ppf' && ($warranty->installation_position || $warranty->roll_number || $warranty->roll_number_2))
        <div class="section">Spesifikasi Teknis <span class="tag">PPF</span></div>
        <table class="grid" cellspacing="0" cellpadding="0" style="margin-top: 0;">
            <tr>
                <td>
                    @if($warranty->installation_position)
                        <div class="cell">
                            <div class="label">Posisi Pemasangan</div>
                            <div class="value">{{ $warranty->installation_position === 'full_body' ? 'Seluruh Bodi' : 'Parsial' }}</div>
                            @if($warranty->installation_position === 'partial' && $warranty->installation_position_detail)
                                <div class="sub">{{ $warranty->installation_position_detail }}</div>
                            @endif
                        </div>
                    @endif
                </td>
                <td>
                    @if($warranty->roll_number)
                        <div class="cell">
                            <div class="label">Roll Number / ID Material</div>
                            <div class="value">{{ $warranty->roll_number }}</div>
                        </div>
                    @endif
                </td>
            </tr>
            @if($warranty->roll_number_2)
                <tr>
                    <td>
                        {{-- Mobil yang butuh 2 gulungan PPF (biasanya bodi besar) --}}
                        <div class="cell">
                            <div class="label">Roll Number / ID Material (Gulungan Kedua)</div>
                            <div class="value">{{ $warranty->roll_number_2 }}</div>
                        </div>
                    </td>
                    <td></td>
                </tr>
            @endif
        </table>
    @endif

    {{-- ============ SPESIFIKASI TEKNIS (Window Film) ============ --}}
    @if($warranty->product_category === 'window_film' && ($warranty->roll_number_front || $warranty->roll_number_side_rear))
        <div class="section">Spesifikasi Teknis <span class="tag">WINDOW FILM</span></div>
        <table class="grid" cellspacing="0" cellpadding="0" style="margin-top: 0;">
            <tr>
                <td>
                    @if($warranty->roll_number_front)
                        <div class="cell">
                            <div class="label">Roll Number &mdash; Kaca Depan</div>
                            <div class="value">{{ $warranty->roll_number_front }}</div>
                            @if($warranty->film_model_front)
                                <div class="sub">Tipe Film: {{ $warranty->film_model_front }}</div>
                            @endif
                        </div>
                    @endif
                </td>
                <td>
                    @if($warranty->roll_number_side_rear)
                        <div class="cell">
                            <div class="label">Roll Number &mdash; Samping &amp; Belakang</div>
                            <div class="value">{{ $warranty->roll_number_side_rear }}</div>
                            @if($warranty->film_model_side_rear)
                                <div class="sub">Tipe Film: {{ $warranty->film_model_side_rear }}</div>
                            @endif
                        </div>
                    @endif
                </td>
            </tr>
        </table>
    @endif

    <div class="footer">
        <strong>Ginnva House &middot; PT. Ginnva Shield Indonesia</strong><br>
        Sertifikat digital ini diterbitkan secara sah oleh sistem manajemen garansi terpusat Ginnva House.<br>
        Segala bentuk pemalsuan atau manipulasi data akan diverifikasi langsung melalui basis data server utama kami.
    </div>

</div>

</body>
</html>
