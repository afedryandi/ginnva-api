<?php

namespace App\Filament\Resources;

use App\Exports\CustomerExport;
use App\Filament\Resources\CustomerResource\Pages;
use App\Models\Customer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Maatwebsite\Excel\Facades\Excel;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Penjualan';

    protected static ?string $navigationLabel = 'Akun Customer';

    protected static ?string $modelLabel = 'Customer';

    protected static ?string $pluralModelLabel = 'Akun Customer';

    /**
     * Read-only untuk semua admin — akun customer dibuat sendiri oleh
     * pengguna lewat OTP di mobile app, admin tidak perlu (dan tidak
     * boleh) membuat/edit akun customer secara manual. Resource ini
     * murni untuk MELIHAT siapa saja yang sudah daftar, plus riwayat
     * warranty & booking mereka.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'regional_admin']) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Data Akun')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nama')
                        ->disabled(),

                    Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->disabled(),

                    Forms\Components\TextInput::make('phone_number')
                        ->label('No. WhatsApp')
                        ->disabled(),

                    Forms\Components\Placeholder::make('email_verified_at')
                        ->label('Email Terverifikasi')
                        ->content(fn (?Customer $record) => $record?->email_verified_at?->format('d M Y H:i') ?? 'Belum'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('phone_number')
                    ->label('No. WhatsApp')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('warranties_count')
                    ->label('Jumlah Garansi')
                    ->counts('warranties'),

                Tables\Columns\TextColumn::make('bookings_count')
                    ->label('Jumlah Booking')
                    ->counts('bookings'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Daftar Pada')
                    ->dateTime('d M Y')
                    ->sortable(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('export')
                    ->label('Export Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->visible(fn () => auth()->user()?->hasRole('super_admin'))
                    ->action(fn () => Excel::download(
                        new CustomerExport(),
                        'customers-' . now()->format('Ymd') . '.xlsx'
                    )),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'view' => Pages\ViewCustomer::route('/{record}'),
        ];
    }
}
