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
        $signed = fn ($n) => ($n > 0 ? '+' : '') . number_format($n, 0, ',', '.');
        $hours = fn ($h) => $h === null ? '-' : ($h < 48 ? number_format($h, 0, ',', '.') . ' jam' : number_format($h / 24, 1, ',', '.') . ' hari');
    @endphp

    <h1>Kepuasan Pelanggan</h1>
    <div class="period">Periode: {{ $result['from']->format('d M Y') }} - {{ $result['to']->format('d M Y') }}</div>

    <table class="summary">
        <tr>
            <td>Total Review: <strong>{{ number_format($result['total'], 0, ',', '.') }}</strong></td>
            <td>Positif: <strong>{{ number_format($result['positive'], 0, ',', '.') }}</strong> ({{ number_format($result['positiveRate'], 1, ',', '.') }}%)</td>
            <td>Skor Bersih: <strong>{{ $signed($result['netScore']) }}</strong></td>
        </tr>
        <tr>
            <td>Netral: <strong>{{ number_format($result['neutral'], 0, ',', '.') }}</strong></td>
            <td>Negatif: <strong>{{ number_format($result['negative'], 0, ',', '.') }}</strong> ({{ $result['unfollowedNegativeCount'] }} belum ditindaklanjuti)</td>
            <td>Tingkat Respons: <strong>{{ $result['responseRate'] !== null ? number_format($result['responseRate'], 1, ',', '.') . '%' : '-' }}</strong> &middot; Lama Tindak Lanjut: <strong>{{ $hours($result['avgFollowUpHours']) }}</strong></td>
        </tr>
    </table>

    <h2>Aspek yang Disukai</h2>
    <table>
        @forelse ($result['tagGroups']['positive'] as $tag => $count)
            <tr><td>{{ \App\Models\StoreReview::tagLabel((string) $tag) }}</td><td class="value">{{ $count }}</td></tr>
        @empty
            <tr><td colspan="2">Belum ada tag positif pada rentang ini.</td></tr>
        @endforelse
    </table>

    <h2>Aspek yang Perlu Diperbaiki</h2>
    <table>
        @forelse ($result['tagGroups']['negative'] as $tag => $count)
            <tr><td>{{ \App\Models\StoreReview::tagLabel((string) $tag) }}</td><td class="value">{{ $count }}</td></tr>
        @empty
            <tr><td colspan="2">Belum ada tag keluhan pada rentang ini.</td></tr>
        @endforelse
    </table>

    <h2>Tren {{ $result['trendMonthly'] ? 'per Bulan' : 'per Minggu' }}</h2>
    <table>
        <tr><th>{{ $result['trendMonthly'] ? 'Bulan' : 'Minggu' }}</th><th class="value">Total</th><th class="value">Positif</th><th class="value">Negatif</th><th class="value">Skor Bersih</th></tr>
        @forelse ($result['trend'] as $row)
            <tr>
                <td>{{ $row['label'] }}</td>
                <td class="value">{{ $row['total'] }}</td>
                <td class="value">{{ $row['positive'] }}</td>
                <td class="value">{{ $row['negative'] }}</td>
                <td class="value">{{ $signed($row['net']) }}</td>
            </tr>
        @empty
            <tr><td colspan="5">Belum ada review pada rentang ini.</td></tr>
        @endforelse
    </table>

    <h2>Per Toko</h2>
    <table>
        <tr>
            <th>Toko</th><th class="value">Total Review</th><th class="value">Positif</th><th class="value">Netral</th><th class="value">Negatif</th>
            <th class="value">% Positif</th><th class="value">Skor Bersih</th><th class="value">Belum Ditindaklanjuti</th>
        </tr>
        @forelse ($result['byStore'] as $storeName => $row)
            <tr>
                <td>{{ $storeName }}</td>
                <td class="value">{{ $row['total'] }}</td>
                <td class="value">{{ $row['positive'] }}</td>
                <td class="value">{{ $row['neutral'] }}</td>
                <td class="value">{{ $row['negative'] }}</td>
                <td class="value">{{ number_format($row['positiveRate'], 1, ',', '.') }}%</td>
                <td class="value">{{ $signed($row['net']) }}</td>
                <td class="value">{{ $row['unfollowed'] > 0 ? $row['unfollowed'] : '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="8">Belum ada review pada rentang ini.</td></tr>
        @endforelse
    </table>

    <h2>Review Pelanggan</h2>
    <table>
        <tr><th>Tanggal</th><th>Toko</th><th>Pelanggan</th><th>Sentiment</th><th>Tag</th><th>Komentar</th><th>No. Booking</th></tr>
        @forelse ($result['reviews'] as $review)
            <tr>
                <td>{{ $review->created_at->format('d M Y') }}</td>
                <td>{{ $review->store?->name ?? '-' }}</td>
                <td>{{ $review->customer?->name ?? 'Pelanggan' }}</td>
                <td>{{ $sentimentLabel($review->sentiment) }}</td>
                <td>{{ implode(', ', $review->tagLabels()) ?: '-' }}</td>
                <td>{{ $review->comment ?: '-' }}</td>
                <td>{{ $review->booking?->booking_number ?? '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="7">Belum ada review pada rentang ini.</td></tr>
        @endforelse
    </table>
</body>
</html>
