<?php

namespace App\Filament\Resources\WarrantyResource\RelationManagers;

use App\Services\PushNotificationService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ClaimsRelationManager extends RelationManager
{
    protected static string $relationship = 'claims';

    protected static ?string $title = 'Riwayat Klaim After-Sales';

    protected static ?string $modelLabel = 'Klaim';

    // Admin toko (store_manager) boleh AJUKAN klaim baru atas nama
    // customer (misal customer datang langsung ke toko) — ini default
    // Filament (create diizinkan kecuali dioverride). Hanya super_admin
    // yang boleh pass/reject, diatur lewat ->visible() pada masing-masing
    // action di bawah, konsisten dengan keputusan akses QA review.

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('category')
                ->label('Kategori After-Sales')
                ->options([
                    'worry_free_wrap' => 'Worry Free Wrap (Anti Gores Bebas Khawatir)',
                    'product_warranty' => 'Product Warranty',
                    'other' => 'Lainnya / Kustomisasi',
                ])
                ->required(),

            Forms\Components\Textarea::make('description')
                ->label('Keluhan / Permintaan Customer')
                ->columnSpanFull(),

            // Penanganan (audit ACCURATE 5, form RMA): siapa yang menangani dan kapan perkiraan selesainya.
            Forms\Components\Select::make('handled_by')
                ->label('Ditangani oleh')
                ->options(fn () => \App\Models\User::query()->where('is_active', true)->role('installer')->orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->placeholder('Belum ditentukan'),

            Forms\Components\DatePicker::make('estimated_completion_date')
                ->label('Perkiraan Selesai')
                ->native(false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('claim_number')
            ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->with(['handler', 'booking.materialMemo.items']))
            ->columns([
                Tables\Columns\TextColumn::make('claim_number')
                    ->label('No. Klaim')
                    ->searchable(),

                Tables\Columns\BadgeColumn::make('category')
                    ->label('Kategori')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'worry_free_wrap' => 'Worry Free Wrap',
                        'product_warranty' => 'Product Warranty',
                        'other' => 'Lainnya',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('description')
                    ->label('Keluhan')
                    ->limit(40)
                    ->placeholder('—'),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'pass',
                        'danger' => 'reject',
                    ]),

                Tables\Columns\TextColumn::make('handler.name')
                    ->label('Ditangani')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('estimated_completion_date')
                    ->label('Perkiraan Selesai')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->color(fn ($record) => $record->isOverdue() ? 'danger' : null)
                    ->description(fn ($record) => $record->isOverdue() ? 'Terlambat' : null)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('materials')
                    ->label('Bahan Terpakai')
                    ->state(function ($record) {
                        $count = $record->materialMemo()?->items->count() ?? 0;

                        return $count > 0 ? "{$count} item" : null;
                    })
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('resolution')
                    ->label('Hasil')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state ? (\App\Models\WarrantyClaim::RESOLUTIONS[$state] ?? $state) : null)
                    ->color('info')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Diajukan')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                // Gap ditutup 2026-10-01 (audit Bagian B) -- SEBELUMNYA tidak
                // ada cara lihat booking kunjungan yang terkait klaim ini
                // (kalau diajukan lewat app, lihat WarrantyClaim::booking()).
                // Klaim lama yang dibuat manual staff (sebelum fitur ini ada)
                // memang tidak punya booking terkait, placeholder '—'.
                Tables\Columns\TextColumn::make('booking.preferred_date')
                    ->label('Jadwal Kunjungan')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->description(fn ($record) => $record->booking?->status
                        ? match ($record->booking->status) {
                            'pending' => 'Menunggu Konfirmasi',
                            'confirmed' => 'Terkonfirmasi',
                            'completed' => 'Selesai',
                            'cancelled' => 'Dibatalkan',
                            default => $record->booking->status,
                        }
                        : null)
                    ->url(fn ($record) => $record->booking
                        ? \App\Filament\Resources\BookingResource::getUrl('view', ['record' => $record->booking->id])
                        : null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'pass' => 'Pass',
                        'reject' => 'Reject',
                    ]),

                Tables\Filters\SelectFilter::make('category')
                    ->options([
                        'worry_free_wrap' => 'Worry Free Wrap',
                        'product_warranty' => 'Product Warranty',
                        'other' => 'Lainnya',
                    ]),
            ])
            ->headerActions([
                // Klaim after-sales cuma masuk akal atas warranty yang
                // sudah lolos QA Certificate review — kalau masih
                // pending_review/rejected, garansinya belum/tidak sah.
                Tables\Actions\CreateAction::make()
                    ->visible(fn () => $this->getOwnerRecord()->review_status === 'approved'),
            ])
            ->actions([
                // Pass/Reject hanya untuk super_admin, sama seperti review
                // QA Certificate di WarrantyResource.
                //
                // BUG DIPERBAIKI 2026-09-25 (audit Garansi): SEBELUMNYA
                // cuma Notification::make() in-app (untuk admin yang
                // klik), tidak ada push ke CUSTOMER pemilik klaim --
                // beda dari Warranty::approve/reject sendiri yang sudah
                // benar (lihat WarrantyObserver::updated()). Customer
                // baru tahu klaimnya diputuskan kalau buka manual
                // "Riwayat Klaim" di app. Tidak ada Observer terpisah
                // untuk WarrantyClaim, jadi push dikirim langsung di sini
                // (satu-satunya jalur pass/reject, tidak ada jalur lain
                // yang perlu diselaraskan).
                Tables\Actions\Action::make('pass')
                    ->label('Pass')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn ($record) => auth()->user()?->isFullAccess() && $record->status === 'pending')
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        $record->update([
                            'status' => 'pass',
                            'rejection_reason' => null,
                            'reviewed_by' => auth()->id(),
                            'reviewed_at' => now(),
                        ]);

                        $customerId = $this->getOwnerRecord()->customer_id;
                        if ($customerId) {
                            app(PushNotificationService::class)->sendToCustomer(
                                $customerId,
                                'Klaim After-Sales Disetujui',
                                "Klaim #{$record->claim_number} Anda telah disetujui (pass)."
                            );
                        }

                        Notification::make()
                            ->title('Klaim disetujui (pass)')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn ($record) => auth()->user()?->isFullAccess() && $record->status === 'pending')
                    ->form([
                        Forms\Components\Textarea::make('rejection_reason')
                            ->label('Alasan Reject')
                            ->required(),
                    ])
                    ->action(function ($record, array $data) {
                        $record->update([
                            'status' => 'reject',
                            'rejection_reason' => $data['rejection_reason'],
                            'reviewed_by' => auth()->id(),
                            'reviewed_at' => now(),
                        ]);

                        $customerId = $this->getOwnerRecord()->customer_id;
                        if ($customerId) {
                            app(PushNotificationService::class)->sendToCustomer(
                                $customerId,
                                'Klaim After-Sales Ditolak',
                                "Klaim #{$record->claim_number} Anda ditolak: {$data['rejection_reason']}"
                            );
                        }

                        Notification::make()
                            ->title('Klaim ditolak')
                            ->warning()
                            ->send();
                    }),

                // Hasil akhir penanganan (audit ACCURATE 5, Aktifitas Proses Klaim): dicatat setelah klaim disetujui (pass).
                // Pelanggan diberi tahu lewat push seperti pada pass/reject.
                // Bahan yang terpakai untuk klaim ini = isi Memo Pengambilan/Pengembalian booking kunjungannya (audit ACCURATE 5,
                // "komponen/servis yang digunakan"). Stok tetap diurus lewat memo; di sini hanya dibaca dan ditautkan.
                Tables\Actions\Action::make('materials')
                    ->label('Bahan Terpakai')
                    ->icon('heroicon-o-cube')
                    ->color('gray')
                    ->visible(fn ($record) => $record->status === 'pass')
                    ->modalHeading(fn ($record) => 'Bahan Terpakai: klaim ' . $record->claim_number)
                    ->modalContent(fn ($record) => view('filament.claims.materials', [
                        'claim' => $record,
                        'booking' => $record->booking,
                        'memo' => $record->materialMemo(),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup'),

                Tables\Actions\Action::make('resolve')
                    ->label('Catat Penyelesaian')
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->color('info')
                    ->visible(fn ($record) => $record->status === 'pass' && $record->resolution === null)
                    ->form(fn ($record) => [
                        Forms\Components\Placeholder::make('materials_hint')
                            ->label('Bahan terpakai')
                            ->content(fn () => ($count = $record->materialMemo()?->items->count() ?? 0) > 0
                                ? "{$count} item tercatat di memo booking kunjungan."
                                : 'Belum ada bahan tercatat. Bila ada film atau bahan yang terpakai, isi dulu lewat tombol "Bahan Terpakai" (Memo Pengambilan).'),
                        Forms\Components\Select::make('resolution')
                            ->label('Hasil Penanganan')
                            ->options(\App\Models\WarrantyClaim::RESOLUTIONS)
                            ->required(),
                        Forms\Components\Textarea::make('resolution_notes')
                            ->label('Catatan')
                            ->rows(3)
                            ->maxLength(1000),
                    ])
                    ->action(function ($record, array $data) {
                        $record->update([
                            'resolution' => $data['resolution'],
                            'resolution_notes' => $data['resolution_notes'] ?? null,
                            'resolved_at' => now(),
                            'handled_by' => $record->handled_by ?? auth()->id(),
                        ]);

                        $customerId = $this->getOwnerRecord()->customer_id;
                        if ($customerId) {
                            app(PushNotificationService::class)->sendToCustomer(
                                $customerId,
                                'Klaim After-Sales Selesai Ditangani',
                                "Klaim #{$record->claim_number} Anda sudah ditangani: " . \App\Models\WarrantyClaim::RESOLUTIONS[$data['resolution']] . '.'
                            );
                        }

                        Notification::make()
                            ->title('Penyelesaian klaim dicatat')
                            ->success()
                            ->send();
                    }),

                // Begitu klaim sudah di-pass/reject, itu jadi riwayat resmi
                // — cuma super_admin yang boleh koreksi/hapus (mis. kalau
                // memang ada salah input). Store Manager masih bebas
                // edit/hapus SELAMA klaim masih 'pending' (belum diputuskan).
                Tables\Actions\EditAction::make()
                    ->visible(fn ($record) => $record->status === 'pending' || auth()->user()?->isFullAccess()),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn ($record) => $record->status === 'pending' || auth()->user()?->isFullAccess()),
            ])
            ->defaultSort('created_at', 'desc');
    }
}