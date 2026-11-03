<?php

namespace App\Filament\Resources\ScrollCodeResource\RelationManagers;

use App\Models\ScrollCodeOffcut;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * "Potongan Sisa" (wasted) dari gulungan ini. Satu baris = satu potongan, tanpa ukuran. Bukan stok inventori dan tanpa nilai uang.
 * Staf gudang menyimpan potongan di sini (atau dari baris roll di Memo), dan potongan diambil lewat baris memo bertipe Potongan Sisa
 * beserta bagian mobilnya. Hanya akses penuh yang boleh membuang.
 */
class OffcutsRelationManager extends RelationManager
{
    protected static string $relationship = 'offcuts';

    protected static ?string $title = 'Potongan Sisa';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('No.')->prefix('#'),
                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors(['warning' => 'stored', 'success' => 'used', 'gray' => 'discarded'])
                    ->formatStateUsing(fn (string $state) => ScrollCodeOffcut::statusLabels()[$state] ?? $state),
                Tables\Columns\TextColumn::make('location')->label('Lokasi')->placeholder('—'),
                Tables\Columns\TextColumn::make('car_part')->label('Dipakai untuk Bagian')->placeholder('—'),
                Tables\Columns\TextColumn::make('usedInMemoItem.memo.memo_number')->label('Memo Pemakaian')->placeholder('—'),
                Tables\Columns\TextColumn::make('originMemo.memo_number')->label('Memo Asal')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('notes')->label('Catatan')->placeholder('—')->wrap()->limit(50),
                Tables\Columns\TextColumn::make('discard_reason')->label('Alasan Dibuang')->placeholder('—')->wrap()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')->label('Disimpan')->dateTime('d M Y, H:i'),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with(['usedInMemoItem.memo:id,memo_number', 'originMemo:id,memo_number']))
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Status')->options(ScrollCodeOffcut::statusLabels()),
            ])
            ->headerActions([
                Tables\Actions\Action::make('store_offcuts')
                    ->label('Simpan Potongan Sisa')
                    ->icon('heroicon-o-archive-box-arrow-down')
                    ->visible(fn () => ScrollCodeOffcut::canManage(auth()->user()))
                    ->form([
                        Forms\Components\TextInput::make('count')
                            ->label('Jumlah Potongan')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->default(1)
                            ->minValue(1)
                            ->maxValue(50),
                        Forms\Components\TextInput::make('location')->label('Lokasi Simpan (opsional)')->maxLength(100)->placeholder('mis. Rak B2'),
                        Forms\Components\Textarea::make('notes')->label('Catatan (opsional)')->rows(2)->maxLength(500),
                    ])
                    ->action(function (array $data) {
                        try {
                            $created = $this->getOwnerRecord()->storeOffcuts((int) $data['count'], $data['location'] ?? null, $data['notes'] ?? null, auth()->id());
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->title('Tidak bisa menyimpan potongan')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title($created->count() . ' potongan sisa disimpan')->success()->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('discard')
                    ->label('Buang')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (ScrollCodeOffcut $record) => $record->status === ScrollCodeOffcut::STORED && (auth()->user()?->isFullAccess() ?? false))
                    ->modalHeading('Buang Potongan Ini?')
                    ->modalDescription('Potongan ditandai Dibuang dan tidak bisa diambil lagi. Barisnya tetap tersimpan sebagai riwayat.')
                    ->form([
                        Forms\Components\Textarea::make('reason')->label('Alasan')->required()->rows(2)->maxLength(500),
                    ])
                    ->action(function (ScrollCodeOffcut $record, array $data) {
                        try {
                            $record->discard((string) $data['reason'], (int) auth()->id());
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->title('Tidak bisa membuang potongan')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Potongan dibuang')->success()->send();
                    }),
            ]);
    }
}
