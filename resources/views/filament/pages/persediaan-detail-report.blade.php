<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $rupiah = fn ($n) => 'Rp' . number_format($n, 0, ',', '.');
        $typeLabel = fn (string $type) => match ($type) {
            'in' => 'Masuk',
            'out' => 'Keluar',
            'adjustment' => 'Penyesuaian',
            default => $type,
        };
        $filterTargets = 'data.from, data.to';
    @endphp

    <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-400">
        "Outlet" dan "No Transaksi" ala Majoo tidak ditampilkan — Bahan Baku/Barang Habis Pakai Ginnva tidak di-scope per toko
        (satu pool bersama, bukan per-cabang), dan pergerakan stok tidak menyimpan nomor referensi transaksi.
    </div>

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $filterTargets }}" class="space-y-6">
    {{-- style inline (bukan class grid-cols-*) -- panel Filament tidak
         compile Tailwind project ini, lihat catatan di SalesResource. --}}
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:1rem;">
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Bahan Baku Masuk</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-success-600 dark:text-success-400">{{ number_format($result['materialInCount'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Bahan Baku Keluar</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-danger-600 dark:text-danger-400">{{ number_format($result['materialOutCount'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Barang Habis Pakai Masuk</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-success-600 dark:text-success-400">{{ number_format($result['consumableInCount'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Barang Habis Pakai Keluar</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-danger-600 dark:text-danger-400">{{ number_format($result['consumableOutCount'], 0, ',', '.') }}</div>
        </x-filament::section>
    </div>

    <x-filament::section>
        <x-slot name="heading">Pergerakan Bahan Baku</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-3">Tanggal</th>
                        <th class="py-2 pr-3">Bahan Baku</th>
                        <th class="py-2 pr-3">Jenis</th>
                        <th class="py-2 pr-3 text-right">Jumlah</th>
                        <th class="py-2 pr-3 text-right">Harga Beli</th>
                        <th class="py-2 pr-3">Oleh</th>
                        <th class="py-2 pl-3">Catatan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($result['materialMovements'] as $movement)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-3 tabular-nums">{{ $movement->created_at->format('d M Y H:i') }}</td>
                            <td class="py-2 pr-3 font-medium">{{ $movement->rawMaterial?->name ?? '—' }}</td>
                            <td class="py-2 pr-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $movement->type === 'in' ? 'bg-success-100 text-success-700 dark:bg-success-500/10 dark:text-success-400' : 'bg-danger-100 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400' }}">
                                    {{ $typeLabel($movement->type) }}
                                </span>
                            </td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ number_format((float) $movement->quantity, 2) }} {{ $movement->rawMaterial?->unit }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $movement->unit_cost ? $rupiah((float) $movement->unit_cost) : '—' }}</td>
                            <td class="py-2 pr-3">{{ $movement->user?->name ?? '—' }}</td>
                            <td class="py-2 pl-3 text-xs text-gray-500 dark:text-gray-400">{{ $movement->note ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-4 text-center text-gray-500 dark:text-gray-400">Tidak ada pergerakan bahan baku pada rentang ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Pergerakan Barang Habis Pakai</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-3">Tanggal</th>
                        <th class="py-2 pr-3">Barang</th>
                        <th class="py-2 pr-3">Jenis</th>
                        <th class="py-2 pr-3 text-right">Jumlah</th>
                        <th class="py-2 pr-3 text-right">Harga Beli</th>
                        <th class="py-2 pr-3">Oleh</th>
                        <th class="py-2 pl-3">Catatan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($result['consumableMovements'] as $movement)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-3 tabular-nums">{{ $movement->created_at->format('d M Y H:i') }}</td>
                            <td class="py-2 pr-3 font-medium">{{ $movement->consumableItem?->name ?? '—' }}</td>
                            <td class="py-2 pr-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $movement->type === 'in' ? 'bg-success-100 text-success-700 dark:bg-success-500/10 dark:text-success-400' : ($movement->type === 'out' ? 'bg-danger-100 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400' : 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400') }}">
                                    {{ $typeLabel($movement->type) }}
                                </span>
                            </td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ number_format((float) $movement->quantity, 2) }} {{ $movement->consumableItem?->unit }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $movement->unit_cost ? $rupiah((float) $movement->unit_cost) : '—' }}</td>
                            <td class="py-2 pr-3">{{ $movement->user?->name ?? '—' }}</td>
                            <td class="py-2 pl-3 text-xs text-gray-500 dark:text-gray-400">{{ $movement->note ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-4 text-center text-gray-500 dark:text-gray-400">Tidak ada pergerakan barang habis pakai pada rentang ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
    </div>
</x-filament-panels::page>
