<?php

namespace App\Support;

use App\Exports\ActivityLogExport;
use App\Models\Booking;
use App\Models\BookingMessage;
use App\Models\BookingSaleLine;
use App\Models\User;
use App\Models\WarrantyMaintenanceSchedule;

/**
 * Isi halaman "Panduan Penggunaan" di panel admin: cara memakai sistem, ditulis dengan bahasa yang dipakai staf. Tiap panduan
 * punya daftar `menus` (nama class resource/halaman, tanpa namespace) -- panduan hanya ditampilkan ke staf yang punya akses ke
 * salah satu menu itu (akses penuh melihat semuanya). `menus` kosong = untuk semua.
 *
 * Isi harus mengikuti perilaku sistem yang sebenarnya (Panduan Fase 0, 2026-10-22). Angka dan label yang bisa berubah diambil dari
 * kode, bukan diketik ulang: tahap pengerjaan dari BookingMessage, jenis layanan dari BookingSaleLine, slot jam dari
 * config('booking.light_slots'), batas respons dari config('booking.confirm_sla_hours'), toleransi maintenance dari
 * WarrantyMaintenanceSchedule::GRACE_DAYS. GuideAccuracyTest menjaga keselarasan ini dan memastikan setiap istilah **tebal**
 * (nama tombol/menu) benar-benar ada sebagai label di kode.
 *
 * Teks tebal yang hanya ada di aplikasi staf (repositori terpisah, tidak bisa diperiksa tes ini) didaftarkan di MOBILE_LABELS
 * setelah diperiksa manual terhadap kode aplikasi.
 */
class GuideCatalog
{
    /** Label yang hanya ada di aplikasi staf; diperiksa manual terhadap kode aplikasi 2026-10-22. */
    public const MOBILE_LABELS = [
        'Absen Masuk',
        'Absen Keluar',
        'Konfirmasi Booking',
        'Selesaikan Booking',
        'Ubah Jadwal',
        'Catat Penjualan',
        'Ajukan Izin/Cuti',
        'Cek Jarak Saya ke Toko',
        'Detail Pengerjaan',
        'K3 & Penilaian',
        'Saya Sudah Membaca',
        'Simpan Kondisi Kendaraan',
        'Simpan Perubahan',
        'Download Slip Gaji (PDF)',
        'Installer (bisa lebih dari satu)',
        'Kirim Hasil QC',
    ];

    /** Halaman yang tidak perlu panduan sendiri (halaman Panduan itu sendiri). */
    public const NO_GUIDE_NEEDED = ['Panduan'];

    /**
     * Kelompok panduan, berurutan seperti tampil di halaman Panduan dan aplikasi staf. Panduan per peran paling atas karena itulah titik
     * mulai tiap staf; daftar yang tampil sudah disaring menurut peran dan akses, jadi staf hanya melihat kelompok yang ada isinya.
     */
    public const CATEGORIES = [
        'peran' => 'Mulai dari peran Anda',
        'layanan' => 'Per layanan',
        'booking' => 'Booking dan penjualan',
        'pelanggan' => 'Pelanggan, produk, dan promosi',
        'keuangan' => 'Keuangan',
        'karyawan' => 'Karyawan',
        'inventaris' => 'Inventaris dan stok',
        'marketing' => 'Marketing dan konten',
        'laporan' => 'Dashboard dan laporan',
        'sistem' => 'Sistem dan pengaturan',
        'referensi' => 'Referensi',
    ];

    /** Panduan => kelompok. Panduan baru WAJIB ditambahkan di sini (dijaga tes). */
    public const CATEGORY_BY_ID = [
        'installer-mulai' => 'peran', 'installer-pekerjaan' => 'peran', 'installer-hr' => 'peran', 'leader-harian' => 'peran',
        'store-manager-harian' => 'peran', 'admin-toko-harian' => 'peran', 'finance-harian' => 'peran', 'hr-harian' => 'peran',
        'direksi-harian' => 'peran',
        'layanan-ppf' => 'layanan', 'layanan-kaca-film' => 'layanan', 'layanan-detailing' => 'layanan', 'layanan-premium-wash' => 'layanan',
        'booking' => 'booking', 'spk' => 'booking', 'quotation-lead' => 'booking', 'invoice' => 'booking', 'penjualan' => 'booking',
        'teknisi' => 'booking', 'tanggal-tidak-tersedia' => 'booking', 'review-toko' => 'booking', 'pengaturan-booking' => 'booking',
        'garansi-maintenance' => 'booking',
        'pelanggan' => 'pelanggan', 'katalog-produk' => 'pelanggan', 'poin-promo' => 'pelanggan', 'reward-voucher' => 'pelanggan',
        'approval' => 'keuangan', 'transaksi-keuangan' => 'keuangan', 'kas-bank' => 'keuangan', 'jurnal-bagan-akun' => 'keuangan',
        'hutang-piutang' => 'keuangan', 'tutup-periode' => 'keuangan', 'anggaran' => 'keuangan', 'laporan-keuangan' => 'keuangan',
        'absensi' => 'karyawan', 'karyawan-akun' => 'karyawan', 'cuti-kontrak' => 'karyawan', 'penggajian' => 'karyawan',
        'k3-kinerja' => 'karyawan', 'hr-laporan' => 'karyawan', 'kebijakan-checklist' => 'karyawan',
        'stok' => 'inventaris', 'aset-tetap' => 'inventaris', 'permohonan-pembelian' => 'inventaris', 'riwayat-stok-dashboard' => 'inventaris',
        'lead-inquiry-kemitraan' => 'marketing', 'konten-beranda' => 'marketing', 'galeri-izin-foto' => 'marketing', 'klaim-bukti' => 'marketing',
        'dashboard' => 'laporan', 'laporan-penjualan' => 'laporan', 'laporan-operasional' => 'laporan', 'laporan-pelanggan-poin' => 'laporan',
        'laporan-persediaan' => 'laporan', 'laporan-karyawan' => 'laporan', 'analisa-laporan' => 'laporan',
        'kirim-notifikasi' => 'sistem', 'riwayat-notifikasi' => 'sistem', 'master-data' => 'sistem', 'histori-aktivitas' => 'sistem',
        'istilah' => 'referensi',
    ];

    /** Versi isi panduan dan tanggal terakhir isinya dicocokkan dengan kode; tiap panduan bisa menimpa lewat kunci `version` dan `reviewed_at`. */
    public const VERSION = '1.0';

    public const REVIEWED_AT = '2026-10-22';

    /** Panduan yang terakhir ditinjau lebih lama dari ini diberi penanda "perlu ditinjau ulang". */
    public const REVIEW_INTERVAL_DAYS = 180;

    /** Panduan sudah lewat masa tinjau? Dasarnya tanggal tinjau terakhir panduan itu. */
    public static function isOverdue(array $guide, ?\Carbon\CarbonInterface $now = null): bool
    {
        $reviewed = \Illuminate\Support\Carbon::parse($guide['reviewed_at'] ?? self::REVIEWED_AT);

        return $reviewed->copy()->addDays(self::REVIEW_INTERVAL_DAYS)->lt($now ?? now());
    }

    /**
     * Menempelkan catatan perusahaan (GuideNote) ke tiap panduan pada kunci `note` (null bila tidak ada). Satu kueri; dilewati bila
     * tabelnya belum ada (mis. basis data yang belum dimigrasi).
     *
     * @param  array<int, array<string, mixed>>  $guides
     * @return array<int, array<string, mixed>>
     */
    public static function withNotes(array $guides): array
    {
        $notes = \Illuminate\Support\Facades\Schema::hasTable('guide_notes')
            ? \App\Models\GuideNote::query()->pluck('body', 'guide_id')->all()
            : [];

        return array_map(fn (array $guide) => $guide + ['note' => $notes[$guide['id']] ?? null], $guides);
    }

    /**
     * Panduan umum yang juga berlaku untuk akun tanpa akses panel (installer). Selain ini installer hanya mendapat panduan ber-`audience`
     * installer (ditulis dari sudut pandang layar aplikasi). Akun tanpa batas `menu_access` dianggap berhak atas semua menu, jadi tanpa
     * daftar ini installer akan melihat panduan keuangan dan HR; panduan Booking dan layanan juga tidak cocok karena menjelaskan aksi
     * leader (konfirmasi, chat, QC, serah terima) yang ditolak untuk installer.
     */
    public const APP_ONLY_GUIDE_IDS = ['absensi', 'istilah'];

    /** Panduan yang hanya bermakna bila modul Keuangan menyala (FEATURE_FINANCE). 'approval' tidak termasuk: itu Persetujuan Transaksi (Booking). */
    public const FINANCE_GUIDE_IDS = [
        'transaksi-keuangan', 'kas-bank', 'jurnal-bagan-akun', 'hutang-piutang', 'tutup-periode', 'anggaran', 'laporan-keuangan', 'finance-harian',
    ];

    /**
     * Panduan yang boleh dilihat user ini: akses penuh melihat semua, staf panel melihat panduan modul yang bisa diaksesnya, dan akun
     * tanpa akses panel hanya APP_ONLY_GUIDE_IDS. Dipakai halaman Panduan di panel dan API aplikasi staf supaya keduanya sama.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forUser(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $canUsePanel = $user->canAccessStaffArea();

        $visible = array_values(array_filter(self::all(), function (array $guide) use ($user, $canUsePanel) {
            // Modul Keuangan mati (FEATURE_FINANCE): panduannya tidak ditampilkan karena menunya tidak ada.
            if (! config('features.finance') && in_array($guide['id'], self::FINANCE_GUIDE_IDS, true)) {
                return false;
            }

            // Menu K3, Penilaian Kinerja, dan Pelatihan Karyawan disembunyikan (config/features.php): panduannya ikut hilang bila ketiganya mati.
            if ($guide['id'] === 'k3-kinerja' && ! (config('features.k3') || config('features.employee_training') || config('features.performance_reviews'))) {
                return false;
            }

            if (isset($guide['audience'])) {
                return $user->hasRole($guide['audience']) || $user->isFullAccess();
            }

            // Panduan untuk tugas tanpa role sendiri (mis. HR): hanya akun yang menunya DICENTANG eksplisit. Daftar menu kosong (null) berarti
            // semua menu terbuka, dan itu tidak boleh membuat panduan ini muncul di semua staf.
            if (isset($guide['audience_menus'])) {
                return $user->isFullAccess()
                    || (is_array($user->menu_access) && array_intersect($guide['audience_menus'], $user->menu_access) !== []);
            }

            if (! $canUsePanel) {
                return in_array($guide['id'], self::APP_ONLY_GUIDE_IDS, true);
            }

            // `open`: terbuka untuk semua staf panel walau menunya tidak ada di daftar Akses Menu (mis. Dashboard utama).
            return $guide['menus'] === []
                || ($guide['open'] ?? false)
                || $user->isFullAccess()
                || collect($guide['menus'])->contains(fn (string $menu) => $user->hasMenuAccess($menu));
        }));

        // Urut menurut kelompok (urutan CATEGORIES); di dalam kelompok tetap urutan katalog.
        $order = array_flip(array_keys(self::CATEGORIES));
        usort($visible, fn (array $a, array $b) => ($order[$a['category']] ?? 999) <=> ($order[$b['category']] ?? 999));

        return self::withNotes($visible);
    }

    /**
     * @return array<int, array{id: string, title: string, summary: string, menus: array<int, string>, steps: array<int, string>, tips: array<int, string>}>
     */
    public static function all(): array
    {
        $sla = (int) config('booking.confirm_sla_hours', 4);
        $promoMax = (int) config('marketing.promo_max_campaigns_per_week', 2);
        $quietHours = QuietHours::label();
        $exportMax = number_format(ActivityLogExport::$maxRows, 0, ',', '.');
        $slots = implode(', ', (array) config('booking.light_slots', []));
        $services = implode(', ', BookingSaleLine::SERVICE_TYPES);
        $kacaFilmStages = implode(' → ', array_values(BookingMessage::PRODUCT_STAGES['kaca_film']));
        $ppfStages = implode(' → ', array_values(BookingMessage::PRODUCT_STAGES['ppf']));
        $sharedStages = implode(' → ', array_values(BookingMessage::SHARED_STAGES));
        $graceDays = WarrantyMaintenanceSchedule::GRACE_DAYS;
        $photoMonths = (int) config('booking.photo_retention_months', 24);
        $negotiationMax = rtrim(rtrim(number_format(Booking::NEGOTIATION_DISCOUNT_MAX_PCT, 1, ',', '.'), '0'), ',');

        $guides = [
            [
                'id' => 'booking',
                'title' => 'Booking: dari masuk sampai selesai',
                'summary' => "Alur satu booking untuk keempat layanan ({$services}) dari pesanan sampai serah terima. Langkah di lapangan dikerjakan di aplikasi staff; pengaturan dan pencatatan penjualan di panel.",
                'menus' => ['BookingResource', 'CapacityCalendar', 'BookingServiceTypeResource'],
                'steps' => [
                    "Pesanan masuk. Pelanggan memesan lewat aplikasi: pilih layanan, toko, dan tanggal (untuk Detailing dan Premium Wash wajib memilih jam). Staff juga bisa membuatnya di panel untuk pelanggan dari WhatsApp atau yang datang langsung. Status **Pending**; toko diharapkan merespons dalam {$sla} jam.",
                    'Konfirmasi di aplikasi staff (Store Manager, direksi, super admin, atau staff toko dengan akses menu Booking seperti Leader installer): periksa kapasitas, isi lama pengerjaan (hari kerja) bila perlu, lalu **Konfirmasi Booking**. Status menjadi **Confirmed** dan slot toko terkunci. Bila ada installer yang cuti atau libur pada tanggal itu, muncul peringatan (tidak memblokir konfirmasi).',
                    'Store Manager atau direksi menugaskan installer yang mengerjakan (satu atau lebih). Penugasan tercatat di riwayat aktivitas.',
                    'Saat unit datang, ambil foto penerimaan kendaraan dari empat sudut (depan, belakang, kiri, kanan) dan foto kerusakan yang sudah ada (maksimal 10, wajib diberi keterangan). Leader installer membuat **SPK** (inspeksi kendaraan) sebelum pengerjaan; installer melengkapinya dari booking yang ditugaskan kepadanya.',
                    "Progres dikirim lewat **chat booking** per tahap (boleh dengan foto); pelanggan mendapat notifikasi di setiap tahap. Tahap hanya bisa maju. Kaca Film: {$kacaFilmStages}. PPF: {$ppfStages}. Booking Kaca Film + PPF punya dua jalur tahap. Tahap bersama untuk semua layanan: {$sharedStages}. Detailing dan Premium Wash tidak punya tahap pengerjaan: setelah dikerjakan langsung ke Quality Check.",
                    'Tandai tahap **Quality Check** (semua produk yang dipesan harus sudah sampai tahap terakhirnya). Isi juga checklist QC layanan itu; pemeriksanya Leader, Store Manager, atau akses penuh, bukan orang yang mengerjakan unit. Semua butir harus dijawab dan butir yang tidak lulus wajib diberi catatan. Bila lulus, booking ditandai **Siap Diambil** dan pelanggan diberi tahu.',
                    'Serah terima unit: catat bahwa petunjuk perawatan sudah disampaikan. Untuk PPF dan Kaca Film tanda tangan basah di kertas wajib diterima (untuk Detailing dan Premium Wash opsional); sistem hanya mencatat bahwa tanda tangan sudah diterima. Foto akhir dan catatan boleh ditambahkan. Dicatat sekali per booking.',
                    'Terakhir, **Selesaikan Booking**. Status menjadi **Completed** dan tercatat di riwayat booking pelanggan. Syaratnya tahap Quality Check sudah ditandai.',
                    'Setelah selesai, kasir atau admin mencatat penjualannya di panel lewat **Proses Referral** (lihat panduan Penjualan).',
                ],
                'tips' => [
                    'Kewajiban lulus QC dan mencatat serah terima sebelum booking boleh diselesaikan bisa dinyalakan admin sistem. Bila menyala dan syaratnya belum terpenuhi, tombol Selesaikan Booking menolak dengan pesan yang menjelaskan apa yang kurang.',
                    "Kapasitas ada dua jenis. Layanan berat (PPF, Kaca Film) dihitung per hari; layanan ringan (Detailing, Premium Wash) dihitung per jam dengan slot {$slots}. Booking campuran dihitung sebagai berat. Tanggal yang penuh atau toko libur tidak bisa dipilih. Kapasitas harian diatur di Kalender Kapasitas & Libur; kapasitas per slot jam diatur di form Toko.",
                    'Yang boleh chat dengan pelanggan: leader installer, store manager, dan direksi. Installer tidak ikut chat.',
                    'Pelanggan bisa mengajukan ganti tanggal atau pembatalan; staff menyetujui atau menolak di kartu pengajuan pada booking. Staff juga bisa memindahkan tanggal lewat **Ubah Jadwal**.',
                    'Pelanggan tidak datang: Store Manager atau akses penuh dapat menandai tidak datang bila booking sudah Confirmed, tanggalnya sudah tiba, dan pengerjaan belum dimulai. Booking menjadi **Cancelled**, slot terlepas, dan DP yang sudah diterima dikembalikan penuh.',
                    'Pelanggan menerima pengingat otomatis H-2, H-1, dan pagi hari-H. Teks kebijakan pembatalan yang tampil di aplikasi diatur admin di Operasional, Teks Aplikasi.',
                    'Booking yang sudah dibatalkan atau selesai tidak bisa diaktifkan lagi.',
                    'Layar foto penerimaan, Quality Check, dan serah terima ada di aplikasi staff versi terbaru. Bila tidak terlihat di aplikasi Anda, perbarui aplikasi staff.',
                    'Pelanggan yang datang lagi: di daftar Booking pakai **Duplikat (pelanggan datang lagi)**. Form Buat Booking terisi data pelanggan, kendaraan, toko, dan layanan; tanggal, jam, harga, pembayaran, DP, dan installer diisi baru. Belum ada yang tersimpan sampai Anda menekan Buat.',
                ],
            ],
            [
                'id' => 'layanan-ppf',
                'title' => 'Layanan PPF: langkah di sistem',
                'summary' => 'Yang dikerjakan staff di sistem untuk satu pekerjaan PPF, dari pesanan sampai garansi dan maintenance. Alur umum ada di panduan Booking; di sini hanya hal yang khusus PPF.',
                'menus' => ['BookingResource', 'WarrantyResource', 'InventoryItemResource'],
                'steps' => [
                    'PPF adalah layanan berat: memakai kapasitas harian toko dan bisa berlangsung beberapa hari. Saat **Konfirmasi Booking**, isi lama pengerjaan (hari kerja); sistem memeriksa seluruh hari kerja dalam rentang itu, dan tanggal mulai yang hari lanjutannya penuh tidak bisa dipilih.',
                    'Siapkan unit: tugaskan installer, ambil foto penerimaan (empat sudut), catat kerusakan cat yang sudah ada sebagai foto kerusakan lengkap dengan keterangannya, dan buat **SPK**.',
                    'Catat gulungan film yang dipakai: di menu **Produk PPF/WF** pilih gulungannya lalu **Catat Pemakaian** untuk booking ini. Untuk produk yang diatur **Wajib Lacak Roll (Batch Number)** di **Daftar Produk**, SPK baru bisa ditandai selesai setelah pemakaian roll dicatat.',
                    "Kirim progres lewat **chat booking** pada tahap {$ppfStages}. Pada booking PPF + Kaca Film, tahap PPF berjalan di jalur kedua. Tahap Detailing di jalur ini adalah salah satu tahap pengerjaan PPF, bukan pemesanan layanan Detailing.",
                    'Tandai tahap **Quality Check**, lalu isi checklist QC PPF. Butir bawaannya: tidak ada gelembung atau debu di bawah film, tepi dan sudut rapi dan menempel kuat, tidak ada kerutan atau potongan salah, bodi dan kaca bersih dari sisa larutan, tidak ada baret baru pada cat. Akses penuh dapat mengubah butirnya di menu **Checklist QC**.',
                    'Serah terima: sampaikan petunjuk perawatan dan terima tanda tangan basah di kertas (wajib untuk PPF), lalu **Selesaikan Booking**.',
                    'Buat **Garansi** (atau minta pelanggan mendaftarkannya di aplikasi): pilih kategori PPF, isi plat nomor, tipe mobil, seri produk, **Posisi Pemasangan** (penuh atau sebagian, dengan keterangan area), dan **Kode Gulungan** (isi kode kedua bila satu gulungan tidak cukup). Isi **Kuota Maintenance** dan **Interval Maintenance** bila garansi menawarkan maintenance.',
                    'Garansi dari pelanggan menunggu **review**; setelah disetujui, jadwal maintenance berjalan otomatis (lihat panduan Garansi dan Maintenance PPF). Penjualannya dicatat lewat **Proses Referral** dengan baris layanan PPF.',
                ],
                'tips' => [
                    'Booking PPF + Kaca Film: Quality Check baru bisa ditandai setelah KEDUA produk sampai tahap terakhirnya.',
                    'Kode gulungan tetap muncul di pilihan garansi mobil berikutnya sampai ditandai habis lewat **Tandai Habis** di menu **Produk PPF/WF**.',
                    "Foto penerimaan, foto akhir serah terima, dan foto progres booking yang sudah selesai atau batal dihapus otomatis setelah {$photoMonths} bulan. Simpan bukti yang perlu lebih lama di tempat lain.",
                    'Garansi dan jadwal maintenance hanya ada untuk PPF dan Kaca Film.',
                ],
            ],
            [
                'id' => 'layanan-kaca-film',
                'title' => 'Layanan Kaca Film: langkah di sistem',
                'summary' => 'Yang dikerjakan staff di sistem untuk satu pekerjaan Kaca Film, dari pesanan sampai garansi. Alur umum ada di panduan Booking; di sini hanya hal yang khusus Kaca Film.',
                'menus' => ['BookingResource', 'WarrantyResource', 'InventoryItemResource'],
                'steps' => [
                    'Kaca Film adalah layanan berat: memakai kapasitas harian toko. Saat **Konfirmasi Booking**, isi lama pengerjaan (hari kerja) bila perlu.',
                    'Siapkan unit: tugaskan installer, ambil foto penerimaan (empat sudut) dan foto kerusakan yang sudah ada, lalu buat **SPK**.',
                    'Catat gulungan film yang dipakai: di menu **Produk PPF/WF** pilih gulungannya lalu **Catat Pemakaian** untuk booking ini. Untuk produk yang diatur **Wajib Lacak Roll (Batch Number)** di **Daftar Produk**, SPK baru bisa ditandai selesai setelah pemakaian roll dicatat.',
                    "Kirim progres lewat **chat booking** pada tahap {$kacaFilmStages}. Pada booking Kaca Film + PPF, tahap Kaca Film berjalan di jalur pertama dan PPF di jalur kedua.",
                    'Tandai tahap **Quality Check**, lalu isi checklist QC Kaca Film. Butir bawaannya: tidak ada gelembung, debu, atau goresan pada film, tepi film rapi dan merata di semua kaca, kaca bersih tanpa sisa larutan, semua kaca naik-turun normal, defogger dan sensor tidak terganggu. Akses penuh dapat mengubah butirnya di menu **Checklist QC**.',
                    'Serah terima: sampaikan petunjuk perawatan dan terima tanda tangan basah di kertas (wajib untuk Kaca Film), lalu **Selesaikan Booking**.',
                    'Buat **Garansi** (atau minta pelanggan mendaftarkannya di aplikasi): pilih kategori Window Film, isi plat nomor, tipe mobil, dan seri produk. Di bagian Detail Instalasi isi **Kode Gulungan — Kaca Depan** dan, bila mobil ini juga dipasang di posisi itu, **Kode Gulungan — Kaca Samping & Belakang**. Pilihan hanya menampilkan gulungan yang terdaftar untuk posisi tersebut.',
                    'Garansi dari pelanggan menunggu **review**. Penjualannya dicatat lewat **Proses Referral** dengan baris layanan Kaca Film.',
                ],
                'tips' => [
                    'Satu gulungan kaca film dipakai untuk banyak mobil (sekitar 30) dan tetap muncul di pilihan garansi sampai ditandai habis lewat **Tandai Habis** di menu **Produk PPF/WF**.',
                    'Kaca Film tidak memakai kuota maintenance; kuota dan interval maintenance hanya untuk garansi PPF.',
                    "Foto penerimaan, foto akhir serah terima, dan foto progres booking yang sudah selesai atau batal dihapus otomatis setelah {$photoMonths} bulan.",
                ],
            ],
            [
                'id' => 'layanan-detailing',
                'title' => 'Layanan Detailing: langkah di sistem',
                'summary' => 'Yang dikerjakan staff di sistem untuk satu pekerjaan Detailing. Detailing adalah layanan ringan dengan jalur yang lebih singkat daripada PPF dan Kaca Film.',
                'menus' => ['BookingResource', 'BookingServiceTypeResource'],
                'steps' => [
                    "Detailing adalah layanan ringan: pelanggan wajib memilih jam, kapasitas dihitung per jam (slot {$slots}; jumlah per slot diatur di form Toko), dan tidak memakai kapasitas harian layanan berat. Harga paket tetap untuk semua ukuran kendaraan.",
                    'Konfirmasi dengan **Konfirmasi Booking**, lalu tugaskan installer.',
                    'Saat unit datang, ambil foto penerimaan (empat sudut) dan foto kerusakan yang sudah ada, lalu buat **SPK**.',
                    'Detailing tidak punya tahap pengerjaan. Kabari pelanggan lewat **chat booking** bila perlu (teks atau foto); setelah selesai dikerjakan, langsung tandai tahap **Quality Check**.',
                    'Isi checklist QC Detailing. Butir bawaannya: interior bersih dan kering, eksterior bersih tanpa sisa pasta atau compound, kaca bersih tanpa noda, velg dan ban bersih, tidak ada kerusakan baru. Akses penuh dapat mengubah butirnya di menu **Checklist QC**.',
                    'Serah terima: sampaikan petunjuk perawatan. Tanda tangan basah di kertas opsional untuk Detailing. Lalu **Selesaikan Booking**.',
                    'Penjualannya dicatat lewat **Proses Referral** dengan baris layanan Detailing.',
                ],
                'tips' => [
                    'Detailing tidak punya garansi atau jadwal maintenance di sistem; garansi hanya untuk PPF dan Kaca Film.',
                    'Nama, deskripsi, lama pengerjaan, dan kategori kapasitas layanan diatur di menu **Layanan Booking**.',
                    'Booking yang berisi layanan berat dan ringan sekaligus dihitung sebagai berat.',
                    'Tahap Detailing yang muncul di booking PPF adalah tahap pengerjaan PPF, bukan layanan ini.',
                ],
            ],
            [
                'id' => 'layanan-premium-wash',
                'title' => 'Layanan Premium Wash: langkah di sistem',
                'summary' => 'Yang dikerjakan staff di sistem untuk satu pekerjaan Premium Wash. Seperti Detailing, ini layanan ringan dengan jalur singkat.',
                'menus' => ['BookingResource', 'BookingServiceTypeResource'],
                'steps' => [
                    "Premium Wash adalah layanan ringan: pelanggan wajib memilih jam, kapasitas dihitung per jam (slot {$slots}; jumlah per slot diatur di form Toko), dan tidak memakai kapasitas harian layanan berat. Harga paket tetap untuk semua ukuran kendaraan.",
                    'Konfirmasi dengan **Konfirmasi Booking**, lalu tugaskan installer.',
                    'Saat unit datang, ambil foto penerimaan (empat sudut) dan foto kerusakan yang sudah ada, lalu buat **SPK**.',
                    'Premium Wash tidak punya tahap pengerjaan. Setelah selesai dikerjakan, langsung tandai tahap **Quality Check**.',
                    'Isi checklist QC Premium Wash. Butir bawaannya: bodi bersih tanpa sisa sabun, kaca bersih tanpa noda, velg dan ban bersih, interior disedot dan dilap, tidak ada kerusakan baru. Akses penuh dapat mengubah butirnya di menu **Checklist QC**.',
                    'Serah terima: sampaikan petunjuk perawatan. Tanda tangan basah di kertas opsional untuk Premium Wash. Lalu **Selesaikan Booking**.',
                    'Penjualannya dicatat lewat **Proses Referral** dengan baris layanan Premium Wash.',
                ],
                'tips' => [
                    'Premium Wash tidak punya garansi atau jadwal maintenance di sistem; garansi hanya untuk PPF dan Kaca Film.',
                    'Nama, deskripsi, lama pengerjaan, dan kategori kapasitas layanan diatur di menu **Layanan Booking**.',
                    'Booking yang berisi layanan berat dan ringan sekaligus dihitung sebagai berat.',
                ],
            ],
            [
                'id' => 'penjualan',
                'title' => 'Penjualan: Proses Referral, rincian harga, dan pelunasan',
                'summary' => 'Mencatat nilai penjualan sebuah booking yang sudah selesai: harga per layanan, pembayaran yang diterima, DP, voucher, dan kode referral.',
                'menus' => ['BookingResource', 'SalesResource'],
                'steps' => [
                    'Syaratnya booking sudah **Completed**. Di panel, buka daftar Booking dan pilih aksi **Proses Referral** pada booking itu. Biasanya dikerjakan kasir atau admin, bukan installer. Aplikasi staff versi terbaru punya **Catat Penjualan** dengan aturan persetujuan yang sama.',
                    'Isi **Rincian Harga per Layanan**: satu baris per layanan atau produk (jenis layanan, produk, bagian atau keterangan, jumlah, harga satuan, potongan baris). Pendapatan per jenis layanan di laporan mengikuti nilai baris ini; tanpa rincian, nilai dibagi rata antar jenis layanan. Untuk booking yang wajib rincian, form menolak bila belum ada baris bernilai.',
                    'Isi **Nominal Transaksi**: angka bersih setelah voucher, promo, atau nego. Selisihnya dengan jumlah rincian dianggap potongan. Nominal ini juga dasar poin referral (1 poin per Rp10.000).',
                    'Isi **Pembayaran diterima**. Boleh lebih dari satu baris (mis. sebagian tunai, sebagian transfer): pilih metode dan nominal. Jumlah pembayaran ditambah DP yang terpakai tidak boleh melebihi Nominal Transaksi; sisanya tercatat sebagai sisa tagihan (Belum Lunas) di booking. Hapus semua baris bila belum ada pembayaran.',
                    'Isi bila ada: **Ukuran Kendaraan** (dasar menghitung nego harga), **Promo Total Pembelian**, **Kode Voucher** (otomatis ditandai Terpakai), dan **Kode Referral Partner**.',
                    'Simpan. Untuk staff tanpa akses penuh, pengajuan menunggu persetujuan akses penuh dan belum berlaku (lihat panduan Approval). Untuk akses penuh, nominal dan tanggal penjualan tersimpan, lalu booking masuk ke laporan penjualan.',
                ],
                'tips' => [
                    "Nego harga hingga {$negotiationMax}% dari harga acuan matriks boleh tanpa persetujuan; lebih dari itu perlu persetujuan.",
                    'Uang muka dicatat lewat **Catat Uang Muka (DP)**; DP yang tidak terpakai dikembalikan lewat **Kembalikan Sisa DP**. DP yang terpakai otomatis mengurangi sisa tagihan saat Proses Referral.',
                    'Refund dilakukan lewat **Proses Refund** pada booking; untuk staff tanpa akses penuh refund menunggu persetujuan.',
                    'Sisa tagihan yang belum dibayar tampil di booking dan laporan penjualan; pelunasan dan pembukuannya dilakukan di Accurate.',
                    'Salah mengisi kode referral? Kosongkan kolomnya lalu simpan untuk menghapusnya.',
                ],
            ],
            [
                'id' => 'spk',
                'title' => 'SPK: surat perintah kerja kendaraan',
                'summary' => 'Form inspeksi dan perintah kerja untuk satu booking: data kendaraan, daftar pekerjaan, perlengkapan, dan kerusakan yang sudah ada sebelum pengerjaan.',
                'menus' => ['SpkResource'],
                'steps' => [
                    'Buat SPK dari booking yang sudah Dikonfirmasi dan belum punya SPK (satu booking satu SPK). Yang bisa membuat SPK baru: leader installer, Store Manager, direksi, dan super admin. Installer dan staff lain dengan akses menu melengkapi SPK yang sudah ada, termasuk dari aplikasi staff.',
                    'Di tab **Informasi** lengkapi data pelanggan dan kendaraan (nama, telepon, alamat, no. polisi, no. rangka, merek, tahun, jenis, kilometer, BBM, battery) serta **Jam Masuk**. Sebagian data terisi otomatis dari booking.',
                    'Di tab **Checklist** daftar Uraian Pekerjaan, Extra Services, dan Perlengkapan Kendaraan sudah terisi daftar bawaan (mis. PPF, Kaca Film, STNK, Ban Serep). Centang yang sesuai; tambah atau hapus baris bila perlu.',
                    'Di tab **Kondisi Kendaraan** tandai kerusakan yang sudah ada pada diagram kendaraan dengan kode: C (cat luka atau belang), B (baret dalam), P (penyok), G (kaca baret atau retak), M (komponen hilang), OS (over spray), lengkap dengan catatan. Ini bukti kondisi unit sebelum dikerjakan.',
                    'Tab **Catatan** untuk keterangan tambahan.',
                    'Saat kendaraan keluar, isi **Jam Keluar** (SPK ditandai selesai). Untuk produk film yang diatur Wajib Lacak Roll, SPK baru bisa ditandai selesai setelah pemakaian roll dicatat di menu **Produk PPF/WF** lewat **Catat Pemakaian**.',
                    '**Cetak PDF** menghasilkan lembar kerja untuk ditandatangani basah. Setelah kendaraan keluar, **Cetak Ulang (Arsip)** menghasilkan salinan arsip.',
                ],
                'tips' => [
                    'Nomor SPK dibuat otomatis oleh sistem.',
                    'Daftar bawaan checklist bisa ditambah atau dikurangi per SPK tanpa memengaruhi SPK lain.',
                    'Menghapus SPK hanya boleh akses penuh atau staff yang diberi hak hapus.',
                ],
            ],
            [
                'id' => 'quotation-lead',
                'title' => 'Quotation (Lead): menindaklanjuti calon pelanggan',
                'summary' => 'Permintaan penawaran dari aplikasi atau website masuk sebagai lead. Tugas staff: menghubungi, mencatat perkembangan, dan menetapkan hasilnya.',
                'menus' => ['QuotationResource'],
                'steps' => [
                    'Lead baru berstatus New dan muncul sebagai angka badge di menu. Lead dari website yang belum punya toko diteruskan ke akun akses penuh dan staff yang punya akses menu Quotation.',
                    'Hubungi calon pelanggan lewat tombol **WhatsApp** atau **Telepon** di baris lead. Setelah dihubungi, ubah statusnya lewat **Ubah Status**: New, lalu Contacted, lalu Closed atau Cancelled.',
                    'Isi **Penanggung Jawab** (staff yang menangani), **Kanal Masuk** (dari mana lead datang: WhatsApp, telepon, datang langsung, dan sebagainya), dan periksa **Produk Diminati** serta catatan pelanggan.',
                    'Setelah ada kepastian, tetapkan **Hasil**: Deal bila lead sudah jadi pelanggan, booking, atau partner; Tidak Jadi bila batal. Untuk Tidak Jadi, **Alasan Tidak Jadi** wajib dipilih (harga di atas anggaran, memilih penyedia lain, tidak merespons lagi, belum butuh atau menunda, lokasi terlalu jauh, layanan tidak sesuai kebutuhan, atau lainnya).',
                ],
                'tips' => [
                    'Lead berstatus New yang belum ditindaklanjuti lebih dari 24 jam memicu pengingat otomatis ke staff.',
                    'Kolom **Direspons Setelah** menunjukkan berapa lama lead menunggu sampai staff merespons; dipakai laporan kecepatan respons.',
                    'Hasil dan alasan tercatat di riwayat lead; pilih alasan yang paling mendekati supaya laporan bisa mengelompokkannya.',
                ],
            ],
            [
                'id' => 'invoice',
                'title' => 'Invoice: tagihan dan pembayaran',
                'summary' => 'Membuat dokumen tagihan untuk pelanggan, mencatat pembayaran bertahap, dan mencetaknya.',
                'menus' => ['InvoiceResource'],
                'steps' => [
                    'Buat invoice baru: pilih **Toko**, isi **Referensi Booking (opsional)** bila terkait booking, lalu **Tanggal Dibuat** dan **Jatuh Tempo**. Data pelanggan diisi manual atau dengan memilih **Akun Pelanggan (opsional)**.',
                    'Di **Detail Produk** tambahkan baris: nama, jumlah, satuan, harga (Rp), dan diskon (%) per baris. Produk dari katalog boleh dipilih (opsional). Invoice harus punya minimal satu baris.',
                    'Di **Rincian Tagihan** atur **Jenis Diskon Transaksi**, **Nilai Diskon Transaksi**, **Biaya Pengiriman**, dan **Biaya Lainnya**. Total tagihan dihitung otomatis.',
                    'Cetak lewat **Cetak PDF**.',
                    'Pembayaran bertahap dicatat lewat **Catat Pembayaran**: nominal ditambahkan ke yang sudah dibayar, dan status berubah ke Lunas otomatis begitu terpenuhi. **Tandai Lunas** langsung mengisi sisa tagihan penuh; jangan dipakai untuk pembayaran bertahap. **Void** membatalkan invoice dan bersifat final.',
                ],
                'tips' => [
                    'Invoice hanya bisa diubah selama berstatus draft atau belum dibayar. Yang sudah Lunas atau Void tidak bisa diedit; koreksi lewat Void lalu buat invoice baru.',
                    'Invoice adalah dokumen tagihan: tidak menghitung pajak atau PPN. Penjualan booking dicatat lewat **Proses Referral** (lihat panduan Penjualan).',
                    'Nomor invoice dibuat otomatis per toko dan hari.',
                    'Kaki invoice menampilkan nama perusahaan, NPWP, dan alamat dari menu **Profil Perusahaan** di Sistem (diisi akses penuh; NPWP 15 atau 16 angka). Bila kosong, hanya nama bawaan yang tampil. Invoice tetap bukan Faktur Pajak.',
                ],
            ],
            [
                'id' => 'teknisi',
                'title' => 'Installer: data, komisi, dan persetujuan',
                'summary' => 'Data installer dan installer, level sertifikasi, dan komisi per pekerjaan.',
                'menus' => ['TechnicianResource'],
                'steps' => [
                    'Isi **Nama Installer**, **Toko**, **No. Telepon / HP**, dan **Level Sertifikasi**. Hubungkan ke **Akun Installer** supaya level sertifikasi terlihat saat menugaskan installer di Booking; boleh dikosongkan bila akun loginnya belum dibuat.',
                    'Installer baru berstatus Menunggu Review. Akses penuh menekan **Aktifkan** untuk menyetujui atau **Tolak** (alasan penolakan wajib diisi dan tersimpan di catatan installer).',
                    'Isi **Komisi per Pekerjaan (Rp)** bila ada: nominal tetap per booking yang dikerjakan, bukan persentase. Bila satu booking dikerjakan lebih dari satu installer, masing-masing mendapat nominal penuh, bukan dibagi.',
                    'Bagian Riwayat Pekerjaan di halaman installer menampilkan 10 pekerjaan terbarunya.',
                ],
                'tips' => [
                    'Komisi per Pekerjaan diabaikan begitu installer punya minimal satu baris di tab Tarif per Layanan; tarif per layanan yang dipakai.',
                    'Hanya akses penuh yang mengubah Status installer.',
                ],
            ],
            [
                'id' => 'tanggal-tidak-tersedia',
                'title' => 'Kalender Kapasitas & Libur: kapasitas harian dan menutup tanggal',
                'summary' => 'Mengatur berapa pekerjaan berat yang boleh masuk per hari, dan menutup tanggal tertentu (libur, renovasi, acara) supaya pelanggan tidak bisa memilihnya.',
                'menus' => ['CapacityCalendar'],
                'steps' => [
                    'Buka **Kalender Kapasitas & Libur** (grup Operasional). Admin memilih toko di pojok kanan atas; staff toko otomatis terkunci ke tokonya. Angka di tiap tanggal adalah booking yang menempati dibagi kapasitas harian layanan berat.',
                    'Klik tanggal (hari ini atau ke depan). Isi **Kapasitas** lalu **Simpan**. Nyalakan **Pakai kapasitas bawaan toko** untuk menghapus pengaturan manual tanggal itu. Untuk banyak tanggal sekaligus pakai **Atur Kapasitas Rentang**.',
                    'Menutup satu tanggal penuh (libur, renovasi, acara): klik tanggalnya, nyalakan **Tutup tanggal ini**, isi **Alasan (opsional)**, lalu **Simpan**. Tanggal yang ditutup tampil bergaris abu-abu bertuliskan Ditutup, tidak bisa dipilih pelanggan di aplikasi, dan kapasitasnya tidak berlaku.',
                    'Menutup beberapa hari sekaligus: **Tutup Rentang Tanggal**, isi **Dari Tanggal**, **Sampai Tanggal**, dan **Alasan (opsional)**. Booking yang sudah ada di rentang itu ditampilkan sebagai peringatan dan tidak dibatalkan. Tanggal yang sudah ditutup dilewati, tanggal lampau tidak bisa ditutup, dan satu kali paling banyak 120 hari.',
                    'Libur nasional atau cuti bersama untuk seluruh toko (hanya akses penuh): menu **Lainnya** > **Tutup Semua Toko**, isi tanggal dan **Alasan** (wajib). Semua toko aktif ditutup sekaligus; tanggal yang sudah tertutup dilewati dan alasannya tidak ditimpa. Menu **Lainnya** juga berisi **Hapus Semua Override** yang mengembalikan semua tanggal ke depan ke kapasitas bawaan toko.',
                    'Membuka kembali: klik tanggal yang ditutup, nyalakan **Buka kembali tanggal ini**, lalu **Simpan**; atau tekan **Buka Kembali** pada daftar **Tanggal ditutup yang akan datang** di bawah kalender. Alasan tanggal ditutup bisa diubah di kotak yang sama.',
                ],
                'tips' => [
                    'Menutup tanggal berbeda dengan kapasitas kecil: tanggal ditutup tidak menerima booking sama sekali, tidak dihitung Alpha dan jam kerjanya tidak dihitung di gaji. Kapasitas hanya membatasi jumlah booking berat per hari.',
                    'Libur rutin tiap minggu (mis. selalu libur hari Minggu) diatur di Toko > Jam Operasional, bukan di sini. Hari itu tampil Libur dan tidak bisa diklik.',
                    'Kapasitas hanya untuk layanan berat (PPF, Kaca Film). Detailing dan Premium Wash dihitung per jam dan diatur di data Toko.',
                    'Mengatur kapasitas mengikuti akses menu Booking Instalasi; menutup atau membuka tanggal mengikuti izin lama "Tanggal Tidak Tersedia" di Hak Akses (nama kotaknya kini Tutup/Buka Tanggal).',
                ],
            ],
            [
                'id' => 'review-toko',
                'title' => 'Review Toko: menindaklanjuti review pelanggan',
                'summary' => 'Review yang diberikan pelanggan setelah booking selesai, dan tindak lanjut untuk review yang kurang baik.',
                'menus' => ['StoreReviewResource'],
                'steps' => [
                    'Setelah booking selesai, pelanggan memberi review di aplikasi: **Sentimen**, **Aspek yang Disebut**, dan **Komentar**. Staff tidak membuat review; menu ini hanya untuk membaca dan menindaklanjuti.',
                    'Review negatif otomatis mengirim pemberitahuan ke akun akses penuh dan ke staff toko itu yang punya akses menu Review Toko. Staff tanpa akses menu itu, atau dari toko lain, tidak diberi tahu.',
                    'Buka review, hubungi pelanggan bila perlu, lalu tekan **Tandai Ditindaklanjuti** dan isi **Catatan Tindak Lanjut**.',
                    'Gunakan filter **Sudah Ditindaklanjuti** untuk melihat review yang masih terbuka.',
                ],
                'tips' => [
                    'Satu booking hanya bisa diberi satu review.',
                ],
            ],
            [
                'id' => 'pengaturan-booking',
                'title' => 'Pengaturan booking: Checklist QC dan Teks Aplikasi',
                'summary' => 'Dua pengaturan yang memengaruhi alur booking dan hanya diubah akses penuh.',
                'menus' => ['QcChecklistItemResource', 'AppSettingResource'],
                'steps' => [
                    '**Checklist QC**: pilih **Layanan**, lalu tulis **Butir yang Diperiksa** sebagai pernyataan yang harus benar (mis. "Tepi film rapi dan menempel kuat"). Butir baru masuk di urutan paling akhir untuk layanannya. Untuk mengubah urutan, tekan **Urutkan Ulang** di atas daftar, seret barisnya ke posisi yang diinginkan, lalu tekan **Selesai Mengurutkan**.',
                    'Centang **Wajib Lulus** untuk butir yang menentukan: bila butir wajib tidak lulus, Quality Check dinyatakan tidak lulus. Matikan **Dipakai** untuk berhenti memeriksa sebuah butir tanpa menghapus riwayatnya.',
                    '**Teks Aplikasi**: ubah **Isi Teks** (saat ini kebijakan pembatalan booking) yang tampil ke pelanggan. Perubahan langsung berlaku tanpa rilis aplikasi. Kunci teksnya tetap; tidak ada tambah atau hapus.',
                ],
                'tips' => [
                    'Perubahan Checklist QC berlaku untuk pemeriksaan berikutnya; hasil lama menyimpan salinan butirnya sendiri.',
                    'Teks kebijakan pembatalan bawaan netral ("Tidak ada biaya pembatalan"); ganti dengan aturan toko yang sebenarnya.',
                    'Kedua menu ini hanya bisa diubah akses penuh.',
                ],
            ],
            [
                'id' => 'garansi-maintenance',
                'title' => 'Garansi dan Maintenance PPF',
                'summary' => 'Dari menerbitkan garansi sampai jadwal maintenance berulang dan kunjungan pelanggan.',
                'menus' => ['WarrantyResource', 'BookingReworkResource'],
                'steps' => [
                    'Garansi dibuat staff atau didaftarkan pelanggan di aplikasi. Garansi dari pelanggan menunggu **review**; saat disetujui, pelanggan mendapat poin registrasi garansi.',
                    'Untuk PPF, isi **Kuota Maintenance** (berapa kali boleh datang) dan **Interval** (jarak bulan antar kunjungan). Sistem menghitung tanggal kunjungan berikutnya sendiri.',
                    'Sekitar 7 hari sebelum jadwal, pelanggan menerima notifikasi **Konfirmasi Kedatangan**. Pelanggan bisa konfirmasi, memilih tanggal lain, atau menolak.',
                    'Staff atau sales boleh menghubungi pelanggan lebih dulu (mis. lewat WhatsApp) lalu memakai aksi **Buatkan Booking** di tab Jadwal Maintenance garansi tersebut.',
                    'Pelanggan yang datang tanpa jadwal dicatat lewat **Catat Kunjungan Maintenance**. Setiap kunjungan mengurangi kuota.',
                    'Klaim after-sales dicatat di tab **Riwayat Klaim After-Sales** pada garansi yang sudah disetujui QA: pilih **Kategori After-Sales** dan tulis keluhan. Akses penuh memutuskan **Pass** atau **Reject** (Reject wajib memakai alasan); pelanggan mendapat notifikasi.',
                    '**Pekerjaan Ulang** (grup Setelah Layanan) mencatat booking yang perlu dikerjakan ulang: pilih **Booking** dan **Sumber**, isi **Apa yang perlu diperbaiki** dan **Catatan pembinaan (opsional)**. Installer yang bertugas pada booking itu otomatis ikut terhitung. Isi **Selesai dikerjakan ulang** saat pekerjaan selesai. Dipakai laporan Kompetensi Installer untuk pembinaan, bukan dasar potongan gaji atau komisi.',
                    'Untuk klaim yang berjalan, isi **Ditangani oleh** (installer) dan **Perkiraan Selesai** lewat Ubah. Perkiraan yang sudah lewat ditandai **Terlambat** di daftar.',
                    'Klaim yang disetujui punya tombol **Bahan Terpakai**: isinya Memo Pengambilan/Pengembalian booking kunjungan klaim itu (barang, diambil, dikembalikan, terpakai). Bila memo belum ada, tautan di dalamnya membuka form memo yang sudah terisi booking, toko, dan kendaraan. Stok berubah lewat memo, bukan dari klaim.',
                    'Setelah ditangani, pakai **Catat Penyelesaian**: pilih **Hasil Penanganan** (Diperbaiki, Diganti atau dipasang ulang, Dana dikembalikan, atau Dibatalkan pelanggan) dan isi catatan. Pelanggan menerima notifikasi bahwa klaimnya sudah ditangani.',
                ],
                'tips' => [
                    "Ada masa toleransi {$graceDays} hari setelah tanggal jadwal. Setelah itu jadwal tidak hangus; pelanggan diminta memilih tanggal baru dan kuota tidak terpakai.",
                    'Jadwal hanya hangus kalau pelanggan menekan "Tidak bisa datang".',
                    'Kunjungan yang salah catat hanya bisa dibatalkan akses penuh; kuota dikembalikan.',
                    'Tab Jadwal Maintenance menunjukkan status tiap kunjungan: Menunggu Jadwal, Menunggu Konfirmasi, Dikonfirmasi, Hangus, Selesai.',
                    'Klaim lama atau yang dibuat manual tanpa booking kunjungan tidak punya tautan memo; tombol Bahan Terpakai hanya menjelaskan hal itu.',
                    'Catat Penyelesaian mengirim notifikasi ke pelanggan dan tidak bisa diulang untuk klaim yang sama, jadi pastikan hasilnya benar.',
                ],
            ],
            [
                'id' => 'reward-voucher',
                'title' => 'Reward dan Voucher',
                'summary' => 'Menyusun katalog tukar poin, termasuk voucher diskon, dan memakai voucher di transaksi.',
                'menus' => ['RewardResource', 'RewardRedemptionResource'],
                'steps' => [
                    'Buka **Katalog Reward** dan buat reward baru. Pilih jenis **Barang/Layanan** (diproses staff) atau **Voucher Diskon**.',
                    'Untuk voucher, isi nominal diskon dan masa berlaku (hari sejak ditukar), lalu harga poin dan stok. Reward langsung muncul di katalog pelanggan.',
                    'Pelanggan menukar poin. Untuk voucher, kode unik (GNV-...) langsung terbit di **Voucher Saya**. Untuk barang, penukaran masuk ke **Klaim Reward** dan menunggu diproses.',
                    'Saat pelanggan memakai voucher, pilih **Kode Voucher** di form **Proses Referral** pada booking (lihat panduan Penjualan). Nominal diskon dipotong dan voucher berstatus Terpakai.',
                ],
                'tips' => [
                    'Voucher melekat ke akun yang menukarnya; tidak bisa dipakai di booking pelanggan lain.',
                    'Voucher yang kedaluwarsa atau sudah dipakai tidak bisa dipilih lagi.',
                    'Penukaran voucher bisa dibatalkan selama belum dipakai (poin dikembalikan). Setelah dipakai atau dibatalkan, statusnya terkunci.',
                    'Jenis reward tidak bisa diubah setelah pernah ditukar. Nonaktifkan reward dengan toggle Aktif, jangan dihapus.',
                ],
            ],
            [
                'id' => 'approval',
                'title' => 'Approval: refund, referral, dan uang muka',
                'summary' => 'Aksi sensitif yang harus disetujui atasan sebelum berlaku.',
                'menus' => ['TransactionApprovalRequestResource', 'FinanceTransactionApprovalRequestResource', 'FinanceTransactionResource', 'FinanceSettingsPage'],
                'steps' => [
                    'Staff mengajukan aksi (refund, Proses Referral, atau Catat Uang Muka). Pengajuan masuk ke **Persetujuan Transaksi** dengan status menunggu.',
                    'Atasan membuka daftar yang sesuai, memeriksa rinciannya, lalu **Setujui** atau **Tolak**.',
                    'Aksi baru dijalankan setelah disetujui. Yang ditolak tidak mengubah data apa pun.',
                ],
                'tips' => [
                    'Refund, referral, dan uang muka dari staff non-akses-penuh wajib disetujui akses penuh.',
                    'Semua keputusan tercatat di Riwayat Aktivitas (siapa dan kapan).',
                ],
            ],
            [
                'id' => 'transaksi-keuangan',
                'title' => 'Transaksi Keuangan: pemasukan dan pengeluaran toko',
                'summary' => 'Mencatat uang masuk dan keluar harian toko, lengkap dengan kategori, rekening, dan bukti.',
                'menus' => ['FinanceTransactionResource', 'FinanceCategoryResource'],
                'steps' => [
                    'Buka Transaksi Keuangan dan buat transaksi baru: pilih **Tipe** (pemasukan atau pengeluaran), **Kategori**, isi **Nominal**, **Toko**, **Rekening Kas/Bank** (dari mana uang keluar atau ke mana uang masuk), **Tanggal Transaksi**, dan **Keterangan**. Lampirkan **Bukti/Nota (opsional)** berupa foto atau PDF maksimal 5 MB.',
                    'Pemasukan langsung dicatat dan menjadi jurnal. Pengeluaran dari staff tanpa akses penuh tidak langsung dicatat: masuk antrean persetujuan (staff, Store Manager, lalu direksi; lihat panduan Approval) dan baru menjadi jurnal setelah disetujui.',
                    'Pengeluaran kecil dari rekening Kas Kecil dicatat dengan memilih rekening itu. Sampai batas per bukti yang diatur di Rekening Kas & Bank, karyawan toko yang sama atau pemegang kas tidak perlu menunggu persetujuan direksi.',
                    'Kolom **No. Jurnal** menunjukkan jurnal dari transaksi itu; **Belum terposting ke jurnal** menandai transaksi yang belum punya jurnal.',
                    'Akses penuh yang mengubah transaksi yang sudah punya jurnal wajib mengisi **Alasan Perubahan**; menghapusnya wajib mengisi **Alasan Penghapusan**. Keduanya tercatat.',
                    'Kategori diatur di **Kategori Keuangan**: tiap kategori bertipe masuk atau keluar dan terhubung ke satu akun di Bagan Akun. Kategori yang belum terhubung ditandai **Belum terhubung akun**. Kategori yang sudah dipakai tidak bisa dihapus; nonaktifkan saja.',
                    'Transaksi serupa dibuat cepat lewat **Duplikat** di baris daftar: form Buat terisi kategori, rekening, toko, nominal, dan keterangan; tanggal diisi hari ini, sedangkan nomor, bukti, dan jurnal tidak ikut. Periksa isinya, lalu tekan Buat.',
                ],
                'tips' => [
                    'Setiap transaksi wajib terikat ke satu toko; Store Manager hanya melihat dan mencatat transaksi tokonya sendiri.',
                    'Kategori nonaktif tidak muncul lagi di transaksi baru, tetapi riwayat lamanya tetap utuh.',
                    'Data identik (nominal, kategori, toko, tanggal, keterangan) dari akun yang sama dalam 10 menit terakhir ditolak, untuk mencegah klik ganda. Cek daftar bila sudah merasa mengirim.',
                    'Pengeluaran dari rekening Kas Kecil ditolak bila nominalnya melebihi saldo kas kecil itu.',
                    'Pencatatan nilai penjualan booking tidak dilakukan di sini; gunakan Proses Referral (lihat panduan Penjualan).',
                    'Duplikat tidak melewati aturan lain: pengeluaran dari staff tetap masuk antrean persetujuan, dan data identik dalam 10 menit tetap ditolak.',
                ],
            ],
            [
                'id' => 'kas-bank',
                'title' => 'Kas dan bank: rekening, pemindahan, hitung kas, rekonsiliasi',
                'summary' => 'Mengatur rekening kas dan bank, memindahkan uang antar rekening, menghitung kas fisik harian, dan mencocokkan mutasi bank dengan jurnal.',
                'menus' => ['PaymentAccountResource', 'CashTransferResource', 'CashCountResource', 'BankStatementLineResource', 'CashBalanceReport'],
                'steps' => [
                    'Buat rekening di **Rekening Kas & Bank**: isi nama, jenis, **Akun di Bagan Akun** (satu akun untuk satu rekening supaya saldonya terpisah), dan toko (kosongkan untuk rekening perusahaan yang dipakai semua toko). **Menerima metode bayar** menentukan pembayaran booking dengan metode mana yang masuk ke rekening itu; rekening toko didahulukan atas rekening pusat. **Rekening bawaan** dipakai bila metode bayar tidak cocok atau tidak diisi (satu per toko dan satu untuk pusat). Rekening tidak dihapus; nonaktifkan.',
                    'Untuk kas kecil isi **Plafon dana** (saldo yang dijaga), **Batas per bukti** (pengeluaran di atas ini tetap lewat persetujuan; kosong berarti semua lewat persetujuan), dan **Pemegang kas**. Aksi **Isi ulang** menambah kas sebesar plafon dikurangi saldo dari rekening yang dipilih di **Diambil dari**.',
                    '**Pemindahan Kas** memindahkan uang antar akun kas atau bank (mis. setor tunai toko ke bank): isi **Dari**, **Ke**, **Nominal**, **Tanggal**, dan **Catatan**. Pemindahan tidak diedit atau dihapus; salah catat dibatalkan lewat **Batalkan** dengan alasan, yang membuat jurnal pembalik.',
                    '**Hitung Kas Harian**: pilih **Kas yang dihitung**, isi **Tanggal hitung** dan **Uang yang dihitung**. Sistem membandingkannya dengan saldo sistem sampai akhir tanggal itu. Bila ada selisih, **Catatan** wajib diisi untuk menjelaskan kemungkinan penyebabnya. Hanya akses penuh yang menjurnalkan selisih lewat **Jurnal selisih** (kekurangan ke akun 7900, kelebihan ke akun 7200). Hitungan tidak diedit atau dihapus.',
                    '**Rekonsiliasi Bank** (akses penuh): unggah mutasi lewat **Import Mutasi** (CSV atau Excel dengan 3 kolom: tanggal, keterangan, nominal; nominal positif uang masuk dan negatif uang keluar; baris pertama dianggap judul). Lalu **Cocokkan Otomatis**; sisanya dicocokkan manual lewat **Cocokkan** ke baris jurnal. Mutasi yang belum ada jurnalnya, seperti biaya admin atau bunga bank, dicatat lewat **Catat sebagai Transaksi** (akses penuh): pilih **Kategori** dan **Toko**, dan sistem langsung membuat transaksinya, jurnalnya, dan kecocokannya. Mutasi yang memang tidak perlu dicocokkan (mis. saldo pembuka atau mutasi ganda) ditandai **Tandai Diabaikan**. Kecocokan atau pengabaian bisa dibatalkan lewat **Batalkan Kecocokan** atau **Batalkan Pengabaian**. Bukti diekspor lewat **Export Bukti Rekonsiliasi**.',
                    '**Saldo Kas & Bank** menampilkan saldo semua akun kas dan bank hari ini dari jurnal yang sudah diposting, beserta rekening dan tokonya. Akun kas yang belum terhubung ke rekening tetap tampil supaya total kas lengkap.',
                ],
                'tips' => [
                    'Rekonsiliasi tidak pernah mengoreksi jurnal. Mutasi bank yang tidak punya jurnal diabaikan dulu, lalu koreksinya dibuat manual di Jurnal Umum supaya jejak audit jelas.',
                    'Pemindahan Kas dan Hitung Kas Harian dapat diberikan ke Spv Finance lewat Hak Akses Detail; Rekening Kas & Bank dan Rekonsiliasi Bank terutama untuk akses penuh.',
                    'Pengeluaran kecil dari kas kecil dicatat di Transaksi Keuangan dengan memilih rekening kas kecilnya.',
                ],
            ],
            [
                'id' => 'jurnal-bagan-akun',
                'title' => 'Jurnal Umum dan Bagan Akun',
                'summary' => 'Membuat dan mengoreksi jurnal manual serta mengelola struktur akun. Hanya akses penuh; staff toko memakai Transaksi Keuangan yang lebih sederhana.',
                'menus' => ['JournalEntryResource', 'ChartOfAccountResource'],
                'steps' => [
                    '**Bagan Akun**: tiap akun punya kode, nama, klasifikasi, dan saldo normal. **Akun Induk (opsional)** hanya bisa memilih akun header dengan klasifikasi yang sama. Matikan **Bisa Diposting Transaksi** untuk akun header (pembungkus seperti "Aset Lancar") yang tidak boleh menerima transaksi langsung.',
                    'Aktifkan **Akun Kas / Bank** untuk akun kas dan setara kas: jurnal yang menyentuhnya dihitung sebagai arus kas. Untuk akun non-kas, pilih **Kategori Arus Kas** (kosong dianggap Operasional). Struktur akun yang sudah dipakai jurnal dikunci.',
                    '**Import Akun** dari Excel atau CSV hanya membuat akun baru; kode yang sudah ada dilewati. Bila ada satu baris yang salah, tidak ada akun yang dibuat. Pakai hasil **Export Excel** sebagai template.',
                    '**Jurnal Umum**: buat jurnal baru dengan **Tanggal**, **Keterangan**, **Toko (opsional)**, lalu baris **Akun**, **Debit**, dan **Kredit**. Minimal 2 baris dan total debit harus sama dengan total kredit. Lampiran (foto atau PDF, maksimal 5 MB) opsional.',
                    'Jurnal disimpan sebagai draf, lalu **Posting**. Jurnal yang sudah diposting terkunci total. Anda tidak bisa memposting jurnal buatan sendiri; harus orang lain yang berwenang.',
                    'Koreksi hanya lewat **Balik Jurnal**: isi **Tanggal Jurnal Pembalik** (tidak boleh sebelum tanggal jurnal asli atau di periode yang sudah ditutup) dan **Alasan Pembalikan**. Jurnal asli tetap tersimpan. Jurnal otomatis dari modul lain (booking, penggajian, dan sebagainya) tidak dibalik di sini, melainkan lewat modul asalnya (**Dibalik lewat modul asal**).',
                ],
                'tips' => [
                    'Jurnal bertanggal di periode yang sudah ditutup tidak bisa dibuat, diubah, atau diposting (lihat panduan Tutup periode).',
                    'Sumber jurnal tampil di daftar: jurnal buatan sistem (booking, penggajian, penyusutan, dan lainnya) berbeda dari jurnal manual.',
                    '**Export Excel** di Jurnal Umum mengikuti rentang tanggal yang dipilih.',
                ],
            ],
            [
                'id' => 'hutang-piutang',
                'title' => 'Hutang, Piutang, dan Tagihan Rutin',
                'summary' => 'Mencatat dan membayar tagihan supplier, memantau umur hutang dan piutang, dan membuat tagihan berulang otomatis.',
                'menus' => ['PayableResource', 'ReceivableResource', 'PayableAgingReport', 'ReceivableAgingReport', 'SupplierResource', 'RecurringBillTemplateResource'],
                'steps' => [
                    'Sebagian besar tagihan di **Hutang Usaha** lahir otomatis dari Permohonan Pembelian yang ditandai Terpenuhi. Tagihan yang tidak lewat permohonan (mis. sewa atau jasa pihak ketiga) dicatat lewat **Catat Tagihan Manual**: isi **Nama Supplier**, **Nominal Tagihan**, **Akun yang Didebit** (akun beban atau aset yang sesuai), **Tanggal Tagihan / Invoice**, dan opsional jatuh tempo serta lampiran. Jurnalnya langsung diposting.',
                    'Bayar tagihan lewat **Catat Pembayaran**: isi **Nominal Dibayar**, **Dibayar dari**, dan **Tanggal Bayar**. Boleh bertahap; sisa tagihan dan statusnya menyesuaikan.',
                    'Koreksi lewat **Batalkan Pembayaran** (jurnal pembayaran dibalik dan tagihan kembali berstatus belum atau sebagian dibayar) atau **Batalkan Tagihan** (jurnal pengakuan dibalik dan tagihan tidak bisa dibayar lagi). Keduanya meminta **Alasan**. Nominal tagihan yang sudah tercatat tidak diedit karena jurnalnya terkunci.',
                    '**Supplier** adalah master yang dipakai tagihan: isi nama, NPWP (15 atau 16 digit tanpa titik atau strip, opsional), kontak, dan rekening pembayaran. Supplier yang sudah punya tagihan tidak bisa dihapus; nonaktifkan, dan supplier nonaktif tidak muncul di pilihan tagihan baru.',
                    '**Template Tagihan Rutin** membuat tagihan berulang otomatis tiap bulan (mis. sewa): isi **Nama Template**, **Supplier / Penerima**, **Akun Beban (Debit)** (kreditnya selalu akun 2110 Hutang Usaha), **Nominal per Periode**, **Tanggal Generate Tiap Bulan** (bila bulan itu tidak punya tanggalnya, jatuh ke tanggal terakhir bulan itu), dan **Mulai Generate Dari**. Opsional: **Berakhir Pada**, **Maksimal Jumlah Tagihan**, dan **Jeda Sampai**. **Jalankan Sekarang** membuat tagihan seketika; **Lewati Bulan Berikutnya** melewatkan satu bulan; nonaktifkan untuk menghentikannya tanpa menghapus riwayat.',
                    '**Umur Hutang** dan **Umur Piutang** mengelompokkan sisa tagihan aktif menurut keterlambatan dari jatuh tempo, per supplier atau per pelanggan. Staff toko otomatis dibatasi ke tokonya; akses penuh bisa memilih toko atau "Pusat / Tanpa Toko". Bisa diekspor ke Excel atau PDF.',
                    'Di atas tabel Umur Piutang ada bagian **Perputaran Piutang dan Hari Penagihan**. Pilih **Periode Perputaran Piutang** (30 sampai 365 hari terakhir): sistem menampilkan **Piutang Terbit**, **Dibayar**, **Rata-rata Piutang**, **Perputaran Piutang**, dan **Hari Penagihan**. Makin kecil Hari Penagihan, makin cepat piutang tertagih.',
                ],
                'tips' => [
                    'Template Tagihan Rutin memposting jurnal otomatis tiap bulan, jadi hanya akses penuh yang membuat atau mengubahnya.',
                    'Template yang belum pernah menghasilkan tagihan cukup dinonaktifkan bila hanya ingin dijeda.',
                    'Piutang Usaha menampilkan sisa tagihan pelanggan yang belum dibayar (lihat panduan Penjualan).',
                    'Untuk template serupa, pakai **Duplikat** di daftar Template Tagihan Rutin: nama diberi "(salinan)"; supplier, toko, akun, nominal, dan tanggal tiap bulan ikut, sedangkan jadwal mulai dan batas diisi baru.',
                ],
            ],
            [
                'id' => 'tutup-periode',
                'title' => 'Tutup periode dan tutup tahun buku',
                'summary' => 'Mengunci bulan yang pembukuannya sudah selesai, dan memindahkan laba tahunan ke Laba Ditahan. Hanya akses penuh.',
                'menus' => ['ClosePeriodPage', 'YearEndClosingPage', 'LedgerReconciliationReport'],
                'steps' => [
                    'Buka **Tutup Periode** dan pilih **Tahun**. Tiap bulan punya **Daftar periksa** dengan dua jenis butir. **Harus diselesaikan dulu (memblokir penutupan)**: jurnal berstatus Draft di bulan itu, dan total debit-kredit jurnal posted yang tidak seimbang. **Perlu diperiksa (peringatan)**: pengajuan pengeluaran atau transaksi yang masih menunggu persetujuan, slip gaji Draft, aset aktif tanpa jurnal penyusutan, mutasi bank yang belum dicocokkan, dan booking selesai yang belum diproses ke pendapatan (bila pengaturan itu aktif).',
                    'Tutup satu bulan. Bulan harus ditutup berurutan dari yang paling lama. Bila ada peringatan, centang pernyataan bahwa Anda sudah memeriksanya; **Catatan penutupan (opsional)** boleh diisi. Setelah ditutup, jurnal bertanggal di bulan itu tidak bisa lagi dibuat, diubah, atau diposting.',
                    '**Tutup Beberapa Bulan** menutup semua bulan terbuka mulai dari yang paling lama sampai **Tutup sampai bulan** yang dipilih, berurutan. Proses berhenti di bulan pertama yang gagal (ada draf, tidak seimbang, atau peringatan belum dikonfirmasi).',
                    'Di atas daftar bulan, bagian **Batas penguncian** menampilkan **Periode tertutup terbaru** (siapa dan kapan menutupnya, sekaligus batas jurnal yang terkunci) dan **Aktivitas terakhir** (tutup atau buka kembali terakhir, oleh siapa).',
                    'Membuka kembali sebuah bulan butuh **Alasan membuka kembali**; keputusannya tercatat. Jurnal di bulan itu bisa dibuat atau diubah lagi sampai ditutup ulang.',
                    '**Tutup Tahun Buku**: setelah tahun berlalu, pilih **Tahun**, baca **Yang akan terjadi**, isi **Catatan**, lalu **Tutup Tahun Buku**. Sistem membuat jurnal penutup bertanggal 31 Desember per toko (dan satu untuk jurnal tanpa toko) yang menolkan akun Laba Rugi ke Laba Ditahan (3200). Syaratnya: tidak ada jurnal Draft di tahun itu dan periode Desember belum ditutup. Jadi tutup tahun dulu, baru tutup Desember.',
                    '**Batalkan Penutupan** (butuh **Alasan**) membalik jurnal penutup. Periode Desember harus terbuka untuk membatalkannya.',
                    '**Rekonsiliasi Sub-Buku** membandingkan saldo akun kontrol di Buku Besar dengan total sub-buku (Piutang, Hutang, DP, Aset Tetap, Persediaan). Selisih perlu dijelaskan akuntan. **Sinkronkan Persediaan** membuat satu jurnal penyesuaian yang menyamakan akun persediaan dengan nilai stok sekarang; pilih akun lawan dengan hati-hati dan minta akuntan memeriksanya.',
                ],
                'tips' => [
                    'Laporan Laba Rugi periode tidak ikut menghitung jurnal penutup tahun, jadi laba tahun itu tetap terlihat.',
                    'Peringatan di checklist boleh diabaikan dengan sadar; butir yang memblokir tidak bisa.',
                    'Di atas setiap halaman Keuangan ada bilah **Status buku**: bulan ini dan bulan lalu, Terbuka atau Ditutup, beserta siapa yang menutup. Jurnal bertanggal di bulan yang Ditutup tidak bisa dibuat.',
                ],
            ],
            [
                'id' => 'anggaran',
                'title' => 'Anggaran dan realisasi',
                'summary' => 'Menetapkan anggaran bulanan per akun beban dan membandingkannya dengan realisasi.',
                'menus' => ['BudgetResource', 'BudgetVsActualReport'],
                'steps' => [
                    'Buka **Anggaran** dan tambah data: pilih **Bulan** (tanggal mana pun di bulan itu), **Toko** (kosongkan untuk seluruh perusahaan), **Akun beban**, dan isi **Anggaran** serta **Catatan**.',
                    'Gunakan **Salin dari bulan lalu** (pilih **Bulan tujuan**) untuk menyalin anggaran bulan sebelumnya.',
                    'Buka **Anggaran vs Realisasi** dan pilih **Bulan** serta **Toko** untuk melihat anggaran tiap akun dibandingkan realisasinya.',
                ],
                'tips' => [
                    'Anggaran hanya peringatan: transaksi yang melebihi anggaran tidak diblokir.',
                    'Menu Anggaran dapat diberikan ke Spv Finance lewat Hak Akses Detail.',
                ],
            ],
            [
                'id' => 'laporan-keuangan',
                'title' => 'Laporan keuangan: cara membaca dan mengekspor',
                'summary' => 'Neraca, Neraca Saldo, Buku Besar, Laba Rugi, Arus Kas, ringkasan bulanan, dan dasbor, semuanya dari jurnal yang sudah diposting.',
                'menus' => ['BalanceSheetReport', 'TrialBalanceReport', 'GeneralLedgerReport', 'IncomeStatementReport', 'MonthlyIncomeStatementReport', 'CashFlowReport', 'FinanceReport', 'FinanceDashboard'],
                'steps' => [
                    '**Neraca**: Aset sama dengan Kewajiban ditambah Modal pada **Per Tanggal** yang dipilih; **Bandingkan Dengan** menampilkan periode pembanding.',
                    '**Neraca Saldo**: saldo tiap akun pada tanggal yang dipilih. Isi **Dari Tanggal (opsional)** untuk melihat Saldo Awal, Mutasi, dan Saldo Akhir. Akun Laba Rugi dihitung sejak 1 Januari tahun laporan.',
                    '**Buku Besar**: rincian tiap baris jurnal pada satu akun dalam rentang tanggal, dengan saldo berjalan. Dipakai untuk mengecek kenapa saldo sebuah akun sebesar itu. **Akun Sebelumnya** dan **Akun Berikutnya** pindah antar akun.',
                    '**Laporan Laba Rugi**: untuk satu rentang periode (bukan kumulatif), dengan **Bandingkan Dengan** untuk periode lain.',
                    '**Laba Rugi Bulanan**: satu tahun penuh dengan satu kolom per bulan dan kolom **Total**, per akun dan per kelompok sampai Laba Bersih. Pilih **Tahun** dan **Toko**; angka tiap bulan sama dengan Laporan Laba Rugi untuk bulan itu. Bisa diekspor; di PDF tabelnya dicetak mendatar dengan huruf kecil.',
                    '**Laporan Arus Kas**: metode langsung, dikelompokkan Operasional, Investasi, dan Pendanaan menurut Kategori Arus Kas tiap akun. **Tampilkan rincian per jurnal** menampilkan jurnal satu per satu.',
                    '**Laporan Keuangan**: ringkasan bulanan pemasukan dan pengeluaran dari Transaksi Keuangan dengan rincian per kategori. **Kelola Widget** memilih akun yang dipin sebagai kartu saldo.',
                    '**Dasbor Keuangan**: kas, laba bulan ini, piutang dan hutang, dan hal yang perlu ditindak dalam satu layar. **Saldo Kas & Bank** dan **Anggaran vs Realisasi** punya panduan sendiri.',
                    'Semua laporan bisa diekspor lewat **Export ke Excel** atau **Export ke PDF**; export tercatat di Riwayat Aktivitas.',
                ],
                'tips' => [
                    'Memilih toko tidak mencakup jurnal pusat (tanpa toko, mis. gaji pusat atau penyusutan). Pilih "Pusat / Tanpa Toko" untuk melihatnya, atau kosongkan pilihan toko untuk semuanya.',
                    'Laporan keuangan menghitung hanya jurnal yang sudah diposting.',
                    'Menu ini terbuka untuk akses penuh; staff lain bisa diberi izin lihat lewat Hak Akses Detail, dan staff yang punya toko dikunci ke tokonya.',
                    'Saldo Kas & Bank, Anggaran vs Realisasi, dan Rekonsiliasi Sub-Buku juga punya tombol **Export ke Excel** dan **Export ke PDF** di kanan atas, mengikuti pilihan bulan dan toko yang sedang tampil. Angka di Excel berupa angka biasa sehingga bisa dijumlahkan.',
                ],
            ],
            [
                'id' => 'stok',
                'title' => 'Stok dan Inventaris',
                'summary' => 'Memantau stok, mengoreksi selisih, dan mencatat barang keluar atau terbuang.',
                'menus' => ['InventoryItemResource', 'RawMaterialResource', 'ConsumableItemResource', 'StockOpnameResource', 'StockWriteOffResource', 'MaterialMemoResource'],
                'steps' => [
                    'Lihat posisi stok di **Produk PPF/WF** (gulungan film), **Daftar Bahan Baku**, dan **Barang Habis Pakai**, serta riwayat pergerakannya di halaman Riwayat (keluar/masuk, bahan baku, barang habis pakai).',
                    'Pengambilan atau pengembalian barang untuk pekerjaan dicatat lewat **Memo Pengambilan/Pengembalian**. Pilih barang dan jumlahnya; stok ikut berubah.',
                    'Barang rusak atau hilang dicatat di **Stok Terbuang** dengan alasan. Stok berkurang dan jejaknya tercatat.',
                    'Hitung fisik berkala lewat **Stok Opname**: isi jumlah hasil hitung, sistem menyesuaikan stok dengan selisihnya.',
                    'Pada data Bahan Baku dan Barang Habis Pakai, isi **Pemasok Utama** (opsional) dengan supplier langganan barang itu. Namanya tampil di peringatan stok menipis dan menjadi isian awal **Supplier** saat permohonan pembelian ditandai terpenuhi.',
                ],
                'tips' => [
                    'Data stok dibatasi per toko; staff toko hanya melihat tokonya sendiri.',
                    'Riwayat pergerakan stok adalah jejak audit; jangan mengedit catatan lama, gunakan aksi koreksi yang tersedia.',
                    'Jangan menghapus memo utuh; hapus baris barang yang salah, atau hubungi admin.',
                    'Memo juga dipakai untuk bahan klaim garansi: dari klaim yang disetujui, tombol **Bahan Terpakai** membuka memo booking kunjungannya, atau membuka form memo baru yang sudah terisi bila belum ada.',
                ],
            ],
            [
                'id' => 'aset-tetap',
                'title' => 'Aset Tetap: kode QR, serah terima, dan penyusutan',
                'summary' => 'Mendaftarkan peralatan dan perlengkapan toko, mencetak kode QR, mencatat serah terima, dan melacak nilai bukunya.',
                'menus' => ['AssetResource'],
                'steps' => [
                    'Tambah aset di **Aset Tetap**: isi **Nama Aset**, **Kategori**, **Status**, **Dipegang Oleh**, **Lokasi (Toko)**, **Tanggal Masuk**, dan **Catatan**. **Kode Aset / QR** dibuat otomatis oleh sistem dan tidak bisa diubah.',
                    'Status aset: **Aktif Dipakai**, **Sedang Diperbaiki**, **Rusak**, **Dijual**, atau **Hilang**. Aset yang rusak, diperbaiki, atau hilang muncul di widget Aset Bermasalah pada Dashboard Inventaris.',
                    'Banyak aset sekaligus: pilih **Download Template**, isi Excel mengikuti formatnya, lalu **Import Excel**. Baris pertama (judul) dilewati. **Dipegang Oleh** dicocokkan persis ke nama user dan **Lokasi** ke nama toko; bila tidak ketemu, kolom itu dikosongkan tetapi asetnya tetap dibuat, jadi periksa hasilnya.',
                    'Cetak label dengan **Download QR** (satu aset) atau **Download QR (PDF Massal)** (beberapa aset terpilih). Tempel label di aset agar bisa dipindai.',
                    'Pindah tangan atau pindah toko dicatat lewat **Serah Terima**: isi **Diserahkan Ke**, **Pindah Ke Toko** (hanya akses penuh), **Kondisi Fisik Saat Ini**, dan **Alasan / Keterangan Serah Terima**. Riwayat serah terima tersimpan sebagai jejak.',
                    'Isi **Jadwal Maintenance Berikutnya** bila aset butuh servis atau kalibrasi berkala; admin diberi tahu otomatis saat jatuh tempo.',
                    'Untuk nilai buku, isi **Tanggal Beli**, **Harga Beli**, **Umur Ekonomis (tahun)**, dan **Nilai Residu** (boleh 0); sistem menghitung **Nilai Buku Saat Ini** dengan garis lurus. Kosongkan semuanya bila aset tidak perlu dilacak nilainya.',
                    '**Lepas Aset** (hanya akses penuh) dipakai untuk aset yang dijual atau hilang atau dibuang. Pilih **Jenis Pelepasan** dan **Tanggal Pelepasan**; bila dijual, isi **Hasil Penjualan**.',
                    'Sistem mengisi status aset (Dijual atau Hilang) beserta tanggal dan hasil pelepasannya. Pembukuan laba atau rugi pelepasan dilakukan di Accurate.',
                ],
                'tips' => [
                    'Data aset dibatasi per toko; staff toko hanya melihat aset tokonya.',
                    'Aset baru yang dibeli lewat Permohonan Pembelian belum otomatis menjadi baris Aset; tambahkan sendiri di sini.',
                    'Satu aset hanya bisa dilepas satu kali, dan tidak bisa dibatalkan dari tombolnya.',
                    'Nilai Buku Saat Ini adalah perkiraan garis lurus dari harga beli, umur ekonomis, dan nilai residu; pembukuan resmi penyusutan dilakukan di Accurate.',
                ],
            ],
            [
                'id' => 'permohonan-pembelian',
                'title' => 'Permohonan Pembelian barang dan aset',
                'summary' => 'Mengajukan pembelian bahan, barang habis pakai, atau aset; persetujuan; sampai ditandai terpenuhi.',
                'menus' => ['PurchaseRequestResource'],
                'steps' => [
                    'Staff membuat permohonan di **Permohonan Pembelian**: pilih **Toko / Workshop**, **Jenis Barang** (Bahan Baku, Barang Habis Pakai, atau Aset Baru), **Barang** (atau **Nama Barang / Aset yang Diminta** untuk aset baru), **Jumlah**, dan **Alasan / Catatan**. Permohonan masih bisa diubah selama statusnya menunggu.',
                    'Hanya akses penuh (super admin atau direksi) yang menekan **Setujui** atau **Tolak** (isi **Alasan Ditolak**). Permohonan yang sudah diproses terkunci; bila dua orang menekan bersamaan, yang kedua mendapat pemberitahuan "sudah diproses".',
                    'Setelah barang diterima, catat dulu masuknya lewat **Catat Stok** pada barangnya, baru tekan **Tandai Terpenuhi**. Aksi ini bisa dilakukan akses penuh atau pengaju permohonan.',
                    'Saat **Tandai Terpenuhi** isi **Supplier** dan **Total Biaya Aktual** bila perlu (keduanya opsional; supplier baru bisa dibuat langsung di sini). Pembukuan pembelian dilakukan di Accurate.',
                    'Permohonan juga bisa dibuat dari peringatan stok menipis di Dashboard Inventaris lewat **Buat Permintaan Beli**. Satu barang hanya punya satu permintaan berjalan; bila barangnya punya **Pemasok Utama**, kolom **Supplier** saat **Tandai Terpenuhi** sudah terisi dengan pemasok itu.',
                    'Saat barang tiba, pakai **Tandai Terpenuhi** dan isi **Jumlah Diterima** (terisi sama dengan jumlah diminta; ubah bila kurang atau lebih). Daftar menampilkan kolom **Diterima** dan, bila kurang, tulisan **Kurang** berikut jumlahnya. Filter **Diterima kurang dari diminta** menampilkan permohonan yang kirimannya kurang; sisanya diajukan sebagai permohonan baru.',
                ],
                'tips' => [
                    'Menandai terpenuhi TIDAK menambah stok; stok hanya bertambah lewat Catat Stok.',
                    'Staff toko hanya melihat permohonan tokonya sendiri.',
                    'Jumlah Diterima dan Total Biaya Aktual hanya catatan pemantauan; tidak ada jurnal atau tagihan yang dibuat di sini.',
                ],
            ],
            [
                'id' => 'riwayat-stok-dashboard',
                'title' => 'Dashboard Inventaris, Daftar Stok, dan riwayat pergerakan',
                'summary' => 'Memantau stok yang perlu perhatian, melihat Awal/Masuk/Keluar/Akhir per periode, dan menelusuri atau membatalkan catatan pergerakan terakhir.',
                'menus' => ['InventoryDashboard', 'StockCardReport', 'InventoryMovementResource', 'RawMaterialMovementResource', 'ConsumableItemMovementResource'],
                'steps' => [
                    '**Dashboard Inventaris** merangkum yang perlu tindakan: **Bahan Baku Perlu Perhatian** (stok di bawah titik pesan ulang, kedaluwarsa dalam 30 hari, atau lama tidak bergerak, 60 hari), **Barang Habis Pakai Perlu Perhatian**, dan **Aset Bermasalah** (rusak, diperbaiki, hilang). Tekan **Tandai Ditinjau** setelah item diperiksa; item muncul lagi bila datanya berubah. Pada barang yang stoknya menipis, tekan **Buat Permintaan Beli**: pilih **Toko / Workshop**, periksa **Jumlah** (terisi selisih ambang dan stok), lalu simpan. Permintaan masuk ke **Permohonan Pembelian** berstatus menunggu, kolom **Permintaan Beli** menampilkan nomornya, dan tombolnya hilang selama permintaan itu masih berjalan.',
                    '**Daftar Stok** (Kartu Stok) menampilkan Awal, Masuk, Keluar, dan Akhir per bahan baku dan barang habis pakai untuk satu rentang tanggal. Atur dengan **Periode Cepat**, **Dari**, **Sampai**, dan **Jenis**. Hasilnya bisa diunduh lewat **Export ke Excel** atau **Export ke PDF**.',
                    '**Riwayat Keluar/Masuk** (produk film), **Riwayat Bahan Baku**, dan **Riwayat Barang Habis Pakai** adalah daftar pergerakan lintas semua barang; saring dengan **Dari Tanggal** dan **Sampai Tanggal**, dan download lewat **Export Excel**. Daftar ini hanya untuk dibaca; catatan baru dibuat dari aksi pada barangnya atau pemindaian QR di aplikasi.',
                    'Salah catat? Akses penuh bisa menekan **Batalkan** pada baris tersebut. Stok kembali ke sebelumnya dan baris "Koreksi" ditambahkan sebagai jejak. Pembatalan hanya bisa untuk kejadian PALING TERAKHIR barang itu.',
                    'Pada riwayat bahan baku dan barang habis pakai, **Cek Rekonsiliasi** memeriksa apakah stok semua barang cocok dengan penjumlahan riwayat pergerakannya; selisih berarti ada data yang perlu ditelusuri.',
                ],
                'tips' => [
                    'Stok pada Daftar Stok masih nasional (belum per toko). "Akhir" dihitung mundur dari stok saat ini, bukan dari foto stok harian.',
                    'Riwayat pergerakan adalah jejak audit; koreksi lewat Batalkan, jangan mengubah catatan lama.',
                ],
            ],
            [
                'id' => 'katalog-produk',
                'title' => 'Katalog produk, harga, dan resep bahan',
                'summary' => 'Mengatur produk film yang dijual: harga per ukuran kendaraan, harga khusus per grup pelanggan, import harga massal, dan resep bahan standar.',
                'menus' => ['FilmProductResource', 'MasterResepResource', 'ProductImportLogResource', 'ScheduledPriceChangeResource'],
                'steps' => [
                    'Di **Daftar Produk** isi **SKU**, **Nama Produk**, **Tipe Produk**, dan **Posisi Kaca**. Harga bisa flat lewat **Harga Jual (Dasar / Flat)** (satu harga untuk semua ukuran, mis. Panoramic) atau berbeda per ukuran lewat bagian **Harga Jual per Ukuran Kendaraan** (pilih **Ukuran Kendaraan** lalu **Harga Jual**). Bila kedua-duanya diisi, harga per ukuran yang dipakai.',
                    'Harga khusus untuk grup pelanggan tertentu (mis. Member atau Korporat) diisi di bagian **Harga Khusus per Grup Pelanggan**: pilih **Grup Pelanggan**, lalu harganya. Pelanggan harus sudah dimasukkan ke grupnya (lihat panduan Pelanggan).',
                    '**Aktif (tampil di pilihan quotation)** menentukan apakah produk muncul di pilihan quotation. Matikan untuk produk yang sudah tidak dijual; riwayat lama tetap utuh. Produk yang masih dipakai kode gulungan tidak bisa dihapus: nonaktifkan saja, atau habiskan dulu gulungannya.',
                    '**Wajib Lacak Roll (Batch Number)**: bila aktif, booking dengan produk ini tidak bisa ditandai selesai sebelum staff mencatat gulungan yang dipakai lewat Catat Pemakaian di menu Produk PPF/WF.',
                    'Harga massal: download dengan **Download Template Import**, isi Excel, lalu **Import Excel**. Kolom kosong tidak mengubah data yang ada, dan SKU yang tidak ditemukan dilewati (import tidak membuat produk baru). Hasil tiap import tercatat di **Riwayat Import Produk**; tekan **Lihat Detail** untuk membaca baris yang dilewati atau error.',
                    '**Master Resep** mencatat bahan standar untuk satu kali pemasangan tiap produk (pilih produk, lalu isi **Bahan** dan **Jumlah** di bagian **Bahan Standar per 1x Pemasangan**). Resep hanya berisi kuantitas, bukan harga atau HPP, dan dipakai sebagai acuan perkiraan bahan serta perencanaan stok. Produk dibuat di Daftar Produk; Master Resep hanya mengisi resepnya.',
                    'Daftar Produk bisa diekspor lewat **Export ke Excel** atau **Export ke PDF**.',
                    '**Perubahan Harga Terjadwal** (hanya akses penuh) menaikkan atau menurunkan harga banyak produk sekaligus pada tanggal tertentu. Isi **Nama**, **Berlaku Mulai**, **Persen** (positif naik, negatif turun), dan **Pembulatan**; pilih **Produk** tertentu atau kosongkan untuk semua produk film aktif. Bagian **Pratinjau** menunjukkan contoh harga lama dan baru sebelum disimpan.',
                    'Pada tanggal berlaku, harga berubah otomatis pada dini hari: harga dasar, harga per ukuran kendaraan, dan harga khusus per grup bila **Ikut ubah harga khusus per grup pelanggan** dinyalakan. **Terapkan Sekarang** menerapkannya langsung bila tanggalnya sudah tiba; **Batalkan** membatalkan yang masih terjadwal.',
                ],
                'tips' => [
                    'Ubah harga lewat Daftar Produk atau import; booking yang sudah dibuat tidak ikut berubah harganya.',
                    'Riwayat Import Produk hanya untuk dibaca dan tidak bisa diubah.',
                    'Booking, SPK, dan invoice yang sudah dibuat menyimpan harganya sendiri dan tidak ikut berubah. Harga lama dan baru tiap produk tercatat di riwayat aktivitas.',
                    'Perubahan yang sudah berlaku tidak bisa dibatalkan. Untuk mengembalikan, buat perubahan baru dengan persen kebalikannya; hasilnya bisa berselisih sedikit karena pembulatan.',
                ],
            ],
            [
                'id' => 'pelanggan',
                'title' => 'Daftar Pelanggan dan Grup Pelanggan',
                'summary' => 'Melihat dan melengkapi data pelanggan, memasukkan pelanggan ke grup untuk harga khusus, mencatat referral, dan menghapus data atas permintaan pelanggan.',
                'menus' => ['CustomerResource', 'CustomerGroupResource'],
                'steps' => [
                    'Cari pelanggan di **Daftar Pelanggan** lewat **Nama**, **Email**, atau **No. WhatsApp**. Kolom **Jumlah Booking** dan **Jumlah Garansi** memberi gambaran riwayatnya; **Setuju Promo** menunjukkan apakah ia bersedia menerima promosi.',
                    'Data tambahan seperti **Jenis Kelamin** dan **Alamat** diisi admin lewat aksi **Data Pribadi** di daftar.',
                    '**Grup Pelanggan** mengelompokkan pelanggan untuk harga khusus produk. Buat grup dengan **Nama Grup**, **Deskripsi (opsional)**, dan **Urutan** (angka kecil tampil lebih dulu). Nonaktifkan grup agar tidak muncul sebagai pilihan baru tanpa mengubah pelanggan yang sudah memakainya; grup yang masih dipakai pelanggan tidak bisa dihapus.',
                    'Masukkan banyak pelanggan sekaligus ke grup: cari atau saring dulu, pilih beberapa baris, lalu **Atur Grup Pelanggan**. Harga khusus per grup diisi di Daftar Produk.',
                    'Pelanggan datang lewat ajakan teman atau mitra? Pakai **Set Referral**. Ini hanya penanda; poin baru diberikan lewat Proses Referral di Booking setelah pelanggan itu booking dan selesai.',
                    'Daftar bisa diunduh lewat **Export Excel**.',
                    'Penghapusan memakai **Hapus**: nama, email, dan nomor WhatsApp dihapus PERMANEN (dianonimkan) dan tidak bisa dipulihkan, sedangkan riwayat booking dan garansi tetap tersimpan tanpa identitas. Lakukan hanya atas permintaan pelanggan atau kebijakan perusahaan.',
                ],
                'tips' => [
                    'Hapus massal juga bersifat permanen; periksa pilihan baris sebelum konfirmasi.',
                ],
            ],
            [
                'id' => 'poin-promo',
                'title' => 'Poin pelanggan dan partner, serta promo total pembelian',
                'summary' => 'Membaca dan mengoreksi buku poin, serta membuat potongan harga berdasarkan total pembelian.',
                'menus' => ['PointTransactionResource', 'PartnerPointTransactionResource', 'SpendPromoResource'],
                'steps' => [
                    '**Riwayat Poin Pelanggan** adalah buku besar poin pelanggan: tiap baris mencatat **Pelanggan**, **Tipe** (Dapat Poin atau Pakai Poin), **Jumlah Poin**, **Sumber** (booking, ajak teman, registrasi garansi, tukar reward, dan lain-lain), dan **Keterangan**. Saring berdasarkan tipe atau sumber untuk menelusuri.',
                    'Baris yang sudah tersimpan tidak bisa diubah atau dihapus. Bila ada kesalahan atau perlu pemberian khusus (mis. bonus permintaan maaf), akses penuh mencatat entri baru: pilih **Pelanggan**, **Tipe**, **Jumlah Poin**, dan isi **Keterangan** yang jelas. Entri koreksi inilah jejak auditnya.',
                    '**Riwayat Poin Partner** bekerja sama: entri baru bisa dicatat, tetapi tidak bisa diubah atau dihapus. Setiap entri langsung menambah atau mengurangi saldo poin partner.',
                    '**Promo Total Pembelian** membuat potongan flat Rupiah untuk booking yang nilai kotornya mencapai ambang. Isi **Nama Promo**, **Minimal Pembelian (kotor)**, **Potongan Harga**, **Mulai Berlaku**, **Berakhir** (kosong berarti tanpa batas), dan **Deskripsi**. Matikan **Aktif** untuk menghentikan.',
                    'Promo ini diterapkan MANUAL oleh staff di form Booking (kolom Promo Total Pembelian); sistem tidak memasangnya otomatis.',
                ],
                'tips' => [
                    'Poin belum punya masa kedaluwarsa; pemberian bonus perlu terukur.',
                    'Kolom **Dipakai** di Promo Total Pembelian menunjukkan seberapa sering promo diterapkan.',
                ],
            ],
            [
                'id' => 'analisa-laporan',
                'title' => 'Analisa: kepuasan, waktu teramai, dan perputaran stok',
                'summary' => 'Empat laporan analisa untuk membaca review pelanggan, hari tersibuk, dan seberapa cepat stok terpakai.',
                'menus' => ['CustomerSatisfactionReport', 'PeakProductTimeReport', 'PeakSalesTimeReport', 'StockTurnoverReport'],
                'steps' => [
                    'Semua laporan memakai **Periode Cepat** atau **Dari** dan **Sampai**; sebagian punya **Toko**. Hasil bisa diunduh lewat **Export ke Excel** atau **Export ke PDF**.',
                    '**Kepuasan Pelanggan** merangkum review toko per toko: **Total Review**, **Positif**, dan **Negatif**. Dasarnya review yang diisi pelanggan setelah booking selesai.',
                    '**Waktu Teramai Penjualan** menunjukkan hari dalam seminggu yang paling ramai: **Penjualan (Rp)**, **Transaksi**, **Produk**, dan **Pelanggan** (pelanggan unik per hari). Hari dihitung dari tanggal pendapatan tercatat.',
                    '**Waktu Teramai Produk** memecahnya per produk dan hari. Booking yang belum diisi SKU-nya masuk ke baris "Belum Diisi SKU", jadi lengkapi SKU agar angkanya akurat.',
                    '**Perputaran Stok** menunjukkan kecepatan pemakaian bahan baku dan barang habis pakai: **Terpakai**, **Rata-rata Stok**, **Perputaran Stok**, **Konsumsi/Hari**, dan **Estimasi Habis**. Rata-rata stok disederhanakan dari stok awal dan akhir periode; stok awal dihitung mundur dari stok saat ini.',
                ],
                'tips' => [
                    'Laporan ini dihitung dari data yang tercatat, jadi booking yang belum diselesaikan atau belum dicatat pendapatannya tidak ikut terhitung.',
                ],
            ],
            [
                'id' => 'laporan-penjualan',
                'title' => 'Laporan penjualan, refund, dan pembatalan',
                'summary' => 'Membaca ringkasan penjualan, tren per periode, per outlet, per jasa dan produk, uang masuk, refund, dan booking yang dibatalkan.',
                'menus' => ['SalesSummaryReport', 'SalesByPeriodReport', 'SalesByOutletReport', 'LayananReport', 'JenisOrderReport', 'ProductSalesReport', 'PaymentBookReport', 'RefundReport', 'VoidReport'],
                'steps' => [
                    'Cara umum: pilih rentang lewat **Periode Cepat** atau **Dari** dan **Sampai**, lalu **Toko** atau **Toko** bila tersedia. Staff toko hanya melihat tokonya; pilihan toko khusus akses penuh. Hampir semua laporan bisa diunduh lewat **Export ke Excel** atau **Export ke PDF**.',
                    'Penjualan dihitung dari booking yang penjualannya sudah diproses lewat Proses Referral (tanggal penjualannya tercatat). Booking yang selesai tetapi belum diproses tidak ikut; **Ringkasan Penjualan** menampilkan peringatan jumlah booking seperti itu dengan tautan langsung ke daftarnya.',
                    '**Ringkasan Penjualan** menyusun pendapatan sampai penjualan bersih dan laba kotor. Pakai **Bandingkan Dengan** (periode sebelumnya yang sama panjang, atau periode yang sama tahun lalu) untuk melihat naik turunnya.',
                    '**Penjualan Per Periode** menampilkan satu baris per periode untuk melihat tren; atur dengan **Kelompokkan Per** (Harian, Mingguan, atau Bulanan). Kolomnya: **Transaksi**, **Penjualan**, **Diterima**, **Piutang**, **Pengembalian**, **Komisi**, **HPP**, dan **Laba Kotor**.',
                    '**Penjualan Outlet** membandingkan toko: **Penjualan (bersih)**, **Pengembalian**, **Rata-rata/Transaksi**, dan **Piutang** per outlet.',
                    '**Laporan Jasa** merekap per jenis layanan (jumlah dan pendapatan, juga per toko); **Laporan Jenis Order** menampilkan data yang sama dengan nama menu yang berbeda. **Penjualan Produk** merinci per SKU: **Jumlah**, **Penjualan**, **Refund**, **Komisi**, **HPP**, dan **Laba Kotor**. Booking yang belum diisi SKU-nya terkumpul di baris "Belum Diisi SKU", jadi lengkapi SKU agar angkanya akurat.',
                    '**Buku Pembayaran** mendaftar semua uang masuk dari booking dalam satu rentang: uang muka (DP) dan pembayaran saat penjualan diproses, lengkap dengan **Metode**. Laporan ini hanya membaca dan tidak menyalin data.',
                    '**Laporan Refund** mendaftar refund (parsial boleh) dengan **No. Refund**, **Metode Pembayaran**, dan **Alasan**. Refund diproses lewat Proses Refund di Booking, bukan di sini.',
                    '**Laporan Void** merekap booking yang dibatalkan beserta **Dibatalkan Oleh** dan **Alasan**. Data lama tanpa alasan tercatat memakai pelaku log sebagai pengganti.',
                ],
                'tips' => [
                    'Selisih antara laporan sering berasal dari perbedaan dasar: laporan penjualan berbasis tanggal pendapatan, sedangkan Reservasi berbasis tanggal jadwal pemasangan.',
                    'Biaya yang tidak pernah dicatat sistem (mis. ongkos kirim atau biaya platform) tidak muncul di Ringkasan Penjualan.',
                ],
            ],
            [
                'id' => 'laporan-operasional',
                'title' => 'Laporan reservasi, kapasitas, durasi kerja, dan nomor seri',
                'summary' => 'Membaca jadwal dan kapasitas toko, kecepatan konfirmasi, durasi pengerjaan, serta jejak pemakaian gulungan film.',
                'menus' => ['ReservationReport', 'ReservationUtilizationReport', 'BayZoneUtilizationReport', 'BookingServiceLevelReport', 'JobDurationReport', 'JobDurationByServiceReport', 'FixedCommissionReport', 'SerialNumberReport'],
                'steps' => [
                    '**Laporan Reservasi** mendaftar jadwal pemasangan berdasarkan **Tanggal Diinginkan**, termasuk yang masih menunggu konfirmasi dan belum tentu menjadi pendapatan. Saring dengan **Status**, **Periode Cepat**, dan **Toko**.',
                    '**Laporan Reservasi & Utilisasi** menghitung per toko: **Kapasitas/Hari**, **Total Kapasitas**, **Terpakai**, **Kosong**, **Dibatalkan**, **Tingkat Pembatalan**, dan **Utilisasi**. Kapasitas diambil dari pengaturan toko di Toko/Dealer.',
                    '**Laporan Utilisasi Zona/Bay** menghitung pemakaian zona kerja dari durasi NYATA booking di tahap-tahapnya (**Jam Tersedia**, **Jam Terpakai**, **Utilisasi**, **Peak Bersamaan**). Pilih satu toko dulu; toko atau zona yang belum diisi jumlah slotnya ditandai "Belum dikonfigurasi" dan dilewati. Berbeda dari Laporan Reservasi & Utilisasi, yang hanya menghitung jumlah booking per hari.',
                    '**Laporan SLA & Tidak Datang** mengukur kecepatan konfirmasi dibanding target, konversi dari menunggu ke terkonfirmasi, tingkat tidak datang, serta pembatalan beserta alasannya. Waktu tunggu hanya dihitung untuk booking yang tercatat waktunya.',
                    '**Proses Order** menampilkan durasi tiap pekerjaan (satu SPK) dengan **Installer** dan **Jenis Layanan**. **Proses Produk** merangkumnya per jenis layanan: **Jumlah Job**, **Rata-rata**, **Tercepat**, dan **Terlama**. Dipakai untuk menilai waktu standar, bukan untuk menghitung komisi.',
                    '**Komisi Tetap** menampilkan komisi installer per periode: **Jumlah Pekerjaan**, **Penjualan**, **Skema Tarif**, dan **Total Komisi**. Komisi Ginnva berupa nominal tetap per pekerjaan; komisi bertingkat berdasarkan volume tidak ada. Nominal komisi diatur di data Installer.',
                    '**Laporan Serial Number** menelusuri gulungan film PPF dan kaca film: **Kode Serial**, **Produk**, **Panjang Total**, **Sisa Panjang**, tanggal alokasi dan habis, serta riwayat **Meter Dipakai** per pemakaian. Saring dengan **Status**.',
                ],
                'tips' => [
                    'Ubah kapasitas dan jumlah slot di Toko/Dealer; laporan kapasitas tidak berarti bila angkanya belum diisi.',
                    'Durasi pekerjaan bergantung pada pencatatan tahap oleh installer; tahap yang tidak dicatat membuat durasi tidak lengkap.',
                ],
            ],
            [
                'id' => 'laporan-pelanggan-poin',
                'title' => 'Laporan pelanggan, poin, dan promo',
                'summary' => 'Membaca perilaku belanja pelanggan, pergerakan poin, dan performa voucher serta reward.',
                'menus' => ['CustomerReport', 'PointReport', 'PromoLoyaltyReport', 'CouponReport'],
                'steps' => [
                    '**Laporan Pelanggan** membandingkan pelanggan baru dan berulang untuk periode terpilih: **Booking (Periode Ini)**, **Belanja (Periode Ini)**, **Total Belanja (Sepanjang Waktu)**, **Kunjungan Terakhir**, dan rata-rata per bulan. Hanya pelanggan yang punya akun yang dihitung.',
                    '**Laporan Poin** merinci harian: **Poin Didapat**, **Poin Ditukar**, dan nilai Rupiahnya. Laporan ini nasional, tidak dipisah per toko.',
                    '**Laporan Promo** memuat performa Promo Total Pembelian dan potongan yang diterapkan, voucher (**Diklaim**, **Dipakai**, **Tingkat Pakai**, **Sisa Stok**), serta reward (**Ditukar**, **Terpenuhi**, **Poin Terpakai**). **Laporan Voucher** menampilkan data voucher yang sama; "voucher" di Ginnva adalah voucher.',
                ],
                'tips' => [
                    'Poin belum punya masa kedaluwarsa, jadi saldo yang beredar terus bertambah; pantau lewat Laporan Poin.',
                ],
            ],
            [
                'id' => 'laporan-persediaan',
                'title' => 'Laporan persediaan dan stok kedaluwarsa',
                'summary' => 'Melihat nilai stok per barang, rincian pergerakan dalam periode, dan bahan yang mendekati kedaluwarsa.',
                'menus' => ['PersediaanRingkasanReport', 'PersediaanDetailReport', 'ExpiringStockReport'],
                'steps' => [
                    '**Lap. Ringkasan Persediaan** menilai stok saat ini per barang: **Kuantitas**, **Satuan**, **Harga Modal**, dan **Total Nilai Persediaan**. Ini posisi hari ini, bukan per tanggal tertentu.',
                    '**Lap. Detail Persediaan** merinci pergerakan stok bahan baku dan barang habis pakai dalam rentang **Dari** dan **Sampai**: **Jenis**, **Jumlah**, **Harga Beli**, **Oleh**, dan **Catatan**.',
                    '**Laporan Stok Kedaluwarsa** mendaftar batch bahan baku dengan **Tanggal Kedaluwarsa** di rentang yang dipilih: **Sisa Qty**, **Nilai**, dan **Status**. Berbeda dari widget Dashboard Inventaris yang hanya menunjukkan 30 hari ke depan.',
                ],
                'tips' => [
                    'Laporan persediaan bersifat nasional (tidak dibatasi per toko). Untuk selisih stok, bandingkan dengan Daftar Stok dan Cek Rekonsiliasi (lihat panduan Dashboard dan riwayat stok).',
                ],
            ],
            [
                'id' => 'laporan-karyawan',
                'title' => 'Laporan absensi dan laporan karyawan',
                'summary' => 'Membaca kehadiran harian dan ringkasan bulanan per karyawan.',
                'menus' => ['AttendanceReport', 'EmployeeReport'],
                'steps' => [
                    '**Absensi** (laporan) menampilkan kehadiran harian mentah: **Absen Masuk**, **Absen Keluar**, **Terlambat**, **Pulang Cepat**, dan **Jenis**, dengan ringkasan keterlambatan di atasnya. Saring dengan **Periode Cepat**, **Dari**, **Sampai**, dan **Toko**. Koreksi absensi yang disetujui ikut tampil.',
                    '**Laporan Karyawan** adalah ringkasan BULANAN dari Penggajian: **Hari Kerja**, **Telat (Menit)**, **Alpha (Hari)**, **Gaji Bersih**, dan **Status**. Pilih **Bulan** dan **Toko**. Datanya baru ada setelah payroll bulan itu digenerate.',
                ],
                'tips' => [
                    'Untuk membuat atau memperbaiki data dasarnya, lihat panduan Absensi Karyawan dan Penggajian bulanan.',
                ],
            ],
            [
                'id' => 'lead-inquiry-kemitraan',
                'title' => 'Inquiry produk, kemitraan, partner, dan Dasbor Lead',
                'summary' => 'Menindaklanjuti calon pelanggan dan calon mitra dari website dan aplikasi, mencatat hasilnya, mengelola partner, dan membaca kecepatan respons.',
                'menus' => ['ProductInquiryResource', 'PartnershipInquiryResource', 'PartnerResource', 'LeadFunnelReport'],
                'steps' => [
                    'Inquiry masuk dari formulir website atau aplikasi sebagai **Inquiry Produk** (lead berstatus baru). Lead dari WhatsApp, telepon, atau datang langsung dicatat staff lewat **Catat Lead Manual**; kanalnya dipilih di **Kanal Masuk**.',
                    'Buka lead, isi **Penanggung Jawab**, hubungi calon pelanggan, lalu ubah **Status Follow-up**. Catat perkembangan di **Catatan Internal Sales**. Waktu respons pertama tercatat otomatis saat status pertama kali berubah dari baru.',
                    'Setelah ada kepastian, isi **Hasil**: Deal bila lead sudah jadi pelanggan, booking, atau partner; Tidak Jadi bila batal. Untuk Tidak Jadi, pilih **Alasan Tidak Jadi** agar laporan bisa mengelompokkan sebabnya.',
                    '**Kemitraan & Sales Referral** berisi pengajuan jadi mitra dari website. Penanganannya sama: **Penanggung Jawab**, **Status**, **Hasil**, dan **Alasan Tidak Jadi**. Untuk pengajuan yang disetujui, tekan **Jadikan Partner**, lalu isi **Nama Partner / Usaha**, **Email (untuk login)** (wajib bila pemohon tidak mencantumkan email), dan **Password Awal**. Password awal disampaikan ke partner secara manual; ia bisa menggantinya lewat fitur lupa password di aplikasi.',
                    '**Partner** mendaftar semua mitra. **Kode Referral** dibuat otomatis dan dibagikan partner ke kenalannya. **Download QR** (atau **Download QR (PDF Massal)**) menghasilkan kode QR menuju halaman pendaftaran partner. Isi **Sumber Pendaftaran** agar QR memakai landing page yang tepat; bila kosong, dipakai halaman partner umum. **Saldo Poin** diperbarui dari buku poin partner.',
                    '**Dasbor Lead** merangkum corong lead dari quotation, inquiry produk, dan kemitraan dalam rentang **Dari** dan **Sampai** (saring dengan **Jenis Lead**): berapa lead masuk, sudah direspons, median waktu respons, berapa dalam target, deal, tidak jadi, masih terbuka, dan tingkat deal. Rinciannya per jenis lead, per kanal, dan per kampanye (utm_campaign), ditambah alasan tidak jadi.',
                ],
                'tips' => [
                    'Lead baru yang belum ditindak melewati target (bawaan 24 jam) memicu pengingat harian ke penanggung jawab, atau ke staff yang punya akses menu bila belum ada penanggung jawab.',
                    'Lead lama sebelum fitur ini belum punya waktu respons, jadi tampil sebagai belum ditindak sampai statusnya diperbarui. Rapikan lead lama agar pengingat tidak ramai.',
                    'Lead dari website dan aplikasi mengisi kanal dan kampanye otomatis; lead manual hanya punya kanal.',
                ],
            ],
            [
                'id' => 'konten-beranda',
                'title' => 'Konten website dan aplikasi: banner, seri produk, berita, lowongan, materi',
                'summary' => 'Mengelola konten yang tampil ke pelanggan dan publik: banner, kartu seri produk di beranda, berita, lowongan kerja, dan materi unduhan.',
                'menus' => ['CarouselResource', 'FeaturedProductResource', 'NewsResource', 'JobOpeningResource', 'MaterialResource', 'MaterialCategoryResource'],
                'steps' => [
                    '**Banner / Carousel**: unggah **Gambar Banner** (maksimal 3 MB, rasio ideal 2:1, mis. 1200 x 600 px) dan wajib isi **Teks Alternatif Gambar** untuk pembaca layar. **Judul (opsional)**, **Sub-judul (opsional)**, dan **Link (opsional)** (harus diawali http:// atau https://) bersifat tambahan. **Tampil Untuk** menentukan audiens, dan **Urutan** mengatur susunan. Atur **Mulai Tayang (opsional)** dan **Berhenti Tayang (opsional)** supaya banner tayang dan berhenti sendiri; kosongkan untuk tayang langsung atau tanpa batas. Kolom **Klik** dan **Status Tayang** membantu menilai banner.',
                    '**Seri Produk (Beranda)** mengatur kartu di beranda aplikasi: **Gambar Beranda (Thumbnail)** (maksimal 3 MB, ukuran contoh 800 x 1000 px) dan **Gambar Isi (Detail)** yang tampil utuh saat kartu diketuk (kosongkan untuk memakai gambar beranda). **Link (opsional)** dipakai tombol "Lihat Selengkapnya". Matikan **Tampilkan** untuk menyembunyikan tanpa menghapus.',
                    '**Berita**: isi **Judul**, **Slug**, **Ringkasan Singkat**, dan **Isi Berita**, unggah **Gambar Cover** (maksimal 2 MB) dengan **Teks Alternatif Cover**. Untuk Google, isi **Judul untuk Google (opsional)** (maksimal 70 karakter) dan **Deskripsi untuk Google (opsional)** (maksimal 170 karakter); kosong berarti memakai judul dan ringkasan. Berita yang hanya tautan keluar memakai **Link Sumber**. Terbitkan lewat **Publikasikan** dan **Tanggal Publish**.',
                    '**Lowongan Kerja**: isi **Nama Posisi**, **Departemen**, **Lokasi**, **Tipe**, **Deskripsi Pekerjaan**, dan **Kualifikasi** (satu baris satu poin). **Tanggal Terbit** dipakai Google Jobs sebagai tanggal dibuka. **Berlaku Sampai (opsional)** membuat lowongan hilang sendiri dari website dan Google setelah lewat; kosongkan bila belum ada batas. Matikan **Tampilkan di Website** untuk menyembunyikan tanpa menghapus.',
                    '**Materi Download** menyimpan berkas untuk diunduh (PDF, ZIP, JPG, PNG, PPTX, maksimal 50 MB) lewat **Upload File**, di bawah sebuah **Kategori**. **Tampilkan ke Publik** menentukan apakah tampil di website. **Kategori Materi** mengatur nama dan **Urutan Tampil** kategori; kategori yang masih berisi materi tidak bisa dihapus, pindahkan atau hapus materinya dulu.',
                ],
                'tips' => [
                    'Banner, berita, dan lowongan lama tanpa jadwal tetap tayang seperti sebelumnya.',
                    'Teks alternatif membantu pembaca layar dan mesin pencari; jangan dikosongkan.',
                ],
            ],
            [
                'id' => 'galeri-izin-foto',
                'title' => 'Galeri pemasangan, galeri pelanggan, dan izin foto',
                'summary' => 'Menayangkan foto hasil kerja sebagai materi pemasaran hanya dengan izin pelanggan, dan menarik izin bila diminta.',
                'menus' => ['CaseStudyResource', 'CustomerGalleryPhotoResource', 'MarketingConsentResource'],
                'steps' => [
                    'Izin foto direkam saat serah terima: pelanggan memberi izin lewat kotak opt-in, dan bawaannya TIDAK ada izin. **Izin Foto Pelanggan** menampilkan semua izin (**Booking**, **Pelanggan**, **Kendaraan**, **Diberikan**, **Status**) dan hanya untuk dibaca.',
                    'Pelanggan meminta izin ditarik? Tekan **Tarik Izin** (isi **Catatan (opsional)**). Materi galeri dari booking itu langsung dinonaktifkan. Foto yang sudah telanjur dibagikan di luar sistem perlu ditarik manual.',
                    'Di **Galeri Pemasangan** tekan **Nominasikan dari Booking** untuk menyiapkan materi. Hanya booking yang pelanggannya memberi izin dan sudah punya foto akhir serah terima yang bisa dipilih; pilih juga **Foto sebelum (opsional)**. Materi dibuat dalam keadaan TIDAK tayang.',
                    'Lengkapi materi: **Judul Lengkap**, **Judul Singkat**, **Merek Mobil**, **Tipe Mobil**, **Produk Film** (kosongkan untuk Detailing dan Premium Wash), dan **Teks Alternatif Foto**. Centang **Plat nomor tertutup stiker di foto** HANYA bila plat memang tertutup; bila masih terlihat, unggah foto lain. Foto maksimal 2 MB (JPG, PNG, WebP).',
                    'Materi dari booking hanya bisa tayang bila izin pelanggan aktif, plat tertutup stiker di foto, dan teks alternatif terisi. Setelah itu aktifkan **Tampilkan di Website**.',
                    '**Galeri Pelanggan** berisi foto hasil terbaik per pelanggan yang tampil di beranda aplikasi pelanggan tersebut: pilih **Pelanggan**, unggah **Foto** (maksimal 5 MB), isi **Keterangan (opsional)**, dan atur **Urutan Tampil**. Matikan **Tampilkan di Beranda** untuk menyembunyikan tanpa menghapus.',
                ],
                'tips' => [
                    'Penarikan izin tidak bisa membatalkan foto yang sudah dipakai di luar sistem; catat di mana saja foto pernah dipakai.',
                ],
            ],
            [
                'id' => 'klaim-bukti',
                'title' => 'Klaim & Bukti: arsip angka produk',
                'summary' => 'Mencatat angka yang dipakai dalam pemasaran (TSER, UV, VLT, garansi) beserta buktinya, supaya perusahaan terlindungi bila diragukan.',
                'menus' => ['ProductClaimResource'],
                'steps' => [
                    'Staff menyusun klaim di **Klaim & Bukti**: isi **Seri / Produk** (mis. A70, H70, atau "Umum" untuk yang berlaku ke semua), **Jenis Klaim**, **Teks yang Tampil**, dan **Nilai** persis seperti yang akan dipakai (mis. "61%" atau "10 tahun").',
                    'Lampirkan bukti lewat **Berkas Bukti (laporan uji / sertifikat)** (PDF, JPG, atau PNG, maksimal 5 MB), atau isi **Catatan Sumber** bila tidak ada berkas, serta **Tanggal Uji / Dokumen**. Angka hanya bisa disetujui bila ada berkas bukti atau catatan sumber, dan tanggalnya jelas.',
                    'Hanya akses penuh yang menekan **Setujui**; angka itu dicatat sebagai terbukti dan boleh dipakai di materi pemasaran. **Tarik** menandai angka tidak boleh lagi dipakai. Mengubah klaim yang sudah disetujui mengembalikannya ke draft.',
                ],
                'tips' => [
                    'Menu ini arsip internal: menyetujui atau menarik klaim TIDAK mengubah angka yang tampil di website, yang tetap mengikuti kodenya.',
                ],
            ],
            [
                'id' => 'absensi',
                'title' => 'Absensi Karyawan',
                'summary' => 'Karyawan absen lewat aplikasi staff; atasan memeriksa dan menyetujui koreksi.',
                'menus' => ['AttendanceResource', 'AttendanceCorrectionRequestResource'],
                'steps' => [
                    'Karyawan menekan **Absen Masuk** saat tiba dan **Absen Keluar** saat pulang di aplikasi staff. Absen memakai lokasi dan hanya bisa dilakukan di dalam radius toko (diatur di form Toko).',
                    'Keterlambatan dihitung dari jam buka toko, dengan toleransi keterlambatan yang diatur di form Toko. Semua karyawan mengikuti jam buka toko; Ginnva tidak memakai shift.',
                    'Kalau karyawan lupa absen, ia mengajukan koreksi di aplikasi staff; atasan menyetujui atau menolak di menu **Koreksi Absensi**.',
                    'Rekap kehadiran dilihat di **Absensi Karyawan** (panel).',
                ],
                'tips' => [
                    'Laporan Absensi memakai jam hadir ini, jadi koreksi yang disetujui ikut memengaruhi laporan.',
                    'Menu Jadwal Kerja (shift) tidak dipakai dan disembunyikan; abaikan bila ada petunjuk lama yang menyebutnya.',
                ],
            ],
            [
                'id' => 'karyawan-akun',
                'title' => 'Karyawan: akun, role, dan akses menu',
                'summary' => 'Membuat akun karyawan, menentukan role dan toko, mengisi data personalia, mengatur akses menu, dan menonaktifkan akun.',
                'menus' => ['UserResource', 'RoleResource', 'EmployeeTypeResource'],
                'steps' => [
                    'Buat akun di **User** (semua akun di sini adalah karyawan; partner dan pelanggan tidak termasuk). Isi **Nama**, **Email**, **No. Karyawan**, **No. HP**, **Tanggal Mulai Kerja**, **Password**, dan **Konfirmasi Password**.',
                    'Pilih **Role**. Super admin dan direksi berakses penuh. Store manager dan installer wajib memilih **Toko**; tanpa toko, akun tidak bisa mengakses booking atau chat toko mana pun. Installer tidak bisa login ke panel, hanya lewat aplikasi staff. Role baru (mis. hrd, warehouse_staff) dibuat sendiri di **Role & Divisi** tanpa developer; nama role memakai huruf kecil dan garis bawah.',
                    'Isi data personalia: **Tipe Karyawan**, **Gaji Pokok** (dasar Penggajian bulanan), **Masa Percobaan Berakhir** (kosongkan bila tidak ada; maksimal 3 bulan untuk PKWTT dan PKWT tidak boleh ada masa percobaan), serta **Tanggal Berakhir Kontrak** untuk kontrak pertama. Perpanjangan berikutnya dicatat lewat menu **Perpanjang Kontrak**, bukan diedit di sini. NIK, NPWP, BPJS, kontak darurat, dan rekening gaji juga diisi di bagian ini.',
                    'Bagian **Akses Menu & Hak Akses Detail** hanya untuk role staff atau divisi. Tanpa centang apa pun, akun mendapat akses ke semua menu (cara paling aman bila belum yakin). Mencentang modul tertentu MEMBATASI akun ke modul itu saja; lalu atur Lihat, Buat, Ubah, Hapus, atau Void untuk tiap modul.',
                    'Karyawan pindah toko: ubah **Toko** dan isi **Alasan Perpindahan Toko (opsional)**; perpindahan tercatat di riwayat karir.',
                    'Karyawan resign atau cuti panjang: pakai **Nonaktifkan** (mematikan **Akun Aktif**) untuk mencabut akses login panel dan aplikasi tanpa menghapus riwayat. Akun yang punya riwayat HR (absensi, cuti, gaji, surat peringatan, kontrak) tidak bisa dihapus permanen, dan penghapusan massal melewatinya.',
                    '**Tipe Karyawan** adalah daftar status kepegawaian yang Anda definisikan sendiri (mis. Tetap, Kontrak, Freelance, Magang). **Memiliki Tanggal Berakhir** menentukan apakah Tanggal Berakhir Kontrak wajib diisi untuk tipe itu. Tipe yang masih dipakai karyawan tidak bisa dihapus; nonaktifkan saja.',
                    'Karyawan yang aksesnya sama dengan rekan lain: di bagian akses (hanya terlihat oleh akses penuh), pilih **Salin akses dari karyawan lain**. Kotak modul dan hak detail terisi sesuai karyawan itu; Role dan Toko tidak ikut. Periksa, lalu tekan Simpan, karena belum tersimpan sebelum itu. Bila yang dipilih berakses penuh, semua kotak dikosongkan (artinya akses penuh).',
                ],
                'tips' => [
                    'Kolom Status Kontrak di daftar User membantu melihat kontrak yang akan berakhir; ringkasannya juga muncul di Dasbor HR.',
                    'Perubahan akses menu dan role tercatat di riwayat aktivitas akun.',
                ],
            ],
            [
                'id' => 'cuti-kontrak',
                'title' => 'Izin, cuti, kontrak, dan surat peringatan',
                'summary' => 'Memproses pengajuan izin dan cuti, mengatur jenis dan batasnya, mencatat perpanjangan kontrak, dan menerbitkan surat peringatan.',
                'menus' => ['LeaveRequestResource', 'LeaveTypeResource', 'ContractExtensionResource', 'WarningLetterResource'],
                'steps' => [
                    'Karyawan mengajukan izin atau cuti lewat aplikasi staff, atau HR mengisinya di **Izin & Cuti**: **Karyawan**, **Jenis**, **Dari Tanggal**, **Sampai Tanggal**, **Alasan**, dan **Lampiran** (mis. surat dokter). Wajib atau tidaknya lampiran mengikuti aturan jenis yang dipilih.',
                    'Atasan menekan **Setujui** atau **Tolak** (isi **Alasan Ditolak**). Karyawan mendapat pemberitahuan atas keputusannya.',
                    '**Jenis Izin & Cuti** mengatur tiap jenis: **Maksimal hari per pengajuan** (kosong berarti batas umum 30 hari), **Wajib lampiran mulai (hari)**, dan **Memotong jatah cuti tahunan** (aktifkan hanya untuk Cuti Tahunan; cuti khusus seperti menikah atau melahirkan tidak memotong jatah). Angka bawaan hanyalah titik awal dan perlu disesuaikan dengan Peraturan Perusahaan. Jenis yang sudah dipakai pengajuan tidak bisa dihapus; nonaktifkan.',
                    '**Perpanjang Kontrak**: pilih **Karyawan**, isi **Tanggal Berakhir Kontrak Baru** (harus lebih baru dari tanggal akhir yang berlaku) dan **Catatan (opsional)**. Riwayat perpanjangan tersimpan dengan tanggal **Sebelumnya** dan **Diperpanjang Sampai**.',
                    '**Surat Peringatan**: isi **Toko**, **Karyawan**, **Tingkat**, **Tanggal Diterbitkan**, **Berlaku Sampai (opsional)**, dan **Alasan / Pelanggaran**; unggah **Scan Surat (opsional)**. **Riwayat SP Karyawan Ini** menampilkan surat peringatan sebelumnya, dan **Dibaca Karyawan** menandai surat sudah dilihat di aplikasi.',
                ],
                'tips' => [
                    'Menghapus riwayat perpanjangan kontrak TIDAK mengembalikan tanggal akhir kontrak di User; sesuaikan manual di menu User bila perlu.',
                    'Scan surat peringatan disimpan privat; buka lewat **Lihat Scan**.',
                ],
            ],
            [
                'id' => 'penggajian',
                'title' => 'Penggajian bulanan',
                'summary' => 'Dari menyiapkan aturan potongan dan data karyawan, menghasilkan slip gaji bulanan, menambah komponen, sampai pembayaran.',
                'menus' => ['PayrollResource', 'PayrollItemCategoryResource', 'SalaryGradeResource', 'AttendanceDeductionRuleResource'],
                'steps' => [
                    'Sebelum generate, periksa laporan **Kelengkapan Data Karyawan** (karyawan aktif yang datanya belum lengkap, dipisah antara yang wajib dan yang disarankan). Data yang kurang memengaruhi gaji dan absensi.',
                    'Atur **Aturan Potongan Absensi**: satu baris per tanggal **Berlaku Mulai** (isi awal bulan). Isi **Ukuran Blok Telat (menit)**, **Potongan per Blok Telat (Rp)**, dan **Denda Tetap per Hari Alpha (Rp)** (ditambahkan di atas potongan Alpha per jam kerja). Hanya blok penuh yang dipotong; contoh blok 15 menit: total telat 14 menit tidak dipotong, 40 menit sama dengan 2 blok. Aturan yang sudah dipakai payroll tidak bisa diubah atau dihapus; perubahan dibuat sebagai baris baru dengan tanggal berlaku baru.',
                    'Di **Penggajian** pilih **Generate Payroll Bulanan**: isi **Bulan** (pilih bulan yang sudah selesai penuh; potongan telat dan Alpha bulan berjalan belum lengkap) dan **Toko** (kosongkan untuk semua toko). Baris yang sudah ditandai dibayar terkunci dari generate ulang.',
                    'Tambahan (tunjangan, bonus, THR) dan potongan (BPJS, PPh 21, kasbon) per karyawan diisi lewat **Komponen Gaji**: **Kategori**, **Nominal (Rp)**, dan **Nama di slip (opsional)**. Sistem hanya mencatat; sistem TIDAK menghitung pajak dan iuran, itu sesuai hitungan akuntan. **Salin dari Bulan Lalu** menyalin komponen yang ditandai untuk disalin ke bulan depan; komponen yang sudah ada dilewati.',
                    '**Kategori Komponen Gaji** (diatur akuntan) mendaftarkan jenis komponen: **Jenis** (tambahan menambah gaji bersih, potongan menguranginya; tidak bisa diubah setelah dipakai) dan **Biasanya ikut disalin ke bulan berikutnya**. Kategori yang sudah dipakai tidak bisa dihapus; nonaktifkan.',
                    'Pembayaran: **Ajukan Pembayaran** mengirim pengajuan ke direksi. Direksi atau akses penuh menekan **Setujui & Tandai Dibayar** atau **Tolak Pengajuan** (isi **Alasan Penolakan**). Setelah ditandai dibayar, barisnya terkunci, jadi pastikan gaji benar-benar sudah ditransfer. **Tandai Dibayar** langsung hanya untuk direksi atau akses penuh.',
                    '**Download Slip Gaji** menghasilkan slip per karyawan.',
                ],
                'tips' => [
                    'Aturan potongan sebaiknya tertulis di Peraturan Perusahaan yang sudah diterima karyawan (lihat panduan Kebijakan dan checklist).',
                    'Slip dengan status dibayar tidak digenerate ulang; koreksi dibuat sebagai komponen di bulan berikutnya, bukan menimpa slip.',
                ],
            ],
            [
                'id' => 'k3-kinerja',
                'title' => 'K3, pelatihan, dan penilaian kinerja',
                'summary' => 'Mencatat pembagian APD, kejadian K3, pelatihan, dan penilaian kinerja.',
                'menus' => ['PpeTypeResource', 'PpeIssuanceResource', 'SafetyIncidentResource', 'EmployeeTrainingResource', 'PerformanceReviewResource'],
                'steps' => [
                    '**Jenis APD** mendaftarkan perlengkapan beserta **Umur pakai (bulan)**, dasar jatuh tempo penggantian (kosongkan bila tidak ada jadwal ganti). Jenis yang sudah pernah dibagikan tidak bisa dihapus; nonaktifkan.',
                    '**Pembagian APD** mencatat siapa menerima apa: **Karyawan**, **Jenis APD**, **Jumlah**, dan **Tanggal dibagikan**. **Jatuh tempo ganti** terisi otomatis dari umur pakai bila dikosongkan. Isi **Dikembalikan/diganti pada** saat barang lama dikembalikan; barang itu tidak dihitung jatuh tempo lagi. Filter **Perlu diganti** menampilkan yang sudah waktunya diganti.',
                    '**Kejadian K3**: catat **Waktu kejadian**, **Jenis**, **Tingkat**, **Karyawan terlibat/terdampak**, **Apa yang terjadi**, dan **Tindakan saat itu**. Isi **Penyebab** dan **Tindak lanjut/pencegahan** (wajib sebelum kejadian bisa ditutup), lalu tekan **Tutup**. Kejadian tidak bisa dihapus. Untuk kejadian cedera, periksa kewajiban pelaporan ke BPJS Ketenagakerjaan; cedera yang belum ditutup ditandai di Dasbor HR.',
                    '**Pelatihan Karyawan** mencatat pelatihan K3, pengetahuan produk, dan sejenisnya: **Karyawan**, **Nama pelatihan**, **Penyelenggara**, **Tanggal pelatihan**, **Berlaku sampai** (kosongkan bila tidak kedaluwarsa), dan **Berkas sertifikat (opsional)** yang disimpan privat. Pelatihan installer per layanan tetap dicatat di Installer, Pelatihan & Sertifikat.',
                    '**Penilaian Kinerja** menilai empat aspek dengan skor 1 sampai 5, ditambah **Kekuatan**, **Yang perlu diperbaiki**, dan **Target periode berikutnya**. Satu penilaian per karyawan per **Periode**. Untuk pembinaan, bukan dasar gaji atau potongan. Penilaian yang sudah dibaca karyawan terkunci dari perubahan nilai.',
                ],
                'tips' => [
                    'Pelatihan yang sudah kedaluwarsa atau akan kedaluwarsa dalam 30 hari ditandai di daftar dan di Dasbor HR.',
                ],
            ],
            [
                'id' => 'hr-laporan',
                'title' => 'Dasbor HR dan laporan karyawan',
                'summary' => 'Satu layar tindakan HR, dan laporan kelengkapan data, kompetensi installer, serta utilisasi installer.',
                'menus' => ['HrDashboard', 'EmployeeDataCompletenessReport', 'InstallerCompetencyReport', 'TechnicianServiceDurationReport', 'TechnicianUtilizationReport'],
                'steps' => [
                    '**Dasbor HR** menampilkan angka yang butuh tindakan: kontrak yang akan berakhir, masa percobaan, data belum lengkap, peraturan yang belum diterima, APD jatuh tempo, sertifikat kedaluwarsa, kejadian K3 yang masih terbuka, dan karyawan yang belum dinilai. Pilih **Toko** untuk menyaringnya.',
                    '**Kelengkapan Data Karyawan** mendaftar karyawan aktif yang datanya belum lengkap, dipisah antara yang WAJIB (memengaruhi gaji dan absensi) dan yang DISARANKAN. Periksa sebelum menggenerate payroll.',
                    '**Kompetensi Installer** menampilkan matriks keterampilan installer per layanan, sertifikat yang kedaluwarsa atau hampir kedaluwarsa, dan mutu pekerjaan (lulus QC pertama, pekerjaan ulang) per installer pada rentang tanggal yang dipilih. Untuk pembinaan, bukan dasar potongan gaji atau komisi.',
                    '**Durasi Servis Installer** adalah laporan akumulasi durasi layanan selesai per installer. Laporan ini belum menghitung komisi bertingkat.',
                    'Laporan installer bisa diekspor lewat **Export ke Excel** atau **Export ke PDF**.',
                ],
                'tips' => [
                    'Laporan Kompetensi Installer dan Pekerjaan Ulang dipakai untuk pembinaan, tidak menjadi dasar potongan gaji.',
                ],
            ],
            [
                'id' => 'kebijakan-checklist',
                'title' => 'Peraturan perusahaan dan kebijakan',
                'summary' => 'Menerbitkan Peraturan Perusahaan dan SOP ke aplikasi karyawan dengan bukti penerimaan.',
                'menus' => ['CompanyPolicyResource', 'ChecklistTemplateResource', 'EmployeeChecklistItemResource'],
                'steps' => [
                    'Di **Peraturan & Kebijakan** buat dokumen baru: **Judul**, **Jenis**, **Versi**, **Berlaku Sejak**, **Berkas (PDF)**, dan **Ringkasan untuk karyawan**. Berkas disimpan privat; karyawan membukanya lewat aplikasi dengan tautan sementara.',
                    'Aktifkan **Karyawan wajib menyatakan menerima** untuk peraturan yang mengikat (mis. aturan potongan), dan **Terbit di aplikasi karyawan** supaya dokumen terlihat karyawan. Hanya dokumen yang terbit terlihat.',
                    'Dokumen yang sudah punya bukti penerimaan terkunci (judul, versi, dan berkas tidak bisa diubah). Untuk versi baru, buat dokumen baru lalu cabut terbit versi lama. Filter **Belum menerima** menampilkan karyawan yang belum menyatakan menerima.',
                ],
                'tips' => [
                    'Aturan potongan telat dan Alpha sebaiknya tertulis di Peraturan Perusahaan yang sudah diterima karyawan; Dasbor HR menandai peraturan yang belum diterima.',
                ],
            ],
            [
                'id' => 'installer-mulai',
                'title' => 'Installer: mulai memakai aplikasi staff',
                'audience' => 'installer',
                'summary' => 'Apa saja yang ada di aplikasi untuk installer, apa yang menjadi tugas Anda di sana, dan apa yang dipegang leader atau Store Manager.',
                'menus' => [],
                'steps' => [
                    'Masuk lewat aplikasi staff dengan email dan password dari admin. Installer tidak memakai panel web; semuanya lewat aplikasi. Lupa password? Pakai fitur lupa password di layar masuk, atau minta admin. Ganti password lewat nama Anda di bagian atas **Beranda**.',
                    'Bilah bawah berisi **Beranda**, **Absensi**, **Booking**, **Gaji**, dan **Profil**. Menu lain ada sebagai kotak di Beranda: **Surat Perintah Kerja**, **Kotak Masuk**, **Slip Gaji**, **K3 & Penilaian**, **Peraturan & Kebijakan**, dan **Panduan**.',
                    'Pekerjaan Anda muncul di **Booking Toko** dan **Surat Perintah Kerja**, hanya untuk booking yang DITUGASKAN kepada Anda, bukan seluruh booking toko. Saat leader menugaskan atau mencopot Anda dari sebuah booking, Anda menerima pemberitahuan "Penugasan Instalasi Baru" atau "Penugasan Dicabut"; riwayatnya ada di **Kotak Masuk**. Periksa kotak masuk di awal hari.',
                    'Yang dipegang leader installer, Store Manager, atau atasan, bukan installer: konfirmasi dan pembatalan booking, chat dengan pelanggan, foto penerimaan, Quality Check, serah terima, dan penugasan installer. Layar-layar itu ditolak untuk akun installer; hubungi leader bila ada kendala di lapangan.',
                    'Yang menjadi tugas Anda di aplikasi: melihat detail pengerjaan, melengkapi SPK, absen, mengajukan izin dan koreksi, serta membaca slip gaji, perlengkapan, dan peraturan. Langkah tiap tugas ada di panduan Installer: mengerjakan satu booking dan Installer: absensi, gaji, dan K3.',
                ],
                'tips' => [
                    'Booking yang belum ditugaskan ke Anda tidak muncul sama sekali; itu normal, bukan error.',
                    'Daftar kotak di Beranda bisa berbeda antar akun menurut hak akses.',
                ],
            ],
            [
                'id' => 'installer-pekerjaan',
                'title' => 'Installer: mengerjakan satu booking',
                'audience' => 'installer',
                'summary' => 'Dari melihat detail pengerjaan sampai melengkapi SPK dan menandai kendaraan keluar.',
                'menus' => [],
                'steps' => [
                    "Buka **Booking Toko** lalu pilih booking Anda. Layar **Detail Pengerjaan** menampilkan Layanan, Tanggal, Lama Pengerjaan, Toko, Plat Nomor, Kendaraan, Warna, Ukuran Kendaraan, Pelanggan, Status, dan rekan Installer, serta Catatan. Layanan berat (PPF, Kaca Film) bisa berlangsung beberapa hari; layanan ringan (Detailing, Premium Wash) berlangsung per jam, dan jamnya tertulis di Tanggal.",
                    'Bila SPK sudah dibuat, tekan **Lihat SPK**. Bila belum, layar menyebut bahwa SPK dibuat oleh leader installer atau atasannya; installer biasa tidak membuat SPK baru. Daftar semua SPK Anda ada di **Surat Perintah Kerja**.',
                    'Di SPK, lengkapi data kendaraan (**No. Polisi**, **No. Rangka**, merek, tahun, jenis, kilometer, BBM, battery), centang daftar **Uraian Pekerjaan**, **Extra Services**, dan **Perlengkapan Kendaraan** yang sesuai, lalu isi **Catatan** bila perlu. Tekan **Simpan Perubahan** agar tersimpan.',
                    'Ketuk **Kondisi Kendaraan** untuk menandai kerusakan yang sudah ada sebelum pengerjaan pada diagram kendaraan (C cat luka, B baret dalam, P penyok, G kaca baret atau retak, M komponen hilang, OS over spray). Ini bukti kondisi unit saat diterima; tandai teliti, lalu tekan **Simpan Kondisi Kendaraan**.',
                    'Saat kendaraan selesai dan keluar, tekan **Tandai Kendaraan Keluar**. Untuk produk film yang wajib dilacak gulungannya, penandaan ditolak sampai pemakaian gulungan dicatat; minta bagian inventaris atau leader mencatatnya, lalu ulangi.',
                    'Kendala lain, mis. unit tidak sesuai data, kerusakan baru, atau jadwal mundur, sampaikan ke leader installer. Chat dengan pelanggan dipegang leader.',
                ],
                'tips' => [
                    'SPK yang sudah keluar tetap bisa dibuka, tetapi pastikan datanya benar sebelum menekan Tandai Kendaraan Keluar.',
                    'Foto penerimaan dan serah terima dikelola leader atau Store Manager, bukan installer.',
                ],
            ],
            [
                'id' => 'installer-hr',
                'title' => 'Installer: absensi, izin, gaji, dan K3',
                'audience' => 'installer',
                'summary' => 'Absen masuk dan keluar, koreksi, izin dan cuti, slip gaji dan komisi, perlengkapan, serta peraturan.',
                'menus' => [],
                'steps' => [
                    'Di **Absensi**, tekan **Absen Masuk** saat tiba dan **Absen Keluar** saat pulang. Absen memakai lokasi dan hanya berhasil di dalam radius toko; aktifkan GPS. Tekan **Cek Jarak Saya ke Toko** bila absen ditolak karena jarak.',
                    'Lupa absen? Tekan **Ajukan Koreksi Absensi**, isi Tanggal, Jam Masuk, Jam Keluar, dan Alasan. Atasan menyetujui atau menolak; hasilnya ada di Riwayat Pengajuan.',
                    'Tidak masuk karena sakit atau keperluan lain: tekan tombol Ajukan / Lihat Izin & Cuti, lalu **Ajukan Izin/Cuti** (pilih Jenis, **Dari Tanggal**, **Sampai Tanggal**, tulis Alasan, dan lampirkan foto bila dibutuhkan). Pengajuan menunggu persetujuan admin; Anda bisa membatalkannya selama belum diproses.',
                    '**Slip Gaji** menampilkan Gaji Pokok, Gaji Berjalan, Komisi Installer, dan Gaji Bersih per bulan, dan bisa diunduh lewat **Download Slip Gaji (PDF)**. Bila ada pekerjaan yang tarif komisinya belum diatur, aplikasi memberi tahu bahwa komisi itu belum ikut ditotal; tanyakan ke atasan.',
                    '**K3 & Penilaian** menampilkan perlengkapan (APD) atas nama Anda beserta jatuh tempo ganti, pelatihan dan sertifikat, serta penilaian kinerja. Tekan **Saya Sudah Membaca** setelah membaca penilaian. Penilaian untuk pembinaan, bukan penentu gaji.',
                    '**Peraturan & Kebijakan** berisi peraturan perusahaan dan SOP. Baca dokumennya dan tekan tombol penerimaan bila diminta. Aturan potongan telat dan Alpha hanya sah bila tertulis di peraturan yang sudah Anda terima.',
                    '**Profil** menampilkan masa kerja dan kontrak, data diri, kontak darurat, dan dokumen. Bila ada data yang salah, minta HR memperbaikinya. Surat peringatan yang pernah diterbitkan ada di **Riwayat Surat Peringatan** pada layar Absensi.',
                ],
                'tips' => [
                    'Koreksi absensi yang disetujui ikut memengaruhi perhitungan telat dan gaji.',
                    'Aturan ini sama untuk semua karyawan; yang khusus installer hanyalah komisi pada Slip Gaji.',
                ],
            ],
            [
                'id' => 'kirim-notifikasi',
                'title' => 'Kirim Notifikasi, kampanye, dan template',
                'summary' => 'Mengirim pesan push ke pelanggan atau partner, menjadwalkannya, menyimpan template, dan memantau hasil tiap kampanye.',
                'menus' => ['SendNotification', 'NotificationCampaignResource', 'NotificationTemplateResource'],
                'steps' => [
                    "Di **Kirim Notifikasi** pilih **Kirim Ke**: Pelanggan (aplikasi utama) atau Partner (mitra referral). Tentukan penerima: **Kirim ke semua pengguna** atau **Pilih Pelanggan** (untuk partner: **Kirim ke semua partner** atau **Pilih Partner**).",
                    "Pilih **Jenis Pesan** untuk pelanggan. **Promosi** (diskon, penawaran, ajakan membeli) hanya dikirim ke pelanggan yang sudah menyetujui menerima promo di aplikasi, dibatasi {$promoMax} kampanye per 7 hari, dan tidak dikirim pada jam tenang ({$quietHours}). **Informasi penting** (jadwal, perubahan layanan) dikirim ke semua pelanggan dan jangan dipakai untuk promosi.",
                    'Isi **Judul Notifikasi** dan **Isi Pesan**, atau mulai dari **Pakai Template (opsional)**. Pada bagian Deep Link (hanya notifikasi Pelanggan) pilih **Tujuan Halaman** agar ketukan notifikasi membuka halaman tertentu; **ID Record (opsional)** diisi bila tujuannya halaman detail garansi atau booking.',
                    "Kirim sekarang, atau aktifkan **Jadwalkan pengiriman** lalu isi **Waktu Kirim (WIB)** (minimal 2 menit dari sekarang). Promosi tidak bisa dijadwalkan pada jam tenang ({$quietHours}).",
                    'Pesan yang akan dipakai lagi disimpan dengan **Simpan sebagai template** (isi **Nama Template**; judul dan isi wajib ada). Di **Template Notifikasi** template bisa diubah namanya, disunting, atau dihapus.',
                    '**Kampanye Notifikasi** mencatat setiap kiriman: **Status** (Terjadwal, Sedang dikirim, Terkirim, Gagal, Dibatalkan), **Penerima**, **Dilewati** (mis. belum menyetujui promo), perangkat **Terkirim** dan **Gagal / Token Mati**, serta hasil kuitansi dari Expo. Kampanye yang masih terjadwal bisa dihentikan dengan **Batalkan**; ini tidak bisa diurungkan.',
                ],
                'tips' => [
                    'Notifikasi booking dan transaksi dikirim otomatis oleh sistem dan tidak terpengaruh jam tenang atau batas promosi.',
                    'Pelanggan yang menolak promo tidak menerima Promosi, jadi jumlah Penerima bisa lebih kecil dari jumlah pelanggan.',
                ],
            ],
            [
                'id' => 'riwayat-notifikasi',
                'title' => 'Riwayat Notifikasi, Log Pengiriman, dan Katalog Notifikasi',
                'summary' => 'Menelusuri notifikasi yang pernah dikirim, memeriksa apakah sebuah push sampai ke perangkat, dan melihat daftar semua titik pengiriman otomatis.',
                'menus' => ['CustomerNotificationResource', 'PartnerNotificationResource', 'NotificationDeliveryResource', 'NotificationCatalogPage'],
                'steps' => [
                    '**Riwayat Notifikasi** (pelanggan) dan **Riwayat Notifikasi Partner** mendaftar pesan yang dikirim admin: **Judul**, **Isi**, **Target**, **Deep Link**, **Dikirim Oleh**, dan **Dikirim**. Saring dengan **Broadcast saja** atau **Targeted saja**, serta **Dari Tanggal** dan **Sampai Tanggal**.',
                    '**Log Pengiriman** menjawab "apakah notifikasi ini sampai ke orang itu": satu baris per perangkat dengan **Penerima**, **Judul**, **Hasil**, dan **Keterangan**. Hasilnya: Menunggu kuitansi, Terkirim, Gagal, Token mati, atau Tidak diketahui. Hasil "Menunggu kuitansi" berubah sendiri setelah kuitansi Expo diperiksa (**Kuitansi Diperiksa**). Token mati berarti aplikasi sudah dihapus atau perangkat tidak lagi terdaftar; itu bukan kesalahan pesan.',
                    '**Katalog Notifikasi** adalah daftar baca-saja semua titik di sistem yang mengirim push, beserta penerimanya (pelanggan atau staff) dan apakah staff juga mendapat catatan di lonceng panel. Pakai untuk menjawab "notifikasi ini datang dari mana".',
                ],
                'tips' => [
                    'Log Pengiriman dibersihkan otomatis setelah 90 hari.',
                    'Pelanggan selalu mendapat baris di kotak masuk aplikasinya; staff punya Kotak Masuk sendiri di aplikasi staff.',
                ],
            ],
            [
                'id' => 'master-data',
                'title' => 'Master Data: toko, kendaraan, dan luas kaca',
                'summary' => 'Mengisi data dasar yang dipakai booking, harga, absensi, dan laporan, serta memeriksa kelengkapannya di Pusat Master Data.',
                'menus' => ['MasterDataCenter', 'StoreResource', 'VehicleResource', 'VehicleGlassAreaResource'],
                'steps' => [
                    '**Pusat Master Data** menghitung data yang masih kurang (kendaraan, luas kaca, toko, produk) dan menautkan ke tempat memperbaikinya lewat tombol **Buka**. Butir bertanda penting perlu ditindak; butir lain hanya saran. Halaman ini hanya membaca.',
                    'Di **Toko/Dealer** isi **Nama Toko**, **Kode Toko** (singkat dan unik, untuk laporan), **Penanggung Jawab**, **Kota**, dan **No. Telepon**. Tempel **Tempel Link Google Maps** agar **Latitude** dan **Longitude** terisi otomatis; koordinat dibutuhkan untuk absen berbasis lokasi. Isi **Google Place ID** untuk tombol review di aplikasi.',
                    'Kapasitas: **Kapasitas Instalasi / Hari (Default)** untuk PPF dan Kaca Film (nilai awal saat booking dikonfirmasi, masih bisa diubah per booking), **Kapasitas Layanan Ringan / Jam** untuk Detailing dan Premium Wash, serta **Jumlah Slot Detailing** dan **Jumlah Slot Instalasi & QC** untuk laporan utilisasi zona. Toko yang belum diisi slotnya dilewati laporan itu.',
                    'Absensi: **Radius Absen (meter)** (kosong berarti 150 m) dan **Toleransi Pengingat Absen & Pulang Cepat (menit)** (kosong berarti 15 menit). Toleransi ini hanya untuk pengingat dan bukan dasar potongan telat; aturan potongan ada di Aturan Potongan Absensi.',
                    '**Jam Operasional**: satu baris per kelompok hari dengan **Jam Buka**, **Jam Tutup**, dan istirahat **Mulai Istirahat (opsional)** serta **Selesai Istirahat (opsional)** bila ada; tandai hari **Libur** bila tutup. Jam istirahat dikurangi dari jam kerja efektif untuk potongan Alpha per jam.',
                    'Toko yang sudah punya data (booking, staff, installer, garansi) tidak bisa dihapus: matikan **Aktif (tampil di web publik)**.',
                    '**Kendaraan** adalah katalog yang muncul di aplikasi dan formulir: **Merek**, **Tipe / Model**, **Varian**, **Kategori Ukuran** (S, M, L, XL, XXL; menentukan harga), dan **Tipe Bodi**. Satu merek+model sebaiknya hanya punya satu ukuran; ukuran ganda membuat harga ambigu. Kendaraan yang tidak ditawarkan lagi dinonaktifkan lewat **Nonaktifkan**, bukan dihapus; riwayat penawaran dan kendaraan pelanggan tetap utuh.',
                    '**Luas Kaca** menyimpan luas kaca (m2) per merek+model: **Depan**, **Samping**, **Belakang**, **Sunroof**, dan **Total**, untuk menghitung kebutuhan bahan dan harga kaca film. Samakan penulisan merek dan modelnya dengan menu Kendaraan agar terhubung (kolom **Di katalog**). Hanya akses penuh yang mengubah data ini dan menjalankan **Import dari Google Sheet**; staff lain hanya melihat, kecuali diberi izin aksi.',
                    'Saat import, baca **Pratinjau** dulu. Bawaannya hanya menambah baris baru; aktifkan **Perbarui juga baris yang nilainya berbeda dari data di sistem** bila memang ingin menimpa data yang ada.',
                ],
                'tips' => [
                    'Kapasitas yang salah memengaruhi kemungkinan tanggal booking yang bisa dipilih; ubah dengan hati-hati.',
                ],
            ],
            [
                'id' => 'histori-aktivitas',
                'title' => 'Riwayat Aktivitas: jejak siapa mengubah apa',
                'summary' => 'Menelusuri perubahan data dan kejadian keamanan akun, melihat nilai sebelum dan sesudah, dan mengekspornya.',
                'menus' => ['ActivityResource'],
                'steps' => [
                    'Menu ini hanya untuk akses penuh, atau staff yang kotak **Riwayat Aktivitas** dicentang secara eksplisit di Akses Menu akunnya (mis. supervisor Finance atau IT). Akun dengan akses menu kosong (semua menu) TIDAK otomatis melihatnya karena isinya sensitif.',
                    'Daftar menampilkan **Waktu (WIB)**, **Pelaku**, **Modul**, **Aksi**, **Asal** (Panel Admin, Aplikasi Staff, Aplikasi Pelanggan, API, Web, atau Sistem), **Objek**, dan **Deskripsi**. Tab **Semua**, **Perubahan Berisiko** (mis. gaji, akses, periode, harga, hapus), dan **Keamanan Akun** (masuk gagal, ganti dan reset password) menyempitkan tampilan.',
                    'Saring dengan **Modul**, **Pelaku**, **Pelaku (Pelanggan)**, **Asal**, **Dari Tanggal (WIB)** dan **Sampai Tanggal (WIB)**, atau **Objek** (**Jenis Objek** dan **Nomor Objek**) untuk menelusuri riwayat satu data, mis. satu booking.',
                    'Buka sebuah baris untuk melihat **Email Pelaku**, **Alamat IP**, **Perangkat**, **Alasan** (bila diisi), serta **Nilai Sebelumnya** dan **Nilai Baru** per kolom. IP dan perangkat hanya dicatat untuk staff, bukan pelanggan.',
                    "Export hasil filter lewat **Export ke Excel** (maksimal {$exportMax} baris; persempit rentang bila lebih). Export itu sendiri tercatat sebagai kejadian di riwayat.",
                ],
                'tips' => [
                    'Log tidak bisa diubah atau dihapus oleh siapa pun; koreksi data dilakukan di modul aslinya dan akan muncul sebagai catatan baru.',
                    'Perubahan oleh sistem terjadwal tercatat sebagai "Sistem (otomatis)" tanpa pelaku.',
                ],
            ],
            [
                'id' => 'leader-harian',
                'title' => 'Leader installer: tugas harian di aplikasi',
                'audience' => 'installer_leader',
                'summary' => 'Dari menugaskan installer dan menerima unit sampai chat progres, Quality Check, serah terima, dan menyelesaikan booking; plus apa yang hanya boleh dilakukan Store Manager.',
                'menus' => [],
                'steps' => [
                    'Leader installer memegang sisi lapangan: menugaskan installer, menerima unit, berbicara dengan pelanggan lewat chat, memeriksa hasil, dan menyerahkan unit. Installer sendiri tidak ikut chat dan tidak melihat layar-layar ini. Di chat, nama Anda tampil sebagai "Leader Installer". Yang Anda lihat hanya booking toko Anda, dan Anda butuh akses menu Booking (diatur admin).',
                    'Awal hari: buka **Booking Toko**, periksa booking Confirmed yang jadwalnya hari ini dan beberapa hari ke depan. Ada booking baru berstatus Pending? Anda bisa menekan **Konfirmasi Booking** (isi lama pengerjaan bila perlu; **Kapasitas Instalasi per Tanggal** menunjukkan hari mana yang masih longgar untuk layanan berat) atau mengubah tanggalnya lewat **Ubah Jadwal** (alasan wajib untuk booking yang sudah Confirmed). Pengajuan ganti jadwal dari pelanggan disetujui atau ditolak di kartu pengajuan pada booking.',
                    'Tugaskan installer lewat **Assignment Booking**: pilih satu atau lebih di **Installer (bisa lebih dari satu)**, lalu **Simpan**. Hanya installer aktif toko itu yang bisa dipilih. Sistem memberi peringatan bila installer sudah bertugas di booking lain pada tanggal yang bertumpuk; peringatan tidak memblokir. Installer yang ditambahkan atau dicopot menerima pemberitahuan.',
                    'Saat unit datang, buka **Foto Penerimaan**: foto empat sudut (depan, belakang, kiri, kanan) dan setiap **Kerusakan yang sudah ada** lengkap dengan keterangan bagiannya (wajib). Lalu buat SPK lewat tombol Buat SPK di booking; installer akan melengkapinya (lihat panduan SPK).',
                    'Kirim progres di **Chat & Progress**: pilih produk dan tahap lewat **Update Tahap**, boleh dengan foto, atau kirim pesan teks. Pelanggan menerima notifikasi tiap tahap. Tahap hanya bisa maju. Salah memilih tahap? Hanya Store Manager atau akses penuh yang bisa memperbaikinya lewat **Koreksi Tahap**. Detailing dan Premium Wash tidak punya tahap pengerjaan.',
                    'Setelah pengerjaan selesai, tandai tahap **Quality Check** lalu isi daftar periksanya di layar Quality Check: jawab **Lulus** atau **Tidak lulus** tiap butir (butir tidak lulus wajib diberi catatan), lalu **Kirim Hasil QC**. Anda tidak bisa memeriksa unit yang Anda kerjakan sendiri; minta leader atau Store Manager lain. Pada booking dua produk, kedua produk harus sudah di tahap akhirnya.',
                    'Buka **Serah Terima Unit** saat pelanggan mengambil kendaraan: centang bahwa petunjuk perawatan sudah disampaikan, dan untuk PPF dan Kaca Film pastikan tanda tangan basah di kertas sudah diterima (wajib; untuk Detailing dan Premium Wash opsional). Tambahkan foto kondisi akhir bila perlu. Centang izin foto pemasaran HANYA bila pelanggan menyetujuinya; bawaannya tidak ada izin. Tekan **Catat Serah Terima** (hanya sekali per booking), lalu **Selesaikan Booking**.',
                    'Yang TIDAK bisa Anda lakukan dan harus diteruskan ke Store Manager atau akses penuh: membatalkan booking, menandai pelanggan tidak datang, menyetujui pembatalan dari pelanggan, dan mengoreksi tahap. Aplikasi menolaknya dengan pesan yang jelas.',
                    'Kendala installer di lapangan (unit tidak sesuai data, kerusakan baru, jadwal mundur) diteruskan kepada Anda; Anda yang berbicara dengan pelanggan dan memutuskan langkah berikutnya.',
                ],
                'tips' => [
                    'Foto penerimaan dan kerusakan yang sudah ada adalah bukti kondisi unit; lengkapi sebelum pengerjaan dimulai.',
                    'Alur lengkap semua layanan dan syarat selesai ada di panduan Booking; panduan ini hanya merangkum bagian leader.',
                ],
            ],
            [
                'id' => 'store-manager-harian',
                'title' => 'Store Manager: tugas harian dan wewenang',
                'audience' => 'store_manager',
                'summary' => 'Yang Anda putuskan setiap hari untuk satu toko: booking, absensi, pengeluaran, dan persetujuan, serta batas wewenang Anda dibanding Leader dan akses penuh.',
                'menus' => [],
                'steps' => [
                    'Lingkup Anda adalah SATU toko: booking, absensi, pengeluaran, dan pengajuan yang Anda lihat hanya milik tokonya. Hal yang diputuskan Anda dan akses penuh (direksi, super admin) tersebar di aplikasi staff dan panel; panduan ini merangkum yang paling sering dan batasnya.',
                    'Awal hari: buka **Kotak Masuk** dan **Booking Toko**. Booking baru berstatus Pending perlu direspons dalam batas jam yang ditetapkan sistem; Anda mendapat pengingat otomatis bila ada yang tertunda. Tekan **Konfirmasi Booking** (periksa kapasitas dan lama pengerjaan), atau gunakan **Ubah Jadwal** bila tanggalnya perlu digeser (alasan wajib untuk booking Confirmed). Pengajuan ganti jadwal dan pembatalan dari pelanggan dijawab di kartu pengajuan pada booking: **Setujui** atau **Tolak** (alasan penolakan dikirim ke pelanggan).',
                    'Sebagian wewenang booking hanya milik Anda dan akses penuh, tidak dimiliki Leader maupun staff lain: **Batalkan Booking** (alasan wajib untuk booking Confirmed), **Tandai Tidak Datang** (hanya bila booking Confirmed, tanggalnya sudah tiba, dan pengerjaan belum dimulai; DP yang sudah diterima dikembalikan penuh), **Koreksi Tahap** (alasan wajib; tidak mengirim notifikasi ke pelanggan), serta menyetujui pembatalan dari pelanggan.',
                    'Tugaskan installer di **Assignment Booking**, pastikan SPK dan foto penerimaan dibuat Leader atau Anda, dan periksa hasil kerja lewat **Quality Check**. Anda boleh memeriksa unit yang tidak Anda kerjakan sendiri.',
                    'Absensi: koreksi absensi karyawan tokonya diputuskan di menu **Koreksi Absensi** di panel dengan **Setujui** atau **Tolak**. Anda tidak bisa menyetujui koreksi yang Anda ajukan sendiri. Izin dan cuti tokonya juga Anda putuskan di menu **Izin & Cuti** (kecuali milik Anda sendiri).',
                    'Keuangan di aplikasi: layar **Keuangan** menampilkan Saldo Kas, ringkasan hari ini (pengeluaran tercatat), dan piutang lewat jatuh tempo. Catat pengeluaran lewat **Catat Pengeluaran** dan hitung kas laci lewat **Hitung Kas**. Pengajuan pengeluaran dari staff tokonya muncul di **Persetujuan Pengeluaran**; tekan **Setujui** atau **Tolak Pengajuan**. Pengajuan Anda sendiri langsung naik ke direksi.',
                    'Batas persetujuan pengeluaran: secara bawaan setelah Anda setuju, pengajuan tetap naik ke direksi. Bila akses penuh mengisi batas di **Pengaturan Keuangan**, pengeluaran sampai nominal itu langsung dicatat setelah Anda menyetujuinya. Anda tidak boleh menyetujui pengajuan yang Anda ajukan sendiri.',
                    'Refund dan Proses Referral: Anda bisa mengajukannya, tetapi baru berlaku setelah akses penuh menyetujuinya di **Persetujuan Transaksi**. Anda hanya melihat pengajuan tokonya.',
                ],
                'tips' => [
                    'Semua keputusan Anda tercatat di Riwayat Aktivitas dengan nama dan waktu.',
                    'Bila sebuah tombol tidak muncul, kemungkinan itu wewenang akses penuh atau tahap berikutnya dalam alur persetujuan, bukan error.',
                ],
            ],
            [
                'id' => 'finance-harian',
                'title' => 'Spv Finance: rutinitas dan batas wewenang',
                'audience' => 'spv_finance',
                'summary' => 'Yang Anda kerjakan harian, mingguan, dan akhir bulan di panel, dan hal-hal yang sengaja hanya boleh dilakukan direksi atau akses penuh.',
                'menus' => [],
                'steps' => [
                    'Akses Anda diatur admin lewat Akses Menu akun Anda. Bagan Akun, Jurnal Umum, Rekonsiliasi Bank, dan Penggajian hanya berlaku untuk role Spv Finance; mencentang menu itu untuk role lain tidak membukanya. Anda bekerja lintas toko dan melihat jurnal seluruh perusahaan. Hak membuat, mengubah, menghapus, dan memposting TIDAK ada bawaan; tiap hak itu harus diberikan admin di Hak Akses Detail. Tanpa hak itu, Anda hanya melihat.',
                    'Harian: periksa **Transaksi Keuangan** toko (kategori, rekening, nota), lalu **Rekonsiliasi Bank** (import mutasi dan cocokkan dengan transaksi), dan pantau Hutang Usaha dan Piutang Usaha yang jatuh tempo. Persetujuan pengeluaran toko berjalan lewat Store Manager lalu direksi; itu bukan keputusan Anda.',
                    '**Jurnal Umum**: jurnal manual dibuat sebagai draft dan harus seimbang. Anda tidak bisa memposting atau membalik jurnal yang Anda buat sendiri; minta orang lain yang berhak (direksi atau Spv Finance lain dengan hak ubah) memprosesnya. Direksi diberi tahu setiap kali Spv Finance memposting jurnal. Jurnal otomatis (booking, transaksi keuangan, penyusutan, DP) dibalik lewat modul asalnya, bukan dari sini.',
                    'Akhir bulan, penggajian: pastikan **Kelengkapan Data Karyawan** beres, jalankan **Generate Payroll Bulanan** untuk bulan yang sudah selesai penuh, isi **Komponen Gaji**, lalu tekan **Ajukan Pembayaran**. Anda tidak bisa menandai dibayar sendiri: direksi yang menekan **Setujui & Tandai Dibayar**, dan baru saat itu gaji diposting ke Jurnal Umum.',
                    '**Tutup Periode** dan **Tutup Tahun Buku** hanya untuk akses penuh; menunya tidak terbuka untuk Anda. Tugas Anda menyiapkannya: tidak ada jurnal Draft yang tertinggal di bulan itu (posting atau hapus), jurnal seimbang, rekonsiliasi bank selesai, dan selisih sub-buku sudah Anda jelaskan. Setelah itu minta direksi menutup periode, bulan demi bulan dari yang paling lama.',
                    'Untuk membaca hasilnya pakai laporan keuangan (Laba Rugi, Neraca, Arus Kas, Buku Besar); langkah rinci tiap menu ada di panduan Transaksi Keuangan, Kas dan Bank, Jurnal dan Bagan Akun, Hutang dan Piutang, Penggajian bulanan, serta Laporan Keuangan.',
                ],
                'tips' => [
                    'Bila sebuah tombol tidak muncul, kemungkinan itu hak yang belum diberikan atau wewenang direksi (pemisahan tugas), bukan error.',
                    'Koreksi untuk periode yang sudah ditutup tidak dibuat dengan mengubah jurnal lama; minta direksi membuka kembali bulannya dengan alasan tertulis.',
                ],
            ],
            [
                'id' => 'hr-harian',
                'title' => 'HR: rutinitas karyawan dan batas wewenang',
                'audience_menus' => ['LeaveRequestResource', 'ContractExtensionResource', 'WarningLetterResource', 'CompanyPolicyResource'],
                'summary' => 'Urutan kerja HR dari karyawan baru sampai kontrak, cuti, disiplin, K3, dan peraturan, serta hal-hal yang hanya boleh dilakukan akses penuh atau role lain.',
                'menus' => [],
                'steps' => [
                    'Di sistem tidak ada role "HR": tugas HR dijalankan akun yang menu Karyawannya dicentang admin di Akses Menu. Panduan ini muncul bagi akun yang menu Izin & Cuti, Riwayat Kontrak, Surat Peringatan, atau Peraturan & Kebijakan-nya dicentang secara eksplisit.',
                    'Yang TIDAK bisa Anda lakukan, karena sengaja hanya untuk pihak lain: membuat atau mengubah akun, role, dan tipe karyawan (hanya akses penuh); mencabut persetujuan izin atau cuti yang sudah diberikan (hanya akses penuh); menyetujui koreksi absensi (Store Manager toko itu atau akses penuh); serta menjalankan penggajian (Spv Finance atau akses penuh). Bila tombol tidak muncul, itu pemisahan tugas, bukan error.',
                    'Karyawan baru: minta akses penuh membuat akunnya di User (tipe karyawan, tanggal mulai, kontrak, toko). Setelah itu Anda memastikan peraturan yang wajib diterima sudah terbit dan memeriksa **Kelengkapan Data Karyawan** sampai butir yang wajib beres, karena data itu dipakai gaji dan absensi.',
                    'Setiap hari mulai dari **Dasbor HR**: kontrak dan masa percobaan yang akan berakhir, data belum lengkap, peraturan belum diterima, APD jatuh tempo, sertifikat kedaluwarsa, kejadian K3 terbuka, dan karyawan yang belum dinilai. Kerjakan yang paling dekat jatuh tempo lebih dulu.',
                    '**Izin & Cuti**: Anda dapat mencatatkan pengajuan atas nama karyawan dan mengubahnya selama masih menunggu, tetapi keputusan **Setujui** atau **Tolak** ada pada akses penuh, Store Manager, dan Spv Finance toko itu (kecuali pengajuan mereka sendiri); ingatkan mereka bila pengajuan menumpuk. Aturan tiap jenis (maksimal hari, lampiran, memotong jatah) diatur di Jenis Izin & Cuti.',
                    '**Perpanjang Kontrak** mencatat tanggal berakhir yang baru dan tidak bisa diedit setelah tersimpan; bila salah, catat perpanjangan baru yang benar. Menghapus riwayat tidak mengembalikan tanggal akhir kontrak di akun karyawan.',
                    '**Surat Peringatan**: Anda boleh menerbitkannya bila admin memberi hak Buat untuk menu ini di Hak Akses Detail akun Anda, tetapi setelah terbit hanya akses penuh (atau pemegang hak ubah yang diberikan admin) yang bisa mengoreksi tingkat, alasan, dan tanggalnya, dan hanya akses penuh yang menghapus. Periksa Riwayat SP Karyawan Ini sebelum menetapkan tingkat. Anda hanya bisa menerbitkan SP 1; SP 2 dan SP 3 (yang bisa berujung PHK) hanya diterbitkan akses penuh, jadi ajukan ke mereka bila pelanggarannya layak.',
                    'K3 dan kinerja: catat pembagian APD dan pelatihan, laporkan **Kejadian K3** (tidak bisa dihapus; ditutup setelah penyebab dan tindak lanjut terisi), isi **Penilaian Kinerja** per periode (terkunci setelah dibaca karyawan), dan catat **Pekerjaan Ulang** untuk pembinaan. Semuanya bukan dasar gaji.',
                    '**Peraturan & Kebijakan**: terbitkan dokumen dengan versi dan tanggal berlaku. Dokumen yang sudah punya bukti penerimaan terkunci; untuk perubahan buat versi baru lalu cabut terbit versi lama. Potongan telat dan Alpha hanya sah bila tertulis di peraturan yang sudah diterima karyawan.',
                    'Akhir bulan: sebelum Spv Finance menjalankan Generate Payroll Bulanan, pastikan Kelengkapan Data Karyawan dan Aturan Potongan Absensi sudah benar, serta koreksi absensi dan pengajuan cuti bulan itu sudah diputuskan.',
                ],
                'tips' => [
                    'Akun yang menu Karyawannya kosong (semua menu) tidak otomatis melihat panduan ini; panduan hanya muncul bila menunya dicentang eksplisit.',
                    'Panduan menu per menu ada di Karyawan: akun, role, dan akses menu; Izin, cuti, kontrak, dan surat peringatan; K3, pelatihan, penilaian kinerja, dan pekerjaan ulang; serta Peraturan perusahaan dan checklist karyawan.',
                ],
            ],
            [
                'id' => 'admin-toko-harian',
                'title' => 'Admin/Kasir toko: rutinitas harian dan batas wewenang',
                'audience_menus' => ['BookingResource', 'InvoiceResource'],
                'summary' => 'Dari pesanan yang masuk sampai penjualan dicatat dan kas dihitung, serta hal-hal yang hanya boleh dilakukan Store Manager atau akses penuh.',
                'menus' => [],
                'steps' => [
                    'Admin/kasir toko adalah staff toko tanpa role khusus: akun dengan menu Booking atau Invoice yang dicentang admin. Yang Anda lihat dibatasi ke toko Anda, dan di chat booking nama Anda tampil sebagai "Admin Toko" diikuti nama tokonya. Panduan ini muncul bagi akun yang menu Booking atau Invoice-nya dicentang eksplisit.',
                    'Pesanan masuk: booking dari aplikasi muncul sebagai Pending. Pelanggan dari WhatsApp, telepon, atau yang datang langsung Anda buatkan booking-nya di panel (pilih layanan; Detailing dan Premium Wash wajib memilih jam; tanggal yang penuh atau toko libur tidak bisa dipilih). Respons booking Pending dalam batas jam yang ditetapkan sistem; Anda mendapat pengingat otomatis bila ada yang tertunda. Calon pelanggan yang meminta penawaran masuk di Quotation (Lead): hubungi, catat, dan tetapkan hasilnya.',
                    'Hari pelaksanaan: pantau status booking toko dan jawab pesan pelanggan di chat booking bila Anda punya akses menu Booking. Penugasan installer, Quality Check, dan serah terima dipegang Leader atau Store Manager (lihat panduan Booking).',
                    'Setelah booking **Completed**, catat penjualannya lewat **Proses Referral**: isi rincian harga per layanan, nominal transaksi, pembayaran yang diterima, voucher, promo, dan kode referral. Karena Anda bukan akses penuh, pengajuannya MENUNGGU persetujuan akses penuh dan belum berlaku sampai disetujui.',
                    'Uang muka dicatat lewat **Catat Uang Muka (DP)** dan sisa yang tidak terpakai dikembalikan lewat **Kembalikan Sisa DP**. Sisa tagihan yang belum dibayar tampil sebagai Belum Lunas di booking; pelunasannya dibukukan di Accurate. Pelanggan yang meminta dokumen tagihan dibuatkan **Invoice**.',
                    'Refund diajukan lewat **Proses Refund** pada booking; seperti Proses Referral, refund dari Anda menunggu persetujuan akses penuh.',
                    'Yang TIDAK bisa Anda lakukan, karena sengaja dibatasi: membatalkan booking, menandai pelanggan tidak datang, dan mengoreksi tahap (Store Manager atau akses penuh), serta menghapus data. Di panel, hak Void (pembatalan) dan Hapus dapat diberikan admin sebagai hak tambahan di Hak Akses Detail. Bila tombolnya tidak muncul, minta Store Manager.',
                ],
                'tips' => [
                    'Salah mengisi Proses Referral? Pengajuan yang masih menunggu belum berlaku; hubungi akses penuh sebelum disetujui.',
                    'Panduan menu per menu ada di Booking: dari masuk sampai selesai, Penjualan, Invoice, Quotation (Lead), dan Approval.',
                ],
            ],
            [
                'id' => 'direksi-harian',
                'title' => 'Direksi: antrian keputusan dan pengawasan',
                'audience' => 'direksi',
                'summary' => 'Semua yang bermuara ke akses penuh: persetujuan, pengaturan, dan cara mengawasi tanpa menyetujui diri sendiri.',
                'menus' => ['GuideNoteResource'],
                'steps' => [
                    'Direksi (CEO, CCO, CFO) setara dengan super admin: akses penuh ke semua menu dan semua toko, tanpa pembatasan Akses Menu. Tidak ada hak khusus per jabatan; keputusan keuangan dan karyawan dipegang semua akun akses penuh. Banyak tombol di sistem sengaja hanya muncul untuk Anda.',
                    'Antrian keputusan, lewat notifikasi dan menu terkait: **Persetujuan Transaksi** (refund, referral, dan DP dari staff), **Penggajian** (**Setujui & Tandai Dibayar** atau **Tolak Pengajuan** untuk pengajuan Spv Finance), **Izin & Cuti** (**Setujui** atau **Tolak**), **Permohonan Pembelian**, installer baru yang menunggu review (**Aktifkan** atau tolak), dan **Klaim & Bukti**.',
                    'Pemisahan tugas: Anda tidak bisa menyetujui pengajuan yang Anda ajukan sendiri (transaksi). Pengajuan penggajian datang dari Spv Finance. Bila Anda perlu mengajukan sesuatu, minta akses penuh lain yang memutuskannya.',
                    'Booking: Anda melihat semua toko dan memiliki wewenang Store Manager (membatalkan booking, menandai tidak datang, mengoreksi tahap). Anda juga bisa ditunjuk sebagai Direksi Pemantau pada sebuah booking untuk ikut memantau progresnya.',
                    'Pengaturan yang hanya Anda pegang: **Profil Perusahaan** (identitas di kop invoice), akun karyawan, role, dan Akses Menu di User, Aturan Potongan Absensi, Checklist QC, serta import Master Data. Atur hak tambahan seperti Void dan Hapus untuk staff di Hak Akses Detail, dan beri hak itu seperlunya.',
                    '**Catatan Panduan** (menu Lainnya) untuk menambah catatan perusahaan di bawah panduan tertentu tanpa menunggu rilis, mis. aturan toko, batas, atau kebijakan. Satu catatan per panduan; tampil di halaman Panduan dan aplikasi staff, dan setiap perubahan tercatat di Riwayat Aktivitas. Jangan menulis password atau data pribadi di sana.',
                    'Pengawasan: **Riwayat Aktivitas** (tab Perubahan Berisiko dan Keamanan Akun) menunjukkan siapa mengubah apa, termasuk gaji, akses, harga, dan periode; Dasbor Lead untuk kecepatan tindak lanjut calon pelanggan; Dasbor HR untuk kontrak, data, dan K3; serta laporan penjualan.',
                ],
                'tips' => [
                    'Setiap keputusan Anda tercatat dengan nama dan waktu di Riwayat Aktivitas.',
                    'Pengajuan pengeluaran yang menunggu terlalu lama memicu pengingat otomatis; periksa antrian di awal hari.',
                ],
            ],
            [
                'id' => 'dashboard',
                'title' => 'Dashboard: membaca angka harian dan penjualan',
                'open' => true,
                'summary' => 'Arti kartu dan grafik di Dashboard utama, dan cara memakai Dashboard Penjualan (harian, mingguan, bulanan).',
                'menus' => ['DashboardHome', 'SalesDashboard'],
                'steps' => [
                    'Dashboard utama terbuka untuk semua staff, tetapi kartu dan grafiknya mengikuti akses menu Anda. Staff toko selalu melihat tokonya sendiri. Akses penuh dengan lebih dari satu toko dapat memilih **Semua toko** atau satu toko; pilihan itu ikut tersimpan di alamat halaman.',
                    "Mulai dari **Perlu Perhatian**: tiap kartu menghitung pekerjaan yang menunggu tindakan, yaitu Garansi Hampir Kedaluwarsa (30 hari ke depan), Garansi Menunggu Review QA, Booking Pending Lewat SLA (belum dikonfirmasi lewat batas {$sla} jam), Pengajuan Jadwal Ulang Menunggu, Pengajuan Pembatalan Menunggu, Booking Lewat Tanggal (dikonfirmasi tapi belum dikerjakan), Booking Belum Lunas (Bulan Ini), dan Karyawan Belum Absen Hari Ini. Angka nol berarti beres; tindak lanjuti lewat menu yang bersangkutan.",
                    '**Pendapatan Booking**: Pendapatan Hari Ini (dibanding kemarin), Pendapatan Bulan Ini (dibanding bulan lalu), Rata-rata Nilai Booking, Sudah Diterima (Bulan Ini), dan Piutang (Bulan Ini). Angka ini hanya menghitung booking yang penjualannya SUDAH diproses, jadi penjualan yang masih menunggu persetujuan atau belum diproses belum masuk.',
                    '**Booking** menampilkan Booking Hari Ini, Garansi Aktif, Menunggu Review QA, Garansi Hampir Kedaluwarsa, dan Quotation Baru (7 Hari). Grafik di bawahnya: Tren Pendapatan Harian (bulan ini dibanding bulan lalu), Pendapatan per Kategori dan per Metode Pembayaran (bulan ini), Tren Pengajuan Garansi, Garansi per Toko, Tren Quotation, Grafik Jenis Order, dan Grafik Penjualan Outlet.',
                    '**Dashboard Penjualan** (menu terpisah; perlu dicentang di Akses Menu karena memuat omzet) bisa diatur dengan tombol **Harian**, **Mingguan**, atau **Bulanan**, ditambah panah tanggal dan **Lompat ke**. Akses penuh juga bisa memilih toko. Badge persentase di tiap angka membandingkan dengan periode sebelumnya yang sama panjang.',
                    'Angka utamanya: **Total Penjualan** (bersih, yaitu penjualan tercatat dikurangi pengembalian), **Penjualan Belum Dibayar (Piutang)**, **Penjualan Terbayar**, **Transaksi**, **Penjualan per Transaksi**, **Produk Terjual**, dan **Produk per Transaksi**. Di bawahnya **Indikator Penjualan** (Transaksi Multi-Layanan, Pelanggan Kembali, Potongan Diberikan), penjualan per jenis layanan dan per toko, **Akumulasi dari Awal Bulan** dan **Proyeksi Bulan Ini** (selalu bulan berjalan, tidak mengikuti periode yang dipilih), serta **Ranking Installer**.',
                    'Bila muncul peringatan kuning "booking sudah selesai tapi belum diproses ke pendapatan", nominalnya belum masuk angka. Proses dulu lewat Proses Referral (lihat panduan Penjualan). Untuk rincian dan export gunakan tautan Lihat & Export di Ringkasan Penjualan, yang membawa rentang tanggal yang sama.',
                ],
                'tips' => [
                    'Dashboard memperbarui angka saat halaman dibuka atau filter diubah, tidak terus-menerus; muat ulang halaman untuk data terbaru.',
                    'Selisih antara dashboard dan laporan biasanya berasal dari dasar yang berbeda; baca catatan "Cara baca angka" di Dashboard Penjualan dan panduan Laporan penjualan.',
                    'Akses penuh juga melihat kartu **Cadangan Database**: waktu dan ukuran cadangan terakhir di server, peringatan bila sudah lebih dari 30 jam, dan apakah salinan di luar server (off-site) sudah diatur. Cadangan berjalan harian jam 03:00.',
                ],
            ],
            [
                'id' => 'istilah',
                'title' => 'Istilah yang sering muncul',
                'summary' => 'Arti singkat istilah di layar dan laporan.',
                'menus' => [],
                'steps' => [
                    '**Pending / Confirmed / Completed / Cancelled**: status booking (menunggu, dikonfirmasi, selesai, dibatalkan).',
                    '**Layanan berat** adalah PPF dan Kaca Film (dihitung per hari, tahap pengerjaan, tanda tangan basah wajib). **Layanan ringan** adalah Detailing dan Premium Wash (dihitung per jam, tanpa tahap pengerjaan).',
                    '**SPK**: Surat Perintah Kerja, form inspeksi kendaraan sebelum pengerjaan.',
                    '**Quality Check** (QC): pemeriksaan hasil kerja sebelum unit diserahkan. **Siap Diambil** adalah tanda hasil QC lulus, bukan status booking baru.',
                    "**Kapasitas**: jumlah pekerjaan yang bisa dikerjakan toko. Berat per hari, ringan per jam. Hanya booking Confirmed yang memakainya. **Batas respons** adalah waktu {$sla} jam bagi toko untuk merespons booking Pending.",
                    '**Opname**: penghitungan fisik stok untuk dicocokkan dengan catatan sistem.',
                    '**DP**: uang muka booking. **PPN**: pajak yang dihitung pada transaksi sesuai pengaturan. **Piutang**: sisa tagihan booking yang belum dibayar.',
                    '**Akses penuh**: peran super admin dan direksi; melihat semua toko dan semua menu.',
                ],
                'tips' => [
                    'Bingung dengan isian tertentu? Banyak kolom di form punya teks bantuan kecil di bawahnya.',
                ],
            ],
        ];

        return array_map(fn (array $guide) => $guide + [
            'category' => self::CATEGORY_BY_ID[$guide['id']] ?? 'lainnya',
            'version' => self::VERSION,
            'reviewed_at' => self::REVIEWED_AT,
        ], $guides);
    }
}
