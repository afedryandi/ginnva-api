<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierResource\Pages;
use App\Models\Supplier;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Master Supplier untuk Hutang Usaha (audit Hutang Usaha 2026-09-29).
 * Supplier bersifat company-wide (tanpa filter toko). Tidak bisa dihapus kalau
 * sudah punya tagihan -- nonaktifkan saja (is_active) supaya histori utuh.
 */
class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $cluster = \App\Filament\Clusters\KeuanganCluster::class;

    protected static ?string $navigationLabel = 'Supplier';

    protected static ?string $modelLabel = 'Supplier';

    protected static ?string $pluralModelLabel = 'Supplier';

    protected static ?int $navigationSort = 16;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea() && $user->hasMenuAccess(static::class);
    }

    public static function canCreate(): bool
    {
        return static::canManage();
    }

    public static function canEdit($record): bool
    {
        return static::canManage();
    }

    public static function canDelete($record): bool
    {
        return (auth()->user()?->isFullAccess() ?? false) && ! $record->payables()->exists();
    }

    public static function canDeleteAny(): bool
    {
        return false; // tanpa bulk delete
    }

    private static function canManage(): bool
    {
        $user = auth()->user();

        return $user?->isFullAccess()
            || ($user?->hasMenuAccess(static::class) && $user->hasModuleAction(static::class, 'update', false));
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identitas')->columns(2)->schema([
                Forms\Components\TextInput::make('name')->label('Nama Supplier')->required()->maxLength(255)
                    ->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('npwp')->label('NPWP')->maxLength(30),
                Forms\Components\TextInput::make('phone')->label('Telepon')->tel()->maxLength(30),
                Forms\Components\TextInput::make('email')->label('Email')->email()->maxLength(255),
                Forms\Components\Textarea::make('address')->label('Alamat')->rows(2)->columnSpanFull(),
            ]),
            Forms\Components\Section::make('Rekening Pembayaran')->columns(3)->schema([
                Forms\Components\TextInput::make('bank_name')->label('Bank')->maxLength(255),
                Forms\Components\TextInput::make('bank_account_number')->label('No. Rekening')->maxLength(50),
                Forms\Components\TextInput::make('bank_account_name')->label('Atas Nama')->maxLength(255),
            ]),
            Forms\Components\Section::make('Lainnya')->schema([
                Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true)
                    ->helperText('Supplier nonaktif tidak muncul di pilihan tagihan baru, histori tetap utuh.'),
                Forms\Components\Textarea::make('notes')->label('Catatan')->rows(2),
            ]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('payables');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Supplier')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('npwp')->label('NPWP')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('bank_name')->label('Bank')->placeholder('—')
                    ->description(fn (Supplier $r) => $r->bank_account_number ? $r->bank_account_number . ($r->bank_account_name ? ' a.n. ' . $r->bank_account_name : '') : null)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('phone')->label('Telepon')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('payables_count')->label('Tagihan')->sortable(),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Aktif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(function (Supplier $record, Tables\Actions\DeleteAction $action) {
                        if ($record->payables()->exists()) {
                            Notification::make()->title('Supplier sudah punya tagihan — nonaktifkan saja.')->danger()->send();
                            $action->cancel();
                        }
                    }),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuppliers::route('/'),
            'create' => Pages\CreateSupplier::route('/create'),
            'edit' => Pages\EditSupplier::route('/{record}/edit'),
        ];
    }
}
