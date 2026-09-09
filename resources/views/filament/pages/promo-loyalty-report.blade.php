<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $vouchers = $result['vouchers'];
        $rewards = $result['rewards'];
        $points = $result['points'];
        $rupiah = fn ($n) => 'Rp' . number_format($n, 0, ',', '.');
    @endphp

    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Transaksi dengan Promo</div>
            <div class="mt-1 text-2xl font-bold tabular-nums">{{ number_format($result['promoTransactionCount'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Nilai Promo</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-danger-600 dark:text-danger-400">({{ $rupiah($result['promoValue']) }})</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Penjualan dengan Promo</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-success-600 dark:text-success-400">{{ $rupiah($result['promoSalesTotal']) }}</div>
        </x-filament::section>
    </div>

    <x-filament-widgets::widgets
        :widgets="[\App\Filament\Widgets\PromoValueChart::class]"
        :columns="1"
    />

    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Poin Customer Diterbitkan</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-success-600 dark:text-success-400">+{{ number_format($points['issued_customer'], 0, ',', '.') }}</div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Dipakai: -{{ number_format($points['spent_customer'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Poin Partner Diterbitkan</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-success-600 dark:text-success-400">+{{ number_format($points['issued_partner'], 0, ',', '.') }}</div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Dipakai: -{{ number_format($points['spent_partner'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Klaim Reward</div>
            <div class="mt-1 text-2xl font-bold tabular-nums">{{ number_format($result['totalRedemptions'], 0, ',', '.') }}</div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Customer &amp; Partner, periode terpilih</div>
        </x-filament::section>
    </div>

    <x-filament::section>
        <x-slot name="heading">Detail Transaksi Promo</x-slot>
        <x-slot name="description">Tiap baris = 1 voucher yang benar-benar dipakai di 1 booking.</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-3">Tanggal</th>
                        <th class="py-2 pr-3">Promo</th>
                        <th class="py-2 pr-3">No. Booking</th>
                        <th class="py-2 pr-3">Toko</th>
                        <th class="py-2 pl-3 text-right">Nilai</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($result['usedClaims'] as $claim)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-3 tabular-nums">{{ optional($claim->used_at)->format('d M Y') }}</td>
                            <td class="py-2 pr-3">{{ $claim->voucher?->name ?? '—' }}</td>
                            <td class="py-2 pr-3">{{ $claim->booking?->booking_number ?? '—' }}</td>
                            <td class="py-2 pr-3">{{ $claim->booking?->store?->name ?? '—' }}</td>
                            <td class="py-2 pl-3 text-right tabular-nums">({{ $rupiah((float) ($claim->voucher->discount_amount ?? 0)) }})</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-4 text-center text-gray-500 dark:text-gray-400">Tidak ada promo dipakai pada rentang ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Performa Voucher</x-slot>
        <x-slot name="description">Diklaim & dipakai dalam rentang tanggal terpilih — bukan status stok sekarang.</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-3">Voucher</th>
                        <th class="py-2 pr-3 text-right">Diklaim</th>
                        <th class="py-2 pr-3 text-right">Dipakai</th>
                        <th class="py-2 pr-3 text-right">Tingkat Pakai</th>
                        <th class="py-2 pr-3 text-right">Sisa Stok</th>
                        <th class="py-2 pl-3">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($vouchers as $voucher)
                        @php
                            $rate = $voucher->claimed_in_period > 0
                                ? round(($voucher->used_in_period / $voucher->claimed_in_period) * 100, 1)
                                : null;
                        @endphp
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-3 font-medium">{{ $voucher->name }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $voucher->claimed_in_period }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $voucher->used_in_period }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $rate === null ? '—' : $rate . '%' }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $voucher->remainingStock() }}</td>
                            <td class="py-2 pl-3">
                                @if ($voucher->is_active)
                                    <span class="fi-badge inline-flex items-center rounded-full bg-success-50 px-2 py-0.5 text-xs font-medium text-success-700 dark:bg-success-500/10 dark:text-success-400">Aktif</span>
                                @else
                                    <span class="fi-badge inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-white/5 dark:text-gray-400">Nonaktif</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-4 text-center text-gray-500 dark:text-gray-400">Belum ada voucher.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Performa Reward</x-slot>
        <x-slot name="description">Ditukar dalam rentang tanggal terpilih — poin_cost mencerminkan harga saat ini, bukan harga saat ditukar.</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-3">Reward</th>
                        <th class="py-2 pr-3 text-right">Ditukar</th>
                        <th class="py-2 pr-3 text-right">Terpenuhi</th>
                        <th class="py-2 pr-3 text-right">Poin Terpakai</th>
                        <th class="py-2 pl-3 text-right">Sisa Stok</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rewards as $reward)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-3 font-medium">{{ $reward->name }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $reward->redeemed_in_period }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $reward->fulfilled_in_period }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ number_format($reward->points_spent_in_period ?? 0, 0, ',', '.') }}</td>
                            <td class="py-2 pl-3 text-right tabular-nums">{{ $reward->stock === null ? '∞' : $reward->stock }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-4 text-center text-gray-500 dark:text-gray-400">Belum ada reward.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
