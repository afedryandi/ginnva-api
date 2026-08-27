<?php

namespace App\Exports;

use App\Models\ScrollCode;
use App\Models\Warranty;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ScrollCodeExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    /**
     * Kode gulungan -> daftar warranty_code yang memakainya. Dihitung
     * SEKALI di query() (bukan per baris di map()) — sebelumnya
     * $row->warranties()->pluck(...) dipanggil ulang untuk SETIAP baris
     * karena warranties() bukan relasi Eloquent asli (jadi tidak bisa
     * di-eager-load via with()), bikin 1 query tambahan per baris untuk
     * export yang isinya ribuan kode.
     */
    private array $warrantyCodesByRollNumber = [];

    public function query(): Builder
    {
        $codes = ScrollCode::pluck('code');

        $this->warrantyCodesByRollNumber = Warranty::query()
            ->where(fn ($q) => $q->whereIn('roll_number', $codes)
                ->orWhereIn('roll_number_2', $codes)
                ->orWhereIn('roll_number_front', $codes)
                ->orWhereIn('roll_number_side_rear', $codes))
            ->get(['warranty_code', 'roll_number', 'roll_number_2', 'roll_number_front', 'roll_number_side_rear'])
            ->reduce(function (array $carry, Warranty $w) {
                foreach ([$w->roll_number, $w->roll_number_2, $w->roll_number_front, $w->roll_number_side_rear] as $rollNumber) {
                    if ($rollNumber !== null) {
                        $carry[$rollNumber][] = $w->warranty_code;
                    }
                }

                return $carry;
            }, []);

        return ScrollCode::with(['filmProduct', 'store'])->orderBy('created_at', 'desc');
    }

    public function headings(): array
    {
        return [
            'Kode', 'Produk Film', 'Tipe', 'Posisi', 'Toko', 'Status',
            'No. Garansi', 'Total Panjang (m)', 'Sisa Panjang (m)', 'Terpakai (m)', 'Yield (%)',
            'Dialokasi Pada', 'Dipakai Pada', 'Dibuat Pada',
        ];
    }

    public function map($row): array
    {
        $fp = $row->filmProduct;

        // Yield sederhana: berapa persen panjang gulungan yang sudah
        // benar-benar terpakai (bukan sisa/waste) — cuma dihitung untuk
        // kode yang punya data Total Panjang, kode lama tanpa data ini
        // tetap diekspor tapi kolom yield-nya kosong (bukan 0%, supaya
        // tidak disalahartikan sebagai "belum terpakai sama sekali").
        $total = $row->total_length_meters !== null ? (float) $row->total_length_meters : null;
        $remaining = $row->remaining_length_meters !== null ? (float) $row->remaining_length_meters : null;
        $used = $total !== null && $remaining !== null ? round($total - $remaining, 2) : null;
        $yieldPercent = ($total !== null && $total > 0 && $used !== null) ? round(($used / $total) * 100, 1) : null;

        return [
            $row->code,
            $fp?->name ?? '—',
            $fp ? ($fp->product_type === 'ppf' ? 'PPF' : 'Kaca Film') : '—',
            ($fp && $fp->product_type === 'window_film')
                ? ($fp->position === 'front' ? 'Kaca Depan' : 'Samping & Belakang')
                : '—',
            $row->store?->name ?? '—',
            match ($row->status) {
                'unallocated' => 'Belum Dialokasi',
                'allocated'   => 'Dialokasi',
                'used'        => 'Terpakai',
                default       => $row->status,
            },
            implode(', ', $this->warrantyCodesByRollNumber[$row->code] ?? []) ?: '—',
            $total ?? '—',
            $remaining ?? '—',
            $used ?? '—',
            $yieldPercent !== null ? $yieldPercent . '%' : '—',
            $row->allocated_at?->format('d/m/Y H:i') ?? '—',
            $row->used_at?->format('d/m/Y H:i') ?? '—',
            $row->created_at?->format('d/m/Y H:i'),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}