<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShiftResource\Pages;
use App\Models\Shift;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Master Shift — audit Majoo vs Ginnva ("Master data Shift terpisah
 * dari Jadwal Kerja"), dibangun 2026-09-22. Gate 5-method eksplisit di
 * setiap resource baru (bukan cuma canViewAny) -- pola wajib sejak audit
 * "gate default-deny" 2026-09-14 (lihat feedback_gate_authorization_sweep),
 * supaya tidak lolos jadi 1 lagi resource yang tidak sengaja tak bisa
 * diakses siapa pun.
 */
class ShiftResource extends Resource
{
    protected static ?string $model = Shift::class;

    protected static ?string $navigationIcon = 'heroicon-o-sun';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationGroup = 'Jadwal Kerja';

    protected static ?string $navigationLabel = 'Daftar Shift';

    protected static ?string $modelLabel = 'Shift';

    protected static ?string $pluralModelLabel = 'Shift';

    protected static ?int $navigationSort = 40;

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);
        }

        return $query;
    }

    private static function accessGate(): bool
    {
        $user = auth()->user();

        return ($user?->canAccessStaffArea() ?? false)
            && $user->hasMenuAccess(static::class);
    }

    public static function canViewAny(): bool
    {
        return static::accessGate();
    }

    public static function canCreate(): bool
    {
        return static::accessGate();
    }

    public static function canView($record): bool
    {
        return static::accessGate();
    }

    public static function canEdit($record): bool
    {
        return static::accessGate();
    }

    public static function canDelete($record): bool
    {
        return static::accessGate();
    }

    public static function canDeleteAny(): bool
    {
        return static::accessGate();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('store_id')
                ->label('Toko')
                ->relationship('store', 'name')
                ->default(fn () => auth()->user()?->store_id)
                ->disabled(fn () => ! (auth()->user()?->isFullAccess() ?? false))
                ->dehydrated()
                ->required(),

            Forms\Components\TextInput::make('name')
                ->label('Nama Shift')
                ->placeholder('mis. Pagi, Sore, Malam')
                ->required()
                ->maxLength(50),

            Forms\Components\TimePicker::make('start_time')
                ->label('Jam Mulai')
                ->seconds(false)
                ->required(),

            Forms\Components\TimePicker::make('end_time')
                ->label('Jam Selesai')
                ->seconds(false)
                ->required()
                ->helperText('Kalau shift lintas tengah malam (mis. shift malam 22:00-06:00), isi jam selesai lebih kecil dari jam mulai apa adanya -- sistem otomatis mendeteksi ini sebagai lintas hari.'),

            Forms\Components\TimePicker::make('break_start_time')
                ->label('Mulai Istirahat (opsional)')
                ->seconds(false),

            Forms\Components\TimePicker::make('break_end_time')
                ->label('Selesai Istirahat (opsional)')
                ->seconds(false),

            Forms\Components\ColorPicker::make('color')
                ->label('Warna (utk kalender)')
                ->helperText('Kosongkan untuk pakai warna default sistem.'),

            Forms\Components\Toggle::make('is_active')
                ->label('Aktif')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ColorColumn::make('color')
                    ->label('')
                    ->default('#6b7280'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Shift')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('start_time')
                    ->label('Jam Kerja')
                    ->formatStateUsing(fn (Shift $record) => \Illuminate\Support\Carbon::parse($record->start_time)->format('H:i')
                        . ' – ' . \Illuminate\Support\Carbon::parse($record->end_time)->format('H:i')),

                Tables\Columns\TextColumn::make('break_start_time')
                    ->label('Istirahat')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state, Shift $record) => $state
                        ? \Illuminate\Support\Carbon::parse($record->break_start_time)->format('H:i') . ' – ' . \Illuminate\Support\Carbon::parse($record->break_end_time)->format('H:i')
                        : null),

                Tables\Columns\TextColumn::make('net_minutes')
                    ->label('Jam Kerja Bersih')
                    ->state(fn (Shift $record) => $record->netMinutes())
                    ->formatStateUsing(fn (int $state) => number_format($state / 60, 1, ',', '.') . ' jam'),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status Aktif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->modalDescription('Menghapus shift ini bisa memengaruhi Jadwal Kerja yang masih memakainya. Pastikan tidak ada template yang menggunakannya lagi.'),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShifts::route('/'),
            'create' => Pages\CreateShift::route('/create'),
            'edit' => Pages\EditShift::route('/{record}/edit'),
        ];
    }
}
