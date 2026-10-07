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

    // Grup sidebar (audit navigasi 2026-09-29) -- band 0-99 "Transaksi".
    protected static ?string $navigationGroup = 'Transaksi';

    protected static ?string $navigationLabel = 'Supplier';

    protected static ?string $modelLabel = 'Supplier';

    protected static ?string $pluralModelLabel = 'Supplier';

    protected static ?int $navigationSort = 2;

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
        // Sebelumnya hanya memeriksa payables() -- supplier yang sudah dipakai Template Tagihan
        // Rutin (belum pernah generate) bisa dihapus dan template-nya diam-diam kehilangan taut
        // (nullOnDelete) tanpa peringatan (audit Supplier 2026-09-29).
        return (auth()->user()?->isFullAccess() ?? false) && ! $record->isInUse();
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
                    ->content(fn (Forms\Get $get) => new \Illuminate\Support\HtmlString('<span class="text-warning-600">⚠ Mirip dengan supplier yang sudah ada: <strong>' . e($get('similar_warning')) . '</strong> — pastikan ini bukan duplikat.</span>'))
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('npwp')->label('NPWP')->maxLength(30)
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
        return parent::getEloquentQuery()->withCount('payables');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Supplier')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('npwp')->label('NPWP')->placeholder('—')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('bank_name')->label('Bank')->placeholder('—')
                    ->description(fn (Supplier $r) => $r->bank_account_number ? $r->bank_account_number . ($r->bank_account_name ? ' a.n. ' . $r->bank_account_name : '') : null)
                    // Cari juga di nomor rekening (bukan cuma nama bank) -- audit Supplier 2026-09-29.
                    ->searchable(['bank_name', 'bank_account_number', 'bank_account_name'])
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
                        if ($record->isInUse()) {
                            Notification::make()->title('Supplier sudah dipakai tagihan atau template tagihan rutin — nonaktifkan saja.')->danger()->send();
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
