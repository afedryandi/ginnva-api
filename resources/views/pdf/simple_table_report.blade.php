<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        h2 { font-size: 13px; margin: 16px 0 6px; }
        .period { color: #6b7280; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        th { background: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 9px; }
        td.value, th.value { text-align: right; }
        tfoot td { font-weight: bold; border-top: 2px solid #9ca3af; border-bottom: none; }
        .note { margin-top: 10px; font-size: 9px; color: #6b7280; }
        .compact th, .compact td { padding: 3px 2px; font-size: 7px; }
        .compact th { font-size: 6.5px; }
    </style>
</head>
<body>
    {{--
        Tampilan umum untuk laporan tabel sederhana. Data dari ExportsReportTable::reportExportData():
        title, subtitle, note (opsional) dan pdf_sections: tiap bagian punya title (opsional), headings, pdf_rows (sel yang sudah diformat), pdf_totals (opsional), right (indeks kolom rata kanan).
    --}}
    <h1>{{ $data['title'] }}</h1>
    @if (! empty($data['subtitle']))
        <div class="period">{{ $data['subtitle'] }}</div>
    @endif

    <div @class(['compact' => $data['compact'] ?? false])>
    @foreach ($data['pdf_sections'] as $section)
        @php($right = $section['right'])
        @if (! empty($section['title']))
            <h2>{{ $section['title'] }}</h2>
        @endif
        <table>
            <thead>
                <tr>
                    @foreach ($section['headings'] as $i => $heading)
                        <th @class(['value' => in_array($i, $right, true)])>{{ $heading }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($section['pdf_rows'] as $row)
                    <tr>
                        @foreach ($row as $i => $cell)
                            <td @class(['value' => in_array($i, $right, true)])>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($section['headings']) }}">Tidak ada data.</td></tr>
                @endforelse
            </tbody>
            @if (! empty($section['pdf_totals']))
                <tfoot>
                    <tr>
                        @foreach ($section['pdf_totals'] as $i => $cell)
                            <td @class(['value' => in_array($i, $right, true)])>{{ $cell }}</td>
                        @endforeach
                    </tr>
                </tfoot>
            @endif
        </table>
    @endforeach
    </div>

    @if (! empty($data['note']))
        <div class="note">{{ $data['note'] }}</div>
    @endif
    @include('pdf.partials.report-footer')
</body>
</html>
