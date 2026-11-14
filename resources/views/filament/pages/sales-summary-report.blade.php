<x-filament-panels::page>
    {{-- Panel memakai CSS Filament yang sudah dikompilasi, jadi class Tailwind yang tidak dipakai bawaan Filament tidak berefek. Gaya di bawah
         menggantikannya untuk halaman ini (pita judul, banner, warna angka, jarak sel). Grid memakai style inline di elemennya. --}}
    @once
        <style>
            .sd-up { color: #15803d; } .sd-warn { color: #b45309; }
            .dark .sd-up { color: #4ade80; } .dark .sd-warn { color: #fbbf24; }
            .sd-num { font-variant-numeric: tabular-nums; }
            .sd-banner { display: flex; align-items: center; gap: 8px; padding: 8px 12px; border: 1px solid; border-radius: 8px; font-size: 14px; text-decoration: none; }
            .sd-banner svg { flex-shrink: 0; }
            .sd-banner-warn { background: #fffbeb; border-color: #fcd34d; color: #92400e; }
            .sd-banner-warn:hover { background: #fef3c7; }
            .dark .sd-banner-warn { background: rgba(245, 158, 11, .1); border-color: rgba(245, 158, 11, .3); color: #fcd34d; }
            .dark .sd-banner-warn:hover { background: rgba(245, 158, 11, .2); }
            .ss-band { padding: 8px 12px; border-radius: 8px 8px 0 0; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; }
            .ss-band-ok { background: #f0fdf4; color: #15803d; }
            .ss-band-warn { background: #fffbeb; color: #b45309; }
            .ss-band-info { background: #fef2f2; color: #b91c1c; }
            .dark .ss-band-ok { background: rgba(34, 197, 94, .1); color: #4ade80; }
            .dark .ss-band-warn { background: rgba(245, 158, 11, .1); color: #fbbf24; }
            .dark .ss-band-info { background: rgba(239, 68, 68, .1); color: #f87171; }
            .ss-cell-l { padding: .5rem .75rem .5rem 0; } .ss-cell-r { padding: .5rem 0 .5rem .75rem; text-align: right; }
            .py-10 { padding-top: 2.5rem; padding-bottom: 2.5rem; }
        </style>
    @endonce

    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $rupiah = fn ($n) => 'Rp' . number_format($n, 0, ',', '.');
        $isEmpty = $result['bookingCount'] === 0;
        // wire:target untuk wire:loading di bawah — path model Filament
        // form dgn statePath('data') selalu "data.<namafield>".
        $filterTargets = 'data.from, data.to, data.store_id, data.compare, data.preset';
        $compare = $result['compare'] ?? null;
        $delta = function ($cur, $prev) {
            if ($prev === null || abs($prev) < 0.005) {
                return null;
            }

            $pct = (($cur - $prev) / abs($prev)) * 100;

            return ($pct >= 0 ? '+' : '') . number_format($pct, 1, ',', '.') . '%';
        };
    @endphp

    @if ($result['pendingCount'] > 0)
        <a
            href="{{ \App\Filament\Resources\BookingResource::getUrl('index', ['tableFilters' => ['selesai_belum_diproses' => ['isActive' => true]]]) }}"
            class="sd-banner sd-banner-warn"
        >
            <x-heroicon-o-exclamation-triangle class="h-4 w-4" />
            <span>
                <strong class="font-semibold">{{ number_format($result['pendingCount'], 0, ',', '.') }} booking</strong>
                sudah selesai tapi belum diproses ke pendapatan — nominalnya belum masuk laporan ini.
            </span>
            <x-heroicon-o-arrow-right class="ml-auto h-4 w-4" />
        </a>
    @endif

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $filterTargets }}" class="space-y-6">
    @if ($isEmpty)
        <x-filament::section>
            <div class="flex flex-col items-center gap-2 py-10 text-center">
                <x-heroicon-o-document-magnifying-glass class="h-10 w-10 text-gray-300 dark:text-gray-600" />
                <p class="text-sm font-medium text-gray-600 dark:text-gray-300">Belum ada penjualan tercatat untuk periode ini.</p>
                <p class="max-w-md text-xs text-gray-500 dark:text-gray-400">
                    Angka baru muncul setelah booking selesai diproses lewat "Catat Penjualan"{{ config('features.finance') ? ' ke Jurnal Umum' : '' }}.
                    @if ($result['pendingCount'] > 0)
                        Ada {{ number_format($result['pendingCount'], 0, ',', '.') }} booking selesai yang menunggu diproses (lihat peringatan di atas).
                    @endif
                </p>
            </div>
        </x-filament::section>
    @else
    @if ($compare)
        <p class="text-xs text-gray-500 dark:text-gray-400">Pembanding: {{ $result['compareLabel'] }}</p>
    @endif

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px">
        <x-filament::section>
            <div class="flex items-center justify-between">
                <div class="text-xs text-gray-500 dark:text-gray-400">Total Penjualan Bersih</div>
                <a href="{{ $this->salesUrl() }}" class="text-xs text-primary-600 hover:underline" title="Lihat Detail Penjualan">Detail →</a>
            </div>
            <div class="mt-1 text-2xl font-bold sd-num sd-up">{{ $rupiah($result['netSales']) }}</div>
            @if ($compare)
                <div class="mt-1 text-xs text-gray-400">vs {{ $rupiah($compare['net']) }} ({{ $delta($result['netSales'], $compare['net']) ?? '—' }})</div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Jumlah Transaksi</div>
            <div class="mt-1 text-2xl font-bold sd-num">{{ number_format($result['bookingCount'], 0, ',', '.') }}</div>
            @if ($compare)
                <div class="mt-1 text-xs text-gray-400">vs {{ number_format($compare['count'], 0, ',', '.') }} ({{ $delta($result['bookingCount'], $compare['count']) ?? '—' }})</div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Biaya Promosi (Voucher + Promo)</div>
            <div class="mt-1 text-2xl font-bold sd-num sd-warn">({{ $rupiah($result['promoTotal']) }})</div>
        </x-filament::section>
    </div>

    <x-filament::section>
        <x-slot name="heading">Rincian Ringkasan Penjualan</x-slot>
        <x-slot name="description">
            Penjualan Kotor sudah setelah potongan promo dan voucher, jadi Biaya Promosi hanya informasi dan tidak dikurangkan lagi.
        </x-slot>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px">
            {{-- PENDAPATAN --}}
            <div>
                <div class="ss-band ss-band-ok">Pendapatan</div>
                <table class="w-full text-sm">
                    <tbody>
                        <tr class="font-bold">
                            <td class="ss-cell-l">Penjualan Kotor</td>
                            <td class="ss-cell-r sd-num">{{ $rupiah($result['grossSales']) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- BIAYA PROMOSI --}}
            <div>
                <div class="ss-band ss-band-warn">Biaya Promosi</div>
                <table class="w-full text-sm">
                    <tbody>
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="ss-cell-l">Promo Voucher</td>
                            <td class="ss-cell-r sd-num">({{ $rupiah($result['voucherDiscount']) }})</td>
                        </tr>
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="ss-cell-l">Promo Total Pembelian</td>
                            <td class="ss-cell-r sd-num">({{ $rupiah($result['spendPromoDiscount']) }})</td>
                        </tr>
                        <tr class="font-bold">
                            <td class="ss-cell-l">Total Biaya Promosi</td>
                            <td class="ss-cell-r sd-num">({{ $rupiah($result['promoTotal']) }})</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- PENJUALAN BERSIH --}}
            <div>
                <div class="ss-band ss-band-info">Penjualan Bersih</div>
                <table class="w-full text-sm">
                    <tbody>
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="ss-cell-l">Total Penjualan</td>
                            <td class="ss-cell-r sd-num">{{ $rupiah($result['grossSales']) }}</td>
                        </tr>
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="ss-cell-l">Pengembalian (Refund)</td>
                            <td class="ss-cell-r sd-num">{{ $result['refund'] > 0 ? '(' . $rupiah($result['refund']) . ')' : '—' }}</td>
                        </tr>
                        <tr class="font-bold">
                            <td class="ss-cell-l">Total Penjualan Bersih</td>
                            <td class="ss-cell-r sd-num">{{ $rupiah($result['netSales']) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </x-filament::section>
    @endif
    </div>
</x-filament-panels::page>
