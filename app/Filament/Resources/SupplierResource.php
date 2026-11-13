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
use Illuminate\Support\HtmlString;

/**
 * Master Supplier (audit Hutang Usaha 2026-09-29, diperluas audit Supplier 2026-11-02): kontak pemasok yang dipakai Pemasok Utama
 * Bahan Baku / Barang Habis Pakai, penerimaan barang (Permohonan Pembelian), dan bila Keuangan menyala, Hutang Usaha.
 * Company-wide (tanpa filter toko). Akses eksplisit (menu harus dicentang di Hak Akses); NPWP dan rekening hanya tampil bila
 * Keuangan menyala dan akun berizin Lihat. Tidak bisa dihapus selama masih dipakai -- nonaktifkan saja supaya riwayat utuh.
 */
class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $cluster = \App\Filament\Clusters\InventarisCluster::class;

    // Grup sidebar (audit navigasi 2026-09-29) -- band 0-99 "Transaksi".
    protected static ?string $navigationGroup = 'Permintaan & Memo';

    protected static ?string $navigationLabel = 'Supplier';

    protected static ?string $modelLabel = 'Supplier';

    protected static ?string $pluralModelLabel = 'Supplier';

    protected static ?int $navigationSort = 91;

    /** Eksplisit (bukan akses bawaan): akun dengan menu kosong tidak ikut, sebab isinya kontak dan data rekening pemasok. */
    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->canAccessStaffArea() && $user->hasExplicitMenuAccess(static::class);
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();

        return static::canViewAny() && ($user->isFullAccess() || $user->hasModuleAction(static::class, 'create', false));
    }

    public static function canEdit($record): bool
    {
        $user = auth()->user();

        return static::canViewAny() && ($user->isFullAccess() || $user->hasModuleAction(static::class, 'update', false));
    }

    public static function canDelete($record): bool
    {
        // Sebelumnya hanya memeriksa payables() -- supplier yang sudah dipakai Template Tagihan
        // Rutin (belum pernah generate) bisa dihapus dan template-nya diam-diam kehilangan taut
        // (nullOnDelete) tanpa peringatan (audit Supplier 2026-09-29). Kini termasuk Pemasok Utama bahan dan permohonan pembelian.
        return (auth()->user()?->isFullAccess() ?? false) && ! $record->isInUse();
    }

    public static function canDeleteAny(): bool
    {
        return false; // tanpa bulk delete
    }

    /**
     * NPWP dan rekening pembayaran: hanya saat Keuangan menyala (bila mati, pembayaran dilakukan di Accurate dan kolom ini
     * disembunyikan; data yang sudah ada tetap tersimpan) dan hanya bagi akses penuh atau pemegang izin Lihat.
     */
    public static function canSeeBanking(): bool
    {
        $user = auth()->user();

        return (bool) config('features.finance')
            && $user !== null
            && ($user->isFullAccess() || $user->hasModuleAction(static::class, 'view', false));
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identitas')->columns(2)->schema([
                Forms\Components\TextInput::make('name')->label('Nama Supplier')->required()->maxLength(255)
                    ->unique(ignoreRecord: true)
                    // unique() di atas membandingkan teks mentah: "PT  Jaya" (spasi ganda) lolos padahal sama
                    // dengan "PT Jaya". Bandingkan versi yang sudah dirapikan, sama dengan yang tersimpan.
                    ->rule(fn (?Supplier $record) => function (string $attribute, $value, \Closure $fail) use ($record) {
                        $clean = Supplier::normalizeName((string) $value);

                        if (Supplier::whereRaw('LOWER(name) = ?', [mb_strtolower($clean)])->when($record, fn ($q) => $q->whereKeyNot($record->id))->exists()) {
                            $fail('Supplier dengan nama ini sudah ada.');
                        }
                    })
                    ->live(onBlur: true)
                    // Tidak memblokir (typo/singkatan yang beda sengaja tetap boleh) -- hanya
                    // mengingatkan kalau ada nama yang mirip, supaya tidak tanpa sadar membuat
                    // supplier duplikat gara-gara ejaan/kapitalisasi berbeda (audit Supplier 2026-09-29).
                    ->afterStateUpdated(function ($state, Forms\Set $set, ?Supplier $record) {
                        $set('similar_warning', static::findSimilarSupplierName($state, $record?->id));
                    }),

                Forms\Components\Placeholder::make('similar_warning')
                    ->label('')
                    ->visible(fn (Forms\Get $get) => filled($get('similar_warning')))
                    ->content(fn (Forms\Get $get) => new HtmlString('<span class="text-warning-600">⚠ Mirip dengan supplier yang sudah ada: <strong>' . e($get('similar_warning')) . '</strong> — pastikan ini bukan duplikat.</span>'))
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('npwp')->label('NPWP')->maxLength(30)
                    ->visible(fn () => static::canSeeBanking())
                    ->unique(ignoreRecord: true)
                    // Format lama 15 digit (xx.xxx.xxx.x-xxx.xxx) atau baru 16 digit (NIK) -- disimpan
                    // tanpa titik/strip, jadi validasi cukup 15/16 digit angka (audit Supplier 2026-09-29).
                    ->rule('regex:/^\d{15,16}$/')
                    ->validationMessages(['regex' => 'NPWP harus 15 atau 16 digit angka (tanpa titik/strip).', 'unique' => 'NPWP ini sudah dipakai supplier lain.'])
                    ->helperText('15 atau 16 digit, tanpa titik/strip. Opsional.'),
                Forms\Components\TextInput::make('phone')->label('Telepon')->tel()->maxLength(30)
                    ->rule('regex:/^[0-9+()\-\s]{6,30}$/')
                    ->validationMessages(['regex' => 'Nomor telepon hanya boleh angka, spasi, +, -, ( dan ).']),
                Forms\Components\TextInput::make('email')->label('Email')->email()->maxLength(255),
                Forms\Components\Textarea::make('address')->label('Alamat')->rows(2)->columnSpanFull(),
            ]),
            Forms\Components\Section::make('Rekening Pembayaran')->columns(3)
                ->visible(fn () => static::canSeeBanking())
                ->schema([
                    Forms\Components\TextInput::make('bank_name')->label('Bank')->maxLength(255),
                    Forms\Components\TextInput::make('bank_account_number')->label('No. Rekening')->maxLength(50),
                    Forms\Components\TextInput::make('bank_account_name')->label('Atas Nama')->maxLength(255),
                ]),
            Forms\Components\Section::make('Dipasok untuk')
                ->visible(fn (?Supplier $record) => $record !== null)
                ->schema([
                    Forms\Components\Placeholder::make('supplied_items')
                        ->label('')
                        ->content(fn (?Supplier $record) => static::suppliedItemsHtml($record)),
                ]),
            Forms\Components\Section::make('Lainnya')->schema([
                Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true)
                    ->helperText('Supplier nonaktif tidak muncul di pilihan baru, riwayat tetap utuh.'),
                Forms\Components\Textarea::make('notes')->label('Catatan')->rows(2),
            ]),
        ]);
    }

    /** Bahan Baku dan Barang Habis Pakai yang menjadikan supplier ini Pemasok Utama (maksimal 20 nama per jenis). */
    private static function suppliedItemsHtml(?Supplier $record): HtmlString
    {
        if ($record === null) {
            return new HtmlString('—');
        }

        $lines = [];

        foreach (array_filter([['Bahan Baku', $record->suppliedMaterials()], config('features.consumables') ? ['Barang Habis Pakai', $record->suppliedConsumables()] : null]) as [$label, $relation]) {
            $total = (clone $relation)->count();

            if ($total === 0) {
                continue;
            }

            $names = (clone $relation)->orderBy('name')->limit(20)->pluck('name')->map(fn ($n) => e($n))->implode(', ');
            $more = $total > 20 ? ' dan ' . ($total - 20) . ' lainnya' : '';
            $lines[] = "<div><strong>{$label} ({$total}):</strong> {$names}{$more}</div>";
        }

        return new HtmlString($lines === [] ? 'Belum jadi Pemasok Utama bahan atau barang mana pun.' : implode('', $lines));
    }

    /** Cek nama mirip (jarak edit kecil) di antara supplier aktif lain -- lihat afterStateUpdated di atas. */
    private static function findSimilarSupplierName(?string $name, ?int $ignoreId): ?string
    {
        $name = trim((string) $name);
        if (mb_strlen($name) < 3) {
            return null;
        }

        $normalized = mb_strtolower(preg_replace('/\s+/', ' ', $name));

        foreach (Supplier::where('is_active', true)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->pluck('name') as $existing) {
            $existingNormalized = mb_strtolower(preg_replace('/\s+/', ' ', $existing));

            if ($existingNormalized === $normalized) {
                continue; // sudah ditangkap validasi unique, tidak perlu peringatan ganda
            }

            if (levenshtein($normalized, $existingNormalized) <= 2) {
                return $existing;
            }
        }

        return null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount(['payables', 'suppliedMaterials', 'suppliedConsumables'])
            ->withCount(['purchaseRequests as received_count' => fn ($q) => $q->withoutGlobalScopes()->where('status', 'fulfilled')]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Supplier')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('phone')->label('Telepon')->placeholder('—')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('email')->label('Email')->placeholder('—')->searchable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('npwp')->label('NPWP')->placeholder('—')->searchable()->toggleable()
                    ->visible(fn () => static::canSeeBanking()),
                Tables\Columns\TextColumn::make('bank_name')->label('Bank')->placeholder('—')
                    ->visible(fn () => static::canSeeBanking())
                    ->description(fn (Supplier $r) => $r->bank_account_number ? $r->bank_account_number . ($r->bank_account_name ? ' a.n. ' . $r->bank_account_name : '') : null)
                    // Cari juga di nomor rekening (bukan cuma nama bank) -- audit Supplier 2026-09-29.
                    ->searchable(['bank_name', 'bank_account_number', 'bank_account_name'])
                    ->toggleable(),
                Tables\Columns\TextColumn::make('supplied_items')->label('Pemasok Utama untuk')
                    ->state(fn (Supplier $r) => (int) $r->supplied_materials_count + (int) $r->supplied_consumables_count)
                    ->suffix(' bahan/barang')
                    ->alignEnd(),
                Tables\Columns\TextColumn::make('received_count')->label('Pembelian Diterima')->sortable()->alignEnd(),
                Tables\Columns\TextColumn::make('payables_count')->label('Tagihan')->sortable()
                    ->visible(fn () => (bool) config('features.finance')),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Aktif'),
                Tables\Filters\TernaryFilter::make('has_bank')->label('Punya rekening')
                    ->visible(fn () => static::canSeeBanking())
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('bank_account_number')->where('bank_account_number', '!=', ''),
                        false: fn (Builder $query) => $query->where(fn ($q) => $q->whereNull('bank_account_number')->orWhere('bank_account_number', '')),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(function (Supplier $record, Tables\Actions\DeleteAction $action) {
                        if ($record->isInUse()) {
                            Notification::make()
                                ->title('Supplier masih dipakai')
                                ->body('Dipakai sebagai ' . implode(', ', $record->inUseReasons()) . '. Nonaktifkan saja.')
                                ->danger()
                                ->send();
                            $action->cancel();
                        }
                    }),
            ])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Resources\SupplierResource\RelationManagers\PurchaseRequestsRelationManager::class,
            \App\Filament\Resources\RelationManagers\ActivityHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuppliers::route('/'),
            'create' => Pages\CreateSupplier::route('/create'),
            'view' => Pages\ViewSupplier::route('/{record}'),
            'edit' => Pages\EditSupplier::route('/{record}/edit'),
        ];
    }
}
