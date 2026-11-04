<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
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
 * MENIRU PERSIS apa yang tampil di layar. Baris Pajak (PPN), blok Laba Kotor, dan baris "Tidak berlaku"/"Belum tersedia" dihapus
 * 2026-11-04 (lihat SalesSummaryReport).
 */
class SalesSummaryExport implements FromArray, WithStyles, WithColumnFormatting
{
    public function __construct(private array $result) {}

    public function array(): array
    {
        $r = $this->result;

        // Semua nominal ditulis sebagai ANGKA (bukan teks berformat) supaya bisa dijumlahkan/difilter di Excel.
        // Pengurang (voucher, refund) bernilai negatif -- ditampilkan "(1.234)" lewat format kolom, dan nol tampil "-"
        // (lihat columnFormats()). Baris yang memang tidak tersedia tetap teks apa adanya.
        $number = fn ($n) => round((float) $n, 2);
        $negative = fn ($n) => (float) $n > 0 ? -round((float) $n, 2) : 0.0;   // hindari -0.0

        return [
            ['Ringkasan Penjualan'],
            ['Periode', $r['from']->format('d M Y') . ' - ' . $r['to']->format('d M Y')],
            [],
            ['PENDAPATAN'],
            ['Penjualan Kotor', $number($r['grossSales'])],
            [],
            ['BIAYA PROMOSI'],
            ['Promo Voucher', $negative($r['voucherDiscount'])],
            ['Promo Total Pembelian', $negative($r['spendPromoDiscount'])],
            ['Total Biaya Promosi', $negative($r['promoTotal'])],
            [],
            ['PENJUALAN BERSIH'],
            ['Total Penjualan', $number($r['grossSales'])],
            ['Pengembalian (Refund)', $negative($r['refund'])],
            ['Total Penjualan Bersih', $number($r['netSales'])],
            [],
            ['Jumlah Transaksi', $r['bookingCount']],
            [],
            ['Catatan', 'Penjualan Kotor adalah nominal transaksi yang sudah setelah potongan promo dan voucher, jadi Biaya Promosi tidak dikurangkan lagi.'],
        ];
    }

    /** Kolom nilai: ribuan, pengurang di dalam kurung, nol sebagai "-". Sel teks ("Tidak berlaku", dst.) tidak terpengaruh. */
    public function columnFormats(): array
    {
        return ['B' => '#,##0;(#,##0);"-"'];
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
            'A7' => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']]],
            'A12' => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']]],
            'A4:B4' => ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '166534']]],
            'A7:B7' => ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '854D0E']]],
            'A12:B12' => ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1D4ED8']]],
        ];
    }
}
