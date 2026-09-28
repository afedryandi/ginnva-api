<?php

namespace App\Exports;

use App\Models\JournalEntry;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Ekspor Jurnal Umum (audit Jurnal Umum 2026-09-29, gap "tidak ada ekspor"):
 * 1 baris per BARIS JURNAL (bukan per jurnal) supaya bisa diolah auditor
 * di Excel -- nomor, tanggal, status, sumber, toko, akun, debit, kredit.
 */
class JournalEntryExport implements FromArray, WithHeadings, WithStyles
{
    /** @param Collection<int, JournalEntry> $entries (dengan relasi lines.account & store sudah di-load) */
    public function __construct(private Collection $entries) {}

    public function headings(): array
    {
        return ['No. Jurnal', 'Tanggal', 'Status', 'Sumber', 'Toko', 'Keterangan', 'Kode Akun', 'Nama Akun', 'Debit', 'Kredit'];
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->entries as $entry) {
            foreach ($entry->lines as $line) {
                $rows[] = [
                    $entry->entry_number,
                    $entry->entry_date->format('Y-m-d'),
                    $entry->status,
                    $entry->reference_type ?? 'manual',
                    $entry->store?->name ?? 'Company-wide',
                    $entry->description,
                    $line->account?->code,
                    $line->account?->name,
                    (float) $line->debit,
                    (float) $line->credit,
                ];
            }
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
