<?php

namespace App\Filament\Resources\BlockedDateResource\Pages;

use App\Filament\Resources\BlockedDateResource;
use App\Models\BlockedDate;
use App\Models\Store;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;

class ListBlockedDates extends ListRecords
{
    protected static string $resource = BlockedDateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Libur nasional dan cuti bersama ditentukan manual oleh staf yang bertanggung jawab (keputusan pemilik 2026-10-28), jadi
            // tidak ada kalender libur tersendiri: tanggal tidak tersedia inilah yang dipakai booking, absensi (tidak ada Alpha), dan
            // hitungan jam kerja gaji. Aksi ini menutup SEMUA toko aktif sekaligus supaya staf tidak mengulang per toko.
            Actions\Action::make('closeAllStores')
                ->label('Tutup Semua Toko')
                ->icon('heroicon-o-building-storefront')
                ->color('gray')
                ->visible(fn () => BlockedDateResource::canCreate() && (auth()->user()?->isFullAccess() ?? false))
                ->modalDescription('Menutup semua toko aktif pada tanggal ini, misalnya libur nasional atau cuti bersama. Tanggal ini tidak bisa dibooking, tidak dihitung Alpha, dan tidak dihitung jam kerjanya di gaji. Booking yang sudah ada tidak dibatalkan.')
                ->form([
                    Forms\Components\DatePicker::make('date')->label('Tanggal')->native(false)->required()->minDate(today()),
                    Forms\Components\DatePicker::make('date_end')->label('Sampai Tanggal (opsional)')->native(false)->after('date')->helperText('Kosongkan untuk satu hari saja.'),
                    Forms\Components\TextInput::make('reason')->label('Alasan')->required()->maxLength(255)->placeholder('Mis. Libur Nasional - Hari Kemerdekaan'),
                ])
                ->action(function (array $data) {
                    $start = Carbon::parse($data['date'])->startOfDay();
                    $end = filled($data['date_end'] ?? null) ? Carbon::parse($data['date_end'])->startOfDay() : $start->copy();
                    $created = 0;
                    $skipped = 0;

                    foreach (Store::where('is_active', true)->get() as $store) {
                        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
                            $row = BlockedDate::firstOrCreate(
                                ['store_id' => $store->id, 'date' => $day->toDateString()],
                                ['reason' => $data['reason']],
                            );
                            $row->wasRecentlyCreated ? $created++ : $skipped++;
                        }
                    }

                    Notification::make()
                        ->title("{$created} tanggal ditutup")
                        ->body($skipped > 0 ? "{$skipped} sudah tertutup sebelumnya dan dilewati." : null)
                        ->success()
                        ->send();
                }),
            Actions\CreateAction::make()->label('Blokir Tanggal'),
        ];
    }

    // Gap "tampilan kalender ringkas" diperbaiki 2026-09-25 (audit
    // Tanggal Tidak Tersedia) -- lihat App\Filament\Widgets\
    // BlockedDateCalendarWidget.
    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Widgets\BlockedDateCalendarWidget::class,
        ];
    }
}
