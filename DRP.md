# Ginnva — Rencana Disaster Recovery (DRP)

> Dokumen ini melengkapi [RUNBOOK.md](RUNBOOK.md) — RUNBOOK berisi SOP operasional rutin (deploy, rollback, backup manual), dokumen ini berisi **prosedur saat insiden besar** (server down total, kehilangan data, kompromi keamanan). Disusun 2026-09-14 sebagai bagian dari audit framework, item "Rencana Disaster Recovery (DRP)".

## 1. Target Pemulihan

| Metrik | Target | Artinya |
|---|---|---|
| **RTO** (Recovery Time Objective) | **< 4 jam** | Maksimal 4 jam dari insiden terdeteksi sampai sistem kembali bisa dipakai staff/customer. |
| **RPO** (Recovery Point Objective) | **< 24 jam** | Maksimal kehilangan data 1 hari transaksi — sesuai jadwal `backup:database` otomatis harian jam 03:00 (lihat RUNBOOK.md §5). |

**Catatan penting soal realitas target ini:**
- RTO < 4 jam **HANYA tercapai kalau §4 (Simulasi Restore) di bawah sudah pernah benar-benar dipraktikkan** sebelum insiden sungguhan terjadi — tim kecil tanpa on-call 24/7 dan belum pernah uji coba restore realistis TIDAK akan mencapai 4 jam di insiden pertama. Anggap 4 jam sebagai target yang didorong lewat latihan rutin, bukan jaminan otomatis.
- RPO 24 jam bergantung pada backup otomatis (§5 RUNBOOK) benar-benar berjalan setiap hari — kalau `RCLONE_BACKUP_REMOTE` belum diisi di `.env` production, backup masih tersimpan **lokal di server yang sama**, jadi kalau server itu sendiri yang hancur total (bukan cuma database-nya), RPO efektif jadi tidak terbatas (0 backup tersisa). **Prioritas #1 sebelum DRP ini bisa diandalkan: isi `RCLONE_BACKUP_REMOTE`.**

## 2. Skenario Insiden & Prosedur

### 2.1 Server production down total (VPS mati/tidak bisa diakses)

1. Konfirmasi bukan masalah jaringan lokal Anda — cek `https://api.ginnva.id` dan `https://ginnva.id` dari jaringan lain/HP data seluler.
2. Hubungi provider hosting (`vps.ptsml.id`) untuk status server & estimasi pemulihan.
3. Kalau provider memperkirakan pemulihan > 1 jam, mulai siapkan server pengganti (lihat §3 Deploy Ulang dari Nol) secara paralel — jangan tunggu provider dulu baru mulai.
4. Setelah server lama kembali ATAU server baru siap, jalankan verifikasi (§6) sebelum mengumumkan sistem pulih.

### 2.2 Database corrupt / hilang / ter-drop tidak sengaja

1. **JANGAN** jalankan migrasi atau tulis apa pun ke database dulu — cegah kerusakan lebih lanjut.
2. Ambil backup terakhir dari remote (`RCLONE_BACKUP_REMOTE`) atau lokal (`storage/app/backups/` di server) — lihat nama file `ginnva_backup_YYYYMMDD_HHMMSS.sql.gz`.
3. Restore ke database BARU/terpisah dulu (bukan langsung timpa production) untuk verifikasi isinya benar:
   ```bash
   gunzip -c ginnva_backup_YYYYMMDD_HHMMSS.sql.gz | mysql -u <user> -p <database_baru_untuk_verifikasi>
   ```
4. Setelah terverifikasi, restore ke database production sungguhan, lalu jalankan §6.
5. Hitung data yang hilang (dari timestamp backup ke waktu insiden) — informasikan ke staff/store manager yang transaksinya di rentang itu untuk re-input manual kalau perlu.

### 2.3 Kredensial/API key bocor (lihat juga temuan audit "Manajemen kredensial & secret")

1. Rotasi SEGERA kredensial yang bocor (Firebase API key, `.env` production, dll) di layanan terkait.
2. Cek log akses (Sentry, log server) untuk tanda penyalahgunaan sebelum rotasi.
3. Kalau kredensial database yang bocor: ganti `DB_PASSWORD`, update `.env` production, restart layanan (`php artisan config:cache` setelah update `.env`).

### 2.4 Deploy yang merusak production (bug lolos, migrasi gagal)

Ikuti SOP Rollback yang sudah ada di RUNBOOK.md §4 — `git checkout` ke commit/tag stabil sebelumnya + `migrate:rollback` kalau perlu. Backup manual SEBELUM migrasi berisiko (RUNBOOK §5) adalah jaring pengaman utama di skenario ini.

## 3. Deploy Ulang dari Nol (server baru, insiden total)

Urutan minimal untuk membangun ulang `ginnva-api` dari server kosong:

```bash
# 1. Clone repo (butuh akses SSH key/token GitHub tim)
git clone https://github.com/afedryandi/ginnva-api.git
cd ginnva-api

# 2. Install dependency
composer install --no-dev --optimize-autoloader

# 3. Siapkan .env — SALIN dari password manager tim (Bitwarden/1Password
#    vault "Ginnva Production", lihat RUNBOOK.md §2), JANGAN generate
#    APP_KEY baru (akan membuat data terenkripsi lama tidak terbaca).
cp .env.example .env
# ... isi manual dari password manager ...

# 4. Buat database kosong, lalu restore dari backup terakhir
mysql -u <user> -p -e "CREATE DATABASE ginnva CHARACTER SET utf8mb4;"
gunzip -c ginnva_backup_TERBARU.sql.gz | mysql -u <user> -p ginnva

# 5. Cache & optimasi
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 6. Pasang cron scheduler (WAJIB, tanpa ini backup/reminder/notifikasi
#    terjadwal semua tidak jalan) — cek dulu path PHP yang benar di
#    server baru (bisa beda dari /opt/cpanel/ea-php82/... kalau bukan
#    cPanel), lihat RUNBOOK.md §5 untuk contoh format lengkap:
crontab -e
# * * * * * cd /path/ke/project && php artisan schedule:run >> /dev/null 2>&1
# * * * * * cd /path/ke/project && php artisan queue:work --stop-when-empty >> /dev/null 2>&1

# 7. Restore file storage (Firebase credentials, dll) yang TIDAK ada di
#    Git (lihat RUNBOOK.md §2) — firebase-service-account.json ke
#    storage/app/firebase/, dst. Ambil dari password manager/backup file.

# 8. Verifikasi (lihat §6 di bawah) sebelum arahkan DNS/traffic ke
#    server baru ini.
```

Untuk `ginnva-web` (Next.js) — lihat SOP Deploy di RUNBOOK.md §3, prosesnya lebih sederhana (tidak ada database, cuma `npm install && npm run build`).

## 4. Simulasi Restore — Wajib Minimal 1x/Tahun

Backup yang belum pernah dicoba restore bukan backup yang bisa dipercaya. Jadwalkan simulasi ini, catat hasilnya:

1. Pilih 1 file backup terbaru dari remote.
2. Restore ke database **staging/terpisah** (bukan production) mengikuti langkah §2.2.
3. Jalankan aplikasi mengarah ke database hasil restore, cek: bisa login, data booking/customer/keuangan terlihat wajar, tidak ada error di log.
4. Catat **waktu yang dibutuhkan** dari mulai sampai selesai — bandingkan dengan target RTO 4 jam di atas. Kalau jauh lebih lama, itu sinyal RTO perlu direvisi turun (lebih realistis) atau prosesnya perlu disederhanakan.
5. Catat tanggal simulasi & hasilnya di bawah ini (update manual tiap kali dilakukan):

| Tanggal Simulasi | Hasil | Waktu Restore | Catatan |
|---|---|---|---|
| _(belum pernah dilakukan)_ | — | — | — |

## 5. Kontak Person-in-Charge

| Peran | Nama | Kontak | Tanggung Jawab |
|---|---|---|---|
| PIC Utama | Antony | _(isi manual — nomor WA/telepon yang bisa dihubungi 24/7 saat insiden)_ | Koordinasi keseluruhan insiden, keputusan darurat, komunikasi ke tim/stakeholder |
| Hosting/VPS | _(isi manual)_ | `vps.ptsml.id` — provider hosting | Status server, akses SSH tingkat provider |
| Database/kredensial | _(isi manual — siapa yang pegang password manager tim)_ | — | Akses `.env` production, rotasi kredensial |

_Perbarui tabel ini setiap kali ada perubahan tim — dokumen DRP yang kontaknya basi sama buruknya dengan tidak ada dokumen sama sekali._

## 6. Verifikasi Sistem Pulih (checklist sebelum umumkan "sudah normal")

- [ ] `https://api.ginnva.id/admin` bisa diakses & login berhasil
- [ ] `https://ginnva.id` (situs publik) bisa diakses
- [ ] Mobile app staff bisa login & lihat data booking
- [ ] Cron scheduler jalan (`crontab -l` terpasang, cek log tidak ada error jam berikutnya)
- [ ] Data terkini sesuai ekspektasi (tidak ada tabel kosong/data hilang tak terduga)
- [ ] Sentry (`SENTRY_LARAVEL_DSN`) tidak menunjukkan lonjakan error baru
