<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $checks = $this->getChecks();
        $rupiah = fn ($n) => ($n < 0 ? '-' : '') . 'Rp ' . number_format(abs($n), 0, ',', '.');
        $problems = $checks->reject(fn ($c) => $c['ok'])->count();
    @endphp

    <div wire:loading.class="opacity-50" wire:target="data.store_id" class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">
                @if ($problems === 0)
                    Semua sub-buku cocok dengan Buku Besar
                @else
                    {{ $problems }} dari {{ $checks->count() }} pemeriksaan berselisih
                @endif
            </x-slot>
            <x-slot name="description">
                Saldo akun di Buku Besar sampai hari ini dibandingkan dengan total sub-buku keadaan sekarang. Selisih belum tentu galat: bisa dari saldo awal yang diinput tanpa jurnal, atau pemakaian bahan yang belum dijurnal sebagai HPP. Laporan ini tidak mengubah data apa pun.
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm tabular-nums">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pe-4">Pemeriksaan</th>
                            <th class="py-2 pe-4 text-right">Buku Besar</th>
                            <th class="py-2 pe-4 text-right">Sub-buku</th>
                            <th class="py-2 pe-4 text-right">Selisih</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($checks as $check)
                            <tr class="border-b border-gray-100 align-top last:border-0 dark:border-white/5">
                                <td class="py-2 pe-4">
                                    <div class="font-medium">{{ $check['label'] }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $check['account'] }}</div>
                                    @if (! $check['ok'] && $check['note'])
                                        <div class="mt-1 text-xs text-warning-700 dark:text-warning-400">{{ $check['note'] }}</div>
                                    @endif
                                </td>
                                <td class="py-2 pe-4 text-right">{{ $rupiah($check['ledger']) }}</td>
                                <td class="py-2 pe-4 text-right">{{ $rupiah($check['subledger']) }}</td>
                                <td class="py-2 pe-4 text-right">
                                    @if ($check['ok'])
                                        <span class="text-success-600 dark:text-success-400">Cocok</span>
                                    @else
                                        <span class="rounded-md bg-warning-50 px-2 py-0.5 font-semibold text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">{{ $rupiah($check['difference']) }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
