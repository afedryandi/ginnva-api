# Rencana Deploy Production: Pembaruan Dependensi (32 advisori composer audit)

Dibuat 2026-10-23. Melanjutkan `RENCANA_DEPLOY_NOTIFIKASI_HISTORI_MASTERDATA_PANDUAN.md` (dijalankan 2026-10-22, production `0da10b1`). **DIJALANKAN 2026-10-23:** production `0da10b1` → `b49a7af`, `composer install --no-dev` memasang 1 install, 58 pembaruan, 1 penghapusan; `composer audit`: tidak ada advisori; Laravel 12.70.1, PHP 8.2.35, production, debug OFF. Backup 03:00 hari itu ada. Sebelum rilis: pemeriksaan pasca-PHP2Shell bersih (akun, berkas, log akses 17-22 Okt). Verifikasi klik (bagian 5) menyusul. Perintah untuk server cPanel Ginnva, user `ginnva`, folder production `ginnva-api` (tanpa `cd`).

## 1. Cakupan

Hanya `composer.lock` (commit `9e027e6`); `composer.json` tidak berubah, tidak ada migrasi, tidak ada perubahan kode aplikasi. `composer audit` production sebelum rilis: 32 advisori di 9 paket. Setelah pembaruan, di lock: **tidak ada advisori** (`composer audit --locked`).

| Paket | Sebelum | Sesudah | Alasan |
|---|---|---|---|
| `symfony/http-foundation` | 7.4.14 | 7.4.21 | ReDoS lewat header `Cache-Control`, terjangkau tanpa login |
| `livewire/livewire` | 3.8.1 | 3.8.11 | XSS DOM (panel admin Filament) |
| `phpoffice/phpspreadsheet` | 1.30.5 | 1.30.7 | memori habis (XLS/OLE, Gnumeric), SSRF via WEBSERVICE; dipakai impor Excel |
| `maatwebsite/excel` | 3.1.69 | 3.1.70 | tulis ekspor di luar disk yang dikonfigurasi |
| `guzzlehttp/guzzle` | 7.14.0 | 7.16.0 | bypass host, cookie, proxy |
| `dompdf/dompdf` | 3.1.5 | 3.1.6 | SVG baca berkas, DoS gambar |
| `league/commonmark` | 2.8.2 | 2.10.4 | DoS dan XSS di parser Markdown |
| `league/flysystem` (+local) | 3.35.2 | 3.36.0 | normalisasi path UTF-8 |
| `laravel/framework` | 12.63.0 | 12.70.1 | XSS halaman debug |
| `symfony/yaml` (dev) | 7.4.13 | 7.4.21 | rekursi tak terbatas (hanya dev) |

Sekitar 58 paket lain naik patch atau minor karena dependensi. `filament/filament` tetap 3.3.54. Paket dihapus: `thecodingmachine/safe` (tidak dipakai kode proyek); ditambah: `symfony/polyfill-time`. Tidak ada paket di lock yang mensyaratkan PHP di atas 8.2 (dipastikan; percobaan pertama sempat memilih Symfony 8.x yang butuh PHP 8.4, ditolak dengan menyimulasikan platform PHP 8.2.35).

## 2. Bukti sebelum rilis

- Dev: `composer install` bersih di PHP 8.2.35, `composer audit` "No security vulnerability advisories found".
- Dev: **suite penuh hijau, 2747 tes, 19.171 assertion** (29 menit) dengan dependensi baru.

## 3. Sebelum deploy

1. Backup 03:00 hari ini ada:

```bash
find ~ storage -maxdepth 6 -name 'ginnva_backup_*.sql.gz' -mtime -1 -printf '%TY-%Tm-%Td %TH:%TM  %10s bytes  %p\n' 2>/dev/null | sort
```

2. Keadaan Git production (hanya berkas dump kendaraan yang boleh tidak terlacak). **Catat hash commit**, itu titik kembali:

```bash
git branch --show-current && git status --short && git log --oneline -1
```

   Harapan: `development`, tanpa perubahan lain, `0da10b1` (atau commit dokumen sesudahnya).

## 4. Deploy

```bash
git pull origin development && composer install --no-dev --optimize-autoloader --no-interaction && php artisan optimize:clear && php artisan optimize && php artisan filament:optimize && php artisan queue:restart
```

Bila `composer` mengeluh soal versi PHP, panggil dengan PHP yang sama dengan web: `/opt/cpanel/ea-php82/root/usr/bin/php "$(which composer)" install --no-dev --optimize-autoloader --no-interaction`.

Lalu:

```bash
composer audit; php artisan about | head -n 12
```

Harapan: "No security vulnerability advisories found" dan Laravel 12.70.1.

## 5. Verifikasi setelah deploy (production, tanpa membuat data pelanggan)

Sebagai akses penuh di panel:

1. **Login panel dan Dashboard** terbuka tanpa error (Livewire dan Filament baru).
2. **Panduan:** halaman terbuka, kelompok bisa dibuka dan ditutup, pencarian bekerja.
3. **Ekspor Excel:** ekspor satu daftar kecil (mis. Daftar Produk atau Histori Aktivitas dengan rentang sempit) dan berkas terunduh serta bisa dibuka.
4. **PDF:** unduh satu slip gaji atau cetak satu SPK atau invoice, dan tampilannya normal.
5. **Aplikasi staf lama:** login, absensi, dan daftar booking tetap bekerja.
6. Log kesalahan bersih:

```bash
tail -n 50 storage/logs/laravel.log | grep -i "error\|exception" | head
```

## 6. Rollback

Hanya kode dan paket, tanpa basis data (tidak ada migrasi):

```bash
git checkout <hash-yang-dicatat> && composer install --no-dev --optimize-autoloader --no-interaction && php artisan optimize:clear && php artisan optimize && php artisan filament:optimize && php artisan queue:restart
```

## 7. Risiko dan catatan

- Risiko tertinggi: perilaku ekspor dan impor Excel (`maatwebsite/excel` + `phpspreadsheet`) dan tampilan Filament/Livewire. Keduanya tertutup suite penuh hijau dan diverifikasi manual di bagian 5.
- Berkas `vehicles-backup-20260827.sql` yang tidak terlacak di folder production tidak mengganggu `git pull`; pindahkan atau hapus di luar rilis ini.
- PHP 8.2 berakhir dukungannya 2026-12-31; kenaikan ke 8.3/8.4 adalah pekerjaan terpisah dan butuh audit kompatibilitas `composer.lock`.
