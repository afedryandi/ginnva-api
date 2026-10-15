# Rencana Deploy Production: Booking v2 (Fase 0–5)

Dibuat 2026-10-15. Melanjutkan `RENCANA_RILIS_PRODUCTION.md` (rilis 13 Okt). **Dokumen ini belum dijalankan.** Perintah untuk server cPanel Ginnva, user `ginnva`, folder production `ginnva-api` (perintah tidak diawali `cd`; terminal Anda sudah di sana).

## 1. Keadaan dan cakupan

| Hal | Production sekarang | Yang akan naik |
|---|---|---|
| Commit | `4bd0531` (rilis 13 Okt), kemungkinan di cabang `hotfix-edit-booking` (`8b32ceb`, 4bd0531 + perbaikan Edit Booking) | `origin/development` (49 commit di atas 4bd0531) |
| Migrasi | sampai rilis 13 Okt | **10 migrasi baru**, semuanya hanya menambah tabel/kolom/baris awal (tabel 3 bagian di bawah) |
| Composer | tidak berubah | tidak berubah (`composer.json`/`lock` sama) |
| Jadwal | seperti sekarang | + `idempotency:prune` harian 04:20, + `bookings:prune-photos` mingguan Minggu 04:40 |
| Variabel `.env` | `APP_ENV=production`, `APP_TIMEZONE=Asia/Jakarta` (sudah diperiksa 2026-10-15) | **tidak ada yang wajib**; semua flag baru punya bawaan aman (bagian 5) |
| Tes | n/a | seluruh suite 2.274 tes hijau di dev; `smoke:flow` 44/44 |

Yang naik: versi minimum aplikasi (426), kunci idempotensi, Kendaraan Saya, booking v2, katalog layanan (menu **Layanan Booking**), kapasitas berat/ringan dan slot jam, Quality Check, serah terima, foto penerimaan, tandai tidak datang, pengingat H-2/H-1/hari-H, kebijakan batal dan persetujuan privasi (menu **Teks Aplikasi**), laporan SLA & tidak datang, retensi foto, dan perbaikan Edit Booking.

## 2. Perubahan perilaku yang terlihat SEGERA setelah deploy server

Sebelum build mobile baru dirilis, ini sudah terasa:

1. **Pelanggan lama mulai menerima pengingat baru.** Run pagi 08:00 mengirim push H-2 dan "Jadwal Anda Hari Ini" untuk **semua** booking terkonfirmasi (bukan hanya booking baru). Pesan H-1 juga berubah isinya (layanan, toko, jam, kebijakan). Push membuka layar Booking Saya, yang sudah ada di aplikasi lama.
2. **Panel admin berubah:** menu baru Layanan Booking, Daftar Periksa QC, Teks Aplikasi; Laporan SLA & Tidak Datang; kolom Jam Diinginkan di form Booking menyarankan slot; bagian "Penerimaan, QC, dan Serah Terima" di detail Booking.
3. **Kapasitas dihitung ulang:** booking yang hanya berisi Detailing/Premium Wash tidak lagi memakan kapasitas harian PPF/kaca film; booking tanpa penanda produk tetap dihitung berat. **Tanggal yang tadinya "penuh" bisa terbuka.** Bandingkan Kalender Kapasitas sebelum dan sesudah.
4. **Slot jam mengikuti jam buka toko.** Toko dengan jam buka kosong atau salah akan menampilkan slot yang salah. Periksa jam buka tiap toko (bagian 4).
5. **Aplikasi Play Store yang beredar tetap berfungsi:** payload booking lama diterima, `confirm`/`complete` tidak berubah (flag QC dan serah terima mati), dan versi minimum tidak dipasang.

## 3. Migrasi yang akan jalan

| Migrasi | Isi | Risiko |
|---|---|---|
| `2026_10_14_000001_create_idempotency_keys_table` | tabel baru | rendah |
| `2026_10_15_000001_create_customer_vehicles_table` | tabel baru | rendah |
| `2026_10_15_000002_add_vehicle_snapshot_to_bookings_table` | tambah kolom kendaraan (nullable) di `bookings` | rendah; tabel terbesar yang diubah, lama menyesuaikan jumlah baris |
| `2026_10_16_000001_create_booking_service_types_table` | tabel baru + 4 baris layanan | rendah |
| `2026_10_16_000002_add_light_capacity_to_stores_table` | kolom `stores.light_capacity_per_slot` (nullable) | rendah |
| `2026_10_17_000001_create_qc_tables` | 3 tabel + 20 butir QC bawaan + kolom `bookings.ready_at` | rendah |
| `2026_10_18_000001_create_booking_handovers_table` | tabel baru | rendah |
| `2026_10_19_000001_add_no_show_and_intake_photos` | kolom `bookings.no_show_at` + tabel foto | rendah |
| `2026_10_20_000001_add_h2_and_dayof_reminder_columns_and_app_settings` | 2 kolom di `bookings` + tabel `app_settings` + 1 baris kebijakan | rendah |
| `2026_10_21_000001_add_confirmed_at_and_privacy_consent_to_bookings` | 3 kolom di `bookings` + 1 baris pemberitahuan privasi | rendah |

Tidak ada migrasi yang menghapus atau mengubah data yang sudah ada, dan semuanya memeriksa dulu apakah tabel/kolom sudah ada. Di dev tiap migrasi selesai dalam milidetik sampai sekitar 0,4 detik.

## 4. Sebelum deploy (persiapan, tidak menyentuh kode)

1. **Pastikan backup 03:00 hari itu ada** (cadangan manual tambahan tidak diperlukan; cron harian sudah ada). Perintah yang sama dengan sebelumnya:

```bash
find ~ storage -maxdepth 6 -name 'ginnva_backup_*.sql.gz' -mtime -1 -printf '%TY-%Tm-%Td %TH:%TM  %10s bytes  %p\n' 2>/dev/null | sort; rclone lsl gdrive-crypt: | sort -k2,3 | tail -n 3
```

   Ukuran sekitar 71–72 MB dan ada di lokal **dan** Drive.
2. **Cek cabang production dan keadaan Git:**

```bash
git branch --show-current && git status --short && git log --oneline -1
```

   Harapan: cabang `hotfix-edit-booking` atau `development`, `status` kosong, commit `8b32ceb` atau `4bd0531`. Kalau ada perubahan lokal yang tidak dikenal, **berhenti dan lapor** (jangan di-stash sembarangan).
3. **Pemeriksaan retensi foto (wajib, hanya membaca):** jadwal mingguan yang baru akan menghapus foto booking selesai/batal yang lebih dari 24 bulan. Lihat dulu apa yang akan terkena:

```bash
php artisan bookings:prune-photos --dry-run
```

   Perintah ini baru tersedia setelah kode naik, jadi jalankan segera setelah langkah 6 dan **sebelum Minggu 04:40**. Harapannya `0 file` (sistem baru beroperasi 2026). Kalau angkanya bukan 0, jangan biarkan jadwal berjalan: tunda dengan `BOOKING_PHOTO_RETENTION_MONTHS=999` di `.env`.
4. **Catat angka pembanding sebelum deploy** (untuk dicocokkan sesudahnya): jumlah booking terkonfirmasi 14 hari ke depan per toko, dan tanggal yang tampil penuh di Kalender Kapasitas.
5. **Jam buka tiap toko** (panel: Toko): pastikan terisi benar. Slot layanan ringan hanya ditawarkan di dalam jam buka; toko tanpa jam buka memakai semua slot 09:00–17:00.
6. Pilih waktu sepi (hari kerja pagi sebelum jam buka, setelah backup 03:00 dan sebelum run pengingat 08:00 lebih aman), satu orang memantau.

## 5. Deploy

Perintah (urut, hentikan bila ada yang gagal):

```bash
git checkout development && git pull origin development && php artisan migrate --force && php artisan optimize:clear && php artisan optimize && php artisan filament:optimize && php artisan queue:restart
```

Lalu langsung:

```bash
php artisan migrate:status | tail -n 14; php artisan bookings:prune-photos --dry-run
```

Harapan: 10 migrasi baru berstatus `Ran`, dan dry-run melaporkan `0 file`.

Variabel `.env`: **tidak ada yang perlu diubah.** Bawaan yang berlaku:

| Variabel | Bawaan | Arti |
|---|---|---|
| `BOOKING_QC_REQUIRED` | `false` | QC belum wajib untuk menyelesaikan booking |
| `BOOKING_HANDOVER_REQUIRED` | `false` | serah terima belum wajib |
| `BOOKING_SHOW_PRICES` | `false` | harga tidak tampil di aplikasi |
| `BOOKING_LIGHT_CAPACITY_DEFAULT` | `2` | kapasitas layanan ringan per jam bila toko belum mengisi |
| `BOOKING_CONFIRM_SLA_HOURS` | `4` | target konfirmasi untuk laporan SLA |
| `BOOKING_PHOTO_RETENTION_MONTHS` | `24` | masa simpan foto |
| `APP_MIN_VERSION_ANDROID/IOS` | kosong | tidak ada versi minimum (jangan diisi sebelum build baru beredar) |

## 6. Verifikasi setelah deploy (production, tanpa membuat data)

`smoke:api` dan `smoke:flow` **menolak** berjalan di database production (dijaga nama database). Verifikasi di production manual dan hanya membaca:

1. Endpoint publik (ganti domain bila perlu):

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://api.ginnva.id/api/stores; curl -s https://api.ginnva.id/api/booking/services | head -c 300; echo; curl -s https://api.ginnva.id/api/app/config | head -c 200; echo
```

   Harapan: `200`, katalog memuat 4 layanan dan `cancellation_policy`, konfigurasi versi tanpa `min_version`.
2. Panel admin (login akses penuh), tiap halaman terbuka tanpa error: Booking (daftar, satu detail, satu Edit lalu simpan, pastikan tidak error 500), Layanan Booking, Daftar Periksa QC, Teks Aplikasi, Kalender Kapasitas, Laporan SLA & Tidak Datang, Toko (kolom Kapasitas Layanan Ringan / Jam).
3. Bandingkan Kalender Kapasitas dengan catatan sebelum deploy (bagian 4 butir 4); selisihnya harus bisa dijelaskan oleh booking layanan ringan.
4. Absen, aplikasi mobile lama (Play Store): buka daftar booking, buka satu booking staf, kirim satu pesan chat. Harus berjalan seperti sebelumnya.
5. Pantau Sentry dan `storage/logs/laravel.log` selama minimal 1 jam, lalu setelah run 08:00 berikutnya (pengingat baru) dan setelah 03:00 (backup tetap satu file per hari).
6. Tanyakan ke satu pelanggan atau staf uji apakah push H-2/hari-H terbaca wajar.

## 7. Jalur mundur

- **Mundur kode saja (cukup untuk hampir semua masalah):** migrasi hanya menambah, jadi kode lama mengabaikan tabel/kolom baru dan tetap jalan.

```bash
git checkout hotfix-edit-booking && php artisan optimize:clear && php artisan optimize && php artisan filament:optimize && php artisan queue:restart
```

  Catatan jujur: jalur ini **belum pernah dilatih**. Pengingat baru berhenti, menu admin baru hilang, booking yang sudah dibuat dengan data kendaraan/persetujuan tetap tersimpan di kolomnya (tidak hilang).
- **Pulihkan database dari backup** hanya bila data rusak (bukan karena kode): `migrate:rollback` **tidak dipakai** (kebijakan RUNBOOK §3). Pemulihan menghilangkan semua perubahan sejak backup 03:00, jadi pilih ini dengan sadar.
- Pengingat yang sudah terkirim ke pelanggan tidak bisa ditarik.

## 8. Setelah server stabil (sebelum build mobile dirilis)

Dikerjakan admin di panel; semua langsung terbaca aplikasi, tanpa rilis:

1. **Layanan Booking:** periksa nama, deskripsi, durasi, dan kategori berat/ringan tiap layanan.
2. **Daftar Periksa QC:** ganti 5 butir bawaan per layanan dengan SOP nyata Ginnva.
3. **Teks Aplikasi:** isi kebijakan pembatalan yang benar-benar diterapkan, dan **tinjau pemberitahuan privasi** (sebaiknya dibaca oleh pihak yang paham UU PDP; teks bawaan saya bukan nasihat hukum).
4. **Toko:** isi Kapasitas Layanan Ringan / Jam tiap toko dan pastikan jam buka benar.
5. **Pengguna:** pastikan tiap toko punya Store Manager aktif (Installer Leader masih vacant; QC memerlukan Store Manager atau Leader).

## 9. Rilis aplikasi mobile (setelah langkah 1–8)

1. Developer meng-commit perubahan mobile Fase 0–5 (belum di-commit, dan belum pernah dijalankan di HP).
2. Bangun dulu sebagai **preview internal** (profil `preview` di `eas.json`, APK) dan pasang di HP uji yang menunjuk ke **server dev**. Jalankan `CHECKLIST_UJI_HP.md` bagian A–L. Perbaiki dulu semua temuan.
3. Bangun versi produksi dan rilis bertahap di Play Store (mulai persentase kecil bila memungkinkan). **Server sudah harus di production sebelum langkah ini**, karena booking v2 mewajibkan persetujuan privasi dan membutuhkan endpoint baru.
4. Setelah mayoritas pengguna memperbarui: isi `APP_MIN_VERSION_ANDROID` di `.env` production dengan versi build baru (aplikasi lama diarahkan memperbarui) lalu `php artisan config:cache`.
5. Setelah staf terlatih dan tiap toko punya pemeriksa: nyalakan `BOOKING_QC_REQUIRED=true` dan `BOOKING_HANDOVER_REQUIRED=true`, lalu `php artisan config:cache`. Nyalakan bertahap (QC dulu, serah terima kemudian) bila ingin lebih hati-hati.

## 10. Keputusan dan risiko yang masih terbuka

- **Retensi foto 24 bulan** lebih pendek dari garansi PPF; foto bukti pemasangan terhapus sebelum garansi habis. Putuskan angka final sebelum jadwal mingguan berjalan.
- **Memori server production:** MySQL pernah restart sendiri (14 Okt 21:21) dan swap hampir penuh. Fitur baru menambah beban (foto, laporan, pengingat). Tindak lanjuti dengan hosting sebelum menaikkan penggunaan.
- **Mobile belum teruji di perangkat**; satu-satunya bukti yang ada adalah tes server dan tipe TypeScript.
- Yang sengaja tidak masuk: paket/add-on, antrean tunggu, deposit, tahap kerja layanan ringan, pengingat garansi, booking oleh staf, audit aksesibilitas, uji beban (lihat tabel pemeriksaan kelengkapan di `RANCANGAN_ULANG_BOOKING.md`).
- Item pascarilis lama yang masih terbuka: pemantau uptime `/api/stores`, pesan ke hosting soal memori, hapus berkas cadangan lama (`~/env_sebelum_prod_20261014.bak`, `~/rclone.conf.bak-20261014`, `~/ginnva_backup_*.sql.gz`).
