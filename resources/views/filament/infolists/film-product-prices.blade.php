{{--
    Harga produk (halaman Lihat): harga dasar, harga per ukuran kendaraan, dan harga khusus per grup pelanggan dalam tabel kecil dengan
    angka rata kanan (tabular-nums). Gaya polos karena panel memakai CSS Filament terkompilasi. Harga INTERNAL, tidak tampil ke pelanggan.
--}}
@php
    $record = $getRecord();
    $rp = fn ($n) => 'Rp' . number_format((float) $n, 0, ',', '.');
    $sizeNames = [
        'S' => 'S — City Car',
        'M' => 'M — Sedan / Hatchback',
        'L' => 'L — SUV / MPV',
        'XL' => 'XL — Large SUV / Van',
        'XXL' => 'XXL — Bus / Truck',
    ];
    $sizes = $record->prices->sortBy(fn ($p) => array_search($p->vehicle_size, array_keys($sizeNames), true));
    $groups = $record->groupPrices->load('customerGroup');
    $cell = 'padding:.6rem 0;border-top:1px solid rgba(128,128,128,.2);';
    $num = 'text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;font-weight:600;';
    $head = 'font-size:.75rem;text-transform:uppercase;letter-spacing:.04em;color:rgb(107 114 128);font-weight:600;margin-bottom:.25rem;';
@endphp

<div style="display:flex;flex-direction:column;gap:1.5rem;">
    <div>
        <div style="{{ $head }}">Harga dasar (flat)</div>
        <div style="font-size:1.25rem;font-weight:700;font-variant-numeric:tabular-nums;">
            {{ (float) $record->base_price > 0 ? $rp($record->base_price) : 'Belum diisi' }}
        </div>
    </div>

    <div>
        <div style="{{ $head }}">Per ukuran kendaraan <span style="text-transform:none;letter-spacing:0;font-weight:400;">(menang atas harga dasar)</span></div>
        @if ($sizes->isEmpty())
            <div style="color:rgb(107 114 128);font-size:.875rem;">Tidak ada. Produk memakai harga dasar untuk semua ukuran.</div>
        @else
            <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
                @foreach ($sizes as $row)
                    <tr>
                        <td style="{{ $cell }}">{{ $sizeNames[$row->vehicle_size] ?? $row->vehicle_size }}</td>
                        <td style="{{ $cell }}{{ $num }}">{{ $rp($row->price) }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>

    @if ($groups->isNotEmpty())
        <div>
            <div style="{{ $head }}">Harga khusus per grup pelanggan <span style="text-transform:none;letter-spacing:0;font-weight:400;">(menang atas harga normal)</span></div>
            <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
                @foreach ($groups as $row)
                    <tr>
                        <td style="{{ $cell }}">{{ $row->customerGroup?->name ?? '—' }}</td>
                        <td style="{{ $cell }}{{ $num }}">{{ $rp($row->price) }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif
</div>
