<x-filament-panels::page>
    {{-- Panel memakai CSS Filament yang sudah dikompilasi, jadi class Tailwind yang tidak dipakai bawaan Filament tidak berefek. Gaya di bawah
         menggantikannya untuk halaman ini. Grid kartu memakai style inline di elemennya. --}}
    @once
        <style>
            .ps-num { font-variant-numeric: tabular-nums; }
            .ps-r { text-align: right; }
            .ps-good { color: #15803d; } .dark .ps-good { color: #4ade80; }
            .ps-danger { color: #b91c1c; } .dark .ps-danger { color: #f87171; }
            .ps-muted { color: #6b7280; } .dark .ps-muted { color: #9ca3af; }
            .ps-note { border: 1px solid #fcd34d; background: #fffbeb; color: #92400e; border-radius: .5rem; padding: .75rem 1rem; font-size: .875rem; }
            .dark .ps-note { border-color: rgba(245, 158, 11, .35); background: rgba(245, 158, 11, .1); color: #fcd34d; }
            .ps-table th, .ps-table td { padding: .5rem .75rem; vertical-align: top; }
            .ps-table th { white-space: nowrap; }
            .ps-table th:first-child, .ps-table td:first-child { padding-left: 0; }
            .ps-table td.ps-nowrap { white-space: nowrap; }
            .ps-table tr.ps-unassigned td { font-style: italic; color: #6b7280; }
            .dark .ps-table tr.ps-unassigned td { color: #9ca3af; }
            .ps-table tfoot td { font-weight: 700; border-top: 2px solid rgba(107, 114, 128, .5); }
        </style>
    @endonce

    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $rupiah = fn ($n) => 'Rp' . number_format($n, 0, ',', '.');
        $filterTargets = 'data.from, data.to, data.store_id, data.preset';
    @endphp

    @if ($result['unassignedCount'] > 0)
        <div class="ps-note">
            {{ $result['unassignedCount'] }} dari {{ $result['totalCount'] }} transaksi ({{ number_format($result['unassignedPct'], 1) }}%) punya nilai yang belum diisi produk (SKU)-nya, senilai {{ $rupiah($result['unassignedRevenue']) }} —
            isi kolom Produk pada rincian harga saat mencatat penjualan supaya laporan ini akurat sepenuhnya.
        </div>
    @endif

    @if ($result['multiProductCount'] > 0)
        <div class="ps-note">
            {{ $result['multiProductCount'] }} transaksi lama (senilai {{ $rupiah($result['multiProductRevenue']) }}) memakai lebih dari satu produk tetapi tidak punya rincian harga. Seluruh nilainya dihitung ke produk utama booking, jadi angka per produk untuk transaksi itu belum pasti.
        </div>
    @endif

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $filterTargets }}" class="space-y-6">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px">
            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Total Penjualan Produk (bersih)</div>
                <div class="mt-1 text-2xl font-bold ps-num ps-good">{{ $rupiah($result['totalRevenue']) }}</div>
                @if ($result['totalRefundAmount'] > 0)
                    <div class="mt-1 text-xs ps-danger">setelah pengembalian {{ $rupiah($result['totalRefundAmount']) }} (kotor {{ $rupiah($result['grossRevenue']) }})</div>
                @endif
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Jumlah Transaksi</div>
                <div class="mt-1 text-2xl font-bold ps-num">{{ number_format($result['totalCount'], 0, ',', '.') }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Transaksi berproduk ganda dihitung di tiap produknya pada tabel.</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Total Refund</div>
                <div class="mt-1 text-2xl font-bold ps-num ps-danger">{{ $rupiah($result['totalRefundAmount']) }}</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Total Komisi Installer</div>
                <div class="mt-1 text-2xl font-bold ps-num">{{ $rupiah($result['totalCommission']) }}</div>
            </x-filament::section>
        </div>

        {{--
            @livewire() langsung (bukan <x-filament-widgets::widgets>) supaya
            bisa kirim from/to/storeId ke mount() grafik (audit 2026-09-11,
            temuan A) — sebelumnya grafik selalu 14/30/90 hari terakhir sendiri.
        --}}
        @livewire(
            \App\Filament\ReportWidgets\ProductSalesChart::class,
            ['from' => $result['from']->toDateString(), 'to' => $result['to']->toDateString(), 'storeId' => $result['storeId']],
            key('product-sales-chart-' . $result['from']->toDateString() . '-' . $result['to']->toDateString() . '-' . ($result['storeId'] ?? 'all'))
        )

        <x-filament::section>
            <x-slot name="heading">Penjualan per Produk (SKU)</x-slot>
            <x-slot name="description">
                Diurutkan dari penjualan tertinggi. Nilai tiap transaksi dibagi ke produknya menurut rincian harga per layanan; "Belum Diisi SKU" dirinci per jenis layanan.
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm ps-table">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th>Produk</th>
                            <th>SKU</th>
                            <th>Jenis Produk</th>
                            <th class="ps-r">Jumlah Transaksi</th>
                            <th class="ps-r">Jumlah %</th>
                            <th class="ps-r">Penjualan</th>
                            <th class="ps-r">Penjualan %</th>
                            <th class="ps-r">Jumlah Refund</th>
                            <th class="ps-r">Refund</th>
                            <th class="ps-r">Komisi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($result['rows'] as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5 {{ $row['unassigned'] ? 'ps-unassigned' : '' }}">
                                <td class="font-medium">{{ $row['name'] }}</td>
                                <td class="font-mono ps-nowrap">{{ $row['sku'] }}</td>
                                <td class="ps-nowrap">{{ $row['type'] }}</td>
                                <td class="ps-r ps-num">{{ $row['count'] }}</td>
                                <td class="ps-r ps-num">{{ number_format($row['countPct'], 1) }}%</td>
                                <td class="ps-r ps-num ps-nowrap">{{ $rupiah($row['revenue']) }}</td>
                                <td class="ps-r ps-num">{{ number_format($row['revenuePct'], 1) }}%</td>
                                <td class="ps-r ps-num">{{ $row['refundCount'] > 0 ? $row['refundCount'] : '—' }}</td>
                                <td class="ps-r ps-num ps-nowrap {{ $row['refundAmount'] > 0 ? 'ps-danger' : '' }}">{{ $row['refundAmount'] > 0 ? '(' . $rupiah($row['refundAmount']) . ')' : '—' }}</td>
                                <td class="ps-r ps-num ps-nowrap">
                                    {{ $row['commission'] > 0 ? '(' . $rupiah($row['commission']) . ')' : '—' }}
                                    @if ($row['hasUnratedJob'])<span title="Ada installer di baris ini yang komisinya belum diatur — nominal belum lengkap.">*</span>@endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="text-center text-gray-500 dark:text-gray-400" style="padding: 1rem 0">Belum ada data pada rentang ini.</td></tr>
                        @endforelse
                    </tbody>
                    @if ($result['rows']->isNotEmpty())
                        <tfoot>
                            <tr>
                                <td colspan="3">Total</td>
                                <td class="ps-r ps-num">{{ number_format($result['slotCount'], 0, ',', '.') }}</td>
                                <td></td>
                                <td class="ps-r ps-num">{{ $rupiah($result['grossRevenue']) }}</td>
                                <td></td>
                                <td></td>
                                <td class="ps-r ps-num">{{ $result['totalRefundAmount'] > 0 ? '(' . $rupiah($result['totalRefundAmount']) . ')' : '—' }}</td>
                                <td class="ps-r ps-num">{{ $result['totalCommission'] > 0 ? '(' . $rupiah($result['totalCommission']) . ')' : '—' }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
            <p class="mt-3 text-xs ps-muted">Jumlah baris bisa lebih besar dari jumlah transaksi karena transaksi berproduk ganda dihitung di tiap produknya. * = ada installer yang komisinya belum diatur.</p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
