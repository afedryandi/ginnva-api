# Ginnva — Runbook Produksi

> Dokumen operasional: URL, daftar kredensial (lokasi, BUKAN nilai), dan
> SOP deploy/rollback/backup. Diperbarui manual — kalau ada perubahan
> infrastruktur, update file ini juga.
>
> Untuk prosedur **insiden besar** (server down total, database hilang,
> kredensial bocor, deploy ulang dari nol server kosong) — lihat
> [DRP.md](DRP.md).

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
| `google-services.json` | **TIDAK** ter-track di repo (di `.gitignore`) — file asli ada di mesin developer/server build, harus disalin manual sebelum `eas build`. Sempat ter-commit sekali (riwayat Git commit `a0ca865`, sudah dihapus dari tracking) — kalau API key Firebase (`ginnva-79b3a`) belum pernah dirotasi sejak audit 2026-09-14, prioritaskan itu, karena riwayat commit lama tidak bisa "dihapus" tanpa rewrite history. |
| `firebase-service-account.json` | **TIDAK** pernah ter-commit ke repo manapun (dikonfirmasi lewat audit 2026-09-14) — tetap simpan hanya di server/password manager tim, jangan taruh di repo. |
| Expo/EAS account | Akun yang dipakai untuk build (`eas build`) — kredensial login tersimpan di akun Expo tim, bukan di file |
| Play Console | Akun Google Play Console untuk submit APK/AAB — kredensial di password manager tim |

### Akses server
| Item | Lokasi |
|---|---|
| SSH key / password VPS (`vps.ptsml.id`) | Password manager tim, akses dibatasi ke yang butuh deploy |
| Akses database langsung (di luar aplikasi) | Sama seperti akses SSH — DB tidak expose ke publik, hanya `127.0.0.1` dari server itu sendiri |

## 3. SOP Deploy

1. Pastikan branch yang akan di-deploy sudah lolos testing lokal (`npx tsc --noEmit` untuk mobile, cek balance kurung untuk PHP kalau tidak ada linter) — dan sejak 2026-09-14 lolos CI (`.github/workflows/ci.yml`, lint+test) kalau push-nya lewat GitHub.
2. **Migrasi baru sejak deploy terakhir — WAJIB direview manual dulu sebelum langkah 3** (audit framework 2026-09-14, "Manajemen migrasi database"): `git log --name-only -- database/migrations` atau `ls -lt database/migrations | head` untuk lihat file baru. Kalau ADA migrasi yang menghapus/mengubah DATA (bukan cuma skema) — cek isinya untuk pola `DB::table(...)->delete()`, `->update()`, `DB::statement(...DELETE/UPDATE...)` — treat sebagai **destruktif**:
   - Jalankan backup manual dulu (§5) walau backup harian otomatis sudah jalan — jangan andalkan jadwal 03:00 semalam kalau migrasi mau dijalankan siang ini.
   - Idealnya uji dulu migrasi itu di database staging berisi salinan data production, bukan langsung di production.
   - Migrasi yang jujur menulis di komentar `down()` bahwa dirinya TIDAK reversibel (mis. penggabungan/penghapusan data) — itu tanda migrasi tersebut butuh perhatian ekstra di langkah ini, bukan sekadar informasi.
3. `git push` ke remote (branch utama atau branch rilis, sesuai konvensi tim).
4. Di server: `git pull`, lalu (untuk `ginnva-api`):
   ```bash
   composer install --no-dev --optimize-autoloader
   php artisan migrate --force
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```
5. Untuk `ginnva-web`: `npm install && npm run build`, lalu restart proses Next.js (PM2/systemd sesuai setup server).
6. Untuk mobile: build lewat EAS (`eas build --platform android --profile production`), submit ke Play Console kalau sudah siap rilis publik.

## 4. SOP Rollback

1. `git log` di server untuk cari commit/tag sebelumnya yang stabil.
2. `git checkout <tag-atau-commit-lama>` (atau `git reset --hard` kalau memang mau buang perubahan — **pastikan sudah backup dulu**, lihat §5).
3. Ulangi langkah cache-clear/build seperti SOP Deploy di atas.
4. Kalau rollback juga perlu mundur migrasi database, jalankan `php artisan migrate:rollback` **dengan hati-hati** — pastikan tidak ada data baru yang akan hilang; lebih aman restore dari backup (§5) kalau migrasi sudah mengubah struktur data secara signifikan.

## 5. SOP Backup Database

**Otomatis sejak 2026-09-14** (audit framework, "Jadwal backup database otomatis"): `App\Console\Commands\BackupDatabase` jalan **harian jam 03:00** lewat Laravel Scheduler (lihat `routes/console.php`) — dump + gzip + upload ke remote `rclone` (kalau `RCLONE_BACKUP_REMOTE` di `.env` sudah diisi, lihat `.env.example`), plus retensi lokal otomatis (`BACKUP_RETENTION_DAYS`, default 14 hari). Syarat: `php artisan schedule:run` harus terpasang di **crontab server** (`* * * * * cd /path/ke/project && php artisan schedule:run >> /dev/null 2>&1`) — cek dulu apakah sudah ada sebelum mengandalkan ini, karena scheduler Laravel TIDAK jalan sendiri tanpa cron ini.

**Uji restore secara berkala** — auditor manapun akan bilang backup yang belum pernah dicoba restore bukan backup yang bisa dipercaya. Jadwalkan simulasi restore minimal 1x/kuartal ke database staging, bukan cuma percaya file `.sql.gz` ada di remote.

**Manual (masih berguna untuk backup ad-hoc sebelum migrasi berisiko / di luar jadwal harian):**

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

## 7. Konsistensi Lintas-Environment (Single Source of Truth)

Ditambahkan 2026-09-14 (audit framework, "Konsistensi lintas-environment") — insiden nyata pernah terjadi: 2 clone lokal `ginnva-mobile` (folder kerja developer vs folder kerja AI assistant) menyimpang berminggu-minggu tanpa disadari, salah satunya sempat merender ulang fitur yang sudah diperbaiki di clone lain (regresi ter-deploy diam-diam). Aturan berikut mencegah pengulangan.

**Aturan #1 — remote Git (`origin`) adalah SATU-SATUNYA sumber kebenaran, bukan file lokal di komputer mana pun.** Kalau ada keraguan "versi mana yang benar", jawabannya selalu: apa pun yang ada di `origin/master` (mobile/web) atau `origin/development` (api) — BUKAN isi folder lokal terbaru yang "kelihatannya" benar.

**Aturan #2 — `ginnva-api` & `ginnva-web`: 1 clone per mesin kerja, selalu `git pull` sebelum mulai kerja.** Repo ini TIDAK punya masalah dual-clone (dikonfirmasi 2026-09-14) — pertahankan begini, jangan buat clone kerja paralel kedua.

**Aturan #3 — `ginnva-mobile` PUNYA 2 clone lokal yang disengaja tetap ada** (folder kerja developer `C:\Users\Antony\ginnva-mobile` + folder kerja sesi AI assistant `Downloads\...\ginnva-mobile`) — kalau situasi ini berubah (mis. AI assistant selalu kerja langsung di 1 folder yang sama dengan developer), sederhanakan jadi 1 clone. Selama masih 2:
- **WAJIB `git status` + `git log -1` di KEDUA folder sebelum mulai sesi kerja baru** — kalau HEAD commit beda, SELESAIKAN penyimpangan itu dulu (pull/merge/diff manual) sebelum menulis kode baru di folder mana pun.
- Perubahan yang belum di-commit (per instruksi standing user: mobile TIDAK auto-commit/push) **WAJIB disalin file-per-file ke folder satunya** segera setelah diedit — jangan menumpuk banyak file berbeda dulu baru disinkronkan belakangan, itu yang menyebabkan insiden awal.
- Kalau ragu file mana yang lebih baru/benar antara 2 folder: `diff` langsung, JANGAN asumsi berdasarkan tanggal file (bisa menyesatkan kalau salah satu di-edit dari sesi lama yang belum ditutup).

## 8. Kebijakan Retensi Data

Ditambahkan 2026-09-14 (audit framework, "Retensi & penghapusan data historis"). Status per jenis data — bukan daftar lengkap final, perbarui kalau ada keputusan baru:

| Jenis Data | Retensi | Status |
|---|---|---|
| Kode OTP (`otp_codes`) | 7 hari setelah expired | **Otomatis** — `App\Console\Commands\PruneExpiredOtpCodes`, jadwal harian jam 04:00 |
| Log aktivitas (`activity_log`, spatie/laravel-activitylog) | Tanpa batas (belum diputuskan) | Sengaja dibiarkan tanpa purge dulu — cakupannya baru diperluas untuk audit trail (lihat item "Jejak audit"), volume masih kecil. Revisit kalau tabel mulai membengkak nyata. |
| Riwayat chat booking (`booking_messages`) | Tanpa batas (belum diputuskan) | Sama alasan di atas — jangan buru-buru hapus riwayat yang mungkin masih relevan untuk sengketa/investigasi. |
| Data transaksi keuangan (`bookings`, `journal_entries`, `receivables`, `payables`) | **Terikat kewajiban pajak** — BELUM diputuskan formal | Terkait langsung ke keputusan model PPN yang masih menunggu atasan (lihat memory `project_penjualan_majoo_blocked_items.md`). Jangan hapus/purge apa pun di kategori ini sebelum ada kepastian. |
| Device token (`device_tokens`) milik akun yang sudah dihapus/tidak aktif | Belum ada kebijakan | **Belum dievaluasi** — kandidat pembersihan berikutnya kalau diminta lanjut. |

**Prinsip umum**: kalau ragu antara hapus atau simpan, DEFAULT ke simpan dulu (terutama data yang menyentuh kewajiban hukum/pajak) — purge yang salah tidak bisa dibatalkan, keputusan menunda purge selalu bisa direvisi nanti.
