<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ChartOfAccountResource\Pages;
use App\Models\ChartOfAccount;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Bagan Akun — TERBATAS full-access (super_admin/direksi), TIDAK lewat
 * menu_access seperti resource lain di grup Keuangan. Sama filosofi
 * dengan PayrollResource: struktur akun adalah keputusan akuntansi yang
 * mempengaruhi laporan seluruh perusahaan, bukan operasional harian
 * yang cocok didelegasikan ke store_manager (beda dari Transaksi
 * Keuangan yang memang staff toko input sendiri tiap hari).
 *
 * Belum ada tabel Jurnal yang mereferensikan akun ini (Fase 2.5/3,
 * belum dibangun) — resource ini murni kelola struktur Bagan Akun dulu.
 */
class ChartOfAccountResource extends Resource
{
    protected static ?string $model = ChartOfAccount::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?string $navigationLabel = 'Bagan Akun';

    protected static ?string $modelLabel = 'Akun';

    protected static ?string $pluralModelLabel = 'Bagan Akun';

    protected static ?int $navigationSort = 0;

    private const TYPE_OPTIONS = [
        'aset' => 'Aset',
        'kewajiban' => 'Kewajiban',
        'modal' => 'Modal',
        'pendapatan' => 'Pendapatan',
        'beban_pokok' => 'Beban Pokok Penjualan (HPP)',
        'beban_operasional' => 'Beban Operasional',
        'pendapatan_lain' => 'Pendapatan Lain-lain',
        'beban_lain' => 'Beban Lain-lain',
        'pajak' => 'Pajak',
    ];

    private const TYPE_COLORS = [
        'aset' => 'info',
        'kewajiban' => 'warning',
        'modal' => 'primary',
        'pendapatan' => 'success',
        'beban_pokok' => 'danger',
        'beban_operasional' => 'danger',
        'pendapatan_lain' => 'gray',
        'beban_lain' => 'gray',
        'pajak' => 'gray',
    ];

    public static function canViewAny(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    /**
     * Diblokir kalau masih punya akun anak (parent_id.nullOnDelete() di
     * DB tidak akan MENOLAK, cuma diam-diam melepas anaknya jadi
     * yatim) — dicek di sini supaya staff sadar dulu sebelum struktur
     * hierarki keliru tanpa peringatan.
     */
    public static function canDelete($record): bool
    {
        return (auth()->user()?->isFullAccess() ?? false)
            && ! $record->children()->exists();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Akun')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->label('Kode Akun')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(10)
                        ->placeholder('Mis. 6480')
                        // Kode SEKALI ditentukan tidak diubah lagi — begitu
                        // akun ini mulai dipakai jurnal (Fase berikutnya),
                        // riwayat laporan lama akan merujuk kode ini. Boleh
                        // diisi bebas cuma saat pertama kali dibuat.
                        ->disabled(fn (?ChartOfAccount $record) => $record !== null)
                        ->dehydrated(),

                    Forms\Components\TextInput::make('name')
                        ->label('Nama Akun')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\Select::make('type')
                        ->label('Klasifikasi')
                        ->options(self::TYPE_OPTIONS)
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('parent_id', null))
                        ->helperText('Menentukan saldo normal (debit/kredit) akun ini secara otomatis.'),

                    Forms\Components\Select::make('parent_id')
                        ->label('Akun Induk (opsional)')
                        ->options(fn (Forms\Get $get, ?ChartOfAccount $record) => ChartOfAccount::where('is_postable', false)
                            ->when($get('type'), fn ($q, $type) => $q->where('type', $type))
                            ->when($record, fn ($q) => $q->where('id', '!=', $record->id))
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => $a->display_name]))
                        ->searchable()
                        ->placeholder('— Tidak ada (akun tingkat atas) —')
                        ->helperText('Cuma bisa memilih akun header (yang "Bisa Diposting" dimatikan) dengan klasifikasi yang sama.'),

                    Forms\Components\Toggle::make('is_postable')
                        ->label('Bisa Diposting Transaksi')
                        ->default(true)
                        ->helperText('Matikan untuk akun header/pengelompok (mis. "Aset Lancar") yang cuma membungkus akun detail di bawahnya — tidak boleh menerima transaksi langsung.'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Aktif')
                        ->default(true)
                        ->helperText('Akun nonaktif tidak muncul lagi sebagai pilihan baru, riwayat lama tetap tersimpan.'),

                    Forms\Components\Textarea::make('description')
                        ->label('Keterangan')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Akun')
                    ->searchable()
                    // Indentasi visual untuk akun anak — supaya hierarki
                    // kelihatan sekilas tanpa perlu tree-grid terpisah,
                    // konsisten dengan urutan defaultSort('code') yang
                    // secara alami mengelompokkan parent-child.
                    ->formatStateUsing(fn (ChartOfAccount $record, string $state) => $record->parent_id ? "— {$state}" : $state)
                    ->weight(fn (ChartOfAccount $record) => $record->is_postable ? 'normal' : 'bold'),

                Tables\Columns\BadgeColumn::make('type')
                    ->label('Klasifikasi')
                    ->colors(self::TYPE_COLORS)
                    ->formatStateUsing(fn (string $state) => self::TYPE_OPTIONS[$state] ?? $state),

                Tables\Columns\TextColumn::make('normal_balance')
                    ->label('Saldo Normal')
                    ->badge()
                    ->color(fn (string $state) => $state === 'debit' ? 'info' : 'warning')
                    ->formatStateUsing(fn (string $state) => ucfirst($state)),

                Tables\Columns\IconColumn::make('is_postable')
                    ->label('Postable')
                    ->boolean()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),

                Tables\Columns\TextColumn::make('children_count')
                    ->label('Akun Anak')
                    ->counts('children')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Klasifikasi')
                    ->options(self::TYPE_OPTIONS),

                Tables\Filters\TernaryFilter::make('is_postable')
                    ->label('Bisa Diposting'),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Aktif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn (ChartOfAccount $record) => (auth()->user()?->isFullAccess() ?? false) && ! $record->children()->exists())
                    ->action(function (ChartOfAccount $record) {
                        if ($record->children()->exists()) {
                            Notification::make()
                                ->title('Tidak bisa menghapus akun ini')
                                ->body('Akun ini masih punya akun anak di bawahnya. Pindahkan atau hapus akun anaknya dulu.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->delete();

                        Notification::make()->title('Akun dihapus')->success()->send();
                    }),
            ])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListChartOfAccounts::route('/'),
            'create' => Pages\CreateChartOfAccount::route('/create'),
            'edit' => Pages\EditChartOfAccount::route('/{record}/edit'),
        ];
    }
}
