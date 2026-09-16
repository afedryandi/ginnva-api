<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>SPK {{ $spk->spk_number }}</title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #222; font-size: 11px; line-height: 1.4; }
        .header { width: 100%; margin-bottom: 14px; border-bottom: 2px solid #222; padding-bottom: 10px; }
        .brand { font-size: 20px; font-weight: bold; color: #ED1651; }
        .brand-sub { font-size: 9px; color: #666; margin-top: 2px; }
        .title { font-size: 15px; font-weight: bold; text-align: right; color: #111; text-transform: uppercase; }
        .title-sub { font-size: 10px; color: #666; font-weight: normal; text-transform: none; }
        .note-list { font-size: 9px; color: #555; margin-bottom: 10px; }
        .note-list td { padding: 1px 6px 1px 0; vertical-align: top; }
        table.grid { width: 100%; border-collapse: collapse; border: 1px solid #222; margin-bottom: 10px; }
        table.grid > tbody > tr > td { border: 1px solid #222; padding: 8px; vertical-align: top; }
        .section-title { background: #111; color: #fff; font-weight: bold; text-transform: uppercase; font-size: 10px; padding: 4px 8px; }
        .field-table td { padding: 2px 0; vertical-align: top; }
        .field-label { width: 34%; }
        .checklist-title { font-weight: bold; font-size: 10px; margin: 6px 0 3px; }
        .checklist-item { padding: 1px 0; }
        .box { color: #16a34a; font-weight: bold; }
        .box-empty { color: #999; }
        .sign-table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        .sign-table td { border: 1px solid #222; padding: 6px 8px; width: 25%; vertical-align: top; height: 90px; }
        .sign-label { font-weight: bold; font-size: 10px; }
        .footer-note { margin-top: 14px; font-size: 9px; color: #555; }
        .footer-note ol { margin: 4px 0 0 14px; padding: 0; }
    </style>
</head>
<body>

    <table class="header">
        <tr>
            <td style="width: 60%;">
                <div class="brand">GINNVA</div>
                <div class="brand-sub">
                    www.ginnva.co.id<br>
                    {{ $spk->store?->name ?? 'Ginnva Shield Indonesia' }}
                    @if ($spk->store?->address) &middot; {{ $spk->store->address }}@endif
                </div>
            </td>
            <td class="title" style="width: 40%;">
                Surat Perintah Kerja
                <div class="title-sub">Nomor: {{ $spk->spk_number }}</div>
            </td>
        </tr>
    </table>

    <table class="note-list">
        <tr>
            <td><strong>Catatan:</strong></td>
            <td>Lembar 1 : Untuk Admin</td>
            <td>Lembar 3 : Untuk PIC Cutting</td>
        </tr>
        <tr>
            <td></td>
            <td>Lembar 2 : Untuk Installer</td>
            <td>Lembar 4 : Untuk Pelanggan</td>
        </tr>
    </table>

    <table class="grid">
        <tr>
            <td style="width: 38%;">
                <div class="section-title">Data Pelanggan</div>
                <table class="field-table" style="width: 100%; margin-top: 6px;">
                    <tr><td class="field-label">Nama</td><td>: {{ $spk->customer_name }}</td></tr>
                    <tr><td class="field-label">Alamat</td><td>: {{ $spk->address }}</td></tr>
                    <tr><td class="field-label">Telepon</td><td>: {{ $spk->phone_number }}</td></tr>
                    <tr><td class="field-label">No. Polisi</td><td>: {{ $spk->vehicle_plate }}</td></tr>
                    <tr><td class="field-label">No. Rangka</td><td>: {{ $spk->vehicle_vin }}</td></tr>
                    <tr><td class="field-label">Merek / Tahun</td><td>: {{ $spk->vehicle_brand }} / {{ $spk->vehicle_year }}</td></tr>
                    <tr><td class="field-label">Jenis</td><td>: {{ \App\Models\Spk::VEHICLE_TYPE_LABELS[$spk->vehicle_type] ?? '-' }}</td></tr>
                    <tr><td class="field-label">Kilometer</td><td>: {{ $spk->vehicle_km ? number_format($spk->vehicle_km, 0, ',', '.') . ' Km' : '-' }}</td></tr>
                    <tr><td class="field-label">BBM / Battery</td><td>: {{ \App\Models\Spk::FUEL_LEVEL_LABELS[$spk->fuel_level] ?? '-' }} / {{ $spk->battery_note ?? '-' }}</td></tr>
                    <tr><td class="field-label">Masuk</td><td>: {{ $spk->checked_in_at?->translatedFormat('d M Y, H:i') ?? '-' }}</td></tr>
                    <tr><td class="field-label">Keluar</td><td>: {{ $spk->checked_out_at?->translatedFormat('d M Y, H:i') ?? '-' }}</td></tr>
                </table>
            </td>
            <td style="width: 62%;">
                <div class="section-title">Kondisi Kendaraan</div>

                {{-- Diagram visual -- titik kerusakan digambar LANGSUNG
                     ke bitmap-nya pakai GD (Spk::damageDiagramDataUri()),
                     BUKAN overlay CSS "position: absolute" seperti di
                     Filament/mobile -- DomPDF terbukti tidak bisa
                     diandalkan menempatkan elemen absolut di dalam sel
                     tabel dengan benar (titik meleset keluar border,
                     lihat screenshot user 2026-09-16). Kolom "Data
                     Pelanggan" dipersempit (50% -> 38%) supaya kolom
                     ini dapat jatah lebar lebih besar (62%), diagram
                     bisa diperbesar sampai dekat tepi bawah border
                     tanpa berebut ruang sama legenda kode di sampingnya. --}}
                @php
                    $diagramWidth = 250;
                    $diagramHeight = round($diagramWidth * 1400 / 1120);
                @endphp
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td style="width: 250px; vertical-align: top; padding: 0;">
                            <img
                                src="{{ $spk->damageDiagramDataUri() }}"
                                width="{{ $diagramWidth }}"
                                height="{{ $diagramHeight }}"
                                style="width: {{ $diagramWidth }}px; height: {{ $diagramHeight }}px; border: 1px solid #ccc;"
                            >
                            @if ($spk->damageMarks->isEmpty())
                                <p style="color: #888; font-size: 9px; margin-top: 6px;">
                                    (Belum ada titik kerusakan ditandai dari aplikasi)
                                </p>
                            @endif
                        </td>
                        <td style="vertical-align: top; padding: 0 0 0 10px; font-size: 9px; color: #333;">
                            <strong style="display: block; margin-bottom: 4px;">Kode Kerusakan</strong>
                            @foreach (\App\Models\Spk::DAMAGE_CODE_LABELS as $code => $label)
                                <div style="margin-bottom: 3px;">
                                    <strong>{{ $code }}</strong> = {{ $label }}
                                </div>
                            @endforeach
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="grid">
        <tr>
            <td style="width: 33.33%;">
                <div class="checklist-title">Uraian Pekerjaan</div>
                @foreach ($spk->checklistItems->where('category', 'pekerjaan') as $item)
                    <div class="checklist-item">
                        <span class="{{ $item->is_checked ? 'box' : 'box-empty' }}">{{ $item->is_checked ? '[x]' : '[ ]' }}</span>
                        {{ $item->label }}
                    </div>
                @endforeach
            </td>
            <td style="width: 33.33%;">
                <div class="checklist-title">Extra Services</div>
                @foreach ($spk->checklistItems->where('category', 'extra_service') as $item)
                    <div class="checklist-item">
                        <span class="{{ $item->is_checked ? 'box' : 'box-empty' }}">{{ $item->is_checked ? '[x]' : '[ ]' }}</span>
                        {{ $item->label }}
                    </div>
                @endforeach
            </td>
            <td style="width: 33.33%;">
                <div class="checklist-title">Perlengkapan Kendaraan</div>
                @foreach ($spk->checklistItems->where('category', 'perlengkapan') as $item)
                    <div class="checklist-item">
                        <span class="{{ $item->is_checked ? 'box' : 'box-empty' }}">{{ $item->is_checked ? '[x]' : '[ ]' }}</span>
                        {{ $item->label }}
                    </div>
                @endforeach
            </td>
        </tr>
    </table>

    @if ($spk->notes)
        <table class="grid">
            <tr>
                <td>
                    <div class="section-title">Catatan</div>
                    <p style="margin-top: 6px;">{{ $spk->notes }}</p>
                </td>
            </tr>
        </table>
    @endif

    <table class="sign-table">
        <tr>
            <td>
                <div class="sign-label">Dikerjakan Oleh :</div>
            </td>
            <td>
                <div class="sign-label">Diperiksa Oleh :</div>
            </td>
            <td>
                <div class="sign-label">Konfirmasi Pelanggan :</div>
            </td>
            <td>
                <div class="sign-label">Diterima dengan Baik :</div>
            </td>
        </tr>
    </table>

    <div class="footer-note">
        <strong>Perhatian:</strong>
        <ol>
            <li>Perintah kerja ini adalah pekerjaan yang telah disepakati antara <strong>Customer</strong> dan <strong>Ginnva</strong>.</li>
            <li>Perintah Kerja sebagai <i>check list</i> kondisi kendaraan, diketahui dan disetujui oleh Customer.</li>
            <li>Perintah kerja sebagai tanda terima saat customer menerima kendaraan yang telah dikerjakan. Apabila hilang, tunjukkan STNK Asli.</li>
            <li>Ginnva Indonesia tidak bertanggung jawab atas kehilangan barang berharga yang ditinggalkan di dalam kendaraan.</li>
        </ol>
    </div>

</body>
</html>
