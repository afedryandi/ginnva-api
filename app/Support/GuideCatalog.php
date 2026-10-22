<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\BookingMessage;
use App\Models\BookingSaleLine;
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
    ];

    /** Halaman yang tidak perlu panduan sendiri (halaman Panduan itu sendiri). */
    public const NO_GUIDE_NEEDED = ['Panduan'];

    /**
     * @return array<int, array{id: string, title: string, summary: string, menus: array<int, string>, steps: array<int, string>, tips: array<int, string>}>
     */
    public static function all(): array
    {
        $sla = (int) config('booking.confirm_sla_hours', 4);
        $slots = implode(', ', (array) config('booking.light_slots', []));
        $services = implode(', ', BookingSaleLine::SERVICE_TYPES);
        $kacaFilmStages = implode(' → ', array_values(BookingMessage::PRODUCT_STAGES['kaca_film']));
        $ppfStages = implode(' → ', array_values(BookingMessage::PRODUCT_STAGES['ppf']));
        $sharedStages = implode(' → ', array_values(BookingMessage::SHARED_STAGES));
        $graceDays = WarrantyMaintenanceSchedule::GRACE_DAYS;
        $negotiationMax = rtrim(rtrim(number_format(Booking::NEGOTIATION_DISCOUNT_MAX_PCT, 1, ',', '.'), '0'), ',');

        return [
            [
                'id' => 'booking',
                'title' => 'Booking: dari masuk sampai selesai',
                'summary' => "Alur satu booking untuk keempat layanan ({$services}) dari pesanan sampai serah terima. Langkah di lapangan dikerjakan di aplikasi staf; pengaturan dan pencatatan penjualan di panel.",
                'menus' => ['BookingResource', 'CapacityCalendar', 'BookingServiceTypeResource'],
                'steps' => [
                    "Pesanan masuk. Customer memesan lewat aplikasi: pilih layanan, toko, dan tanggal (untuk Detailing dan Premium Wash wajib memilih jam). Staf juga bisa membuatnya di panel untuk customer dari WhatsApp atau yang datang langsung. Status **Pending**; toko diharapkan merespons dalam {$sla} jam.",
                    'Konfirmasi di aplikasi staf (Store Manager, direksi, atau super admin): periksa kapasitas, isi lama pengerjaan (hari kerja) bila perlu, lalu **Konfirmasi Booking**. Status menjadi **Confirmed** dan slot toko terkunci. Bila ada installer yang cuti atau libur pada tanggal itu, muncul peringatan (tidak memblokir konfirmasi).',
                    'Store Manager atau direksi menugaskan installer yang mengerjakan (satu atau lebih). Penugasan tercatat di riwayat aktivitas.',
                    'Saat unit datang, ambil foto penerimaan kendaraan dari empat sudut (depan, belakang, kiri, kanan) dan foto kerusakan yang sudah ada (maksimal 10, wajib diberi keterangan). Leader installer membuat **SPK** (inspeksi kendaraan) sebelum pengerjaan; installer melengkapinya dari booking yang ditugaskan kepadanya.',
                    "Progres dikirim lewat **chat booking** per tahap (boleh dengan foto); customer mendapat notifikasi di setiap tahap. Tahap hanya bisa maju. Kaca Film: {$kacaFilmStages}. PPF: {$ppfStages}. Booking Kaca Film + PPF punya dua jalur tahap. Tahap bersama untuk semua layanan: {$sharedStages}. Detailing dan Premium Wash tidak punya tahap pengerjaan: setelah dikerjakan langsung ke Quality Check.",
                    'Tandai tahap **Quality Check** (semua produk yang dipesan harus sudah sampai tahap terakhirnya). Isi juga daftar periksa QC layanan itu; pemeriksanya Leader, Store Manager, atau akses penuh, bukan orang yang mengerjakan unit. Semua butir harus dijawab dan butir yang tidak lulus wajib diberi catatan. Bila lulus, booking ditandai **Siap Diambil** dan customer diberi tahu.',
                    'Serah terima unit: catat bahwa petunjuk perawatan sudah disampaikan. Untuk PPF dan Kaca Film tanda tangan basah di kertas wajib diterima (untuk Detailing dan Premium Wash opsional); sistem hanya mencatat bahwa tanda tangan sudah diterima. Foto akhir dan catatan boleh ditambahkan. Dicatat sekali per booking.',
                    'Terakhir, **Selesaikan Booking**. Status menjadi **Completed** dan tercatat di riwayat booking customer. Syaratnya tahap Quality Check sudah ditandai.',
                    'Setelah selesai, kasir atau admin mencatat penjualannya di panel lewat **Proses Referral** (lihat panduan Penjualan).',
                ],
                'tips' => [
                    'Kewajiban lulus QC dan mencatat serah terima sebelum booking boleh diselesaikan bisa dinyalakan admin sistem. Bila menyala dan syaratnya belum terpenuhi, tombol Selesaikan Booking menolak dengan pesan yang menjelaskan apa yang kurang.',
                    "Kapasitas ada dua jenis. Layanan berat (PPF, Kaca Film) dihitung per hari; layanan ringan (Detailing, Premium Wash) dihitung per jam dengan slot {$slots}. Booking campuran dihitung sebagai berat. Tanggal yang penuh atau toko libur tidak bisa dipilih. Kapasitas harian diatur di Kalender Kapasitas; kapasitas per slot jam diatur di form Toko.",
                    'Yang boleh chat dengan customer: leader installer, store manager, dan direksi. Installer tidak ikut chat.',
                    'Customer bisa mengajukan ganti tanggal atau pembatalan; staf menyetujui atau menolak di kartu pengajuan pada booking. Staf juga bisa memindahkan tanggal lewat **Ubah Jadwal**.',
                    'Pelanggan tidak datang: Store Manager atau akses penuh dapat menandai tidak datang bila booking sudah Confirmed, tanggalnya sudah tiba, dan pengerjaan belum dimulai. Booking menjadi **Cancelled**, slot terlepas, dan DP yang sudah diterima dikembalikan penuh.',
                    'Customer menerima pengingat otomatis H-2, H-1, dan pagi hari-H. Teks kebijakan pembatalan yang tampil di aplikasi diatur admin di Operasional, Teks Aplikasi.',
                    'Booking yang sudah dibatalkan atau selesai tidak bisa diaktifkan lagi.',
                    'Layar foto penerimaan, Quality Check, dan serah terima ada di aplikasi staf versi terbaru. Bila tidak terlihat di aplikasi Anda, perbarui aplikasi staf.',
                ],
            ],
            [
                'id' => 'penjualan',
                'title' => 'Penjualan: Proses Referral, rincian harga, dan pelunasan',
                'summary' => 'Mencatat nilai penjualan sebuah booking yang sudah selesai: harga per layanan, pembayaran yang diterima, DP, voucher, dan kode referral.',
                'menus' => ['BookingResource', 'SalesResource', 'ReceivableResource'],
                'steps' => [
                    'Syaratnya booking sudah **Completed**. Di panel, buka daftar Booking dan pilih aksi **Proses Referral** pada booking itu. Biasanya dikerjakan kasir atau admin, bukan installer. Aplikasi staf versi terbaru punya **Catat Penjualan** dengan aturan persetujuan yang sama.',
                    'Isi **Rincian Harga per Layanan**: satu baris per layanan atau produk (jenis layanan, produk, bagian atau keterangan, jumlah, harga satuan, potongan baris). Pendapatan per jenis layanan di laporan mengikuti nilai baris ini; tanpa rincian, nilai dibagi rata antar jenis layanan. Untuk booking yang wajib rincian, form menolak bila belum ada baris bernilai.',
                    'Isi **Nominal Transaksi**: angka bersih setelah voucher, promo, atau nego. Selisihnya dengan jumlah rincian dianggap potongan. Nominal ini juga dasar poin referral (1 poin per Rp10.000).',
                    'Isi **Pembayaran diterima**. Boleh lebih dari satu baris (mis. sebagian tunai, sebagian transfer): pilih metode, rekening (otomatis bila dikosongkan), dan nominal. Jumlah pembayaran ditambah DP yang terpakai tidak boleh melebihi Nominal Transaksi; sisanya menjadi **Piutang Usaha**. Hapus semua baris bila belum ada pembayaran.',
                    'Isi bila ada: **Ukuran Kendaraan** (dasar menghitung nego harga), **Promo Total Pembelian**, **Kode Voucher** (otomatis ditandai Terpakai), dan **Kode Referral Partner**.',
                    'Simpan. Untuk staf tanpa akses penuh, pengajuan menunggu persetujuan akses penuh dan belum berlaku (lihat panduan Approval). Untuk akses penuh, nominal tersimpan dan jurnal pendapatan dibuat.',
                ],
                'tips' => [
                    "Nego harga hingga {$negotiationMax}% dari harga acuan matriks boleh tanpa persetujuan; lebih dari itu perlu persetujuan.",
                    'Uang muka dicatat lewat **Catat Uang Muka (DP)**; DP yang tidak terpakai dikembalikan lewat **Kembalikan Sisa DP**. DP yang terpakai otomatis mengurangi sisa tagihan saat Proses Referral.',
                    'Refund dilakukan lewat **Proses Refund** pada booking; untuk staf tanpa akses penuh refund menunggu persetujuan.',
                    'Piutang Usaha menampilkan sisa tagihan yang belum dibayar; pelunasannya dicatat di menu itu.',
                    'Salah mengisi kode referral? Kosongkan kolomnya lalu simpan untuk menghapusnya.',
                ],
            ],
            [
                'id' => 'garansi-maintenance',
                'title' => 'Garansi dan Maintenance PPF',
                'summary' => 'Dari menerbitkan garansi sampai jadwal maintenance berulang dan kunjungan customer.',
                'menus' => ['WarrantyResource'],
                'steps' => [
                    'Garansi dibuat staf atau didaftarkan customer di aplikasi. Garansi dari customer menunggu **review**; saat disetujui, customer mendapat poin registrasi garansi.',
                    'Untuk PPF, isi **Kuota Maintenance** (berapa kali boleh datang) dan **Interval** (jarak bulan antar kunjungan). Sistem menghitung tanggal kunjungan berikutnya sendiri.',
                    'Sekitar 7 hari sebelum jadwal, customer menerima notifikasi **Konfirmasi Kedatangan**. Customer bisa konfirmasi, memilih tanggal lain, atau menolak.',
                    'Staf atau sales boleh menghubungi customer lebih dulu (mis. lewat WhatsApp) lalu memakai aksi **Buatkan Booking** di tab Jadwal Maintenance garansi tersebut.',
                    'Customer yang datang tanpa jadwal dicatat lewat **Catat Kunjungan Maintenance**. Setiap kunjungan mengurangi kuota.',
                ],
                'tips' => [
                    "Ada masa toleransi {$graceDays} hari setelah tanggal jadwal. Setelah itu jadwal tidak hangus; customer diminta memilih tanggal baru dan kuota tidak terpakai.",
                    'Jadwal hanya hangus kalau customer menekan "Tidak bisa datang".',
                    'Kunjungan yang salah catat hanya bisa dibatalkan akses penuh; kuota dikembalikan.',
                    'Tab Jadwal Maintenance menunjukkan status tiap kunjungan: Menunggu Jadwal, Menunggu Konfirmasi, Dikonfirmasi, Hangus, Selesai.',
                ],
            ],
            [
                'id' => 'reward-voucher',
                'title' => 'Reward dan Voucher',
                'summary' => 'Menyusun katalog tukar poin, termasuk voucher diskon, dan memakai voucher di transaksi.',
                'menus' => ['RewardResource', 'RewardRedemptionResource'],
                'steps' => [
                    'Buka **Katalog Reward** dan buat reward baru. Pilih jenis **Barang/Layanan** (diproses staf) atau **Voucher Diskon**.',
                    'Untuk voucher, isi nominal diskon dan masa berlaku (hari sejak ditukar), lalu harga poin dan stok. Reward langsung muncul di katalog customer.',
                    'Customer menukar poin. Untuk voucher, kode unik (GNV-...) langsung terbit di **Voucher Saya**. Untuk barang, penukaran masuk ke **Klaim Reward** dan menunggu diproses.',
                    'Saat customer memakai voucher, pilih **Kode Voucher** di form **Proses Referral** pada booking (lihat panduan Penjualan). Nominal diskon dipotong dan voucher berstatus Terpakai.',
                ],
                'tips' => [
                    'Voucher melekat ke akun yang menukarnya; tidak bisa dipakai di booking customer lain.',
                    'Voucher yang kedaluwarsa atau sudah dipakai tidak bisa dipilih lagi.',
                    'Penukaran voucher bisa dibatalkan selama belum dipakai (poin dikembalikan). Setelah dipakai atau dibatalkan, statusnya terkunci.',
                    'Jenis reward tidak bisa diubah setelah pernah ditukar. Nonaktifkan reward dengan toggle Aktif, jangan dihapus.',
                ],
            ],
            [
                'id' => 'approval',
                'title' => 'Approval: pengeluaran, refund, dan referral',
                'summary' => 'Aksi sensitif yang harus disetujui atasan sebelum berlaku.',
                'menus' => ['TransactionApprovalRequestResource', 'FinanceTransactionApprovalRequestResource', 'FinanceTransactionResource'],
                'steps' => [
                    'Staf mengajukan aksi (pengeluaran, refund, atau Proses Referral). Pengajuan masuk ke daftar persetujuan dengan status menunggu: **Persetujuan Pengeluaran** untuk pengeluaran, **Persetujuan Transaksi** untuk refund dan referral.',
                    'Atasan membuka daftar yang sesuai, memeriksa rinciannya, lalu **Setujui** atau **Tolak**.',
                    'Aksi baru dijalankan setelah disetujui. Yang ditolak tidak mengubah data apa pun.',
                ],
                'tips' => [
                    'Pengeluaran bertingkat: staf, store manager, lalu direksi; semua nominal wajib disetujui direksi.',
                    'Refund dan referral dari staf non-akses-penuh wajib disetujui akses penuh.',
                    'Semua keputusan tercatat di Histori Aktivitas (siapa dan kapan).',
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
                ],
                'tips' => [
                    'Data stok dibatasi per toko; staf toko hanya melihat tokonya sendiri.',
                    'Riwayat pergerakan stok adalah jejak audit; jangan mengedit catatan lama, gunakan aksi koreksi yang tersedia.',
                    'Jangan menghapus memo utuh; hapus baris barang yang salah, atau hubungi admin.',
                ],
            ],
            [
                'id' => 'absensi',
                'title' => 'Absensi Karyawan',
                'summary' => 'Karyawan absen lewat aplikasi staf; atasan memeriksa dan menyetujui koreksi.',
                'menus' => ['AttendanceResource', 'AttendanceCorrectionRequestResource'],
                'steps' => [
                    'Karyawan menekan **Absen Masuk** saat tiba dan **Absen Keluar** saat pulang di aplikasi staf. Absen memakai lokasi dan hanya bisa dilakukan di dalam radius toko (diatur di form Toko).',
                    'Keterlambatan dihitung dari jam buka toko, dengan toleransi keterlambatan yang diatur di form Toko. Semua karyawan mengikuti jam buka toko; Ginnva tidak memakai shift.',
                    'Kalau karyawan lupa absen, ia mengajukan koreksi di aplikasi staf; atasan menyetujui atau menolak di menu **Koreksi Absensi**.',
                    'Rekap kehadiran dilihat di **Absensi Karyawan** (panel).',
                ],
                'tips' => [
                    'Laporan Absensi dan Utilisasi Teknisi memakai jam hadir ini, jadi koreksi yang disetujui ikut memengaruhi laporan.',
                    'Menu Jadwal Kerja (shift) tidak dipakai dan disembunyikan; abaikan bila ada petunjuk lama yang menyebutnya.',
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
                    '**DP**: uang muka booking. **PPN**: pajak yang dihitung pada transaksi sesuai pengaturan. **Piutang Usaha**: sisa tagihan yang belum dibayar.',
                    '**Akses penuh**: peran super admin dan direksi; melihat semua toko dan semua menu.',
                ],
                'tips' => [
                    'Bingung dengan isian tertentu? Banyak kolom di form punya teks bantuan kecil di bawahnya.',
                ],
            ],
        ];
    }
}
