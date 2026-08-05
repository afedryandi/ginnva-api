<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MaterialCategoryResource\Pages;
use App\Models\MaterialCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Sebelumnya kategori materi cuma bisa dibuat inline lewat "+ Add" di
 * form MaterialResource — tidak ada cara edit nama yang salah ketik,
 * hapus kategori kosong/duplikat, atau cegah nama duplikat sama sekali.
 * Resource kecil ini melengkapi itu.
 */
class MaterialCategoryResource extends Resource
{
    protected static ?string $model = MaterialCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-folder';

    protected static ?string $navigationGroup = 'Konten';

    protected static ?string $navigationLabel = 'Kategori Materi';

    protected static ?string $modelLabel = 'Kategori Materi';

    protected static ?string $pluralModelLabel = 'Kategori Materi';

    protected static ?int $navigationSort = 39;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Nama Kategori')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),

            Forms\Components\TextInput::make('sort_order')
                ->label('Urutan Tampil')
                ->numeric()
                ->default(fn () => (MaterialCategory::max('sort_order') ?? 0) + 1)
                ->helperText('Angka lebih kecil tampil lebih dulu.')
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Kategori')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Urutan')
                    ->sortable(),

                Tables\Columns\TextColumn::make('materials_count')
                    ->label('Jumlah Materi')
                    ->counts('materials')
                    ->badge(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime('d M Y')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                // materials.material_category_id pakai cascadeOnDelete()
                // — hapus kategori yang masih punya materi akan ikut
                // menghapus SEMUA materinya diam-diam. Cuma boleh hapus
                // kategori yang benar-benar kosong.
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (MaterialCategory $record) => $record->materials()->count() === 0)
                    ->action(function (MaterialCategory $record) {
                        if ($record->materials()->count() > 0) {
                            Notification::make()
                                ->title('Tidak bisa menghapus kategori ini')
                                ->body('Kategori ini masih punya materi di dalamnya. Pindahkan atau hapus materinya dulu.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->delete();

                        Notification::make()->title('Kategori dihapus')->success()->send();
                    }),
            ])
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListMaterialCategories::route('/'),
            'create' => Pages\CreateMaterialCategory::route('/create'),
            'edit'   => Pages\EditMaterialCategory::route('/{record}/edit'),
        ];
    }
}
