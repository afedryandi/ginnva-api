<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BookingResource\Pages;
use App\Models\Booking;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Penjualan';

    protected static ?string $navigationLabel = 'Booking Instalasi';

    protected static ?string $modelLabel = 'Booking';

    protected static ?string $pluralModelLabel = 'Booking Instalasi';

    protected static ?string $navigationBadgeColor = 'warning';

    /**
     * regional_admin (admin toko) hanya melihat booking yang ditujukan
     * ke tokonya sendiri — konsisten dengan scope di WarrantyResource/
     * QuotationResource. super_admin melihat semua.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && ! $user->hasRole('super_admin')) {
            $query->where('store_id', $user->store_id);
        }

        return $query;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'regional_admin']) ?? false;
    }

    public static function canCreate(): bool
    {
        // Booking hanya dibuat customer lewat mobile app (wajib login),
        // admin tidak membuat booking palsu atas nama customer.
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Detail Booking')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('booking_number')
                        ->label('No. Booking')
                        ->disabled(),

                    Forms\Components\TextInput::make('customer.name')
                        ->label('Customer')
                        ->disabled(),

                    Forms\Components\TextInput::make('store.name')
                        ->label('Toko Tujuan')
                        ->disabled(),

                    Forms\Components\TextInput::make('service_type')
                        ->label('Jenis Layanan')
                        ->disabled(),

                    Forms\Components\DatePicker::make('preferred_date')
                        ->label('Tanggal Diinginkan')
                        ->disabled(),

                    Forms\Components\TextInput::make('preferred_time')
                        ->label('Jam Diinginkan')
                        ->disabled(),

                    Forms\Components\Textarea::make('notes')
                        ->label('Catatan dari Customer')
                        ->disabled()
                        ->columnSpanFull(),

                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options([
                            'pending' => 'Menunggu Konfirmasi',
                            'confirmed' => 'Dikonfirmasi',
                            'completed' => 'Selesai',
                            'cancelled' => 'Dibatalkan',
                        ])
                        ->required(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('booking_number')
                    ->label('No. Booking')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label('Customer')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('service_type')
                    ->label('Layanan')
                    ->limit(30),

                Tables\Columns\TextColumn::make('preferred_date')
                    ->label('Tgl Diinginkan')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'pending',
                        'info' => 'confirmed',
                        'success' => 'completed',
                        'danger' => 'cancelled',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'Menunggu',
                        'confirmed' => 'Dikonfirmasi',
                        'completed' => 'Selesai',
                        'cancelled' => 'Dibatalkan',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Diajukan')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Menunggu',
                        'confirmed' => 'Dikonfirmasi',
                        'completed' => 'Selesai',
                        'cancelled' => 'Dibatalkan',
                    ]),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->relationship('store', 'name')
                    ->visible(fn () => auth()->user()?->hasRole('super_admin')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('preferred_date');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBookings::route('/'),
            'edit' => Pages\EditBooking::route('/{record}/edit'),
        ];
    }
}
