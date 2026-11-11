<?php

namespace App\Filament\Resources\AttendanceResource\Pages;

use App\Filament\Resources\AttendanceResource;
use App\Filament\Widgets\NotCheckedInTodayWidget;
use App\Models\User;
use App\Models\Store;
use App\Services\AlphaCleanupService;
use App\Services\AttendanceCorrectionService;
use Filament\Forms\Get;
use Illuminate\Support\HtmlString;
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

            // Libur yang lupa ditetapkan: Alpha dibuat dini hari untuk kemarin dan tanggal yang sudah lewat tidak bisa ditutup di Kalender
            // Kapasitas & Libur, jadi baris Alpha-nya dihapus lewat sini (hanya akses penuh, alasan wajib, tercatat di Riwayat Aktivitas).
            Actions\Action::make('clearAlphaForHoliday')
                ->label('Hapus Alpha Hari Libur')
                ->icon('heroicon-o-trash')
                ->color('gray')
                ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                ->modalHeading('Hapus Alpha Hari Libur')
                ->modalDescription('Untuk libur yang lupa ditetapkan: menghapus baris Alpha pada satu tanggal (kemarin atau sebelumnya, paling jauh ' . AlphaCleanupService::MAX_DAYS_BACK . ' hari). Hanya baris Alpha yang dihapus; absen, izin, dan cuti tidak tersentuh. Tidak bisa dibatalkan, dan dicatat di Riwayat Aktivitas.')
                ->modalWidth('lg')
                ->modalSubmitActionLabel('Hapus Alpha')
                ->form([
                    Forms\Components\DatePicker::make('date')
                        ->label('Tanggal Libur')
                        ->native(false)
                        ->required()
                        ->default(today()->subDay())
                        ->maxDate(today()->subDay())
                        ->minDate(today()->subDays(AlphaCleanupService::MAX_DAYS_BACK))
                        ->live(),
                    Forms\Components\Select::make('store_id')
                        ->label('Toko')
                        ->placeholder('Semua toko')
                        ->options(fn () => Store::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                        ->helperText('Kosongkan untuk libur nasional atau cuti bersama yang berlaku di semua toko.')
                        ->live(),
                    Forms\Components\Placeholder::make('preview')
                        ->label('Alpha yang akan dihapus')
                        ->content(function (Get $get) {
                            if (! $get('date')) {
                                return '—';
                            }

                            $rows = app(AlphaCleanupService::class)->candidates($get('date'), $get('store_id') ? (int) $get('store_id') : null)->with('user:id,name')->get();

                            if ($rows->isEmpty()) {
                                return 'Tidak ada baris Alpha pada tanggal ini.';
                            }

                            $names = $rows->map(fn ($row) => e($row->user?->name ?? '—'))->take(10)->implode(', ');
                            $more = $rows->count() > 10 ? ' dan ' . ($rows->count() - 10) . ' lainnya' : '';

                            return new HtmlString('<strong>' . $rows->count() . ' baris</strong>: ' . $names . $more);
                        }),
                    Forms\Components\TextInput::make('reason')
                        ->label('Alasan')
                        ->placeholder('Mis. Libur Nasional - Maulid Nabi')
                        ->required()
                        ->maxLength(255),
                ])
                ->action(function (array $data) {
                    try {
                        $count = app(AlphaCleanupService::class)->remove($data['date'], $data['store_id'] ?: null, $data['reason'], auth()->user());
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Alpha tidak dihapus')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    $count > 0
                        ? Notification::make()->title("{$count} baris Alpha dihapus")->success()->send()
                        : Notification::make()->title('Tidak ada Alpha pada tanggal itu')->warning()->send();
                }),

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

                    try {
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
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Pengajuan tidak dikirim')->body($e->getMessage())->danger()->send();

                        return;
                    }

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
