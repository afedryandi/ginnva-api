<?php

namespace App\Support;

/**
 * Isi halaman "Panduan" di panel admin (2026-10-12): alur singkat per modul yang kompleks, ditulis dengan bahasa yang
 * dipakai staf. Tiap panduan punya daftar `menus` (nama class resource/halaman, tanpa namespace) -- panduan hanya
 * ditampilkan ke staf yang punya akses ke salah satu menu itu (akses penuh melihat semuanya). `menus` kosong = untuk semua.
 *
 * Isi harus mengikuti perilaku sistem yang sebenarnya; kalau aturan sistem berubah, perbarui panduannya di sini
 * (GuideCatalogTest memastikan setiap panduan lengkap).
 */
class GuideCatalog
{
    /**
     * @return array<int, array{id: string, title: string, summary: string, menus: array<int, string>, steps: array<int, string>, tips: array<int, string>}>
     */
    public static function all(): array
    {
        return [
            [
                'id' => 'booking',
                'title' => 'Booking: dari masuk sampai selesai',
                'summary' => 'Alur satu booking instalasi (Kaca Film / PPF) dari customer memesan sampai diserahterimakan.',
                'menus' => ['BookingResource', 'CapacityCalendar'],
                'steps' => [
                    'Customer memesan lewat aplikasi: pilih layanan, toko, dan tanggal. Booking masuk dengan status **Pending**.',
                    'Staf toko memeriksa kapasitas hari itu, lalu **Konfirmasi** dan mengisi lama pengerjaan (hari kerja). Status menjadi **Confirmed**. Slot toko baru terkunci saat dikonfirmasi, bukan saat pending.',
                    'Store Manager menugaskan installer yang mengerjakan (satu atau lebih). Penugasan tercatat di riwayat aktivitas.',
                    'Leader installer membuat **SPK** (inspeksi kendaraan) sebelum pengerjaan. Installer melengkapi SPK dari booking yang ditugaskan kepadanya.',
                    'Progres dikirim lewat **chat booking** per tahap (boleh dengan foto). Customer mendapat notifikasi otomatis di setiap tahap. Tahap hanya bisa maju, dan urutannya mengikuti produk yang dipesan.',
                    'Setelah tahap **QC** ditandai, booking baru bisa **Diselesaikan**. Status menjadi **Completed** dan tercatat di riwayat booking customer.',
                ],
                'tips' => [
                    'Yang boleh chat dengan customer: leader installer, store manager, dan direksi. Installer tidak ikut chat.',
                    'Customer bisa mengajukan ganti tanggal atau pembatalan; staf menyetujui atau menolak di kartu pengajuan pada booking.',
                    'Tanggal yang penuh atau toko libur tidak bisa dipilih. Kapasitas per hari bisa diatur per tanggal di Kalender Kapasitas.',
                    'Booking yang sudah dibatalkan tidak bisa diaktifkan lagi.',
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
                    'Ada masa toleransi **30 hari** setelah tanggal jadwal. Setelah itu jadwal tidak hangus; customer diminta memilih tanggal baru dan kuota tidak terpakai.',
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
                    'Saat customer memakai voucher di booking, pilih **Kode Voucher** di form transaksi booking. Nominal diskon dipotong dan voucher berstatus Terpakai.',
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
                    'Staf mengajukan aksi (pengeluaran, refund, atau proses referral). Pengajuan masuk ke daftar approval dengan status menunggu.',
                    'Atasan membuka daftar approval, memeriksa rinciannya, lalu **Setujui** atau **Tolak**.',
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
                    'Lihat posisi stok di **Daftar Stok** dan riwayat pergerakannya di halaman Riwayat (keluar/masuk, bahan baku, barang habis pakai).',
                    'Pengambilan atau pengembalian barang untuk pekerjaan dicatat lewat **Memo**. Pilih barang dan jumlahnya; stok ikut berubah.',
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
                'id' => 'absensi-jadwal',
                'title' => 'Absensi dan Jadwal Kerja',
                'summary' => 'Karyawan absen lewat aplikasi; jadwal shift diatur per karyawan.',
                'menus' => ['AttendanceResource', 'WorkScheduleResource', 'ShiftResource', 'AttendanceCorrectionRequestResource'],
                'steps' => [
                    'Karyawan **Clock In** dan **Clock Out** lewat aplikasi staf. Keterlambatan dihitung dari jam buka toko.',
                    'Atur shift dan pola jadwal di **Jadwal Kerja**, lalu tugaskan ke karyawan. Perubahan satu hari bisa dilakukan dengan klik sel di kalender mingguan.',
                    'Kalau karyawan lupa absen, ia mengajukan **koreksi absensi**; atasan menyetujui atau menolak.',
                ],
                'tips' => [
                    'Laporan Absensi dan Utilisasi Teknisi memakai jam hadir ini, jadi koreksi yang disetujui ikut memengaruhi laporan.',
                ],
            ],
            [
                'id' => 'istilah',
                'title' => 'Istilah yang sering muncul',
                'summary' => 'Arti singkat istilah di layar dan laporan.',
                'menus' => [],
                'steps' => [
                    '**Pending / Confirmed / Completed / Cancelled**: status booking (menunggu, dikonfirmasi, selesai, dibatalkan).',
                    '**SPK**: Surat Perintah Kerja, form inspeksi kendaraan sebelum pengerjaan.',
                    '**Kapasitas**: jumlah mobil yang bisa dikerjakan toko per hari. Hanya booking Confirmed yang memakainya.',
                    '**Opname**: penghitungan fisik stok untuk dicocokkan dengan catatan sistem.',
                    '**DP**: uang muka booking. **PPN**: pajak yang dihitung pada transaksi sesuai pengaturan.',
                    '**Akses penuh**: peran super admin dan direksi; melihat semua toko dan semua menu.',
                ],
                'tips' => [
                    'Bingung dengan isian tertentu? Banyak kolom di form punya teks bantuan kecil di bawahnya.',
                ],
            ],
        ];
    }
}
