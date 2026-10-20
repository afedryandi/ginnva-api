# Rencana Deploy Production: Karyawan (Fase 0–5) dan Keuangan (Fase 0–6)

Dibuat 2026-11-09. Melengkapi `RENCANA_DEPLOY_MARKETING_V1.md` (Marketing/Konten Fase 1–5), yang ikut naik dalam rilis yang sama bila belum dijalankan. **Dokumen ini belum dijalankan.** Perintah untuk server cPanel Ginnva, user `ginnva`, folder production `ginnva-api` (perintah tidak diawali `cd`; terminal sudah di folder itu).

## 1. Keadaan dan cakupan

| Hal | Production sekarang (harus dikonfirmasi, bagian 4 butir 1) | Yang akan naik |
|---|---|---|
| Kode API | sampai Booking V2 (rilis 2026-10-15); Marketing mungkin belum | `origin/development` terakhir, commit `2c25b9f` atau lebih baru |
| Migrasi | sampai `2026_10_22_000001` | **20 migrasi baru** (bagian 3): 8 Marketing, 8 Karyawan, 4 Keuangan |
| Jadwal | seperti sekarang | + `leads:remind-stale` 08:00 (Marketing), + `cogs:sync` 05:00 (Keuangan; tidak melakukan apa pun selama flag HPP mati) |
| `.env` | tidak ada yang wajib | semua opsional dan bernilai bawaan aman (bagian 5) |
| Tes | n/a | suite penuh terakhir hijau di dev: 2485 tes (setelah Keuangan Fase 5); Fase 6 diuji per bagian (YearEndClosing 7, FinanceDashboard 4, tutup periode 31 hijau) |
| Mobile | tidak ikut | **tidak ikut**; belum di-commit dan belum pernah dicoba di HP (bagian 9) |
| Web | tidak berubah oleh Karyawan/Keuangan | ikut `RENCANA_DEPLOY_MARKETING_V1.md` bagian 6 bila Marketing belum naik |

## 2. Perubahan perilaku yang terlihat SEGERA setelah deploy

**Karyawan**
1. **Data identitas karyawan dienkripsi di database** (NIK, NPWP, nomor BPJS, nomor rekening). Migrasi mengenkripsi nilai yang ada sekali jalan. Data hanya terbaca dengan `APP_KEY` yang sama: **simpan salinan `APP_KEY` di luar server sebelum deploy** (bagian 4 butir 3). Mundur kode tidak mendekripsi.
2. **Pengajuan izin/sakit/cuti:** form dan aplikasi staf memakai Jenis Izin. Sakit lebih dari batas hari butuh dokumen; cuti tahunan memeriksa kuota; melebihi batas hari ditolak. Daftar jenis izin dibuat otomatis saat menu pertama dibuka (angka bawaan perlu diperiksa HR terhadap peraturan perusahaan).
3. **Payroll:** komponen tambahan (bonus, THR, tunjangan) lewat kategori komponen gaji; potongan keterlambatan memakai aturan potongan absensi (kosong = perilaku lama). Membuat slip gaji tanpa Peraturan Perusahaan aktif menampilkan peringatan (tidak memblokir). Akun THR baru 6145 dipakai komponen baru; slip lama tidak berubah.
4. **Surat Peringatan:** masa berlaku 6 bulan otomatis dan saran tingkat berikutnya (hanya peringatan). Perpanjangan PKWT melebihi 5 tahun diperingatkan.
5. **Penugasan installer:** peringatan (bukan blokir) bila installer cuti/belum punya keahlian layanan/ada pekerjaan ulang; aksi "Saran Installer" di Edit Booking.
6. **Panel admin:** menu baru Dasbor HR, Kebijakan Perusahaan, Daftar Periksa Karyawan, Grade Gaji, Keahlian dan Pelatihan, APD, Pelatihan/Sertifikat, Kejadian K3, Penilaian Kinerja, Laporan Kompetensi Installer. Staf non-akses-penuh perlu dicentang menu ini bila diperlukan (bagian 8).
7. **API staf baru** (`/api/staff/profile`, `leave-types`, `policies`, `safety`, `finance/*`) hanya dipakai build mobile baru; aplikasi lama tidak terpengaruh.

**Keuangan**
1. **Rekening Kas/Bank:** tabel baru, awalnya kosong. Selama tidak ada rekening yang dibuat, semua kas tetap ke akun 1101 seperti sekarang (nilai awal tidak mengubah jurnal apa pun).
2. **Transaksi Keuangan memakai hapus lunak** (data dihapus tersimpan untuk jejak audit). Tampilan daftar tidak berubah.
3. **Rekonsiliasi Sub-Buku** hanya laporan; selisih saat pertama dibuka **wajar** (saldo awal piutang/hutang/persediaan yang diinput tanpa jurnal). Jangan klik "Sinkronkan Persediaan" tanpa akuntan.
4. **HPP otomatis tetap MATI** (`FEATURE_INVENTORY_GL` tidak diisi). Jangan dinyalakan sebelum persediaan disinkronkan dan akuntan setuju (komentar di `config/features.php`).
5. **Persetujuan pengeluaran, kas kecil, hitung kas, anggaran:** batas Store Manager kosong = semua ke direksi seperti sekarang; kas kecil, hitung kas, anggaran tidak aktif sebelum dibuat. Daftar periksa tutup bulan punya **tiga peringatan baru** (selisih hitung kas, sub-buku tidak cocok bila bulan baru lewat, akun melewati anggaran): menutup bulan pertama setelah rilis kemungkinan menampilkan peringatan sub-buku sampai saldo awal dibereskan; peringatan boleh dikonfirmasi.
6. **Tutup Tahun Buku dan Dasbor Keuangan** adalah menu baru. Tutup Tahun hanya dijalankan manual oleh akses penuh; **jangan menutup tahun apa pun sebelum akuntan memeriksa**.
7. **Akun COA baru** (6145 dan akun lain yang belum ada) ditambah lewat seeder yang aman diulang (`firstOrCreate`, tidak menimpa akun yang ada).

## 3. Migrasi yang akan jalan

| Migrasi | Isi | Risiko |
|---|---|---|
| `2026_10_23` s/d `2026_10_27` (8 file) | Marketing/Konten (lihat `RENCANA_DEPLOY_MARKETING_V1.md` bagian 3) | rendah; satu sedang di `case_studies` |
| `2026_10_28_000001_create_attendance_deduction_rules_and_payroll_hour_columns` | tabel aturan potongan + kolom jam di payroll (nullable) | rendah |
| `2026_10_28_000002_encrypt_employee_identity_fields` | **kolom identitas `users` jadi TEXT lalu nilainya dienkripsi** | **tinggi**: mengubah isi data; tidak bisa dibalik otomatis; butuh `APP_KEY` stabil |
| `2026_10_29_000001_create_payroll_items` | tabel komponen gaji (baru) | rendah |
| `2026_10_30_000001_create_leave_types` | tabel + kolom `leave_type_id` di izin (nullable; izin lama diisi saat dipakai) | rendah |
| `2026_10_31_000001_create_employment_lifecycle_tables` | 6 tabel baru + 2 kolom nullable di `users` | rendah |
| `2026_11_01_000001_create_technician_skills_and_trainings` | 2 tabel baru | rendah |
| `2026_11_02_000001_create_safety_and_performance_tables` | 5 tabel baru | rendah |
| `2026_11_03_000001_create_booking_reworks_table` | tabel baru | rendah |
| `2026_11_04_000001_add_soft_deletes_to_finance_transactions` | kolom `deleted_at` nullable | rendah |
| `2026_11_05_000001_create_payment_accounts_and_cash_transfers` | 2 tabel baru + kolom `payment_account_id` nullable di `finance_transactions` dan `booking_down_payments` | rendah |
| `2026_11_07_000001_create_cash_control_tables` | pengaturan, hitung kas, anggaran + kolom kas kecil | rendah |
| `2026_11_09_000001_create_year_end_closings_table` | tabel baru | rendah |

Hanya `encrypt_employee_identity_fields` mengubah data yang sudah ada. Kebijakan RUNBOOK §3 (migrasi destruktif wajib ditinjau + cadangan manual) berlaku untuknya: **ambil cadangan manual tepat sebelum migrasi** (bagian 4 butir 2), jangan hanya mengandalkan cron 03:00. Migrasi aman dijalankan ulang (nilai yang sudah terenkripsi dilewati).

## 4. Sebelum deploy (persiapan)

1. **Konfirmasi baseline production** (hanya membaca):

```bash
git branch --show-current && git status --short && git log --oneline -1 && php artisan migrate:status | grep -i pending
```

   Harapan: cabang `development`, `status` kosong, 20 migrasi `Pending` dimulai dari `2026_10_23_000001`. Bila daftar pending **lebih sedikit** (Marketing sudah naik), lewati bagian Marketing; bila **ada yang tidak dikenal**, **berhenti dan lapor**. Sisa lama dari rilis 2026-10-13 (APP_ENV=local, stash QuotationController, skrip backup, `ginnva_restoretest`) sebaiknya dibereskan sebelum ini; periksa `grep APP_ENV .env` dan `git stash list`.
2. **Cadangan manual segar** (lokal dan Drive), lalu cek berkasnya ada dan tidak nol:

```bash
php artisan backup:database && find ~ storage -maxdepth 6 -name 'ginnva_backup_*.sql.gz' -mtime -1 -printf '%TY-%Tm-%Td %TH:%TM  %10s bytes  %p\n' 2>/dev/null | sort; rclone lsl gdrive-crypt: | sort -k2,3 | tail -n 3
```

3. **Simpan `APP_KEY`** di tempat terpisah dari server (password manager). Tanpa kunci ini, data identitas karyawan tidak bisa dibaca bila `.env` hilang. Cek tanpa menampilkan nilainya:

```bash
grep -c '^APP_KEY=base64:' .env
```

   Harapan: `1`. **Jangan ubah `APP_KEY`** sesudah deploy.
4. **Jumlah data yang akan dienkripsi** (hanya membaca):

```bash
php artisan tinker --execute="echo App\Models\User::whereNotNull('nik')->count().' karyawan punya NIK';"
```

5. Pilih waktu sepi (setelah backup 03:00, sebelum pengingat 08:00), satu orang memantau. Persiapan Marketing (butir 3 sampai 5 di rencananya) tetap berlaku bila Marketing ikut naik.

## 5. Deploy API

```bash
git checkout development && git pull origin development && php artisan migrate --force && php artisan db:seed --class=ChartOfAccountSeeder --force && php artisan optimize:clear && php artisan optimize && php artisan filament:optimize && php artisan queue:restart
```

Seeder Bagan Akun aman diulang (hanya menambah akun yang belum ada, tidak menimpa). Lalu langsung:

```bash
php artisan migrate:status | tail -n 25; php artisan schedule:list | grep -iE "cogs|lead"; php artisan tinker --execute="echo App\Models\ChartOfAccount::whereIn('code',['6145','6520','3200'])->pluck('code')->implode(',');"
```

Harapan: 20 migrasi `Ran`; `cogs:sync` 05:00 dan `leads:remind-stale` 08:00 terdaftar; akun `3200,6145,6520` ada.

`.env` opsional (tidak perlu diubah): `HR_WARNING_VALIDITY_MONTHS` (6), `HR_PKWT_MAX_YEARS` (5), `HR_PROBATION_WARNING_DAYS` (14), `LEAD_RESPONSE_SLA_HOURS` (24), `PROMO_MAX_CAMPAIGNS_PER_WEEK` (2). **Jangan** isi `FEATURE_INVENTORY_GL`.

## 6. Verifikasi setelah deploy (production, tanpa membuat data)

1. Cek enkripsi (hanya membaca, tanpa menampilkan isi):

```bash
php artisan tinker --execute="echo DB::table('users')->whereNotNull('nik')->where('nik','not like','eyJ%')->count().' NIK belum terenkripsi (harus 0)'; echo PHP_EOL.(App\Models\User::whereNotNull('nik')->first()?->nik ? 'NIK terbaca lewat model' : 'tidak ada NIK');"
```

2. Endpoint publik dan login: `/api/stores` 200; login panel akses penuh berhasil.
3. Panel admin, tiap halaman terbuka tanpa error 500: Dasbor HR, Data Karyawan (buka satu karyawan, pastikan NIK/rekening terbaca di formulir), Izin & Cuti (buat form baru tanpa menyimpan), Payroll (buka satu slip), Dasbor Keuangan, Saldo Kas & Bank, Rekonsiliasi Sub-Buku, Tutup Periode (buka Oktober tanpa menutup), Tutup Tahun Buku (halaman terbuka, **jangan tutup**), Anggaran vs Realisasi, Transaksi Keuangan (daftar), satu booking (aksi Saran Installer), plus daftar halaman Marketing di rencananya.
4. Aplikasi mobile lama: buka daftar booking, absen, payroll; harus berjalan seperti sebelumnya (nilai identitas yang terenkripsi tidak boleh tampil sebagai teks acak di profil).
5. Pantau Sentry dan `storage/logs/laravel.log` minimal 1 jam, lalu setelah run 05:00 (`cogs:sync` harus selesai tanpa membuat jurnal), 08:00 (pengingat lead, kontrak, absen) dan 03:00 (backup tetap satu berkas per hari).

## 7. Setelah server stabil (dikerjakan admin di panel)

1. **Hak akses menu** untuk staf terbatas: Dasbor HR, menu Karyawan baru, Dasbor Keuangan, Saldo Kas & Bank, Rekonsiliasi Sub-Buku, Anggaran, Hitung Kas.
2. **HR:** periksa Jenis Izin bawaan terhadap peraturan perusahaan, isi Kebijakan Perusahaan/Peraturan Perusahaan, Grade Gaji, aturan potongan absensi (bila dipakai), kategori komponen gaji.
3. **Keuangan:** buat Rekening Kas/Bank (kas toko, bank per metode bayar), atur batas persetujuan Store Manager dan Kas Kecil bila mau, lalu **minta akuntan memeriksa Rekonsiliasi Sub-Buku** dan menentukan saldo awal persediaan.
4. **Tutup bulan** pertama setelah rilis: ikuti daftar periksa; konfirmasi peringatan hanya setelah dibaca.
5. **Tutup tahun buku** hanya setelah akuntan menyetujui angka tahun itu.

## 8. Mobile (terpisah, setelah server stabil)

Perubahan mobile (profil, izin berjenis, kebijakan, K3, navbar bawah, Keuangan: pengeluaran, persetujuan, hitung kas) belum di-commit dan belum pernah dijalankan di HP. Urutan: developer meng-commit, build preview menunjuk **server dev**, uji HP (`docs/CHECKLIST_UJI_HP.md`), rilis bertahap setelah server production stabil.

## 9. Jalur mundur

- **Mundur kode saja:** semua migrasi hanya menambah (kecuali enkripsi), jadi kode lama mengabaikan kolom/tabel baru. **Penting:** kode lama membaca kolom identitas sebagai teks biasa; setelah enkripsi, NIK/NPWP/BPJS/rekening akan tampil sebagai teks acak di kode lama. Jadi mundur kode **setelah** migrasi enkripsi berarti kolom itu tidak terbaca sampai kode baru kembali (data tidak hilang selama `APP_KEY` sama).

```bash
git checkout <commit-production-sebelumnya> && php artisan optimize:clear && php artisan optimize && php artisan filament:optimize && php artisan queue:restart
```

  Catat commit production dengan `git log --oneline -1` (bagian 4 butir 1). Jalur ini belum pernah dilatih.
- **Pulihkan database dari cadangan manual bagian 4 butir 2** hanya bila data rusak; `migrate:rollback` tidak dipakai (RUNBOOK §3). Perubahan setelah cadangan hilang.
- Jurnal yang sudah dibuat (mis. penutupan tahun) dibatalkan lewat fitur batalnya, bukan dihapus.

## 10. Risiko dan hal yang masih terbuka

- **Enkripsi identitas** adalah satu-satunya langkah tidak mudah dibalik; bergantung pada `APP_KEY` dan cadangan segar.
- **Tampilan panel dan layar mobile belum pernah dilihat langsung** (hanya tes otomatis): jadwalkan uji manual bagian 6 butir 3 oleh orang yang paham alurnya.
- **Perilaku dengan data nyata belum teruji**: terutama Rekonsiliasi Sub-Buku (selisih saldo awal), daftar periksa tutup bulan (peringatan baru), dan tarif/angka bawaan Jenis Izin (belum diverifikasi ke peraturan terbaru).
- **Tes penuh di dev** dijalankan per kelompok huruf (`artisan test` penuh mati diam-diam); suite penuh belum diulang setelah Fase 6. Jalankan sebelum deploy bila ingin kepastian: lihat catatan di memori tim.
- **Belum ada:** Paket Akuntan/ekspor (menunggu jawaban akuntan eksternal), pajak/PKP (Fase 3 ditunda), HPP otomatis (sengaja mati).
- **Memori server production:** MySQL pernah restart sendiri dan swap hampir penuh; migrasi enkripsi memproses karyawan per 200 baris sehingga ringan.
