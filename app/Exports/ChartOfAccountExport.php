<?php

namespace App\Exports;

use App\Models\ChartOfAccount;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Ekspor daftar Bagan Akun (audit Bagan Akun 2026-09-28). Kolom pertama 8
 * (kode..kategori_arus_kas) sama dengan format impor
 * (ChartOfAccountResource "Impor Akun"), jadi file ekspor bisa dipakai
 * sebagai template. Kolom "saldo" hanya informasi, diabaikan saat impor.
 */
class ChartOfAccountExport implements FromArray, WithHeadings, WithStyles
{
    public const HEADINGS = ['kode', 'nama', 'klasifikasi', 'kode_induk', 'postable', 'akun_kas', 'akun_kontra', 'kategori_arus_kas', 'saldo'];

    public function headings(): array
    {
        return self::HEADINGS;
    }

    public function array(): array
    {
        $accounts = ChartOfAccount::query()->orderBy('code')->get();
        $codes = $accounts->pluck('code', 'id');

        return $accounts->map(fn (ChartOfAccount $a) => [
            $a->code,
            $a->name,
            $a->type,
            $a->parent_id ? ($codes[$a->parent_id] ?? '') : '',
            $a->is_postable ? 'ya' : 'tidak',
            $a->is_cash ? 'ya' : 'tidak',
            $a->is_contra ? 'ya' : 'tidak',
            $a->cash_flow_category ?? '',
            $a->balance(),
        ])->all();
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
