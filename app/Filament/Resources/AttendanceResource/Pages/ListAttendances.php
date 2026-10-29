<?php

namespace App\Filament\Resources\AttendanceResource\Pages;

use App\Filament\Resources\AttendanceResource;
use App\Filament\Widgets\NotCheckedInTodayWidget;
use App\Models\User;
use App\Services\AttendanceCorrectionService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListAttendances extends ListRecords
{
    protected static string $resource = AttendanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Entri Manual'),

            // "Ajukan Koreksi" (audit Majoo f24) — untuk ENTRI BARU (mis.
            // lupa absen sama sekali), jalur staff non-manager. Manager/
            // full-access pakai "Entri Manual" di atas (langsung, tanpa
            // approval, self-approval tidak menambah kontrol).
            Actions\Action::make('requestCorrection')
                ->label('Ajukan Koreksi')
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->visible(fn () => ! (auth()->user()?->isFullAccess() ?? false) && ! (auth()->user()?->isStoreManager() ?? false))
                ->form([
                    // Staf biasa hanya mengajukan koreksi untuk dirinya sendiri; yang berhak melihat satu toko boleh memilih rekan.
                    Forms\Components\Select::make('user_id')
                        ->label('Karyawan')
                        ->options(fn () => User::where('store_id', auth()->user()?->store_id)
                            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'partner'))
                            ->when(! (auth()->user()?->canSeeStoreAttendance() ?? false), fn ($q) => $q->whereKey(auth()->id()))
                            ->pluck('name', 'id'))
                        ->default(fn () => auth()->user()?->canSeeStoreAttendance() ? null : auth()->id())
                        ->disabled(fn () => ! (auth()->user()?->canSeeStoreAttendance() ?? false))
                        ->dehydrated()
                        ->searchable()
                        ->required(),

                    Forms\Components\DatePicker::make('date')
                        ->label('Tanggal')
                        ->required()
                        ->default(today())
                        ->maxDate(today()),

                    Forms\Components\Select::make('entry_type')
                        ->label('Jenis')
                        ->options([
                            'manual' => 'Manual (device/wifi absen mati)',
                            'field_duty' => 'Dinas Luar / Ambil Kendaraan Client',
                        ])
                        ->default('manual')
                        ->required(),

                    Forms\Components\DateTimePicker::make('clock_in_at')
                        ->label('Jam Masuk')
                        ->seconds(false),

                    Forms\Components\DateTimePicker::make('clock_out_at')
                        ->label('Jam Keluar')
                        ->seconds(false),

                    Forms\Components\Textarea::make('reason')
                        ->label('Alasan')
                        ->required()
                        ->rows(2)
                        ->columnSpanFull(),
                ])
                ->action(function (array $data) {
                    // Jaga sisi server: isian karyawan tidak dipercaya untuk staf yang hanya berhak atas dirinya sendiri.
                    $userId = (auth()->user()?->canSeeStoreAttendance() ?? false) ? $data['user_id'] : auth()->id();

                    app(AttendanceCorrectionService::class)->submit([
                        'attendance_id' => null,
                        'user_id' => $userId,
                        'store_id' => auth()->user()?->store_id,
                        'date' => $data['date'],
                        'entry_type' => $data['entry_type'],
                        'clock_in_at' => $data['clock_in_at'] ?: null,
                        'clock_out_at' => $data['clock_out_at'] ?: null,
                        'reason' => $data['reason'],
                    ], auth()->id());

                    Notification::make()
                        ->title('Permintaan koreksi dikirim')
                        ->body('Menunggu persetujuan store manager/admin sebelum data absensi resmi berubah.')
                        ->success()
                        ->send();
                }),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        // Daftar nama yang belum absen hanya untuk yang berhak melihat satu toko.
        return (auth()->user()?->canSeeStoreAttendance() ?? false) ? [NotCheckedInTodayWidget::class] : [];
    }
}
