<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Buku Besar" (GeneralLedgerReport) ke Excel. FromArray karena
 * hasilnya array dari FinancialStatementService::generalLedger() (baris
 * "Saldo Awal" + mutasi + baris "Total Mutasi Periode Ini"), bukan
 * Eloquent Builder biasa.
 */
class GeneralLedgerExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result) {}

    public function headings(): array
    {
        return [
            'Tanggal',
            'No. Jurnal',
            'Keterangan',
            'Debit',
            'Kredit',
            'Saldo Berjalan',
        ];
    }

    public function array(): array
    {
        $r = $this->result;
        $rows = [];

        $rows[] = ['Saldo Awal', '', '', '', '', (float) $r['opening_balance']];

        foreach ($r['rows'] as $row) {
            $rows[] = [
                $row['entry_date']->format('Y-m-d'),
                $row['entry_number'],
                $row['description'],
                $row['debit'] > 0 ? (float) $row['debit'] : '—',
                $row['credit'] > 0 ? (float) $row['credit'] : '—',
                (float) $row['running_balance'],
            ];
        }

        $rows[] = [
            'Total Mutasi Periode Ini', '', '',
            (float) $r['total_debit'],
            (float) $r['total_credit'],
            (float) $r['closing_balance'],
        ];

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        $lastRow = 2 + count($this->result['rows']);

        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']],
            ],
            2 => ['font' => ['italic' => true], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F3F4F6']]],
            $lastRow => ['font' => ['bold' => true]],
        ];
    }
}
