<?php

namespace App\Filament\Resources\WarrantyResource\Pages;

use App\Filament\Resources\WarrantyResource;
use App\Exports\WarrantyExport;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;

class ListWarranties extends ListRecords
{
    protected static string $resource = WarrantyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Export Excel sejajar dengan tombol utama di kepala halaman (sebelumnya di baris sendiri di dalam kartu tabel).
            // Untuk dikirim manual ke tim Ginnva China; hanya akses penuh karena memuat data pelanggan lengkap. Mengikuti filter dan
            // pencarian yang aktif di tabel; rentang tanggal dari form ini ditambahkan di atasnya.
            Actions\Action::make('exportExcel')
                ->label('Export Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                ->form([
                    Forms\Components\DatePicker::make('start_date')
                        ->label('Dari Tanggal (opsional)')
                        ->helperText('Kosongkan untuk export semua data dari awal.'),

                    Forms\Components\DatePicker::make('end_date')
                        ->label('Sampai Tanggal (opsional)')
                        ->helperText('Kosongkan untuk export sampai data terbaru.'),
                ])
                ->action(function (array $data) {
                    $query = $this->getFilteredTableQuery()
                        ->when($data['start_date'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['end_date'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date));

                    return Excel::download(new WarrantyExport($query), 'warranty-export-' . now()->format('Ymd-His') . '.xlsx');
                }),

            Actions\CreateAction::make(),
        ];
    }

    /**
     * Tab per kebutuhan sehari-hari: Semua (bawaan), Menunggu review (QA), Aktif, Akan berakhir (30 hari), dan Berakhir. Aturannya sama dengan
     * filter Status Garansi dan Akan Berakhir di tabel, jadi angka di tab sama dengan hasil filternya. Jumlah mengikuti pembatasan toko.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $base = fn () => WarrantyResource::getEloquentQuery()->withoutEagerLoads();

        $pending = fn (Builder $q) => $q->where('review_status', 'pending_review');
        $active = fn (Builder $q) => $q->where('review_status', 'approved')->where('status', 'active')->whereDate('expiry_date', '>=', now());
        $soon = fn (Builder $q) => $q->where('review_status', 'approved')->whereDate('expiry_date', '>=', now())->whereDate('expiry_date', '<=', now()->addDays(30));
        $expired = fn (Builder $q) => $q->where('review_status', 'approved')->where('status', '!=', 'revoked')->whereDate('expiry_date', '<', now());

        return [
            'semua' => Tab::make('Semua'),
            'menunggu' => Tab::make('Menunggu review')
                ->badge($pending($base())->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $pending($query)),
            'aktif' => Tab::make('Aktif')
                ->badge($active($base())->count())
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $active($query)),
            'segera' => Tab::make('Akan berakhir')
                ->badge($soon($base())->count())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $soon($query)),
            'berakhir' => Tab::make('Berakhir')
                ->modifyQueryUsing(fn (Builder $query) => $expired($query)),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'semua';
    }
}
