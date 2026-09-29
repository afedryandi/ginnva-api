<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Bukti rekonsiliasi bank (audit Rekonsiliasi Bank 2026-09-29): daftar mutasi 1 akun dalam 1
 * periode beserta statusnya, plus ringkasan saldo sistem vs total yang sudah/belum dicocokkan --
 * dokumen yang bisa dilampirkan saat tutup buku bulanan.
 */
class BankReconciliationExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result) {}

    public function headings(): array
    {
        return ['Tanggal', 'Keterangan', 'Nominal', 'Status', 'No. Jurnal Tercocok'];
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->result['lines'] as $line) {
            $rows[] = [
                $line['date']->format('Y-m-d'),
                $line['description'],
                (float) $line['amount'],
                $line['status_label'],
                $line['journal_entry_number'] ?? '',
            ];
        }

        $rows[] = [];
        $rows[] = ['Ringkasan'];
        $rows[] = ['Saldo sistem per ' . $this->result['as_of']->format('d M Y'), (float) $this->result['system_balance']];
        $rows[] = ['Jumlah mutasi cocok', $this->result['matched_count']];
        $rows[] = ['Jumlah mutasi belum cocok', $this->result['unmatched_count']];
        $rows[] = ['Total nilai belum cocok', (float) $this->result['unmatched_total']];
        $rows[] = ['Jumlah mutasi perlu ditinjau ulang (jurnal dibalik)', $this->result['stale_count']];

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E5E7EB']]]];
    }
}
