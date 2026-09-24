<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Neraca Saldo" (TrialBalanceReport) ke Excel. FromArray karena
 * sumbernya array hasil FinancialStatementService::trialBalance() (rows
 * berisi model Account + saldo), bukan Eloquent Builder biasa. Baris
 * "Total" ditambahkan di akhir array, di-bold lewat styles().
 */
class TrialBalanceExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result) {}

    public function headings(): array
    {
        return [
            'Kode',
            'Nama Akun',
            'Debit',
            'Kredit',
            'Saldo',
        ];
    }

    public function array(): array
    {
        $rows = collect($this->result['rows'])
            ->map(fn (array $row) => [
                $row['account']->code,
                $row['account']->name,
                (float) $row['debit'],
                (float) $row['credit'],
                (float) $row['balance'],
            ])
            ->values()
            ->all();

        $rows[] = [
            '',
            'Total',
            (float) $this->result['total_debit'],
            (float) $this->result['total_credit'],
            '',
        ];

        return $rows;
    }

    /**
     * Baris terakhir (Total) di-bold -- posisinya dihitung dinamis dari
     * jumlah baris rows() + 1 (heading) + 1 (baris Total itu sendiri),
     * bukan hardcoded, karena jumlah akun bisa berubah tiap periode.
     */
    public function styles(Worksheet $sheet): array
    {
        $totalRowNumber = count($this->result['rows']) + 2;

        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '1D4ED8'],
                ],
            ],
            $totalRowNumber => ['font' => ['bold' => true]],
        ];
    }
}
