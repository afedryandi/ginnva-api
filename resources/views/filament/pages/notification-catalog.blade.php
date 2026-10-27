<x-filament-panels::page>
    @php
        $summary = $this->getSummary();
        $groups = $this->getGroups();
        $labels = \App\Filament\Pages\NotificationCatalogPage::AUDIENCE_LABELS;
    @endphp

    <x-filament::section>
        <div class="grid gap-4 text-sm md:grid-cols-4">
            <div><div class="text-xs text-gray-500 dark:text-gray-400">Titik pengiriman</div><div class="text-2xl font-bold tabular-nums">{{ $summary['total'] }}</div></div>
            <div><div class="text-xs text-gray-500 dark:text-gray-400">Untuk pelanggan</div><div class="text-2xl font-bold tabular-nums">{{ $summary['customer'] }}</div></div>
            <div><div class="text-xs text-gray-500 dark:text-gray-400">Untuk staff</div><div class="text-2xl font-bold tabular-nums">{{ $summary['staff'] }}</div></div>
            <div>
                <div class="text-xs text-gray-500 dark:text-gray-400">Staff: push saja (tanpa lonceng panel)</div>
                <div class="text-2xl font-bold tabular-nums text-warning-600 dark:text-warning-400">{{ $summary['staff_push_only'] }}</div>
            </div>
        </div>
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
            Daftar ini hanya membaca. Pelanggan selalu mendapat baris di kotak masuk aplikasinya. Untuk staff, "push saja" berarti berkas pengirimnya tidak membuat notifikasi di lonceng panel,
            jadi pesan yang terlewat di ponsel tidak tersimpan. Aplikasi staff punya Kotak Masuk, tetapi baru memuat titik yang sudah memakai pengirim staff yang baru.
        </p>
    </x-filament::section>

    <x-filament::section>
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari judul atau berkas…" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-white/10 dark:bg-white/5" />
    </x-filament::section>

    @forelse ($groups as $audience => $entries)
        <x-filament::section :heading="($labels[$audience] ?? $audience) . ' (' . count($entries) . ')'">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pe-4">Judul / jenis</th>
                            <th class="py-2 pe-4">Sumber di kode</th>
                            <th class="py-2">{{ $audience === 'pelanggan' ? 'Kotak masuk' : 'Lonceng panel' }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entries as $entry)
                            <tr class="border-b border-gray-100 align-top last:border-0 dark:border-white/5">
                                <td class="py-2 pe-4 font-medium">{{ $entry['title'] }}</td>
                                <td class="py-2 pe-4 text-xs text-gray-500 dark:text-gray-400">{{ $entry['source'] }}</td>
                                <td class="py-2 text-xs">
                                    @if ($audience === 'pelanggan')
                                        <span class="text-success-600 dark:text-success-400">Ya</span>
                                    @elseif ($entry['panel_inbox'])
                                        <span class="text-success-600 dark:text-success-400">Ya (di berkas ini)</span>
                                    @else
                                        <span class="rounded-md bg-warning-50 px-2 py-0.5 font-semibold text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">Push saja</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @empty
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">Tidak ada yang cocok dengan pencarian.</p>
        </x-filament::section>
    @endforelse
</x-filament-panels::page>
