<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RewardResource\Pages;
use App\Models\Reward;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\QueryException;

class RewardResource extends Resource
{
    protected static ?string $model = Reward::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationGroup = 'Marketing/Konten';

    protected static ?int $navigationSort = 85;

    protected static ?string $navigationLabel = 'Katalog Reward';

    protected static ?string $modelLabel = 'Reward';

    protected static ?string $pluralModelLabel = 'Reward';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    /**
     * SEBELUMNYA tidak ada override sama sekali di sini dan tidak ada
     * RewardPolicy terdaftar — canCreate()/canEdit()/canDelete() bawaan
     * Resource selalu FALSE untuk siapa pun tanpa policy (default-deny
     * Laravel). Akibatnya tombol "New", "Edit", DAN "Hapus" tidak pernah
     * muncul — admin sama sekali tidak bisa kelola katalog reward lewat
     * Filament. Sama bug class dengan Partner/Voucher/PointTransaction
     * yang ditemukan di audit-audit sebelumnya. Dibiarkan seluas
     * canViewAny() (bukan isFullAccess()) karena kelola katalog reward
     * adalah tugas rutin staff yang sudah diberi akses menu ini, sama
     * seperti resource katalog lain (Berita, Galeri, dst).
     */
    public static function canCreate(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function canEdit($record): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function canDelete($record): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Data Reward')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nama Reward')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('description')
                        ->label('Deskripsi')
                        ->columnSpanFull(),

                    Forms\Components\FileUpload::make('image')
                        ->label('Gambar')
                        ->image()
                        ->directory('rewards')
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('points_cost')
                        ->label('Harga (Poin)')
                        ->numeric()
                        ->required()
                        ->minValue(1),

                    Forms\Components\TextInput::make('stock')
                        ->label('Stok')
                        ->numeric()
                        ->minValue(0)
                        ->helperText('Kosongkan kalau stok tidak dibatasi (mis. voucher diskon).'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Aktif')
                        ->default(true)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->label('')
                    ->square(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('points_cost')
                    ->label('Harga Poin')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('stock')
                    ->label('Stok')
                    ->placeholder('Tanpa batas')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ditambahkan')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                // reward_redemptions.reward_id sekarang restrictOnDelete()
                // — hapus reward yang masih punya riwayat penukaran akan
                // ditolak database. Ditangkap di sini supaya staff dapat
                // pesan jelas ("nonaktifkan saja"), bukan error mentah.
                Tables\Actions\DeleteAction::make()
                    ->action(function (Reward $record) {
                        try {
                            $record->delete();
                        } catch (QueryException $e) {
                            Notification::make()
                                ->title('Tidak bisa menghapus reward ini')
                                ->body('Reward ini masih punya riwayat penukaran. Nonaktifkan saja lewat toggle "Aktif" supaya tidak bisa ditukar lagi, tanpa menghapus riwayatnya.')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()->title('Reward dihapus')->success()->send();
                    }),
            ])
            ->defaultSort('points_cost');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListRewards::route('/'),
            'create' => Pages\CreateReward::route('/create'),
            'edit'   => Pages\EditReward::route('/{record}/edit'),
        ];
    }
}
