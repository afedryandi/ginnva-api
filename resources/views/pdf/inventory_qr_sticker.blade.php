<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Sticker QR Inventaris - Ginnva</title>
    @php
        // Lembar sticker A4 polos (tanpa potongan): QR 25 mm, tiap sel 30 x 35,5 mm dengan garis potong tipis, 6 kolom x 8 baris = 48 per lembar.
        // 6 x 30 = 180 mm dan 8 x 35,5 = 284 mm muat di A4 dengan margin 15 mm kiri-kanan dan 5,5 mm atas-bawah. Ubah angka di bawah bila perlu.
        $cols = 6;
        $rowsPerPage = 8;
        $cellW = 30;        // lebar sel (mm)
        $cellH = 35.5;      // tinggi sel (mm)
        $qr = 25;           // sisi QR (mm)
        $marginX = 15;      // margin kiri-kanan (mm)
        $marginY = 5.5;     // margin atas-bawah (mm)
        $perPage = $cols * $rowsPerPage;
    @endphp
    <style>
        @page { margin: {{ $marginY }}mm {{ $marginX }}mm; }
        body { font-family: Helvetica, Arial, sans-serif; color: #000; margin: 0; padding: 0; }
        * { box-sizing: border-box; }

        .sheet { page-break-after: always; }
        .sheet.last { page-break-after: auto; }
        table.grid { border-collapse: collapse; border-spacing: 0; }
        table.grid td { width: {{ $cellW }}mm; height: {{ $cellH }}mm; padding: 0; vertical-align: top; }
        /* Garis potong tipis (abu-abu putus-putus); hapus baris border bila lembar sudah berpotongan. */
        .label { width: {{ $cellW }}mm; height: {{ $cellH }}mm; text-align: center; overflow: hidden; border: 0.3pt dashed #b5b5b5; }

        .qr { width: {{ $qr }}mm; height: {{ $qr }}mm; display: block; margin: 0.5mm auto 0 auto; }
        .batch { font-size: 6pt; font-weight: bold; line-height: 1.1; margin-top: 0.4mm; letter-spacing: 0.2pt; }
        .name { font-size: 6pt; line-height: 1.1; margin-top: 0.2mm; padding: 0 1mm; }
        .roll { font-size: 5pt; line-height: 1.1; margin-top: 0.2mm; color: #333; padding: 0 0.5mm; }
    </style>
</head>
<body>

@foreach ($items->chunk($perPage) as $page)
    <div class="sheet {{ $loop->last ? 'last' : '' }}">
        <table class="grid" cellspacing="0" cellpadding="0">
            @foreach ($page->chunk($cols) as $row)
                <tr>
                    @foreach ($row as $item)
                        <td>
                            <div class="label">
                                {{-- Kode barang (INV-...) tidak dicetak, tetapi tetap isi QR supaya bisa dipindai aplikasi. --}}
                                <img class="qr" src="{{ $item['qr'] }}" alt="QR">
                                @if (!empty($item['batch']))
                                    <div class="batch">{{ $item['batch'] }}</div>
                                @endif
                                @if (!empty($item['name']))
                                    <div class="name">{{ \Illuminate\Support\Str::limit($item['name'], 26) }}</div>
                                @endif
                                @if (!empty($item['scroll_code']))
                                    <div class="roll">{{ $item['scroll_code'] }}</div>
                                @endif
                            </div>
                        </td>
                    @endforeach
                    @for ($i = $row->count(); $i < $cols; $i++)
                        <td></td>
                    @endfor
                </tr>
            @endforeach
        </table>
    </div>
@endforeach

</body>
</html>
