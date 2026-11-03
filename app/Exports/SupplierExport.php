<?php

namespace App\Exports;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export daftar Supplier. $withBanking: NPWP dan rekening hanya bagi yang boleh melihatnya (SupplierResource::canSeeBanking());
 * $query: daftar yang sedang difilter di layar (kosong = semua).
 */
class SupplierExport implements FromCollection, WithHeadings, WithStyles
{
    public function __construct(private bool $withBanking = false, private ?Builder $query = null)
    {
    }

    public function headings(): array
    {
        $headings = ['Supplier', 'Telepon', 'Email', 'Alamat', 'Status', 'Pemasok Utama untuk (bahan/barang)', 'Pembelian Diterima'];

        return $this->withBanking ? array_merge($headings, ['NPWP', 'Bank', 'No. Rekening', 'Atas Nama']) : $headings;
    }

    public function collection(): Collection
    {
        $rows = ($this->query ?? Supplier::query()->orderBy('name'))
            ->withCount(['suppliedMaterials', 'suppliedConsumables'])
            ->withCount(['purchaseRequests as received_count' => fn ($q) => $q->withoutGlobalScopes()->where('status', 'fulfilled')])
            ->get();

        return $rows->map(function (Supplier $supplier) {
            $row = [
                $supplier->name,
                $supplier->phone ?? '-',
                $supplier->email ?? '-',
                $supplier->address ?? '-',
                $supplier->is_active ? 'Aktif' : 'Nonaktif',
                (int) $supplier->supplied_materials_count + (int) $supplier->supplied_consumables_count,
                (int) $supplier->received_count,
            ];

            return $this->withBanking
                ? array_merge($row, [$supplier->npwp ?? '-', $supplier->bank_name ?? '-', $supplier->bank_account_number ?? '-', $supplier->bank_account_name ?? '-'])
                : $row;
        });
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F2937']],
            ],
        ];
    }
}