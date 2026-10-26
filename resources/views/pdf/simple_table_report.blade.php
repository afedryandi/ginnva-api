<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .period { color: #6b7280; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        th { background: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 9px; }
        td.value, th.value { text-align: right; }
        tfoot td { font-weight: bold; border-top: 2px solid #9ca3af; border-bottom: none; }
        .note { margin-top: 10px; font-size: 9px; color: #6b7280; }
    </style>
</head>
<body>
    {{--
        Tampilan umum untuk laporan tabel sederhana. Data dari ExportsReportTable::reportExportData():
        title, subtitle, headings, rows (sel berupa teks yang sudah diformat), totals (opsional), right (indeks kolom rata kanan), note (opsional).
    --}}
    <h1>{{ $data['title'] }}</h1>
    @if (! empty($data['subtitle']))
        <div class="period">{{ $data['subtitle'] }}</div>
    @endif

    @php($right = $data['right'] ?? [])
    <table>
        <thead>
            <tr>
                @foreach ($data['headings'] as $i => $heading)
                    <th @class(['value' => in_array($i, $right, true)])>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($data['pdf_rows'] as $row)
                <tr>
                    @foreach ($row as $i => $cell)
                        <td @class(['value' => in_array($i, $right, true)])>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($data['headings']) }}">Tidak ada data.</td></tr>
            @endforelse
        </tbody>
        @if (! empty($data['pdf_totals']))
            <tfoot>
                <tr>
                    @foreach ($data['pdf_totals'] as $i => $cell)
                        <td @class(['value' => in_array($i, $right, true)])>{{ $cell }}</td>
                    @endforeach
                </tr>
            </tfoot>
        @endif
    </table>

    @if (! empty($data['note']))
        <div class="note">{{ $data['note'] }}</div>
    @endif
    @include('pdf.partials.report-footer')
</body>
</html>
