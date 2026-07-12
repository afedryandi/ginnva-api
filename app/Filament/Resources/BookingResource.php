<?php

namespace App\Filament\Resources;

use App\Exports\BookingExport;
use App\Filament\Resources\BookingResource\Pages;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;

class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Penjualan';

    protected static ?string $navigationLabel = 'Booking Instalasi';

    protected static ?string $modelLabel = 'Booking';

    protected static ?string $pluralModelLabel = 'Booking Instalasi';

    protected static ?string $navigationBadgeColor = 'warning';

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
        return auth()->user()?->hasAnyRole(['super_admin', 'regional_admin']) ?? false;
    }

    public static function form(Form $form): Form
    {
        $isSuperAdmin = auth()->user()?->hasRole('super_admin');

        return $form->schema([
            Forms\Components\Section::make('Sumber Booking')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('source')
                        ->label('Asal Booking')
                        ->options([
                            'app'       => '📱 Mobile App',
                            'whatsapp'  => '💬 WhatsApp',
                            'walk_in'   => '🚶 Walk-in',
                        ])
                        ->default('whatsapp')
                        ->required()
                        ->live()
                        ->disabledOn('edit'),

                    Forms\Components\TextInput::make('booking_number')
                        ->label('No. Booking')
                        ->disabled()
                        ->hiddenOn('create'),
                ]),

            Forms\Components\Section::make('Data Customer')
                ->columns(2)
                ->schema([
                    // Booking dari app — pilih dari akun terdaftar
                    Forms\Components\Select::make('customer_id')
                        ->label('Akun Customer (App)')
                        ->options(fn () => Customer::orderBy('name')
                            ->get()
                            ->mapWithKeys(fn ($c) => [
                                $c->id => trim(($c->name ?? 'Tanpa Nama') . ' — ' . $c->email),
                            ])
                        )
                        ->searchable()
                        ->nullable()
                        ->visible(fn (Forms\Get $get) => $get('source') === 'app')
                        ->disabledOn('edit'),

                    // Booking manual (WA/walk-in) — input nama & nomor langsung
                    Forms\Components\TextInput::make('customer_name')
                        ->label('Nama Customer')
                        ->required(fn (Forms\Get $get) => in_array($get('source'), ['whatsapp', 'walk_in']))
                        ->visible(fn (Forms\Get $get) => in_array($get('source'), ['whatsapp', 'walk_in']))
                        ->maxLength(255),

                    Forms\Components\TextInput::make('phone_number')
                        ->label('No. WhatsApp / Telepon')
                        ->tel()
                        ->visible(fn (Forms\Get $get) => in_array($get('source'), ['whatsapp', 'walk_in']))
                        ->maxLength(50),
                ]),

            Forms\Components\Section::make('Detail Booking')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('store_id')
                        ->label('Toko / Workshop')
                        ->options(fn () => Store::where('is_active', true)->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->live()
                        ->default(fn () => $isSuperAdmin ? null : auth()->user()?->store_id)
                        ->disabled(! $isSuperAdmin)
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('installer_user_id', null)),

                    Forms\Components\Select::make('installer_user_id')
                        ->label('Installer Bertugas')
                        ->helperText('Installer hanya bisa lihat & chat teks di booking yang ditugaskan ke dirinya di mobile app.')
                        ->options(fn (Forms\Get $get) => User::where('store_id', $get('store_id'))
                            ->whereHas('roles', fn ($q) => $q->where('name', 'installer'))
                            ->pluck('name', 'id')
                        )
                        ->searchable()
                        ->nullable()
                        ->disabled(fn (Forms\Get $get) => ! $get('store_id')),

                    Forms\Components\Select::make('service_type')
                        ->label('Jenis Layanan')
                        ->options([
                            'Kaca Film Mobil'       => 'Kaca Film Mobil',
                            'Paint Protection Film' => 'Paint Protection Film (PPF)',
                            'Konsultasi'            => 'Konsultasi',
                            'Klaim Garansi'         => 'Klaim Garansi',
                            'Lainnya'               => 'Lainnya (isi di catatan)',
                        ])
                        ->required(),

                    Forms\Components\DatePicker::make('preferred_date')
                        ->label('Tanggal Diinginkan')
                        ->required()
                        ->minDate(fn () => $form->getOperation() === 'create' ? now() : null),

                    Forms\Components\TextInput::make('preferred_time')
                        ->label('Jam Diinginkan')
                        ->placeholder('Contoh: 09:00')
                        ->maxLength(50),

                    Forms\Components\Textarea::make('notes')
                        ->label('Catatan')
                        ->placeholder('Tipe mobil, warna, permintaan khusus, dll.')
                        ->columnSpanFull(),

                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options([
                            'pending'   => 'Menunggu Konfirmasi',
                            'confirmed' => 'Dikonfirmasi',
                            'completed' => 'Selesai',
                            'cancelled' => 'Dibatalkan',
                        ])
                        ->default('confirmed')
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

                Tables\Columns\TextColumn::make('display_name')
                    ->label('Customer')
                    ->getStateUsing(fn (Booking $record): string =>
                        $record->customer_name
                            ?? $record->customer?->name
                            ?? $record->customer?->email
                            ?? '—'
                    )
                    ->searchable(query: fn (Builder $query, string $search) => $query
                        ->where('customer_name', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($q) => $q
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                        )
                    ),

                Tables\Columns\TextColumn::make('phone_number')
                    ->label('No. Telepon')
                    ->getStateUsing(fn (Booking $record): string =>
                        $record->phone_number ?? $record->customer?->phone_number ?? '—'
                    )
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('source')
                    ->label('Asal')
                    ->colors([
                        'primary' => 'app',
                        'success' => 'whatsapp',
                        'gray'    => 'walk_in',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'app'      => '📱 App',
                        'whatsapp' => '💬 WA',
                        'walk_in'  => '🚶 Walk-in',
                        default    => $state,
                    }),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('installer.name')
                    ->label('Installer')
                    ->placeholder('Belum ditugaskan')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('service_type')
                    ->label('Layanan')
                    ->limit(25),

                Tables\Columns\TextColumn::make('preferred_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('preferred_time')
                    ->label('Jam')
                    ->placeholder('—'),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'pending',
                        'info'    => 'confirmed',
                        'success' => 'completed',
                        'danger'  => 'cancelled',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending'   => 'Menunggu',
                        'confirmed' => 'Dikonfirmasi',
                        'completed' => 'Selesai',
                        'cancelled' => 'Dibatalkan',
                        default     => $state,
                    }),
            ])
            ->groups([
                Tables\Grouping\Group::make('preferred_date')
                    ->label('Tanggal')
                    ->date('l, d M Y')
                    ->collapsible(),
            ])
            ->defaultGroup('preferred_date')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending'   => 'Menunggu',
                        'confirmed' => 'Dikonfirmasi',
                        'completed' => 'Selesai',
                        'cancelled' => 'Dibatalkan',
                    ]),

                Tables\Filters\SelectFilter::make('source')
                    ->label('Asal Booking')
                    ->options([
                        'app'      => 'Mobile App',
                        'whatsapp' => 'WhatsApp',
                        'walk_in'  => 'Walk-in',
                    ]),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->relationship('store', 'name')
                    ->visible(fn () => auth()->user()?->hasRole('super_admin')),

                Tables\Filters\Filter::make('upcoming')
                    ->label('Hanya yang akan datang')
                    ->query(fn (Builder $query) => $query->whereDate('preferred_date', '>=', today()))
                    ->default(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('export')
                    ->label('Export Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->action(function () {
                        $storeId = auth()->user()?->hasRole('super_admin')
                            ? null
                            : auth()->user()?->store_id;

                        return Excel::download(
                            new BookingExport($storeId),
                            'booking-' . now()->format('Ymd') . '.xlsx'
                        );
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('preferred_date');
    }

    public static function getRelations(): array
    {
        return [
            BookingResource\RelationManagers\MessagesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListBookings::route('/'),
            'create' => Pages\CreateBooking::route('/create'),
            'edit'   => Pages\EditBooking::route('/{record}/edit'),
        ];
    }
}
