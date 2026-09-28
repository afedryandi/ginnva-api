<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    <x-report-notices :notices="$this->getNotices()" />

    @php
        $result = $this->getResult();
        $compare = $result['compare'] ?? null;
        // Negatif (mis. akun pengurang) dengan kurung, konsisten dengan Laporan Laba Rugi.
        $rupiah = fn ($n) => ($n < 0 ? '(' : '') . 'Rp ' . number_format(abs($n), 0, ',', '.') . ($n < 0 ? ')' : '');
        $drill = fn ($account) => $this->ledgerUrl($account->id);
        // Dasar persentase (common-size): Total Aset.
        $base = (float) $result['aset']['total'];

        // Peta id akun => saldo pembanding (lintas Aset/Kewajiban/Modal).
        $prevMap = null;
        if ($compare) {
            $prevMap = [];
            foreach (['aset', 'kewajiban', 'modal'] as $group) {
                foreach ($compare[$group]['rows'] as $r) {
                    $prevMap[$r['account']->id] = $r['balance'];
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

        // Baris total dengan pembanding opsional.
        $prevOf = fn ($path) => $compare ? data_get($compare, $path) : null;
    @endphp

    <x-filament::section>
        <x-slot name="heading">Neraca per {{ $result['as_of']->format('d M Y') }}</x-slot>
        <x-slot name="description">
            Toko: {{ $result['store_label'] ?? 'Semua Toko' }}. Persentase kecil di kanan = porsi terhadap Total Aset.
            Dari Jurnal Umum berstatus posted. "Laba (Rugi) Tahun Berjalan" dihitung otomatis dari 1 Januari {{ $result['as_of']->year }} s.d. tanggal ini, dan laba tahun-tahun sebelumnya yang belum dipindahkan ke Laba Ditahan ditampilkan terpisah. Klik nama akun untuk melihat Buku Besar-nya.
            @if ($compare)
                Kolom kiri = pembanding ({{ $result['compare_label'] }}), lalu selisih %, lalu saldo saat ini.
            @endif
        </x-slot>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {{-- ASET --}}
            <div>
                <div class="mb-2 text-base font-bold text-info-700 dark:text-info-400">Aset</div>
                @if ($result['aset']['rows']->isEmpty())
                    <p class="text-xs text-gray-500 dark:text-gray-400">Belum ada saldo aset.</p>
                @else
                    @include('filament.pages.partials.report-lines', ['rows' => $result['aset']['rows'], 'key' => 'balance', 'rupiah' => $rupiah, 'prevMap' => $prevMap, 'drill' => $drill])
                @endif
                <div class="mt-2 flex justify-between border-t-2 border-gray-300 pt-2 text-sm font-bold dark:border-white/20">
                    <span>Total Aset</span>
                    <span class="flex gap-4 tabular-nums">
                        @if ($compare)
                            <span class="text-xs font-normal text-gray-400">{{ $rupiah($prevOf('aset.total')) }}</span>
                            <span class="text-xs font-normal">{{ $delta($result['aset']['total'], $prevOf('aset.total')) }}</span>
                        @endif
                        <span>{{ $rupiah($result['aset']['total']) }}</span>
                    </span>
                </div>
            </div>

            {{-- KEWAJIBAN & MODAL --}}
            <div class="space-y-6">
                <div>
                    <div class="mb-2 text-base font-bold text-warning-700 dark:text-warning-400">Kewajiban</div>
                    @if ($result['kewajiban']['rows']->isEmpty())
                        <p class="text-xs text-gray-500 dark:text-gray-400">Belum ada saldo kewajiban.</p>
                    @else
                        @include('filament.pages.partials.report-lines', ['rows' => $result['kewajiban']['rows'], 'key' => 'balance', 'rupiah' => $rupiah, 'prevMap' => $prevMap, 'drill' => $drill])
                    @endif
                    <div class="mt-2 flex justify-between border-t border-gray-200 pt-1.5 text-sm font-semibold dark:border-white/10">
                        <span>Total Kewajiban</span>
                        <span class="flex gap-4 tabular-nums">
                            @if ($compare)
                                <span class="text-xs font-normal text-gray-400">{{ $rupiah($prevOf('kewajiban.total')) }}</span>
                                <span class="text-xs font-normal">{{ $delta($result['kewajiban']['total'], $prevOf('kewajiban.total')) }}</span>
                            @endif
                            <span>{{ $rupiah($result['kewajiban']['total']) }}</span>
                        </span>
                    </div>
                </div>

                <div>
                    <div class="mb-2 text-base font-bold text-success-700 dark:text-success-400">Modal</div>
                    @if ($result['modal']['rows']->isEmpty())
                        <p class="text-xs text-gray-500 dark:text-gray-400">Belum ada saldo modal.</p>
                    @else
                        @include('filament.pages.partials.report-lines', ['rows' => $result['modal']['rows'], 'key' => 'balance', 'rupiah' => $rupiah, 'prevMap' => $prevMap, 'drill' => $drill])
                    @endif
                    @if (abs($result['modal']['laba_tahun_lalu']) >= 0.005)
                        <div class="flex justify-between border-b border-gray-100 py-1.5 text-sm italic dark:border-white/5">
                            <span class="text-gray-600 dark:text-gray-300">Laba (Rugi) Tahun-Tahun Sebelumnya (belum ditutup ke Laba Ditahan)</span>
                            <span class="tabular-nums">{{ $rupiah($result['modal']['laba_tahun_lalu']) }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between border-b border-gray-100 py-1.5 text-sm italic dark:border-white/5">
                        <span class="text-gray-600 dark:text-gray-300">Laba (Rugi) Tahun Berjalan</span>
                        <span class="tabular-nums">{{ $rupiah($result['modal']['laba_tahun_berjalan']) }}</span>
                    </div>
                    <div class="mt-2 flex justify-between border-t border-gray-200 pt-1.5 text-sm font-semibold dark:border-white/10">
                        <span>Total Modal</span>
                        <span class="flex gap-4 tabular-nums">
                            @if ($compare)
                                <span class="text-xs font-normal text-gray-400">{{ $rupiah($prevOf('modal.total')) }}</span>
                                <span class="text-xs font-normal">{{ $delta($result['modal']['total'], $prevOf('modal.total')) }}</span>
                            @endif
                            <span>{{ $rupiah($result['modal']['total']) }}</span>
                        </span>
                    </div>
                </div>

                <div class="flex justify-between border-t-2 border-gray-300 pt-2 text-sm font-bold dark:border-white/20">
                    <span>Total Kewajiban + Modal</span>
                    <span class="flex gap-4 tabular-nums">
                        @if ($compare)
                            <span class="text-xs font-normal text-gray-400">{{ $rupiah($prevOf('total_kewajiban_modal')) }}</span>
                            <span class="text-xs font-normal">{{ $delta($result['total_kewajiban_modal'], $prevOf('total_kewajiban_modal')) }}</span>
                        @endif
                        <span>{{ $rupiah($result['total_kewajiban_modal']) }}</span>
                    </span>
                </div>
            </div>
        </div>

        <div class="mt-6">
            @if ($result['is_balanced'])
                <div class="rounded-lg border border-success-300 bg-success-50 p-3 text-sm text-success-700 dark:border-success-700 dark:bg-success-950 dark:text-success-300">
                    ✓ Balance — Total Aset ({{ $rupiah($result['aset']['total']) }}) sama dengan Total Kewajiban + Modal.
                </div>
            @else
                <div class="rounded-lg border border-danger-300 bg-danger-50 p-3 text-sm text-danger-700 dark:border-danger-700 dark:bg-danger-950 dark:text-danger-300">
                    ✗ TIDAK balance — Total Aset ({{ $rupiah($result['aset']['total']) }}) berbeda dari Total Kewajiban + Modal ({{ $rupiah($result['total_kewajiban_modal']) }}). Periksa jurnal yang mungkin belum lengkap.
                </div>
            @endif
        </div>

        {{-- Rasio keuangan dasar (Lancar = akun berinduk 1100 Aset Lancar / 2100 Kewajiban Lancar) --}}
        @if (! empty($result['ratios']))
            @php $x = $result['ratios']; @endphp
            <div class="mt-6 grid grid-cols-2 gap-4 text-sm lg:grid-cols-4">
                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Rasio Lancar</div>
                    <div class="text-lg font-semibold tabular-nums">{{ $x['current_ratio'] === null ? '—' : number_format($x['current_ratio'], 2, ',', '.') . 'x' }}</div>
                    <div class="text-xs text-gray-400">Aset Lancar ÷ Kewajiban Lancar</div>
                </div>
                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Modal Kerja</div>
                    <div class="text-lg font-semibold tabular-nums">{{ $rupiah($x['working_capital']) }}</div>
                    <div class="text-xs text-gray-400">Aset Lancar − Kewajiban Lancar</div>
                </div>
                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Kewajiban ÷ Modal</div>
                    <div class="text-lg font-semibold tabular-nums">{{ $x['debt_to_equity'] === null ? '—' : number_format($x['debt_to_equity'], 2, ',', '.') . 'x' }}</div>
                    <div class="text-xs text-gray-400">Total Kewajiban ÷ Total Modal</div>
                </div>
                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Kewajiban ÷ Aset</div>
                    <div class="text-lg font-semibold tabular-nums">{{ $x['debt_to_assets'] === null ? '—' : number_format($x['debt_to_assets'], 1, ',', '.') . '%' }}</div>
                    <div class="text-xs text-gray-400">Porsi aset yang dibiayai utang</div>
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
