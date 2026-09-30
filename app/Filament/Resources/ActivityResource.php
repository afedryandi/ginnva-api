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

    /**
     * Peta log_name -> label Indonesia (audit 2026-09-30) -- SEBELUMNYA badge
     * "Modul" & filter dropdown cuma mengenali 14 dari 65+ log_name yang
     * benar-benar dipakai di sistem (hasil rangkaian audit LogsActivity
     * sebelumnya di 65 model + beberapa activity() manual seperti
     * 'report_export'). Modul yang tidak dikenali jatuh ke badge mentah
     * snake_case DAN sama sekali tidak muncul di filter, jadi staff tidak
     * bisa menyaring sebagian besar histori aktivitas lewat dropdown ini.
     * Daftar ini harus di-update kalau ada useLogName()/activity() baru --
     * cek dengan: grep -rhoP "useLogName\('\K[^']+" app/Models | sort -u
     */
    private const LOG_NAME_LABELS = [
    'accounting_period' => 'Periode Akuntansi',
    'asset' => 'Aset Tetap',
    'attendance' => 'Absensi',
    'attendance_correction_request' => 'Koreksi Absensi',
    'bank_statement_line' => 'Mutasi Rekening Bank',
    'blocked_date' => 'Tanggal Diblokir',
    'booking' => 'Booking',
    'carousel' => 'Banner/Carousel',
    'case_study' => 'Studi Kasus',
    'chart_of_account' => 'Bagan Akun',
    'consumable_item' => 'Barang Habis Pakai',
    'contract_extension' => 'Perpanjangan Kontrak',
    'customer' => 'Pelanggan',
    'customer_gallery_photo' => 'Galeri Customer',
    'customer_group' => 'Grup Pelanggan',
    'employee_document' => 'Dokumen Karyawan',
    'employee_schedule_assignment' => 'Penugasan Jadwal Kerja',
    'employee_type' => 'Jenis Karyawan',
    'featured_product' => 'Seri Produk (Beranda)',
    'film_product' => 'Varian Produk (SKU)',
    'film_product_group_price' => 'Harga Grup Produk',
    'film_product_price' => 'Harga Produk',
    'film_product_recipe_item' => 'Resep Produk',
    'finance_category' => 'Kategori Keuangan',
    'finance_transaction' => 'Transaksi Keuangan',
    'finance_transaction_approval_request' => 'Approval Transaksi Keuangan',
    'inventory_item' => 'Produk PPF/WF',
    'invoice' => 'Invoice',
    'job_opening' => 'Lowongan Kerja',
    'journal_entry' => 'Jurnal',
    'journal_entry_line' => 'Baris Jurnal',
    'leave_request' => 'Pengajuan Cuti',
    'material' => 'Materi Download',
    'material_category' => 'Kategori Materi',
    'material_memo_item' => 'Memo Pengambilan/Pengembalian',
    'news' => 'Berita',
    'partner' => 'Partner',
    'partner_point_transaction' => 'Poin Partner',
    'payable' => 'Utang',
    'payable_payment' => 'Pembayaran Utang',
    'payroll' => 'Penggajian',
    'product_inquiry' => 'Inquiry Produk',
    'purchase_request' => 'Permohonan Pembelian',
    'quotation' => 'Penawaran',
    'raw_material' => 'Bahan Baku',
    'receivable' => 'Piutang',
    'receivable_payment' => 'Pembayaran Piutang',
    'recurring_bill_template' => 'Template Tagihan Berulang',
    'report_export' => 'Ekspor Laporan',
    'reward' => 'Reward',
    'reward_redemption' => 'Klaim Reward',
    'role' => 'Role / Divisi',
    'schedule_day_override' => 'Override Jadwal Harian',
    'scroll_code' => 'Kode Gulungan',
    'shift' => 'Shift',
    'spend_promo' => 'Promo Spend',
    'spk' => 'SPK',
    'stock_opname' => 'Stok Opname',
    'stock_write_off' => 'Stok Terbuang',
    'store' => 'Toko/Dealer',
    'store_capacity_override' => 'Override Kapasitas Toko',
    'supplier' => 'Supplier',
    'technician' => 'Teknisi',
    'technician_service_rate' => 'Tarif Layanan Teknisi',
    'transaction_approval_request' => 'Approval Referral/Refund',
    'user' => 'User Admin',
    'vehicle' => 'Kendaraan',
    'voucher_claim' => 'Voucher',
    'warning_letter' => 'Surat Peringatan',
    'warranty' => 'Garansi',
    'work_schedule' => 'Jadwal Kerja',
    ];

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    // Dipindah ke SistemCluster 2026-09-14 (struktur dropdown top-nav
    // "Lainnya" bertingkat — lihat catatan di MasterDataCluster.php).
    protected static ?string $cluster = \App\Filament\Clusters\SistemCluster::class;

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
                    ->formatStateUsing(fn (?string $state): string => $state
                        ? (self::LOG_NAME_LABELS[$state] ?? $state)
                        : '—'),

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
                    ->searchable()
                    ->options(self::LOG_NAME_LABELS),
                // BUKAN pakai ->relationship('causer', 'name') — 'causer' itu
                // relasi polymorphic (morphTo), helper relationship() Filament
                // tidak dibuat untuk itu (salah query langsung ke tabel
                // activity_log, bukan ke tabel causer-nya). Filter manual saja:
                // di sistem ini causer SELALU App\Models\User (staff/admin/partner).
                Tables\Filters\SelectFilter::make('causer_id')
                    ->label('Pelaku')
                    ->searchable()
                    // Urut is_active dulu (audit 2026-09-30) -- SEBELUMNYA staff
                    // resign/nonaktif tercampur rata dengan yang aktif, bikin
                    // dropdown makin panjang & susah dicari tanpa manfaat (histori
                    // lama tetap tersimpan lewat causer_id, bukan lewat filter ini).
                    ->options(fn () => User::orderByDesc('is_active')->orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'],
                        fn (Builder $q, $value) => $q->where('causer_id', $value)->where('causer_type', User::class)
                    )),

                // Filter rentang tanggal (audit 2026-09-30) -- sejajar Riwayat lain,
                // penting di sini karena 65+ modul aktif logging bisa menumpuk cepat.
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('Dari Tanggal'),
                        \Filament\Forms\Components\DatePicker::make('until')->label('Sampai Tanggal'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'], fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'], fn ($q, $date) => $q->whereDate('created_at', '<=', $date))
                    ),
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
            TextEntry::make('log_name')
                ->label('Modul')
                ->formatStateUsing(fn (?string $state): string => $state ? (self::LOG_NAME_LABELS[$state] ?? $state) : '—'),
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