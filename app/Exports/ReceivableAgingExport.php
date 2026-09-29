<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Export "Umur Piutang" (ReceivableAgingReport) ke Excel -- audit 2026-09-29. */
class ReceivableAgingExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $aging, private string $storeLabel) {}

    public function headings(): array
    {
        return ['Toko', 'Customer', 'Belum Jatuh Tempo', '1-30 Hari', '31-60 Hari', '61-90 Hari', '> 90 Hari', 'Total'];
    }

    public function array(): array
    {
        $rows = $this->aging['rows']->map(fn ($r) => [
            $this->storeLabel,
            $r->customer,
            (float) $r->current_amt,
            (float) $r->b1,
            (float) $r->b2,
            (float) $r->b3,
            (float) $r->b4,
            (float) $r->total,
        ])->all();

        $rows[] = ['', 'Total', (float) $this->aging['totals']['current_amt'], (float) $this->aging['totals']['b1'], (float) $this->aging['totals']['b2'], (float) $this->aging['totals']['b3'], (float) $this->aging['totals']['b4'], (float) $this->aging['totals']['total']];

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        $total = count($this->aging['rows']) + 2;

        return [
            1 => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']]],
            $total => ['font' => ['bold' => true]],
        ];
    }
}
