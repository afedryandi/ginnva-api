<?php

namespace App\Filament\Resources\BookingResource\Pages;

use App\Exports\BookingExport;
use App\Filament\Resources\BookingResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Export Excel sejajar dengan tombol utama di kepala halaman (sebelumnya di baris sendiri di dalam kartu tabel).
            Actions\Action::make('export')
                ->label('Export Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->action(function () {
                    $storeId = auth()->user()?->isFullAccess()
                        ? null
                        : auth()->user()?->store_id;

                    // Ekspor mengikuti filter & pencarian tabel yang aktif (keputusan 2026-10-06).
                    $ids = $this->getFilteredTableQuery()->reorder()->pluck('bookings.id')->all();

                    return Excel::download(
                        new BookingExport($storeId, $ids),
                        'booking-' . now()->format('Ymd') . '.xlsx'
                    );
                }),

            Actions\CreateAction::make()->label('Tambah Booking'),
        ];
    }

    /**
     * Status sebagai tab di atas daftar (menggantikan filter Status dan filter bawaan "Aktif & akan datang"): "Aktif" bawaan, lalu Semua dan
     * tiap status, dengan jumlah di tab. "Aktif": pending/confirmed APAPUN tanggalnya (pending lewat tanggal dan job multi-hari yang mulai
     * kemarin tidak boleh hilang), ditambah yang selesai/batal bertanggal hari ini atau mendatang. Jumlah mengikuti pembatasan toko pengguna.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $base = fn () => BookingResource::getEloquentQuery();

        $tabs = [
            'aktif' => Tab::make('Aktif')
                ->badge($base()->where(fn (Builder $q) => $q->whereIn('bookings.status', ['pending', 'confirmed'])->orWhereDate('bookings.preferred_date', '>=', today()))->count())
                ->badgeColor('primary')
                ->modifyQueryUsing(fn (Builder $query) => $query->where(fn (Builder $q) => $q
                    ->whereIn('bookings.status', ['pending', 'confirmed'])
                    ->orWhereDate('bookings.preferred_date', '>=', today()))),
            'semua' => Tab::make('Semua'),
        ];

        foreach ([
            'pending'   => ['Menunggu', 'warning'],
            'confirmed' => ['Dikonfirmasi', 'info'],
            'completed' => ['Selesai', 'success'],
            'cancelled' => ['Dibatalkan', 'danger'],
        ] as $status => [$label, $color]) {
            $tabs[$status] = Tab::make($label)
                ->badge($base()->where('bookings.status', $status)->count())
                ->badgeColor($color)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('bookings.status', $status));
        }

        return $tabs;
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'aktif';
    }
}
