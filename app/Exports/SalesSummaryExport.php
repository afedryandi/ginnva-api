<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Ringkasan Penjualan" (SalesSummaryReport) ke Excel — diminta
 * 2026-09-09, analog tombol "Ekspor Laporan" di halaman Ringkasan
 * Penjualan Majoo.
 *
 * BUKAN FromQuery (halaman ini bukan Filament Table, hasilnya array
 * hasil agregasi dari getResult()) — pakai FromArray, baris-barisnya
 * MENIRU PERSIS apa yang tampil di layar, termasuk baris "Tidak
 * berlaku"/"Belum tersedia" apa adanya (BUKAN dikonversi jadi 0 atau
 * dikosongkan) supaya file Excel-nya tidak menyesatkan kalau dibuka
 * terpisah tanpa konteks halaman webnya.
 */
class SalesSummaryExport implements FromArray, WithStyles
{
    public function __construct(private array $result) {}

    public function array(): array
    {
        $r = $this->result;
        $rupiah = fn ($n) => number_format($n, 0, ',', '.');

        return [
            ['Ringkasan Penjualan'],
            ['Periode', $r['from']->format('d M Y') . ' - ' . $r['to']->format('d M Y')],
            [],
            ['PENDAPATAN'],
            ['Penjualan Kotor', $rupiah($r['grossSales'])],
            ['Ongkos Kirim', 'Tidak berlaku'],
            ['Biaya Pelayanan / MDR', 'Tidak berlaku'],
            ['Pajak (PPN)', 'Belum tersedia'],
            ['Total Pendapatan', $rupiah($r['grossSales'])],
            [],
            ['BIAYA PROMOSI'],
            ['Promo Voucher', '(' . $rupiah($r['voucherDiscount']) . ')'],
            ['Reward Poin (nilai Rp)', 'Belum tersedia'],
            ['Total Biaya Promosi', '(' . $rupiah($r['voucherDiscount']) . ')'],
            [],
            ['PENJUALAN BERSIH'],
            ['Total Penjualan', $rupiah($r['grossSales'])],
            ['Pengembalian (Refund)', $r['refund'] > 0 ? '(' . $rupiah($r['refund']) . ')' : '-'],
            ['Total Penjualan Bersih', $rupiah($r['netSales'])],
            [],
            ['LABA KOTOR'],
            ['Penjualan Bersih', $rupiah($r['netSales'])],
            ['HPP (Harga Pokok Penjualan)', 'Belum tersedia'],
            ['Komisi Partner', 'Belum tersedia'],
            ['Total Laba Kotor', 'Belum tersedia — perlu HPP untuk akurat'],
            [],
            ['Jumlah Transaksi', $r['bookingCount']],
        ];
    }

    /**
     * Nomor baris di bawah ini HARDCODED sesuai urutan persis array()
     * di atas -- kalau array() diubah (baris ditambah/dihapus/diurut
     * ulang), nomor baris di sini WAJIB disesuaikan juga, atau warna
     * section header jadi salah tempat (bukan error fatal, cuma
     * kosmetik keliru).
     */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            'A4' => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']]],
            'A11' => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']]],
            'A16' => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']]],
            'A21' => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']]],
            'A4:B4' => ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '166534']]],
            'A11:B11' => ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '854D0E']]],
            'A16:B16' => ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']]],
            'A21:B21' => ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '374151']]],
        ];
    }
}
