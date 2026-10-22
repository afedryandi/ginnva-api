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
        $photoMonths = (int) config('booking.photo_retention_months', 24);
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
                'id' => 'layanan-ppf',
                'title' => 'Layanan PPF: langkah di sistem',
                'summary' => 'Yang dikerjakan staf di sistem untuk satu pekerjaan PPF, dari pesanan sampai garansi dan maintenance. Alur umum ada di panduan Booking; di sini hanya hal yang khusus PPF.',
                'menus' => ['BookingResource', 'WarrantyResource', 'InventoryItemResource'],
                'steps' => [
                    'PPF adalah layanan berat: memakai kapasitas harian toko dan bisa berlangsung beberapa hari. Saat **Konfirmasi Booking**, isi lama pengerjaan (hari kerja); sistem memeriksa seluruh hari kerja dalam rentang itu, dan tanggal mulai yang hari lanjutannya penuh tidak bisa dipilih.',
                    'Siapkan unit: tugaskan installer, ambil foto penerimaan (empat sudut), catat kerusakan cat yang sudah ada sebagai foto kerusakan lengkap dengan keterangannya, dan buat **SPK**.',
                    'Catat gulungan film yang dipakai: di menu **Produk PPF/WF** pilih gulungannya lalu **Catat Pemakaian** untuk booking ini. Untuk produk yang diatur **Wajib Lacak Roll (Batch Number)** di **Daftar Produk**, SPK baru bisa ditandai selesai setelah pemakaian roll dicatat.',
                    "Kirim progres lewat **chat booking** pada tahap {$ppfStages}. Pada booking PPF + Kaca Film, tahap PPF berjalan di jalur kedua. Tahap Detailing di jalur ini adalah salah satu tahap pengerjaan PPF, bukan pemesanan layanan Detailing.",
                    'Tandai tahap **Quality Check**, lalu isi daftar periksa QC PPF. Butir bawaannya: tidak ada gelembung atau debu di bawah film, tepi dan sudut rapi dan menempel kuat, tidak ada kerutan atau potongan salah, bodi dan kaca bersih dari sisa larutan, tidak ada baret baru pada cat. Akses penuh dapat mengubah butirnya di menu **Daftar Periksa QC**.',
                    'Serah terima: sampaikan petunjuk perawatan dan terima tanda tangan basah di kertas (wajib untuk PPF), lalu **Selesaikan Booking**.',
                    'Buat **Garansi** (atau minta customer mendaftarkannya di aplikasi): pilih kategori PPF, isi plat nomor, tipe mobil, seri produk, **Posisi Pemasangan** (penuh atau sebagian, dengan keterangan area), dan **Kode Gulungan** (isi kode kedua bila satu gulungan tidak cukup). Isi **Kuota Maintenance** dan **Interval Maintenance** bila garansi menawarkan maintenance.',
                    'Garansi dari customer menunggu **review**; setelah disetujui, jadwal maintenance berjalan otomatis (lihat panduan Garansi dan Maintenance PPF). Penjualannya dicatat lewat **Proses Referral** dengan baris layanan PPF.',
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
                'summary' => 'Yang dikerjakan staf di sistem untuk satu pekerjaan Kaca Film, dari pesanan sampai garansi. Alur umum ada di panduan Booking; di sini hanya hal yang khusus Kaca Film.',
                'menus' => ['BookingResource', 'WarrantyResource', 'InventoryItemResource'],
                'steps' => [
                    'Kaca Film adalah layanan berat: memakai kapasitas harian toko. Saat **Konfirmasi Booking**, isi lama pengerjaan (hari kerja) bila perlu.',
                    'Siapkan unit: tugaskan installer, ambil foto penerimaan (empat sudut) dan foto kerusakan yang sudah ada, lalu buat **SPK**.',
                    'Catat gulungan film yang dipakai: di menu **Produk PPF/WF** pilih gulungannya lalu **Catat Pemakaian** untuk booking ini. Untuk produk yang diatur **Wajib Lacak Roll (Batch Number)** di **Daftar Produk**, SPK baru bisa ditandai selesai setelah pemakaian roll dicatat.',
                    "Kirim progres lewat **chat booking** pada tahap {$kacaFilmStages}. Pada booking Kaca Film + PPF, tahap Kaca Film berjalan di jalur pertama dan PPF di jalur kedua.",
                    'Tandai tahap **Quality Check**, lalu isi daftar periksa QC Kaca Film. Butir bawaannya: tidak ada gelembung, debu, atau goresan pada film, tepi film rapi dan merata di semua kaca, kaca bersih tanpa sisa larutan, semua kaca naik-turun normal, defogger dan sensor tidak terganggu. Akses penuh dapat mengubah butirnya di menu **Daftar Periksa QC**.',
                    'Serah terima: sampaikan petunjuk perawatan dan terima tanda tangan basah di kertas (wajib untuk Kaca Film), lalu **Selesaikan Booking**.',
                    'Buat **Garansi** (atau minta customer mendaftarkannya di aplikasi): pilih kategori Window Film, isi plat nomor, tipe mobil, dan seri produk. Di bagian Detail Instalasi isi **Kode Gulungan — Kaca Depan** dan, bila mobil ini juga dipasang di posisi itu, **Kode Gulungan — Kaca Samping & Belakang**. Pilihan hanya menampilkan gulungan yang terdaftar untuk posisi tersebut.',
                    'Garansi dari customer menunggu **review**. Penjualannya dicatat lewat **Proses Referral** dengan baris layanan Kaca Film.',
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
                'summary' => 'Yang dikerjakan staf di sistem untuk satu pekerjaan Detailing. Detailing adalah layanan ringan dengan jalur yang lebih singkat daripada PPF dan Kaca Film.',
                'menus' => ['BookingResource', 'BookingServiceTypeResource'],
                'steps' => [
                    "Detailing adalah layanan ringan: customer wajib memilih jam, kapasitas dihitung per jam (slot {$slots}; jumlah per slot diatur di form Toko), dan tidak memakai kapasitas harian layanan berat. Harga paket tetap untuk semua ukuran kendaraan.",
                    'Konfirmasi dengan **Konfirmasi Booking**, lalu tugaskan installer.',
                    'Saat unit datang, ambil foto penerimaan (empat sudut) dan foto kerusakan yang sudah ada, lalu buat **SPK**.',
                    'Detailing tidak punya tahap pengerjaan. Kabari customer lewat **chat booking** bila perlu (teks atau foto); setelah selesai dikerjakan, langsung tandai tahap **Quality Check**.',
                    'Isi daftar periksa QC Detailing. Butir bawaannya: interior bersih dan kering, eksterior bersih tanpa sisa pasta atau compound, kaca bersih tanpa noda, velg dan ban bersih, tidak ada kerusakan baru. Akses penuh dapat mengubah butirnya di menu **Daftar Periksa QC**.',
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
                'summary' => 'Yang dikerjakan staf di sistem untuk satu pekerjaan Premium Wash. Seperti Detailing, ini layanan ringan dengan jalur singkat.',
                'menus' => ['BookingResource', 'BookingServiceTypeResource'],
                'steps' => [
                    "Premium Wash adalah layanan ringan: customer wajib memilih jam, kapasitas dihitung per jam (slot {$slots}; jumlah per slot diatur di form Toko), dan tidak memakai kapasitas harian layanan berat. Harga paket tetap untuk semua ukuran kendaraan.",
                    'Konfirmasi dengan **Konfirmasi Booking**, lalu tugaskan installer.',
                    'Saat unit datang, ambil foto penerimaan (empat sudut) dan foto kerusakan yang sudah ada, lalu buat **SPK**.',
                    'Premium Wash tidak punya tahap pengerjaan. Setelah selesai dikerjakan, langsung tandai tahap **Quality Check**.',
                    'Isi daftar periksa QC Premium Wash. Butir bawaannya: bodi bersih tanpa sisa sabun, kaca bersih tanpa noda, velg dan ban bersih, interior disedot dan dilap, tidak ada kerusakan baru. Akses penuh dapat mengubah butirnya di menu **Daftar Periksa QC**.',
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
                'id' => 'spk',
                'title' => 'SPK: surat perintah kerja kendaraan',
                'summary' => 'Form inspeksi dan perintah kerja untuk satu booking: data kendaraan, daftar pekerjaan, perlengkapan, dan kerusakan yang sudah ada sebelum pengerjaan.',
                'menus' => ['SpkResource'],
                'steps' => [
                    'Buat SPK dari booking yang sudah Dikonfirmasi dan belum punya SPK (satu booking satu SPK). Yang bisa membuat SPK baru: leader installer, Store Manager, direksi, dan super admin. Installer dan staf lain dengan akses menu melengkapi SPK yang sudah ada, termasuk dari aplikasi staf.',
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
                    'Menghapus SPK hanya boleh akses penuh atau staf yang diberi hak hapus.',
                ],
            ],
            [
                'id' => 'quotation-lead',
                'title' => 'Quotation (Lead): menindaklanjuti calon pelanggan',
                'summary' => 'Permintaan penawaran dari aplikasi atau website masuk sebagai lead. Tugas staf: menghubungi, mencatat perkembangan, dan menetapkan hasilnya.',
                'menus' => ['QuotationResource'],
                'steps' => [
                    'Lead baru berstatus New dan muncul sebagai angka badge di menu. Lead dari website yang belum punya toko diteruskan ke akun akses penuh dan staf yang punya akses menu Quotation.',
                    'Hubungi calon pelanggan lewat tombol **WhatsApp** atau **Telepon** di baris lead. Setelah dihubungi, ubah statusnya lewat **Ubah Status**: New, lalu Contacted, lalu Closed atau Cancelled.',
                    'Isi **Penanggung Jawab** (staf yang menangani), **Kanal Masuk** (dari mana lead datang: WhatsApp, telepon, datang langsung, dan sebagainya), dan periksa **Produk Diminati** serta catatan pelanggan.',
                    'Setelah ada kepastian, tetapkan **Hasil**: Deal bila lead sudah jadi pelanggan, booking, atau partner; Tidak Jadi bila batal. Untuk Tidak Jadi, **Alasan Tidak Jadi** wajib dipilih (harga di atas anggaran, memilih penyedia lain, tidak merespons lagi, belum butuh atau menunda, lokasi terlalu jauh, layanan tidak sesuai kebutuhan, atau lainnya).',
                ],
                'tips' => [
                    'Lead berstatus New yang belum ditindaklanjuti lebih dari 24 jam memicu pengingat otomatis ke staf.',
                    'Kolom **Direspons Setelah** menunjukkan berapa lama lead menunggu sampai staf merespons; dipakai laporan kecepatan respons.',
                    'Hasil dan alasan tercatat di riwayat lead; pilih alasan yang paling mendekati supaya laporan bisa mengelompokkannya.',
                ],
            ],
            [
                'id' => 'invoice',
                'title' => 'Invoice: tagihan dan pembayaran',
                'summary' => 'Membuat dokumen tagihan untuk pelanggan, mencatat pembayaran bertahap, dan mencetaknya.',
                'menus' => ['InvoiceResource'],
                'steps' => [
                    'Buat invoice baru: pilih **Toko**, isi **Referensi Booking (opsional)** bila terkait booking, lalu **Tanggal Dibuat** dan **Jatuh Tempo**. Data pelanggan diisi manual atau dengan memilih **Akun Customer (opsional)**.',
                    'Di **Detail Produk** tambahkan baris: nama, jumlah, satuan, harga (Rp), dan diskon (%) per baris. Produk dari katalog boleh dipilih (opsional). Invoice harus punya minimal satu baris.',
                    'Di **Rincian Tagihan** atur **Jenis Diskon Transaksi**, **Nilai Diskon Transaksi**, **Biaya Pengiriman**, dan **Biaya Lainnya**. Total tagihan dihitung otomatis.',
                    'Cetak lewat **Cetak PDF**.',
                    'Pembayaran bertahap dicatat lewat **Catat Pembayaran**: nominal ditambahkan ke yang sudah dibayar, dan status berubah ke Lunas otomatis begitu terpenuhi. **Tandai Lunas** langsung mengisi sisa tagihan penuh; jangan dipakai untuk pembayaran bertahap. **Void** membatalkan invoice dan bersifat final.',
                ],
                'tips' => [
                    'Invoice hanya bisa diubah selama berstatus draft atau belum dibayar. Yang sudah Lunas atau Void tidak bisa diedit; koreksi lewat Void lalu buat invoice baru.',
                    'Invoice adalah dokumen tagihan: tidak membuat jurnal dan tidak menghitung pajak atau PPN. Penjualan booking dicatat lewat **Proses Referral** (lihat panduan Penjualan).',
                    'Nomor invoice dibuat otomatis per toko dan hari.',
                ],
            ],
            [
                'id' => 'teknisi',
                'title' => 'Teknisi: data, komisi, dan persetujuan',
                'summary' => 'Data teknisi dan installer, level sertifikasi, dan komisi per pekerjaan.',
                'menus' => ['TechnicianResource'],
                'steps' => [
                    'Isi **Nama Teknisi**, **Toko**, **No. Telepon / HP**, dan **Level Sertifikasi**. Hubungkan ke **Akun Installer** supaya level sertifikasi terlihat saat menugaskan installer di Booking; boleh dikosongkan bila akun loginnya belum dibuat.',
                    'Teknisi baru berstatus Menunggu Review. Akses penuh menekan **Aktifkan** untuk menyetujui atau **Tolak** (alasan penolakan wajib diisi dan tersimpan di catatan teknisi).',
                    'Isi **Komisi per Pekerjaan (Rp)** bila ada: nominal tetap per booking yang dikerjakan, bukan persentase. Bila satu booking dikerjakan lebih dari satu teknisi, masing-masing mendapat nominal penuh, bukan dibagi.',
                    'Bagian Riwayat Pekerjaan di halaman teknisi menampilkan 10 pekerjaan terbarunya.',
                ],
                'tips' => [
                    'Komisi per Pekerjaan diabaikan begitu teknisi punya minimal satu baris di tab Tarif per Layanan; tarif per layanan yang dipakai.',
                    'Hanya akses penuh yang mengubah Status teknisi.',
                ],
            ],
            [
                'id' => 'tanggal-tidak-tersedia',
                'title' => 'Tanggal Tidak Tersedia: menutup tanggal booking',
                'summary' => 'Menutup tanggal tertentu untuk satu toko (libur, renovasi, acara) supaya customer tidak bisa memilihnya.',
                'menus' => ['BlockedDateResource'],
                'steps' => [
                    'Buka menu, lalu tambah data baru. Pilih **Toko / Workshop**.',
                    'Isi **Tanggal Mulai**. Untuk menutup beberapa hari sekaligus, isi juga **Sampai Tanggal**; kosongkan untuk satu hari saja.',
                    'Isi **Alasan** (opsional) supaya tim lain tahu kenapa tanggal itu ditutup.',
                    'Gunakan filter **Hanya yang akan datang** untuk menyembunyikan tanggal yang sudah lewat.',
                ],
                'tips' => [
                    'Tanggal yang ditutup tidak bisa dipilih customer di aplikasi.',
                    'Untuk mengatur jumlah pekerjaan per hari (bukan menutup penuh), pakai Kalender Kapasitas.',
                ],
            ],
            [
                'id' => 'review-toko',
                'title' => 'Review Toko: menindaklanjuti ulasan pelanggan',
                'summary' => 'Ulasan yang diberikan pelanggan setelah booking selesai, dan tindak lanjut untuk ulasan yang kurang baik.',
                'menus' => ['StoreReviewResource'],
                'steps' => [
                    'Setelah booking selesai, pelanggan memberi review di aplikasi: **Sentimen**, **Aspek yang Disebut**, dan **Komentar**. Staf tidak membuat review; menu ini hanya untuk membaca dan menindaklanjuti.',
                    'Review negatif otomatis mengirim pemberitahuan ke akun akses penuh dan ke staf toko itu yang punya akses menu Review Toko. Staf tanpa akses menu itu, atau dari toko lain, tidak diberi tahu.',
                    'Buka review, hubungi pelanggan bila perlu, lalu tekan **Tandai Ditindaklanjuti** dan isi **Catatan Tindak Lanjut**.',
                    'Gunakan filter **Sudah Ditindaklanjuti** untuk melihat review yang masih terbuka.',
                ],
                'tips' => [
                    'Satu booking hanya bisa diberi satu review.',
                ],
            ],
            [
                'id' => 'pengaturan-booking',
                'title' => 'Pengaturan booking: Daftar Periksa QC dan Teks Aplikasi',
                'summary' => 'Dua pengaturan yang memengaruhi alur booking dan hanya diubah akses penuh.',
                'menus' => ['QcChecklistItemResource', 'AppSettingResource'],
                'steps' => [
                    '**Daftar Periksa QC**: pilih **Layanan**, lalu tulis **Butir yang Diperiksa** sebagai pernyataan yang harus benar (mis. "Tepi film rapi dan menempel kuat"). Atur **Urutan**.',
                    'Centang **Wajib Lulus** untuk butir yang menentukan: bila butir wajib tidak lulus, Quality Check dinyatakan tidak lulus. Matikan **Dipakai** untuk berhenti memeriksa sebuah butir tanpa menghapus riwayatnya.',
                    '**Teks Aplikasi**: ubah **Isi Teks** (saat ini kebijakan pembatalan booking) yang tampil ke pelanggan. Perubahan langsung berlaku tanpa rilis aplikasi. Kunci teksnya tetap; tidak ada tambah atau hapus.',
                ],
                'tips' => [
                    'Perubahan Daftar Periksa QC berlaku untuk pemeriksaan berikutnya; hasil lama menyimpan salinan butirnya sendiri.',
                    'Teks kebijakan pembatalan bawaan netral ("Tidak ada biaya pembatalan"); ganti dengan aturan toko yang sebenarnya.',
                    'Kedua menu ini hanya bisa diubah akses penuh.',
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
                'menus' => ['TransactionApprovalRequestResource', 'FinanceTransactionApprovalRequestResource', 'FinanceTransactionResource', 'FinanceSettingsPage'],
                'steps' => [
                    'Staf mengajukan aksi (pengeluaran, refund, atau Proses Referral). Pengajuan masuk ke daftar persetujuan dengan status menunggu: **Persetujuan Pengeluaran** untuk pengeluaran, **Persetujuan Transaksi** untuk refund dan referral.',
                    'Atasan membuka daftar yang sesuai, memeriksa rinciannya, lalu **Setujui** atau **Tolak**.',
                    'Aksi baru dijalankan setelah disetujui. Yang ditolak tidak mengubah data apa pun.',
                ],
                'tips' => [
                    'Pengeluaran bertingkat: staf, store manager, lalu direksi. Secara bawaan semua nominal wajib disetujui direksi. Di **Pengaturan Keuangan** (akses penuh) bisa diisi **Batas yang cukup disetujui Store Manager**: pengeluaran sampai nominal itu langsung dicatat setelah Store Manager menyetujui. Pengeluaran dari Kas Kecil punya batas sendiri di Rekening Kas & Bank.',
                    'Refund dan referral dari staf non-akses-penuh wajib disetujui akses penuh.',
                    'Semua keputusan tercatat di Histori Aktivitas (siapa dan kapan).',
                ],
            ],
            [
                'id' => 'transaksi-keuangan',
                'title' => 'Transaksi Keuangan: pemasukan dan pengeluaran toko',
                'summary' => 'Mencatat uang masuk dan keluar harian toko, lengkap dengan kategori, rekening, dan bukti.',
                'menus' => ['FinanceTransactionResource', 'FinanceCategoryResource'],
                'steps' => [
                    'Buka Transaksi Keuangan dan buat transaksi baru: pilih **Tipe** (pemasukan atau pengeluaran), **Kategori**, isi **Nominal**, **Toko**, **Rekening Kas/Bank** (dari mana uang keluar atau ke mana uang masuk), **Tanggal Transaksi**, dan **Keterangan**. Lampirkan **Bukti/Nota (opsional)** berupa foto atau PDF maksimal 5 MB.',
                    'Pemasukan langsung dicatat dan menjadi jurnal. Pengeluaran dari staf tanpa akses penuh tidak langsung dicatat: masuk antrean persetujuan (staf, Store Manager, lalu direksi; lihat panduan Approval) dan baru menjadi jurnal setelah disetujui.',
                    'Pengeluaran kecil dari rekening Kas Kecil dicatat dengan memilih rekening itu. Sampai batas per bukti yang diatur di Rekening Kas & Bank, karyawan toko yang sama atau pemegang kas tidak perlu menunggu persetujuan direksi.',
                    'Kolom **No. Jurnal** menunjukkan jurnal dari transaksi itu; **Belum terposting ke jurnal** menandai transaksi yang belum punya jurnal.',
                    'Akses penuh yang mengubah transaksi yang sudah punya jurnal wajib mengisi **Alasan Perubahan**; menghapusnya wajib mengisi **Alasan Penghapusan**. Keduanya tercatat.',
                    'Kategori diatur di **Kategori Keuangan**: tiap kategori bertipe masuk atau keluar dan terhubung ke satu akun di Bagan Akun. Kategori yang belum terhubung ditandai **Belum terhubung akun**. Kategori yang sudah dipakai tidak bisa dihapus; nonaktifkan saja.',
                ],
                'tips' => [
                    'Setiap transaksi wajib terikat ke satu toko; Store Manager hanya melihat dan mencatat transaksi tokonya sendiri.',
                    'Kategori nonaktif tidak muncul lagi di transaksi baru, tetapi riwayat lamanya tetap utuh.',
                    'Data identik (nominal, kategori, toko, tanggal, keterangan) dari akun yang sama dalam 10 menit terakhir ditolak, untuk mencegah klik ganda. Cek daftar bila sudah merasa mengirim.',
                    'Pengeluaran dari rekening Kas Kecil ditolak bila nominalnya melebihi saldo kas kecil itu.',
                    'Pencatatan nilai penjualan booking tidak dilakukan di sini; gunakan Proses Referral (lihat panduan Penjualan).',
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
                    '**Rekonsiliasi Bank** (akses penuh): unggah mutasi lewat **Import Mutasi** (CSV atau Excel dengan 3 kolom: tanggal, keterangan, nominal; nominal positif uang masuk dan negatif uang keluar; baris pertama dianggap judul). Lalu **Cocokkan Otomatis**; sisanya dicocokkan manual lewat **Cocokkan** ke baris jurnal. Mutasi yang memang tidak perlu dicocokkan (mis. biaya admin bank yang belum dicatat) ditandai **Tandai Diabaikan**. Kecocokan atau pengabaian bisa dibatalkan lewat **Batalkan Kecocokan** atau **Batalkan Pengabaian**. Bukti diekspor lewat **Ekspor Bukti Rekonsiliasi**.',
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
                'summary' => 'Membuat dan mengoreksi jurnal manual serta mengelola struktur akun. Hanya akses penuh; staf toko memakai Transaksi Keuangan yang lebih sederhana.',
                'menus' => ['JournalEntryResource', 'ChartOfAccountResource'],
                'steps' => [
                    '**Bagan Akun**: tiap akun punya kode, nama, klasifikasi, dan saldo normal. **Akun Induk (opsional)** hanya bisa memilih akun header dengan klasifikasi yang sama. Matikan **Bisa Diposting Transaksi** untuk akun header (pembungkus seperti "Aset Lancar") yang tidak boleh menerima transaksi langsung.',
                    'Aktifkan **Akun Kas / Bank** untuk akun kas dan setara kas: jurnal yang menyentuhnya dihitung sebagai arus kas. Untuk akun non-kas, pilih **Kategori Arus Kas** (kosong dianggap Operasional). Struktur akun yang sudah dipakai jurnal dikunci.',
                    '**Impor Akun** dari Excel atau CSV hanya membuat akun baru; kode yang sudah ada dilewati. Bila ada satu baris yang salah, tidak ada akun yang dibuat. Pakai hasil **Ekspor Excel** sebagai templat.',
                    '**Jurnal Umum**: buat jurnal baru dengan **Tanggal**, **Keterangan**, **Toko (opsional)**, lalu baris **Akun**, **Debit**, dan **Kredit**. Minimal 2 baris dan total debit harus sama dengan total kredit. Lampiran (foto atau PDF, maksimal 5 MB) opsional.',
                    'Jurnal disimpan sebagai draf, lalu **Posting**. Jurnal yang sudah diposting terkunci total. Anda tidak bisa memposting jurnal buatan sendiri; harus orang lain yang berwenang.',
                    'Koreksi hanya lewat **Balik Jurnal**: isi **Tanggal Jurnal Pembalik** (tidak boleh sebelum tanggal jurnal asli atau di periode yang sudah ditutup) dan **Alasan Pembalikan**. Jurnal asli tetap tersimpan. Jurnal otomatis dari modul lain (booking, penggajian, dan sebagainya) tidak dibalik di sini, melainkan lewat modul asalnya (**Dibalik lewat modul asal**).',
                ],
                'tips' => [
                    'Jurnal bertanggal di periode yang sudah ditutup tidak bisa dibuat, diubah, atau diposting (lihat panduan Tutup periode).',
                    'Sumber jurnal tampil di daftar: jurnal buatan sistem (booking, penggajian, penyusutan, dan lainnya) berbeda dari jurnal manual.',
                    '**Ekspor Excel** di Jurnal Umum mengikuti rentang tanggal yang dipilih.',
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
                    '**Umur Hutang** dan **Umur Piutang** mengelompokkan sisa tagihan aktif menurut keterlambatan dari jatuh tempo, per supplier atau per customer. Staf toko otomatis dibatasi ke tokonya; akses penuh bisa memilih toko atau "Pusat / Tanpa Toko". Bisa diekspor ke Excel atau PDF.',
                ],
                'tips' => [
                    'Template Tagihan Rutin memposting jurnal otomatis tiap bulan, jadi hanya akses penuh yang membuat atau mengubahnya.',
                    'Template yang belum pernah menghasilkan tagihan cukup dinonaktifkan bila hanya ingin dijeda.',
                    'Piutang Usaha menampilkan sisa tagihan customer yang belum dibayar (lihat panduan Penjualan).',
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
                    'Membuka kembali sebuah bulan butuh **Alasan membuka kembali**; keputusannya tercatat. Jurnal di bulan itu bisa dibuat atau diubah lagi sampai ditutup ulang.',
                    '**Tutup Tahun Buku**: setelah tahun berlalu, pilih **Tahun**, baca **Yang akan terjadi**, isi **Catatan**, lalu **Tutup Tahun Buku**. Sistem membuat jurnal penutup bertanggal 31 Desember per toko (dan satu untuk jurnal tanpa toko) yang menolkan akun Laba Rugi ke Laba Ditahan (3200). Syaratnya: tidak ada jurnal Draft di tahun itu dan periode Desember belum ditutup. Jadi tutup tahun dulu, baru tutup Desember.',
                    '**Batalkan Penutupan** (butuh **Alasan**) membalik jurnal penutup. Periode Desember harus terbuka untuk membatalkannya.',
                    '**Rekonsiliasi Sub-Buku** membandingkan saldo akun kontrol di Buku Besar dengan total sub-buku (Piutang, Hutang, DP, Aset Tetap, Persediaan). Selisih perlu dijelaskan akuntan. **Sinkronkan Persediaan** membuat satu jurnal penyesuaian yang menyamakan akun persediaan dengan nilai stok sekarang; pilih akun lawan dengan hati-hati dan minta akuntan memeriksanya.',
                ],
                'tips' => [
                    'Laporan Laba Rugi periode tidak ikut menghitung jurnal penutup tahun, jadi laba tahun itu tetap terlihat.',
                    'Peringatan di daftar periksa boleh diabaikan dengan sadar; butir yang memblokir tidak bisa.',
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
                'menus' => ['BalanceSheetReport', 'TrialBalanceReport', 'GeneralLedgerReport', 'IncomeStatementReport', 'CashFlowReport', 'FinanceReport', 'FinanceDashboard'],
                'steps' => [
                    '**Neraca**: Aset sama dengan Kewajiban ditambah Modal pada **Per Tanggal** yang dipilih; **Bandingkan Dengan** menampilkan periode pembanding.',
                    '**Neraca Saldo**: saldo tiap akun pada tanggal yang dipilih. Isi **Dari Tanggal (opsional)** untuk melihat Saldo Awal, Mutasi, dan Saldo Akhir. Akun Laba Rugi dihitung sejak 1 Januari tahun laporan.',
                    '**Buku Besar**: rincian tiap baris jurnal pada satu akun dalam rentang tanggal, dengan saldo berjalan. Dipakai untuk mengecek kenapa saldo sebuah akun sebesar itu. **Akun Sebelumnya** dan **Akun Berikutnya** pindah antar akun.',
                    '**Laporan Laba Rugi**: untuk satu rentang periode (bukan kumulatif), dengan **Bandingkan Dengan** untuk periode lain.',
                    '**Laporan Arus Kas**: metode langsung, dikelompokkan Operasional, Investasi, dan Pendanaan menurut Kategori Arus Kas tiap akun. **Tampilkan rincian per jurnal** menampilkan jurnal satu per satu.',
                    '**Laporan Keuangan**: ringkasan bulanan pemasukan dan pengeluaran dari Transaksi Keuangan dengan rincian per kategori. **Kelola Widget** memilih akun yang dipin sebagai kartu saldo.',
                    '**Dasbor Keuangan**: kas, laba bulan ini, piutang dan hutang, dan hal yang perlu ditindak dalam satu layar. **Saldo Kas & Bank** dan **Anggaran vs Realisasi** punya panduan sendiri.',
                    'Semua laporan bisa diekspor lewat **Export ke Excel** atau **Export ke PDF**; ekspor tercatat di Histori Aktivitas.',
                ],
                'tips' => [
                    'Memilih toko tidak mencakup jurnal pusat (tanpa toko, mis. gaji pusat atau penyusutan). Pilih "Pusat / Tanpa Toko" untuk melihatnya, atau kosongkan pilihan toko untuk semuanya.',
                    'Laporan keuangan menghitung hanya jurnal yang sudah diposting.',
                    'Menu ini terbuka untuk akses penuh; staf lain bisa diberi izin lihat lewat Hak Akses Detail, dan staf yang punya toko dikunci ke tokonya.',
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
                'id' => 'aset-tetap',
                'title' => 'Aset Tetap: kode QR, serah terima, dan penyusutan',
                'summary' => 'Mendaftarkan peralatan dan perlengkapan toko, mencetak kode QR, mencatat serah terima, dan melacak nilai bukunya.',
                'menus' => ['AssetResource'],
                'steps' => [
                    'Tambah aset di **Aset Tetap**: isi **Nama Aset**, **Kategori**, **Status**, **Dipegang Oleh**, **Lokasi (Toko)**, **Tanggal Masuk**, dan **Catatan**. **Kode Aset / QR** dibuat otomatis oleh sistem dan tidak bisa diubah.',
                    'Status aset: **Aktif Dipakai**, **Sedang Diperbaiki**, **Rusak**, **Dijual**, atau **Hilang**. Aset yang rusak, diperbaiki, atau hilang muncul di widget Aset Bermasalah pada Dashboard Inventaris.',
                    'Banyak aset sekaligus: pilih **Download Template**, isi Excel mengikuti formatnya, lalu **Import Excel**. Baris pertama (judul) dilewati. **Dipegang Oleh** dicocokkan persis ke nama user dan **Lokasi** ke nama toko; bila tidak ketemu, kolom itu dikosongkan tetapi asetnya tetap dibuat, jadi periksa hasilnya.',
                    'Cetak label dengan **Unduh QR** (satu aset) atau **Unduh QR (PDF Massal)** (beberapa aset terpilih). Tempel label di aset agar bisa dipindai.',
                    'Pindah tangan atau pindah toko dicatat lewat **Serah Terima**: isi **Diserahkan Ke**, **Pindah Ke Toko** (hanya akses penuh), **Kondisi Fisik Saat Ini**, dan **Alasan / Keterangan Serah Terima**. Riwayat serah terima tersimpan sebagai jejak.',
                    'Isi **Jadwal Maintenance Berikutnya** bila aset butuh servis atau kalibrasi berkala; admin diberi tahu otomatis saat jatuh tempo.',
                    'Untuk nilai buku, isi **Tanggal Beli**, **Harga Beli**, **Umur Ekonomis (tahun)**, dan **Nilai Residu** (boleh 0); sistem menghitung **Nilai Buku Saat Ini** dengan garis lurus. Isi **Akun Aset (Bagan Akun)** bersama **Akun Akumulasi Penyusutan** supaya aset ikut disusutkan otomatis tiap bulan. Kosongkan semuanya bila aset tidak perlu dilacak nilainya.',
                ],
                'tips' => [
                    'Data aset dibatasi per toko; staf toko hanya melihat aset tokonya.',
                    'Aset baru yang dibeli lewat Permohonan Pembelian belum otomatis menjadi baris Aset; tambahkan sendiri di sini.',
                ],
            ],
            [
                'id' => 'permohonan-pembelian',
                'title' => 'Permohonan Pembelian barang dan aset',
                'summary' => 'Mengajukan pembelian bahan, barang habis pakai, atau aset; persetujuan; sampai ditandai terpenuhi dan tercatat sebagai hutang.',
                'menus' => ['PurchaseRequestResource'],
                'steps' => [
                    'Staf membuat permohonan di **Permohonan Pembelian**: pilih **Toko / Workshop**, **Jenis Barang** (Bahan Baku, Barang Habis Pakai, atau Aset Baru), **Barang** (atau **Nama Barang / Aset yang Diminta** untuk aset baru), **Jumlah**, dan **Alasan / Catatan**. Permohonan masih bisa diubah selama statusnya menunggu.',
                    'Hanya akses penuh (super admin atau direksi) yang menekan **Setujui** atau **Tolak** (isi **Alasan Ditolak**). Permohonan yang sudah diproses terkunci; bila dua orang menekan bersamaan, yang kedua mendapat pemberitahuan "sudah diproses".',
                    'Setelah barang diterima, catat dulu masuknya lewat **Catat Stok** pada barangnya, baru tekan **Tandai Terpenuhi**. Aksi ini bisa dilakukan akses penuh atau pengaju permohonan.',
                    'Saat **Tandai Terpenuhi** isi **Total Biaya Aktual**, **Supplier** (supplier baru bisa dibuat langsung di sini), dan **Jatuh Tempo (opsional)**. Sistem otomatis menjurnal Persediaan atau Aset dengan kredit Hutang Usaha, dan tagihannya muncul di menu Hutang Usaha.',
                    'Untuk jenis Aset Baru, pilih **Akun Aset Tetap Tujuan** secara manual.',
                ],
                'tips' => [
                    'Menandai terpenuhi TIDAK menambah stok; stok hanya bertambah lewat Catat Stok.',
                    'Staf toko hanya melihat permohonan tokonya sendiri.',
                ],
            ],
            [
                'id' => 'riwayat-stok-dashboard',
                'title' => 'Dashboard Inventaris, Daftar Stok, dan riwayat pergerakan',
                'summary' => 'Memantau stok yang perlu perhatian, melihat Awal/Masuk/Keluar/Akhir per periode, dan menelusuri atau membatalkan catatan pergerakan terakhir.',
                'menus' => ['InventoryDashboard', 'StockCardReport', 'InventoryMovementResource', 'RawMaterialMovementResource', 'ConsumableItemMovementResource'],
                'steps' => [
                    '**Dashboard Inventaris** merangkum yang perlu tindakan: **Bahan Baku Perlu Perhatian** (stok di bawah titik pesan ulang, kedaluwarsa dalam 30 hari, atau lama tidak bergerak, 60 hari), **Barang Habis Pakai Perlu Perhatian**, dan **Aset Bermasalah** (rusak, diperbaiki, hilang). Tekan **Tandai Ditinjau** setelah item diperiksa; item muncul lagi bila datanya berubah.',
                    '**Daftar Stok** (Kartu Stok) menampilkan Awal, Masuk, Keluar, dan Akhir per bahan baku dan barang habis pakai untuk satu rentang tanggal. Atur dengan **Periode Cepat**, **Dari**, **Sampai**, dan **Jenis**. Hasilnya bisa diunduh lewat **Export ke Excel** atau **Export ke PDF**.',
                    '**Riwayat Keluar/Masuk** (produk film), **Riwayat Bahan Baku**, dan **Riwayat Barang Habis Pakai** adalah daftar pergerakan lintas semua barang; saring dengan **Dari Tanggal** dan **Sampai Tanggal**, dan unduh lewat **Export Excel**. Daftar ini hanya untuk dibaca; catatan baru dibuat dari aksi pada barangnya atau pemindaian QR di aplikasi.',
                    'Salah catat? Akses penuh bisa menekan **Batalkan** pada baris tersebut. Stok kembali ke sebelumnya dan baris "Koreksi" ditambahkan sebagai jejak. Pembatalan hanya bisa untuk kejadian PALING TERAKHIR barang itu.',
                    'Pada riwayat bahan baku dan barang habis pakai, **Cek Rekonsiliasi** memeriksa apakah stok semua barang cocok dengan penjumlahan riwayat pergerakannya; selisih berarti ada data yang perlu ditelusuri.',
                ],
                'tips' => [
                    'Stok pada Daftar Stok masih nasional (belum per cabang). "Akhir" dihitung mundur dari stok saat ini, bukan dari foto stok harian.',
                    'Riwayat pergerakan adalah jejak audit; koreksi lewat Batalkan, jangan mengubah catatan lama.',
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
                'id' => 'karyawan-akun',
                'title' => 'Karyawan: akun, role, dan akses menu',
                'summary' => 'Membuat akun karyawan, menentukan role dan toko, mengisi data personalia, mengatur akses menu, dan menonaktifkan akun.',
                'menus' => ['UserResource', 'RoleResource', 'EmployeeTypeResource'],
                'steps' => [
                    'Buat akun di **User** (semua akun di sini adalah karyawan; partner dan customer tidak termasuk). Isi **Nama**, **Email**, **No. Karyawan**, **No. HP**, **Tanggal Mulai Kerja**, **Password**, dan **Konfirmasi Password**.',
                    'Pilih **Role**. Super admin dan direksi berakses penuh. Store manager dan installer wajib memilih **Toko**; tanpa toko, akun tidak bisa mengakses booking atau chat toko mana pun. Installer tidak bisa login ke panel, hanya lewat aplikasi staf. Role baru (mis. hrd, warehouse_staff) dibuat sendiri di **Role / Divisi** tanpa developer; nama role memakai huruf kecil dan garis bawah.',
                    'Isi data personalia: **Tipe Karyawan**, **Gaji Pokok** (dasar Penggajian bulanan), **Golongan Upah** (dari menu Struktur & Skala Upah; gaji di luar rentang golongan hanya ditandai, tidak ditolak), **Masa Percobaan Berakhir** (kosongkan bila tidak ada; maksimal 3 bulan untuk PKWTT dan PKWT tidak boleh ada masa percobaan), serta **Tanggal Berakhir Kontrak** untuk kontrak pertama. Perpanjangan berikutnya dicatat lewat menu **Perpanjang Kontrak**, bukan diedit di sini. NIK, NPWP, BPJS, kontak darurat, dan rekening gaji juga diisi di bagian ini.',
                    'Bagian **Akses Menu & Hak Akses Detail** hanya untuk role staf atau divisi. Tanpa centang apa pun, akun mendapat akses ke semua menu (cara paling aman bila belum yakin). Mencentang modul tertentu MEMBATASI akun ke modul itu saja; lalu atur Lihat, Buat, Ubah, Hapus, atau Void untuk tiap modul.',
                    'Karyawan pindah toko: ubah **Toko** dan isi **Alasan Perpindahan Toko (opsional)**; perpindahan tercatat di riwayat karir.',
                    'Karyawan resign atau cuti panjang: pakai **Nonaktifkan** (mematikan **Akun Aktif**) untuk mencabut akses login panel dan aplikasi tanpa menghapus riwayat. Akun yang punya riwayat HR (absensi, cuti, gaji, surat peringatan, kontrak) tidak bisa dihapus permanen, dan penghapusan massal melewatinya.',
                    '**Tipe Karyawan** adalah daftar status kepegawaian yang Anda definisikan sendiri (mis. Tetap, Kontrak, Freelance, Magang). **Memiliki Tanggal Berakhir** menentukan apakah Tanggal Berakhir Kontrak wajib diisi untuk tipe itu. Tipe yang masih dipakai karyawan tidak bisa dihapus; nonaktifkan saja.',
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
                    'Karyawan mengajukan izin atau cuti lewat aplikasi staf, atau HR mengisinya di **Izin & Cuti**: **Karyawan**, **Jenis**, **Dari Tanggal**, **Sampai Tanggal**, **Alasan**, dan **Lampiran** (mis. surat dokter). Wajib atau tidaknya lampiran mengikuti aturan jenis yang dipilih.',
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
                'summary' => 'Dari menyiapkan aturan potongan dan data karyawan, menghasilkan slip gaji bulanan, menambah komponen, sampai pembayaran dan posting ke jurnal.',
                'menus' => ['PayrollResource', 'PayrollItemCategoryResource', 'SalaryGradeResource', 'AttendanceDeductionRuleResource'],
                'steps' => [
                    'Sebelum generate, periksa laporan **Kelengkapan Data** (karyawan aktif yang datanya belum lengkap, dipisah antara yang wajib dan yang disarankan). Data yang kurang memengaruhi gaji dan absensi.',
                    'Atur **Aturan Potongan Absensi**: satu baris per tanggal **Berlaku Mulai** (isi awal bulan). Isi **Ukuran Blok Telat (menit)**, **Potongan per Blok Telat (Rp)**, dan **Denda Tetap per Hari Alpha (Rp)** (ditambahkan di atas potongan Alpha per jam kerja). Hanya blok penuh yang dipotong; contoh blok 15 menit: total telat 14 menit tidak dipotong, 40 menit sama dengan 2 blok. Aturan yang sudah dipakai payroll tidak bisa diubah atau dihapus; perubahan dibuat sebagai baris baru dengan tanggal berlaku baru.',
                    'Di **Penggajian** pilih **Generate Payroll Bulanan**: isi **Bulan** (pilih bulan yang sudah selesai penuh; potongan telat dan Alpha bulan berjalan belum lengkap) dan **Toko** (kosongkan untuk semua toko). Baris yang sudah ditandai dibayar terkunci dari generate ulang.',
                    'Tambahan (tunjangan, bonus, THR) dan potongan (BPJS, PPh 21, kasbon) per karyawan diisi lewat **Komponen Gaji**: **Kategori**, **Nominal (Rp)**, dan **Nama di slip (opsional)**. Sistem hanya mencatat; sistem TIDAK menghitung pajak dan iuran, itu sesuai hitungan akuntan. **Salin dari Bulan Lalu** menyalin komponen yang ditandai untuk disalin ke bulan depan; komponen yang sudah ada dilewati.',
                    '**Kategori Komponen Gaji** (diatur akuntan) mendaftarkan jenis komponen: **Jenis** (tambahan menambah gaji bersih, potongan menguranginya; tidak bisa diubah setelah dipakai), **Akun Pembukuan** (tambahan: akun beban yang didebit; potongan: akun yang dikredit, mis. Hutang BPJS), dan **Biasanya ikut disalin ke bulan berikutnya**. Kategori yang sudah dipakai tidak bisa dihapus; nonaktifkan.',
                    'Pembayaran: **Ajukan Pembayaran** mengirim pengajuan ke direksi. Direksi atau akses penuh menekan **Setujui & Tandai Dibayar** atau **Tolak Pengajuan** (isi **Alasan Penolakan**). Setelah ditandai dibayar, slip diposting ke Jurnal Umum dan barisnya terkunci, jadi pastikan gaji benar-benar sudah ditransfer. **Tandai Dibayar** langsung hanya untuk direksi atau akses penuh.',
                    '**Unduh Slip Gaji** menghasilkan slip per karyawan.',
                    '**Struktur & Skala Upah** mendaftarkan rentang gaji pokok per golongan atau jabatan (**Gaji pokok minimum** dan **Gaji pokok maksimum**). Gaji pokok karyawan di luar rentang hanya ditandai di Kelengkapan Data. Golongan yang masih dipakai karyawan tidak bisa dihapus.',
                ],
                'tips' => [
                    'Aturan potongan sebaiknya tertulis di Peraturan Perusahaan yang sudah diterima karyawan (lihat panduan Kebijakan dan checklist).',
                    'Slip dengan status dibayar tidak digenerate ulang; koreksi dilakukan lewat jurnal, bukan menimpa slip.',
                ],
            ],
            [
                'id' => 'k3-kinerja',
                'title' => 'K3, pelatihan, penilaian kinerja, dan pekerjaan ulang',
                'summary' => 'Mencatat pembagian APD, kejadian K3, pelatihan, penilaian kinerja, dan booking yang perlu dikerjakan ulang.',
                'menus' => ['PpeTypeResource', 'PpeIssuanceResource', 'SafetyIncidentResource', 'EmployeeTrainingResource', 'PerformanceReviewResource', 'BookingReworkResource'],
                'steps' => [
                    '**Jenis APD** mendaftarkan perlengkapan beserta **Umur pakai (bulan)**, dasar jatuh tempo penggantian (kosongkan bila tidak ada jadwal ganti). Jenis yang sudah pernah dibagikan tidak bisa dihapus; nonaktifkan.',
                    '**Pembagian APD** mencatat siapa menerima apa: **Karyawan**, **Jenis APD**, **Jumlah**, dan **Tanggal dibagikan**. **Jatuh tempo ganti** terisi otomatis dari umur pakai bila dikosongkan. Isi **Dikembalikan/diganti pada** saat barang lama dikembalikan; barang itu tidak dihitung jatuh tempo lagi. Filter **Perlu diganti** menampilkan yang sudah waktunya diganti.',
                    '**Kejadian K3**: catat **Waktu kejadian**, **Jenis**, **Tingkat**, **Karyawan terlibat/terdampak**, **Apa yang terjadi**, dan **Tindakan saat itu**. Isi **Penyebab** dan **Tindak lanjut/pencegahan** (wajib sebelum kejadian bisa ditutup), lalu tekan **Tutup**. Kejadian tidak bisa dihapus. Untuk kejadian cedera, periksa kewajiban pelaporan ke BPJS Ketenagakerjaan; cedera yang belum ditutup ditandai di Dasbor HR.',
                    '**Pelatihan Karyawan** mencatat pelatihan K3, pengetahuan produk, dan sejenisnya: **Karyawan**, **Nama pelatihan**, **Penyelenggara**, **Tanggal pelatihan**, **Berlaku sampai** (kosongkan bila tidak kedaluwarsa), dan **Berkas sertifikat (opsional)** yang disimpan privat. Pelatihan installer per layanan tetap dicatat di Teknisi, Pelatihan & Sertifikat.',
                    '**Penilaian Kinerja** menilai empat aspek dengan skor 1 sampai 5, ditambah **Kekuatan**, **Yang perlu diperbaiki**, dan **Target periode berikutnya**. Satu penilaian per karyawan per **Periode**. Untuk pembinaan, bukan dasar gaji atau potongan. Penilaian yang sudah dibaca karyawan terkunci dari perubahan nilai.',
                    '**Pekerjaan Ulang** mencatat booking yang perlu dikerjakan ulang: pilih **Booking** dan **Sumber**, isi **Apa yang perlu diperbaiki** dan **Catatan pembinaan (opsional)**. Installer yang bertugas pada booking itu otomatis ikut terhitung. Isi **Selesai dikerjakan ulang** saat pekerjaan selesai. Dipakai laporan Kompetensi Installer untuk pembinaan, bukan dasar potongan gaji atau komisi.',
                ],
                'tips' => [
                    'Pelatihan yang sudah kedaluwarsa atau akan kedaluwarsa dalam 30 hari ditandai di daftar dan di Dasbor HR.',
                ],
            ],
            [
                'id' => 'hr-laporan',
                'title' => 'Dasbor HR dan laporan karyawan',
                'summary' => 'Satu layar tindakan HR, dan laporan kelengkapan data, kompetensi installer, serta utilisasi teknisi.',
                'menus' => ['HrDashboard', 'EmployeeDataCompletenessReport', 'InstallerCompetencyReport', 'TechnicianServiceDurationReport', 'TechnicianUtilizationReport'],
                'steps' => [
                    '**Dasbor HR** menampilkan angka yang butuh tindakan: kontrak yang akan berakhir, masa percobaan, data belum lengkap, peraturan yang belum diterima, APD jatuh tempo, sertifikat kedaluwarsa, kejadian K3 yang masih terbuka, dan karyawan yang belum dinilai. Pilih **Toko** untuk menyaringnya.',
                    '**Kelengkapan Data** mendaftar karyawan aktif yang datanya belum lengkap, dipisah antara yang WAJIB (memengaruhi gaji dan absensi) dan yang DISARANKAN. Periksa sebelum menggenerate payroll.',
                    '**Kompetensi Installer** menampilkan matriks keterampilan teknisi per layanan, sertifikat yang kedaluwarsa atau hampir kedaluwarsa, dan mutu pekerjaan (lulus QC pertama, pekerjaan ulang) per installer pada rentang tanggal yang dipilih. Untuk pembinaan, bukan dasar potongan gaji atau komisi.',
                    '**Utilisasi Teknisi** menampilkan beban kerja teknisi pada rentang **Dari** dan **Sampai**; **Cabang** dan **Urutkan** mengatur tampilannya. Jam Job adalah estimasi dari lama pengerjaan booking, bukan jam kerja sebenarnya.',
                    '**Durasi Servis Teknisi** adalah laporan akumulasi durasi layanan selesai per teknisi. Laporan ini belum menghitung komisi bertingkat.',
                    'Laporan teknisi bisa diekspor lewat **Export ke Excel** atau **Export ke PDF**.',
                ],
                'tips' => [
                    'Laporan Kompetensi Installer dan Pekerjaan Ulang dipakai untuk pembinaan, tidak menjadi dasar potongan gaji.',
                ],
            ],
            [
                'id' => 'kebijakan-checklist',
                'title' => 'Peraturan perusahaan dan checklist karyawan',
                'summary' => 'Menerbitkan Peraturan Perusahaan dan SOP ke aplikasi karyawan dengan bukti penerimaan, serta checklist onboarding dan offboarding.',
                'menus' => ['CompanyPolicyResource', 'ChecklistTemplateResource', 'EmployeeChecklistItemResource'],
                'steps' => [
                    'Di **Peraturan & Kebijakan** buat dokumen baru: **Judul**, **Jenis**, **Versi**, **Berlaku Sejak**, **Berkas (PDF)**, dan **Ringkasan untuk karyawan**. Berkas disimpan privat; karyawan membukanya lewat aplikasi dengan tautan sementara.',
                    'Aktifkan **Karyawan wajib menyatakan menerima** untuk peraturan yang mengikat (mis. aturan potongan), dan **Terbit di aplikasi karyawan** supaya dokumen terlihat karyawan. Hanya dokumen yang terbit terlihat.',
                    'Dokumen yang sudah punya bukti penerimaan terkunci (judul, versi, dan berkas tidak bisa diubah). Untuk versi baru, buat dokumen baru lalu cabut terbit versi lama. Filter **Belum menerima** menampilkan karyawan yang belum menyatakan menerima.',
                    '**Template Checklist** mendaftar butir standar onboarding dan offboarding (**Jenis**, **Butir**, **Urutan**).',
                    'Di **Checklist Karyawan**, aksi **Mulai Checklist Karyawan** menyalin template ke seorang karyawan. HR lalu mencentang tiap butir sampai **Selesai**; waktu dan pencatatnya tersimpan.',
                ],
                'tips' => [
                    'Aturan potongan telat dan Alpha sebaiknya tertulis di Peraturan Perusahaan yang sudah diterima karyawan; Dasbor HR menandai peraturan yang belum diterima.',
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
