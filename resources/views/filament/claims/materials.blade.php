@php
    $canSeeMemos = \App\Filament\Resources\MaterialMemoResource::canViewAny();
@endphp

@include('filament.partials.plain-ui')

<div class="pu pu-stack">
    @if (! $booking)
        <p class="pu-muted" style="margin: 0;">
            Klaim ini belum punya booking kunjungan, jadi bahan belum bisa ditautkan. Buat booking kunjungan untuk klaim ini lebih dulu,
            lalu catat bahan lewat Memo Pengambilan.
        </p>
    @else
        <p class="pu-muted" style="margin: 0;">
            Booking kunjungan: <span class="pu-strong">{{ $booking->booking_number }}</span>.
            Bahan dicatat di Memo Pengambilan/Pengembalian booking ini; stok berkurang saat memo diisi.
        </p>

        @if (! $memo)
            <p class="pu-muted" style="margin: 0;">Belum ada memo untuk booking ini, jadi belum ada bahan tercatat.</p>

            @if ($canSeeMemos && \App\Filament\Resources\MaterialMemoResource::canCreate())
                <a href="{{ \App\Filament\Resources\MaterialMemoResource::getUrl('create', ['booking' => $booking->id]) }}"
                   class="pu-link">
                    Buat Memo Pengambilan untuk booking ini
                </a>
            @endif
        @else
            @if (! $canSeeMemos)
                <p class="pu-muted" style="margin: 0;">Memo {{ $memo->memo_number }} tercatat dengan {{ $memo->items->count() }} item. Anda tidak punya akses ke menu Memo.</p>
            @else
                <p class="pu-strong" style="margin: 0;">Memo {{ $memo->memo_number }}</p>

                @if ($memo->items->isEmpty())
                    <p class="pu-muted" style="margin: 0;">Memo ini belum berisi barang.</p>
                @else
                    <div class="pu-scroll">
                        <table class="pu-table is-dense">
                            <thead>
                                <tr>
                                    <th>Barang</th>
                                    <th class="is-num">Diambil</th>
                                    <th class="is-num">Dikembalikan</th>
                                    <th class="is-num">Terpakai</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($memo->items as $item)
                                    <tr>
                                        <td>{{ $item->item_name }}</td>
                                        @if ($item->item_type === 'inventory_item')
                                            <td class="is-num">—</td>
                                            <td class="is-num">—</td>
                                            <td class="is-num">{{ rtrim(rtrim(number_format((float) $item->meters_used, 2, ',', '.'), '0'), ',') }} m</td>
                                        @else
                                            <td class="is-num">{{ rtrim(rtrim(number_format((float) $item->qty_taken, 2, ',', '.'), '0'), ',') }} {{ $item->unit }}</td>
                                            <td class="is-num">{{ $item->qty_returned === null ? '—' : rtrim(rtrim(number_format((float) $item->qty_returned, 2, ',', '.'), '0'), ',') }}</td>
                                            <td class="is-num">{{ $item->qty_used === null ? '—' : rtrim(rtrim(number_format((float) $item->qty_used, 2, ',', '.'), '0'), ',') }}</td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <a href="{{ \App\Filament\Resources\MaterialMemoResource::getUrl('edit', ['record' => $memo]) }}"
                   class="pu-link">
                    Buka memo untuk menambah atau mengubah bahan
                </a>
            @endif
        @endif
    @endif
</div>
