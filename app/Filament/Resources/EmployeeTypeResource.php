<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmployeeTypeResource\Pages;
use App\Models\EmployeeType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * "Master Tipe Karyawan" — audit Majoo vs Ginnva. Sebelum ini status
 * kepegawaian di Ginnva cuma 1 kolom bebas (`contract_end_date` yang
 * selalu tampil di form User, terlepas apakah karyawan itu tetap atau
 * kontrak). Sekarang admin bisa definisikan tipe kustom sendiri (bukan
 * enum tetap Tetap/Kontrak/Lepas) — mis. "Tetap", "Kontrak (PKWT)",
 * "Freelance Musiman", "Magang" — masing-masing dengan flag
 * `has_end_date` sendiri, yang menentukan apakah field "Tanggal
 * Berakhir Kontrak" wajib/tampil di form User atau tidak. Dibangun
 * 2026-09-22.
 *
 * Master perusahaan (bukan per-toko, beda dari Shift/WorkSchedule) --
 * tipe kepegawaian tidak masuk akal beda-beda per cabang.
 */
class EmployeeTypeResource extends Resource
{
    protected static ?string $model = EmployeeType::class;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    protected static ?string $navigationGroup = 'Karyawan';

    protected static ?string $navigationLabel = 'Tipe Karyawan';

    protected static ?string $modelLabel = 'Tipe Karyawan';

    protected static ?string $pluralModelLabel = 'Tipe Karyawan';

    protected static ?int $navigationSort = 5;

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
        return static::accessGate() && ! $record->users()->exists();
    }

    public static function canDeleteAny(): bool
    {
        return static::accessGate();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Nama Tipe')
                ->placeholder('mis. Tetap, Kontrak (PKWT), Freelance Musiman, Magang')
                ->required()
                ->maxLength(50)
                ->unique(ignoreRecord: true),

            Forms\Components\Toggle::make('has_end_date')
                ->label('Memiliki Tanggal Berakhir')
                ->helperText('Kalau aktif, field "Tanggal Berakhir Kontrak" akan WAJIB diisi untuk karyawan dengan tipe ini di menu Daftar Karyawan. Kalau tidak, field itu disembunyikan (mis. untuk tipe "Tetap").')
                ->default(false),

            Forms\Components\Toggle::make('is_active')
                ->label('Aktif')
                ->helperText('Matikan supaya tidak muncul lagi sebagai pilihan baru, tanpa mengubah karyawan yang sudah memakainya.')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Tipe')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\IconColumn::make('has_end_date')
                    ->label('Punya Tanggal Berakhir')
                    ->boolean(),

                Tables\Columns\TextColumn::make('users_count')
                    ->label('Jumlah Karyawan')
                    ->counts('users')
                    ->sortable(),

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
                    ->modalDescription('Tipe yang masih dipakai karyawan tidak bisa dihapus — nonaktifkan saja kalau tidak mau dipakai lagi.'),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployeeTypes::route('/'),
            'create' => Pages\CreateEmployeeType::route('/create'),
            'edit' => Pages\EditEmployeeType::route('/{record}/edit'),
        ];
    }
}
