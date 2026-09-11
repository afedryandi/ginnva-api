<x-filament-panels::page>
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $result = $this->getResult();
        $rupiah = fn ($n) => 'Rp' . number_format($n, 0, ',', '.');
        $filterTargets = 'data.from, data.to';
    @endphp

    <div wire:loading.class="opacity-50 pointer-events-none" wire:target="{{ $filterTargets }}" class="space-y-6">
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Total Refund</div>
            <div class="mt-1 text-2xl font-bold tabular-nums text-danger-600 dark:text-danger-400">{{ $rupiah($result['totalAmount']) }}</div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs text-gray-500 dark:text-gray-400">Jumlah Refund</div>
            <div class="mt-1 text-2xl font-bold tabular-nums">{{ number_format($result['totalCount'], 0, ',', '.') }}</div>
        </x-filament::section>
    </div>

    <x-filament::section>
        <x-slot name="heading">Daftar Refund</x-slot>
        <x-slot name="description">
            Tiap refund otomatis punya jurnal kontra di Jurnal Umum — klik No. Jurnal untuk lihat detailnya.
            "Metode Pembayaran" masih selalu "Tunai" — RefundService saat ini SELALU mengasumsikan refund dibayar tunai
            (kredit akun Kas), belum menangani refund non-tunai/transfer bank.
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pr-3">No. Refund</th>
                        <th class="py-2 pr-3">Tanggal</th>
                        <th class="py-2 pr-3">No. Booking</th>
                        <th class="py-2 pr-3">Pelanggan</th>
                        <th class="py-2 pr-3">Toko</th>
                        <th class="py-2 pr-3">Metode Pembayaran</th>
                        <th class="py-2 pr-3">Diproses Oleh</th>
                        <th class="py-2 pr-3">No. Jurnal</th>
                        <th class="py-2 pr-3">Alasan</th>
                        <th class="py-2 pl-3 text-right">Nominal</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($result['refunds'] as $refund)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-3 font-medium">{{ $refund->refund_number }}</td>
                            <td class="py-2 pr-3 tabular-nums">{{ $refund->created_at->format('d M Y H:i') }}</td>
                            <td class="py-2 pr-3">{{ $refund->booking?->booking_number ?? '—' }}</td>
                            <td class="py-2 pr-3">{{ $refund->booking?->customer_name ?? '—' }}</td>
                            <td class="py-2 pr-3">{{ $refund->booking?->store?->name ?? '—' }}</td>
                            <td class="py-2 pr-3">Tunai</td>
                            <td class="py-2 pr-3">{{ $refund->creator?->name ?? '—' }}</td>
                            <td class="py-2 pr-3">
                                @if ($refund->journalEntry)
                                    <a
                                        href="{{ \App\Filament\Resources\JournalEntryResource::getUrl('index', ['tableSearch' => $refund->journalEntry->entry_number]) }}"
                                        class="font-mono text-primary-600 hover:underline dark:text-primary-400"
                                    >{{ $refund->journalEntry->entry_number }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="py-2 pr-3">{{ $refund->reason ?: '—' }}</td>
                            <td class="py-2 pl-3 text-right tabular-nums text-danger-600 dark:text-danger-400">{{ $rupiah((float) $refund->amount) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="py-4 text-center text-gray-500 dark:text-gray-400">Tidak ada refund pada rentang ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
    </div>
</x-filament-panels::page>
