<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $statusLabel = fn (string $status) => match ($status) {
            'unallocated' => 'Belum Dialokasikan',
            'allocated' => 'Dialokasikan',
            'used' => 'Habis Dipakai',
            default => $status,
        };
        $statusColor = fn (string $status) => match ($status) {
            'unallocated' => 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400',
            'allocated' => 'bg-warning-100 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400',
            'used' => 'bg-success-100 text-success-700 dark:bg-success-500/10 dark:text-success-400',
            default => 'bg-gray-100 text-gray-600',
        };
    @endphp

    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Roll</div>
            <div class="mt-1 text-2xl font-bold tabular-nums">{{ number_format($result['totalCount'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Habis Dipakai</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-success-600 dark:text-success-400">{{ number_format($result['usedCount'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Sisa Panjang</div>
            <div class="mt-1 text-2xl font-bold tabular-nums">{{ number_format($result['totalRemainingMeters'], 2) }} m</div>
        </x-filament::section>
    </div>

    <x-filament::section>
        <x-slot name="heading">Daftar Serial Number (Roll)</x-slot>
        <x-slot name="description">Filter berdasarkan tanggal alokasi ke toko — roll yang belum pernah dialokasikan tidak muncul kecuali status "Belum Dialokasikan" dipilih dan tanggalnya cocok.</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-3">Kode Serial</th>
                        <th class="py-2 pr-3">Produk</th>
                        <th class="py-2 pr-3">Toko</th>
                        <th class="py-2 pr-3 text-right">Panjang Total</th>
                        <th class="py-2 pr-3 text-right">Sisa Panjang</th>
                        <th class="py-2 pr-3">Tgl Alokasi</th>
                        <th class="py-2 pr-3">Tgl Habis Dipakai</th>
                        <th class="py-2 pl-3">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($result['codes'] as $code)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-3 font-mono font-medium">{{ $code->code }}</td>
                            <td class="py-2 pr-3">{{ $code->filmProduct ? "{$code->filmProduct->sku} — {$code->filmProduct->name}" : '—' }}</td>
                            <td class="py-2 pr-3">{{ $code->store?->name ?? '—' }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ number_format((float) $code->total_length_meters, 2) }} m</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ number_format((float) $code->remaining_length_meters, 2) }} m</td>
                            <td class="py-2 pr-3 tabular-nums">{{ $code->allocated_at?->format('d M Y') ?? '—' }}</td>
                            <td class="py-2 pr-3 tabular-nums">{{ $code->used_at?->format('d M Y') ?? '—' }}</td>
                            <td class="py-2 pl-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $statusColor($code->status) }}">
                                    {{ $statusLabel($code->status) }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-4 text-center text-gray-500 dark:text-gray-400">Tidak ada serial number pada rentang/filter ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Riwayat Pemakaian</x-slot>
        <x-slot name="description">
            Analog "Jenis Transaksi"/"Tanggal" Majoo — tiap baris = 1 kali roll dipakai instalasi. "No Transaksi" dan
            "Stok"/"Stok Akhir" (saldo sebelum/sesudah) tidak ditampilkan — datanya tidak tersimpan, cuma jumlah meter
            yang dipakai saat itu yang dicatat sistem.
        </x-slot>

        <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
            Total meter dipakai pada rentang ini: <span class="font-semibold tabular-nums">{{ number_format($result['totalMetersUsed'], 2) }} m</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-3">Tanggal</th>
                        <th class="py-2 pr-3">Kode Serial</th>
                        <th class="py-2 pr-3">Toko</th>
                        <th class="py-2 pr-3 text-right">Meter Dipakai</th>
                        <th class="py-2 pr-3">Oleh</th>
                        <th class="py-2 pl-3">Catatan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($result['usages'] as $usage)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-3 tabular-nums">{{ $usage->created_at->format('d M Y H:i') }}</td>
                            <td class="py-2 pr-3 font-mono">{{ $usage->scrollCode?->code ?? '—' }}</td>
                            <td class="py-2 pr-3">{{ $usage->scrollCode?->store?->name ?? '—' }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ number_format((float) $usage->meters, 2) }} m</td>
                            <td class="py-2 pr-3">{{ $usage->user?->name ?? '—' }}</td>
                            <td class="py-2 pl-3 text-xs text-gray-500 dark:text-gray-400">{{ $usage->note ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-4 text-center text-gray-500 dark:text-gray-400">Tidak ada pemakaian roll pada rentang ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
