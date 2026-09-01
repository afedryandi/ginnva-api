<?php

namespace App\Exports;

use App\Models\Warranty;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Export data Warranty ke Excel, untuk dikirim manual (email) ke tim
 * Ginnva China secara mingguan/bulanan — sesuai keputusan resmi mereka
 * (akhir Juni 2026) bahwa API/data interface realtime belum bisa
 * disediakan karena ketentuan pemerintah. Ini PENGGANTI dari job
 * SyncWarrantyToChina yang sebelumnya mencoba kirim lewat API (sekarang
 * sudah tidak dipakai).
 *
 * SEBELUMNYA implements FromCollection dengan query independen sendiri
 * (cuma difilter tanggal) — sama sekali tidak peduli filter tabel
 * Filament yang sedang aktif (Toko, Status Review QA, Kategori Produk,
 * pencarian). Admin filter ke "Toko A" di layar lalu export, tetap
 * dapat data SEMUA toko. Diganti ke FromQuery + terima Builder dari
 * $livewire->getFilteredTableQuery() (pola sama dengan
 * InventoryMovementExport/RawMaterialMovementExport/
 * ConsumableItemMovementExport yang sudah lebih dulu benar), supaya
 * export selalu konsisten dengan apa yang sedang dilihat admin di
 * layar. Ditemukan lewat testing manual 2026-08-31.
 */
class WarrantyExport implements FromQuery, WithHeadings, WithMapping, WithStyles
{
    public function __construct(private ?Builder $query = null) {}

    public function query(): Builder
    {
        return ($this->query ?? Warranty::query())
            ->with('store')
            ->reorder('created_at');
    }

    public function headings(): array
    {
        return [
            'Kode Garansi',
            'Nama Pelanggan',
            'No. Telepon',
            'Plat Nomor',
            'Tipe Mobil',
            'Seri Produk',
            'Kategori Produk',
            'VIN',
            'Posisi Pemasangan (PPF)',
            'Keterangan Area (PPF)',
            'Roll Number PPF',
            'Roll Number PPF (Gulungan Kedua)',
            'Roll Number Depan (Window Film)',
            'Roll Number Samping/Belakang (Window Film)',
            'Tipe Film Depan (Window Film)',
            'Tipe Film Samping/Belakang (Window Film)',
            'Tanggal Pasang',
            'Tanggal Berakhir',
            'Nama Dealer',
            'Toko',
            'Status Review QA',
            'Status Garansi',
            'Tanggal Diajukan',
        ];
    }

    /**
     * @param Warranty $warranty
     */
    public function map($warranty): array
    {
        return [
            $warranty->warranty_code,
            $warranty->customer_name,
            $warranty->phone_number,
            $warranty->car_plate,
            $warranty->car_type,
            $warranty->product_series,
            match ($warranty->product_category) {
                'window_film' => 'Window Film',
                'ppf' => 'PPF',
                'color_change' => 'Color Change',
                default => '-',
            },
            $warranty->vin ?? '-',
            match ($warranty->installation_position) {
                'full_body' => 'Seluruh Bodi',
                'partial' => 'Parsial',
                default => '-',
            },
            $warranty->installation_position_detail ?? '-',
            $warranty->roll_number ?? '-',
            $warranty->roll_number_2 ?? '-',
            $warranty->roll_number_front ?? '-',
            $warranty->roll_number_side_rear ?? '-',
            $warranty->film_model_front ?? '-',
            $warranty->film_model_side_rear ?? '-',
            optional($warranty->installation_date)->format('Y-m-d'),
            optional($warranty->expiry_date)->format('Y-m-d'),
            $warranty->dealer_name,
            $warranty->store?->name ?? '-',
            $warranty->review_status,
            // status di sini SENGAJA dibaca lewat attribute mentah
            // (bukan accessor getStatusAttribute()), karena tim China
            // hanya butuh status dasar (active/expired/pending) sesuai
            // skema kolom asli, bukan nilai gabungan pending_review/
            // rejected yang khusus dipakai untuk tampilan internal admin.
            $warranty->getRawOriginal('status'),
            optional($warranty->created_at)->format('Y-m-d H:i'),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '1F2937'],
                ],
            ],
        ];
    }
}