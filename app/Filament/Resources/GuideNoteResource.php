<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GuideNoteResource\Pages;
use App\Models\GuideNote;
use App\Support\GuideCatalog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * "Catatan Panduan" (Panduan Fase 4): pemilik menambah catatan perusahaan di bawah panduan tertentu tanpa rilis. Hanya akses penuh; staf
 * lain membaca catatannya di halaman Panduan dan aplikasi staf. Satu catatan per panduan. Perubahan tercatat di Histori Aktivitas.
 */
class GuideNoteResource extends Resource
{
    protected static ?string $model = GuideNote::class;

    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $navigationGroup = 'Lainnya';

    protected static ?string $navigationLabel = 'Catatan Panduan';

    protected static ?string $modelLabel = 'Catatan Panduan';

    protected static ?string $pluralModelLabel = 'Catatan Panduan';

    protected static ?int $navigationSort = 71;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return static::canViewAny();
    }

    public static function canDeleteAny(): bool
    {
        return static::canViewAny();
    }

    /** @return array<string, string> id panduan => judul */
    public static function guideOptions(): array
    {
        return collect(GuideCatalog::all())->pluck('title', 'id')->all();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('guide_id')
                ->label('Panduan')
                ->options(fn () => static::guideOptions())
                ->searchable()
                ->required()
                ->in(fn () => array_keys(static::guideOptions()))
                ->unique(ignoreRecord: true)
                ->helperText('Satu catatan per panduan. Catatan tampil di bawah langkah-langkah panduan itu.'),

            Forms\Components\Textarea::make('body')
                ->label('Catatan Perusahaan')
                ->required()
                ->rows(6)
                ->maxLength(2000)
                ->helperText('Isi dengan aturan toko, batas, atau kebijakan perusahaan yang melengkapi panduan. Jangan menulis langkah di sistem (itu ada di panduan) dan jangan memuat data pribadi atau kata sandi. Tanda **tebal** didukung.'),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('guide_id')
                    ->label('Panduan')
                    ->formatStateUsing(fn (string $state) => static::guideOptions()[$state] ?? $state)
                    ->searchable(),
                Tables\Columns\TextColumn::make('body')
                    ->label('Catatan')
                    ->limit(80)
                    ->wrap(),
                Tables\Columns\TextColumn::make('updatedBy.name')
                    ->label('Diubah oleh')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGuideNotes::route('/'),
            'create' => Pages\CreateGuideNote::route('/create'),
            'edit' => Pages\EditGuideNote::route('/{record}/edit'),
        ];
    }
}
