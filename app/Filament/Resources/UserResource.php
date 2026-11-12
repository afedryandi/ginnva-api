<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\Store;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $cluster = \App\Filament\Clusters\KaryawanCluster::class;

    // Grup sidebar (audit navigasi 2026-09-29) -- band 0-99 "Data Karyawan", pola band
    // 100-lebar per grup sama seperti cluster Keuangan.
    protected static ?string $navigationGroup = 'Karyawan';

    protected static ?string $navigationLabel = 'User';

    protected static ?string $modelLabel = 'User';

    protected static ?string $pluralModelLabel = 'User';

    protected static ?int $navigationSort = 1;

    /**
     * Resource ini HANYA boleh diakses super_admin. Tidak pakai Policy
     * terpisah karena scope-nya simpel (binary: super_admin atau tidak
     * sama sekali) — beda dengan Warranty/Quotation yang perlu scope
     * per-store yang lebih kompleks.
     */
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

    public static function canDelete($record): bool
    {
        // User tidak boleh hapus akun miliknya sendiri lewat panel ini,
        // supaya tidak ada super_admin yang tidak sengaja terkunci keluar.
        // Hapus PERMANEN juga ditutup begitu akun sudah punya riwayat HR
        // (absensi/cuti/gaji/SP/kontrak) — semua tabel itu cascadeOnDelete
        // ke users.id, jadi hard delete di titik ini akan memusnahkan
        // riwayat payroll/absensi yang wajib tetap ada untuk kebutuhan
        // pajak/BPJS/audit. Gunakan aksi "Nonaktifkan" di tabel, bukan
        // hapus, begitu karyawan sudah pernah tercatat.
        return auth()->user()?->isFullAccess()
            && auth()->id() !== $record->id
            && ! $record->hasHrHistory();
    }

    /**
     * Semua Resource yang BISA diakses staff/role divisi (via
     * canViewAny() atau Policy::viewAny() masing-masing) — dikelompokkan
     * per navigationGroup untuk ditampilkan di CheckboxList "Akses Menu".
     * Resource yang selalu super_admin/direksi-only (RoleResource, User
     * ini sendiri, ActivityResource) SENGAJA tidak masuk sini — staff
     * biasa memang tidak pernah bisa mengaksesnya apapun isi
     * menu_access-nya.
     */
    private static function menuAccessOptions(): array
    {
        // Grup di sini DIRAPIKAN supaya sama persis dengan $navigationGroup
        // resource masing-masing (lihat AdminPanelProvider::navigationGroups())
        // — SEBELUMNYA masih pakai nama grup lama (Penjualan, Konten,
        // Partnership Referral) yang sudah tidak dipakai lagi di sidebar,
        // jadi form "Akses Menu" ini menampilkan section yang tidak nyambung
        // dengan struktur menu yang benar-benar staff lihat. Key 'Karyawan'
        // di sini SENGAJA cuma isi Absensi/Izin/Surat Peringatan/Perpanjang
        // Kontrak (User & Role resource di grup navigasi yang sama TETAP
        // super_admin-only lewat canViewAny() masing-masing, tidak pernah
        // muncul di sini apapun menu_access-nya).
        return [
            'Booking' => [
                'QuotationResource' => 'Quotation (Lead)',
                'BookingResource' => 'Booking Instalasi',
                // Menu Tanggal Tidak Tersedia digabung ke Kalender Kapasitas & Libur (2026-10-26). Kunci lama DIPERTAHANKAN: izin menutup/membuka
                // tanggal yang sudah diberikan tetap berlaku dan tidak hilang saat form pengguna disimpan.
                'BlockedDateResource' => 'Tutup/Buka Tanggal (Kalender Kapasitas & Libur)',
                'BookingServiceTypeResource' => 'Layanan Booking',
                'QcChecklistItemResource' => 'Checklist QC',
                'AppSettingResource' => 'Teks Aplikasi',
                'WarrantyResource' => 'Garansi',
                'StoreReviewResource' => 'Review Toko',
                // Dipindah dari bagian Karyawan (2026-10-30); kuncinya tetap, izin yang tersimpan tidak hilang.
                'BookingReworkResource' => 'Pekerjaan Ulang',
                // Invoice/SPK SEBELUMNYA sudah pakai hasMenuAccess() di
                // canViewAny() masing-masing (siap didelegasikan) tapi
                // KELUPAAN tidak pernah dimasukkan ke daftar ini — checkbox-
                // nya tidak pernah muncul di form User, jadi staff mustahil
                // diberi akses selain lewat isFullAccess(). Ditambahkan
                // 2026-09-23 (audit Majoo f64, susulan) — bukan keputusan
                // bisnis baru, cuma menutup celah checklist yang tertinggal.
                'InvoiceResource' => 'Invoice',
                'SpkResource' => 'SPK',
            ],
            // Halaman laporan yang dijaga menu_access tetapi dulu TIDAK punya kotak di sini, jadi staf yang dibatasi mustahil diberi
            // aksesnya (hanya akses penuh yang bisa). Grup berawalan "Laporan " tampil sebagai satu tab "Laporan" berisi sub-bagian.
            // Laporan omzet (Ringkasan, Per Periode, Outlet, Buku Pembayaran, Refund, Pembatalan, Jasa, Penjualan Produk, Perputaran
            // Stok, Stok Kedaluwarsa) butuh kotak ini dicentang secara eksplisit (User::hasExplicitMenuAccess).
            'Laporan Penjualan' => [
                'SalesSummaryReport' => 'Ringkasan Penjualan',
                'SalesByPeriodReport' => 'Penjualan Per Periode',
                'SalesByOutletReport' => 'Penjualan Outlet',
                'PaymentBookReport' => 'Buku Pembayaran',
                'RefundReport' => 'Laporan Refund',
                'VoidReport' => 'Laporan Pembatalan',
                'LayananReport' => 'Laporan Jasa',
                'ProductSalesReport' => 'Penjualan Produk',
            ],
            'Laporan Jasa & Operasional' => [
                'BookingServiceLevelReport' => 'Laporan SLA & Tidak Datang',
                'JobDurationReport' => 'Proses Order',
                'JobDurationByServiceReport' => 'Proses Produk',
                'ReservationReport' => 'Laporan Reservasi',
                'ReservationUtilizationReport' => 'Laporan Reservasi & Utilisasi',
                'BayZoneUtilizationReport' => 'Utilisasi Zona/Bay',
                'TechnicianServiceDurationReport' => 'Durasi Servis Installer',
                'TechnicianUtilizationReport' => 'Utilisasi Installer',
                'CapacityCalendar' => 'Kalender Kapasitas & Libur',
                'CustomerSatisfactionReport' => 'Kepuasan Pelanggan',
                'PeakSalesTimeReport' => 'Waktu Teramai Penjualan',
                'PeakProductTimeReport' => 'Waktu Teramai Produk',
            ],
            'Laporan Persediaan' => [
                'ExpiringStockReport' => 'Stok Kedaluwarsa',
                'StockTurnoverReport' => 'Perputaran Stok',
                'PersediaanRingkasanReport' => 'Ringkasan Persediaan',
                'PersediaanDetailReport' => 'Detail Persediaan',
                'SerialNumberReport' => 'Serial Number',
            ],
            'Laporan Pelanggan & Promo' => [
                'CustomerReport' => 'Laporan Pelanggan',
                'PointReport' => 'Laporan Poin',
                'PromoLoyaltyReport' => 'Laporan Promo',
            ],
            'Penjualan' => [
                // Cluster "Produk" di bawah grup top-nav "Penjualan"
                // (2026-09-10). FilmProductResource dipindah kemari dari
                // "Master Data". Halaman-halaman Laporan di cluster
                // "Laporan"/"Analisa Laporan" TIDAK didaftarkan di sini
                // (pola sama seperti report page lain — akses lewat
                // canAccess() + menu_access null = boleh).
                // Dashboard Penjualan = permission SENDIRI sejak 2026-09-10
                // (dulu ikut BookingResource) — angka omzet data manajemen.
                'SalesDashboard' => 'Dashboard Penjualan',
                'FilmProductResource' => 'Daftar Produk',
                'MasterResepResource' => 'Master Resep',
                // Sama kelupaan dgn Invoice/SPK di grup 'Booking' —
                // SalesResource ('Detail Penjualan') sudah pakai
                // hasMenuAccess() di kode tapi belum pernah masuk daftar
                // ini, ditambahkan 2026-09-23 (audit Majoo f64, susulan).
                'SalesResource' => 'Detail Penjualan',
            ],
            // Cluster "Inventori" (dulu "Inventaris") sekarang di bawah
            // grup top-nav "Penjualan" (2026-09-10, ikut struktur Majoo)
            // — tetap section sendiri di sini karena isinya banyak.
            'Inventori' => [
                'InventoryDashboard' => 'Dashboard Inventaris',
                'InventoryItemResource' => 'Produk PPF/WF',
                'InventoryMovementResource' => 'Riwayat Keluar/Masuk',
                'RawMaterialResource' => 'Daftar Bahan Baku',
                'RawMaterialMovementResource' => 'Riwayat Bahan Baku',
                'AssetResource' => 'Aset Tetap',
                'ConsumableItemResource' => 'Barang Habis Pakai',
                'ConsumableItemMovementResource' => 'Riwayat Barang Habis Pakai',
                'MaterialMemoResource' => 'Memo Pengambilan/Pengembalian',
                'PurchaseRequestResource' => 'Permohonan Pembelian',
                'StockCardReport' => 'Daftar Stok (Kartu Stok)',
                'StockWriteOffResource' => 'Stok Terbuang',
                // Sama kelupaan dgn Invoice/SPK/Sales — StockOpnameResource
                // sudah pakai hasMenuAccess() lewat canAccess() tapi belum
                // pernah masuk daftar ini, ditambahkan 2026-09-23 (audit
                // Majoo f64, susulan).
                'StockOpnameResource' => 'Stok Opname',
            ],
            'Karyawan' => [
                // Dipindah ke klaster Karyawan (2026-11-11): Installer, Laporan Absensi, dan Jadwal Kerja Karyawan.
                'TechnicianResource' => 'Installer',
                'AttendanceReport' => 'Laporan Absensi',
                'WorkScheduleCalendarReport' => 'Jadwal Kerja Karyawan',
                'EmployeeTypeResource' => 'Tipe Karyawan',
                'MyAttendance' => 'Absen Saya',
                'AttendanceResource' => 'Absensi Karyawan',
                'AttendanceCorrectionRequestResource' => 'Koreksi Absensi',
                'LeaveRequestResource' => 'Izin & Cuti',
                'OvertimeRequestResource' => 'Pengajuan Lembur',
                'LeaveTypeResource' => 'Jenis Izin & Cuti',
                'CompanyPolicyResource' => 'Peraturan & Kebijakan',
                'EmployeeChecklistItemResource' => 'Checklist Masuk & Keluar',
                'ChecklistTemplateResource' => 'Template Checklist',
                'WarningLetterResource' => 'Surat Peringatan',
                'ContractExtensionResource' => 'Riwayat Kontrak',
                // Modul Jadwal Kerja (audit Majoo, 2026-09-22).
                // WorkScheduleCalendarReport (Page) SENGAJA tidak
                // dimasukkan di sini -- pola sama dgn report Page lain,
                // akses lewat canAccess()+menu_access null = boleh.
                'ShiftResource' => 'Daftar Shift',
                'WorkScheduleResource' => 'Daftar Jadwal Kerja',
                // PayrollResource dibuka 2026-09-24 (keputusan user) —
                // TAPI beda dari resource lain di grup ini, akses hanya
                // efektif utk role spv_finance (lihat
                // PayrollResource::canViewAny()), centang di sini TIDAK
                // cukup utk role lain.
                'PayrollResource' => 'Payroll',
                'AttendanceDeductionRuleResource' => 'Aturan Potongan Absensi',
                'PayrollItemCategoryResource' => 'Kategori Komponen Gaji',
                'SalaryGradeResource' => 'Struktur & Skala Upah',
                'EmployeeDataCompletenessReport' => 'Kelengkapan Data Karyawan',
                'InstallerCompetencyReport' => 'Kompetensi Installer',
                'PpeTypeResource' => 'Jenis APD',
                'PpeIssuanceResource' => 'Pembagian APD',
                'EmployeeTrainingResource' => 'Pelatihan Karyawan',
                'SafetyIncidentResource' => 'Kejadian K3',
                'PerformanceReviewResource' => 'Penilaian Kinerja',
                'HrDashboard' => 'Dasbor HR',
            ],
            // Saklar FEATURE_FINANCE mati: menunya tidak tampil di panel, tapi kuncinya TETAP di daftar supaya menu_access user yang
            // memuatnya tidak ikut terhapus saat disimpan (menu_access kosong = akses penuh).
            (config('features.finance') ? 'Keuangan' : 'Keuangan (modul nonaktif)') => [
                'FinanceCategoryResource' => 'Kategori Keuangan',
                'FinanceTransactionResource' => 'Transaksi Keuangan',
                'FinanceReport' => 'Laporan Keuangan',
                'TrialBalanceReport' => 'Neraca Saldo',
                'LedgerReconciliationReport' => 'Rekonsiliasi Sub-Buku',
                'PaymentAccountResource' => 'Rekening Kas & Bank',
                'CashTransferResource' => 'Pemindahan Kas',
                'CashBalanceReport' => 'Saldo Kas & Bank',
                'CashCountResource' => 'Hitung Kas Harian',
                'BudgetResource' => 'Anggaran',
                'BudgetVsActualReport' => 'Anggaran vs Realisasi',
                'IncomeStatementReport' => 'Laporan Laba Rugi',
                'GeneralLedgerReport' => 'Buku Besar',
                'BalanceSheetReport' => 'Neraca',
                'CashFlowReport' => 'Laporan Arus Kas',
                'PayableResource' => 'Hutang Usaha',
                'SupplierResource' => 'Supplier',
                'RecurringBillTemplateResource' => 'Template Tagihan Rutin',
                'ReceivableResource' => 'Piutang Usaha',
                'FinanceDashboard' => 'Dasbor Keuangan',
                'MonthlyIncomeStatementReport' => 'Laba Rugi Bulanan',
                'PayableAgingReport' => 'Umur Hutang',
                'ReceivableAgingReport' => 'Umur Piutang',
                // ChartOfAccountResource, JournalEntryResource &
                // BankStatementLineResource dibuka 2026-09-24 (keputusan
                // user) — TAPI beda dari resource lain di grup ini, akses
                // hanya efektif utk role spv_finance (lihat canViewAny()
                // masing-masing resource), centang di sini TIDAK cukup
                // utk role lain (mis. store_manager tetap tidak bisa
                // meski dicentang, karena bukan spv_finance).
                'ChartOfAccountResource' => 'Bagan Akun',
                'JournalEntryResource' => 'Jurnal Umum',
                'BankStatementLineResource' => 'Rekonsiliasi Bank',
            ],
            // Cluster "Pelanggan" (Penjualan > Pelanggan) — CustomerResource
            // dipindah kemari dari Marketing/Konten (2026-09-10).
            'Pelanggan' => [
                'CustomerResource' => 'Daftar Pelanggan',
                'CustomerGroupResource' => 'Grup Pelanggan',
            ],
            // Cluster "Promosi" (Penjualan > Promosi) — Voucher/Reward/
            // Klaim Reward dipindah kemari dari Marketing/Konten (2026-09-10).
            'Promosi' => [
                'SpendPromoResource' => 'Promo Total Pembelian',
                'RewardResource' => 'Katalog Reward',
                'RewardRedemptionResource' => 'Klaim Reward',
            ],
            'Marketing/Konten' => [
                'PointTransactionResource' => 'Riwayat Poin Pelanggan',
                'PartnershipInquiryResource' => 'Pengajuan Kemitraan',
                'ProductInquiryResource' => 'Inquiry Produk',
                'LeadFunnelReport' => 'Dasbor Lead',
                'ProductClaimResource' => 'Klaim & Bukti Produk',
                'CaseStudyResource' => 'Galeri Pemasangan',
                'MarketingConsentResource' => 'Izin Foto Pelanggan',
                'CustomerGalleryPhotoResource' => 'Galeri Pelanggan',
                'NewsResource' => 'News / Berita',
                'CarouselResource' => 'Banner / Carousel',
                'FeaturedProductResource' => 'Seri Produk (Beranda)',
                'JobOpeningResource' => 'Lowongan Kerja',
                'MaterialResource' => 'Materi Download',
                'MaterialCategoryResource' => 'Kategori Materi',
                'PartnerResource' => 'Partner',
                'PartnerPointTransactionResource' => 'Riwayat Poin Partner',
            ],
            'Master Data' => [
                // FilmProductResource dipindah ke grup 'Penjualan' di
                // atas (2026-09-10) — sekarang di cluster Produk.
                'VehicleResource' => 'Kendaraan',
                'StoreResource' => 'Toko/Dealer',
                'VehicleGlassAreaResource' => 'Luas Kaca',
                'MasterDataCenter' => 'Pusat Master Data',
                'ScrollCodeResource' => 'Kode Gulungan',
                // PriceRuleResource ('Koefisien Harga') SENGAJA tidak di
                // sini — resource-nya hidden (shouldRegisterNavigation
                // false): model base_price × koefisien ditinggalkan
                // 2026-09-10, diganti matriks harga (film_product_prices).
            ],
            'Sistem' => [
                'TransactionApprovalRequestResource' => 'Persetujuan Transaksi',
                'FinanceTransactionApprovalRequestResource' => 'Persetujuan Pengeluaran',
                // Hak lihat + ekspor Histori Aktivitas untuk staf non-full-access (mis. Spv Finance / Spv IT), keputusan 2026-10-21.
                'ActivityResource' => 'Riwayat Aktivitas',
                'CustomerNotificationResource' => 'Riwayat Notifikasi',
                'PartnerNotificationResource' => 'Riwayat Notifikasi Partner',
                'SendNotification' => 'Kirim Notifikasi',
                'NotificationDeliveryResource' => 'Log Pengiriman',
                'NotificationCampaignResource' => 'Kampanye Notifikasi',
                'NotificationTemplateResource' => 'Template Notifikasi',
                'NotificationCatalogPage' => 'Katalog Notifikasi',
            ],
        ];
    }

    /**
     * "Akses Menu" cuma relevan untuk role staff biasa (store_manager,
     * role divisi mana pun) — super_admin/direksi selalu full access,
     * installer/partner tidak login ke Filament sama sekali. Dicek dengan
     * "role apa pun SELAIN full-access & NO_PANEL_ROLES" supaya role baru
     * yang dibuat lewat RoleResource otomatis kebagian section ini juga.
     */
    private static function isRestrictableStaffSelected(Forms\Get $get): bool
    {
        $roleIds = $get('roles') ?? [];
        if (empty($roleIds)) {
            return false;
        }

        $excluded = array_merge(['super_admin', 'direksi'], \App\Models\User::NO_PANEL_ROLES);

        return \Spatie\Permission\Models\Role::whereIn('id', $roleIds)
            ->whereNotIn('name', $excluded)
            ->exists();
    }

    /**
     * Pilihan Jabatan di form User: id => nama yang mudah dibaca; jabatan bawaan yang membawa wewenang diberi tanda. "partner" tidak
     * ditawarkan (dikelola di menu Partner).
     *
     * @return array<int, string>
     */
    public static function roleOptions(): array
    {
        return \Spatie\Permission\Models\Role::where('name', '!=', 'partner')->orderBy('name')->get()
            ->mapWithKeys(fn ($role) => [
                $role->id => (string) str($role->name)->replace('_', ' ')->title() . (isset(RoleResource::DESCRIPTIONS[$role->name]) ? ' (wewenang bawaan)' : ''),
            ])
            ->all();
    }

    /**
     * Toggle per MODUL (bukan lagi 1 CheckboxList per grup) — diubah
     * 2026-09-23 supaya "Akses Menu" & "Hak Akses Detail" bisa digabung
     * jadi 1 tampilan (centang modul -> langsung muncul checklist CRUD-
     * nya di bawah, lihat form() di bawah), bukan 2 section terpisah yang
     * membingungkan. Tiap modul punya field boolean SEMENTARA sendiri
     * (mis. 'menu_access_bookingresource'), digabung jadi array
     * `menu_access` beneran lewat mergeMenuAccessFields() di
     * CreateUser/EditUser, dipecah balik lewat splitMenuAccessIntoFields().
     */
    public static function moduleAccessFieldKey(string $moduleKey): string
    {
        return 'menu_access_' . Str::slug($moduleKey, '_');
    }

    /**
     * Satu-satunya modul yang dibuka pada mode "Hanya aplikasi": karyawan yang tidak memerlukan panel (HP tidak bisa menjalankan aplikasi,
     * mis. iPhone, tetap bisa absen lewat Absen Saya). Disimpan sebagai daftar modul biasa, jadi tidak ada arti baru pada menu_access.
     */
    public const APP_ONLY_MODULE = 'MyAttendance';

    public static function mergeMenuAccessFields(array $data): array
    {
        // Pilihan "Akses penuh / Dibatasi / Hanya aplikasi" hanya untuk tampilan form; yang disimpan tetap hasil kotak modul
        // (kosong = NULL = akses penuh). Hanya aplikasi = hanya modul Absen Saya, tanpa hak tambahan.
        $mode = $data['access_mode'] ?? null;
        unset($data['access_mode']);

        if ($mode === 'app_only') {
            foreach (array_keys($data) as $key) {
                if (str_starts_with($key, 'menu_permissions_') || str_starts_with($key, 'menu_access_')) {
                    unset($data[$key]);
                }
            }
            $data[self::moduleAccessFieldKey(self::APP_ONLY_MODULE)] = true;
        }

        $merged = [];
        foreach (self::menuAccessOptions() as $options) {
            foreach (array_keys($options) as $moduleKey) {
                $key = self::moduleAccessFieldKey($moduleKey);
                $checked = (bool) ($data[$key] ?? false);
                unset($data[$key]);

                if ($checked) {
                    $merged[] = $moduleKey;
                }
            }
        }

        // Tidak ada yang dicentang sama sekali = NULL (akses penuh) —
        // sesuai teks bantuan di form ("kosongkan supaya akses penuh").
        // Cuma kalau ADA yang dicentang, baru dibatasi ke daftar itu saja.
        $data['menu_access'] = empty($merged) ? null : $merged;

        return $data;
    }

    public static function splitMenuAccessIntoFields(array $data): array
    {
        $selected = $data['menu_access'] ?? [];

        // Mode akses di form: kosong = akses penuh (arti menu_access NULL); hanya Absen Saya tanpa hak tambahan = hanya aplikasi;
        // selain itu dibatasi ke modul terpilih.
        $data['access_mode'] = match (true) {
            empty($selected) => 'full',
            $selected === [self::APP_ONLY_MODULE] && empty($data['menu_permissions']) => 'app_only',
            default => 'limited',
        };

        foreach (self::menuAccessOptions() as $options) {
            foreach (array_keys($options) as $moduleKey) {
                $data[self::moduleAccessFieldKey($moduleKey)] = in_array($moduleKey, $selected, true);
            }
        }

        return $data;
    }

    /**
     * Aksi granular yang bisa diatur per modul di "Hak Akses Detail"
     * (audit Majoo f64, 2026-09-23) — lihat User::hasModuleAction().
     * 'void' cuma relevan utk modul yang benar-benar punya konsep
     * pembatalan (Booking dkk), tapi SENGAJA ditawarkan di semua modul
     * (bukan whitelist per-modul) supaya daftar modul di sini tetap 1
     * sumber kebenaran (menuAccessOptions()) — mencentang Void di modul
     * yang tidak punya aksi Void sama sekali cuma tidak berefek apa-apa,
     * bukan bug.
     */
    private const MODULE_ACTIONS = [
        'view' => 'Lihat',
        'create' => 'Buat',
        'update' => 'Ubah',
        'delete' => 'Hapus',
        'void' => 'Void',
    ];

    /**
     * Sama pola dgn moduleAccessFieldKey() — field sementara per MODUL,
     * digabung balik jadi 1 kolom
     * `menu_permissions` lewat mergeMenuPermissionFields().
     */
    public static function menuPermissionFieldKey(string $moduleKey): string
    {
        return 'menu_permissions_' . Str::slug($moduleKey, '_');
    }

    public static function mergeMenuPermissionFields(array $data): array
    {
        $merged = [];
        foreach (self::menuAccessOptions() as $options) {
            foreach (array_keys($options) as $moduleKey) {
                $key = self::menuPermissionFieldKey($moduleKey);
                $actions = $data[$key] ?? [];
                unset($data[$key]);

                if (! empty($actions)) {
                    $merged[$moduleKey] = array_values($actions);
                }
            }
        }

        // Kosong total = NULL (belum pernah diatur granular sama sekali,
        // lihat default per-aksi di User::hasModuleAction()) — BUKAN
        // array kosong, supaya beda makna dgn "modul ini sudah diatur
        // tapi sengaja tidak ada aksi yang diizinkan sama sekali".
        $data['menu_permissions'] = empty($merged) ? null : $merged;

        return $data;
    }

    public static function splitMenuPermissionsIntoFields(array $data): array
    {
        $selected = $data['menu_permissions'] ?? [];

        foreach (self::menuAccessOptions() as $options) {
            foreach (array_keys($options) as $moduleKey) {
                $key = self::menuPermissionFieldKey($moduleKey);
                $data[$key] = $selected[$moduleKey] ?? [];
            }
        }

        return $data;
    }

    /** Hanya modul ini yang benar-benar memakai aksi Void; di modul lain kotaknya tidak berefek, jadi tidak ditawarkan. */
    private const VOID_MODULES = ['BookingResource', 'WarningLetterResource'];

    /**
     * Pilihan aksi tambahan satu modul. Void yang sudah tersimpan di modul lain tetap ditawarkan (agar tidak hilang atau gagal validasi
     * saat form disimpan).
     *
     * @return array<string, string>
     */
    private static function moduleActionOptions(string $moduleKey, ?User $record = null): array
    {
        $actions = self::MODULE_ACTIONS;
        $savedVoid = in_array('void', $record?->menu_permissions[$moduleKey] ?? [], true);

        if (! in_array($moduleKey, self::VOID_MODULES, true) && ! $savedVoid) {
            unset($actions['void']);
        }

        return $actions;
    }

    /** Empat aksi dasar yang dicentang massal; Void sengaja tidak ikut karena hanya berlaku di dua modul. */
    private const BULK_ACTIONS = ['view', 'create', 'update', 'delete'];

    /**
     * Hasil pencentangan massal hak akses detail untuk modul yang menunya SUDAH menyala di satu tab.
     *
     * $action: 'all' mencentang keempat aksi; 'none' mengosongkannya (Void tetap); salah satu dari view/create/update/delete bekerja
     * sebagai sakelar: bila semua modul menyala sudah punya aksi itu, dilepas dari semuanya; bila belum, dicentang di semuanya.
     *
     * @param  array<string, list<string>>  $current  kunci modul => aksi yang sedang tercentang (hanya modul yang menyala)
     * @return array<string, list<string>>
     */
    public static function bulkPermissionChange(array $current, string $action): array
    {
        $result = [];

        if ($action === 'all') {
            foreach ($current as $moduleKey => $actions) {
                $result[$moduleKey] = array_values(array_unique(array_merge($actions, self::BULK_ACTIONS)));
            }

            return $result;
        }

        if ($action === 'none') {
            foreach ($current as $moduleKey => $actions) {
                $result[$moduleKey] = array_values(array_diff($actions, self::BULK_ACTIONS));
            }

            return $result;
        }

        $allHave = $current !== [] && collect($current)->every(fn (array $actions) => in_array($action, $actions, true));

        foreach ($current as $moduleKey => $actions) {
            $result[$moduleKey] = $allHave
                ? array_values(array_diff($actions, [$action]))
                : array_values(array_unique(array_merge($actions, [$action])));
        }

        return $result;
    }

    /** Terapkan pencentangan massal ke modul yang menyala pada satu tab. @param list<string> $keys */
    private static function applyBulkPermissions(Forms\Get $get, Forms\Set $set, array $keys, string $action): void
    {
        $current = [];

        foreach ($keys as $moduleKey) {
            if ($get(self::moduleAccessFieldKey($moduleKey))) {
                $current[$moduleKey] = array_values((array) ($get(self::menuPermissionFieldKey($moduleKey)) ?? []));
            }
        }

        foreach (self::bulkPermissionChange($current, $action) as $moduleKey => $actions) {
            $set(self::menuPermissionFieldKey($moduleKey), $actions);
        }
    }

    /** Semua kunci modul, termasuk yang menunya disembunyikan saklar fitur. */
    private static function allModuleKeys(): array
    {
        return collect(self::menuAccessOptions())->flatMap(fn (array $options) => array_keys($options))->values()->all();
    }

    /** Satu baris modul: sakelar akses di kiri; setelah menyala, kotak aksi tambahan muncul sejajar di kanan. */
    private static function moduleRow(string $label, string $moduleKey): Forms\Components\Grid
    {
        $toggleKey = self::moduleAccessFieldKey($moduleKey);

        return Forms\Components\Grid::make(['default' => 1, 'md' => 12])
            ->extraAttributes(['style' => 'padding: .4rem 0; border-bottom: 1px solid rgba(107, 114, 128, .2)'])
            ->schema([
                Forms\Components\Toggle::make($toggleKey)
                    ->label($label)
                    ->live()
                    ->columnSpan(['md' => 5]),

                Forms\Components\CheckboxList::make(self::menuPermissionFieldKey($moduleKey))
                    ->hiddenLabel()
                    ->options(fn (?User $record) => self::moduleActionOptions($moduleKey, $record))
                    ->columns(['default' => 2, 'md' => 5])
                    ->visible(fn (Forms\Get $get) => (bool) $get($toggleKey))
                    ->columnSpan(['md' => 7]),
            ]);
    }

    /**
     * Tab per grup menu. Grup berawalan "Laporan " digabung jadi satu tab "Laporan" berisi sub-bagian. Tiap tab punya lencana jumlah modul
     * terpilih dan tombol Pilih semua / Kosongkan.
     */
    private static function accessTabs(): Forms\Components\Tabs
    {
        $tabs = [];
        foreach (self::visibleMenuAccessOptions() as $group => $options) {
            $tabs[str_starts_with($group, 'Laporan ') ? 'Laporan' : $group][$group] = $options;
        }

        $schema = [];
        foreach ($tabs as $tabName => $groups) {
            $keys = collect($groups)->flatMap(fn (array $options) => array_keys($options))->values()->all();
            $slug = Str::slug($tabName, '_');

            $content = [
                Forms\Components\Actions::make([
                    Forms\Components\Actions\Action::make("select_all_{$slug}")
                        ->label('Pilih semua di tab ini')
                        ->color('gray')
                        ->size('sm')
                        ->action(function (Forms\Set $set) use ($keys) {
                            foreach ($keys as $moduleKey) {
                                $set(self::moduleAccessFieldKey($moduleKey), true);
                            }
                        }),
                    Forms\Components\Actions\Action::make("clear_{$slug}")
                        ->label('Kosongkan tab ini')
                        ->color('gray')
                        ->size('sm')
                        ->action(function (Forms\Set $set) use ($keys) {
                            foreach ($keys as $moduleKey) {
                                $set(self::moduleAccessFieldKey($moduleKey), false);
                                $set(self::menuPermissionFieldKey($moduleKey), []);
                            }
                        }),
                ]),

                // Hak akses detail untuk semua modul yang SUDAH menyala di tab ini. Satu tombol Semua Lihat/Buat/Ubah/Hapus bekerja sebagai
                // sakelar (centang di semua, atau lepas bila sudah tercentang di semua).
                Forms\Components\Actions::make([
                    Forms\Components\Actions\Action::make("all_actions_{$slug}")
                        ->label('Centang semua aksi')
                        ->color('gray')
                        ->size('sm')
                        ->action(fn (Forms\Get $get, Forms\Set $set) => self::applyBulkPermissions($get, $set, $keys, 'all')),
                    Forms\Components\Actions\Action::make("all_view_{$slug}")
                        ->label('Semua Lihat')
                        ->color('gray')
                        ->size('sm')
                        ->action(fn (Forms\Get $get, Forms\Set $set) => self::applyBulkPermissions($get, $set, $keys, 'view')),
                    Forms\Components\Actions\Action::make("all_create_{$slug}")
                        ->label('Semua Buat')
                        ->color('gray')
                        ->size('sm')
                        ->action(fn (Forms\Get $get, Forms\Set $set) => self::applyBulkPermissions($get, $set, $keys, 'create')),
                    Forms\Components\Actions\Action::make("all_update_{$slug}")
                        ->label('Semua Ubah')
                        ->color('gray')
                        ->size('sm')
                        ->action(fn (Forms\Get $get, Forms\Set $set) => self::applyBulkPermissions($get, $set, $keys, 'update')),
                    Forms\Components\Actions\Action::make("all_delete_{$slug}")
                        ->label('Semua Hapus')
                        ->color('gray')
                        ->size('sm')
                        ->action(fn (Forms\Get $get, Forms\Set $set) => self::applyBulkPermissions($get, $set, $keys, 'delete')),
                    Forms\Components\Actions\Action::make("no_actions_{$slug}")
                        ->label('Kosongkan aksi')
                        ->color('gray')
                        ->size('sm')
                        ->action(fn (Forms\Get $get, Forms\Set $set) => self::applyBulkPermissions($get, $set, $keys, 'none')),
                ]),
            ];

            foreach ($groups as $group => $options) {
                $rows = collect($options)->map(fn (string $label, string $moduleKey) => self::moduleRow($label, $moduleKey))->values()->all();

                $content[] = $tabName === 'Laporan'
                    ? Forms\Components\Section::make(trim(substr($group, strlen('Laporan '))))->compact()->schema($rows)
                    : Forms\Components\Group::make($rows);
            }

            $schema[] = Forms\Components\Tabs\Tab::make($tabName)
                ->badge(fn (Forms\Get $get) => collect($keys)->filter(fn ($moduleKey) => (bool) $get(self::moduleAccessFieldKey($moduleKey)))->count() ?: null)
                ->schema($content);
        }

        return Forms\Components\Tabs::make('Modul')
            ->tabs($schema)
            ->columnSpanFull()
            ->visible(fn (Forms\Get $get) => $get('access_mode') === 'limited')
            // Tab tersembunyi saat Akses penuh; kotak yang tadinya tercentang tetap ikut disimpan (pindah ke Akses penuh sudah mengosongkannya).
            ->dehydratedWhenHidden();
    }

    /** Pilihan "Akses penuh / Dibatasi" beserta ringkasan jumlah modul yang dipilih. */
    private static function accessModeFields(): array
    {
        return [
            Forms\Components\Radio::make('access_mode')
                ->label('Akses menu')
                ->options([
                    'full' => 'Akses penuh',
                    'limited' => 'Dibatasi ke modul tertentu',
                    'app_only' => 'Hanya aplikasi (panel: Absen Saya saja)',
                ])
                ->descriptions([
                    'full' => 'Dapat membuka semua menu yang tersedia untuk jabatannya. Paling aman bila belum yakin.',
                    'limited' => 'Hanya modul yang dipilih di tab di bawah yang tampil.',
                    'app_only' => 'Untuk karyawan yang tidak perlu panel. Absen, izin, dan slip gaji lewat aplikasi karyawan; di panel hanya halaman Absen Saya.',
                ])
                ->default('full')
                ->live()
                ->columnSpanFull()
                ->afterStateUpdated(function (?string $state, Forms\Set $set) {
                    // Akses penuh tidak punya daftar modul, dan Hanya aplikasi hanya punya Absen Saya: kotak yang tadi dicentang dikosongkan
                    // supaya tidak ikut tersimpan.
                    if (! in_array($state, ['full', 'app_only'], true)) {
                        return;
                    }

                    foreach (self::allModuleKeys() as $moduleKey) {
                        $set(self::moduleAccessFieldKey($moduleKey), false);
                        $set(self::menuPermissionFieldKey($moduleKey), []);
                    }

                    if ($state === 'app_only') {
                        $set(self::moduleAccessFieldKey(self::APP_ONLY_MODULE), true);
                    }
                })
                ->rules([
                    fn (Forms\Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                        if ($value !== 'limited') {
                            return;
                        }

                        foreach (self::allModuleKeys() as $moduleKey) {
                            if ($get(self::moduleAccessFieldKey($moduleKey))) {
                                return;
                            }
                        }

                        $fail('Pilih minimal satu modul, atau ubah ke Akses penuh.');
                    },
                ]),

            Forms\Components\Placeholder::make('access_summary')
                ->hiddenLabel()
                ->columnSpanFull()
                ->content(function (Forms\Get $get) {
                    if (($get('access_mode') ?? 'full') === 'app_only') {
                        return 'Hanya aplikasi: karyawan ini memakai aplikasi karyawan; di panel hanya halaman Absen Saya yang bisa dibuka.';
                    }

                    if (($get('access_mode') ?? 'full') !== 'limited') {
                        return 'Akses penuh: tidak ada pembatasan modul.';
                    }

                    $chosen = collect(self::allModuleKeys())->filter(fn ($moduleKey) => (bool) $get(self::moduleAccessFieldKey($moduleKey)))->count();

                    return $chosen === 0
                        ? 'Belum ada modul dipilih. Centang minimal satu modul di tab di bawah.'
                        : "{$chosen} modul dipilih; menu lainnya tidak tampil untuk karyawan ini.";
                }),
        ];
    }

    /**
     * Nilai field form "Akses Menu & Hak Akses Detail" milik $source (kotak modul + kotak Lihat/Buat/Ubah/Hapus/Void),
     * dalam bentuk field sementara yang dipakai form ini. Role dan Toko SENGAJA tidak ikut: itu identitas akun, bukan akses.
     * Sumber dengan menu_access kosong (akses penuh) menghasilkan semua kotak mati, sama dengan arti "kosongkan = akses penuh".
     *
     * @return array<string, mixed>
     */
    public static function accessFormFieldsFor(User $source): array
    {
        $fields = self::splitMenuPermissionsIntoFields(self::splitMenuAccessIntoFields([
            'menu_access' => $source->menu_access,
            'menu_permissions' => $source->menu_permissions,
        ]));

        unset($fields['menu_access'], $fields['menu_permissions']);

        return $fields;
    }

    /** Password acak 10 karakter yang pasti memuat huruf besar, huruf kecil, dan angka (tanpa huruf/angka yang mudah tertukar). */
    public static function generatePassword(): string
    {
        $pick = fn (string $chars, int $n) => collect(range(1, $n))->map(fn () => $chars[random_int(0, strlen($chars) - 1)])->implode('');

        return str_shuffle($pick('ABCDEFGHJKLMNPQRSTUVWXYZ', 3) . $pick('abcdefghijkmnpqrstuvwxyz', 4) . $pick('23456789', 3));
    }

    /**
     * Modul yang menunya sedang disembunyikan saklar fitur (config/features.php). Kuncinya TETAP ada di menuAccessOptions()
     * supaya izin yang sudah tersimpan tidak hilang saat form disimpan, tapi kotaknya tidak ditampilkan.
     *
     * @return list<string>
     */
    private static function disabledFeatureModuleKeys(): array
    {
        $keys = [];

        foreach ([
            'finance' => ['FinanceCategoryResource', 'FinanceTransactionResource', 'FinanceReport', 'TrialBalanceReport', 'LedgerReconciliationReport',
                'PaymentAccountResource', 'CashTransferResource', 'CashBalanceReport', 'CashCountResource', 'BudgetResource', 'BudgetVsActualReport',
                'IncomeStatementReport', 'GeneralLedgerReport', 'BalanceSheetReport', 'CashFlowReport', 'PayableResource',
                'RecurringBillTemplateResource', 'ReceivableResource', 'ChartOfAccountResource', 'JournalEntryResource', 'BankStatementLineResource',
                'FinanceDashboard', 'MonthlyIncomeStatementReport', 'PayableAgingReport', 'ReceivableAgingReport', 'FinanceTransactionApprovalRequestResource'],
            'k3' => ['PpeTypeResource', 'PpeIssuanceResource', 'SafetyIncidentResource'],
            'employee_training' => ['EmployeeTrainingResource'],
            'performance_reviews' => ['PerformanceReviewResource'],
            'salary_grades' => ['SalaryGradeResource'],
            'master_resep' => ['MasterResepResource'],
            'report_jenis_order' => ['JenisOrderReport'],
            'report_voucher' => ['CouponReport'],
            'report_persediaan_detail' => ['PersediaanDetailReport'],
            'report_peak_time' => ['PeakSalesTimeReport', 'PeakProductTimeReport'],
            'employee_checklists' => ['EmployeeChecklistItemResource', 'ChecklistTemplateResource'],
            'work_schedules' => ['ShiftResource', 'WorkScheduleResource'],
        ] as $feature => $moduleKeys) {
            if (! config("features.{$feature}")) {
                array_push($keys, ...$moduleKeys);
            }
        }

        return $keys;
    }

    /** menuAccessOptions() tanpa modul yang fiturnya dimatikan (dan tanpa grup yang jadi kosong). */
    private static function visibleMenuAccessOptions(): array
    {
        $hidden = self::disabledFeatureModuleKeys();

        return collect(self::menuAccessOptions())
            ->map(fn (array $options) => array_diff_key($options, array_flip($hidden)))
            ->filter()
            ->all();
    }

    /**
     * Kotak modul yang disembunyikan tetap ikut di state form (Hidden), jadi nilai tersimpannya dibaca dan ditulis balik apa adanya.
     *
     * @return array<int, Forms\Components\Hidden>
     */
    private static function hiddenAccessFields(): array
    {
        $fields = [];

        foreach (self::disabledFeatureModuleKeys() as $moduleKey) {
            $fields[] = Forms\Components\Hidden::make(self::moduleAccessFieldKey($moduleKey));
            $fields[] = Forms\Components\Hidden::make(self::menuPermissionFieldKey($moduleKey));
        }

        return $fields;
    }

    /** Pilihan "Salin akses dari karyawan lain" (hanya akses penuh): mengisi kotak modul dan hak detail sekaligus, belum disimpan. */
    private static function copyAccessField(): Forms\Components\Select
    {
        return Forms\Components\Select::make('copy_access_from')
            ->label('Salin akses dari karyawan lain')
            ->placeholder('Pilih karyawan…')
            ->options(fn (?User $record) => User::query()
                ->where('is_active', true)
                ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'partner'))
                ->orderBy('name')
                ->pluck('name', 'id'))
            ->searchable()
            ->live()
            ->dehydrated(false)
            ->columnSpanFull()
            ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
            ->helperText('Mengisi kotak modul dan hak detail di bawah sesuai karyawan yang dipilih, lalu tinggal diubah seperlunya. Jabatan dan Toko tidak ikut disalin. Baru berlaku setelah Anda menekan Simpan.')
            ->afterStateUpdated(function ($state, Forms\Set $set) {
                $source = $state ? User::find($state) : null;

                if (! $source) {
                    return;
                }

                foreach (self::accessFormFieldsFor($source) as $key => $value) {
                    $set($key, $value);
                }
                $set('copy_access_from', null);

                \Filament\Notifications\Notification::make()
                    ->title(empty($source->menu_access)
                        ? "{$source->name} berakses penuh, jadi semua kotak dikosongkan"
                        : "Akses {$source->name} disalin ke form")
                    ->body('Periksa lagi, lalu tekan Simpan.')
                    ->success()
                    ->send();
            });
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Akun & Login')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Nama')
                        ->required()
                        ->maxLength(255),

                    // Email boleh kosong bila No. HP diisi (karyawan masuk dengan No. HP); salah satunya wajib supaya akun punya cara masuk.
                    Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required(fn (\Filament\Forms\Get $get) => blank($get('phone')))
                        ->live(onBlur: true)
                        ->unique(ignoreRecord: true)
                        ->maxLength(255)
                        ->helperText('Boleh dikosongkan bila No. HP diisi. Tanpa email, lupa password diatur ulang oleh admin.'),

                    Forms\Components\TextInput::make('phone')
                        ->label('No. HP')
                        ->tel()
                        ->required(fn (\Filament\Forms\Get $get) => blank($get('email')))
                        ->live(onBlur: true)
                        ->rule(fn (?User $record) => function (string $attribute, $value, \Closure $fail) use ($record) {
                            $digits = \App\Support\PhoneNumber::digits($value);

                            if (! $digits) {
                                $fail('No. HP tidak valid (9 sampai 20 angka).');

                                return;
                            }

                            $taken = User::where('phone_digits', $digits)->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))->exists();

                            if ($taken) {
                                $fail('No. HP ini sudah dipakai karyawan lain.');
                            }
                        })
                        ->maxLength(255)
                        ->helperText('Bisa dipakai untuk masuk (panel dan aplikasi) selain email. Ditulis 0812…, 62812…, atau +62812… dianggap sama.'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Akun Aktif')
                        ->default(true)
                        ->helperText('Matikan untuk mencabut akses login (panel & mobile app) tanpa menghapus akun & riwayatnya — dipakai saat karyawan resign/cuti panjang. Beda dari Hapus: data absensi/gaji/SP tetap tersimpan utuh.')
                        // Karyawan tidak boleh menonaktifkan akunnya
                        // sendiri lewat form ini — kalau butuh, super
                        // admin lain yang harus melakukannya.
                        ->disabled(fn (?User $record) => $record && auth()->id() === $record->id)
                        ->dehydrated(),

                    Forms\Components\Select::make('roles')
                        ->label('Jabatan')
                        ->helperText('Jabatan karyawan. Store Manager, Spv Finance, Installer Leader, Installer, Direksi, dan Super Admin (bertanda "wewenang bawaan") membawa wewenang tertentu; jabatan lain hanya nama, dan menu yang boleh dibuka diatur di Akses Menu di bawah.')
                        ->relationship('roles', 'name')
                        // "partner" sengaja tidak ditawarkan di sini — akun
                        // mitra referral dibuat & dikelola lewat menu Partner
                        // (PartnerResource), bukan dari menu User internal ini.
                        ->options(fn () => self::roleOptions())
                        ->multiple()
                        ->preload()
                        // Bikin role baru langsung dari sini kalau divisinya
                        // belum ada di daftar — tidak perlu ke halaman
                        // Role/Divisi terpisah dulu. Guard 'web' otomatis
                        // ke-set sama seperti role lain (lihat RoleResource).
                        ->createOptionForm([
                            Forms\Components\TextInput::make('name')
                                ->label('Nama Jabatan Baru')
                                ->required()
                                ->unique(table: 'roles', column: 'name')
                                ->regex('/^[a-z_]+$/')
                                ->validationMessages([
                                    'regex' => 'Huruf kecil & underscore saja, contoh: warehouse_staff.',
                                    'unique' => 'Nama jabatan ini sudah digunakan, pakai nama lain.',
                                ])
                                ->helperText('Contoh: hrd, warehouse_staff, sales_admin. Jabatan baru hanya nama; tidak membawa wewenang bawaan.'),
                            Forms\Components\Hidden::make('guard_name')->default('web'),
                        ])
                        // Jalur cepat ini TIDAK lewat halaman CreateRole sama
                        // sekali (Role::create() langsung), jadi
                        // CreateRole::afterCreate() yang mencatat Activity
                        // Log tidak pernah terpicu untuk role yang dibuat
                        // dari sini — dicatat manual di closure ini supaya
                        // konsisten dengan role yang dibuat lewat menu
                        // Role/Divisi. Ditemukan dari testing live checklist
                        // Role/Divisi.
                        ->createOptionUsing(function (array $data) {
                            $role = \Spatie\Permission\Models\Role::create($data);

                            activity('role')
                                ->causedBy(auth()->user())
                                ->performedOn($role)
                                ->log("Role \"{$role->name}\" dibuat");

                            return $role->getKey();
                        })
                        ->live()
                        ->required()
                        ->helperText('super_admin/direksi: akses penuh. store_manager & installer: wajib pilih Toko di bawah (installer tidak bisa login ke panel ini, hanya via mobile app).'),

                    Forms\Components\Select::make('store_id')
                        ->label('Toko (khusus jabatan yang terikat 1 toko)')
                        ->relationship('store', 'name')
                        ->searchable()
                        ->preload()
                        ->live()
                        ->required(function (Forms\Get $get): bool {
                            $roleIds = $get('roles') ?? [];
                            if (empty($roleIds)) return false;
                            return \Spatie\Permission\Models\Role::whereIn('id', $roleIds)
                                ->whereIn('name', ['installer', 'store_manager'])
                                ->exists();
                        })
                        // Sisi "wajib diisi" untuk installer/store_manager
                        // sudah ada di atas — ini sisi sebaliknya yang
                        // sebelumnya tidak ada sama sekali: mencegah akun
                        // role 'partner' (company-wide, bukan staff toko)
                        // tersimpan dengan store_id terisi, yang bisa bikin
                        // logic mobile API yang mengandalkan kombinasi
                        // role+store_id jadi rancu.
                        ->rules([
                            fn (Forms\Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {
                                if (! $value) return;
                                $roleIds = $get('roles') ?? [];
                                if (empty($roleIds)) return;
                                $isPartner = \Spatie\Permission\Models\Role::whereIn('id', $roleIds)
                                    ->where('name', 'partner')
                                    ->exists();
                                if ($isPartner) {
                                    $fail('Jabatan Partner tidak boleh terikat ke satu Toko — partner adalah akun bisnis company-wide, bukan karyawan toko. Kosongkan field ini.');
                                }
                            },
                        ])
                        ->helperText('Wajib diisi kalau role-nya installer atau store_manager — tanpa Toko, akun ini tidak akan bisa akses booking/chat toko manapun. Kosongkan untuk super_admin/direksi/partner/role company-wide lain.'),

                    // "Riwayat Karir" (audit Majoo f57) — field TRANSIEN,
                    // BUKAN kolom users (lihat User::$pendingTransferReason),
                    // cuma tampil kalau Toko baru saja diubah dari nilai
                    // semula, supaya alasan perpindahannya ikut tercatat
                    // di tab "Riwayat Karir" (lihat EditUser::mutateFormDataBeforeSave()).
                    Forms\Components\TextInput::make('transfer_reason')
                        ->label('Alasan Perpindahan Toko (opsional)')
                        ->maxLength(255)
                        ->visible(fn (Forms\Get $get, ?User $record) => $record !== null && (int) $get('store_id') !== (int) $record->store_id)
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('password')
                        ->label('Password')
                        ->password()
                        ->revealable()
                        ->required(fn (string $context): bool => $context === 'create')
                        ->dehydrated(fn ($state) => filled($state))
                        ->minLength(8)
                        // Gap diperbaiki 2026-09-26 (audit fitur User) --
                        // SEBELUMNYA cuma minLength(8), tidak ada syarat
                        // kompleksitas sama sekali. Akun di resource ini
                        // adalah fondasi otorisasi SELURUH sistem
                        // (termasuk super_admin) -- wajar diberi syarat
                        // lebih ketat daripada akun customer/partner biasa.
                        ->regex('/^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).+$/')
                        ->same('passwordConfirmation')
                        // Default Laravel untuk rule 'same' cuma separuh
                        // diterjemahkan Filament ("The password field must
                        // match konfirmasi Password" — dua bahasa
                        // tercampur), pesan sendiri di sini biar konsisten
                        // Indonesia semua. Ditemukan saat testing live
                        // checklist User, dilaporkan pengguna.
                        ->validationMessages([
                            'same' => 'Password dan Konfirmasi Password harus sama persis.',
                            'regex' => 'Password harus mengandung huruf besar, huruf kecil, dan angka.',
                        ])
                        ->live(debounce: 500)
                        ->helperText(fn (string $context) => $context === 'edit'
                            ? 'Kosongkan kalau tidak mau mengubah password.'
                            : 'Minimal 8 karakter, wajib ada huruf besar, huruf kecil, dan angka.'),

                    Forms\Components\TextInput::make('passwordConfirmation')
                        ->label('Konfirmasi Password')
                        ->password()
                        ->revealable()
                        ->required(fn (string $context): bool => $context === 'create')
                        ->dehydrated(false)
                        ->helperText('Ulangi password yang sama persis.'),
                ]),

            Forms\Components\Section::make('Kepegawaian')
                ->description('Dipakai Penggajian dan menu Karyawan. Semua boleh dikosongkan dulu dan dilengkapi bertahap.')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('employee_number')
                        ->label('No. Karyawan')
                        ->helperText('Nomor induk karyawan Ginnva. Semua akun di sini adalah karyawan (partner & pelanggan tidak termasuk).')
                        ->unique(ignoreRecord: true)
                        ->maxLength(50),

                    Forms\Components\DatePicker::make('join_date')
                        ->label('Tanggal Mulai Kerja')
                        ->maxDate(today()),

                    Forms\Components\DatePicker::make('attendance_start_date')
                        ->label('Mulai Wajib Absen')
                        ->helperText('Kosongkan bila absensi wajib sejak Tanggal Mulai Kerja. Isi bila absensinya baru diberlakukan belakangan: sebelum tanggal ini tidak ada Alpha otomatis, pengingat belum absen, atau potongan Alpha di Payroll.'),

                    Forms\Components\Select::make('employee_type_id')
                        ->label('Tipe Karyawan')
                        ->relationship('employeeType', 'name', fn ($query) => $query->where('is_active', true))
                        ->helperText('Kelola daftar tipe di menu "Tipe Karyawan". Tipe menentukan apakah "Tanggal Berakhir Kontrak" di bawah wajib diisi atau tidak.')
                        ->preload()
                        ->live(),

                    Forms\Components\DatePicker::make('contract_end_date')
                        ->label('Tanggal Berakhir Kontrak')
                        ->helperText('Isi di sini untuk kontrak PERTAMA kali. Perpanjangan berikutnya dicatat lewat menu "Perpanjang Kontrak" supaya riwayatnya tersimpan, bukan diedit langsung di sini.')
                        ->visible(fn (Forms\Get $get) => (bool) \App\Models\EmployeeType::find($get('employee_type_id'))?->has_end_date)
                        ->required(fn (Forms\Get $get) => (bool) \App\Models\EmployeeType::find($get('employee_type_id'))?->has_end_date),

                    Forms\Components\DatePicker::make('last_work_date')
                        ->label('Hari Kerja Terakhir')
                        ->helperText('Isi bila karyawan berhenti. Gaji bulan terakhirnya dihitung proporsional sampai tanggal ini dan Alpha sesudahnya tidak dicatat. Nonaktifkan akun setelah gaji terakhir dibayar; Generate Payroll tetap menyertakan karyawan nonaktif yang berhenti di bulan itu.'),

                    Forms\Components\DatePicker::make('probation_end_date')
                        ->label('Masa Percobaan Berakhir')
                        ->helperText('Kosongkan bila tidak ada masa percobaan. Maksimal 3 bulan untuk PKWTT; PKWT tidak boleh ada masa percobaan.'),

                    Forms\Components\TextInput::make('base_salary')
                        ->label('Gaji Pokok')
                        ->helperText('Dipakai sebagai dasar hitung Penggajian bulanan.')
                        ->numeric()
                        ->prefix('Rp')
                        ->minValue(0),

                    Forms\Components\Select::make('salary_grade_id')
                        ->label('Golongan Upah')
                        ->visible(fn () => (bool) config('features.salary_grades'))
                        ->relationship('salaryGrade', 'name', fn ($query) => $query->where('is_active', true))
                        ->helperText('Dari menu "Struktur & Skala Upah". Gaji pokok di luar rentang golongan hanya ditandai di Kelengkapan Data, tidak ditolak.')
                        ->preload(),
                ]),

            // "Profil karyawan HRIS lengkap" (audit Majoo f54), dibangun
            // 2026-09-22. TERBATAS full-access — data personalia formal
            // (NIK/NPWP/BPJS/rekening) adalah data sensitif, sama
            // filosofi dgn komisi/gaji pokok. Semua field opsional,
            // diisi bertahap.
            Forms\Components\Section::make('Data Personalia (HRIS)')
                ->description('Data formal untuk keperluan payroll & kepatuhan (BPJS/pajak) — opsional, diisi bertahap. Hanya bisa diubah full-access.')
                ->collapsed()
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('nik')
                        ->label('NIK (KTP)')
                        ->maxLength(20)
                        ->disabled(fn () => ! auth()->user()?->isFullAccess())
                        ->dehydrated(),

                    Forms\Components\TextInput::make('npwp')
                        ->label('NPWP')
                        ->maxLength(25)
                        ->disabled(fn () => ! auth()->user()?->isFullAccess())
                        ->dehydrated(),

                    Forms\Components\TextInput::make('bpjs_kesehatan_number')
                        ->label('No. BPJS Kesehatan')
                        ->maxLength(20)
                        ->disabled(fn () => ! auth()->user()?->isFullAccess())
                        ->dehydrated(),

                    Forms\Components\TextInput::make('bpjs_ketenagakerjaan_number')
                        ->label('No. BPJS Ketenagakerjaan')
                        ->maxLength(20)
                        ->disabled(fn () => ! auth()->user()?->isFullAccess())
                        ->dehydrated(),

                    Forms\Components\TextInput::make('emergency_contact_name')
                        ->label('Nama Kontak Darurat')
                        ->maxLength(255)
                        ->disabled(fn () => ! auth()->user()?->isFullAccess())
                        ->dehydrated(),

                    Forms\Components\TextInput::make('emergency_contact_phone')
                        ->label('No. HP Kontak Darurat')
                        ->tel()
                        ->maxLength(255)
                        ->disabled(fn () => ! auth()->user()?->isFullAccess())
                        ->dehydrated(),

                    Forms\Components\TextInput::make('emergency_contact_relationship')
                        ->label('Hubungan dgn Kontak Darurat')
                        ->placeholder('mis. Orang Tua, Pasangan, Saudara')
                        ->maxLength(255)
                        ->disabled(fn () => ! auth()->user()?->isFullAccess())
                        ->dehydrated(),

                    Forms\Components\TextInput::make('bank_name')
                        ->label('Nama Bank (Rekening Gaji)')
                        ->maxLength(255)
                        ->disabled(fn () => ! auth()->user()?->isFullAccess())
                        ->dehydrated(),

                    Forms\Components\TextInput::make('bank_account_number')
                        ->label('No. Rekening')
                        ->maxLength(255)
                        ->disabled(fn () => ! auth()->user()?->isFullAccess())
                        ->dehydrated(),

                    Forms\Components\TextInput::make('bank_account_holder_name')
                        ->label('Nama Pemilik Rekening')
                        ->maxLength(255)
                        ->disabled(fn () => ! auth()->user()?->isFullAccess())
                        ->dehydrated(),
                ]),

            // "Akses Menu" + "Hak Akses Detail" DIGABUNG jadi 1 tampilan
            // (keputusan user 2026-09-23) — SEBELUMNYA 2 section terpisah
            // (checklist ringkas "boleh lihat menu ini atau tidak", lalu
            // section kedua yang menampilkan checklist CRUD utk SEMUA ~40
            // modul sekaligus, kepanjangan & tidak fokus krn kebanyakan
            // modulnya belum tentu dicentang). Sekarang tiap modul jadi 1
            // Toggle; begitu dicentang, checklist Lihat/Buat/Ubah/Hapus/
            // Void modul itu langsung muncul persis di bawahnya
            // (->visible() baca state Toggle via Get, ->live() di Toggle
            // supaya reaktif).
            //
            // Checklist CRUD ini GRANT-ONLY (diubah 2026-09-23 dari model
            // allowlist-per-modul, lihat User::hasModuleAction()) — tiap
            // kotak MENAMBAH izin utk aksi itu spesifik, TIDAK PERNAH
            // mencabut/membatasi aksi lain yang tidak dicentang. Model
            // allowlist lama adalah jebakan: admin yang niatnya cuma mau
            // menambah izin Hapus, tapi lupa ikut mencentang Lihat/Buat/
            // Ubah, tanpa sadar mencabut ketiganya. Konsekuensinya:
            // checklist ini SEKARANG TIDAK BISA dipakai utk membatasi
            // aksi yang defaultnya sudah boleh (mis. "boleh lihat tapi
            // jangan boleh ubah") — kalau nanti perlu itu, butuh mekanisme
            // terpisah, bukan checklist yang sama.
            Forms\Components\Section::make('Akses Menu & Hak Akses Detail')
                ->description('Khusus karyawan panel (jabatan selain Super Admin, Direksi, Installer, dan Partner). Pilih Akses penuh, batasi ke modul tertentu lalu centang modulnya di tab, atau Hanya aplikasi bagi karyawan yang tidak perlu panel. Kotak Lihat/Buat/Ubah/Hapus di sebelah modul hanya MENAMBAH izin: Lihat, Buat, dan Ubah tetap jalan seperti biasa, sedangkan Hapus (dan Void) baru aktif bila dicentang. Menu yang fiturnya dimatikan tidak ditampilkan, izinnya yang sudah tersimpan tetap dijaga.')
                ->visible(fn (Forms\Get $get) => self::isRestrictableStaffSelected($get))
                ->schema(array_merge(
                    self::accessModeFields(),
                    [self::copyAccessField()],
                    self::hiddenAccessFields(),
                    [self::accessTabs()]
                )),
        ]);
    }
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('employee_number')
                    ->label('No. Karyawan')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label('No. HP')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Jabatan')
                    ->badge()
                    // Warna menandai jabatan bawaan yang membawa wewenang; jabatan bebas buatan admin abu-abu.
                    ->color(fn (string $state): string => match (true) {
                        in_array($state, ['super_admin', 'direksi'], true) => 'danger',
                        $state === 'store_manager' => 'warning',
                        isset(RoleResource::DESCRIPTIONS[$state]) => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->title()),

                Tables\Columns\TextColumn::make('store.name')
                    ->label('Toko')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('employeeType.name')
                    ->label('Tipe Karyawan')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                /**
                 * Gap standar enterprise diperbaiki 2026-09-27 (audit
                 * Perpanjang Kontrak) -- SEBELUMNYA tidak ada badge
                 * status kontrak (aktif/akan habis/expired) di mana
                 * pun, admin tidak bisa lihat sekilas "siapa saja
                 * kontraknya mau habis" dari daftar karyawan.
                 */
                Tables\Columns\TextColumn::make('contract_end_date')
                    ->label('Status Kontrak')
                    ->placeholder('—')
                    ->date('d M Y')
                    ->description(function (User $record) {
                        if (! $record->contract_end_date) {
                            return null;
                        }
                        $days = today()->diffInDays($record->contract_end_date, false);
                        if ($days < 0) return 'Sudah berakhir';
                        if ($days <= 30) return "{$days} hari lagi";

                        return null;
                    })
                    ->color(function (User $record) {
                        if (! $record->contract_end_date) {
                            return 'gray';
                        }
                        $days = today()->diffInDays($record->contract_end_date, false);
                        if ($days < 0) return 'danger';
                        if ($days <= 30) return 'warning';

                        return 'success';
                    })
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Status')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('roles')
                    ->label('Jabatan')
                    ->relationship('roles', 'name'),

                Tables\Filters\SelectFilter::make('store_id')
                    ->label('Toko')
                    ->options(fn () => Store::pluck('name', 'id')),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Status Akun')
                    ->trueLabel('Aktif')
                    ->falseLabel('Nonaktif'),

                Tables\Filters\Filter::make('tanpa_no_karyawan')
                    ->label('Belum ada No. Karyawan')
                    ->query(fn (Builder $query) => $query->whereNull('employee_number')),

                // Gap standar enterprise (audit Perpanjang Kontrak
                // 2026-09-27) -- filter dasar untuk "siapa saja kontraknya
                // akan/sudah habis", sebelumnya tidak ada sama sekali.
                Tables\Filters\SelectFilter::make('contract_status')
                    ->label('Status Kontrak')
                    ->options([
                        'expiring' => 'Akan Habis (30 hari)',
                        'expired'  => 'Sudah Berakhir',
                    ])
                    ->query(function (Builder $query, array $data) {
                        return match ($data['value'] ?? null) {
                            'expiring' => $query->whereNotNull('contract_end_date')
                                ->whereDate('contract_end_date', '>=', today())
                                ->whereDate('contract_end_date', '<=', today()->addDays(30)),
                            'expired' => $query->whereNotNull('contract_end_date')
                                ->whereDate('contract_end_date', '<', today()),
                            default => $query,
                        };
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                // Ganti utama untuk "keluarkan karyawan dari sistem" —
                // toggle is_active, TIDAK menyentuh baris/relasi apa pun,
                // jadi aman dipakai kapan saja tanpa risiko kehilangan
                // riwayat (beda dari Hapus di bawah).
                Tables\Actions\Action::make('toggleActive')
                    ->label(fn (User $record) => $record->is_active ? 'Nonaktifkan' : 'Aktifkan')
                    ->icon(fn (User $record) => $record->is_active ? 'heroicon-o-lock-closed' : 'heroicon-o-lock-open')
                    ->color(fn (User $record) => $record->is_active ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (User $record) => $record->is_active
                        ? "Akun \"{$record->name}\" tidak akan bisa login (panel & mobile app) sampai diaktifkan lagi. Semua riwayat absensi/cuti/gaji/SP tetap tersimpan."
                        : "Akun \"{$record->name}\" akan bisa login kembali.")
                    ->visible(fn (User $record) => auth()->id() !== $record->id)
                    ->action(function (User $record) {
                        $record->update(['is_active' => ! $record->is_active]);

                        \Filament\Notifications\Notification::make()
                            ->title($record->is_active ? 'Akun diaktifkan' : 'Akun dinonaktifkan')
                            ->success()
                            ->send();
                    }),

                // Admin mengatur ulang password karyawan yang lupa dan tidak bisa menerima kode di email. Sandi awal sudah terisi acak
                // dan memenuhi aturan; admin menyampaikannya langsung. Sesi aplikasi karyawan dicabut supaya perangkat lama harus login ulang.
                Tables\Actions\Action::make('resetPassword')
                    ->label('Atur Ulang Password')
                    ->icon('heroicon-o-key')
                    ->color('gray')
                    ->visible(fn (User $record) => (auth()->user()?->isFullAccess() ?? false) && auth()->id() !== $record->id)
                    ->modalHeading(fn (User $record) => "Atur ulang password {$record->name}")
                    ->modalDescription('Sampaikan password ini langsung ke karyawan. Semua sesi aplikasinya akan keluar dan ia perlu masuk lagi.')
                    ->modalSubmitActionLabel('Atur Ulang')
                    ->form([
                        Forms\Components\TextInput::make('password')
                            ->label('Password baru')
                            ->default(fn () => self::generatePassword())
                            ->required()
                            ->minLength(8)
                            ->regex('/^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).+$/')
                            ->validationMessages(['regex' => 'Password harus mengandung huruf besar, huruf kecil, dan angka.'])
                            ->helperText('Boleh diganti sendiri. Minimal 8 karakter, wajib ada huruf besar, huruf kecil, dan angka.'),
                    ])
                    ->action(function (User $record, array $data) {
                        $record->update(['password' => $data['password']]);
                        $record->revokeSessions();

                        \App\Support\AccountSecurityLog::passwordChanged($record, 'diubah oleh admin (panel)', auth()->user());

                        \Filament\Notifications\Notification::make()
                            ->title('Password diatur ulang')
                            ->body("Sampaikan password baru ke {$record->name}. Sesi aplikasinya sudah dikeluarkan.")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\DeleteAction::make()
                    ->modalDescription(fn (User $record) => $record->hasHrHistory()
                        ? 'Akun ini sudah punya riwayat HR (absensi/cuti/gaji/SP/kontrak) — tidak bisa dihapus permanen. Gunakan "Nonaktifkan" untuk mencabut akses tanpa kehilangan riwayat.'
                        : 'Yakin hapus akun ini? Tindakan ini tidak bisa dibatalkan.'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // Bulk hapus custom: setiap baris dicek hasHrHistory()
                    // & error FK per-baris ditangkap satu-satu (pola sama
                    // dengan VehicleResource/FilmProductResource) — bukan
                    // DeleteBulkAction polos yang bisa cascade riwayat HR
                    // tanpa peringatan atau berhenti total kalau 1 baris
                    // gagal.
                    // SEBELUMNYA tidak ada ->visible() sama sekali di sini —
                    // BulkAction custom TIDAK auto-wired ke Gate (beda dari
                    // DeleteBulkAction bawaan, lihat catatan di
                    // FilmProductResource), jadi staff manapun dengan akses
                    // menu ini bisa hapus massal akun staff lain, terlepas
                    // dari canDelete()==isFullAccess() yang sudah ada di
                    // atas. Ditemukan lewat sapu bersih 2026-09-14 (audit
                    // framework, "Otorisasi default-deny").
                    Tables\Actions\BulkAction::make('delete')
                        ->label('Hapus')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->visible(fn () => auth()->user()?->isFullAccess() ?? false)
                        ->requiresConfirmation()
                        ->modalDescription('Akun dengan riwayat HR (absensi/cuti/gaji/SP/kontrak) akan DILEWATI, bukan dihapus — nonaktifkan akun itu satu-satu lewat aksi "Nonaktifkan".')
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            $deleted = 0;
                            $blocked = 0;

                            foreach ($records as $record) {
                                if (auth()->id() === $record->id || $record->hasHrHistory()) {
                                    $blocked++;
                                    continue;
                                }

                                try {
                                    $record->delete();
                                    $deleted++;
                                } catch (\Illuminate\Database\QueryException $e) {
                                    $blocked++;
                                }
                            }

                            \Filament\Notifications\Notification::make()
                                ->title("{$deleted} akun dihapus" . ($blocked > 0 ? ", {$blocked} dilewati (punya riwayat HR/terkunci)" : ''))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function getEloquentQuery(): Builder
    {
        // User dengan role "partner" (mitra referral, akun dibuat massal
        // lewat form GIIAS/signup) SENGAJA disembunyikan dari sini — dulu
        // mereka bercampur dengan user internal (staff/direksi) di satu
        // list yang sama, padahal jumlahnya jauh lebih banyak dan profil
        // lengkapnya (nama bisnis, referral code, poin) sudah ada
        // tersendiri di menu Partner (PartnerResource). Menu "User" ini
        // sekarang khusus akun internal perusahaan.
        // ->with('employeeType') ditambahkan 2026-09-27 (audit Tipe
        // Karyawan) -- kolom "employeeType.name" di table() sebelumnya
        // N+1 per baris, ditambahkan 2026-09-22 (bersamaan dengan fitur
        // Tipe Karyawan) SETELAH sweep N+1 eager-loading proyek ini
        // selesai, jadi belum sempat kena perbaikan itu.
        return parent::getEloquentQuery()
            ->with('employeeType')
            ->whereDoesntHave('roles', fn (Builder $q) => $q->where('name', 'partner'));
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Resources\UserResource\RelationManagers\DocumentsRelationManager::class,
            \App\Filament\Resources\UserResource\RelationManagers\CareerHistoriesRelationManager::class,
            \App\Filament\Resources\RelationManagers\ActivityHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}