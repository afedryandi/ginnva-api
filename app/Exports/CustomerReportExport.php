<?php

namespace App\Exports;

use App\Models\Customer;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export "Laporan Pelanggan" (CustomerReport) ke Excel — daftar Top 20
 * Pelanggan, kolom sama persis dengan tabel di layar.
 */
class CustomerReportExport implements FromArray, WithHeadings, WithStyles
{
    public function __construct(private array $result) {}

    public function headings(): array
    {
        return [
            'Pelanggan',
            'Kontak',
            'Tanggal Registrasi',
            'Booking (Periode Ini)',
            'Belanja (Periode Ini)',
            'Total Booking (Sepanjang Waktu)',
            'Total Belanja (Sepanjang Waktu)',
            'Kunjungan Terakhir',
            'Rata-rata Kunjungan/Bulan',
            'Rata-rata Belanja/Bulan',
        ];
    }

    public function array(): array
    {
        return collect($this->result['topCustomers'])
            ->map(fn (Customer $customer) => [
                $customer->name,
                $customer->phone_number ?? $customer->email ?? '-',
                optional($customer->created_at)->format('Y-m-d'),
                $customer->bookings_in_period,
                (float) $customer->spend_in_period,
                $customer->bookings_all_time,
                (float) $customer->spend_all_time,
                $customer->last_visit ? Carbon::parse($customer->last_visit)->format('Y-m-d') : '-',
                $customer->avg_bookings_per_month,
                (float) $customer->avg_spend_per_month,
            ])
            ->values()
            ->all();
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '166534']],
            ],
        ];
    }
}
