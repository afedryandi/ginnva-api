<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $label }} {{ $title }}</title>
    {{-- Gaya mengikuti cetakan SPK (pdf.spk): kop dengan logo dan alamat, judul rata kanan, kotak bergaris dengan bilah judul hitam. --}}
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #222; font-size: 11px; line-height: 1.4; }
        .header { width: 100%; margin-bottom: 14px; border-bottom: 2px solid #222; padding-bottom: 10px; }
        .brand { font-size: 20px; font-weight: bold; color: #ED1651; }
        .brand img { height: 24px; vertical-align: middle; }
        .brand-sub { font-size: 9px; color: #666; margin-top: 2px; }
        .title { font-size: 15px; font-weight: bold; text-align: right; color: #111; text-transform: uppercase; }
        .title-sub { font-size: 10px; color: #666; font-weight: normal; text-transform: none; }
        table.grid { width: 100%; border-collapse: collapse; border: 1px solid #222; margin-bottom: 10px; }
        table.grid > tbody > tr > td { border: 1px solid #222; padding: 8px; vertical-align: top; }
        .section-title { background: #111; color: #fff; font-weight: bold; text-transform: uppercase; font-size: 10px; padding: 4px 8px; }
        table.fields { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.fields td { padding: 2px 0; vertical-align: top; }
        table.fields td.k { width: 30%; padding-right: 6px; }
        table.sub { width: 100%; border-collapse: collapse; margin: 3px 0 5px; }
        table.sub th { text-align: left; background: #f1f1f1; border: 1px solid #bbb; padding: 3px 5px; font-size: 9px; text-transform: uppercase; }
        table.sub td { border: 1px solid #ddd; padding: 3px 5px; font-size: 10px; }
        .footer-note { margin-top: 14px; font-size: 9px; color: #555; }
    </style>
</head>
<body>

    <table class="header">
        <tr>
            <td style="width: 60%;">
                <div class="brand">
                    @if ($logo)
                        <img src="{{ $logo }}" alt="Ginnva">
                    @else
                        GINNVA
                    @endif
                </div>
                <div class="brand-sub">
                    Thamrin Business Center, Jl. M.H Thamrin Blok 1 No. 52, PIK 2, Kosambi, Selembaran, Tangerang, Banten 15210<br>
                    www.ginnva.id
                </div>
            </td>
            <td class="title" style="width: 40%;">
                {{ $label }}
                <div class="title-sub">{{ $title }}</div>
            </td>
        </tr>
    </table>

    @foreach ($sections as $section)
        <table class="grid">
            <tr>
                <td>
                    <div class="section-title">{{ $section['heading'] }}</div>
                    <table class="fields">
                        @foreach ($section['rows'] as $row)
                            @if (! empty($row['table']))
                                <tr>
                                    <td colspan="2" style="padding-top: 4px;"><strong>{{ $row['label'] }}</strong></td>
                                </tr>
                                <tr>
                                    <td colspan="2">
                                        <table class="sub">
                                            <tr>
                                                @foreach ($row['table']['columns'] as $column)
                                                    <th>{{ $column }}</th>
                                                @endforeach
                                            </tr>
                                            @foreach ($row['table']['rows'] as $line)
                                                <tr>
                                                    @foreach ($row['table']['columns'] as $column)
                                                        <td>{{ $line[$column] ?? '' }}</td>
                                                    @endforeach
                                                </tr>
                                            @endforeach
                                        </table>
                                    </td>
                                </tr>
                            @else
                                <tr>
                                    <td class="k">{{ $row['label'] }}</td>
                                    <td>: {{ $row['value'] !== '' && $row['value'] !== null ? $row['value'] : '-' }}</td>
                                </tr>
                            @endif
                        @endforeach
                    </table>
                </td>
            </tr>
        </table>
    @endforeach

    <div class="footer-note">
        Dicetak {{ $printedAt }}@if ($printedBy) oleh {{ $printedBy }}@endif &middot; Ginnva Shield Indonesia
    </div>
</body>
</html>
