# Rencana Rilis Production

Dibuat 2026-10-12. Dokumen kerja untuk memperbarui production dari versi lama ke versi terbaru dengan aman.
Perintah di sini untuk server cPanel Ginnva (user `ginnva`); isi dokumen belum dijalankan.

## 1. Keadaan sekarang (diukur 2026-10-12)

| Hal | Production (`/home/ginnva/public_html/ginnva-api`) | Target (`origin/development`) |
|---|---|---|
| Commit | `8009cfb` (4 September 2026), branch `development` | terbaru |
| Jarak | tertinggal **681 commit**, **143 migrasi baru**, 994 file berubah | - |
| Komposer | tidak berubah (`composer.json`/`lock` sama) | - |
| Perubahan lokal di server | `QuotationController.php` (email opsional; **sudah ada di Git** sebagai commit `37fe4a2`) dan berkas `vehicles-backup-20260827.sql` (tidak dilacak) | - |
| Backup | skrip harian `~/backup_ginnva.sh` jam 03:00 ke Google Drive terenkripsi, terbukti jalan | `backup:database` bawaan aplikasi (menggantikan skrip) |
| Cache | belum ada cache rute/event | `php artisan optimize` + `filament:optimize` |

Riwayat production adalah leluhur `origin/development`, jadi pembaruan berupa maju (fast-forward) tanpa konflik riwayat.

## 2. Prinsip

1. **Jangan sentuh production sebelum latihan di salinan data production berhasil.**
2. Backup manual terenkripsi dibuat tepat sebelum rilis (bukan hanya yang jam 03:00).
3. Rilis di jam sepi, dengan satu orang yang memantau dan jalur mundur yang jelas.
4. Migrasi tidak dianggap bisa dibalik. Jalur mundur = **pulihkan database dari backup**, bukan `migrate:rollback`.
5. Aplikasi mobile lama masih terpasang di HP pengguna: API baru harus tetap melayani mereka (lihat bagian 6).

## 3. Fase A, latihan di salinan data production (belum menyentuh production)

Tujuan: membuktikan 143 migrasi lolos terhadap **data nyata**, mengukur lama migrasi, dan menemukan data yang bentrok.

1. Buat database kosong `ginnva_restoretest` di cPanel (MySQL Databases) dan beri user `ginnva_ginnva` hak penuh.
2. Unduh backup terbaru dari Drive (otomatis didekripsi) dan pulihkan ke database itu:

```bash
mkdir -p ~/rilis_test && ~/bin/rclone copy gdrive-crypt:ginnva_backup_YYYYMMDD_HHMMSS.sql.gz ~/rilis_test/ && time (gunzip -c ~/rilis_test/ginnva_backup_YYYYMMDD_HHMMSS.sql.gz | mysql -u ginnva_ginnva -p ginnva_restoretest)
```

3. Dari folder **dev** (`ginnva-api-dev`, kode terbaru), jalankan migrasi terhadap salinan itu. `config:clear` wajib supaya variabel lingkungan dipakai, dan `config:cache` lagi sesudahnya:

```bash
php artisan config:clear && DB_DATABASE=ginnva_restoretest php artisan migrate --force; php artisan config:cache
```

Catat: berapa lama, dan **migrasi mana yang gagal** (kalau ada).
4. Bandingkan jumlah data sebelum dan sesudah (customers, bookings, users, warranties, point_transactions) dan cek sampel: booking lama masih terbaca, garansi lama punya jadwal, user lama masih bisa login.
5. Hapus database uji dan berkas sementara setelah selesai.

### Migrasi yang perlu perhatian khusus saat latihan

Ini yang mengubah atau menghapus **data yang sudah ada**, atau mengimpor data awal. Periksa hasilnya di salinan:

| Migrasi | Risiko |
|---|---|
| `2026_09_10_000002_add_detailing_to_film_products`, `..._09_22_000001_add_premium_wash_to_film_products`, `..._000007_create_panoramic_film_products` | menambah/menghapus produk film; periksa katalog produk |
| `2026_09_10_000008_delete_wf_h15_h30_film_products` | **menghapus** produk film (sudah ditandai tidak reversibel); pastikan tidak dipakai data production |
| `2026_09_10_000004_seed_default_price_rules`, `000006_import_majoo_film_prices`, `000009_import_bahan_baku_from_majoo` | menyisipkan data awal; periksa tidak menggandakan data yang sudah ada |
| `2026_09_14_000001_add_correction_type_to_raw_material_movements`, `2026_09_25_000003_add_revoke_to_warranties`, `2026_09_27_000002_...payrolls`, `2026_09_28_000007`, `2026_09_29_000001/3/4/5/6/7/8` | `ALTER TABLE ... MODIFY` pada tabel berisi data (enum/kolom); periksa data lama tetap valid |
| `2026_09_25_000003_add_revoke_to_warranties` | juga menjalankan `UPDATE` pada status garansi |
| `2026_09_28_000010_create_cfo_role`, `2026_09_29_000002_remove_cfo_role`, `2026_10_10_000001_replace_ppf_leader_with_installer_leader_role` | membuat/menghapus/mengganti nama role; periksa role dan penugasan user |
| `2026_10_02_000002_booking_cancellation_reschedule_and_unread` | `UPDATE` pada `booking_messages` |
| `2026_10_10_000002_add_voucher_type_to_rewards_and_claims` | mengubah tabel voucher dan reward (kolom baru, FK) |
| `2026_09_29_000006` dan `000011` | menyisipkan data pemasok awal |

Tabel baru (akuntansi, SPK, jadwal kerja, dst.) kosong di production, jadi batasan unik pada tabel itu aman.

## 4. Fase B, hari rilis (production)

**Jadwal:** pilih hari dan jam sepi (mis. pagi setelah backup 03:00 selesai). Umumkan ke staf. Perkiraan durasi baru diketahui dari Fase A.

**Sebelum mulai**
- Fase A selesai tanpa kecelakaan.
- Backup otomatis terakhir (jam 03:00) berstatus `OK`.
- Aplikasi mobile terbaru sudah diuji terhadap API dev (bagian 6).

**Langkah** (di folder production, user `ginnva`):

```bash
# 1. Backup manual terenkripsi (cukup jalankan skrip yang sudah ada)
~/backup_ginnva.sh

# 2. Simpan perubahan lokal (QuotationController sudah ada di versi baru, jadi tidak perlu dikembalikan)
git status --short
git stash push -m "hotfix-quotation-20261004" -- app/Http/Controllers/Api/QuotationController.php

# 3. Tarik kode terbaru
git fetch origin && git pull origin development

# 4. Autoload dan dependensi (aman diulang; composer tidak berubah)
composer install --no-dev --optimize-autoloader

# 5. Migrasi (mode pemeliharaan selama langkah 5)
php artisan down --retry=60
php artisan migrate --force
php artisan up

# 6. Cache untuk performa (hanya di production)
php artisan optimize && php artisan filament:optimize
php artisan queue:restart
```

Hal yang **harus diputuskan sebelum rilis** (dari Fase A):
- Apakah perlu `php artisan db:seed --class=ChartOfAccountSeeder` (aman diulang, memakai `updateOrCreate` per kode akun); akun 6520 pernah hilang dari seeder. **Jangan** menjalankan `RolePermissionSeeder` atau `DatabaseSeeder` di production: keduanya dapat membuat akun admin dengan kata sandi acak.
- Setelah rilis: aktifkan `backup:database` bawaan (isi `RCLONE_BACKUP_REMOTE=gdrive-crypt:` dan `RCLONE_BINARY` yang sudah ada di `.env`), lalu **matikan** skrip `backup_ginnva.sh` dan baris cron-nya, supaya tidak ada dua backup sekaligus.

## 5. Uji asap setelah rilis (hari yang sama)

Admin: login, buka Dashboard, Booking, Pelanggan, Garansi (tab Jadwal Maintenance), Stok, satu laporan (Penjualan), halaman Panduan.
API (tanpa login): `GET /api/rewards`, `/api/stores`, `/api/news` harus 200.
API (dengan login): login staf, login pelanggan, daftar booking, detail booking, chat booking, daftar voucher saya.
Pantau: Sentry (error baru), `storage/logs/laravel.log`, antrean (`queue:work` jalan), notifikasi push terkirim.
Cek: backup berikutnya (03:00) berhasil.

## 6. Kompatibilitas aplikasi mobile

Pengguna masih memakai aplikasi versi lama. Sebelum rilis, jalankan **versi aplikasi yang sekarang ada di Play Store** terhadap API dev dan periksa alur utamanya:
login, daftar dan buat booking, chat booking, garansi, poin, voucher saya, reward.

Perubahan API yang bisa memengaruhi aplikasi lama (semua dicatat saat dikerjakan):
- Chat booking staf: installer kini ditolak (403); leader installer yang masuk.
- Pembuatan SPK: hanya leader installer dan atasannya (installer ditolak 403).
- Voucher: bentuk daftar "Voucher Saya" tetap memuat objek `voucher` untuk aplikasi lama; `vouchers/available` kini mengembalikan reward bertipe voucher.
- Konfirmasi maintenance: bisa menerima `preferred_date` (opsional), tetap kompatibel.
- Detail garansi menambah field (`valid_until`, `expired`, `store_id`), tetap kompatibel.
- Tanggal "hampir penuh": endpoint baru `limited-dates` (tambahan).

Rilis build aplikasi baru **setelah** API production diperbarui, bukan sebelumnya. Layar Reward di mobile masih "Coming Soon".

## 6b. Jalur mundur

Jika ada kegagalan serius setelah migrasi:
1. `php artisan down`.
2. Pulihkan database dari backup manual yang dibuat di langkah 1 (`gunzip -c ... | mysql ...` ke database production; **hati-hati, ini menimpa data**).
3. Kembalikan kode: `git checkout 8009cfb` (commit sebelum rilis) lalu `php artisan config:clear && php artisan cache:clear`.
4. `php artisan up`, lalu analisis di salinan, bukan di production.

Data yang masuk **setelah** backup (booking baru, dll.) hilang pada pemulihan: karena itu rilis di jam sepi dan seluruh langkah dicatat waktunya.

## 7. Keputusan yang masih terbuka sebelum rilis

- Besaran poin kunjungan maintenance (`LOYALTY_MAINTENANCE_VISIT_POINTS`, sekarang 0 = mati).
- Akun leader installer (role `installer_leader` sudah ada; akun belum dibuat).
- Bahasa panel admin (`APP_LOCALE=id`).
- Client ID Google Drive sendiri untuk backup (batas akhir 2026).
- Waktu rilis, orang yang memantau, dan pengumuman ke staf.
