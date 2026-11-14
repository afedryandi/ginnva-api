<x-filament-panels::page>
    {{-- Panel memakai CSS Filament yang sudah dikompilasi, jadi class Tailwind yang tidak dipakai bawaan Filament tidak berefek. Gaya di bawah
         menggantikannya untuk halaman ini. Grid kartu memakai style inline di elemennya. --}}
    @once
        <style>
            .vr-num { font-variant-numeric: tabular-nums; }
            .vr-r { text-align: right; }
            .vr-danger { color: #b91c1c; } .dark .vr-danger { color: #f87171; }
            .vr-warn { color: #b45309; font-weight: 600; } .dark .vr-warn { color: #fbbf24; }
            .vr-table th, .vr-table td { padding: .5rem .75rem; vertical-align: top; }
            .vr-table th { white-space: nowrap; }
            .vr-table th:first-child, .vr-table td:first-child { padding-left: 0; }
            .vr-table td.vr-nowrap { white-space: nowrap; }
            .vr-table tfoot td { font-weight: 700; border-top: 2px solid rgba(107, 114, 128, .5); }
            .vr-link:hover { text-decoration: underline; }
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

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $filterTargets }}" class="space-y-6">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px">
            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Booking Dibatalkan</div>
                <div class="mt-1 text-2xl font-bold vr-num vr-danger">{{ number_format($result['totalCount'], 0, ',', '.') }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ number_format($result['noShowCount'], 0, ',', '.') }} di antaranya pelanggan tidak datang.</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Potensi Pendapatan Hilang</div>
                <div class="mt-1 text-2xl font-bold vr-num vr-danger">{{ $rupiah($result['totalLostRevenue']) }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Dari nilai transaksi booking; yang belum bernilai dihitung Rp0.</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">DP Belum Dikembalikan</div>
                <div class="mt-1 text-2xl font-bold vr-num {{ $result['openDpTotal'] > 0 ? 'vr-warn' : '' }}">{{ $rupiah($result['openDpTotal']) }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    @if ($result['openDpCount'] > 0)
                        Ada {{ $result['openDpCount'] }} booking batal yang DP-nya belum dikembalikan; cek kolom DP di bawah.
                    @else
                        Semua DP booking batal sudah dikembalikan.
                    @endif
                </div>
            </x-filament::section>
        </div>

        @if ($result['totalCount'] > 0)
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px">
                <x-filament::section>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Menurut alasan</div>
                    <div class="mt-2 space-y-1 text-sm">
                        @foreach ($result['byReason'] as $reason => $count)
                            <div class="flex justify-between gap-4"><span>{{ $reason }}</span><span class="vr-num">{{ $count }}</span></div>
                        @endforeach
                    </div>
                </x-filament::section>

                <x-filament::section>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Dibatalkan oleh</div>
                    <div class="mt-2 space-y-1 text-sm">
                        @foreach ($result['byActor'] as $actor => $count)
                            <div class="flex justify-between gap-4"><span>{{ $actor }}</span><span class="vr-num">{{ $count }}</span></div>
                        @endforeach
                    </div>
                </x-filament::section>
            </div>
        @endif

        <x-filament::section>
            <x-slot name="heading">Daftar Booking Dibatalkan</x-slot>
            <x-slot name="description">
                Booking yang berstatus Cancelled pada rentang ini, termasuk pelanggan yang tidak datang. "Dibatalkan Oleh" bisa "Sistem" kalau dibatalkan lewat proses otomatis.
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm vr-table">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th>No. Booking</th>
                            <th>Tanggal Order</th>
                            <th>Tanggal Dibatalkan</th>
                            <th>Jenis</th>
                            <th>Pelanggan</th>
                            <th>Toko</th>
                            <th>Layanan</th>
                            <th>Dibatalkan Oleh</th>
                            <th>Alasan</th>
                            <th class="vr-r">Nilai Transaksi</th>
                            <th class="vr-r">DP Belum Dikembalikan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($result['rows'] as $row)
                            @php $booking = $row['booking']; @endphp
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="font-medium vr-nowrap">
                                    <a href="{{ $this->bookingUrl($booking->id) }}" class="vr-link" title="Lihat booking ini">{{ $booking->booking_number }}</a>
                                </td>
                                <td class="vr-num vr-nowrap">{{ optional($booking->created_at)->format('d M Y H:i') }}</td>
                                <td class="vr-num vr-nowrap">{{ $row['cancelled_at']->format('d M Y H:i') }}</td>
                                <td class="vr-nowrap">{{ $row['kind'] }}</td>
                                <td>{{ $booking->display_customer_name }}</td>
                                <td>{{ $booking->store?->name ?? '—' }}</td>
                                <td>{{ $booking->salesProductLabel() ?? '—' }}</td>
                                <td>{{ $row['by'] }}</td>
                                <td>{{ $row['reason'] ?? '—' }}</td>
                                <td class="vr-r vr-num vr-nowrap">{{ $row['amount'] > 0 ? $rupiah($row['amount']) : '—' }}</td>
                                <td class="vr-r vr-num vr-nowrap {{ $row['dp_open'] > 0 ? 'vr-warn' : '' }}">{{ $row['dp_open'] > 0 ? $rupiah($row['dp_open']) : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="11" class="text-center text-gray-500 dark:text-gray-400" style="padding: 1rem 0">Tidak ada booking dibatalkan pada rentang ini.</td></tr>
                        @endforelse
                    </tbody>
                    @if ($result['rows']->isNotEmpty())
                        <tfoot>
                            <tr>
                                <td colspan="9">Total ({{ number_format($result['totalCount'], 0, ',', '.') }} booking)</td>
                                <td class="vr-r vr-num">{{ $rupiah($result['totalLostRevenue']) }}</td>
                                <td class="vr-r vr-num">{{ $rupiah($result['openDpTotal']) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
