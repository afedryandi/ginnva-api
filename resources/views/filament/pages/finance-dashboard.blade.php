<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $s = $this->getSummary();
        $rp = fn ($n) => ($n < 0 ? '-' : '') . 'Rp ' . number_format(abs($n), 0, ',', '.');
        $delta = fn ($now, $prev) => $prev != 0 ? round(($now - $prev) / abs($prev) * 100) : null;
        $m = $s['month'];
        $rev = $delta($m['revenue'], $m['prev_revenue']);
        $pm = $s['previous_month'];
        $revText = $rev === null ? '' : ' (' . ($rev >= 0 ? '+' : '') . $rev . '%)';
        $pmWarn = $pm['warnings'] > 0 ? ' · ' . $pm['warnings'] . ' peringatan' : '';

        // Butir yang butuh tindakan: [label, jumlah, tautan].
        $actions = [
            ['Pengajuan pengeluaran menunggu persetujuan', $s['pending_expense'], \App\Filament\Resources\FinanceTransactionApprovalRequestResource::getUrl()],
            ['Pengajuan Referral/Refund/DP menunggu persetujuan', $s['pending_transactions'], \App\Filament\Resources\TransactionApprovalRequestResource::getUrl()],
            ['Selisih hitung kas 30 hari terakhir belum diselesaikan', $s['cash_count_differences'], \App\Filament\Resources\CashCountResource::getUrl()],
            ['Kas toko belum dihitung hari ini', $s['cash_not_counted_today'], \App\Filament\Resources\CashCountResource::getUrl()],
            ['Akun beban melewati anggaran bulan ini', $s['budget_over'], \App\Filament\Pages\BudgetVsActualReport::getUrl()],
            ['Akun beban hampir habis anggaran (80%+)', $s['budget_near'], \App\Filament\Pages\BudgetVsActualReport::getUrl()],
        ];
    @endphp

    <div wire:loading.class="opacity-50" wire:target="data.store_id" class="space-y-6">
        @if ($s['year_to_close'])
            <div class="rounded-lg bg-warning-50 p-3 text-sm text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
                Tahun buku {{ $s['year_to_close'] }} belum ditutup. <a href="{{ \App\Filament\Pages\YearEndClosingPage::getUrl() }}" class="underline">Tutup Tahun Buku</a>
            </div>
        @endif

        <div class="grid gap-6 md:grid-cols-3">
            <x-filament::section heading="Kas & Bank">
                <div class="text-2xl font-semibold tabular-nums">{{ $rp($s['cash_total']) }}</div>
                <div class="mt-3 space-y-1">
                    @foreach ($s['cash_accounts'] as $row)
                        <div class="flex justify-between text-sm"><span>{{ $row['name'] }}</span><span class="tabular-nums text-gray-500 dark:text-gray-400">{{ $rp($row['balance']) }}</span></div>
                    @endforeach
                </div>
            </x-filament::section>

            <x-filament::section :heading="'Pendapatan ' . $m['label']">
                <div class="text-2xl font-semibold tabular-nums">{{ $rp($m['revenue']) }}</div>
                <div class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    Bulan lalu {{ $rp($m['prev_revenue']) }}{{ $revText }}
                </div>
            </x-filament::section>

            <x-filament::section :heading="'Laba Bersih ' . $m['label']">
                <div class="text-2xl font-semibold tabular-nums {{ $m['net_income'] < 0 ? 'text-danger-600 dark:text-danger-400' : '' }}">{{ $rp($m['net_income']) }}</div>
                <div class="mt-2 text-sm text-gray-500 dark:text-gray-400">Bulan lalu {{ $rp($m['prev_net_income']) }}</div>
            </x-filament::section>
        </div>

        <div class="grid gap-6 md:grid-cols-2">
            <x-filament::section heading="Piutang">
                <div class="text-2xl font-semibold tabular-nums">{{ $rp($s['receivable']['outstanding']) }}</div>
                <div class="mt-2 text-sm {{ $s['receivable']['overdue_count'] ? 'text-warning-700 dark:text-warning-400' : 'text-gray-500 dark:text-gray-400' }}">
                    Jatuh tempo lewat: {{ $rp($s['receivable']['overdue']) }} ({{ $s['receivable']['overdue_count'] }} tagihan)
                </div>
                <a href="{{ \App\Filament\Pages\ReceivableAgingReport::getUrl() }}" class="mt-2 inline-block text-sm underline">Lihat umur piutang</a>
            </x-filament::section>

            <x-filament::section heading="Hutang">
                <div class="text-2xl font-semibold tabular-nums">{{ $rp($s['payable']['outstanding']) }}</div>
                <div class="mt-2 text-sm {{ $s['payable']['overdue_count'] ? 'text-warning-700 dark:text-warning-400' : 'text-gray-500 dark:text-gray-400' }}">
                    Jatuh tempo lewat: {{ $rp($s['payable']['overdue']) }} ({{ $s['payable']['overdue_count'] }} tagihan)
                </div>
                <a href="{{ \App\Filament\Pages\PayableAgingReport::getUrl() }}" class="mt-2 inline-block text-sm underline">Lihat umur hutang</a>
            </x-filament::section>
        </div>

        <x-filament::section heading="Perlu tindakan">
            <ul class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($actions as [$label, $count, $url])
                    <li class="flex items-center justify-between gap-4 py-2 text-sm">
                        <a href="{{ $url }}" class="hover:underline">{{ $label }}</a>
                        @if ($count > 0)
                            <span class="rounded-md bg-warning-50 px-2 py-0.5 text-xs font-semibold tabular-nums text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">{{ $count }}</span>
                        @else
                            <span class="text-xs text-success-600 dark:text-success-400">Beres</span>
                        @endif
                    </li>
                @endforeach
                <li class="flex items-center justify-between gap-4 py-2 text-sm">
                    <a href="{{ \App\Filament\Pages\ClosePeriodPage::getUrl() }}" class="hover:underline">Tutup buku {{ $pm['label'] }}</a>
                    @if ($pm['closed'])
                        <span class="text-xs text-success-600 dark:text-success-400">Sudah ditutup</span>
                    @elseif ($pm['blockers'] > 0)
                        <span class="rounded-md bg-danger-50 px-2 py-0.5 text-xs font-semibold text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">{{ $pm['blockers'] }} penghalang</span>
                    @else
                        <span class="rounded-md bg-warning-50 px-2 py-0.5 text-xs font-semibold text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">Belum ditutup{{ $pmWarn }}</span>
                    @endif
                </li>
            </ul>
        </x-filament::section>
    </div>
</x-filament-panels::page>
