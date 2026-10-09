<?php

namespace App\Filament\Resources\WarrantyResource\RelationManagers;

use App\Models\WarrantyMaintenanceSchedule;
use App\Services\MaintenanceBookingService;
use App\Services\PushNotificationService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/**
 * Bagian C, "Klaim Garansi & Maintenance PPF" (2026-10-01) -- murni untuk staff
 * lihat riwayat tiap occurrence (semua status berubah OTOMATIS lewat sistem:
 * command harian + aksi customer di app, lihat WarrantyMaintenanceSchedule).
 * Tidak ada create/edit manual -- sesuai keputusan user, staff tidak override.
 *
 * Satu pengecualian (2026-10-09): selain customer konfirmasi sendiri di aplikasi, sales/staf boleh menindaklanjuti
 * customer (mis. lewat WhatsApp) dan membuatkan booking maintenance-nya lewat aksi "Buatkan Booking". Aturannya sama
 * persis dengan jalur customer (MaintenanceBookingService).
 */
class MaintenanceSchedulesRelationManager extends RelationManager
{
    protected static string $relationship = 'maintenanceSchedules';

    protected static ?string $title = 'Jadwal Maintenance';

    protected static ?string $modelLabel = 'Jadwal';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('sequence')
            ->columns([
                Tables\Columns\TextColumn::make('sequence')
                    ->label('Ke-'),

                Tables\Columns\TextColumn::make('scheduled_date')
                    ->label('Jadwal')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'Menunggu Jadwal',
                        'confirmation_sent' => 'Menunggu Konfirmasi',
                        'confirmed' => 'Dikonfirmasi',
                        'forfeited' => 'Hangus',
                        'completed' => 'Selesai',
                        default => $state,
                    })
                    ->colors([
                        'gray' => 'pending',
                        'warning' => 'confirmation_sent',
                        'info' => 'confirmed',
                        'danger' => 'forfeited',
                        'success' => 'completed',
                    ]),

                Tables\Columns\TextColumn::make('reminder_sent_at')
                    ->label('Konfirmasi Dikirim')
                    ->dateTime('d M Y H:i')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('responded_at')
                    ->label('Direspons')
                    ->dateTime('d M Y H:i')
                    ->placeholder('—'),

                // Drill-down ke booking yang tercipta saat customer confirm
                // (lihat MaintenanceScheduleController::confirm()).
                Tables\Columns\TextColumn::make('booking.booking_number')
                    ->label('Booking')
                    ->placeholder('—')
                    ->url(fn ($record) => $record->booking
                        ? \App\Filament\Resources\BookingResource::getUrl('view', ['record' => $record->booking->id])
                        : null),
            ])
            ->defaultSort('sequence', 'desc')
            // Gap ditutup 2026-10-01 (audit Maintenance PPF) -- SEBELUMNYA
            // tidak ada filter sama sekali, staff tidak bisa cepat
            // menyaring mis. 'forfeited' saja saat riwayat warranty-nya
            // sudah panjang.
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Menunggu Jadwal',
                        'confirmation_sent' => 'Menunggu Konfirmasi',
                        'confirmed' => 'Dikonfirmasi',
                        'forfeited' => 'Hangus',
                        'completed' => 'Selesai',
                    ]),
            ])
            ->headerActions([])
            ->actions([
                Tables\Actions\Action::make('book_for_customer')
                    ->label('Buatkan Booking')
                    ->icon('heroicon-o-calendar-days')
                    ->color('primary')
                    ->visible(fn (WarrantyMaintenanceSchedule $record) => in_array($record->status, ['pending', 'confirmation_sent'], true)
                        && $record->warranty?->status !== 'revoked')
                    ->modalHeading('Buatkan Booking Maintenance')
                    ->modalDescription('Gunakan setelah customer dihubungi dan setuju datang. Booking dibuat berstatus Menunggu untuk customer ini.')
                    ->form([
                        Forms\Components\DatePicker::make('preferred_date')
                            ->label('Tanggal Kedatangan')
                            ->required()
                            ->default(fn (WarrantyMaintenanceSchedule $record) => $record->scheduled_date->copy()->max(today())->toDateString())
                            ->minDate(today())
                            ->maxDate(today()->addDays(30))
                            ->helperText('Boleh berbeda dari tanggal jadwal. Maksimal 30 hari ke depan; toko tidak boleh tutup dan kapasitas harus cukup.'),
                    ])
                    ->action(function (WarrantyMaintenanceSchedule $record, array $data) {
                        $result = app(MaintenanceBookingService::class)->book($record, Carbon::parse($data['preferred_date']), 'whatsapp');

                        if (! $result['ok']) {
                            Notification::make()->title('Booking tidak dibuat')->body($result['message'])->danger()->send();

                            return;
                        }

                        $booking = $result['booking'];

                        // Customer diberi tahu (push gagal tidak boleh membatalkan booking yang sudah tercatat).
                        try {
                            app(PushNotificationService::class)->sendToCustomer(
                                $booking->customer_id,
                                'Booking Maintenance PPF Dibuat',
                                "Tim kami membuatkan booking maintenance PPF untuk {$booking->preferred_date->format('d M Y')}. Toko akan menghubungi Anda untuk finalisasi jadwal.",
                                ['type' => 'ppf_maintenance_booked', 'booking_id' => $booking->id, 'route' => "/booking/{$booking->id}/chat"]
                            );
                        } catch (\Throwable $e) {
                            report($e);
                        }

                        Notification::make()->title('Booking maintenance dibuat')->body("Nomor: {$booking->booking_number}")->success()->send();
                    }),
            ])
            ->bulkActions([]);
    }
}
