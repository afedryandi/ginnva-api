<?php

namespace App\Exports;

use App\Models\Booking;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class BookingExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    public function __construct(private ?int $storeId = null) {}

    public function query(): Builder
    {
        $query = Booking::with(['customer', 'store']);

        if ($this->storeId) {
            $query->where('store_id', $this->storeId);
        }

        return $query->orderBy('preferred_date', 'desc');
    }

    public function headings(): array
    {
        return [
            'No. Booking', 'Nama Customer', 'Email Customer', 'No. WhatsApp',
            'Toko', 'Jenis Layanan', 'Tanggal Diinginkan', 'Jam Diinginkan',
            'Status', 'Catatan', 'Diajukan Pada',
        ];
    }

    public function map($booking): array
    {
        return [
            $booking->booking_number,
            $booking->customer?->name ?? '—',
            $booking->customer?->email ?? '—',
            $booking->customer?->phone_number ?? '—',
            $booking->store?->name ?? '—',
            $booking->service_type,
            $booking->preferred_date?->format('d/m/Y'),
            $booking->preferred_time,
            match ($booking->status) {
                'pending'   => 'Menunggu Konfirmasi',
                'confirmed' => 'Dikonfirmasi',
                'completed' => 'Selesai',
                'cancelled' => 'Dibatalkan',
                default     => $booking->status,
            },
            $booking->notes,
            $booking->created_at?->format('d/m/Y H:i'),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
