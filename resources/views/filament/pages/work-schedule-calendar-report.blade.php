<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $calendar = $this->getCalendar();
        $filterTargets = 'data.weekOf, data.store_id';
    @endphp

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $filterTargets }}">
        @if ($calendar['rows']->isEmpty())
            <x-filament::section>
                <div class="flex flex-col items-center gap-2 py-10 text-center">
                    <x-heroicon-o-calendar class="h-10 w-10 text-gray-300 dark:text-gray-600" />
                    <p class="text-sm font-medium text-gray-600 dark:text-gray-300">Belum ada karyawan aktif untuk cabang ini, atau pilih cabang dulu.</p>
                </div>
            </x-filament::section>
        @else
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex flex-wrap items-center gap-3">
                        <button type="button" wire:click="shiftWeek(-7)" class="rounded-lg border border-gray-300 px-2 py-1 text-xs hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-white/5" aria-label="Minggu sebelumnya">&larr; Sebelumnya</button>
                        <span>Minggu {{ $calendar['weekStart']->format('d M') }} – {{ $calendar['weekStart']->copy()->endOfWeek(\Carbon\Carbon::SUNDAY)->format('d M Y') }}</span>
                        <button type="button" wire:click="shiftWeek(7)" class="rounded-lg border border-gray-300 px-2 py-1 text-xs hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-white/5" aria-label="Minggu berikutnya">Berikutnya &rarr;</button>
                    </div>
                </x-slot>
                <x-slot name="description">
                    Berdasarkan Jadwal Kerja yang sedang aktif per karyawan. "—" artinya belum ada Jadwal Kerja ter-assign untuk tanggal itu. Klik sel untuk override 1 hari itu saja (mis. tukar shift dadakan) tanpa mengubah template.
                </x-slot>

                <div class="mb-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600 dark:text-gray-400">
                    <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-amber-500"></span> Override 1 hari</span>
                    <span class="inline-flex items-center gap-1"><span class="rounded bg-sky-100 px-1 text-sky-700 dark:bg-sky-500/20 dark:text-sky-300">CUTI</span> Izin/Cuti disetujui</span>
                    <span class="inline-flex items-center gap-1"><span class="rounded bg-rose-100 px-1 text-rose-700 dark:bg-rose-500/20 dark:text-rose-300">Tutup</span> Toko tutup</span>
                    <span class="inline-flex items-center gap-1"><span class="text-gray-500">Libur</span> Libur menurut jadwal</span>
                    <span class="inline-flex items-center gap-1"><span class="text-gray-400">—</span> Belum ada jadwal</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                <th class="py-2 pr-4 sticky left-0 bg-white dark:bg-gray-900">Karyawan</th>
                                @foreach ($calendar['days'] as $date)
                                    <th class="py-2 px-3 text-center whitespace-nowrap">
                                        {{ \App\Models\WorkSchedule::DAY_LABELS[\App\Models\WorkSchedule::DAYS[$loop->index]] }}
                                        <div class="font-normal normal-case text-gray-400">{{ $date->format('d/m') }}</div>
                                        @if (($calendar['closedDays'][$loop->index] ?? false))
                                            <div class="mt-0.5 inline-block rounded bg-rose-100 px-1 text-[10px] font-medium normal-case text-rose-700 dark:bg-rose-500/20 dark:text-rose-300">Tutup</div>
                                        @endif
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($calendar['rows'] as $row)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="py-2 pr-4 font-medium whitespace-nowrap sticky left-0 bg-white dark:bg-gray-900">{{ $row['employee']->name }}</td>
                                    @foreach ($row['cells'] as $i => $cell)
                                        <td
                                            wire:click="mountAction('overrideDay', { userId: {{ $row['employee']->id }}, date: '{{ $calendar['days'][$i]->toDateString() }}' })"
                                            wire:keydown.enter="mountAction('overrideDay', { userId: {{ $row['employee']->id }}, date: '{{ $calendar['days'][$i]->toDateString() }}' })"
                                            tabindex="0"
                                            role="button"
                                            aria-label="Ubah jadwal {{ $row['employee']->name }}, {{ $calendar['days'][$i]->translatedFormat('l d M') }}"
                                            class="relative py-2 px-3 text-center whitespace-nowrap cursor-pointer hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:hover:bg-white/5"
                                            title="{{ $cell['reason'] ?? 'Klik untuk ubah jadwal hari ini' }}"
                                        >
                                            @if ($cell['is_override'] ?? false)
                                                <span class="absolute right-1 top-1 h-1.5 w-1.5 rounded-full bg-amber-500" title="Override aktif"></span>
                                            @endif
                                            @if ($cell['color'] ?? null)
                                                <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium" style="background-color: {{ $cell['color'] }}22; color: {{ $cell['color'] }};">
                                                    {{ $cell['label'] }}
                                                </span>
                                            @elseif ($cell['label'] === 'Libur')
                                                @if ($cell['is_override'] ?? false)
                                                    <span class="text-xs font-medium text-amber-600 dark:text-amber-400">Libur (khusus)</span>
                                                @else
                                                    <span class="text-xs text-gray-500 dark:text-gray-400">Libur</span>
                                                @endif
                                            @elseif ($cell['label'] === '—')
                                                <span class="text-xs text-gray-400 dark:text-gray-500">—</span>
                                            @else
                                                <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-300">
                                                    {{ $cell['label'] }}
                                                </span>
                                            @endif
                                            @if (($cell['time'] ?? null))
                                                <div class="mt-0.5 text-[10px] text-gray-400">{{ $cell['time'] }}</div>
                                            @endif
                                            @if (($cell['leave'] ?? null))
                                                <div class="mt-0.5 inline-block rounded bg-sky-100 px-1 text-[10px] font-medium text-sky-700 dark:bg-sky-500/20 dark:text-sky-300">{{ $cell['leave'] }}</div>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
