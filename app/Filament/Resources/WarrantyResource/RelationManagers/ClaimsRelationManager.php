<?php

namespace App\Filament\Resources\WarrantyResource\RelationManagers;

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
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('claim_number')
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

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Diajukan')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
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

                        Notification::make()
                            ->title('Klaim ditolak')
                            ->warning()
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