<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $rupiah = fn ($n) => 'Rp' . number_format($n, 0, ',', '.');
        $today = now()->startOfDay();
    @endphp

    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Sudah Kedaluwarsa</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-danger-600 dark:text-danger-400">{{ number_format($result['expiredCount'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Belum Kedaluwarsa (dalam rentang)</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-warning-600 dark:text-warning-400">{{ number_format($result['nearExpiryCount'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Nilai Stok Terdampak</div>
            <div class="mt-1 text-2xl font-bold tabular-nums">{{ $rupiah($result['totalValue']) }}</div>
        </x-filament::section>
    </div>

    <x-filament::section>
        <x-slot name="heading">Batch Kedaluwarsa</x-slot>
        <x-slot name="description">
            Cuma batch yang masih ada stoknya (qty > 0) yang dihitung — batch yang sudah habis dipakai tidak relevan lagi.
            "Batch Number" ala Majoo tidak ditampilkan — batch bahan baku Ginnva tidak punya kode batch diskrit (cuma tanggal terima/kedaluwarsa).
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-3">SKU</th>
                        <th class="py-2 pr-3">Bahan Baku</th>
                        <th class="py-2 pr-3">Tanggal Terima</th>
                        <th class="py-2 pr-3">Tanggal Kedaluwarsa</th>
                        <th class="py-2 pr-3 text-right">Sisa Qty</th>
                        <th class="py-2 pr-3 text-right">Nilai</th>
                        <th class="py-2 pl-3">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($result['batches'] as $batch)
                        @php $isExpired = $batch->expiry_date->lt($today); @endphp
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-3 font-mono">{{ $batch->rawMaterial?->code ?? '—' }}</td>
                            <td class="py-2 pr-3 font-medium">{{ $batch->rawMaterial?->name ?? '—' }}</td>
                            <td class="py-2 pr-3 tabular-nums">{{ $batch->received_date?->format('d M Y') ?? '—' }}</td>
                            <td class="py-2 pr-3 tabular-nums">{{ $batch->expiry_date->format('d M Y') }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ number_format((float) $batch->quantity, 2) }} {{ $batch->rawMaterial?->unit }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $rupiah((float) $batch->quantity * (float) ($batch->unit_cost ?? 0)) }}</td>
                            <td class="py-2 pl-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $isExpired ? 'bg-danger-100 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400' : 'bg-warning-100 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400' }}">
                                    {{ $isExpired ? 'Kedaluwarsa (' . $today->diffInDays($batch->expiry_date) . ' hari lalu)' : 'Kedaluwarsa dalam ' . $today->diffInDays($batch->expiry_date) . ' hari' }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-4 text-center text-gray-500 dark:text-gray-400">Tidak ada batch kedaluwarsa pada rentang ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
