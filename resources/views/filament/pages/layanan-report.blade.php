<x-filament-panels::page>
    {{-- Panel memakai CSS Filament yang sudah dikompilasi, jadi class Tailwind yang tidak dipakai bawaan Filament tidak berefek. Gaya di bawah
         menggantikannya untuk halaman ini. Grid kartu memakai style inline di elemennya. --}}
    @once
        <style>
            .lj-num { font-variant-numeric: tabular-nums; }
            .lj-r { text-align: right; }
            .lj-good { color: #15803d; } .dark .lj-good { color: #4ade80; }
            .lj-danger { color: #b91c1c; } .dark .lj-danger { color: #f87171; }
            .lj-muted { color: #6b7280; } .dark .lj-muted { color: #9ca3af; }
            .lj-table th, .lj-table td { padding: .5rem .75rem; vertical-align: top; }
            .lj-table th { white-space: nowrap; }
            .lj-table th:first-child, .lj-table td:first-child { padding-left: 0; }
            .lj-table td.lj-nowrap { white-space: nowrap; }
            .lj-table tfoot td { font-weight: 700; border-top: 2px solid rgba(107, 114, 128, .5); }
            .lj-link:hover { text-decoration: underline; }
        </style>
    @endonce

    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $rupiah = fn ($n) => 'Rp' . number_format($n, 0, ',', '.');
        $filterTargets = 'data.from, data.to, data.store_id, data.preset';
        $labels = \App\Filament\Pages\LayananReport::TYPE_LABELS;
        // "Lainnya" (booking tanpa jenis) hanya ditampilkan bila ada isinya.
        $typeKeys = collect(array_keys($labels))->filter(fn ($key) => $key !== 'lainnya' || $result['byType']['lainnya']['count'] > 0)->values();
        $storeTypeKeys = $typeKeys->filter(fn ($key) => $key !== 'lainnya' || collect($result['byStore'])->sum('types.lainnya') > 0)->values();
    @endphp

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $filterTargets }}" class="space-y-6">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px">
            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Jumlah Transaksi</div>
                <div class="mt-1 text-2xl font-bold lj-num">{{ number_format($result['totalCount'], 0, ',', '.') }}</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Total Pendapatan (bersih)</div>
                <div class="mt-1 text-2xl font-bold lj-num lj-good">{{ $rupiah($result['totalRevenue']) }}</div>
                @if ($result['refund'] > 0)
                    <div class="mt-1 text-xs lj-danger">setelah pengembalian {{ $rupiah($result['refund']) }} (kotor {{ $rupiah($result['grossRevenue']) }})</div>
                @endif
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Rata-rata per Transaksi</div>
                <div class="mt-1 text-2xl font-bold lj-num">{{ $rupiah($result['avgRevenue']) }}</div>
            </x-filament::section>
        </div>

        {{-- @livewire() langsung supaya grafik mengikuti Dari/Sampai/Toko halaman ini, bukan pilihan 14/30/90 hari sendiri. --}}
        @livewire(
            \App\Filament\Widgets\LayananChart::class,
            ['from' => $result['from']->toDateString(), 'to' => $result['to']->toDateString(), 'storeId' => $result['storeId']],
            key('layanan-chart-' . $result['from']->toDateString() . '-' . $result['to']->toDateString() . '-' . ($result['storeId'] ?? 'all'))
        )

        <x-filament::section>
            <x-slot name="heading">Per Jenis Layanan</x-slot>
            <x-slot name="description">
                Transaksi dengan beberapa jenis dibagi menurut rincian harga (rata kalau tidak ada rincian). Pendapatan di sini kotor; Pengembalian dan Bersih membagi refund dengan proporsi yang sama.
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm lj-table">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th>Jenis</th>
                            <th class="lj-r">Jumlah</th>
                            <th class="lj-r">Jumlah %</th>
                            <th class="lj-r">Pendapatan</th>
                            <th class="lj-r">Pendapatan %</th>
                            <th class="lj-r">Rata-rata</th>
                            <th class="lj-r">Pengembalian</th>
                            <th class="lj-r">Bersih</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($typeKeys as $key)
                            @php $row = $result['byType'][$key]; @endphp
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="font-medium">{{ $labels[$key] }}</td>
                                <td class="lj-r lj-num">{{ number_format($row['count'], 0, ',', '.') }}</td>
                                <td class="lj-r lj-num">{{ number_format($row['countPct'], 1) }}%</td>
                                <td class="lj-r lj-num lj-nowrap">{{ $rupiah($row['revenue']) }}</td>
                                <td class="lj-r lj-num">{{ number_format($row['revenuePct'], 1) }}%</td>
                                <td class="lj-r lj-num lj-nowrap">{{ $row['count'] > 0 ? $rupiah($row['avg']) : '—' }}</td>
                                <td class="lj-r lj-num lj-nowrap {{ $row['refund'] > 0 ? 'lj-danger' : '' }}">{{ $row['refund'] > 0 ? '(' . $rupiah($row['refund']) . ')' : '—' }}</td>
                                <td class="lj-r lj-num lj-nowrap">{{ $rupiah($row['net']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Total</td>
                            <td class="lj-r lj-num">{{ number_format($result['slotCount'], 0, ',', '.') }}</td>
                            <td></td>
                            <td class="lj-r lj-num lj-nowrap">{{ $rupiah($result['grossRevenue']) }}</td>
                            <td></td>
                            <td></td>
                            <td class="lj-r lj-num lj-nowrap">{{ $result['refund'] > 0 ? '(' . $rupiah($result['refund']) . ')' : '—' }}</td>
                            <td class="lj-r lj-num lj-nowrap">{{ $rupiah($result['totalRevenue']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="mt-3 text-xs lj-muted">Jumlah total bisa lebih besar dari Jumlah Transaksi karena transaksi berjenis ganda terhitung di tiap jenisnya.</p>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Per Toko</x-slot>
            <x-slot name="description">Pendapatan kotor tiap toko menurut jenis layanan; klik nama toko untuk melihat Detail Penjualannya.</x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm lj-table">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th>Toko</th>
                            <th class="lj-r">Jumlah</th>
                            @foreach ($storeTypeKeys as $key)
                                <th class="lj-r">{{ $labels[$key] }}</th>
                            @endforeach
                            <th class="lj-r">Pendapatan</th>
                            <th class="lj-r">Pengembalian</th>
                            <th class="lj-r">Bersih</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($result['byStore'] as $storeName => $row)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="font-medium">
                                    @if ($storeName !== 'Tanpa Toko')
                                        <a href="{{ $this->salesUrl($storeName) }}" class="lj-link" title="Lihat Detail Penjualan toko ini">{{ $storeName }}</a>
                                    @else
                                        {{ $storeName }}
                                    @endif
                                </td>
                                <td class="lj-r lj-num">{{ number_format($row['count'], 0, ',', '.') }}</td>
                                @foreach ($storeTypeKeys as $key)
                                    <td class="lj-r lj-num lj-nowrap">{{ $row['types'][$key] > 0 ? $rupiah($row['types'][$key]) : '—' }}</td>
                                @endforeach
                                <td class="lj-r lj-num lj-nowrap">{{ $rupiah($row['revenue']) }}</td>
                                <td class="lj-r lj-num lj-nowrap {{ $row['refund'] > 0 ? 'lj-danger' : '' }}">{{ $row['refund'] > 0 ? '(' . $rupiah($row['refund']) . ')' : '—' }}</td>
                                <td class="lj-r lj-num lj-nowrap">{{ $rupiah($row['net']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ 5 + $storeTypeKeys->count() }}" class="text-center text-gray-500 dark:text-gray-400" style="padding: 1rem 0">Belum ada data pada rentang ini.</td></tr>
                        @endforelse
                    </tbody>
                    @if (count($result['byStore']) > 0)
                        <tfoot>
                            <tr>
                                <td>Total</td>
                                <td class="lj-r lj-num">{{ number_format($result['totalCount'], 0, ',', '.') }}</td>
                                @foreach ($storeTypeKeys as $key)
                                    <td class="lj-r lj-num lj-nowrap">{{ $rupiah(collect($result['byStore'])->sum('types.' . $key)) }}</td>
                                @endforeach
                                <td class="lj-r lj-num lj-nowrap">{{ $rupiah($result['grossRevenue']) }}</td>
                                <td class="lj-r lj-num lj-nowrap">{{ $result['refund'] > 0 ? '(' . $rupiah($result['refund']) . ')' : '—' }}</td>
                                <td class="lj-r lj-num lj-nowrap">{{ $rupiah($result['totalRevenue']) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
