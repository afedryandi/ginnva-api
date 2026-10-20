<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $rupiah = fn ($n) => 'Rp' . number_format($n, 0, ',', '.');
    @endphp

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="data.from, data.to, data.store_id" class="space-y-6">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Total uang masuk</div>
                <div class="mt-1 text-2xl font-bold tabular-nums">{{ $rupiah($result['total']) }}</div>
                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">DP yang sudah dikembalikan tidak dihitung.</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Menurut jenis</div>
                <div class="mt-2 space-y-1 text-sm">
                    @forelse ($result['byKind'] as $kind => $sum)
                        <div class="flex justify-between"><span>{{ $kind }}</span><span class="tabular-nums">{{ $rupiah($sum) }}</span></div>
                    @empty
                        <div class="text-gray-500 dark:text-gray-400">—</div>
                    @endforelse
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-xs text-gray-500 dark:text-gray-400">Menurut metode (pembayaran saat selesai)</div>
                <div class="mt-2 space-y-1 text-sm">
                    @forelse ($result['byMethod'] as $method => $sum)
                        <div class="flex justify-between"><span>{{ $method }}</span><span class="tabular-nums">{{ $rupiah($sum) }}</span></div>
                    @empty
                        <div class="text-gray-500 dark:text-gray-400">—</div>
                    @endforelse
                </div>
            </x-filament::section>
        </div>

        <x-filament::section heading="Daftar pembayaran">
            @if ($result['rows']->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">Belum ada pembayaran pada rentang ini.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm tabular-nums">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <th class="py-2 pe-4">Tanggal</th>
                                <th class="py-2 pe-4">Jenis</th>
                                <th class="py-2 pe-4">Referensi</th>
                                <th class="py-2 pe-4">Pelanggan</th>
                                <th class="py-2 pe-4">Metode</th>
                                <th class="py-2 pe-4">Rekening</th>
                                <th class="py-2 text-right">Nominal</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($result['rows'] as $row)
                                <tr class="border-b border-gray-100 align-top last:border-0 dark:border-white/5">
                                    <td class="py-2 pe-4">{{ $row['date']?->format('d M Y') }}</td>
                                    <td class="py-2 pe-4">{{ $row['kind'] }}@if ($row['note']) <span class="text-xs text-warning-600 dark:text-warning-400">({{ $row['note'] }})</span>@endif</td>
                                    <td class="py-2 pe-4">{{ $row['reference'] }}</td>
                                    <td class="py-2 pe-4">{{ $row['customer'] }}</td>
                                    <td class="py-2 pe-4">{{ $row['method'] }}</td>
                                    <td class="py-2 pe-4">{{ $row['account'] }}</td>
                                    <td class="py-2 text-right">{{ $rupiah($row['amount']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
