<?php

namespace App\Exports;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CustomerExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    public function query(): Builder
    {
        return Customer::withCount(['warranties', 'bookings'])->orderBy('created_at', 'desc');
    }

    public function headings(): array
    {
        return [
            'Nama', 'Email', 'No. WhatsApp',
            'Email Terverifikasi', 'Jumlah Garansi', 'Jumlah Booking',
            'Terdaftar Pada',
        ];
    }

    public function map($customer): array
    {
        return [
            $customer->name ?? '—',
            $customer->email,
            $customer->phone_number ?? '—',
            $customer->email_verified_at?->format('d/m/Y H:i') ?? 'Belum',
            $customer->warranties_count,
            $customer->bookings_count,
            $customer->created_at?->format('d/m/Y H:i'),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
