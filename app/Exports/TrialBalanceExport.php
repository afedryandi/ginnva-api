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
 *
 * Mengikuti tampilan halaman (audit Neraca Saldo 2026-09-29): kalau laporan dibuat dengan
 * "Dari Tanggal" (has_period), kolomnya Saldo Awal | Mutasi Debit | Mutasi Kredit | Saldo Akhir.
 */
class TrialBalanceExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result) {}

    private function hasPeriod(): bool
    {
        return (bool) ($this->result['has_period'] ?? false);
    }

    public function headings(): array
    {
        return $this->hasPeriod()
            ? ['Kode', 'Nama Akun', 'Tipe', 'Saldo Awal', 'Mutasi Debit', 'Mutasi Kredit', 'Saldo Akhir']
            : ['Kode', 'Nama Akun', 'Tipe', 'Debit', 'Kredit', 'Saldo'];
    }

    public function array(): array
    {
        $hasPeriod = $this->hasPeriod();

        $rows = collect($this->result['rows'])
            ->map(function (array $row) use ($hasPeriod) {
                $name = $row['account']->name . ($row['account']->is_contra ? ' (pengurang)' : '');

                $type = \App\Services\FinancialStatementService::TYPE_LABELS[$row['account']->type] ?? $row['account']->type;

                return $hasPeriod
                    ? [$row['account']->code, $name, $type, (float) $row['opening_balance'], (float) $row['period_debit'], (float) $row['period_credit'], (float) $row['balance']]
                    : [$row['account']->code, $name, $type, (float) $row['debit'], (float) $row['credit'], (float) $row['balance']];
            })
            ->values()
            ->all();

        $rows[] = $hasPeriod
            ? ['', 'Total', '', '', (float) collect($this->result['rows'])->sum('period_debit'), (float) collect($this->result['rows'])->sum('period_credit'), '']
            : ['', 'Total', '', (float) $this->result['total_debit'], (float) $this->result['total_credit'], ''];

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
