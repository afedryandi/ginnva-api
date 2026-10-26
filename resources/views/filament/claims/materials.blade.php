@php
    $canSeeMemos = \App\Filament\Resources\MaterialMemoResource::canViewAny();
@endphp

<div class="space-y-3 text-sm">
    @if (! $booking)
        <p class="text-gray-600 dark:text-gray-400">
            Klaim ini belum punya booking kunjungan, jadi bahan belum bisa ditautkan. Buat booking kunjungan untuk klaim ini lebih dulu,
            lalu catat bahan lewat Memo Pengambilan.
        </p>
    @else
        <p class="text-gray-600 dark:text-gray-400">
            Booking kunjungan: <span class="font-medium text-gray-950 dark:text-white">{{ $booking->booking_number }}</span>.
            Bahan dicatat di Memo Pengambilan/Pengembalian booking ini; stok berkurang saat memo diisi.
        </p>

        @if (! $memo)
            <p class="text-gray-600 dark:text-gray-400">Belum ada memo untuk booking ini, jadi belum ada bahan tercatat.</p>

            @if ($canSeeMemos && \App\Filament\Resources\MaterialMemoResource::canCreate())
                <a href="{{ \App\Filament\Resources\MaterialMemoResource::getUrl('create', ['booking' => $booking->id]) }}"
                   class="inline-flex items-center gap-1 font-medium text-primary-600 hover:underline dark:text-primary-400">
                    Buat Memo Pengambilan untuk booking ini
                </a>
            @endif
        @else
            @if (! $canSeeMemos)
                <p class="text-gray-600 dark:text-gray-400">Memo {{ $memo->memo_number }} tercatat dengan {{ $memo->items->count() }} item. Anda tidak punya akses ke menu Memo.</p>
            @else
                <p class="font-medium text-gray-950 dark:text-white">Memo {{ $memo->memo_number }}</p>

                @if ($memo->items->isEmpty())
                    <p class="text-gray-600 dark:text-gray-400">Memo ini belum berisi barang.</p>
                @else
                    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-gray-50 text-gray-600 dark:bg-white/5 dark:text-gray-400">
                                <tr>
                                    <th class="px-3 py-2 font-medium">Barang</th>
                                    <th class="px-3 py-2 text-right font-medium">Diambil</th>
                                    <th class="px-3 py-2 text-right font-medium">Dikembalikan</th>
                                    <th class="px-3 py-2 text-right font-medium">Terpakai</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                @foreach ($memo->items as $item)
                                    <tr>
                                        <td class="px-3 py-2">{{ $item->item_name }}</td>
                                        @if ($item->item_type === 'inventory_item')
                                            <td class="px-3 py-2 text-right tabular-nums">—</td>
                                            <td class="px-3 py-2 text-right tabular-nums">—</td>
                                            <td class="px-3 py-2 text-right tabular-nums">{{ rtrim(rtrim(number_format((float) $item->meters_used, 2, ',', '.'), '0'), ',') }} m</td>
                                        @else
                                            <td class="px-3 py-2 text-right tabular-nums">{{ rtrim(rtrim(number_format((float) $item->qty_taken, 2, ',', '.'), '0'), ',') }} {{ $item->unit }}</td>
                                            <td class="px-3 py-2 text-right tabular-nums">{{ $item->qty_returned === null ? '—' : rtrim(rtrim(number_format((float) $item->qty_returned, 2, ',', '.'), '0'), ',') }}</td>
                                            <td class="px-3 py-2 text-right tabular-nums">{{ $item->qty_used === null ? '—' : rtrim(rtrim(number_format((float) $item->qty_used, 2, ',', '.'), '0'), ',') }}</td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <a href="{{ \App\Filament\Resources\MaterialMemoResource::getUrl('edit', ['record' => $memo]) }}"
                   class="inline-flex items-center gap-1 font-medium text-primary-600 hover:underline dark:text-primary-400">
                    Buka memo untuk menambah atau mengubah bahan
                </a>
            @endif
        @endif
    @endif
</div>
