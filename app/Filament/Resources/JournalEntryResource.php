<?php

namespace App\Filament\Resources;

use App\Filament\Resources\JournalEntryResource\Pages;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Store;
use App\Services\JournalEntryService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use RuntimeException;

/**
 * Jurnal Umum — TERBATAS full-access (super_admin/direksi), sama
 * filosofi dengan ChartOfAccountResource/PayrollResource: pembukuan
 * berpasangan bukan operasional harian yang cocok didelegasikan ke
 * store_manager (mereka tetap pakai Transaksi Keuangan yang sederhana).
 *
 * Semua tulis-menulis WAJIB lewat JournalEntryService (create/update/
 * post/reverse) — resource ini TIDAK PERNAH panggil JournalEntry::
 * create()/update() langsung, supaya validasi balance debit=kredit
 * selalu tertegak di SATU tempat, tidak bisa dilewati dari jalur mana
 * pun (termasuk kalau nanti ada integrasi otomatis Fase 3).
 *
 * Jurnal 'posted' TERKUNCI total (semua field ->disabled()) — koreksi
 * lewat aksi "Balik Jurnal" (bikin jurnal pembalik baru), bukan edit
 * langsung, supaya riwayat pembukuan selalu bisa diaudit.
 */
class JournalEntryResource extends Resource
{
    protected static ?string $model = JournalEntry::class;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Keuangan';

    protected static ?string $navigationLabel = 'Jurnal Umum';

    protected static ?string $modelLabel = 'Jurnal';

    protected static ?string $pluralModelLabel = 'Jurnal Umum';

    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    /**
     * SENGAJA selalu true untuk full-access (bukan dibatasi status
     * draft) — halaman Edit dipakai DUA fungsi (edit beneran untuk
     * draft, tampilan read-only untuk posted, lihat form() di bawah)
     * supaya tidak perlu halaman View terpisah. Penguncian aktualnya
     * ada di ->disabled() per-field + JournalEntryService::update()
     * yang menolak kalau statusnya sudah bukan draft.
     */
    public static function canEdit($record): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public static function canDelete($record): bool
    {
        return (auth()->user()?->isFullAccess() ?? false) && $record->isDraft();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Jurnal')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('entry_number')
                        ->label('No. Jurnal')
                        ->disabled()
                        ->dehydrated(false)
                        ->visible(fn (?JournalEntry $record) => $record !== null)
                        ->helperText('Dibuat otomatis oleh sistem.'),

                    Forms\Components\DatePicker::make('entry_date')
                        ->label('Tanggal')
                        ->native(false)
                        ->required()
                        ->default(now())
                        ->disabled(fn (?JournalEntry $record) => $record?->status === 'posted'),

                    Forms\Components\Select::make('store_id')
                        ->label('Toko (opsional)')
                        ->options(fn () => Store::pluck('name', 'id'))
                        ->searchable()
                        ->placeholder('Company-wide / tidak terikat 1 toko')
                        ->disabled(fn (?JournalEntry $record) => $record?->status === 'posted'),

                    Forms\Components\Textarea::make('description')
                        ->label('Keterangan')
                        ->required()
                        ->rows(2)
                        ->columnSpanFull()
                        ->disabled(fn (?JournalEntry $record) => $record?->status === 'posted'),
                ]),

            Forms\Components\Section::make('Baris Debit / Kredit')
                ->description('Minimal 2 baris, total debit harus sama dengan total kredit.')
                ->schema([
                    Forms\Components\Repeater::make('lines')
                        ->label('')
                        ->schema([
                            Forms\Components\Select::make('chart_of_account_id')
                                ->label('Akun')
                                ->options(fn () => ChartOfAccount::where('is_postable', true)
                                    ->where('is_active', true)
                                    ->orderBy('code')
                                    ->get()
                                    ->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => $a->display_name]))
                                ->searchable()
                                ->required()
                                ->columnSpan(2),

                            Forms\Components\TextInput::make('debit')
                                ->label('Debit')
                                ->numeric()
                                ->default(0)
                                ->minValue(0)
                                ->prefix('Rp'),

                            Forms\Components\TextInput::make('credit')
                                ->label('Kredit')
                                ->numeric()
                                ->default(0)
                                ->minValue(0)
                                ->prefix('Rp'),

                            Forms\Components\TextInput::make('description')
                                ->label('Keterangan Baris (opsional)')
                                ->columnSpanFull(),
                        ])
                        ->columns(4)
                        ->minItems(2)
                        ->addActionLabel('+ Tambah Baris')
                        ->disabled(fn (?JournalEntry $record) => $record?->status === 'posted')
                        ->deletable(fn (?JournalEntry $record) => $record?->status !== 'posted')
                        ->addable(fn (?JournalEntry $record) => $record?->status !== 'posted'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('entry_number')
                    ->label('No. Jurnal')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('entry_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Keterangan')
                    ->limit(50)
                    ->searchable(),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->placeholder('Company-wide')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('total_debit')
                    ->label('Total')
                    ->state(fn (JournalEntry $record) => $record->totalDebit())
                    ->money('IDR', locale: 'id'),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'draft',
                        'success' => 'posted',
                    ])
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'draft' => 'Draft',
                        'posted' => 'Posted',
                        default => $state,
                    }),

                Tables\Columns\IconColumn::make('is_reversal')
                    ->label('Pembalik')
                    ->state(fn (JournalEntry $record) => $record->reference_type === 'reversal')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['draft' => 'Draft', 'posted' => 'Posted']),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->options(fn () => Store::pluck('name', 'id')),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label(fn (JournalEntry $record) => $record->status === 'posted' ? 'Lihat' : 'Edit'),

                Tables\Actions\Action::make('post')
                    ->label('Posting')
                    ->icon('heroicon-o-lock-closed')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Setelah diposting, jurnal ini TERKUNCI dan tidak bisa diedit/dihapus lagi — koreksi hanya lewat jurnal pembalik.')
                    ->visible(fn (JournalEntry $record) => $record->isDraft())
                    ->action(function (JournalEntry $record) {
                        try {
                            app(JournalEntryService::class)->post($record, auth()->id());
                            Notification::make()->title('Jurnal diposting')->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Gagal posting jurnal')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\Action::make('reverse')
                    ->label('Balik Jurnal')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->form([
                        Forms\Components\Textarea::make('note')
                            ->label('Catatan (opsional)')
                            ->rows(2),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription('Membuat jurnal baru dengan debit/kredit dibalik dari jurnal ini — jurnal aslinya TETAP tersimpan (tidak dihapus), sesuai praktik akuntansi standar.')
                    ->visible(fn (JournalEntry $record) => $record->isPosted() && ! $record->reversal()->exists())
                    ->action(function (JournalEntry $record, array $data) {
                        try {
                            $reversal = app(JournalEntryService::class)->reverse($record, auth()->id(), $data['note'] ?? null);
                            Notification::make()->title("Jurnal pembalik {$reversal->entry_number} dibuat")->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Gagal membalik jurnal')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn (JournalEntry $record) => $record->isDraft()),
            ])
            ->defaultSort('entry_date', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListJournalEntries::route('/'),
            'create' => Pages\CreateJournalEntry::route('/create'),
            'edit' => Pages\EditJournalEntry::route('/{record}/edit'),
        ];
    }
}
