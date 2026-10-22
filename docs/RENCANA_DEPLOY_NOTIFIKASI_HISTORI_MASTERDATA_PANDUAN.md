# Rencana Deploy Production: Notifikasi, Histori Aktivitas, Master Data, dan Panduan

Dibuat 2026-10-22. Melanjutkan `RENCANA_DEPLOY_PENJUALAN.md` (dijalankan 2026-10-21: production `20f7c00` → `5ff3fa5`). **DIJALANKAN 2026-10-22 (API):** production `5ff3fa5` → `0da10b1`, tujuh migrasi `Ran` (batch 114), jadwal `notifications:prune-old`, `check-receipts`, dan `send-scheduled` terdaftar, `guides:coverage` 152 dari 152 menu (62 panduan). Sebelum deploy: suite penuh di dev 2747 tes, 1 gagal (tes rapuh urutan notifikasi di `TransactionApprovalTest`, diperbaiki di `0da10b1` dan terbukti hijau); `activity_log` 1.283 baris; cron `schedule:run` tiap menit ada; backup 03:00 ada. **Verifikasi klik (bagian 6) selesai 2026-10-22: aman** (dilaporkan pemilik). Temuan terpisah: log production menunjukkan MySQL sesekali menolak koneksi beberapa detik (13, 14, dan 19 Oktober; tidak ada hari ini), yang menggagalkan pembacaan tabel `cache` dan `sessions`; penyebabnya belum diketahui dan perlu dilihat dari log MySQL atau penyedia hosting bila berulang. Perintah untuk server cPanel Ginnva, user `ginnva`, folder production `ginnva-api` (perintah tidak diawali `cd`; terminal Anda sudah di sana).

Marketing/Konten Fase 1 sampai 5 sudah ikut naik bersama Karyawan dan Keuangan (`RENCANA_DEPLOY_KARYAWAN_KEUANGAN.md`), sehingga panduan Marketing tidak lagi menjelaskan fitur yang belum ada. Meski begitu, **pastikan dulu keadaan production yang sebenarnya** (bagian 4, butir 2); seluruh daftar di bawah mengandaikan production ada di `5ff3fa5`.

## 1. Keadaan dan cakupan

| Hal | Production sekarang (menurut dokumen) | Yang akan naik |
|---|---|---|
| Kode API | `5ff3fa5` | `origin/development` terakhir (sekitar 56 commit; sekitar 130 berkas non-tes) |
| Migrasi | sampai `2026_10_27_000002` dan tiga migrasi Penjualan | **7 migrasi baru** (bagian 3) |
| Rute API baru | n/a | 5 rute (kotak masuk staf `GET /api/staff/inbox*`, `GET /api/staff/guides`) |
| Jadwal (cron Laravel) | seperti sekarang | + `notifications:check-receipts` tiap 15 menit, `notifications:send-scheduled` tiap menit |
| `.env` | tidak ada yang wajib | opsional: `PROMO_QUIET_HOURS_*`, `FEATURE_PRICELIST_FROM_DATABASE` (bawaan mati) |
| Paket | n/a | tidak ada perubahan `composer.json` atau `composer.lock`, jadi tidak perlu `composer install` |
| Web (`ginnva-web`) | tidak berubah | **tidak ikut rilis ini** |
| Mobile | tidak ikut | **tidak ikut rilis ini**; layar baru (Kotak Masuk, Panduan) ada di working copy dan belum di-commit (bagian 8) |

Yang naik, per pekerjaan:

- **Notifikasi** (Fase 0 sampai 5): Katalog Notifikasi, kotak masuk staf (API), Log Pengiriman dan pemeriksaan kuitansi Expo, jam tenang dan batas promosi, jenis pesan Promosi/Informasi, penjadwalan kampanye, Kampanye Notifikasi, Templat Notifikasi.
- **Histori Aktivitas** (Fase 0 sampai 5): tampilan terbaca, konteks asal/IP/perangkat untuk staf, log keamanan akun, tab Perubahan Berisiko dan Keamanan Akun, ekspor Excel, perintah `activity:size-report`, dua indeks di `activity_log`.
- **Master Data** (Fase 0 sampai 5): Pusat Master Data, kelola Kendaraan (nonaktifkan, tipe bodi, tahun), Luas Kaca dan impor dari Google Sheet, kode toko dan penanggung jawab, jam operasional.
- **Panduan** (Fase 0 sampai 4): 62 panduan untuk semua menu dan peran, 11 kelompok yang bisa dibuka-tutup, `GET /api/staff/guides`, menu Catatan Panduan, versi dan tanggal tinjau.
- Migrasi `delete_wf_h15_h30` dijadikan no-op: di production sudah berstatus `Ran`, jadi tidak ada efek (hanya mencegah terulang di lingkungan baru).

## 2. Perubahan perilaku yang terlihat SEGERA setelah deploy

1. **Jadwal berjalan lebih sering.** `notifications:send-scheduled` tiap menit dan `notifications:check-receipts` tiap 15 menit hanya jalan bila cron `schedule:run` aktif tiap menit di cPanel. Bila cron-nya lebih jarang, kampanye terjadwal terlambat dan kuitansi tidak diperiksa (bagian 4, butir 3).
2. **Menu baru di panel**, termasuk: Pusat Master Data, Luas Kaca, Katalog Notifikasi, Kampanye Notifikasi, Templat Notifikasi, Log Pengiriman, Histori Aktivitas, Catatan Panduan. **Staf dengan Akses Menu terbatas tidak otomatis melihat menu baru.** Histori Aktivitas hanya untuk akses penuh atau akun yang kotaknya dicentang eksplisit (akun dengan menu kosong TIDAK ikut melihatnya, itu disengaja). Halaman Panduan terbuka untuk semua staf panel.
3. **Histori Aktivitas mulai mencatat konteks.** Perubahan baru oleh staf menyimpan asal (panel, aplikasi), alamat IP, dan perangkat; kejadian keamanan akun (masuk gagal, ganti dan reset password) tercatat. Catatan lama tidak berubah. Pemilik memutuskan log tidak dianonimkan dan disimpan tanpa batas waktu.
4. **Kendaraan:** semua kendaraan yang ada tetap **aktif** (kolom `is_active` bawaan benar). Kendaraan yang kemudian dinonaktifkan hilang dari aplikasi dan formulir.
5. **Toko:** kolom kode toko dan penanggung jawab masih kosong. Pusat Master Data akan menandainya sebagai saran, bukan error.
6. **Luas Kaca kosong dan daftar harga tidak berubah.** `FEATURE_PRICELIST_FROM_DATABASE` mati, jadi kalkulator Price List tetap membaca Google Sheet. Tidak ada yang berpindah sampai Anda menyalakan bendera itu setelah memeriksa impor.
7. **Kirim Notifikasi berubah.** Ada pilihan Jenis Pesan (Promosi hanya ke pelanggan yang menyetujui; bawaan pelanggan lama tidak menyetujui), jadwal, dan templat. Notifikasi yang dikirim lewat menu ini sekarang tercatat sebagai kampanye.
8. **Panduan** tampil langsung di panel. Catatan Panduan kosong sampai pemilik mengisinya. Aplikasi staf lama belum punya layar Panduan atau Kotak Masuk sampai ada build baru.

## 3. Migrasi yang akan jalan

| Migrasi | Isi | Risiko |
|---|---|---|
| `2026_11_13_000001_add_lifecycle_and_attributes_to_vehicles_table` | kolom `is_active` (bawaan aktif, berindeks), tipe bodi, tahun dari/sampai, catatan di `vehicles` | rendah (menambah kolom; memeriksa dulu apakah sudah ada) |
| `2026_11_14_000001_create_vehicle_glass_areas_table` | tabel baru (kosong) | rendah |
| `2026_11_15_000001_add_code_and_manager_to_stores_table` | `stores.code` (unik, boleh kosong) dan `manager_user_id` | rendah |
| `2026_11_16_000001_create_notification_deliveries_table` | tabel baru (kosong) | rendah |
| `2026_11_17_000001_create_notification_campaigns_and_templates` | dua tabel baru (kosong) + kolom `campaign_id` di `notification_deliveries` | rendah |
| `2026_11_18_000001_add_created_at_indexes_to_activity_log` | dua indeks di `activity_log` | **sedang**: satu-satunya yang menyentuh tabel besar; waktu proses dan kunci tabel bergantung jumlah baris (bagian 4, butir 4) |
| `2026_11_19_000001_create_guide_notes_table` | tabel baru (kosong) | rendah |

Semuanya memeriksa dulu apakah kolom, tabel, atau indeks sudah ada, dan semuanya punya `down()`. Tidak ada yang menghapus atau mengubah data yang ada. Kebijakan RUNBOOK §3 dipenuhi: migrasi tidak destruktif, jadi cadangan manual tambahan tidak diperlukan selama backup 03:00 hari itu ada.

## 4. Sebelum deploy (persiapan, tidak menyentuh kode)

1. **Suite penuh di dev harus hijau, dijalankan SETELAH semua commit terakhir.** Catatan terakhir suite penuh adalah 2274 tes sebelum pekerjaan Histori, Notifikasi, Master Data, dan Panduan selesai; tes kelompok-kelompok itu sudah hijau per bagian, tetapi suite penuh belum diulang. Di server dev (jangan `config:cache` sebelum selesai):

```bash
git pull && php artisan config:clear && { vendor/bin/phpunit -d memory_limit=1G > ~/phpunit.log 2>&1; php artisan config:cache; } & tail -f ~/phpunit.log
```

   Perkiraan 26 sampai 30 menit. Harapan: `OK`, tanpa error. `php artisan test` penuh tidak dipakai (kehabisan memori). Bila ada yang gagal, **berhenti** dan kirim hasilnya; jangan deploy.
2. **Pastikan keadaan production sebenarnya** (hanya membaca):

```bash
git branch --show-current && git status --short && git log --oneline -1 && php artisan migrate:status | tail -n 5
```

   Harapan: cabang `development`, `status` kosong, commit `5ff3fa5` (atau commit perbaikan sesudahnya), dan migrasi terakhir `Ran`. Bila commit atau migrasi berbeda dari dugaan, atau ada perubahan lokal yang tidak dikenal, **berhenti dan lapor**; daftar di bagian 3 harus dihitung ulang. **Catat hash commit ini**: itu titik kembali bila perlu rollback.
3. **Pastikan cron Laravel berjalan tiap menit:**

```bash
crontab -l | grep -i "schedule:run"
```

   Harapan: satu baris dengan `* * * * *`. Tanpa itu, kampanye terjadwal tidak terkirim.
4. **Ukur `activity_log` sebelum membuat indeks** (hanya membaca):

```bash
php artisan tinker --execute="echo number_format(DB::table('activity_log')->count()).' baris activity_log';"
```

   Beberapa ratus ribu baris atau kurang: beberapa detik sampai menit, aman kapan saja. Jutaan baris: lakukan di jam sepi dan pastikan tidak ada yang menyimpan data besar saat itu (bagian 5 menjalankan migrasi dalam satu perintah).
5. **Pastikan backup 03:00 hari itu ada** (lokal dan Drive):

```bash
find ~ storage -maxdepth 6 -name 'ginnva_backup_*.sql.gz' -mtime -1 -printf '%TY-%Tm-%Td %TH:%TM  %10s bytes  %p\n' 2>/dev/null | sort; rclone lsl gdrive-crypt: | sort -k2,3 | tail -n 3
```

6. **Beri tahu staf yang memakai kirim notifikasi** tentang aturan Jenis Pesan: Promosi hanya ke pelanggan yang setuju dan tidak dikirim pada 21:00 sampai 08:00 WIB; pesan non-promosi harus dikirim sebagai Informasi penting. Saat ini belum ada pelanggan yang memakai aplikasi, jadi tidak ada promosi yang hilang.
7. Pilih waktu sepi (setelah backup 03:00, sebelum 08:00), satu orang memantau.

## 5. Deploy API

```bash
git checkout development && git pull origin development && php artisan migrate --force && php artisan optimize:clear && php artisan optimize && php artisan filament:optimize && php artisan queue:restart
```

Lalu langsung:

```bash
php artisan migrate:status | tail -n 10; php artisan schedule:list | grep -i "notifications"; php artisan guides:coverage
```

Harapan:

- 7 migrasi baru berstatus `Ran`.
- `notifications:check-receipts` (tiap 15 menit), `notifications:send-scheduled` (tiap menit), dan `notifications:prune-old` terdaftar.
- `guides:coverage`: menu terdaftar sama dengan yang dirujuk panduan (100%), 62 panduan.

Variabel `.env` tidak perlu diubah. Jangan menyalakan `FEATURE_PRICELIST_FROM_DATABASE` pada rilis ini (bagian 7).

## 6. Verifikasi setelah deploy (production, tanpa membuat data pelanggan)

Sebagai akses penuh di panel:

1. **Login panel** berhasil dan Dashboard terbuka tanpa error.
2. **Panduan:** halaman terbuka, kelompok "Mulai dari peran Anda" terbuka, kelompok lain bisa dibuka dan ditutup, dan pencarian (mis. "voucher") membuka kelompok yang cocok. Setiap panduan menampilkan "Versi 1.0 · ditinjau 22 Okt 2026".
3. **Histori Aktivitas:** daftar terbuka, filter Modul dan tab Perubahan Berisiko bekerja, satu baris terbaru menampilkan Asal dan Perangkat.
4. **Pusat Master Data:** terbuka, angka kendaraan dan toko masuk akal, butir saran bertanda "Dilengkapi" bukan error.
5. **Kendaraan:** daftar terbuka dan semua kendaraan lama berstatus aktif. **Luas Kaca:** terbuka dan kosong.
6. **Katalog Notifikasi, Log Pengiriman, Kampanye Notifikasi, Templat Notifikasi** terbuka tanpa error. **Jangan mengirim notifikasi ke pelanggan atau partner untuk menguji.**
7. **Akun terbatas:** masuk sebagai satu staf toko (bukan akses penuh). Panel terbuka, menu Histori Aktivitas dan Catatan Panduan tidak muncul, dan halaman Panduan hanya menampilkan panduan untuk menunya.
8. **Aplikasi staf lama** (build yang sedang dipakai): login, absensi, dan daftar booking tetap bekerja. Rute baru bersifat tambahan, jadi tidak boleh ada yang berubah di layar lama.
9. **Log kesalahan** bersih dan Sentry tenang:

```bash
tail -n 50 storage/logs/laravel.log | grep -i "error\|exception" | head
```

10. **Pantau jadwal:** kira-kira 20 menit setelah deploy, pastikan `notifications:check-receipts` sudah jalan tanpa error dan tidak ada tumpukan baris "Menunggu kuitansi" yang tidak berubah di Log Pengiriman (tanda cron atau Expo bermasalah).

## 7. Langkah sesudahnya (keputusan pemilik, tidak ikut deploy)

- **Kode toko dan penanggung jawab:** isi di Toko/Dealer agar laporan dan dokumen punya kode yang membedakan toko.
- **Luas Kaca:** jalankan **Impor dari Google Sheet**, baca Pratinjau, impor, lalu periksa kelengkapan di Pusat Master Data. Hanya bila impor sudah lengkap dan benar, pertimbangkan menyalakan `FEATURE_PRICELIST_FROM_DATABASE=true` di `.env` pada rilis tersendiri (lalu `php artisan config:cache` setelah memastikan tidak sedang menjalankan tes).
- **Histori Aktivitas:** tentukan siapa yang diberi kotaknya (mis. Spv Finance atau IT) lewat Akses Menu.
- **Catatan Panduan:** pemilik menulis catatan perusahaan yang diperlukan (aturan toko, batas, kebijakan).
- **Notifikasi:** kirim satu notifikasi uji ke akun staf sendiri dan periksa hasilnya di Kampanye dan Log Pengiriman.

## 8. Aplikasi mobile (tidak ikut deploy ini)

Rilis ini hanya sisi server. Layar-layar baru ada di working copy `ginnva-mobile` milik asisten dan **belum di-commit sesuai aturan Anda**: Kotak Masuk staf, layar Panduan (daftar berkelompok yang bisa dibuka-tutup, catatan, kaki versi, tile di beranda), serta perubahan layar lain. Semuanya baru lolos cek tipe dan **belum pernah dicoba di HP**. Sebelum ada build baru: commit oleh developer, uji di perangkat (installer dan Store Manager), baru build. API yang baru bersifat tambahan, jadi aplikasi lama tetap jalan.

## 9. Rollback

- **Masalah tampilan atau perilaku, migrasi sudah `Ran`:** kembalikan kode saja ke hash yang dicatat di bagian 4 butir 2, tanpa menyentuh basis data (kolom dan tabel baru yang tidak dipakai tidak berbahaya):

```bash
git checkout <hash-yang-dicatat> && php artisan optimize:clear && php artisan optimize && php artisan filament:optimize && php artisan queue:restart
```

- **Migrasi gagal di tengah:** jangan jalankan ulang membabi buta. Lihat `php artisan migrate:status`; semua migrasi memeriksa keberadaan kolom, tabel, atau indeks sehingga aman dijalankan ulang setelah penyebabnya diperbaiki.
- **Membalik migrasi (`migrate:rollback --step=7`)** hanya bila perlu dan sebelum ada data baru: tabel baru (kosong) akan dihapus; kampanye, templat, catatan panduan, dan Log Pengiriman yang sudah terbentuk ikut hilang. Pulihkan dari backup bila data pernah terisi.

## 10. Risiko terbuka

- Suite penuh belum diulang setelah pekerjaan terakhir (bagian 4, butir 1); jangan lewati.
- `activity_log` bisa besar; bagian 4 butir 4 menentukan waktu yang aman.
- Cron `schedule:run` tiap menit belum diverifikasi di production (bagian 4, butir 3).
- Layar mobile baru belum teruji di perangkat (bagian 8); tidak menghalangi rilis server.
- Uji klik login panel dan aplikasi lama (bagian 6, butir 1, 7, 8) harus dilakukan manusia; tes otomatis tidak menggantikannya.
