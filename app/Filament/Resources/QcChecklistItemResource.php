<?php

namespace App\Filament\Resources;

use App\Filament\Resources\QcChecklistItemResource\Pages;
use App\Models\BookingServiceType;
use App\Models\QcChecklistItem;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * "Daftar Periksa QC" (Fase 3A pengerjaan ulang booking, 2026-10-17): butir yang diperiksa Leader/Store Manager sebelum
 * kendaraan dinyatakan siap diambil, per layanan. Perubahan berlaku untuk pemeriksaan BERIKUTNYA; hasil lama menyimpan salinan
 * butirnya sendiri. Hanya akses penuh yang boleh mengubah.
 */
class QcChecklistItemResource extends Resource
{
    protected static ?string $model = QcChecklistItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $cluster = \App\Filament\Clusters\BookingCluster::class;

    // Grup sidebar "Operasional" (band 100-199), setelah "Layanan Booking".
    protected static ?string $navigationGroup = 'Operasional';

    protected static ?string $navigationLabel = 'Checklist QC';

    protected static ?string $modelLabel = 'Butir Periksa QC';

    protected static ?string $pluralModelLabel = 'Checklist QC';

    protected static ?int $navigationSort = 103;

    private static function accessGate(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(static::class);
    }

    private static function fullAccess(): bool
    {
        return static::accessGate() && (auth()->user()?->isFullAccess() ?? false);
    }

    public static function canViewAny(): bool
    {
        return static::accessGate();
    }

    public static function canCreate(): bool
    {
        return static::fullAccess();
    }

    public static function canEdit($record): bool
    {
        return static::fullAccess();
    }

    public static function canDelete($record): bool
    {
        return static::fullAccess();
    }

    public static function canDeleteAny(): bool
    {
        return static::fullAccess();
    }

    public static function form(Form $form): Form
    {
        return $form->columns(1)->schema([
            Forms\Components\Section::make('Butir periksa')
                ->description('Satu pernyataan yang harus benar saat Quality Check dilakukan.')
                ->icon('heroicon-o-clipboard-document-check')
                ->schema([
                    Forms\Components\Select::make('service_key')
                        ->label('Layanan')
                        ->options(fn () => BookingServiceType::catalog()->pluck('label', 'key')->all())
                        ->required(),

                    Forms\Components\TextInput::make('label')
                        ->label('Butir yang Diperiksa')
                        ->required()
                        ->maxLength(160)
                        ->helperText('Tulis sebagai pernyataan yang harus benar, mis. "Tepi film rapi dan menempel kuat".'),
                ]),

            Forms\Components\Section::make('Aturan')
                ->icon('heroicon-o-adjustments-horizontal')
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    Forms\Components\Toggle::make('is_required')
                        ->label('Wajib Lulus')
                        ->default(true)
                        ->helperText('Kalau butir wajib tidak lulus, Quality Check tidak lulus.'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Dipakai')
                        ->default(true)
                        ->helperText('Matikan untuk berhenti memeriksa butir ini tanpa menghapus riwayatnya.'),
                ]),
        ]);
    }
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->emptyStateHeading('Belum ada butir periksa')
            ->emptyStateDescription('Tambahkan butir yang harus diperiksa saat Quality Check tiap layanan.')
            ->emptyStateIcon('heroicon-o-clipboard-document-check')
            // Urutan diatur dengan menyeret baris (tombol "Urutkan Ulang"), bukan mengetik angka. Hanya akses penuh, sama seperti ubah dan hapus.
            ->reorderable('sort_order', static::fullAccess())
            ->reorderRecordsTriggerAction(fn (\Filament\Tables\Actions\Action $action, bool $isReordering) => $action
                ->label($isReordering ? 'Selesai Mengurutkan' : 'Urutkan Ulang'))
            ->defaultGroup(
                Tables\Grouping\Group::make('service_key')
                    ->label('Layanan')
                    ->getTitleFromRecordUsing(fn (QcChecklistItem $record) => BookingServiceType::labelFor($record->service_key))
            )
            ->columns([
                Tables\Columns\TextColumn::make('service_key')
                    ->label('Layanan')
                    ->formatStateUsing(fn (string $state) => BookingServiceType::labelFor($state))
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('label')->label('Butir')->weight('semibold')->wrap(),
                Tables\Columns\TextColumn::make('is_required')
                    ->label('Sifat')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Wajib lulus' : 'Opsional')
                    ->color(fn ($state) => $state ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Dipakai' : 'Nonaktif')
                    ->color(fn ($state) => $state ? 'success' : 'gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('service_key')
                    ->label('Layanan')
                    ->options(fn () => BookingServiceType::catalog()->pluck('label', 'key')->all()),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->iconButton()->tooltip('Ubah'),
                Tables\Actions\DeleteAction::make()->iconButton()->tooltip('Hapus'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQcChecklistItems::route('/'),
            'create' => Pages\CreateQcChecklistItem::route('/create'),
            'edit' => Pages\EditQcChecklistItem::route('/{record}/edit'),
        ];
    }
}
