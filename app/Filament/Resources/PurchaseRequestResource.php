<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PurchaseRequestResource\Pages;
use App\Models\ChartOfAccount;
use App\Models\ConsumableItem;
use App\Models\PurchaseRequest;
use App\Models\RawMaterial;
use App\Models\Store;
use App\Services\PurchaseRequestPostingService;
use App\Services\PushNotificationService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PurchaseRequestResource extends Resource
{
    protected static ?string $model = PurchaseRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = 'Inventaris';

    protected static ?string $navigationLabel = 'Permohonan Pembelian';

    protected static ?string $modelLabel = 'Permohonan Pembelian';

    protected static ?string $pluralModelLabel = 'Permohonan Pembelian';

    protected static ?int $navigationSort = 90;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    /**
     * SEBELUMNYA tidak ada override sama sekali di sini dan tidak ada
     * PurchaseRequestPolicy terdaftar — canCreate()/canEdit()/canDelete()
     * bawaan Resource selalu FALSE untuk siapa pun tanpa policy
     * (default-deny Laravel). Sama bug class dengan Resource lain di
     * modul Inventaris (audit-audit sebelumnya). Akibatnya CreateAction,
     * EditAction (sudah ada guard ->visible(status==='pending')), dan
     * DeleteAction (sudah ada guard ->visible(isFullAccess() &&
     * status==='pending')) semuanya tidak pernah muncul. Aksi
     * approve/reject/fulfill AMAN dari bug ini — semuanya Action custom
     * yang di-gate manual lewat ->visible() inline, bukan lewat
     * canEdit()/canDelete() bawaan Filament.
     *
     * canCreate()/canEdit() dibiarkan seluas canViewAny() — tidak ada
     * ->visible() tambahan untuk Create, dan guard status di EditAction
     * sudah cukup sebagai pembatas sendiri. canDelete() dibatasi
     * isFullAccess() (menyamai guard ->visible() yang SUDAH ada eksplisit
     * di DeleteAction).
     */
    public static function canCreate(): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function canEdit($record): bool
    {
        $user = auth()->user();

        return $user?->canAccessStaffArea()
            && $user->hasMenuAccess(static::class);
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
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
            Forms\Components\Section::make('Detail Permohonan')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('store_id')
                        ->label('Toko / Workshop')
                        ->options(fn () => Store::where('is_active', true)->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->default(fn () => $isSuperAdmin ? null : auth()->user()?->store_id)
                        ->disabled(! $isSuperAdmin)
                        ->dehydrated(),

                    Forms\Components\Select::make('item_type')
                        ->label('Jenis Barang')
                        ->options([
                            'raw_material'    => 'Bahan Baku (restock)',
                            'consumable_item' => 'Barang Habis Pakai (restock)',
                            'asset'           => 'Aset Baru (belum ada di katalog)',
                        ])
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('item_id', null)),

                    // Bahan Baku/Barang Habis Pakai: pilih dari katalog yang
                    // sudah ada (restock) — nama & satuan diambil OTOMATIS
                    // dari baris katalognya saat disimpan (lihat
                    // CreatePurchaseRequest), bukan diketik manual, supaya
                    // tidak mungkin typo beda dari nama aslinya di stok.
                    Forms\Components\Select::make('item_id')
                        ->label('Barang')
                        ->visible(fn (Forms\Get $get) => in_array($get('item_type'), ['raw_material', 'consumable_item']))
                        ->required(fn (Forms\Get $get) => in_array($get('item_type'), ['raw_material', 'consumable_item']))
                        ->searchable()
                        ->options(fn (Forms\Get $get) => match ($get('item_type')) {
                            'raw_material'    => RawMaterial::orderBy('name')->pluck('name', 'id'),
                            'consumable_item' => ConsumableItem::orderBy('name')->pluck('name', 'id'),
                            default           => [],
                        }),

                    // Aset baru: belum ada baris katalognya, jadi nama
                    // diketik manual di sini.
                    Forms\Components\TextInput::make('item_name')
                        ->label('Nama Barang / Aset yang Diminta')
                        ->visible(fn (Forms\Get $get) => $get('item_type') === 'asset')
                        ->required(fn (Forms\Get $get) => $get('item_type') === 'asset')
                        ->maxLength(255),

                    Forms\Components\TextInput::make('quantity')
                        ->label('Jumlah')
                        ->numeric()
                        ->minValue(0.01)
                        ->required()
                        ->default(1),

                    Forms\Components\Textarea::make('reason')
                        ->label('Alasan / Catatan')
                        ->placeholder('Contoh: stok tinggal 2 liter, dipakai rata-rata 5 liter/minggu')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('request_number')
                    ->label('No. Permohonan')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('item_name')
                    ->label('Barang')
                    ->searchable()
                    ->description(fn (PurchaseRequest $record) => match ($record->item_type) {
                        'raw_material'    => 'Bahan Baku',
                        'consumable_item' => 'Barang Habis Pakai',
                        'asset'           => 'Aset Baru',
                        default           => null,
                    }),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Jumlah')
                    ->formatStateUsing(fn (PurchaseRequest $record) => rtrim(rtrim(number_format((float) $record->quantity, 2, ',', '.'), '0'), ',') . ($record->unit ? " {$record->unit}" : '')),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'approved',
                        'danger'  => 'rejected',
                        'gray'    => 'fulfilled',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending'  => 'Menunggu Persetujuan',
                        'approved' => 'Disetujui',
                        'rejected' => 'Ditolak',
                        'fulfilled' => 'Terpenuhi',
                        default    => $state,
                    }),

                Tables\Columns\TextColumn::make('actual_cost')
                    ->label('Biaya Aktual')
                    ->money('IDR', locale: 'id')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('requester.name')
                    ->label('Diajukan Oleh')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Tanggal')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending'   => 'Menunggu Persetujuan',
                        'approved'  => 'Disetujui',
                        'rejected'  => 'Ditolak',
                        'fulfilled' => 'Terpenuhi',
                    ]),

                Tables\Filters\SelectFilter::make('item_type')
                    ->label('Jenis Barang')
                    ->options([
                        'raw_material'    => 'Bahan Baku',
                        'consumable_item' => 'Barang Habis Pakai',
                        'asset'           => 'Aset Baru',
                    ]),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->relationship('store', 'name')
                    ->visible(fn () => auth()->user()?->isFullAccess()),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Setujui')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (PurchaseRequest $record) => auth()->user()?->isFullAccess()
                        && $record->status === 'pending')
                    ->requiresConfirmation()
                    ->action(function (PurchaseRequest $record) {
                        $record->update([
                            'status'      => 'approved',
                            'reviewed_by' => auth()->id(),
                            'reviewed_at' => now(),
                        ]);

                        if ($record->requested_by) {
                            app(PushNotificationService::class)->sendToUsers(
                                [$record->requested_by],
                                'Permohonan Pembelian Disetujui',
                                "Permohonan {$record->request_number} ({$record->item_name}) disetujui."
                            );
                        }

                        Notification::make()->title('Permohonan disetujui')->success()->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (PurchaseRequest $record) => auth()->user()?->isFullAccess()
                        && $record->status === 'pending')
                    ->form([
                        Forms\Components\Textarea::make('review_note')
                            ->label('Alasan Ditolak')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (PurchaseRequest $record, array $data) {
                        $record->update([
                            'status'      => 'rejected',
                            'reviewed_by' => auth()->id(),
                            'reviewed_at' => now(),
                            'review_note' => $data['review_note'],
                        ]);

                        if ($record->requested_by) {
                            app(PushNotificationService::class)->sendToUsers(
                                [$record->requested_by],
                                'Permohonan Pembelian Ditolak',
                                "Permohonan {$record->request_number} ({$record->item_name}) ditolak: {$data['review_note']}"
                            );
                        }

                        Notification::make()->title('Permohonan ditolak')->warning()->send();
                    }),

                // Sengaja TIDAK bikin movement stok otomatis di sini — staff
                // tetap catat stok masuk lewat "Catat Stok" yang sudah ada
                // begitu barang benar-benar sampai, baru tandai Terpenuhi
                // (lihat catatan migration create_purchase_requests_table).
                Tables\Actions\Action::make('fulfill')
                    ->label('Tandai Terpenuhi')
                    ->icon('heroicon-o-archive-box-arrow-down')
                    ->color('gray')
                    ->visible(fn (PurchaseRequest $record) => $record->status === 'approved'
                        && (auth()->user()?->isFullAccess() || auth()->id() === $record->requested_by))
                    ->form([
                        // actual_cost BARU diisi di sini (bukan saat
                        // permohonan diajukan) — harga aktual baru pasti
                        // setelah barang benar-benar dibeli, dipakai
                        // PurchaseRequestPostingService untuk jurnal
                        // Persediaan/Aset otomatis.
                        Forms\Components\TextInput::make('actual_cost')
                            ->label('Total Biaya Aktual')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->prefix('Rp')
                            ->helperText('Dipakai untuk mencatat jurnal Persediaan/Aset otomatis (Kredit Hutang Usaha).'),

                        Forms\Components\Select::make('chart_of_account_id')
                            ->label('Akun Aset Tetap Tujuan')
                            ->visible(fn (PurchaseRequest $record) => $record->item_type === 'asset')
                            ->required(fn (PurchaseRequest $record) => $record->item_type === 'asset')
                            ->options(fn () => ChartOfAccount::whereHas('parent', fn ($q) => $q->where('code', '1200'))
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => $a->display_name]))
                            ->searchable()
                            ->helperText('Permohonan jenis "Aset Baru" belum punya baris Aset tersendiri — pilih akun Aset Tetap yang sesuai secara manual.'),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription('Pastikan barang sudah dicatat masuk lewat "Catat Stok" sebelum menandai ini terpenuhi.')
                    ->action(function (PurchaseRequest $record, array $data) {
                        try {
                            DB::transaction(function () use ($record, $data) {
                                $record->update([
                                    'status'       => 'fulfilled',
                                    'fulfilled_at' => now(),
                                    'actual_cost'  => $data['actual_cost'],
                                ]);

                                $entry = app(PurchaseRequestPostingService::class)->post(
                                    $record->refresh(),
                                    (float) $data['actual_cost'],
                                    $data['chart_of_account_id'] ?? null
                                );

                                $record->update(['journal_entry_id' => $entry->id]);
                            });
                        } catch (RuntimeException $e) {
                            Notification::make()
                                ->title('Gagal menandai terpenuhi')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        if ($record->requested_by) {
                            app(PushNotificationService::class)->sendToUsers(
                                [$record->requested_by],
                                'Permohonan Pembelian Terpenuhi',
                                "Permohonan {$record->request_number} ({$record->item_name}) sudah terpenuhi, barang sudah tersedia."
                            );
                        }

                        Notification::make()->title('Permohonan ditandai terpenuhi')->success()->send();
                    }),

                Tables\Actions\EditAction::make()
                    ->visible(fn (PurchaseRequest $record) => $record->status === 'pending'),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn (PurchaseRequest $record) => auth()->user()?->isFullAccess()
                        && $record->status === 'pending'),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    protected static ?string $navigationBadgeColor = 'warning';

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPurchaseRequests::route('/'),
            'create' => Pages\CreatePurchaseRequest::route('/create'),
            'edit'   => Pages\EditPurchaseRequest::route('/{record}/edit'),
        ];
    }
}
