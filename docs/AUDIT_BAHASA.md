# Audit Kata-kata dan Bahasa Sistem

Tanggal: 2026-10-27. Cakupan: teks yang terlihat pengguna di panel admin (Filament: menu, label, bantuan, judul notifikasi, tombol), pesan API ke aplikasi mobile, dan teks Alert di aplikasi mobile. Hanya membaca, belum ada yang diubah.

Cara hitung: 5.612 lokasi teks diambil dari `label()`, `helperText()`, `modalHeading()`, `title()`, `navigationLabel`, dan sejenisnya (2.693 teks unik). Angka di tabel temuan 2 adalah jumlah LOKASI (tombol yang diulang di tiap halaman laporan, mis. "Export ke Excel", dihitung berkali-kali). Pada versi pertama tertulis "teks unik" dan itu keliru; jumlah unik yang benar: Export 7 / Ekspor 8, Import 9 / Impor 11, Download 8 / Unduh 4, Template 24 / Templat 8, Checklist 7 / Daftar Periksa 3, Staff 8 / Staf 3, Review 12 / Ulasan 2, Customer 45 / Pelanggan 43, Teknisi 21 / Installer 20.

Status Inggris (New/Contacted/Closed/Cancelled/Pending/Confirmed/Approved/Rejected) sengaja dipertahankan sesuai keputusan pemilik 2026-10-26 dan TIDAK dihitung sebagai temuan.

## Keputusan pemilik (2026-10-27) dan daftar istilah baku

Istilah baku (satu konsep, satu sebutan):

| Pakai | Bukan |
|---|---|
| Pelanggan | Customer |
| Installer | Teknisi |
| Export, Import, Download | Ekspor, Impor, Unduh |
| Template | Templat |
| Checklist | Daftar Periksa |
| Staff | Staf |
| Review | Ulasan |
| Toko | Cabang |
| Riwayat | Histori, Log |
| Voucher | Kupon |
| User, Password | Pengguna, Kata sandi |
| Nonaktif | Tidak aktif |

Tetap seperti sekarang: Partner (nama program), Void (istilah akuntansi di Invoice dan Laporan Void), status Inggris (New/Pending/Approved dst.), Login/Masuk. Bahasa bawaan: `APP_LOCALE=id`.

Pengecualian disengaja: teks perintah CLI/data demo (`app/Console`), kunci kode dan nama kolom (`customer_id`), dan aplikasi mobile bagian pelanggan (Unduh, Ulasan) yang dibiarkan berbahasa Indonesia biasa karena dibaca pelanggan, bukan staf.

Penjaga: `tests/Unit/GlossaryTest.php` memeriksa teks tampilan di `app/Filament`, `Http`, `Models`, `Services`, `Notifications`, `Exports`, `Support`, `Mail`, dan `Providers` agar sebutan lama tidak kembali.

## Temuan, urut dampak

### 1. Bahasa bawaan aplikasi masih Inggris (dampak terbesar)
- `config/app.php`: `locale` = `en` (`.env.example`: `APP_LOCALE=en`). Tidak ada `setLocale` di kode, tidak ada folder `lang/`. Hasil cek server 2026-10-27: dev `APP_LOCALE=en`, production `APP_LOCALE=id`. Jadi production sudah memakai terjemahan Filament bahasa Indonesia, tetapi dev tidak sama dengan production.
- Akibatnya (di dev, dan di production untuk pesan validasi karena `lang/id` belum ada): tombol dan teks bawaan Filament (Save, Cancel, New, Search, Filters, "No records found", paginasi) muncul Inggris, padahal Filament sudah menyediakan terjemahan `id`.
- Pesan validasi Laravel muncul Inggris ("The reason field is required."). Dari 83 pemanggilan `validate()` di `app/Http`, hanya sebagian kecil punya pesan Indonesia kustom. Aplikasi mobile menampilkan pesan error field pertama apa adanya (`lib/api.ts:53`), jadi staf dan pelanggan bisa melihat kalimat Inggris.
- Efek samping yang sama: nama bulan/hari lewat `translatedFormat` keluar Inggris (sudah ditemui di tes).
- Perbaikan (dikerjakan): default `locale` di `config/app.php` dan `.env.example` jadi `id`; `lang/id/validation.php` ditambahkan lengkap dengan nama field yang dikenal sistem; `tests/Feature/IndonesianValidationTest.php`. Yang tersisa untuk Anda: ubah `APP_LOCALE=id` di `.env` server dev.

### 2. Satu konsep, dua sebutan (campur Indonesia dan Inggris)
| Konsep | Varian A (teks unik) | Varian B | Catatan |
|---|---|---|---|
| Pelanggan | Customer 73 | Pelanggan 72 | Terbelah rata. Menu: "Galeri Customer", "Riwayat Poin Customer" vs "Daftar Pelanggan", "Laporan Pelanggan", "Kepuasan Pelanggan" |
| Ekspor | Export 113 | Ekspor 9 | Tombol "Export ke Excel/PDF" vs judul "Ekspor Laporan" |
| Impor | Import 22 | Impor 14 | "Riwayat Impor Produk" vs "Import selesai" |
| Unduh | Download 16 | Unduh 8 | "Download Template" |
| Templat | Template 34 | Templat 13 | "Templat Notifikasi" vs "Template Checklist", "Template Tagihan Rutin" |
| Daftar periksa | Checklist 12 | Daftar Periksa 6 | "Daftar Periksa QC" vs "Checklist Karyawan", "Template Checklist" |
| Staf | Staff 8 | Staf 3 | |
| Teknisi/Installer | Teknisi 28 | Installer 27 | Menu: "Kompetensi Installer" vs "Utilisasi Teknisi", "Laporan Komisi Teknisi". Satu orang, dua nama |
| Toko/Cabang | Cabang 5 | Toko 232 | "Semua cabang", "Mutasi ke Cabang Lain" |
| Nonaktif | Nonaktif 7 | Tidak aktif 1 | Cukup konsisten, satu pengecualian |
| Review/Ulasan | Review 21 | Ulasan 2 | "Review Toko" |
| Void/Batal | Void 4 | Batalkan 104 | "Laporan Void", "Invoice di-void" vs sisa sistem "Dibatalkan" |
| Riwayat/Histori/Log | Riwayat 94 | Histori 4, Log 4 | "Histori Aktivitas" dan "Log Pengiriman" vs "Riwayat ..." |
| Persetujuan | Approve/Approval 13 | Setuju/Persetujuan 78 | Sudah dominan Indonesia, tinggal sisa |
| Stok | Stok 97 | Stock 4 | |
| Mitra | Partner 47 | Mitra 3 | "Partner" dominan, "Mitra" jarang |
| Kupon/Voucher | Voucher 16 | Kupon 2 | "Laporan Kupon" vs "Voucher" |
| Garansi | Warranty 6 | Garansi 56 | |
| Gaji | Payroll/Salary 11 | Gaji/Penggajian 40 | |
| Masuk (login) | Login 5 | Masuk 47 | |
| Kata sandi | Password 10 | Kata sandi 1 | |
| Pengguna | User 11 | Pengguna 2 | Menu "User" |

Perlu keputusan pemilik: pilih satu sebutan per baris (daftar istilah baku). Pilihan paling murah: ikuti yang sudah dominan, kecuali Pelanggan/Customer (terbelah rata) dan Teknisi/Installer (istilah organisasi).

### 3. Singkatan dan gaya tulis tidak seragam
- Singkatan di judul kolom: "Tgl Pelepasan", "Tgl. Transaksi", "Tgl/Bulan", "Jml. Mengajak", "Jml", "utk" ("Warna (utk kalender)", "Total harga utk seluruh jumlah"). Tulis penuh atau seragamkan.
- Huruf besar/kecil: "Follow Up", "Status Follow-up", "Catatan follow-up" tiga ejaan untuk satu kata. "Store Manager" vs "store manager". "Edit Profil"/"Edit Panjang Gulungan" vs kata kerja Indonesia "Ubah" (dipakai di bagian lain).
- Kata informal di teks bantuan: "cuma" (19 teks), "kalau" (83) vs "jika" (6), "tapi" (4) vs "tetapi" (1), "supaya" (17) vs "agar" (2). Bantuan memakai nada akrab, sedangkan judul formal. Cukup pilih satu nada; usul: formal untuk judul dan notifikasi, boleh santai di bantuan, tetapi seragam.
- Cara menulis contoh: "mis." (82) vs "contoh:" (34) vs "misalnya".
- Cara menyapa: "Anda" dipakai konsisten (tidak ada "kamu/kita"), ini baik.
- Aplikasi mobile: judul Alert tidak seragam besar-kecil hurufnya: "Izin kamera diperlukan", "Izin Kamera Diperlukan", "Izin Diperlukan"; "Alasan wajib" vs "Alasan Wajib Diisi". Judul "Gagal" (46) dan "Berhasil" (32) tanpa konteks.

### 4. Istilah teknis dan sisa pekerjaan lain yang bocor ke layar
- Kunci peran mentah tampil di bantuan: `store_manager`, `super_admin` (mis. `UserResource.php:679`), dan nama kolom basis data `users.contract_end_date` (`ContractExtensionResource.php:201`). Ganti dengan nama peran yang tampil di panel.
- Istilah Inggris yang tidak perlu dan tidak pernah dijelaskan: "X job aktif" (`BookingResource.php:323`), "assignment", "roster", "Follow Up", "Input Manual".
- "Laporan Utilisasi Zona/Bay" (`BayZoneUtilizationReport.php:56`): "Bay" adalah istilah dari audit Majoo dan Ginnva tidak punya entitas bay (catatan keputusan 2026-09-21). Cek apakah halaman ini masih dipakai atau hanya sisa; kalau dipakai, ganti nama dengan istilah toko sendiri.
- Judul menu berbeda gaya: "Toko/Dealer", "Laporan SLA & Tidak Datang", "Kemitraan & Sales Referral" (campur Indonesia dan Inggris dalam satu judul).

### 5. Yang sudah baik
- Tidak ada karakter rusak (mojibake) di sumber; tanda pisah seragam.
- Tidak ada ejaan gaul berat (nggak/gak/banget/udah).
- Sapaan seragam ("Anda").
- Pesan penolakan akses (`abort(403, ...)`) konsisten Indonesia dan jelas (mis. "Booking ini tidak ditugaskan ke Anda.").
- Pesan API Indonesia hampir seluruhnya; hanya "Token linked." dan "Token registered." berbahasa Inggris.
- Nama menu utama sudah Indonesia ("Laporan Arus Kas", "Stok Terbuang", "Perputaran Stok").

## Batas audit ini
- Belum diperiksa: isi Blade (`resources/views`), email, templat WhatsApp, isi Panduan Penggunaan, teks situs web (`ginnva-web`), dan salah ketik per kata (tidak ada pemeriksa ejaan Indonesia otomatis yang andal; temuan salah eja butuh baca manual).
- Nilai `.env` server tidak terlihat dari sini; temuan 1 perlu dicek di server.

## Rencana perbaikan (usulan, urut)
1. Putuskan daftar istilah baku (tabel temuan 2). Tanpa ini perbaikan lain akan bolak-balik.
2. `APP_LOCALE=id` + `lang/id/validation.php` (temuan 1).
3. Ganti istilah mengikuti daftar baku di panel, lalu di mobile.
4. Rapikan singkatan, ejaan "follow-up", judul Alert mobile (temuan 3).
5. Bersihkan istilah teknis bocor dan "Zona/Bay" (temuan 4).
