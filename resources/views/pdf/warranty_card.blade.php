<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>E-Warranty Card - {{ $warranty->warranty_code }}</title>
    <style>
        @page { margin: 14mm 12mm; }
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #111; margin: 0; padding: 0; background: #fff; }
        * { box-sizing: border-box; }
        .wrapper { width: auto; margin: 0 2mm; border: 8px solid #111; padding: 22px 24px; }

        /* Header — brand kiri, QR kanan (dipindah ke sini supaya tidak
           berebut ruang dengan kolom "Nama Pemilik" di bawah, yang jadi
           penyebab utama konten terpotong di layout sebelumnya). */
        .header-table { width: 100%; border-bottom: 3px solid #111; padding-bottom: 14px; margin-bottom: 22px; }
        .header-table td { vertical-align: top; }
        .brand { font-size: 24px; font-weight: bold; letter-spacing: 1.5px; }
        .title { font-size: 11px; color: #777; font-weight: bold; letter-spacing: 0.5px; margin-top: 6px; }
        .qr-box { text-align: center; }
        .qr-box img { width: 62px; height: 62px; }
        .qr-box .qr-caption { font-size: 7px; color: #a0aec0; margin-top: 3px; letter-spacing: 0.3px; }

        /* Grid dasar (info utama) — 2 kolom proporsi 58/42, lebih aman
           untuk lebar A4 portrait daripada 50/50 dengan nested table. */
        .grid-table { width: 100%; margin-bottom: 20px; }
        .grid-table td { padding: 8px 0; vertical-align: top; }
        .label { color: #718096; font-weight: bold; text-transform: uppercase; font-size: 9px; letter-spacing: 0.5px; }
        .value { font-size: 13px; font-weight: 600; color: #111; margin-top: 3px; word-wrap: break-word; }
        .sub-value { font-size: 10px; color: #a0aec0; margin-top: 2px; font-weight: normal; }

        /* Section divider */
        .section-divider { border-top: 1px solid #e2e8f0; margin: 16px 0 14px 0; }
        .section-heading { font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.6px; color: #2d3748; margin-bottom: 10px; }
        .section-heading .tag { display: inline-block; background-color: #edf2f7; color: #4a5568; font-size: 8px; font-weight: bold; padding: 2px 7px; border-radius: 3px; letter-spacing: 0.4px; margin-left: 6px; text-transform: uppercase; vertical-align: middle; }

        /* Tech spec card */
        .tech-card { background-color: #f9fafb; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px 16px; margin-bottom: 18px; }
        .tech-table { width: 100%; }
        .tech-table td { padding: 6px 0; vertical-align: top; width: 50%; }

        /* Status */
        .status-badge { display: inline-block; padding: 3px 10px; color: #fff; font-size: 10px; font-weight: bold; border-radius: 4px; text-transform: uppercase; }
        .status-active { background-color: #2f855a; }
        .status-expired { background-color: #c53030; }
        .status-pending { background-color: #b7791f; }

        /* Footer */
        .footer { border-top: 1px solid #e2e8f0; padding-top: 14px; font-size: 9px; color: #a0aec0; text-align: center; line-height: 1.5; }
    </style>
</head>
<body>

<div class="wrapper">
    <table class="header-table" cellspacing="0" cellpadding="0">
        <tr>
            <td width="70%">
                <div class="brand">GINNVA SHIELD</div>
                <div class="title">OFFICIAL E-WARRANTY CERTIFICATE</div>
            </td>
            <td width="30%" align="right">
                {{-- QR code mengarah ke halaman cek garansi publik
                     (ginnva.id/warranty?code=...), supaya siapapun yang
                     scan pakai kamera HP biasa (tidak harus app Ginnva)
                     langsung melihat hasil verifikasi resmi. --}}
                <div class="qr-box">
                    <img src="{{ $qrCodeDataUri }}" alt="QR Verifikasi">
                    <div class="qr-caption">SCAN UNTUK VERIFIKASI</div>
                </div>
            </td>
        </tr>
    </table>

    {{-- ============ INFO UTAMA ============ --}}
    <table class="grid-table" cellspacing="0" cellpadding="0">
        <tr>
            <td width="55%">
                <div class="label">Kode E-Warranty</div>
                <div class="value" style="color: #2b6cb0; font-size: 16px; font-weight: bold;">{{ $warranty->warranty_code }}</div>
            </td>
            <td width="45%">
                <div class="label">Nama Pemilik</div>
                <div class="value">{{ $warranty->customer_name }}</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="label">Produk Terpasang</div>
                <div class="value">{{ $warranty->product_series }}</div>
                @if($warranty->product_category)
                <div class="sub-value">
                    {{ match($warranty->product_category) {
                        'window_film' => 'Window Film',
                        'ppf' => 'Paint Protection Film (PPF)',
                        'color_change' => 'Color Change Film',
                        default => '',
                    } }}
                </div>
                @endif
            </td>
            <td>
                <div class="label">Informasi Kendaraan</div>
                <div class="value">{{ $warranty->car_type }}</div>
                <div class="sub-value">Plat: {{ $warranty->car_plate }}@if($warranty->vin)<br>VIN: {{ $warranty->vin }}@endif</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="label">Tanggal Pemasangan</div>
                <div class="value">{{ \Carbon\Carbon::parse($warranty->installation_date)->translatedFormat('d F Y') }}</div>
            </td>
            <td>
                <div class="label">Berlaku Hingga</div>
                <div class="value">{{ \Carbon\Carbon::parse($warranty->expiry_date)->translatedFormat('d F Y') }}</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="label">Dealer Pelaksana</div>
                <div class="value">{{ $warranty->dealer_name }}</div>
            </td>
            <td>
                <div class="label">Sisa Masa Berlaku</div>
                <div class="value">{{ $warranty->remaining_days }} Hari</div>
            </td>
        </tr>
    </table>

    {{-- ============ SPESIFIKASI TEKNIS (PPF) ============
         Card terpisah, hanya muncul kalau ada minimal 1 data teknis
         terisi. Dipisah dari grid info utama supaya PDF tetap rapi
         walau field-nya bertambah banyak (VIN, posisi, roll number,
         dst) — dan supaya jelas beda "info umum" vs "data spesifik
         kategori produk". --}}
    @if($warranty->product_category === 'ppf' && ($warranty->installation_position || $warranty->roll_number))
    <div class="section-heading">Spesifikasi Teknis <span class="tag">PPF</span></div>
    <div class="tech-card">
        <table class="tech-table" cellspacing="0" cellpadding="0">
            <tr>
                <td>
                    @if($warranty->installation_position)
                    <div class="label">Posisi Pemasangan</div>
                    <div class="value">
                        {{ $warranty->installation_position === 'full_body' ? 'Seluruh Bodi' : 'Parsial' }}
                    </div>
                    @if($warranty->installation_position === 'partial' && $warranty->installation_position_detail)
                    <div class="sub-value">{{ $warranty->installation_position_detail }}</div>
                    @endif
                    @endif
                </td>
                <td>
                    @if($warranty->roll_number)
                    <div class="label">Roll Number / ID Material</div>
                    <div class="value">{{ $warranty->roll_number }}</div>
                    @endif
                </td>
            </tr>
        </table>
    </div>
    @endif

    {{-- ============ SPESIFIKASI TEKNIS (Window Film) ============ --}}
    @if($warranty->product_category === 'window_film' && ($warranty->roll_number_front || $warranty->roll_number_side_rear))
    <div class="section-heading">Spesifikasi Teknis <span class="tag">Window Film</span></div>
    <div class="tech-card">
        <table class="tech-table" cellspacing="0" cellpadding="0">
            <tr>
                <td>
                    @if($warranty->roll_number_front)
                    <div class="label">Roll Number &mdash; Kaca Depan</div>
                    <div class="value">{{ $warranty->roll_number_front }}</div>
                    @if($warranty->film_model_front)
                    <div class="sub-value">Tipe Film: {{ $warranty->film_model_front }}</div>
                    @endif
                    @endif
                </td>
                <td>
                    @if($warranty->roll_number_side_rear)
                    <div class="label">Roll Number &mdash; Samping &amp; Belakang</div>
                    <div class="value">{{ $warranty->roll_number_side_rear }}</div>
                    @if($warranty->film_model_side_rear)
                    <div class="sub-value">Tipe Film: {{ $warranty->film_model_side_rear }}</div>
                    @endif
                    @endif
                </td>
            </tr>
        </table>
    </div>
    @endif

    {{-- ============ STATUS ============ --}}
    <div class="section-divider"></div>
    <table class="grid-table" cellspacing="0" cellpadding="0" style="margin-bottom: 0;">
        <tr>
            <td>
                <div class="label">Status Validasi Sistem</div>
                <div class="value" style="margin-top: 6px;">
                    @php
                        $statusClass = match($warranty->status) {
                            'expired' => 'status-expired',
                            'pending' => 'status-pending',
                            default => 'status-active',
                        };
                    @endphp
                    <span class="status-badge {{ $statusClass }}">{{ $warranty->status }}</span>
                </div>
            </td>
        </tr>
    </table>

    <div class="footer" style="margin-top: 20px;">
        Sertifikat digital ini diterbitkan secara sah oleh sistem manajemen garansi terpusat Ginnva Shield Indonesia.<br>
        Segala bentuk pemalsuan atau manipulasi data akan diverifikasi langsung melalui basis data server utama kami.
    </div>
</div>

</body>
</html>