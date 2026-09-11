<?php

namespace App\Exports;

use App\Models\ScrollCode;
use App\Models\ScrollCodeUsage;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Laporan Serial Number" (SerialNumberReport) — audit
 * 2026-09-11, temuan B. Dua bagian (Daftar Serial Number + Riwayat
 * Pemakaian) digabung 1 sheet, sama pola PersediaanDetailReportExport.
 */
class SerialNumberReportExport implements FromArray, WithStyles
{
    private const STATUS_LABEL = [
        'unallocated' => 'Belum Dialokasikan',
        'allocated' => 'Dialokasikan',
        'used' => 'Habis Dipakai',
    ];

    public function __construct(private array $result) {}

    public function array(): array
    {
        $rows = [
            ['DAFTAR SERIAL NUMBER (ROLL)'],
            ['Kode Serial', 'Produk', 'Toko', 'Panjang Total (m)', 'Sisa Panjang (m)', 'Tgl Alokasi', 'Tgl Habis Dipakai', 'Status'],
        ];

        foreach ($this->result['codes'] as $code) {
            /** @var ScrollCode $code */
            $rows[] = [
                $code->code,
                $code->filmProduct ? "{$code->filmProduct->sku} - {$code->filmProduct->name}" : '-',
                $code->store?->name ?? '-',
                (float) $code->total_length_meters,
                (float) $code->remaining_length_meters,
                $code->allocated_at?->format('Y-m-d') ?? '-',
                $code->used_at?->format('Y-m-d') ?? '-',
                self::STATUS_LABEL[$code->status] ?? $code->status,
            ];
        }

        $rows[] = [];
        $rows[] = ['RIWAYAT PEMAKAIAN'];
        $rows[] = ['Tanggal', 'Kode Serial', 'Toko', 'Meter Dipakai', 'Oleh', 'Catatan'];

        foreach ($this->result['usages'] as $usage) {
            /** @var ScrollCodeUsage $usage */
            $rows[] = [
                $usage->created_at->format('Y-m-d H:i'),
                $usage->scrollCode?->code ?? '-',
                $usage->scrollCode?->store?->name ?? '-',
                (float) $usage->meters,
                $usage->user?->name ?? '-',
                $usage->note ?: '-',
            ];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 14]],
        ];
    }
}
