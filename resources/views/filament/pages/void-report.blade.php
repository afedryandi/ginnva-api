<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $rupiah = fn ($n) => 'Rp' . number_format($n, 0, ',', '.');
    @endphp

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Booking Dibatalkan</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-danger-600 dark:text-danger-400">{{ number_format($result['totalCount'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Potensi Pendapatan Hilang</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-danger-600 dark:text-danger-400">{{ $rupiah($result['totalLostRevenue']) }}</div>
        </x-filament::section>
    </div>

    <x-filament::section>
        <x-slot name="heading">Daftar Booking Dibatalkan</x-slot>
        <x-slot name="description">
            Diambil dari histori aktivitas (log perubahan status), bukan kolom terpisah — "Dibatalkan Oleh" bisa "Sistem" kalau dibatalkan lewat proses otomatis.
            Kolom "Otorisasi" dan "Nama Meja" ala Majoo tidak ditampilkan — Ginnva tidak punya alur otorisasi void/meja restoran.
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-3">No. Booking</th>
                        <th class="py-2 pr-3">Tanggal Order</th>
                        <th class="py-2 pr-3">Tanggal Dibatalkan</th>
                        <th class="py-2 pr-3">Pelanggan</th>
                        <th class="py-2 pr-3">Toko</th>
                        <th class="py-2 pr-3">Layanan</th>
                        <th class="py-2 pr-3">Dibatalkan Oleh</th>
                        <th class="py-2 pl-3 text-right">Nilai Transaksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($result['events'] as $event)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-3 font-medium">{{ $event->subject->booking_number }}</td>
                            <td class="py-2 pr-3 tabular-nums">{{ optional($event->subject->created_at)->format('d M Y H:i') }}</td>
                            <td class="py-2 pr-3 tabular-nums">{{ $event->created_at->format('d M Y H:i') }}</td>
                            <td class="py-2 pr-3">{{ $event->subject->customer_name ?? '—' }}</td>
                            <td class="py-2 pr-3">{{ $event->subject->store?->name ?? '—' }}</td>
                            <td class="py-2 pr-3">
                                @if ($event->subject->product_kaca_film && $event->subject->product_ppf)
                                    Kaca Film + PPF
                                @elseif ($event->subject->product_ppf)
                                    PPF
                                @elseif ($event->subject->product_kaca_film)
                                    Kaca Film
                                @else
                                    —
                                @endif
                            </td>
                            <td class="py-2 pr-3">{{ $event->causer?->name ?? 'Sistem (otomatis)' }}</td>
                            <td class="py-2 pl-3 text-right tabular-nums">{{ $event->subject->transaction_amount > 0 ? $rupiah($event->subject->transaction_amount) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-4 text-center text-gray-500 dark:text-gray-400">Tidak ada booking dibatalkan pada rentang ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
