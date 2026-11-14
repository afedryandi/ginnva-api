<x-filament-panels::page>
    {{-- Panel memakai CSS Filament yang sudah dikompilasi, jadi class Tailwind yang tidak dipakai bawaan Filament tidak berefek. Gaya di bawah
         menggantikannya untuk halaman ini. Grid kartu memakai style inline di elemennya. --}}
    @once
        <style>
            .rf-num { font-variant-numeric: tabular-nums; }
            .rf-r { text-align: right; }
            .rf-danger { color: #b91c1c; } .dark .rf-danger { color: #f87171; }
            .rf-muted { color: #6b7280; } .dark .rf-muted { color: #9ca3af; }
            .rf-badge { display: inline-block; padding: 0 .5rem; border-radius: .375rem; font-size: .75rem; background: rgba(107, 114, 128, .15); }
            .rf-table th, .rf-table td { padding: .5rem .75rem; vertical-align: top; }
            .rf-table th { white-space: nowrap; }
            .rf-table th:first-child, .rf-table td:first-child { padding-left: 0; }
            .rf-table td.rf-nowrap { white-space: nowrap; }
            .rf-table tfoot td { font-weight: 700; border-top: 2px solid rgba(107, 114, 128, .5); }
            .rf-link:hover { text-decoration: underline; }
        </style>
    @endonce

    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $rupiah = fn ($n) => 'Rp' . number_format($n, 0, ',', '.');
        $filterTargets = 'data.from, data.to, data.store_id, data.preset';
        $financeOn = (bool) config('features.finance');
        $colspan = $financeOn ? 11 : 10;
    @endphp

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $filterTargets }}" class="space-y-6">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px">
            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Total Uang Dikembalikan</div>
                <div class="mt-1 text-2xl font-bold rf-num rf-danger">{{ $rupiah($result['totalAmount']) }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ number_format($result['totalCount'], 0, ',', '.') }} pengembalian.</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Refund Penjualan</div>
                <div class="mt-1 text-2xl font-bold rf-num">{{ $rupiah($result['salesAmount']) }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ number_format($result['salesCount'], 0, ',', '.') }} refund; mengurangi penjualan.</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Refund DP</div>
                <div class="mt-1 text-2xl font-bold rf-num">{{ $rupiah($result['dpAmount']) }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ number_format($result['dpCount'], 0, ',', '.') }} DP; bukan pengurang penjualan.</div>
            </x-filament::section>

            @if ($financeOn)
                <x-filament::section>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Kas Keluar</div>
                    <div class="mt-1 text-2xl font-bold rf-num">{{ $rupiah($result['totalCash']) }}</div>
                </x-filament::section>

                <x-filament::section>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Pengurang Piutang</div>
                    <div class="mt-1 text-2xl font-bold rf-num">{{ $rupiah($result['totalReceivableReduced']) }}</div>
                </x-filament::section>
            @else
                <x-filament::section>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Rata-rata per Pengembalian</div>
                    <div class="mt-1 text-2xl font-bold rf-num">{{ $rupiah($result['totalCount'] > 0 ? $result['totalAmount'] / $result['totalCount'] : 0) }}</div>
                </x-filament::section>
            @endif
        </div>

        @if ($result['totalCount'] > 0)
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px">
                <x-filament::section>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Menurut alasan</div>
                    <div class="mt-2 space-y-1 text-sm">
                        @foreach ($result['byReason'] as $reason => $count)
                            <div class="flex justify-between gap-4"><span>{{ $reason }}</span><span class="rf-num">{{ $count }}</span></div>
                        @endforeach
                    </div>
                </x-filament::section>

                @if ($result['byStore']->count() > 1)
                    <x-filament::section>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Menurut toko</div>
                        <div class="mt-2 space-y-1 text-sm">
                            @foreach ($result['byStore'] as $store => $sum)
                                <div class="flex justify-between gap-4"><span>{{ $store }}</span><span class="rf-num">{{ $rupiah($sum) }}</span></div>
                            @endforeach
                        </div>
                    </x-filament::section>
                @endif
            </div>
        @endif

        <x-filament::section>
            <x-slot name="heading">Daftar Pengembalian</x-slot>
            <x-slot name="description">
                @if ($financeOn)
                    Tiap refund penjualan punya jurnal kontra di Jurnal Umum; klik No. Jurnal untuk detailnya. Metode bisa berisi Kurangi Piutang kalau refund mengurangi Piutang Usaha dulu.
                @else
                    Pengembalian dana ke pelanggan pada rentang ini: refund penjualan (nominal, metode, alasan, pemroses, dan penyetuju) dan DP yang dikembalikan.
                @endif
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm rf-table">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th>No. Refund</th>
                            <th>Tanggal</th>
                            <th>Jenis</th>
                            <th>No. Booking</th>
                            <th>Pelanggan</th>
                            <th>Toko</th>
                            <th>Metode</th>
                            <th>Diproses Oleh</th>
                            <th>Disetujui Oleh</th>
                            @if ($financeOn)
                                <th>No. Jurnal</th>
                            @endif
                            <th>Alasan</th>
                            <th class="rf-r">Nominal</th>
                            <th class="rf-r">Sisa Setelah Refund</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($result['rows'] as $row)
                            @php $booking = $row['booking']; @endphp
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="font-medium rf-nowrap">{{ $row['number'] ?? '—' }}</td>
                                <td class="rf-num rf-nowrap">{{ $row['date']->format('d M Y H:i') }}</td>
                                <td class="rf-nowrap">{{ $row['kind'] }}</td>
                                <td class="rf-nowrap">
                                    @if ($booking)
                                        <a href="{{ $this->bookingUrl($booking->id) }}" class="rf-link" title="Lihat booking ini">{{ $booking->booking_number }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ $booking?->display_customer_name ?? '—' }}</td>
                                <td>{{ $booking?->store?->name ?? '—' }}</td>
                                <td>{{ $row['method_label'] }}</td>
                                <td>{{ $row['by'] ?? '—' }}</td>
                                <td>{{ $row['approved_by'] ?? '—' }}</td>
                                @if ($financeOn)
                                    <td class="rf-nowrap">
                                        @if ($row['journal'])
                                            <a
                                                href="{{ \App\Filament\Resources\JournalEntryResource::getUrl('index', ['tableSearch' => $row['journal']]) }}"
                                                class="font-mono rf-link"
                                            >{{ $row['journal'] }}</a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                @endif
                                <td>{{ $row['reason'] ?: '—' }}</td>
                                <td class="rf-r rf-num rf-nowrap rf-danger">{{ $rupiah($row['amount']) }}</td>
                                <td class="rf-r rf-num rf-nowrap">
                                    @if ($row['remaining'] === null)
                                        —
                                    @elseif ($row['remaining'] <= 0)
                                        <span class="rf-badge">Penuh</span>
                                    @else
                                        {{ $rupiah($row['remaining']) }}
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ $colspan + 2 }}" class="text-center text-gray-500 dark:text-gray-400" style="padding: 1rem 0">Tidak ada pengembalian pada rentang ini.</td></tr>
                        @endforelse
                    </tbody>
                    @if ($result['rows']->isNotEmpty())
                        <tfoot>
                            <tr>
                                <td colspan="{{ $colspan }}">Total ({{ number_format($result['totalCount'], 0, ',', '.') }} pengembalian)</td>
                                <td class="rf-r rf-num">{{ $rupiah($result['totalAmount']) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
            <p class="mt-3 text-xs rf-muted">"Penuh" berarti nilai transaksi booking itu sudah habis di-refund. Refund DP tidak mengurangi penjualan, jadi tidak punya sisa.</p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
