<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $label }} {{ $title }}</title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #333; font-size: 12px; line-height: 1.45; }
        .header { width: 100%; margin-bottom: 18px; border-bottom: 2px solid #222; padding-bottom: 12px; }
        .logo { width: 130px; }
        .title { text-align: right; font-size: 17px; font-weight: bold; color: #111; }
        .title-sub { font-size: 11px; color: #666; font-weight: normal; }
        h2 { font-size: 12px; text-transform: uppercase; letter-spacing: .5px; background: #111; color: #fff; padding: 5px 9px; margin: 16px 0 0; }
        table.rows { width: 100%; border-collapse: collapse; }
        table.rows td { padding: 6px 9px; border-bottom: 1px solid #eee; vertical-align: top; }
        table.rows td.k { width: 32%; color: #666; font-weight: bold; }
        table.sub { width: 100%; border-collapse: collapse; margin: 4px 0; }
        table.sub th { text-align: left; background: #f1f1f1; padding: 4px 6px; font-size: 10px; }
        table.sub td { padding: 4px 6px; border-bottom: 1px solid #eee; font-size: 11px; }
        .footer { margin-top: 28px; border-top: 1px solid #e4e4e4; padding-top: 7px; font-size: 10px; color: #888; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                @if ($logo)
                    <img src="{{ $logo }}" class="logo" alt="Ginnva">
                @else
                    <strong style="font-size: 20px;">GINNVA</strong>
                @endif
            </td>
            <td class="title">
                {{ $label }}
                <div class="title-sub">{{ $title }}</div>
            </td>
        </tr>
    </table>

    @foreach ($sections as $section)
        <h2>{{ $section['heading'] }}</h2>
        <table class="rows">
            @foreach ($section['rows'] as $row)
                @if (! empty($row['table']))
                    <tr>
                        <td class="k">{{ $row['label'] }}</td>
                        <td>
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
                        <td>{{ $row['value'] }}</td>
                    </tr>
                @endif
            @endforeach
        </table>
    @endforeach

    <div class="footer">
        Dicetak {{ $printedAt }}@if ($printedBy) oleh {{ $printedBy }}@endif &middot; Ginnva Shield Indonesia
    </div>
</body>
</html>
