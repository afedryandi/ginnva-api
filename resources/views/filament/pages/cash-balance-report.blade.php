<x-filament-panels::page>
    @php
        $rows = $this->getRows();
        $rupiah = fn ($n) => ($n < 0 ? '-' : '') . 'Rp ' . number_format(abs($n), 0, ',', '.');
        $total = $rows->sum('balance');
    @endphp

    <x-filament::section>
        <x-slot name="heading">Total kas dan bank: {{ $rupiah($total) }}</x-slot>
        <x-slot name="description">
            Saldo sampai hari ini dari jurnal yang sudah diposting. Akun kas yang belum punya rekening (mis. Kas di Tangan lama) tetap dihitung; pindahkan saldonya lewat menu Pemindahan Kas.
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm tabular-nums">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pe-4">Akun</th>
                        <th class="py-2 pe-4">Rekening</th>
                        <th class="py-2 pe-4">Toko</th>
                        <th class="py-2 pe-4 text-right">Saldo</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                            <td class="py-2 pe-4 font-medium">{{ $row['account']->display_name }}</td>
                            <td class="py-2 pe-4">
                                @if ($row['payment'])
                                    {{ $row['payment']->name }}
                                    @if (! $row['payment']->is_active)<span class="text-xs text-gray-500">(nonaktif)</span>@endif
                                @else
                                    <span class="text-xs text-gray-500 dark:text-gray-400">Belum terhubung ke rekening</span>
                                @endif
                            </td>
                            <td class="py-2 pe-4">{{ $row['payment']?->store?->name ?? ($row['payment'] ? 'Pusat' : '—') }}</td>
                            <td class="py-2 pe-4 text-right">{{ $rupiah($row['balance']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
