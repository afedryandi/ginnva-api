<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    <x-report-notices :notices="$this->getNotices()" />

    @php
        $result = $this->getResult();
        $sections = $result['sections'];
        $compare = $result['compare'] ?? null;
        $showDetails = $result['show_details'] ?? false;
        $rupiah = fn ($n) => ($n < 0 ? '(' : '') . 'Rp ' . number_format(abs($n), 0, ',', '.') . ($n < 0 ? ')' : '');
        $delta = function ($cur, $prev) {
            if ($prev === null || abs($prev) < 0.005) {
                return '—';
            }

            $pct = (($cur - $prev) / abs($prev)) * 100;

            return ($pct >= 0 ? '+' : '') . number_format($pct, 1, ',', '.') . '%';
        };

        // Peta label jenis arus kas => total pembanding, per seksi.
        $prevGroups = [];
        if ($compare) {
            foreach ($compare['sections'] as $key => $cs) {
                foreach ($cs['groups'] as $g) {
                    $prevGroups[$key][$g['label']] = $g['total'];
                }
            }
        }
    @endphp

    <x-filament::section>
        <x-slot name="heading">Laporan Arus Kas</x-slot>
        <x-slot name="description">
            Toko: {{ $result['store_label'] ?? 'Semua Toko' }} · {{ $result['from']->format('d M Y') }} – {{ $result['to']->format('d M Y') }}.
            Metode langsung — dari Jurnal Umum berstatus posted yang menyentuh akun kas (Kas di Tangan / Kas di Bank), dikelompokkan menurut jenis (akun lawan).
            @if ($compare)
                Kolom kiri = pembanding ({{ $result['compare_label'] }}), lalu selisih %, lalu periode ini.
            @endif
        </x-slot>

        <div class="space-y-6 text-sm">
            <div class="flex justify-between rounded-lg bg-gray-50 px-3 py-2 font-semibold dark:bg-white/5">
                <span>Saldo Kas Awal Periode</span>
                <span class="flex gap-4 tabular-nums">
                    @if ($compare)
                        <span class="text-xs font-normal text-gray-400">{{ $rupiah($compare['opening_cash']) }}</span>
                        <span class="text-xs font-normal">{{ $delta($result['opening_cash'], $compare['opening_cash']) }}</span>
                    @endif
                    <span>{{ $rupiah($result['opening_cash']) }}</span>
                </span>
            </div>

            @foreach ($sections as $key => $section)
                <div>
                    <div class="mb-1 font-semibold text-gray-700 dark:text-gray-300">{{ $section['label'] }}</div>

                    @forelse ($section['groups'] as $group)
                        <div class="flex justify-between border-b border-gray-100 py-1.5 pl-3 dark:border-white/5">
                            <span class="text-gray-700 dark:text-gray-200">{{ $group['label'] }}
                                <span class="text-xs text-gray-400">({{ $group['rows']->count() }} jurnal)</span>
                            </span>
                            <span class="flex gap-4 tabular-nums">
                                @if ($compare)
                                    @php $prev = $prevGroups[$key][$group['label']] ?? 0; @endphp
                                    <span class="text-xs text-gray-400">{{ $rupiah($prev) }}</span>
                                    <span class="text-xs">{{ $delta($group['total'], $prev) }}</span>
                                @endif
                                <span>{{ $rupiah($group['total']) }}</span>
                            </span>
                        </div>

                        @if ($showDetails)
                            @foreach ($group['rows'] as $row)
                                <div class="flex justify-between py-1 pl-8 text-xs text-gray-500 dark:text-gray-400">
                                    <span>
                                        {{ $row['entry_date']->format('d M') }} — {{ $row['description'] }}
                                        <a href="{{ \App\Filament\Resources\JournalEntryResource::getUrl('view', ['record' => $row['entry_id']]) }}" class="font-mono text-gray-400 hover:underline" title="Lihat jurnal">({{ $row['entry_number'] }})</a>
                                    </span>
                                    <span class="tabular-nums">{{ $rupiah($row['amount']) }}</span>
                                </div>
                            @endforeach
                        @endif
                    @empty
                        <p class="pl-3 text-xs text-gray-500 dark:text-gray-400">Tidak ada arus kas di kategori ini.</p>
                    @endforelse

                    <div class="flex justify-between border-t border-gray-200 pt-1.5 font-semibold dark:border-white/10">
                        <span>Total {{ $section['label'] }}</span>
                        <span class="flex gap-4 tabular-nums">
                            @if ($compare)
                                <span class="text-xs font-normal text-gray-400">{{ $rupiah($compare['sections'][$key]['total']) }}</span>
                                <span class="text-xs font-normal">{{ $delta($section['total'], $compare['sections'][$key]['total']) }}</span>
                            @endif
                            <span>{{ $rupiah($section['total']) }}</span>
                        </span>
                    </div>
                </div>
            @endforeach

            <div class="flex justify-between rounded-lg bg-gray-50 px-3 py-2 font-semibold dark:bg-white/5">
                <span>Kenaikan (Penurunan) Kas Bersih</span>
                <span class="flex gap-4 tabular-nums">
                    @if ($compare)
                        <span class="text-xs font-normal text-gray-400">{{ $rupiah($compare['net_change']) }}</span>
                        <span class="text-xs font-normal">{{ $delta($result['net_change'], $compare['net_change']) }}</span>
                    @endif
                    <span>{{ $rupiah($result['net_change']) }}</span>
                </span>
            </div>

            <div @class([
                'flex justify-between rounded-lg px-3 py-3 text-base font-bold',
                'bg-success-100 text-success-800 dark:bg-success-900 dark:text-success-200' => $result['closing_cash'] >= 0,
                'bg-danger-100 text-danger-800 dark:bg-danger-900 dark:text-danger-200' => $result['closing_cash'] < 0,
            ])>
                <span>Saldo Kas Akhir Periode</span>
                <span class="flex gap-4 tabular-nums">
                    @if ($compare)
                        <span class="text-xs font-normal opacity-70">{{ $rupiah($compare['closing_cash']) }}</span>
                        <span class="text-xs font-normal">{{ $delta($result['closing_cash'], $compare['closing_cash']) }}</span>
                    @endif
                    <span>{{ $rupiah($result['closing_cash']) }}</span>
                </span>
            </div>
        </div>

        {{-- Rincian per akun kas (untuk dicocokkan dengan rekening koran) --}}
        @if (! empty($result['cash_accounts']))
            <div class="mt-6">
                <div class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-300">Rincian per Akun Kas</div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <th class="py-2 pr-4">Akun Kas</th>
                                <th class="py-2 pr-4 text-right">Saldo Awal</th>
                                <th class="py-2 pr-4 text-right">Mutasi</th>
                                <th class="py-2 pr-4 text-right">Saldo Akhir</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($result['cash_accounts'] as $ca)
                                <tr class="border-b border-gray-100 dark:border-white/5">
                                    <td class="py-2 pr-4">
                                        <a href="{{ \App\Filament\Pages\GeneralLedgerReport::getUrl(['chart_of_account_id' => $ca['account']->id, 'from' => $result['from']->toDateString(), 'to' => $result['to']->toDateString(), 'store_id' => $this->data['store_id'] ?? null]) }}" class="hover:underline" title="Lihat Buku Besar akun ini">{{ $ca['account']->display_name }}</a>
                                    </td>
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ $rupiah($ca['opening']) }}</td>
                                    <td class="py-2 pr-4 text-right tabular-nums">{{ $rupiah($ca['mutation']) }}</td>
                                    <td class="py-2 pr-4 text-right font-semibold tabular-nums">{{ $rupiah($ca['closing']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-300 font-bold dark:border-white/20">
                                <td class="py-2 pr-4">Total</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $rupiah(collect($result['cash_accounts'])->sum('opening')) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $rupiah(collect($result['cash_accounts'])->sum('mutation')) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $rupiah(collect($result['cash_accounts'])->sum('closing')) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        @endif

        <div class="mt-6">
            @if ($result['is_reconciled'])
                <div class="rounded-lg border border-success-300 bg-success-50 p-3 text-sm text-success-700 dark:border-success-700 dark:bg-success-950 dark:text-success-300">
                    ✓ Sudah sesuai dengan saldo aktual akun kas ({{ $rupiah($result['closing_cash_actual']) }}).
                </div>
            @else
                <div class="rounded-lg border border-danger-300 bg-danger-50 p-3 text-sm text-danger-700 dark:border-danger-700 dark:bg-danger-950 dark:text-danger-300">
                    ✗ Saldo akhir hasil perhitungan ({{ $rupiah($result['closing_cash']) }}) berbeda dari saldo aktual akun kas ({{ $rupiah($result['closing_cash_actual']) }}) — periksa jurnal.
                </div>
            @endif

            @if (! empty($result['warnings']))
                <div class="mt-3 rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm text-warning-700 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-300">
                    <div class="font-semibold">Perlu diperiksa (klasifikasi arus kas):</div>
                    <ul class="mt-1 list-disc ps-5">
                        @foreach ($result['warnings'] as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-panels::page>
