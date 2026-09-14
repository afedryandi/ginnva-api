<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        td, th { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        td.value, th.value { text-align: right; }
        th { background: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 9px; }
    </style>
</head>
<body>
    @php
        $statusLabel = fn (string $s) => match ($s) {
            'pending' => 'Menunggu Diproses',
            'fulfilled' => 'Sudah Dikirim',
            'cancelled' => 'Dibatalkan',
            default => $s,
        };
        $typeLabel = fn (string $t) => match ($t) {
            'partner' => 'Partner',
            'customer' => 'Customer',
            default => $t,
        };
    @endphp

    <h1>Daftar Klaim Reward</h1>

    <table>
        <tr>
            <th>Ditukar Oleh</th><th>Tipe</th><th>Reward</th><th class="value">Poin</th>
            <th>Status</th><th>Catatan Admin</th><th>Tanggal</th>
        </tr>
        @forelse ($redemptions as $redemption)
            <tr>
                <td>{{ $redemption->redeemer_name }}</td>
                <td>{{ $typeLabel($redemption->redeemer_type) }}</td>
                <td>{{ $redemption->reward?->name ?? '-' }}</td>
                <td class="value">{{ number_format($redemption->points_spent, 0, ',', '.') }}</td>
                <td>{{ $statusLabel($redemption->status) }}</td>
                <td>{{ $redemption->notes ?: '-' }}</td>
                <td>{{ $redemption->created_at?->format('d M Y H:i') }}</td>
            </tr>
        @empty
            <tr><td colspan="7">Belum ada klaim reward.</td></tr>
        @endforelse
    </table>
</body>
</html>
