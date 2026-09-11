<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $rupiah = fn ($n) => 'Rp' . number_format($n, 0, ',', '.');
        $isEmpty = $result['bookingCount'] === 0;
        // wire:target untuk wire:loading di bawah — path model Filament
        // form dgn statePath('data') selalu "data.<namafield>".
        $filterTargets = 'data.from, data.to, data.store_id';
    @endphp

    @if ($result['pendingCount'] > 0)
        <a
            href="{{ \App\Filament\Resources\BookingResource::getUrl('index', ['tableFilters' => ['selesai_belum_diproses' => ['isActive' => true]]]) }}"
            class="flex items-center gap-2 rounded-lg border border-warning-300 bg-warning-50 px-3 py-2 text-sm text-warning-800 transition hover:bg-warning-100 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-300 dark:hover:bg-warning-500/20"
        >
            <x-heroicon-o-exclamation-triangle class="h-4 w-4 flex-shrink-0" />
            <span>
                <strong class="font-semibold">{{ number_format($result['pendingCount'], 0, ',', '.') }} booking</strong>
                sudah selesai tapi belum diproses ke pendapatan — nominalnya belum masuk laporan ini.
            </span>
            <x-heroicon-o-arrow-right class="ml-auto h-4 w-4 flex-shrink-0" />
        </a>
    @endif

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $filterTargets }}" class="space-y-6">
    @if ($isEmpty)
        <x-filament::section>
            <div class="flex flex-col items-center gap-2 py-10 text-center">
                <x-heroicon-o-document-magnifying-glass class="h-10 w-10 text-gray-300 dark:text-gray-600" />
                <p class="text-sm font-medium text-gray-600 dark:text-gray-300">Belum ada penjualan tercatat untuk periode ini.</p>
                <p class="max-w-md text-xs text-gray-500 dark:text-gray-400">
                    Angka baru muncul setelah booking selesai diproses lewat "Proses Referral" ke Jurnal Umum.
                    @if ($result['pendingCount'] > 0)
                        Ada {{ number_format($result['pendingCount'], 0, ',', '.') }} booking selesai yang menunggu diproses (lihat peringatan di atas).
                    @endif
                </p>
            </div>
        </x-filament::section>
    @else
    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Penjualan Bersih</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-success-600 dark:text-success-400">{{ $rupiah($result['netSales']) }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Jumlah Transaksi</div>
            <div class="mt-1 text-2xl font-bold tabular-nums">{{ number_format($result['bookingCount'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Promo Voucher Terpakai</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-warning-600 dark:text-warning-400">({{ $rupiah($result['voucherDiscount']) }})</div>
        </x-filament::section>
    </div>

    <x-filament::section>
        <x-slot name="heading">Rincian Ringkasan Penjualan</x-slot>
        <x-slot name="description">
            Baris bertanda "Tidak berlaku" memang bukan bagian dari bisnis jasa Ginnva (bukan resto/marketplace).
            Baris bertanda "Belum tersedia" butuh data atau keputusan bisnis yang belum ada di sistem — bukan Rp 0.
        </x-slot>

        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
            {{-- PENDAPATAN --}}
            <div>
                <div class="rounded-t-lg bg-success-50 px-3 py-2 text-xs font-bold uppercase tracking-wide text-success-700 dark:bg-success-500/10 dark:text-success-400">
                    Pendapatan
                </div>
                <table class="w-full text-sm">
                    <tbody>
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-3">Penjualan Kotor</td>
                            <td class="py-2 pl-3 text-right tabular-nums">{{ $rupiah($result['grossSales']) }}</td>
                        </tr>
                        <tr class="border-b border-gray-100 dark:border-white/5 text-gray-400 dark:text-gray-500">
                            <td class="py-2 pr-3 italic">Ongkos Kirim</td>
                            <td class="py-2 pl-3 text-right italic">Tidak berlaku</td>
                        </tr>
                        <tr class="border-b border-gray-100 dark:border-white/5 text-gray-400 dark:text-gray-500">
                            <td class="py-2 pr-3 italic">Biaya Pelayanan / MDR</td>
                            <td class="py-2 pl-3 text-right italic">Tidak berlaku</td>
                        </tr>
                        <tr class="border-b border-gray-100 dark:border-white/5 text-gray-400 dark:text-gray-500">
                            <td class="py-2 pr-3 italic">Pajak (PPN)</td>
                            <td class="py-2 pl-3 text-right italic">Belum tersedia</td>
                        </tr>
                        <tr class="font-bold">
                            <td class="py-2 pr-3">Total Pendapatan</td>
                            <td class="py-2 pl-3 text-right tabular-nums">{{ $rupiah($result['grossSales']) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- BIAYA PROMOSI --}}
            <div>
                <div class="rounded-t-lg bg-warning-50 px-3 py-2 text-xs font-bold uppercase tracking-wide text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
                    Biaya Promosi
                </div>
                <table class="w-full text-sm">
                    <tbody>
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-3">Promo Voucher</td>
                            <td class="py-2 pl-3 text-right tabular-nums">({{ $rupiah($result['voucherDiscount']) }})</td>
                        </tr>
                        <tr class="border-b border-gray-100 dark:border-white/5 text-gray-400 dark:text-gray-500">
                            <td class="py-2 pr-3 italic">Reward Poin (nilai Rp)</td>
                            <td class="py-2 pl-3 text-right italic">Belum tersedia</td>
                        </tr>
                        <tr class="font-bold">
                            <td class="py-2 pr-3">Total Biaya Promosi</td>
                            <td class="py-2 pl-3 text-right tabular-nums">({{ $rupiah($result['voucherDiscount']) }})</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- PENJUALAN BERSIH --}}
            <div>
                <div class="rounded-t-lg bg-primary-50 px-3 py-2 text-xs font-bold uppercase tracking-wide text-primary-700 dark:bg-primary-500/10 dark:text-primary-400">
                    Penjualan Bersih
                </div>
                <table class="w-full text-sm">
                    <tbody>
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-3">Total Penjualan</td>
                            <td class="py-2 pl-3 text-right tabular-nums">{{ $rupiah($result['grossSales']) }}</td>
                        </tr>
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-3">Pengembalian (Refund)</td>
                            <td class="py-2 pl-3 text-right tabular-nums">{{ $result['refund'] > 0 ? '(' . $rupiah($result['refund']) . ')' : '—' }}</td>
                        </tr>
                        <tr class="font-bold">
                            <td class="py-2 pr-3">Total Penjualan Bersih</td>
                            <td class="py-2 pl-3 text-right tabular-nums">{{ $rupiah($result['netSales']) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- LABA KOTOR --}}
            <div>
                <div class="rounded-t-lg bg-gray-100 px-3 py-2 text-xs font-bold uppercase tracking-wide text-gray-600 dark:bg-white/5 dark:text-gray-300">
                    Laba Kotor
                </div>
                <table class="w-full text-sm">
                    <tbody>
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-3">Penjualan Bersih</td>
                            <td class="py-2 pl-3 text-right tabular-nums">{{ $rupiah($result['netSales']) }}</td>
                        </tr>
                        <tr class="border-b border-gray-100 dark:border-white/5 text-gray-400 dark:text-gray-500">
                            <td class="py-2 pr-3 italic">HPP (Harga Pokok Penjualan)</td>
                            <td class="py-2 pl-3 text-right italic">Belum tersedia — biaya bahan belum dikaitkan per booking</td>
                        </tr>
                        <tr class="border-b border-gray-100 dark:border-white/5 text-gray-400 dark:text-gray-500">
                            <td class="py-2 pr-3 italic">Komisi Partner</td>
                            <td class="py-2 pl-3 text-right italic">Belum tersedia</td>
                        </tr>
                        <tr class="font-bold text-gray-400 dark:text-gray-500">
                            <td class="py-2 pr-3 italic">Total Laba Kotor</td>
                            <td class="py-2 pl-3 text-right italic">Belum tersedia — perlu HPP untuk akurat</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </x-filament::section>
    @endif
    </div>
</x-filament-panels::page>
