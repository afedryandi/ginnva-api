<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $totals = $this->getTotals();
        $breakdown = $this->getBreakdown();
        $income = $breakdown->where('type', 'in');
        $expense = $breakdown->where('type', 'out');
        $rupiah = fn ($n) => 'Rp ' . number_format($n, 0, ',', '.');
    @endphp

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-filament::section>
            <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Pemasukan</div>
            <div class="mt-1 text-2xl font-bold text-success-600 dark:text-success-400">{{ $rupiah($totals['in']) }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Pengeluaran</div>
            <div class="mt-1 text-2xl font-bold text-danger-600 dark:text-danger-400">{{ $rupiah($totals['out']) }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Saldo Bersih</div>
            <div @class([
                'mt-1 text-2xl font-bold',
                'text-success-600 dark:text-success-400' => $totals['net'] >= 0,
                'text-danger-600 dark:text-danger-400' => $totals['net'] < 0,
            ])>{{ $rupiah($totals['net']) }}</div>
        </x-filament::section>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <x-filament::section>
            <x-slot name="heading">Rincian Pemasukan per Kategori</x-slot>

            @if ($income->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">Belum ada transaksi pemasukan di periode ini.</p>
            @else
                <ul class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($income as $row)
                        <li class="flex items-center justify-between py-2 text-sm">
                            <span class="text-gray-700 dark:text-gray-200">{{ $row['category'] }}</span>
                            <span class="font-semibold text-success-600 dark:text-success-400">{{ $rupiah($row['total']) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Rincian Pengeluaran per Kategori</x-slot>

            @if ($expense->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">Belum ada transaksi pengeluaran di periode ini.</p>
            @else
                <ul class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($expense as $row)
                        <li class="flex items-center justify-between py-2 text-sm">
                            <span class="text-gray-700 dark:text-gray-200">{{ $row['category'] }}</span>
                            <span class="font-semibold text-danger-600 dark:text-danger-400">{{ $rupiah($row['total']) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
