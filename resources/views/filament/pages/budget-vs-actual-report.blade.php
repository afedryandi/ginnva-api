<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $rows = $this->getRows();
        $rupiah = fn ($n) => ($n < 0 ? '-' : '') . 'Rp ' . number_format(abs($n), 0, ',', '.');
        $labels = ['lewat' => 'Melebihi anggaran', 'hampir' => 'Hampir habis', 'aman' => 'Aman', 'tanpa_anggaran' => 'Tanpa anggaran'];
        $totalBudget = $rows->sum('budget');
        $totalActual = $rows->sum('actual');
        $over = $rows->where('status', 'lewat')->count();
    @endphp

    <div wire:loading.class="opacity-50" wire:target="data" class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">
                @if ($rows->isEmpty())
                    Belum ada anggaran atau realisasi beban pada bulan ini
                @else
                    Anggaran {{ $rupiah($totalBudget) }} &middot; Realisasi {{ $rupiah($totalActual) }}
                    @if ($over > 0)<span class="ms-2 text-danger-600 dark:text-danger-400">{{ $over }} akun melebihi anggaran</span>@endif
                @endif
            </x-slot>
            <x-slot name="description">Realisasi dari jurnal yang sudah diposting. Hanya peringatan; transaksi tidak diblokir. Isi anggaran di menu Anggaran.</x-slot>

            @unless ($rows->isEmpty())
                <div class="overflow-x-auto">
                    <table class="w-full text-sm tabular-nums">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <th class="py-2 pe-4">Akun</th>
                                <th class="py-2 pe-4 text-right">Anggaran</th>
                                <th class="py-2 pe-4 text-right">Realisasi</th>
                                <th class="py-2 pe-4 text-right">Sisa</th>
                                <th class="py-2 pe-4 text-right">Terpakai</th>
                                <th class="py-2 pe-4">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                    <td class="py-2 pe-4 font-medium">{{ $row['account']->display_name }}</td>
                                    <td class="py-2 pe-4 text-right">{{ $row['budget'] > 0 ? $rupiah($row['budget']) : '—' }}</td>
                                    <td class="py-2 pe-4 text-right">{{ $rupiah($row['actual']) }}</td>
                                    <td class="py-2 pe-4 text-right">{{ $row['budget'] > 0 ? $rupiah($row['remaining']) : '—' }}</td>
                                    <td class="py-2 pe-4 text-right">{{ $row['percent'] !== null ? number_format($row['percent'], 0) . '%' : '—' }}</td>
                                    <td class="py-2 pe-4">
                                        <span @class([
                                            'rounded-md px-2 py-0.5 text-xs font-medium',
                                            'bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400' => $row['status'] === 'lewat',
                                            'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400' => in_array($row['status'], ['hampir', 'tanpa_anggaran'], true),
                                            'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' => $row['status'] === 'aman',
                                        ])>{{ $labels[$row['status']] }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endunless
        </x-filament::section>
    </div>
</x-filament-panels::page>
