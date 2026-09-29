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
        tfoot td { font-weight: bold; border-top: 2px solid #9ca3af; }
    </style>
</head>
<body>
    @php
        $rupiah = fn ($n) => (float) $n > 0 ? 'Rp' . number_format((float) $n, 0, ',', '.') : '-';
    @endphp

    <h1>Umur Piutang (Aging)</h1>
    <div class="period">Toko: {{ $storeLabel }} · Per {{ now()->format('d M Y') }}</div>

    <table>
        <thead>
            <tr>
                <th>Customer</th>
                <th class="value">Belum Jatuh Tempo</th>
                <th class="value">1-30 Hari</th>
                <th class="value">31-60 Hari</th>
                <th class="value">61-90 Hari</th>
                <th class="value">&gt; 90 Hari</th>
                <th class="value">Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($aging['rows'] as $row)
                <tr>
                    <td>{{ $row->customer }}</td>
                    <td class="value">{{ $rupiah($row->current_amt) }}</td>
                    <td class="value">{{ $rupiah($row->b1) }}</td>
                    <td class="value">{{ $rupiah($row->b2) }}</td>
                    <td class="value">{{ $rupiah($row->b3) }}</td>
                    <td class="value">{{ $rupiah($row->b4) }}</td>
                    <td class="value">{{ $rupiah($row->total) }}</td>
                </tr>
            @empty
                <tr><td colspan="7">Tidak ada piutang yang belum lunas.</td></tr>
            @endforelse
        </tbody>
        @if ($aging['rows']->isNotEmpty())
            <tfoot>
                <tr>
                    <td>Total</td>
                    <td class="value">{{ $rupiah($aging['totals']['current_amt']) }}</td>
                    <td class="value">{{ $rupiah($aging['totals']['b1']) }}</td>
                    <td class="value">{{ $rupiah($aging['totals']['b2']) }}</td>
                    <td class="value">{{ $rupiah($aging['totals']['b3']) }}</td>
                    <td class="value">{{ $rupiah($aging['totals']['b4']) }}</td>
                    <td class="value">{{ $rupiah($aging['totals']['total']) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
    @include('pdf.partials.report-footer')
</body>
</html>
