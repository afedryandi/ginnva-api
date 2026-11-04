<x-filament-panels::page>
    {{-- Panel memakai CSS Filament yang sudah dikompilasi, jadi class Tailwind yang tidak dipakai bawaan Filament tidak berefek. Gaya di bawah
         menggantikannya untuk halaman ini. Grid kartu memakai style inline di elemennya. --}}
    @once
        <style>
            .pb-num { font-variant-numeric: tabular-nums; }
            .pb-r { text-align: right; }
            .pb-warn { color: #b45309; } .dark .pb-warn { color: #fbbf24; }
            .pb-table th, .pb-table td { padding: .5rem .75rem; white-space: nowrap; vertical-align: top; }
            .pb-table th:first-child, .pb-table td:first-child { padding-left: 0; }
            .pb-table tfoot td { font-weight: 700; border-top: 2px solid rgba(107, 114, 128, .5); }
        </style>
    @endonce

    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $rupiah = fn ($n) => 'Rp' . number_format($n, 0, ',', '.');
        // Kolom Rekening hanya ada bila modul Keuangan menyala (saat mati memang tidak ada rekening).
        $financeOn = (bool) config('features.finance');
    @endphp

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="data.from, data.to, data.store_id, data.preset" class="space-y-6">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px">
            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Total uang masuk</div>
                <div class="mt-1 text-2xl font-bold pb-num">{{ $rupiah($result['total']) }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">DP yang sudah dikembalikan tidak dihitung.</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Menurut jenis</div>
                <div class="mt-2 space-y-1 text-sm">
                    @forelse ($result['byKind'] as $kind => $sum)
                        <div class="flex justify-between"><span>{{ $kind }}</span><span class="pb-num">{{ $rupiah($sum) }}</span></div>
                    @empty
                        <div class="text-gray-500 dark:text-gray-400">—</div>
                    @endforelse
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Menurut metode</div>
                <div class="mt-2 space-y-1 text-sm">
                    @forelse ($result['byMethod'] as $method => $sum)
                        <div class="flex justify-between"><span>{{ $method }}</span><span class="pb-num">{{ $rupiah($sum) }}</span></div>
                    @empty
                        <div class="text-gray-500 dark:text-gray-400">—</div>
                    @endforelse
                </div>
                <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">DP yang dicatat sebelum kolom metode ada tidak ikut dirinci per metode.</div>
            </x-filament::section>
        </div>

        <x-filament::section heading="Daftar pembayaran">
            @if ($result['rows']->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">Belum ada pembayaran pada rentang ini.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm pb-num pb-table">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <th>Tanggal</th>
                                <th>Jenis</th>
                                <th>Referensi</th>
                                <th>Pelanggan</th>
                                <th>Metode</th>
                                @if ($financeOn)
                                    <th>Rekening</th>
                                @endif
                                <th class="pb-r">Nominal</th>
                                <th>Catatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($result['rows'] as $row)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td>{{ $row['date']?->format('d M Y') }}</td>
                                    <td>{{ $row['kind'] }}</td>
                                    <td>
                                        @if ($row['booking_id'])
                                            <a href="{{ $this->bookingUrl($row['booking_id']) }}" class="hover:underline" title="Buka booking ini">{{ $row['reference'] }}</a>
                                        @else
                                            {{ $row['reference'] }}
                                        @endif
                                    </td>
                                    <td>{{ $row['customer'] }}</td>
                                    <td>{{ $row['method'] }}</td>
                                    @if ($financeOn)
                                        <td>{{ $row['account'] }}</td>
                                    @endif
                                    <td class="pb-r">{{ $rupiah($row['amount']) }}</td>
                                    <td>@if ($row['note'])<span class="text-xs pb-warn">{{ $row['note'] }}</span>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="{{ $financeOn ? 6 : 5 }}">Total</td>
                                <td class="pb-r">{{ $rupiah($result['total']) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">Pengembalian dana (refund) tidak termasuk di sini; lihat Laporan Refund.</p>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
