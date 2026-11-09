# Rencana Naik Versi PHP (8.2 ke 8.4)

Dibuat 2026-11-09. **Alasan:** dukungan keamanan PHP 8.2 berakhir **31 Desember 2026**. Sesudah itu server production tidak lagi menerima perbaikan keamanan PHP. Target yang disarankan: **PHP 8.4** (Laravel 12 dan Filament 3.3 mendukungnya; dukungan keamanannya jauh lebih panjang). Cadangan: PHP 8.3 bila hosting belum menyediakan 8.4. Tanggal dukungan resmi: cek https://www.php.net/supported-versions.php sebelum memutuskan.

## 1. Hasil pemeriksaan kode (sudah dikerjakan, 2026-11-09)

| Pemeriksaan | Hasil |
|---|---|
| Lint seluruh berkas PHP (1.881 berkas: app, config, database, routes, tests, view) dengan PHP 8.4.23 dan semua peringatan menyala | **0 galat sintaks, 0 deprecation** |
| `composer check-platform-reqs` terhadap PHP 8.4 | Satu penghalang: `lcobucci/clock 2.3.0` (hanya mendukung PHP 8.1 dan 8.2). Penghalang lain tidak ada. |
| Asal penghalang | `tymon/jwt-auth 2.3.0` meminta `lcobucci/jwt ^4.0 atau ^5.0`; versi 4.0.4 yang terpasang meminta `lcobucci/clock ^2.0`. |
| Perbaikan | `composer update lcobucci/jwt lcobucci/clock`: `lcobucci/jwt` 4.0.4 menjadi **5.6.1** (mendukung 8.2, 8.3, 8.4). `lcobucci/clock` dan `stella-maris/clock` tidak lagi dibutuhkan dan terhapus. Hanya `composer.lock` yang berubah. |
| Uji asap JWT di PHP 8.4 setelah perbaikan | Token dibuat dan dibaca kembali: OK. Token rusak: ditolak. (Validasi masa berlaku dan blacklist dikerjakan Tymon dan butuh database; dibuktikan oleh tes di server dev.) |
| `composer.json` | `"php": "^8.2"`: sudah mengizinkan 8.3 dan 8.4, tidak perlu diubah. |

Kompatibel mundur: `lcobucci/jwt 5.6.1` tetap jalan di PHP 8.2, jadi perubahan ini aman dirilis **sebelum** PHP dinaikkan, dan bisa dikembalikan ke 8.2 kapan saja.

## 2. Ekstensi PHP yang dibutuhkan (periksa di PHP baru pada server)

`ctype curl dom fileinfo filter gd hash iconv intl json libxml mbstring openssl pcre session simplexml sodium tokenizer xml xmlreader xmlwriter zip zlib`, ditambah driver `pdo_mysql`. Di cPanel: MultiPHP INI Editor atau "Select PHP Version" pada versi baru. Pastikan `intl`, `gd`, `zip`, `sodium`, dan `pdo_mysql` aktif.

## 3. Urutan pengerjaan

**Tahap A: perbarui dependensi (di PHP 8.2 yang sekarang).**
1. Pull perubahan `composer.lock` (commit "lcobucci/jwt 5.6.1").
2. Di dev: `composer install --no-interaction`, lalu jalankan tes penuh. Semua harus hijau, khususnya tes login, token, dan guard (`PhoneLoginTest`, `AccountSecurityLogTest`, `CustomerPhoneLoginTest`).
3. Rilis ke production seperti biasa dan **uji login sungguhan** (karyawan dan pelanggan).

**Tahap B: dev di PHP 8.4.**
1. cPanel, MultiPHP Manager: ganti PHP untuk `api-dev.ginnva.id` ke **ea-php84**. Pastikan ekstensi pada bagian 2 aktif.
2. Di server dev: `composer install --no-interaction` memakai PHP yang sama (jika composer mengeluh, panggil `/opt/cpanel/ea-php84/root/usr/bin/php "$(which composer)" install ...`).
3. `php artisan --version` dan `php -v` harus menunjukkan 8.4. Jalankan `php artisan migrate --force` (seharusnya tidak ada yang baru) dan `php artisan optimize:clear`.
4. Tes penuh: `php artisan test`. Bandingkan dengan hasil di PHP 8.2 (3424 lulus). Catat setiap deprecation baru di log.
5. Uji klik di panel (login, daftar utama, ekspor Excel dan PDF, unggah foto) dan di aplikasi (login, absen, kotak masuk).

**Tahap C: production di PHP 8.4.** Jalankan di jam sepi, setelah Tahap B hijau selama beberapa hari.
1. **Backup manual** (`php artisan backup:database`) dan catat versi PHP lama.
2. cPanel, MultiPHP Manager: ganti PHP `api.ginnva.id` ke **ea-php84**.
3. **Ganti jalur PHP di crontab server.** Pada cron, `* * * * * ... schedule:run` dan perintah lain yang memakai `/opt/cpanel/ea-php82/root/usr/bin/php` harus diubah ke `ea-php84`. Tanpa ini, scheduler (backup 03:00, pengingat absen, notifikasi, dan lain-lain) tetap jalan di PHP lama atau gagal. Periksa juga pekerja antrean (`queue:work`) bila memakai skrip atau supervisor dengan jalur PHP tetap.
4. `composer install --no-dev --optimize-autoloader --no-interaction` dengan PHP 8.4, lalu: `php artisan migrate --force && php artisan optimize:clear && php artisan optimize && php artisan filament:optimize && php artisan queue:restart`.
5. Uji klik: login panel, satu halaman Filament berat (mis. Daftar Booking), ekspor Excel, satu absen dari aplikasi, kirim satu notifikasi uji, dan cek `storage/logs/laravel.log` serta `/home/ginnva/logs/php.error.log` untuk galat baru.

## 4. Rencana mundur (rollback)

Bila ada masalah sesudah Tahap C: kembalikan PHP `api.ginnva.id` ke **ea-php82** di MultiPHP Manager, kembalikan jalur PHP di crontab, lalu `php artisan optimize:clear && php artisan optimize && php artisan filament:optimize && php artisan queue:restart`. Karena `composer.lock` kompatibel dengan 8.2 sampai 8.4, tidak perlu mengembalikan dependensi.

## 5. Temuan terpisah: celah keamanan dependensi

`composer audit` (2026-11-09) melaporkan **50 advisori di 14 paket**, di antaranya `laravel/framework` (1 kritis, TokenGuard; aplikasi ini memakai guard JWT dan sesi, bukan TokenGuard, jadi kemungkinan tidak terkena, tetap perlu diperbarui), `guzzlehttp/guzzle` (1 tinggi), `league/commonmark` (9 tinggi), `phpoffice/phpspreadsheet` (6 tinggi), `maatwebsite/excel` (1 tinggi), `dompdf`, `livewire`, `flysystem`, dan beberapa paket `symfony/*`. Pembaruan ini **tidak dikerjakan** di rencana ini dan sebaiknya menjadi paket sendiri: `composer update` terarah, tes penuh di dev, lalu rilis. Lihat `docs/RENCANA_DEPLOY_PEMBARUAN_DEPENDENSI.md` untuk pola sebelumnya.
