<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BlockedDateResource\Pages;
use App\Models\BlockedDate;
use App\Models\Store;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BlockedDateResource extends Resource
{
    protected static ?string $model = BlockedDate::class;

    protected static ?string $navigationIcon = 'heroicon-o-x-circle';

    protected static ?string $navigationGroup = 'Operasional';

    protected static ?string $navigationLabel = 'Tanggal Tidak Tersedia';

    protected static ?string $modelLabel = 'Tanggal Blokir';

    protected static ?string $pluralModelLabel = 'Tanggal Tidak Tersedia';

    protected static ?int $navigationSort = 30;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user  = auth()->user();

        if ($user && ! $user->isFullAccess()) {
            $query->where('store_id', $user->store_id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        $isSuperAdmin = auth()->user()?->isFullAccess();

        return $form->schema([
            Forms\Components\Select::make('store_id')
                ->label('Toko / Workshop')
                ->options(fn () => Store::where('is_active', true)->pluck('name', 'id'))
                ->required()
                ->default(fn () => $isSuperAdmin ? null : auth()->user()?->store_id)
                ->disabled(! $isSuperAdmin),

            Forms\Components\DatePicker::make('date')
                ->label('Tanggal')
                ->required()
                ->minDate(today())
                // Composite unique (store_id + date) di database — tanpa
                // validasi ini, coba blokir tanggal yang sudah diblokir
                // untuk toko yang sama akan kena error database mentah
                // alih-alih pesan yang jelas.
                ->unique(
                    table: 'blocked_dates',
                    column: 'date',
                    modifyRuleUsing: fn ($rule, Forms\Get $get) => $rule->where('store_id', $get('store_id')),
                )
                ->validationMessages([
                    'unique' => 'Tanggal ini sudah diblokir untuk toko ini.',
                ]),

            Forms\Components\TextInput::make('reason')
                ->label('Alasan (opsional)')
                ->placeholder('Contoh: Libur nasional, Workshop penuh, Maintenance')
                ->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->sortable(),

                Tables\Columns\TextColumn::make('date')
                    ->label('Tanggal')
                    ->date('l, d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Alasan')
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->relationship('store', 'name')
                    ->visible(fn () => auth()->user()?->isFullAccess()),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('date');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListBlockedDates::route('/'),
            'create' => Pages\CreateBlockedDate::route('/create'),
        ];
    }
}
