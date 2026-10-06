<?php

namespace App\Exports;

use App\Models\Booking;
use App\Models\BookingMessage;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class BookingExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    /** @param  list<int>|null  $bookingIds  batasi ke id tertentu (hasil filter tabel); null = semua */
    public function __construct(private ?int $storeId = null, private ?array $bookingIds = null) {}

    public function query(): Builder
    {
        $query = Booking::with(['customer', 'store', 'installers:id,name', 'downPayments']);

        if ($this->storeId) {
            $query->where('store_id', $this->storeId);
        }

        if ($this->bookingIds !== null) {
            $query->whereIn('id', $this->bookingIds);
        }

        // id sebagai tiebreaker: tanpa itu urutan baris bertanggal sama bisa
        // bergeser antar chunk dan baris terduplikasi/terlewat.
        return $query->orderBy('preferred_date', 'desc')->orderBy('id', 'desc');
    }

    public function headings(): array
    {
        return [
            'No. Booking', 'Nama Customer', 'Email Customer', 'No. WhatsApp',
            'Toko', 'Jenis Layanan', 'Tanggal Diinginkan', 'Jam Diinginkan',
            'Status', 'Catatan', 'Diajukan Pada',
            'Alasan Pembatalan', 'Dibatalkan Oleh', 'Waktu Pembatalan',
            'Tahap', 'Tahap PPF', 'Installer', 'Durasi (hari)', 'Tanggal Selesai',
            'DP Diterima', 'DP Terpakai', 'Sisa DP',
        ];
    }

    public function map($booking): array
    {
        $dpActive = $booking->downPayments->whereNull('refunded_at');

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
            $booking->cancel_reason,
            ['customer' => 'Customer', 'staff' => 'Staff', 'system' => 'Sistem'][$booking->cancelled_by_type] ?? null,
            $booking->cancelled_at?->format('d/m/Y H:i'),
            $booking->current_stage ? (BookingMessage::allStages()[$booking->current_stage] ?? $booking->current_stage) : null,
            $booking->secondary_stage ? (BookingMessage::allStages()[$booking->secondary_stage] ?? $booking->secondary_stage) : null,
            $booking->installers->pluck('name')->implode(', ') ?: null,
            $booking->effective_duration_days,
            $booking->end_date?->format('d/m/Y'),
            // DP dihitung dari relasi yang sudah di-eager-load (bukan accessor
            // outstanding_down_payment yang query per baris).
            $dpActive->sum(fn ($dp) => (float) $dp->amount) ?: null,
            $dpActive->sum(fn ($dp) => (float) $dp->applied_amount) ?: null,
            $dpActive->sum(fn ($dp) => (float) $dp->amount - (float) $dp->applied_amount - (float) $dp->refunded_amount) ?: null,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
