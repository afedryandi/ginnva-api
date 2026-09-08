<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $rupiah = fn ($n) => 'Rp' . number_format($n ?? 0, 0, ',', '.');
    @endphp

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Pelanggan Baru Daftar</div>
            <div class="mt-1 text-2xl font-bold tabular-nums">{{ number_format($result['newCustomers'], 0, ',', '.') }}</div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Terdaftar di rentang tanggal terpilih</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Pelanggan Repeat</div>
            <div class="mt-1 text-2xl font-bold tabular-nums">{{ number_format($result['repeatCount'], 0, ',', '.') }}</div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">&gt;1 booking berbayar sepanjang waktu</div>
        </x-filament::section>
    </div>

    <x-filament::section>
        <x-slot name="heading">Top 20 Pelanggan (Belanja Terbesar)</x-slot>
        <x-slot name="description">Berdasarkan booking berbayar dalam rentang tanggal terpilih — cuma pelanggan yang punya akun (tidak termasuk walk-in tanpa akun).</x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-3">Pelanggan</th>
                        <th class="py-2 pr-3">Kontak</th>
                        <th class="py-2 pr-3 text-right">Booking (Periode Ini)</th>
                        <th class="py-2 pr-3 text-right">Belanja (Periode Ini)</th>
                        <th class="py-2 pl-3 text-right">Total Booking (Sepanjang Waktu)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($result['topCustomers'] as $customer)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-3 font-medium">{{ $customer->name }}</td>
                            <td class="py-2 pr-3 text-xs text-gray-500 dark:text-gray-400">{{ $customer->phone_number ?? $customer->email ?? '—' }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $customer->bookings_in_period }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $rupiah($customer->spend_in_period) }}</td>
                            <td class="py-2 pl-3 text-right tabular-nums">{{ $customer->bookings_all_time }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-4 text-center text-gray-500 dark:text-gray-400">Belum ada pelanggan dengan booking berbayar pada rentang ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
