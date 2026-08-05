<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>QR Referral GIIAS - Ginnva</title>
    <style>
        {{-- Layout tabel (bukan flex/grid) — DomPDF paling stabil dengan
             tabel, sama seperti pola di warranty_card.blade.php. 2 kolom
             x 3 baris = 6 kartu per halaman A4 portrait, cukup besar
             untuk digunting/dipotong manual jadi kartu fisik. --}}
        @page { margin: 12mm 10mm; }
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #111; margin: 0; padding: 0; }
        * { box-sizing: border-box; }

        .page-title { font-size: 12px; font-weight: bold; letter-spacing: 0.5px; color: #555; margin-bottom: 10px; text-transform: uppercase; }

        .grid { width: 100%; border-collapse: collapse; }
        .grid td { width: 50%; padding: 6px; vertical-align: top; }

        .card { border: 1.5px dashed #ccc; border-radius: 8px; padding: 16px; text-align: center; }
        .card .brand { font-size: 10px; font-weight: bold; letter-spacing: 1px; color: #ed1651; }
        .card .qr { width: 130px; height: 130px; margin: 10px auto; display: block; }
        .card .name { font-size: 13px; font-weight: bold; color: #111; margin-top: 4px; word-wrap: break-word; }
        .card .meta { font-size: 9px; color: #888; margin-top: 2px; }
        .card .code { display: inline-block; margin-top: 8px; padding: 4px 10px; background: #fff0f4; color: #ed1651; font-weight: bold; font-size: 12px; letter-spacing: 1px; border-radius: 4px; }
        .card .link { font-size: 7.5px; color: #a0aec0; margin-top: 6px; word-break: break-all; }
        .card .caption { font-size: 8px; color: #a0aec0; margin-top: 6px; }
    </style>
</head>
<body>

<div class="page-title">GINNVA — QR Referral Sales GIIAS</div>

<table class="grid" cellspacing="0" cellpadding="0">
    @foreach ($items->chunk(2) as $row)
        <tr>
            @foreach ($row as $item)
                <td>
                    <div class="card">
                        <div class="brand">GINNVA SHIELD INDONESIA</div>
                        <img class="qr" src="{{ $item['qr'] }}" alt="QR {{ $item['code'] }}">
                        <div class="name">{{ $item['name'] }}</div>
                        @if($item['meta'])
                            <div class="meta">{{ $item['meta'] }}</div>
                        @endif
                        <div class="code">{{ $item['code'] }}</div>
                        <div class="link">{{ $item['link'] }}</div>
                        <div class="caption">SCAN UNTUK BUKA LANDING PAGE GIIAS</div>
                    </div>
                </td>
            @endforeach
            @if ($row->count() < 2)
                <td></td>
            @endif
        </tr>
    @endforeach
</table>

</body>
</html>
