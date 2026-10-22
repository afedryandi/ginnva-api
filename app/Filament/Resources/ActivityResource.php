<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ActivityResource\Pages;
use App\Models\User;
use App\Support\ActivityPresenter;
use Carbon\Carbon;
use Filament\Facades\Filament;
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
    'auth' => 'Keamanan Akun',
    'booking_sale_line' => 'Rincian Penjualan Booking',
    'booking_settlement_payment' => 'Pembayaran Pelunasan Booking',
    'invoice_item' => 'Item Invoice',
    'notification_campaign' => 'Kampanye Notifikasi',
    'notification_template' => 'Templat Notifikasi',
    'asset' => 'Aset Tetap',
    'attendance' => 'Absensi',
    'attendance_correction_request' => 'Koreksi Absensi',
    'bank_statement_line' => 'Mutasi Rekening Bank',
    'blocked_date' => 'Tanggal Diblokir',
    'booking' => 'Booking',
    'booking_cancellation_request' => 'Permintaan Pembatalan Booking',
    'booking_reschedule_request' => 'Permintaan Jadwal Ulang Booking',
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
    'marketing_consent' => 'Izin Foto Pelanggan',
    'material' => 'Materi Download',
    'material_category' => 'Kategori Materi',
    'material_memo_item' => 'Memo Pengambilan/Pengembalian',
    'news' => 'Berita',
    'partner' => 'Partner',
    'partnership_inquiry' => 'Kemitraan & Sales Referral',
    'partner_point_transaction' => 'Poin Partner',
    'payable' => 'Utang',
    'payable_payment' => 'Pembayaran Utang',
    'attendance_deduction_rule' => 'Aturan Potongan Absensi',
    'leave_type' => 'Jenis Izin & Cuti',
    'company_policy' => 'Peraturan & Kebijakan',
    'checklist_template' => 'Template Checklist',
    'employee_checklist_item' => 'Checklist Karyawan',
    'salary_grade' => 'Struktur & Skala Upah',
    'technician_skill' => 'Keterampilan Teknisi',
    'ppe_type' => 'Jenis APD',
    'ppe_issuance' => 'Pembagian APD',
    'employee_training' => 'Pelatihan Karyawan',
    'safety_incident' => 'Kejadian K3',
    'performance_review' => 'Penilaian Kinerja',
    'booking_rework' => 'Pekerjaan Ulang',
    'payment_account' => 'Rekening Kas & Bank',
    'cash_transfer' => 'Pemindahan Kas',
    'cash_count' => 'Hitung Kas Harian',
    'year_end_closing' => 'Tutup Tahun Buku',
    'vehicle_glass_area' => 'Luas Kaca Kendaraan',
    'budget' => 'Anggaran',
    'finance_setting' => 'Pengaturan Keuangan',
    'technician_training' => 'Pelatihan Teknisi',
    'payroll' => 'Penggajian',
    'payroll_item' => 'Komponen Gaji',
    'payroll_item_category' => 'Kategori Komponen Gaji',
    'price_rule' => 'Aturan Harga (Koefisien)',
    'product_claim' => 'Klaim Produk',
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
    'booking_service_type' => 'Layanan Booking',
    'qc_checklist_item' => 'Daftar Periksa QC',
    'app_setting' => 'Teks Aplikasi',
    'voucher' => 'Kampanye Voucher',
    'voucher_claim' => 'Voucher',
    'warning_letter' => 'Surat Peringatan',
    'warranty' => 'Garansi',
    'warranty_maintenance_schedule' => 'Jadwal Maintenance Garansi',
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

    /**
     * Modul yang SEMUA perubahannya dianggap berisiko (Histori Aktivitas Fase 5, tab "Perubahan Berisiko"): hak akses dan peran,
     * penutupan periode dan pengaturan keuangan, harga dan tarif, rekening kas, persetujuan, ekspor data, dan perubahan uang
     * pada booking. Penghapusan di modul mana pun dan kejadian keamanan akun yang janggal ditambahkan oleh applyRiskyScope().
     */
    public const RISKY_MODULES = [
        'user', 'role', 'accounting_period', 'year_end_closing', 'finance_setting', 'chart_of_account',
        'price_rule', 'film_product', 'film_product_price', 'film_product_group_price', 'technician_service_rate',
        'payment_account', 'cash_transfer', 'cash_count', 'attendance_deduction_rule', 'salary_grade', 'payroll',
        'transaction_approval_request', 'finance_transaction_approval_request', 'stock_write_off',
        'booking_sale_line', 'booking_settlement_payment', 'report_export',
    ];

    /** Kejadian keamanan akun yang dianggap berisiko (masuk biasa dan keluar tidak). */
    public const RISKY_AUTH_EVENTS = ['login_failed', 'password_changed', 'password_reset'];

    public static function applyRiskyScope(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereIn('log_name', self::RISKY_MODULES)
                ->orWhere('event', 'deleted')
                ->orWhere(fn (Builder $auth) => $auth->where('log_name', 'auth')->whereIn('event', self::RISKY_AUTH_EVENTS));
        });
    }

    public static function logNameLabel(?string $logName): string
    {
        return $logName ? (self::LOG_NAME_LABELS[$logName] ?? $logName) : '—';
    }

    // Akun full-access, atau staf yang diberi kotak Akses Menu "Histori Aktivitas" (mis. Spv Finance / Spv IT; keputusan pemilik
    // 2026-10-21). Hak lihat sekaligus hak ekspor; tidak ada hak ubah/hapus karena log memang tidak boleh diubah.
    public static function canViewAny(): bool
    {
        $user = auth()->user();

        if ($user?->isFullAccess()) {
            return true;
        }

        return (bool) ($user?->canAccessStaffArea() && $user->hasMenuAccess(static::class));
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
     * Dibiarkan seluas canViewAny(), konsisten dengan pembatasan resource ini.
     */
    public static function canView($record): bool
    {
        return static::canViewAny();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Waktu selalu dalam WIB, apa pun zona aplikasinya (Histori Aktivitas Fase 0).
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Waktu (WIB)')
                    ->dateTime('d M Y H:i:s', ActivityPresenter::TIMEZONE)
                    ->sortable(),

                Tables\Columns\TextColumn::make('causer.name')
                    ->label('Pelaku')
                    ->placeholder('Sistem')
                    ->searchable()
                    ->description(fn (Activity $record) => ActivityPresenter::actorNote($record)),

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
                    // Catatan manual (activity()->log()) tidak mengisi jenis aksi: tampil "Dicatat".
                    ->formatStateUsing(fn (?string $state): string => ActivityPresenter::eventLabel($state)),

                Tables\Columns\TextColumn::make('source')
                    ->label('Asal')
                    ->state(fn (Activity $record) => ActivityPresenter::sourceLabel(data_get($record->properties, 'context.source')))
                    ->toggleable(),

                // Label objek memakai nama resource ("Toko #2") dan menjadi tautan ke rekamannya bila masih ada.
                Tables\Columns\TextColumn::make('subject_type')
                    ->label('Objek')
                    ->formatStateUsing(fn (?string $state, Activity $record) => $state ? ActivityPresenter::subjectLabel($record) : '—')
                    ->url(fn (Activity $record) => ActivityPresenter::subjectUrl($record)),

                Tables\Columns\TextColumn::make('description')
                    ->label('Deskripsi')
                    ->state(fn (Activity $record) => ActivityPresenter::describe($record))
                    ->searchable()
                    ->limit(60)
                    ->tooltip(fn (Activity $record) => ActivityPresenter::describe($record)),
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
                // Pelaku pelanggan (aksi lewat aplikasi pelanggan): dicari lewat nama, email, atau telepon.
                Tables\Filters\SelectFilter::make('customer_causer_id')
                    ->label('Pelaku (Pelanggan)')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => \App\Models\Customer::query()
                        ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('phone_number', 'like', "%{$search}%"))
                        ->orderBy('name')
                        ->limit(50)
                        ->get(['id', 'name', 'email'])
                        ->mapWithKeys(fn ($c) => [$c->id => ($c->name ?: $c->email ?: 'Pelanggan') . ' (#' . $c->id . ')'])
                        ->all())
                    ->getOptionLabelUsing(fn ($value) => 'Pelanggan #' . $value)
                    ->options([])
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'],
                        fn (Builder $q, $value) => $q->where('causer_id', $value)->where('causer_type', \App\Models\Customer::class)
                    )),

                // Asal aksi (Fase 2): panel admin, aplikasi staf, aplikasi pelanggan, atau sistem terjadwal.
                Tables\Filters\SelectFilter::make('source')
                    ->label('Asal')
                    ->options(\App\Support\ActivityContext::SOURCE_LABELS)
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'],
                        fn (Builder $q, $value) => $q->where('properties->context->source', $value)
                    )),

                // Tanggal dibaca sebagai tanggal WIB (batas hari 00:00 sampai 23:59 WIB), lalu diubah ke zona aplikasi.
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('Dari Tanggal (WIB)'),
                        \Filament\Forms\Components\DatePicker::make('until')->label('Sampai Tanggal (WIB)'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $date) => $q->where('created_at', '>=', Carbon::parse($date, ActivityPresenter::TIMEZONE)->startOfDay()->setTimezone(config('app.timezone'))))
                        ->when($data['until'] ?? null, fn ($q, $date) => $q->where('created_at', '<=', Carbon::parse($date, ActivityPresenter::TIMEZONE)->endOfDay()->setTimezone(config('app.timezone'))))
                    ),

                // Filter objek (jenis + nomor): menjawab "apa saja yang terjadi pada Booking #12".
                Tables\Filters\Filter::make('subject')
                    ->label('Objek')
                    ->columns(2)
                    ->form([
                        \Filament\Forms\Components\Select::make('type')
                            ->label('Jenis Objek')
                            ->searchable()
                            ->options(fn () => static::subjectTypeOptions()),
                        \Filament\Forms\Components\TextInput::make('id')->label('Nomor Objek')->numeric()->minValue(1),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['type'] ?? null, fn ($q, $type) => $q->where('subject_type', $type))
                        ->when($data['id'] ?? null, fn ($q, $id) => $q->where('subject_id', $id))
                    )
                    ->indicateUsing(function (array $data): ?string {
                        if (blank($data['type'] ?? null) && blank($data['id'] ?? null)) {
                            return null;
                        }

                        $label = filled($data['type'] ?? null) ? (static::subjectTypeOptions()[$data['type']] ?? class_basename($data['type'])) : 'Objek';

                        return $label . (filled($data['id'] ?? null) ? ' #' . $data['id'] : '');
                    }),
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
            TextEntry::make('created_at')->label('Waktu (WIB)')->dateTime('d M Y H:i:s', ActivityPresenter::TIMEZONE),
            TextEntry::make('causer.name')->label('Pelaku')->placeholder('Sistem (otomatis)'),
            TextEntry::make('causer.email')->label('Email Pelaku')->placeholder('—'),
            TextEntry::make('log_name')
                ->label('Modul')
                ->formatStateUsing(fn (?string $state): string => $state ? (self::LOG_NAME_LABELS[$state] ?? $state) : '—'),
            TextEntry::make('event')->label('Aksi')->formatStateUsing(fn (?string $state) => ActivityPresenter::eventLabel($state)),
            TextEntry::make('context_source')
                ->label('Asal')
                ->state(fn (Activity $record) => ActivityPresenter::sourceLabel(data_get($record->properties, 'context.source'))),
            TextEntry::make('context_ip')
                ->label('Alamat IP')
                ->state(fn (Activity $record) => data_get($record->properties, 'context.ip'))
                ->visible(fn (Activity $record) => filled(data_get($record->properties, 'context.ip'))),
            TextEntry::make('context_device')
                ->label('Perangkat')
                ->state(fn (Activity $record) => data_get($record->properties, 'context.device'))
                ->columnSpanFull()
                ->visible(fn (Activity $record) => filled(data_get($record->properties, 'context.device'))),
            TextEntry::make('subject_type')
                ->label('Objek')
                ->formatStateUsing(fn ($state, $record) => $state ? ActivityPresenter::subjectLabel($record) : '—')
                ->url(fn (Activity $record) => ActivityPresenter::subjectUrl($record)),
            TextEntry::make('description')->label('Deskripsi')->state(fn (Activity $record) => ActivityPresenter::describe($record))->columnSpanFull(),
            TextEntry::make('properties.reason')
                ->label('Alasan')
                ->columnSpanFull()
                ->visible(fn (Activity $record) => filled($record->properties['reason'] ?? null)),
            // Nilai bersarang (array/objek, mis. jam operasional) diubah jadi teks JSON: KeyValueEntry hanya bisa
            // menampilkan teks, dan nilai array membuat halaman detail error.
            KeyValueEntry::make('properties.old')
                ->label('Nilai Sebelumnya')
                ->columnSpanFull()
                ->keyLabel('Kolom')
                ->valueLabel('Nilai')
                ->state(fn (Activity $record) => ActivityPresenter::rows($record->properties['old'] ?? []))
                ->visible(fn (Activity $record) => filled($record->properties['old'] ?? null)),
            KeyValueEntry::make('properties.attributes')
                ->label('Nilai Baru')
                ->columnSpanFull()
                ->keyLabel('Kolom')
                ->valueLabel('Nilai')
                ->state(fn (Activity $record) => ActivityPresenter::rows($record->properties['attributes'] ?? []))
                ->visible(fn (Activity $record) => filled($record->properties['attributes'] ?? null)),
        ]);
    }

    /**
     * @param  mixed  $values
     * @return array<string, string>
     */
    public static function stringifyValues($values): array
    {
        return collect(is_array($values) ? $values : [])
            ->map(fn ($value) => match (true) {
                $value === null => '—',
                is_bool($value) => $value ? 'ya' : 'tidak',
                is_scalar($value) => (string) $value,
                default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            })
            ->all();
    }

    /**
     * Pilihan filter Objek: jenis objek yang benar-benar ada di log, dengan nama resource ("Toko") bila ada.
     *
     * @return array<string, string> kelas model => label
     */
    public static function subjectTypeOptions(): array
    {
        return Activity::query()
            ->whereNotNull('subject_type')
            ->distinct()
            ->pluck('subject_type')
            ->mapWithKeys(function (string $type) {
                $resource = ActivityPresenter::classExists($type) ? Filament::getModelResource($type) : null;

                return [$type => $resource ? $resource::getModelLabel() : class_basename($type)];
            })
            ->sort()
            ->all();
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