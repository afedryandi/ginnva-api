<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        h2 { font-size: 11px; text-transform: uppercase; margin: 16px 0 6px; color: #6b7280; }
        .period { color: #6b7280; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        td, th { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        td.value, th.value { text-align: right; }
        th { background: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 9px; }
        .summary { margin-bottom: 16px; }
        .summary td { border: none; padding: 2px 6px; }
    </style>
</head>
<body>
    @php
        $sentimentLabel = fn (string $s) => match ($s) {
            'positive' => 'Positif',
            'negative' => 'Negatif',
            default => 'Netral',
        };
    @endphp

    <h1>Kepuasan Pelanggan</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>

    <table class="summary">
        <tr>
            <td>Total Review: <strong>{{ number_format($result['total'], 0, ',', '.') }}</strong></td>
            <td>Positif: <strong>{{ number_format($result['positive'], 0, ',', '.') }}</strong> ({{ number_format($result['positiveRate'], 1, ',', '.') }}%)</td>
        </tr>
        <tr>
            <td>Netral: <strong>{{ number_format($result['neutral'], 0, ',', '.') }}</strong></td>
            <td>Negatif: <strong>{{ number_format($result['negative'], 0, ',', '.') }}</strong></td>
        </tr>
    </table>

    <h2>Per Toko</h2>
    <table>
        <tr><th>Toko</th><th class="value">Total Review</th><th class="value">Positif</th><th class="value">Negatif</th></tr>
        @forelse ($result['byStore'] as $storeName => $row)
            <tr>
                <td>{{ $storeName }}</td>
                <td class="value">{{ $row['total'] }}</td>
                <td class="value">{{ $row['positive'] }}</td>
                <td class="value">{{ $row['negative'] }}</td>
            </tr>
        @empty
            <tr><td colspan="4">Belum ada review pada rentang ini.</td></tr>
        @endforelse
    </table>

    <h2>Ulasan Pelanggan</h2>
    <table>
        <tr><th>Tanggal</th><th>Toko</th><th>Pelanggan</th><th>Sentiment</th><th>Tag</th><th>Komentar</th></tr>
        @forelse ($result['reviews'] as $review)
            <tr>
                <td>{{ $review->created_at->format('d M Y') }}</td>
                <td>{{ $review->store?->name ?? '-' }}</td>
                <td>{{ $review->customer?->name ?? 'Pelanggan' }}</td>
                <td>{{ $sentimentLabel($review->sentiment) }}</td>
                <td>{{ implode(', ', $review->tags ?? []) ?: '-' }}</td>
                <td>{{ $review->comment ?: '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="6">Belum ada ulasan pada rentang ini.</td></tr>
        @endforelse
    </table>
</body>
</html>
