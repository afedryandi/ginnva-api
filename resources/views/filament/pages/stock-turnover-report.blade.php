<x-filament-panels::page>
    {{-- Panel memakai CSS Filament yang sudah dikompilasi, jadi class Tailwind yang tidak dipakai bawaan Filament tidak berefek. Gaya di bawah
         menggantikannya untuk halaman ini. --}}
    @once
        <style>
            .st-num { font-variant-numeric: tabular-nums; }
            .st-r { text-align: right; }
            .st-danger { color: #b91c1c; } .dark .st-danger { color: #f87171; }
            .st-muted { color: #6b7280; } .dark .st-muted { color: #9ca3af; }
            .st-link { color: #ED1651; } .st-link:hover { text-decoration: underline; }
            .st-badge { display: inline-block; padding: 0 .5rem; border-radius: .375rem; font-size: .75rem; font-weight: 600; }
            .st-badge-danger { background: rgba(185, 28, 28, .12); color: #b91c1c; } .dark .st-badge-danger { background: rgba(248, 113, 113, .15); color: #f87171; }
            .st-badge-idle { background: rgba(107, 114, 128, .15); }
            .st-table th, .st-table td { padding: .5rem .75rem; vertical-align: top; white-space: nowrap; }
            .st-table th:first-child, .st-table td:first-child { padding-left: 0; }
            .st-table tr.st-idle td { font-style: italic; color: #6b7280; }
            .dark .st-table tr.st-idle td { color: #9ca3af; }
        </style>
    @endonce

    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $filterTargets = 'data.from, data.to, data.preset, data.type, data.only_low, data.show_idle';
        $low = \App\Filament\Pages\StockTurnoverReport::LOW_DAYS;
    @endphp

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $filterTargets }}" class="space-y-6">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px">
            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Barang Dipakai</div>
                <div class="mt-1 text-2xl font-bold st-num">{{ number_format($result['usedCount'], 0, ',', '.') }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Ada pemakaian pada rentang ini.</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Hampir Habis</div>
                <div class="mt-1 text-2xl font-bold st-num {{ $result['lowCount'] > 0 ? 'st-danger' : '' }}">{{ number_format($result['lowCount'], 0, ',', '.') }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Estimasi habis {{ $low }} hari atau kurang.</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Rata-rata Perputaran</div>
                <div class="mt-1 text-2xl font-bold st-num">{{ $result['avgRatio'] !== null ? number_format($result['avgRatio'], 2) . 'x' : '—' }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Dari barang yang rasionya terhitung.</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Stok Tidak Terpakai</div>
                <div class="mt-1 text-2xl font-bold st-num">{{ number_format($result['idleCount'], 0, ',', '.') }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Bersisa stok tetapi tidak dipakai; nyalakan "Tampilkan yang tidak terpakai" untuk melihatnya.</div>
            </x-filament::section>
        </div>

        <x-filament::section>
            <x-slot name="heading">Perputaran per Bahan Baku{{ config('features.consumables') ? ' & Barang Habis Pakai' : '' }}</x-slot>
            <x-slot name="description">
                Perputaran = pemakaian ÷ rata-rata stok. Pemakaian sudah dikurangi bahan yang dikembalikan lewat memo. Rasio tinggi berarti cepat berputar, bukan otomatis baik atau buruk.
                Diurutkan dari rasio tertinggi. Stok bersifat nasional (bukan per toko).
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm st-table">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th>Nama</th>
                            <th>Jenis</th>
                            <th class="st-r">Terpakai</th>
                            <th class="st-r">Stok Akhir</th>
                            <th class="st-r">Rata-rata Stok</th>
                            <th class="st-r">Perputaran</th>
                            <th class="st-r">Hari Terpakai</th>
                            <th class="st-r">Stok Hari Ini</th>
                            <th class="st-r">Konsumsi/Hari</th>
                            <th class="st-r">Estimasi Habis</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($result['rows'] as $row)
                            @php
                                $isLow = $row['daysUntilStockout'] !== null && $row['daysUntilStockout'] <= $low;
                                $isIdle = $row['qtyOut'] <= 0;
                            @endphp
                            <tr class="border-b border-gray-100 dark:border-white/5 {{ $isIdle ? 'st-idle' : '' }}">
                                <td class="font-medium">
                                    <a href="{{ $this->itemUrl($row['type'], $row['item']->id) }}" class="st-link">{{ $row['item']->name }}</a>
                                    @if ($isIdle)<span class="st-badge st-badge-idle">tidak terpakai</span>@endif
                                </td>
                                <td>{{ $row['type'] }}</td>
                                <td class="st-r st-num">
                                    {{ number_format($row['qtyOut'], 2) }} {{ $row['item']->unit }}
                                    @if ($row['returned'] > 0)<div class="text-xs st-muted">({{ number_format($row['returned'], 2) }} dikembalikan)</div>@endif
                                </td>
                                <td class="st-r st-num">{{ number_format($row['stockAtTo'], 2) }} {{ $row['item']->unit }}</td>
                                <td class="st-r st-num">{{ number_format($row['avgStock'], 2) }} {{ $row['item']->unit }}</td>
                                <td class="st-r st-num font-medium">{{ $row['turnoverRatio'] !== null ? number_format($row['turnoverRatio'], 2) . 'x' : '—' }}</td>
                                <td class="st-r st-num">{{ $row['daysSold'] }} Hari</td>
                                <td class="st-r st-num">{{ number_format($row['currentStock'], 2) }} {{ $row['item']->unit }}</td>
                                <td class="st-r st-num">{{ number_format($row['avgDailyConsumption'], 2) }} {{ $row['item']->unit }}</td>
                                <td class="st-r st-num font-medium {{ $isLow ? 'st-danger' : '' }}">
                                    {{ $row['daysUntilStockout'] !== null ? number_format($row['daysUntilStockout'], 0) . ' Hari' : '—' }}
                                    @if ($isLow)<span class="st-badge st-badge-danger">segera beli</span>@endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="text-center text-gray-500 dark:text-gray-400" style="padding: 1rem 0">Tidak ada pergerakan stok pada rentang ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs st-muted">
                Stok awal dan akhir direkonstruksi mundur dari stok hari ini karena sistem tidak menyimpan stok harian.
                "Estimasi Habis" = stok hari ini ÷ konsumsi harian rata-rata (hanya hari yang sudah berjalan); perkiraan kasar dengan asumsi kecepatan pemakaian tetap.
            </p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
