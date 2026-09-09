<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $rupiah = fn ($n) => 'Rp' . number_format($n, 0, ',', '.');
    @endphp

    <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Reservasi Dibuat</div>
            <div class="mt-1 text-2xl font-bold tabular-nums">{{ number_format($result['totalCreated'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Reservasi Selesai</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-success-600 dark:text-success-400">{{ number_format($result['totalCompleted'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Reservasi Dibatalkan</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-danger-600 dark:text-danger-400">{{ number_format($result['totalCancelled'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Tingkat Pembatalan</div>
            <div class="mt-1 text-2xl font-bold tabular-nums {{ $result['cancellationRate'] > 20 ? 'text-danger-600 dark:text-danger-400' : '' }}">{{ number_format($result['cancellationRate'], 1) }}%</div>
        </x-filament::section>
    </div>

    <x-filament-widgets::widgets
        :widgets="[\App\Filament\Widgets\ReservationPerformanceChart::class]"
        :columns="1"
    />

    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Reservasi Aktif (Confirmed + Pending)</div>
            <div class="mt-1 text-2xl font-bold tabular-nums">{{ number_format($result['totalCount'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Terkonfirmasi</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-success-600 dark:text-success-400">{{ number_format($result['confirmedCount'], 0, ',', '.') }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Menunggu Approval</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-warning-600 dark:text-warning-400">{{ number_format($result['pendingCount'], 0, ',', '.') }}</div>
        </x-filament::section>
    </div>

    <x-filament::section>
        <x-slot name="heading">Daftar Reservasi</x-slot>
        <x-slot name="description">
            Diurutkan berdasarkan tanggal diinginkan. Booking berstatus "completed" sudah tercover Laporan Penjualan,
            "cancelled" sudah tercover Laporan Void — tidak ditampilkan lagi di sini supaya tidak tumpang tindih
            (stat card "Dibuat/Selesai/Dibatalkan" di atas TETAP menghitung semua status, sesuai definisi Majoo).
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-3">No. Booking</th>
                        <th class="py-2 pr-3">Tanggal Buat</th>
                        <th class="py-2 pr-3">Tanggal Diinginkan</th>
                        <th class="py-2 pr-3">Durasi</th>
                        <th class="py-2 pr-3">Pelanggan</th>
                        <th class="py-2 pr-3">Toko</th>
                        <th class="py-2 pr-3">Layanan</th>
                        <th class="py-2 pr-3">Teknisi</th>
                        <th class="py-2 pr-3 text-right">Total Tagihan</th>
                        <th class="py-2 pl-3">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($result['bookings'] as $booking)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-3 font-medium">{{ $booking->booking_number }}</td>
                            <td class="py-2 pr-3 tabular-nums">{{ optional($booking->created_at)->format('d M Y') }}</td>
                            <td class="py-2 pr-3 tabular-nums">{{ $booking->preferred_date?->format('d M Y') }}</td>
                            <td class="py-2 pr-3">{{ $booking->duration_days }} hari</td>
                            <td class="py-2 pr-3">{{ $booking->customer_name ?? '—' }}</td>
                            <td class="py-2 pr-3">{{ $booking->store?->name ?? '—' }}</td>
                            <td class="py-2 pr-3">
                                @if ($booking->product_kaca_film && $booking->product_ppf)
                                    Kaca Film + PPF
                                @elseif ($booking->product_ppf)
                                    PPF
                                @elseif ($booking->product_kaca_film)
                                    Kaca Film
                                @else
                                    —
                                @endif
                            </td>
                            <td class="py-2 pr-3">{{ $booking->installers->pluck('name')->join(', ') ?: '—' }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $booking->transaction_amount ? $rupiah($booking->transaction_amount) : '—' }}</td>
                            <td class="py-2 pl-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $booking->status === 'confirmed' ? 'bg-success-100 text-success-700 dark:bg-success-500/10 dark:text-success-400' : 'bg-warning-100 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400' }}">
                                    {{ $booking->status === 'confirmed' ? 'Terkonfirmasi' : 'Menunggu Approval' }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="py-4 text-center text-gray-500 dark:text-gray-400">Tidak ada reservasi pada rentang ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
