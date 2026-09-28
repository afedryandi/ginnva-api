<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    <x-report-notices :notices="$this->getNotices()" />

    @php
        $result = $this->getResult();
        $sections = $result['sections'];
        $compare = $result['compare'] ?? null;
        $rupiah = fn ($n) => ($n < 0 ? '(' : '') . 'Rp ' . number_format(abs($n), 0, ',', '.') . ($n < 0 ? ')' : '');
        $drill = fn ($account) => $this->ledgerUrl($account->id);
        // Dasar persentase (common-size) & margin: total pendapatan periode ini.
        $base = (float) $sections['pendapatan']['total'];

        // Peta id akun => nilai periode pembanding (lintas seksi).
        $prevMap = null;
        if ($compare) {
            $prevMap = [];
            foreach ($compare['sections'] as $cs) {
                foreach ($cs['rows'] as $r) {
                    $prevMap[$r['account']->id] = $r['amount'];
                }
            }
        }

        $delta = function ($cur, $prev) {
            if ($prev === null || abs($prev) < 0.005) {
                return '—';
            }

            $pct = (($cur - $prev) / abs($prev)) * 100;

            return ($pct >= 0 ? '+' : '') . number_format($pct, 1, ',', '.') . '%';
        };
    @endphp

    <x-filament::section>
        <x-slot name="heading">Laporan Laba Rugi</x-slot>
        <x-slot name="description">
            Toko: {{ $result['store_label'] ?? 'Semua Toko' }} · {{ $result['from']->format('d M Y') }} – {{ $result['to']->format('d M Y') }}.
            Dari Jurnal Umum berstatus posted dalam rentang tanggal yang dipilih. Klik nama akun untuk melihat rincian di Buku Besar; persentase kecil di kanan = porsi terhadap total pendapatan (untuk baris laba = margin).
            @if ($compare)
                Kolom kiri = pembanding ({{ $result['compare_label'] }}), lalu selisih %, lalu periode ini.
            @endif
        </x-slot>

        <div class="space-y-6 text-sm">
            @foreach ([
                ['pendapatan', 'Total Pendapatan', 'text-success-700 dark:text-success-400'],
                ['beban_pokok', 'Total HPP', 'text-danger-700 dark:text-danger-400'],
            ] as [$key, $totalLabel, $color])
                <div>
                    <div class="mb-1 font-semibold {{ $color }}">{{ $sections[$key]['label'] }}</div>
                    @if ($sections[$key]['rows']->isEmpty())
                        <p class="pl-3 text-xs text-gray-500 dark:text-gray-400">Tidak ada transaksi.</p>
                    @else
                        @include('filament.pages.partials.report-lines', ['rows' => $sections[$key]['rows'], 'key' => 'amount', 'rupiah' => $rupiah, 'prevMap' => $prevMap, 'drill' => $drill])
                    @endif
                    <div class="flex justify-between border-t border-gray-200 pt-1.5 font-semibold dark:border-white/10">
                        <span>{{ $totalLabel }}</span>
                        <span class="flex gap-4 tabular-nums">
                            @if ($compare)
                                <span class="text-xs font-normal text-gray-400">{{ $rupiah($compare['sections'][$key]['total']) }}</span>
                                <span class="text-xs font-normal">{{ $delta($sections[$key]['total'], $compare['sections'][$key]['total']) }}</span>
                            @endif
                            <span>{{ $rupiah($sections[$key]['total']) }}</span>
                        </span>
                    </div>
                </div>

                @if ($key === 'beban_pokok')
                    @include('filament.pages.partials.profit-row', ['label' => 'Laba Kotor', 'cur' => $result['laba_kotor'], 'prev' => $compare['laba_kotor'] ?? null, 'rupiah' => $rupiah, 'delta' => $delta])
                @endif
            @endforeach

            {{-- Beban Operasional --}}
            <div>
                <div class="mb-1 font-semibold text-danger-700 dark:text-danger-400">{{ $sections['beban_operasional']['label'] }}</div>
                @if ($sections['beban_operasional']['rows']->isEmpty())
                    <p class="pl-3 text-xs text-gray-500 dark:text-gray-400">Tidak ada transaksi.</p>
                @else
                    @include('filament.pages.partials.report-lines', ['rows' => $sections['beban_operasional']['rows'], 'key' => 'amount', 'rupiah' => $rupiah, 'prevMap' => $prevMap, 'drill' => $drill])
                @endif
                <div class="flex justify-between border-t border-gray-200 pt-1.5 font-semibold dark:border-white/10">
                    <span>Total Beban Operasional</span>
                    <span class="flex gap-4 tabular-nums">
                        @if ($compare)
                            <span class="text-xs font-normal text-gray-400">{{ $rupiah($compare['sections']['beban_operasional']['total']) }}</span>
                            <span class="text-xs font-normal">{{ $delta($sections['beban_operasional']['total'], $compare['sections']['beban_operasional']['total']) }}</span>
                        @endif
                        <span>{{ $rupiah($sections['beban_operasional']['total']) }}</span>
                    </span>
                </div>
            </div>

            @include('filament.pages.partials.profit-row', ['label' => 'Laba Operasional', 'cur' => $result['laba_operasional'], 'prev' => $compare['laba_operasional'] ?? null, 'rupiah' => $rupiah, 'delta' => $delta])

            {{-- Pendapatan & Beban Lain-lain --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                @foreach (['pendapatan_lain', 'beban_lain'] as $key)
                    <div>
                        <div class="mb-1 font-semibold text-gray-700 dark:text-gray-300">{{ $sections[$key]['label'] }}</div>
                        @if ($sections[$key]['rows']->isEmpty())
                            <p class="pl-3 text-xs text-gray-500 dark:text-gray-400">Tidak ada transaksi.</p>
                        @else
                            @include('filament.pages.partials.report-lines', ['rows' => $sections[$key]['rows'], 'key' => 'amount', 'rupiah' => $rupiah, 'prevMap' => $prevMap, 'drill' => $drill])
                        @endif
                        <div class="flex justify-between pt-1.5 font-semibold">
                            <span>Total</span>
                            <span class="tabular-nums">{{ $rupiah($sections[$key]['total']) }}</span>
                        </div>
                    </div>
                @endforeach
            </div>

            @include('filament.pages.partials.profit-row', ['label' => 'Laba Sebelum Pajak', 'cur' => $result['laba_sebelum_pajak'], 'prev' => $compare['laba_sebelum_pajak'] ?? null, 'rupiah' => $rupiah, 'delta' => $delta])

            {{-- Pajak --}}
            <div>
                <div class="mb-1 font-semibold text-gray-700 dark:text-gray-300">{{ $sections['pajak']['label'] }}</div>
                @if ($sections['pajak']['rows']->isEmpty())
                    <p class="pl-3 text-xs text-gray-500 dark:text-gray-400">Tidak ada transaksi.</p>
                @else
                    @include('filament.pages.partials.report-lines', ['rows' => $sections['pajak']['rows'], 'key' => 'amount', 'rupiah' => $rupiah, 'prevMap' => $prevMap, 'drill' => $drill])
                @endif
            </div>

            @include('filament.pages.partials.profit-row', ['label' => 'Laba Bersih', 'cur' => $result['laba_bersih'], 'prev' => $compare['laba_bersih'] ?? null, 'rupiah' => $rupiah, 'delta' => $delta, 'big' => true])
        </div>
    </x-filament::section>
</x-filament-panels::page>
