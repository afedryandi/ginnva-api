# Ginnva — Runbook Produksi

> Dokumen operasional: URL, daftar kredensial (lokasi, BUKAN nilai), dan
> SOP deploy/rollback/backup. Diperbarui manual — kalau ada perubahan
> infrastruktur, update file ini juga.

## 1. URL & Endpoint

| Layanan | URL | Keterangan |
|---|---|---|
| Website publik | https://ginnva.id | Next.js (`ginnva-web`) |
| Backend API | https://api.ginnva.id | Laravel (`ginnva-api`) |
| Admin Panel (Filament) | https://api.ginnva.id/admin | Login staff/super_admin |
| Mobile app (Android) | package `id.ginnva.shield` | Expo/React Native (`ginnva-mobile`) |
| Server (VPS) | `vps.ptsml.id` | Path project: `/home/ginnva/public_html/ginnva-api` |

_Isi baris "Mobile app" dengan link Play Store begitu app sudah live di sana._

## 2. Kredensial — lokasi penyimpanan (bukan nilainya)

**Aturan:** jangan pernah menempelkan nilai kredensial asli (password, API key, token) ke dokumen ini, ke chat AI, atau ke commit git. Yang dicatat di sini hanya **nama variabel** dan **di mana nilainya tersimpan**.

### `ginnva-api/.env` (server production)
Lokasi nilai asli: **file `.env` di server production**, dikelola manual oleh sysadmin/tim yang punya akses SSH. Salinan cadangan sebaiknya juga tersimpan di password manager tim (mis. Bitwarden/1Password vault "Ginnva Production").

| Variabel | Fungsi |
|---|---|
| `APP_KEY` | Encryption key Laravel — jangan pernah di-generate ulang di production (akan membuat semua data terenkripsi lama tidak terbaca) |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Kredensial MySQL production |
| `GROQ_API_KEY` | API key Groq untuk Asisten AI (console.groq.com/keys) |
| `FCM_SERVER_KEY` | Firebase Cloud Messaging — push notification |
| `SENTRY_LARAVEL_DSN` | Error tracking backend |
| `MAIL_*` | Kredensial SMTP pengirim email (OTP, notifikasi) |
| `JWT_TTL` | Bukan rahasia, tapi jangan diubah tanpa sadar konsekuensinya (lihat komentar di `.env.example`) |

### `ginnva-web/.env.local` (server production Next.js)
| Variabel | Fungsi |
|---|---|
| `NEXT_PUBLIC_API_URL` | Harus mengarah ke `https://api.ginnva.id` di production |
| `NEXT_PUBLIC_GA_ID` | Google Analytics 4 |
| `NEXT_PUBLIC_SENTRY_DSN`, `SENTRY_DSN`, `SENTRY_ORG`, `SENTRY_PROJECT`, `SENTRY_AUTH_TOKEN` | Error tracking + sourcemap upload |

### Mobile (`ginnva-mobile`)
| Item | Lokasi |
|---|---|
| `google-services.json` | Sudah ada di repo (`ginnva-mobile/google-services.json`) — kredensial Firebase Android, perlakukan sebagai rahasia meski ada di repo |
| `firebase-service-account.json` | Sudah ada di repo — **JANGAN** publikasikan repo ini secara publik selama file ini masih di dalamnya |
| Expo/EAS account | Akun yang dipakai untuk build (`eas build`) — kredensial login tersimpan di akun Expo tim, bukan di file |
| Play Console | Akun Google Play Console untuk submit APK/AAB — kredensial di password manager tim |

### Akses server
| Item | Lokasi |
|---|---|
| SSH key / password VPS (`vps.ptsml.id`) | Password manager tim, akses dibatasi ke yang butuh deploy |
| Akses database langsung (di luar aplikasi) | Sama seperti akses SSH — DB tidak expose ke publik, hanya `127.0.0.1` dari server itu sendiri |

## 3. SOP Deploy

1. Pastikan branch yang akan di-deploy sudah lolos testing lokal (`npx tsc --noEmit` untuk mobile, cek balance kurung untuk PHP kalau tidak ada linter).
2. `git push` ke remote (branch utama atau branch rilis, sesuai konvensi tim).
3. Di server: `git pull`, lalu (untuk `ginnva-api`):
   ```bash
   composer install --no-dev --optimize-autoloader
   php artisan migrate --force
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```
4. Untuk `ginnva-web`: `npm install && npm run build`, lalu restart proses Next.js (PM2/systemd sesuai setup server).
5. Untuk mobile: build lewat EAS (`eas build --platform android --profile production`), submit ke Play Console kalau sudah siap rilis publik.

## 4. SOP Rollback

1. `git log` di server untuk cari commit/tag sebelumnya yang stabil.
2. `git checkout <tag-atau-commit-lama>` (atau `git reset --hard` kalau memang mau buang perubahan — **pastikan sudah backup dulu**, lihat §5).
3. Ulangi langkah cache-clear/build seperti SOP Deploy di atas.
4. Kalau rollback juga perlu mundur migrasi database, jalankan `php artisan migrate:rollback` **dengan hati-hati** — pastikan tidak ada data baru yang akan hilang; lebih aman restore dari backup (§5) kalau migrasi sudah mengubah struktur data secara signifikan.

## 5. SOP Backup Database (manual)

Dijalankan **di server production** lewat SSH (bukan dari mesin development):

```bash
# 1. Buat dump terkompresi dengan timestamp di nama file
# --no-tablespaces: hindari error "Access denied ... PROCESS privilege"
# yang muncul di mysqldump versi baru kalau DB user production tidak
# diberi privilege PROCESS global (umum di shared/managed hosting).
mysqldump --no-tablespaces -u <DB_USERNAME> -p <DB_DATABASE> | gzip > ginnva_backup_$(date +%Y%m%d_%H%M%S).sql.gz
# akan diminta password DB_PASSWORD secara interaktif

# 2. (Opsional tapi disarankan) enkripsi sebelum upload ke cloud
gpg -c ginnva_backup_YYYYMMDD_HHMMSS.sql.gz
# menghasilkan file .gpg, akan diminta membuat passphrase
```

Upload ke Google Drive — dua opsi:

**Opsi A — manual lewat browser:** download file dari server (`scp`/SFTP ke komputer lokal), lalu upload manual ke folder Google Drive tim.

```bash
# Dari komputer lokal (bukan di server):
scp user@vps.ptsml.id:/path/ke/ginnva_backup_YYYYMMDD_HHMMSS.sql.gz ./
```

**Opsi B — langsung dari server pakai `rclone`** (kalau `rclone` sudah dikonfigurasi dengan akun Google Drive tim):

```bash
rclone copy ginnva_backup_YYYYMMDD_HHMMSS.sql.gz remote-gdrive:Ginnva-Backups/
```

_Ganti `remote-gdrive` dengan nama remote sesuai hasil `rclone config` yang sudah di-setup sebelumnya. Kalau belum pernah setup `rclone` di server, gunakan Opsi A dulu._

**Jadwal:** lakukan backup manual ini sebelum setiap deploy besar/migrasi berisiko, dan idealnya juga terjadwal rutin (mingguan) lewat cron + `rclone` supaya tidak bergantung pada backup manual saja.

## 6. Git Tagging (rilis)

```bash
# Pastikan working tree bersih & sudah di branch/commit yang benar
git status

git tag v1.0.0-grand-opening
git push origin --tags
```

Untuk melihat semua tag yang sudah dibuat: `git tag -l`. Untuk menghapus tag yang salah (sebelum ada yang lain pull): `git tag -d <tag>` lalu `git push origin :refs/tags/<tag>`.
