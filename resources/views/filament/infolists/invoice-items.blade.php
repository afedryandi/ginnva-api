{{--
    Tabel baris produk invoice (halaman Lihat): satu judul kolom di atas, angka rata kanan dengan tabular-nums, tanpa label yang
    diulang di tiap baris. Gaya polos (.inv-) karena panel memakai CSS Filament terkompilasi, bukan Tailwind bebas.
--}}
@php
    $record = $getRecord();
    $items = $record->items;
    $money = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');
    $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
@endphp

<div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
        <thead>
            <tr style="text-align:left;color:rgb(107 114 128);font-size:.75rem;text-transform:uppercase;letter-spacing:.04em;">
                <th style="padding:.5rem .5rem .5rem 0;font-weight:600;">Produk</th>
                <th style="padding:.5rem;text-align:right;font-weight:600;">Jumlah</th>
                <th style="padding:.5rem;text-align:right;font-weight:600;">Harga</th>
                <th style="padding:.5rem;text-align:right;font-weight:600;">Diskon</th>
                <th style="padding:.5rem 0 .5rem .5rem;text-align:right;font-weight:600;">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($items as $item)
                <tr style="border-top:1px solid rgba(128,128,128,.2);">
                    <td style="padding:.75rem .5rem .75rem 0;">
                        <div style="font-weight:500;">{{ $item->name }}</div>
                        @if ($item->note)
                            <div style="font-size:.75rem;color:rgb(107 114 128);">{{ $item->note }}</div>
                        @endif
                    </td>
                    <td style="padding:.75rem .5rem;text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;">{{ $qty($item->quantity) }} {{ $item->unit }}</td>
                    <td style="padding:.75rem .5rem;text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;">{{ $money($item->price) }}</td>
                    <td style="padding:.75rem .5rem;text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;">{{ (float) $item->discount_percent > 0 ? $qty($item->discount_percent) . '%' : '—' }}</td>
                    <td style="padding:.75rem 0 .75rem .5rem;text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;font-weight:600;">{{ $money($item->total) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" style="padding:.75rem 0;color:rgb(107 114 128);">Belum ada produk.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
