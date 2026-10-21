# Rencana Deploy Production: Penjualan (Fase 0, 1, 2, 3, 5 sisi server, 6)

Dibuat 2026-11-13. Melanjutkan `RENCANA_DEPLOY_KARYAWAN_KEUANGAN.md` (sudah dijalankan 2026-10-20). **Dokumen ini belum dijalankan.** Perintah untuk server cPanel Ginnva, user `ginnva`, folder production `ginnva-api` (perintah tidak diawali `cd`; terminal sudah di folder itu). Rancangan dan keputusan: `RANCANGAN_ULANG_PENJUALAN.md`.

## 1. Keadaan dan cakupan

| Hal | Production sekarang (konfirmasi di bagian 4) | Yang akan naik |
|---|---|---|
| Kode API | `origin/development` pada saat rilis 2026-10-20 (sekitar commit `20f7c00`) | `origin/development` terakhir (14 commit Penjualan) |
| Migrasi | sampai `2026_11_09_000001` | **3 migrasi baru** (bagian 3) |
| Jadwal | tidak berubah | tidak ada jadwal baru |
| `.env` | tidak ada yang wajib | dua opsional, **keduanya dibiarkan kosong saat rilis** (bagian 8) |
| Tes | n/a | semua kelompok tes Penjualan hijau di dev: Fase 0 (18), Fase 1 dan 2 (53), Fase 3 (10 + 86 terkait), Fase 5 (9 + 93 terkait), Fase 6 (51) |
| Mobile | tidak ikut | **tidak ikut**: layar `sale.tsx` belum pernah dicoba di HP dan belum di-commit (bagian 9) |
| Web | tidak berubah | tidak berubah |

Yang naik: rincian harga per layanan, pembayaran diterima per rekening, Buku Pembayaran, kolom tanggal selesai, Indikator Penjualan, API catat penjualan dari HP, perbaikan Penjualan Produk, dan **perbaikan bug lama Proses Referral** (bagian 2 butir 1).

## 2. Perubahan perilaku yang terlihat SEGERA setelah deploy

1. **Bug lama diperbaiki: Proses Referral pada booking tanpa produk utama.** Sebelumnya kolom "Ukuran Kendaraan" yang tersembunyi membuat aksi ini error ("Undefined array key vehicle_size") dan, bila lolos, menghapus ukuran kendaraan yang tersimpan. Ada sejak 2026-09-22. Cek Sentry untuk jumlah kejadian sebelum rilis.
2. **Form Proses Referral berubah.** Isian "Total Diterima" dan "Metode Pembayaran" diganti daftar **Pembayaran diterima** (metode, rekening, nominal). Satu baris tunai kosong muncul bawaan; nominalnya wajib diisi, atau hapus barisnya bila belum ada uang masuk (sisa menjadi Piutang Usaha). **Kelebihan bayar sekarang ditolak** (dulu dipotong diam-diam). Staf kasir perlu diberi tahu sebelum rilis.
3. **Rincian Harga per Layanan** muncul di form yang sama, **tidak wajib** (bendera kosong). Tanpa rincian, pendapatan antar jenis layanan tetap dibagi rata seperti sekarang.
4. **Jurnal kas bisa terpisah per rekening** untuk booking yang diproses dengan pembayaran campur. Booking tanpa daftar pembayaran memakai jalur satu baris kas yang lama.
5. **Menu baru "Buku Pembayaran"** (Laporan Penjualan) dan bagian **"Indikator Penjualan"** di Dasbor Penjualan. Grafik "Pendapatan per Metode Pembayaran" membaca daftar pembayaran bila ada.
6. **Grafik "Layanan" di dashboard utama** kini memakai pembagian yang sama dengan Jurnal Umum (dulu 50/50 sendiri, dan mengabaikan Detailing/Premium Wash): angkanya bisa bergeser sedikit untuk booking multi-layanan.
7. **Laporan Penjualan Produk** menampilkan peringatan bila ada transaksi dengan lebih dari satu produk (angka tidak berubah).
8. **Aksi baru "Koreksi Tanggal Selesai"** di daftar Booking (akses penuh, hanya sebelum penjualan diproses).
9. **API baru** `GET/POST /api/staff/bookings/{id}/sale` hanya dipakai build mobile baru; aplikasi lama tidak terpengaruh.
10. **Tanggal jurnal pendapatan TIDAK berubah** pada rilis ini: `FEATURE_REVENUE_ON_COMPLETION` mati bawaan. Kolom `completed_at` mulai terisi untuk booking yang selesai sesudah rilis.

## 3. Migrasi yang akan jalan

| Migrasi | Isi | Risiko |
|---|---|---|
| `2026_11_10_000001_create_booking_sale_lines_table` | tabel baru (kosong) | rendah |
| `2026_11_11_000001_create_booking_settlement_payments_table` | tabel baru (kosong) | rendah |
| `2026_11_12_000001_add_completed_at_to_bookings_table` | kolom `completed_at` nullable + indeks; **mengisi** booking selesai lama dari log aktivitas (kejadian pertama status menjadi completed) | rendah-sedang: satu pencarian ke `activity_log` per booking selesai; aman diulang; tidak mengubah jurnal atau data uang |

Tidak ada migrasi yang menghapus atau mengubah isi data uang. Kebijakan RUNBOOK §3 terpenuhi; cadangan 03:00 hari itu sudah cukup (bagian 4 butir 2 hanya memeriksa keberadaannya).

## 4. Sebelum deploy (persiapan)

1. **Konfirmasi baseline production** (hanya membaca):

```bash
git branch --show-current && git status --short && git log --oneline -1 && php artisan migrate:status | grep -i pending
```

   Harapan: cabang `development`, `status` kosong (selain berkas lama yang sudah dikenal), dan **tidak ada** migrasi `Pending` (kode di server belum memuat berkas migrasi baru sebelum `git pull`; tiga migrasi baru baru terlihat sesudah pull, bagian 5). Bila ada migrasi pending yang tidak dikenal, **berhenti dan lapor**.
2. **Pastikan cadangan hari ini ada:**

```bash
find ~ storage -maxdepth 6 -name 'ginnva_backup_*.sql.gz' -mtime -1 -printf '%TY-%Tm-%Td %TH:%TM  %10s bytes  %p\n' 2>/dev/null | sort
```

3. **Perkirakan beban pengisian `completed_at`** (hanya membaca):

```bash
php artisan tinker --execute="echo App\Models\Booking::withoutGlobalScopes()->where('status','completed')->count().' booking selesai; '.DB::table('activity_log')->count().' baris log aktivitas';"
```

   Bila jumlahnya ratusan ribu baris log, jalankan di luar jam ramai; untuk skala Ginnva yang sekarang seharusnya hitungan detik sampai menit.
4. **Cek Sentry** untuk error `Undefined array key "vehicle_size"` (menunjukkan seberapa sering bug lama terjadi).
5. **Beri tahu staf kasir/admin** perubahan form Proses Referral (bagian 2 butir 2) sebelum jam kerja.
6. Pilih waktu sepi, satu orang memantau.

## 5. Deploy API

```bash
git checkout development && git pull origin development && php artisan migrate --force && php artisan optimize:clear && php artisan optimize && php artisan filament:optimize && php artisan queue:restart
```

Tidak ada seeder atau command tambahan. Lalu langsung:

```bash
php artisan migrate:status | tail -n 6; php artisan route:list --path=api/staff/bookings 2>/dev/null | grep -c "sale"
```

Harapan: tiga migrasi baru berstatus `Ran`, dan jumlah rute berisi `sale` bernilai `2`.

## 6. Verifikasi setelah deploy

1. **Kolom `completed_at` terisi** untuk booking lama (hanya membaca):

```bash
php artisan tinker --execute="echo App\Models\Booking::withoutGlobalScopes()->where('status','completed')->whereNotNull('completed_at')->count().' dari '.App\Models\Booking::withoutGlobalScopes()->where('status','completed')->count().' booking selesai punya completed_at';"
```

   Booking selesai lama yang tidak punya log boleh kosong.
2. **Panel admin** (login akses penuh), tiap halaman terbuka tanpa error 500: Dasbor Penjualan (ganti harian/mingguan/bulanan; bagian "Indikator Penjualan" tampil), Buku Pembayaran, Laporan Penjualan Produk, Laporan Layanan, daftar Booking.
3. **Form Proses Referral** pada satu booking selesai yang BELUM diproses: buka dan periksa tampilan (Rincian Harga, Pembayaran diterima, ringkasan), lalu **batalkan tanpa menyimpan**.
4. **Satu penjualan nyata dicatat** pada booking yang memang sedang diproses, oleh admin yang memantau. Sesudahnya periksa di Jurnal Umum: jurnal seimbang, akun pendapatan sesuai rincian (bila diisi), baris kas sesuai pembayaran; Buku Pembayaran memuat pembayarannya.
5. **Aplikasi mobile lama**: buka daftar booking dan satu booking; berjalan seperti sebelumnya.
6. **Pantau Sentry dan `storage/logs/laravel.log`** minimal 1 jam, dan sampai akhir hari kerja pertama (Proses Referral adalah jalur uang).

## 7. Setelah server stabil (dikerjakan admin)

1. **Latih staf** mengisi Rincian Harga per Layanan (disarankan, belum wajib).
2. **Setelah beberapa hari berjalan baik,** pertimbangkan mewajibkan rincian: isi `BOOKING_SALE_LINES_REQUIRED_FROM=<tanggal>` di `.env` (booking yang dibuat pada/setelah tanggal itu wajib punya rincian; booking lama tidak dipaksa), lalu `php artisan config:cache`.
3. **Baca Indikator Penjualan** sebagai acuan awal; cakupan rincian harga akan naik seiring dipakai.

## 8. Mengaktifkan pendapatan pada tanggal selesai (terpisah, belakangan)

`FEATURE_REVENUE_ON_COMPLETION` **jangan dinyalakan pada hari rilis.** Syarat sebelum menyalakan:
1. Kolom `completed_at` sudah terbukti terisi benar pada booking baru selama minimal beberapa hari (bandingkan dengan saat petugas menandai selesai).
2. Akuntan setuju: laporan bulan berjalan akan memindahkan pendapatan booking yang selesai bulan lalu tetapi baru diproses bulan ini ke bulan lalu (kecuali periodenya sudah ditutup, saat itu dipakai hari ini dengan catatan di jurnal).
3. Bulan sebelumnya sudah ditutup atau tidak ada booking selesai yang belum diproses (peringatan baru di daftar periksa tutup bulan membantu).

Menyalakan: isi `FEATURE_REVENUE_ON_COMPLETION=true` di `.env`, lalu `php artisan config:cache`. Mematikan kembali: kosongkan/ubah ke `false`, `php artisan config:cache`; jurnal yang sudah terbentuk tidak berubah.

## 9. Mobile (terpisah)

Layar `app/staff/bookings/sale.tsx` dan tombol di detail booking dikerjakan lokal, belum pernah dicoba di HP, dan belum di-commit (aturan: developer meng-commit). Urutan: developer meng-commit, build preview menunjuk server dev, uji HP (catat penjualan oleh kasir, oleh Store Manager, booking yang diskonnya besar masuk antrean persetujuan, booking yang sudah diproses hanya terlihat), rilis bertahap setelah server production stabil.

## 10. Jalur mundur

- **Mundur kode saja:** migrasi hanya menambah tabel dan satu kolom nullable, jadi kode lama mengabaikannya:

```bash
git checkout <commit-production-sebelumnya> && php artisan optimize:clear && php artisan optimize && php artisan filament:optimize && php artisan queue:restart
```

  Catat commit production dengan `git log --oneline -1` (bagian 4 butir 1). Akibatnya: form Proses Referral kembali ke isian tunggal (bug `vehicle_size` kembali), Buku Pembayaran dan Indikator hilang. Penjualan yang sudah dicatat tetap sah di Jurnal Umum; `amount_received` dan `payment_method` tetap terisi sehingga layar lama tetap konsisten. Pembayaran dan rincian yang sudah tersimpan di tabel baru hanya tidak ditampilkan.
- **Pulihkan database dari cadangan** hanya bila data rusak (bukan karena kode); `migrate:rollback` tidak dipakai (RUNBOOK §3).
- Jurnal pendapatan yang salah dikoreksi lewat Proses Referral (membalik dan memposting ulang), bukan dihapus.

## 11. Risiko dan hal yang masih terbuka

- **Proses Referral adalah jalur uang.** Perubahan form membuat kasir harus mengisi pembayaran dengan benar; baris kosong atau terhapus berarti sisa tercatat sebagai piutang. Pantau piutang baru pada hari pertama.
- **Uji manual panel belum dilakukan oleh orang yang memakainya** (hanya tes otomatis, yang mencakup aksi ujung ke ujung lewat form). Bagian 6 butir 3 dan 4 menutup itu.
- **Layar mobile belum terbukti di HP.**
- **Pengisian `completed_at`** memakai log aktivitas; bila format log lama berbeda, sebagian booking lama bisa kosong (tidak berbahaya selama bendera mati).
- **Saldo kas per tanggal** akan sedikit bergeser dari kenyataan bila pendapatan diakui pada tanggal selesai tetapi uang diterima beda hari (keputusan: satu jurnal; baru berlaku setelah bendera dinyalakan).
- **Belum ada:** penawaran berharga (tidak dibangun), target penjualan (belum diputuskan), kirim invoice dari HP, pajak/faktur pajak (menunggu status PKP di Keuangan Fase 3).
