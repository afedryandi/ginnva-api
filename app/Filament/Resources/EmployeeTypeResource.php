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

    // Grup sidebar (audit navigasi 2026-09-29) -- band 0-99 "Data Karyawan" (sebelumnya
    // grup sendirian 'Karyawan' cuma berisi item ini).
    protected static ?string $navigationGroup = 'Pengaturan Karyawan';

    protected static ?string $navigationLabel = 'Tipe Karyawan';

    protected static ?string $modelLabel = 'Tipe Karyawan';

    protected static ?string $pluralModelLabel = 'Tipe Karyawan';

    protected static ?int $navigationSort = 601;

    /**
     * Saklar "Memiliki Tanggal Berakhir" menentukan siapa yang dinonaktifkan otomatis saat kontrak berakhir (contracts:deactivate-expired),
     * jadi menu ini tidak terbuka lewat akses bawaan akun baru: hanya akses penuh atau staf yang menunya dicentang eksplisit. Membuat,
     * mengubah, dan menghapus butuh izin aksi eksplisit (bawaan tidak).
     */
    private static function accessGate(): bool
    {
        return auth()->user()?->hasExplicitMenuAccess(static::class) ?? false;
    }

    public static function canViewAny(): bool
    {
        return static::accessGate();
    }

    public static function canCreate(): bool
    {
        return static::accessGate()
            && (auth()->user()?->hasModuleAction(static::class, 'create', false) ?? false);
    }

    public static function canView($record): bool
    {
        return static::accessGate()
            && (auth()->user()?->hasModuleAction(static::class, 'view', true) ?? false);
    }

    public static function canEdit($record): bool
    {
        return static::accessGate()
            && (auth()->user()?->hasModuleAction(static::class, 'update', false) ?? false);
    }

    public static function canDelete($record): bool
    {
        return static::accessGate()
            && ! $record->users()->exists()
            && (auth()->user()?->hasModuleAction(static::class, 'delete', false) ?? false);
    }

    public static function canDeleteAny(): bool
    {
        return static::accessGate()
            && (auth()->user()?->hasModuleAction(static::class, 'delete', false) ?? false);
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

            Forms\Components\Placeholder::make('impact')
                ->label('Dampak pada karyawan saat ini')
                ->hiddenOn('create')
                ->content(fn (?EmployeeType $record, Forms\Get $get) => $record
                    ? new \Illuminate\Support\HtmlString(nl2br(e(static::impactSummary($record, (bool) $get('has_end_date')))))
                    : '')
                ->columnSpanFull(),

            Forms\Components\Toggle::make('has_end_date')
                ->live()
                ->label('Memiliki Tanggal Berakhir')
                ->helperText('Kalau aktif, field "Tanggal Berakhir Kontrak" WAJIB diisi untuk karyawan bertipe ini di menu User, dan akun mereka DINONAKTIFKAN OTOMATIS (tidak bisa login ke panel maupun aplikasi) begitu tanggal itu lewat tanpa diperpanjang di Riwayat Kontrak. Kalau tidak, field itu disembunyikan dan tidak ada nonaktivasi otomatis (mis. untuk tipe "Tetap").')
                ->default(false),

            Forms\Components\Toggle::make('is_active')
                ->label('Aktif')
                ->helperText('Matikan supaya tidak muncul lagi sebagai pilihan baru, tanpa mengubah karyawan yang sudah memakainya.')
                ->default(true),
        ]);
    }

    /**
     * Ringkasan dampak tipe ini pada karyawan AKTIF saat ini, mengikuti posisi saklar "Memiliki Tanggal Berakhir" yang sedang dipilih di form
     * (belum disimpan), supaya admin melihat akibatnya sebelum menyimpan.
     */
    public static function impactSummary(EmployeeType $type, bool $hasEndDate): string
    {
        $people = $type->users()->where('is_active', true);
        $total = (clone $people)->count();

        if ($total === 0) {
            return 'Belum ada karyawan aktif bertipe ini.';
        }

        $withoutDate = (clone $people)->whereNull('contract_end_date')->count();
        $expired = (clone $people)->whereNotNull('contract_end_date')->whereDate('contract_end_date', '<', today())->count();
        $soon = (clone $people)->whereNotNull('contract_end_date')->whereDate('contract_end_date', '>=', today())->whereDate('contract_end_date', '<=', today()->addDays(30))->count();

        if (! $hasEndDate) {
            return "{$total} karyawan aktif bertipe ini. Tanpa tanggal berakhir, mereka tidak pernah dinonaktifkan otomatis."
                . ($expired > 0 ? "\n{$expired} di antaranya punya tanggal kontrak yang sudah lewat; tetap aktif." : '');
        }

        return "{$total} karyawan aktif bertipe ini."
            . "\nKontrak berakhir dalam 30 hari: {$soon}."
            . "\nTanggal kontrak sudah lewat: {$expired}" . ($expired > 0 ? ' (akan dinonaktifkan otomatis pada jalannya perintah harian berikutnya).' : '.')
            . "\nBelum ada tanggal berakhir: {$withoutDate}" . ($withoutDate > 0 ? ' (perlu diisi; tidak diminta otomatis).' : '.');
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
                    ->boolean()
                    ->tooltip(fn (EmployeeType $record) => $record->has_end_date
                        ? 'Akun karyawan bertipe ini dinonaktifkan otomatis bila kontraknya lewat tanpa diperpanjang.'
                        : 'Tanpa tanggal berakhir: tidak ada nonaktivasi otomatis.'),

                Tables\Columns\TextColumn::make('users_count')
                    ->label('Jumlah Karyawan')
                    ->counts('users')
                    ->sortable()
                    ->description(fn (EmployeeType $record) => ($record->users_count ?? 0) > 0 ? 'Tidak bisa dihapus selama masih dipakai' : null),

                // Saklar langsung: tipe nonaktif tidak ditawarkan untuk karyawan baru, karyawan yang sudah memakainya tidak berubah.
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Aktif')
                    ->disabled(fn (EmployeeType $record) => ! static::canEdit($record)),
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
