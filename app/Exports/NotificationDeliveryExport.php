<?php

namespace App\Exports;

use App\Models\NotificationDelivery;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Log pengiriman push 90 hari terakhir (data lebih lama dibersihkan otomatis atau terlalu besar untuk satu file). */
class NotificationDeliveryExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    public function query(): Builder
    {
        return NotificationDelivery::query()
            ->with(['user:id,name', 'customer:id,name,deleted_at', 'campaign:id,title'])
            ->where('sent_at', '>=', now()->subDays(90))
            ->orderByDesc('sent_at');
    }

    public function headings(): array
    {
        return ['Dikirim', 'Penerima', 'Judul', 'Kampanye', 'Hasil', 'Keterangan', 'Kuitansi Diperiksa'];
    }

    public function map($delivery): array
    {
        return [
            $delivery->sent_at?->format('d/m/Y H:i'),
            $delivery->user?->name ?? ($delivery->customer ? ($delivery->customer->deleted_at ? '(Akun Dihapus)' : $delivery->customer->name) : 'Perangkat tanpa akun'),
            $delivery->title,
            $delivery->campaign?->title,
            NotificationDelivery::STATUS_LABELS[$delivery->status] ?? $delivery->status,
            $delivery->error,
            $delivery->checked_at?->format('d/m/Y H:i'),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
