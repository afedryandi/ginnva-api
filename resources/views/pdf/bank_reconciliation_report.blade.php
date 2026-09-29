<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .period { color: #6b7280; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        th { background: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 9px; }
        td.value, th.value { text-align: right; }
        .summary td { border: 0; padding: 3px 6px; }
        .summary tr:first-child td { font-weight: bold; }
    </style>
</head>
<body>
    @php
        $rupiah = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');
    @endphp

    <h1>Bukti Rekonsiliasi Bank</h1>
    <div class="period">
        {{ $result['account']->display_name }}<br>
        Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}
    </div>

    <table>
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>Keterangan</th>
                <th class="value">Nominal</th>
                <th>Status</th>
                <th>No. Jurnal</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($result['lines'] as $line)
                <tr>
                    <td>{{ $line['date']->format('d M Y') }}</td>
                    <td>{{ $line['description'] }}</td>
                    <td class="value">{{ $rupiah($line['amount']) }}</td>
                    <td>{{ $line['status_label'] }}</td>
                    <td>{{ $line['journal_entry_number'] ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5">Tidak ada mutasi di rentang tanggal ini.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="summary">
        <tr><td colspan="2">Ringkasan</td></tr>
        <tr><td>Saldo sistem per {{ $result['as_of']->format('d M Y') }}</td><td>{{ $rupiah($result['system_balance']) }}</td></tr>
        <tr><td>Jumlah mutasi cocok</td><td>{{ $result['matched_count'] }}</td></tr>
        <tr><td>Jumlah mutasi belum cocok</td><td>{{ $result['unmatched_count'] }}</td></tr>
        <tr><td>Total nilai belum cocok</td><td>{{ $rupiah($result['unmatched_total']) }}</td></tr>
        <tr><td>Jumlah mutasi perlu ditinjau ulang (jurnal dibalik)</td><td>{{ $result['stale_count'] }}</td></tr>
    </table>
    @include('pdf.partials.report-footer')
</body>
</html>
