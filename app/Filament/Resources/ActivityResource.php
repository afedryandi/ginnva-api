<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ActivityResource\Pages;
use App\Models\User;
use Filament\Forms\Form;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/**
 * Read-only — histori aksi user (super_admin & admin toko) dari
 * spatie/laravel-activitylog. Tidak ada create/edit/delete: log tidak
 * boleh diubah manual, kalau bisa diedit ya bukan audit trail lagi.
 */
class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'Sistem';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Histori Aktivitas';

    protected static ?string $modelLabel = 'Aktivitas';

    protected static ?string $pluralModelLabel = 'Histori Aktivitas';

    // Cuma super_admin — histori aksi staff/partner adalah data sensitif,
    // admin toko tidak perlu (dan tidak boleh) lihat aktivitas toko lain.
    public static function canViewAny(): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    /**
     * SEBELUMNYA tidak ada override di sini dan tidak ada ActivityPolicy
     * terdaftar — canView() bawaan Resource selalu FALSE untuk siapa pun
     * tanpa policy (default-deny Laravel). Akibatnya tombol "View" (ikon
     * mata) tidak pernah muncul, DAN halaman /admin/activities/{record}
     * (getPages() punya route 'view' sendiri, bukan modal) 403 kalau
     * diakses langsung — satu-satunya cara melihat detail lengkap 1 baris
     * audit trail (properties.old/attributes) sama sekali tidak bisa
     * diakses. Sama bug class yang berulang di audit-audit sebelumnya.
     * Dibiarkan seluas canViewAny() (isFullAccess()), konsisten dengan
     * pembatasan resource ini yang memang khusus super_admin/direksi.
     */
    public static function canView($record): bool
    {
        return auth()->user()?->isFullAccess() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y H:i:s')
                    ->sortable(),

                Tables\Columns\TextColumn::make('causer.name')
                    ->label('Pelaku')
                    ->placeholder('Sistem')
                    ->searchable()
                    ->description(fn (Activity $record) => $record->causer?->email),

                Tables\Columns\BadgeColumn::make('log_name')
                    ->label('Modul')
                    ->colors([
                        'info'    => 'booking',
                        'success' => ['partner', 'raw_material'],
                        'warning' => ['voucher_claim', 'consumable_item'],
                        'gray'    => 'user',
                        'danger'  => ['warranty', 'role'],
                        'primary' => ['reward_redemption', 'customer_gallery_photo'],
                    ])
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'booking'                => 'Booking',
                        'partner'                => 'Partner',
                        'voucher_claim'          => 'Voucher',
                        'user'                   => 'User Admin',
                        'warranty'               => 'Garansi',
                        'reward_redemption'      => 'Klaim Reward',
                        'role'                   => 'Role / Divisi',
                        'customer_gallery_photo' => 'Galeri Customer',
                        'raw_material'           => 'Bahan Baku',
                        'consumable_item'        => 'Barang Habis Pakai',
                        'inventory_item'         => 'Produk PPF/WF',
                        'scroll_code'            => 'Kode Gulungan',
                        'asset'                  => 'Aset Tetap',
                        'material_memo_item'     => 'Memo Pengambilan/Pengembalian',
                        default                  => $state ?? '—',
                    }),

                Tables\Columns\TextColumn::make('event')
                    ->label('Aksi')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'created' => 'Dibuat',
                        'updated' => 'Diubah',
                        'deleted' => 'Dihapus',
                        default   => $state ?? '—',
                    }),

                Tables\Columns\TextColumn::make('subject_type')
                    ->label('Objek')
                    ->formatStateUsing(fn (?string $state, Activity $record) => $state
                        ? class_basename($state) . ' #' . $record->subject_id
                        : '—'),

                Tables\Columns\TextColumn::make('description')
                    ->label('Deskripsi')
                    ->limit(60),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('log_name')
                    ->label('Modul')
                    ->options([
                        'booking'           => 'Booking',
                        'partner'           => 'Partner',
                        'voucher_claim'     => 'Voucher',
                        'user'              => 'User Admin',
                        'warranty'          => 'Garansi',
                        'reward_redemption' => 'Klaim Reward',
                        'role'              => 'Role / Divisi',
                        'customer_gallery_photo' => 'Galeri Customer',
                        'raw_material'      => 'Bahan Baku',
                        'consumable_item'   => 'Barang Habis Pakai',
                        'inventory_item'    => 'Produk PPF/WF',
                        'scroll_code'       => 'Kode Gulungan',
                        'asset'             => 'Aset Tetap',
                        'material_memo_item' => 'Memo Pengambilan/Pengembalian',
                    ]),
                // BUKAN pakai ->relationship('causer', 'name') — 'causer' itu
                // relasi polymorphic (morphTo), helper relationship() Filament
                // tidak dibuat untuk itu (salah query langsung ke tabel
                // activity_log, bukan ke tabel causer-nya). Filter manual saja:
                // di sistem ini causer SELALU App\Models\User (staff/admin/partner).
                Tables\Filters\SelectFilter::make('causer_id')
                    ->label('Pelaku')
                    ->options(fn () => User::orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'],
                        fn (Builder $q, $value) => $q->where('causer_id', $value)->where('causer_type', User::class)
                    )),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll(null);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('created_at')->label('Waktu')->dateTime('d M Y H:i:s'),
            TextEntry::make('causer.name')->label('Pelaku')->placeholder('Sistem (otomatis)'),
            TextEntry::make('causer.email')->label('Email Pelaku')->placeholder('—'),
            TextEntry::make('log_name')->label('Modul'),
            TextEntry::make('event')->label('Aksi'),
            TextEntry::make('subject_type')
                ->label('Objek')
                ->formatStateUsing(fn ($state, $record) => $state ? class_basename($state) . ' #' . $record->subject_id : '—'),
            TextEntry::make('description')->label('Deskripsi')->columnSpanFull(),
            KeyValueEntry::make('properties.old')
                ->label('Nilai Sebelumnya')
                ->columnSpanFull()
                ->visible(fn (Activity $record) => filled($record->properties['old'] ?? null)),
            KeyValueEntry::make('properties.attributes')
                ->label('Nilai Baru')
                ->columnSpanFull()
                ->visible(fn (Activity $record) => filled($record->properties['attributes'] ?? null)),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('causer');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivities::route('/'),
            'view'  => Pages\ViewActivity::route('/{record}'),
        ];
    }
}