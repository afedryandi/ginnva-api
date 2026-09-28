<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $months = $this->getMonths();
        $year = (int) ($this->data['year'] ?? now()->year);
    @endphp

    <x-filament::section>
        <x-slot name="heading">Status Periode {{ $year }}</x-slot>
        <x-slot name="description">
            Bulan yang ditutup tidak bisa lagi menerima jurnal baru/perubahan/posting — koreksi hanya lewat Jurnal Pembalik (bertanggal hari ini) atau buka kembali periodenya dulu.
        </x-slot>

        <div class="divide-y divide-gray-100 dark:divide-white/10">
            @foreach ($months as $m)
                <div class="flex items-center justify-between py-3">
                    <div>
                        <div class="font-medium">{{ $m['date']->translatedFormat('F Y') }}</div>
                        @if ($m['is_closed'])
                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                Ditutup {{ $m['period']->closed_at?->format('d M Y H:i') }} oleh {{ $m['period']->closer?->name ?? '—' }}
                                @if ($m['period']->notes)
                                    — {{ $m['period']->notes }}
                                @endif
                            </div>
                            {{-- Ringkasan angka saat ditutup (pembanding kalau periode dibuka lalu ditutup lagi) --}}
                            @if (! empty($m['snapshot']))
                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                    Saat ditutup: {{ $m['snapshot']['journal_count'] ?? 0 }} jurnal ·
                                    Laba bersih Rp {{ number_format((float) ($m['snapshot']['net_income'] ?? 0), 0, ',', '.') }} ·
                                    Kas akhir Rp {{ number_format((float) ($m['snapshot']['closing_cash'] ?? 0), 0, ',', '.') }}
                                </div>
                            @endif
                        @endif
                        @if ($m['posted_count'] || $m['draft_count'])
                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $m['posted_count'] }} jurnal posted
                                @if ($m['draft_count'])
                                    · <span class="text-warning-600">{{ $m['draft_count'] }} draft (harus diposting/dihapus sebelum tutup)</span>
                                @endif
                                · @if ($m['balanced']) <span class="text-success-600">debit = kredit</span> @else <span class="text-danger-600">debit ≠ kredit</span> @endif
                            </div>
                        @endif
                    </div>

                    <div class="flex items-center gap-3">
                        @if ($m['is_closed'])
                            <x-filament::badge color="success">Ditutup</x-filament::badge>
                            <x-filament::button
                                size="sm"
                                color="warning"
                                wire:click="mountAction('reopenPeriod', { year: {{ $year }}, month: {{ $m['month'] }} })"
                            >
                                Buka Kembali
                            </x-filament::button>
                        @elseif ($m['date']->greaterThanOrEqualTo(now()->startOfMonth()))
                            {{-- Bulan berjalan & masa depan belum boleh ditutup (lihat AccountingPeriodService::close()). --}}
                            <x-filament::badge color="gray">{{ $m['is_future'] ? 'Belum Terjadi' : 'Belum Berakhir' }}</x-filament::badge>
                        @else
                            <x-filament::badge color="gray">Terbuka</x-filament::badge>
                            <x-filament::button
                                size="sm"
                                color="danger"
                                wire:click="mountAction('closePeriod', { year: {{ $year }}, month: {{ $m['month'] }} })"
                            >
                                Tutup Periode
                            </x-filament::button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    {{-- Riwayat tutup / buka kembali (tetap ada walau periode dibuka kembali) --}}
    @php $events = $this->getEvents(); @endphp
    <x-filament::section :collapsed="true">
        <x-slot name="heading">Riwayat Tutup / Buka Kembali</x-slot>
        <x-slot name="description">15 kejadian terakhir — jejak siapa, kapan, dan mengapa.</x-slot>

        @if ($events->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Belum ada riwayat.</p>
        @else
            <div class="divide-y divide-gray-100 text-sm dark:divide-white/10">
                @foreach ($events as $event)
                    <div class="flex items-start justify-between gap-4 py-2">
                        <div>
                            <span class="font-medium">{{ $event->period_month->translatedFormat('F Y') }}</span>
                            <x-filament::badge :color="$event->action === 'closed' ? 'success' : 'warning'" class="ms-2">
                                {{ $event->action === 'closed' ? 'Ditutup' : 'Dibuka kembali' }}
                            </x-filament::badge>
                            @if ($event->note)
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $event->note }}</div>
                            @endif
                        </div>
                        <div class="whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">
                            {{ $event->user?->name ?? '—' }} · {{ $event->created_at->format('d M Y H:i') }}
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
