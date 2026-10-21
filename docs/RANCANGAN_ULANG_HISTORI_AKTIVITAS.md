# Rancangan Ulang Menu Histori Aktivitas Ginnva

Dibuat 2026-10-21 atas permintaan pemilik: rancang ulang menu Histori Aktivitas (data, sistem, alur kerja, fungsi, fitur, menu, UI/UX) sesuai standar, tanpa bug, mudah dipakai, dengan riset internet. **Dokumen ini hanya audit, riset, dan rancangan. Belum ada kode yang diubah.** Pola sama dengan `RANCANGAN_ULANG_NOTIFIKASI.md`, `RANCANGAN_ULANG_MASTER_DATA.md`, dan seterusnya.

## Keputusan pemilik yang sudah ada

Semua delapan keputusan di bagian 6 sudah dijawab (2026-10-21):

- **Keputusan 1 dan 2:** peristiwa keamanan akun dicatat; alamat IP dan ringkasan perangkat hanya untuk staf, pelanggan hanya asal aksi (app).
- **Keputusan 3: isi pribadi di log TIDAK dianonimkan saat akun pelanggan dihapus ("biarkan").** Konsekuensinya dicatat apa adanya: nama, telepon, dan alamat pelanggan bertahan di Histori Aktivitas setelah akunnya dihapus, sehingga penghapusan akun belum sepenuhnya menghapus data pribadi. Fase 4 tidak mencakup anonimisasi log; bisa dibuka lagi kapan saja bila kebijakan penghapusan akun atau syarat Google Play mempersoalkannya.
- **Keputusan 4: retensi tanpa batas** (kondisi sekarang). Fase 4 hanya menambah indeks tanggal; tidak ada penghapusan otomatis. Perlu ditinjau lagi bila tabel mulai membengkak (RUNBOOK §).
- **Keputusan 5:** akses lihat dan ekspor didelegasikan lewat Akses Menu (mis. Spv Finance dan Spv IT).
- **Keputusan 6:** ekspor terfilter disediakan, ekspornya dicatat.
- **Keputusan 7:** cap hash harian tidak dibuat sekarang (fase 6 tidak dikerjakan).
- **Keputusan 8:** model uang dan penting yang belum tercatat ditinjau dan dicatat.

## 1. Ringkasan

Histori Aktivitas (Sistem, `ActivityResource`) adalah daftar baca-saja di atas tabel `activity_log` (paket spatie/laravel-activitylog). Sebanyak 109 dari 149 model mencatat perubahan datanya otomatis (siapa mengubah apa, nilai lama dan baru), ditambah catatan manual untuk peran, user, ekspor laporan, penutupan periode, dan beberapa aksi booking. Dasarnya kuat: tabel hanya bisa dibaca dari panel, `password` tidak pernah dicatat, 106 dari 109 model hanya mencatat kolom yang berubah, dan ada tes yang menjaga kelengkapan peta label modul.

Masalahnya ada di empat tempat:

1. **Data pribadi pelanggan tidak ikut terhapus.** Saat akun pelanggan dihapus, nama, telepon, dan alamat dianonimkan di tabel pelanggan, tetapi `activity_log` tetap menyimpan nilai lamanya (bahkan proses anonimisasi itu sendiri menulis nama lama ke log). Kebijakan penghapusan akun kita (dan syarat Google Play) tidak tercapai sepenuhnya.
2. **Peristiwa keamanan tidak tercatat.** Login berhasil dan gagal, logout, ganti dan reset password staf maupun pelanggan tidak meninggalkan jejak. Tidak ada juga alamat IP, perangkat, atau asal aksi (panel, mobile, perintah terjadwal).
3. **Sulit dipakai untuk menjawab pertanyaan sehari-hari.** Riwayat per rekaman hanya ada di dua layar (Bahan Baku, Barang Habis Pakai); untuk Booking, Garansi, atau Pelanggan staf harus mencari di daftar global. Nilai ditampilkan dengan nama kolom mentah dan angka mentah (`store_id: 2`), 57 model memakai deskripsi bawaan ("updated") yang tidak informatif, dan objek tidak punya tautan.
4. **Belum ada kebijakan pengelolaan.** Tidak ada retensi (sengaja tanpa batas sampai sekarang), tidak ada indeks tanggal, akses terkunci ke akun full-access tanpa bisa didelegasikan, tidak ada ekspor untuk auditor, dan beberapa model uang (baris penjualan, pembayaran pelunasan) belum tercatat.

Usulan: tujuh fase (0 sampai 6) yang bisa dirilis sendiri-sendiri. **Delapan keputusan pemilik** (bagian 6).

## 2. Riset

Catatan kejujuran: OWASP dan Spatie dibaca dari sumber resminya; teks PP 71/2019 hanya tersedia sebagai PDF hasil pindai yang tidak bisa dibaca di sini, jadi **bunyi pasal jejak audit di PP 71/2019 tidak terverifikasi**. Rujukan hukum di bawah perlu dicocokkan oleh pemilik atau penasihat hukum sebelum dijadikan dasar keputusan.

**Apa yang dicatat (OWASP Logging Cheat Sheet).** Untuk tiap peristiwa: kapan (waktu), di mana (aplikasi, host, titik masuk), siapa (identitas pengguna dan alamat sumber), apa (jenis peristiwa, objek, hasil, alasan). Peristiwa yang disebut wajib dipertimbangkan: **keberhasilan dan kegagalan autentikasi**, kegagalan otorisasi, **perubahan pengguna dan hak akses**, tindakan admin, **impor dan ekspor data**, pembuatan dan penghapusan objek sistem, serta persetujuan atau izin hukum. Sheet yang sama memperingatkan agar tidak mencatat segalanya karena kebisingan menyembunyikan masalah ([OWASP Logging Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html)).

**Apa yang tidak dicatat.** Kata sandi, token, ID sesi, rahasia, dan **data pribadi atau data bank yang sensitif**; bila identitas tidak diperlukan, dianjurkan pseudonim. Data yang disimpan tidak boleh dihapus sebelum masa simpan wajibnya habis dan tidak boleh disimpan melebihi masa itu (OWASP sama). Sheet itu tidak menyebut angka masa simpan; angkanya ditentukan kewajiban hukum, regulasi, dan kontrak.

**Integritas.** Disarankan deteksi pengubahan atau penghapusan, pembatasan dan pencatatan akses ke log, akun basis data khusus dengan hak tulis terbatas untuk log, serta pemantauan bila pencatatan berhenti (OWASP sama). Pendekatan yang lebih berat seperti rantai hash atau penyimpanan tak bisa diubah banyak dibahas di artikel pihak ketiga; itu mahal untuk usaha seukuran Ginnva dan tidak dianggap wajib di sini.

**Retensi dokumen perusahaan di Indonesia.** Menurut ringkasan Hukumonline dan DDTC, UU 8/1997 tentang Dokumen Perusahaan mewajibkan catatan, bukti pembukuan, dan data pendukung administrasi keuangan disimpan **10 tahun** sejak akhir tahun buku; dokumen lain mengikuti jadwal retensi yang ditetapkan pimpinan perusahaan ([Hukumonline](https://www.hukumonline.com/klinik/a/jangka-waktu-penyimpanan-dokumen-perusahaan-lt646efc4036ca5), [DDTC](https://news.ddtc.co.id/berita/nasional/1805429/alasan-dokumen-dasar-pembukuan-wajib-disimpan-selama-10-tahun), [teks UU 8/1997](https://peraturan.go.id/files/uu8-1997.pdf)). Apakah log aktivitas termasuk "data pendukung" adalah penafsiran yang perlu pemilik putuskan; angka 10 tahun dipakai sebagai batas atas yang aman untuk modul keuangan.

**Spatie activitylog.** Perintah `activitylog:clean` menghapus catatan lebih tua dari `delete_records_older_than_days` (dapat dibatasi per `log_name` dengan `--days`), **tidak dijadwalkan otomatis**, dan setelahnya MySQL bisa dirapikan dengan `OPTIMIZE TABLE` pada jam sepi ([dokumentasi Spatie](https://spatie.be/docs/laravel-activitylog/v4/basic-usage/cleaning-up-the-log)).

**Batas riset.** Kewajiban jejak audit di PP 71/2019 dan UU Pelindungan Data Pribadi (hak penghapusan data) tidak terverifikasi teksnya di sini. Tidak ada standar resmi Indonesia khusus log aktivitas bengkel. Ukuran dan pertumbuhan tabel di production belum diukur (terakhir diketahui 1.122 baris pada 2026-10-13).

## 3. Audit sistem sekarang

Semua butir **diverifikasi di kode** kecuali ditandai.

### 3.1 Yang ada

| Bagian | Isi |
|---|---|
| Menu | Sistem, Histori Aktivitas (`ActivityResource`), baca-saja, daftar + halaman detail |
| Sumber data | 109 model memakai `LogsActivity` (106 dengan `logOnlyDirty`, 108 dengan `dontSubmitEmptyLogs`), 52 punya deskripsi khusus; catatan manual untuk Role, User, Booking (2 titik), Periode Akuntansi, Transaksi Keuangan, dan `report_export` (ekspor laporan, 30-an halaman) |
| Tampilan | Waktu, pelaku (atau "Sistem"), modul (±105 label Indonesia), aksi (dibuat/diubah/dihapus), objek ("Model #id"), deskripsi; filter modul, pelaku, tanggal; detail memuat nilai lama dan baru, alasan |
| Akses | Hanya akun full-access (`isFullAccess()`), tidak ada buat/ubah/hapus |
| Tes | `ActivityResourceTest` (akses, filter, detail, kelengkapan peta label) |
| Pemakai lain | Laporan Void, Utilisasi Zona/Bay, dan riwayat tahap booking membaca `activity_log` sebagai sumber data |

### 3.2 Celah

| # | Temuan | Bukti | Dampak |
|---|---|---|---|
| H1 | **Data pribadi pelanggan tetap ada di log setelah akun dihapus.** Model Customer mencatat `name`, `phone_number`, `address`; deskripsi memuat nama ("Pelanggan \"Budi\" terdaftar"). `CustomerAccountDeletionService` menganonimkan tabel pelanggan, kuotasi, dan inquiry tetapi **tidak menyentuh `activity_log`**; pembaruan anonimisasi itu sendiri menulis nama dan telepon lama ke log sebagai nilai "lama". Model lain yang memuat data pribadi (Quotation, ProductInquiry, Partner) belum diperiksa. | `Customer::getActivitylogOptions`, `CustomerAccountDeletionService` | Kebijakan penghapusan akun (dan syarat Google Play) tidak sepenuhnya terpenuhi; PII bertahan tanpa batas waktu |
| H2 | **Peristiwa keamanan tidak tercatat.** Login berhasil atau gagal, logout, ganti dan reset password (staf maupun pelanggan, panel maupun mobile) tidak memanggil `activity()` di mana pun. | `Api\Staff\AuthController`, `Api\Customer\AuthController`, pencarian `activity(` | Tidak ada jejak serangan tebak sandi atau akses akun orang lain; tidak bisa menjawab "kapan terakhir akun X login" |
| H3 | **Tidak ada konteks permintaan.** Tidak tersimpan alamat IP, perangkat, atau asal aksi (panel, mobile, perintah terjadwal). Pelaku kosong ditampilkan "Sistem", sehingga aksi terjadwal dan aksi yang pelakunya gagal terbaca tidak bisa dibedakan. Pelanggan yang bertindak lewat API (guard `customer`) tercatat sebagai pelaku bertipe Customer, tetapi filter Pelaku hanya memuat User. | tidak ada `tap`/konfigurasi activitylog; filter `causer_id` | Penyelidikan insiden sulit; aksi pelanggan tidak bisa disaring |
| H4 | **Model uang dan penting yang belum tercatat.** Dari 149 model, 40 tidak memakai `LogsActivity`. Sebagian memang buku besar tambah-saja (mutasi stok), tetapi perlu ditinjau satu per satu: `BookingSaleLine`, `BookingSettlementPayment`, `PointTransaction`, `BookingHandover`, `BookingQcResult`, `WarrantyOwnershipTransfer`, `AssetTransfer`, `NotificationCampaign`, `NotificationTemplate`, `CustomerVehicle`, `QuotationItem`, `InvoiceItem`. | daftar model tanpa `LogsActivity` | Perubahan pada baris penjualan dan pembayaran pelunasan (uang) tidak punya jejak "siapa mengubah" |
| H5 | **Tidak ada riwayat per rekaman.** Hanya Bahan Baku dan Barang Habis Pakai punya tab riwayat. Untuk Booking, Garansi, Pelanggan, Invoice, dan lainnya staf harus membuka daftar global dan mencari lewat deskripsi. Kolom Objek menampilkan "Booking #12" tanpa tautan dan tidak bisa disaring per objek. | `ActivityLogRelationManager` hanya 2 pemakai | Pertanyaan paling umum ("apa yang terjadi pada booking ini?") sulit dijawab |
| H6 | **Keterbacaan rendah.** Nilai lama/baru memakai nama kolom dan angka mentah (`store_id: 2`, `status: in_progress`); 57 model memakai deskripsi bawaan ("updated"); catatan manual tanpa jenis aksi tampil "—". | `stringifyValues`, 57 model tanpa `setDescriptionForEvent` | Pemilik sulit membaca tanpa bantuan developer |
| H7 | **Waktu mungkin tampil dalam UTC.** Kolom Waktu memakai zona aplikasi (`APP_TIMEZONE`, bawaan `UTC`, `.env.example` juga `UTC`). Nilai di production **belum diverifikasi**; bila UTC, setiap waktu tampil 7 jam lebih awal dari WIB. | `config/app.php`, `dateTime()` tanpa zona | Salah baca waktu kejadian dalam investigasi |
| H8 | **Akses terkunci pada full-access.** Tidak ada kotak Akses Menu untuk Histori Aktivitas; Spv Finance dan Spv IT (di bawah CFO) tidak bisa diberi akses lihat saja tanpa menjadi full-access. Akses ke log itu sendiri tidak tercatat. | `canViewAny() = isFullAccess()` | Auditor internal tidak bisa bekerja tanpa hak berlebih |
| H9 | **Tidak ada retensi dan indeks tanggal.** Retensi tanpa batas (keputusan sementara, RUNBOOK §), `activitylog:clean` tidak dijadwalkan, tabel hanya berindeks `log_name`, objek, dan pelaku (tidak ada `created_at`) padahal daftar diurutkan dan difilter menurut tanggal. Volume terakhir diketahui kecil (1.122 baris) tetapi cakupan model terus bertambah. | migrasi `create_activity_log_table`, tidak ada `config/activitylog.php` | Lambat dan membengkak seiring waktu; tidak ada jawaban untuk "berapa lama log disimpan" |
| H10 | **Tidak ada ekspor atau ringkasan.** Auditor tidak bisa mengunduh log terfilter; tidak ada tampilan "perubahan berisiko" (hak akses, harga, periode dibuka ulang, jurnal dibalik). | `ActivityResource` | Pemeriksaan berkala memakan waktu |
| H11 | **Integritas bergantung pada basis data.** Resource baca-saja, tetapi baris bisa diubah langsung lewat basis data; tidak ada deteksi pengubahan. Satu akun basis data dipakai bersama (hosting bersama). | arsitektur | Risiko rendah di skala Ginnva; backup harian menjadi satu-satunya pembanding |

### 3.3 Yang sudah baik (dipertahankan)
- Baca-saja dan khusus full-access; kata sandi tidak pernah dicatat; hak akses (`menu_access`, `menu_permissions`) dicatat.
- Hanya perubahan nyata yang dicatat (`logOnlyDirty`, tanpa catatan kosong).
- Ekspor laporan dicatat (siapa, laporan apa) di sekitar 30 halaman.
- Peta label modul dijaga tes; deskripsi khusus yang rapi untuk 52 model.
- Log yang sama dipakai laporan operasional (Void, Utilisasi Bay), jadi mencatat sudah punya nilai nyata.

## 4. Rancangan target

### 4.1 Prinsip
1. **Jawab "siapa, apa, kapan, dari mana"** untuk setiap perubahan penting, termasuk keamanan akun.
2. **Catat secukupnya**: perubahan data bisnis dan peristiwa keamanan, bukan semua aktivitas (hindari kebisingan).
3. **Tidak menyimpan data pribadi lebih lama dari perlunya**: jejak "siapa mengubah apa" tetap, isi pribadi dianonimkan saat subjeknya dihapus.
4. **Riwayat ada di tempat kerja**: tab Riwayat di setiap rekaman utama, bukan hanya menu global.
5. **Terbaca oleh pemilik**: label Indonesia, nilai yang dikenali (nama toko, bukan `2`), tautan ke rekaman.
6. **Dikelola**: retensi tertulis per jenis, ekspor, akses yang bisa didelegasikan, dan pemeriksaan berkala.

### 4.2 Model data (usulan, seminimal mungkin)
- Tetap memakai `activity_log`. Konteks (IP, perangkat, asal) disimpan di `properties.context` tanpa kolom baru. Peristiwa keamanan memakai `log_name = 'auth'`.
- Indeks baru `activity_log (created_at)` dan `(log_name, created_at)`.
- `config/activitylog.php` dengan retensi per kategori (ditentukan keputusan 4) dan penjadwalan `activitylog:clean` per `log_name`.
- Tidak ada tabel baru untuk log.

### 4.3 Alur target
1. Model dan aksi mencatat seperti sekarang; sebuah hook tunggal menambah `properties.context` (IP, ringkasan perangkat, asal: panel / mobile staf / mobile pelanggan / terjadwal).
2. Peristiwa autentikasi (berhasil, gagal, logout, ganti dan reset sandi) dicatat lewat satu listener.
3. Saat akun pelanggan dihapus, `CustomerAccountDeletionService` juga menganonimkan `activity_log` milik pelanggan itu (deskripsi dan nilai pribadi diganti "Pelanggan Terhapus"/titik-titik), jejak perubahannya tetap.
4. Tiap rekaman utama punya tab **Riwayat** (relation manager generik yang sudah ada polanya).
5. Perintah terjadwal membersihkan log menurut retensi.
6. Admin mengekspor log terfilter (ekspor itu sendiri dicatat) dan melihat ringkasan "Perubahan Berisiko".

### 4.4 Fitur dan menu
- **Histori Aktivitas**: waktu dalam WIB; kolom Objek berupa tautan ke rekaman; filter objek (jenis dan nomor) dan asal; nilai lama/baru dengan label kolom Indonesia dan nilai yang dikenali; pencarian pelaku mencakup pelanggan.
- **Riwayat** per rekaman: Booking, Garansi, Pelanggan, Invoice, Penawaran, Produk, Toko, Karyawan, dan lainnya yang dipilih fase 1.
- **Keamanan Akun** (filter atau tab): login berhasil dan gagal, perangkat, terakhir login per akun.
- **Perubahan Berisiko**: daftar siap pakai (hak akses, peran, harga, periode akuntansi, jurnal dibalik, penghapusan).
- **Ekspor** CSV/Excel terfilter.
- Akses lewat kotak **Akses Menu** (hanya lihat), bukan hanya full-access.

### 4.5 Aturan UI/UX
Bahasa sederhana ("Antony mengubah harga SKU H70 dari 1.200.000 menjadi 1.350.000"), bukan nama kolom; nilai uang diformat rupiah; tanggal WIB; tidak ada data pribadi di baris yang sudah dianonimkan.

### 4.6 Keandalan dan keamanan
Pencatatan konteks dan keamanan tidak boleh menggagalkan aksi utama (try/catch + `report()`); kata sandi, token, dan nilai OTP tidak pernah dicatat; akses ke menu log dibatasi dan penggunaan ekspor dicatat; tes otomatis untuk anonimisasi, retensi, dan pencatatan keamanan.

### 4.7 Ukuran keberhasilan
- Nol nilai pribadi pelanggan di `activity_log` setelah akun dihapus.
- 100% login dan kegagalan login tercatat dengan IP.
- Setiap rekaman utama punya tab Riwayat.
- Waktu di layar sama dengan jam WIB.
- Pemilik bisa menjawab "siapa mengubah X, kapan, dari mana" dalam satu layar.

## 5. Peta jalan

| Fase | Isi | Hasil |
|---|---|---|
| **0. Keterbacaan** | **Dikerjakan (2026-10-21), belum diuji di server.** Kelas `App\Support\ActivityPresenter` (hanya tampilan, data log tidak diubah): waktu selalu WIB di daftar, detail, dan filter tanggal (batas hari WIB, apa pun zona aplikasi); label kolom Indonesia (161 kolom terpetakan dari 323 kolom unik yang dicatat, yang paling sering dipakai; sisanya diubah jadi huruf judul tanpa akhiran `_id`); nilai terbaca (nama toko/pengguna/pelanggan/produk/rekening alih-alih angka, rupiah untuk kolom uang, ya/tidak, status Indonesia, tanggal WIB atau tanpa jam untuk kolom tanggal); deskripsi bawaan paket ("updated") menjadi kalimat utuh ("Toko #2 diubah (Status, Toko)") tanpa mengubah 57 model; kolom Objek memakai nama resource ("Toko #2") dan menjadi tautan bila rekamannya masih ada dan pengguna boleh membukanya; filter Objek (jenis + nomor); Aksi "Dicatat" untuk catatan manual; keterangan pelaku "Pelanggan" bila bukan staf. Log lama untuk model yang sudah dihapus dari kode (mis. RollScrapPool) tidak membuat halaman error (objek tidak dimuat lewat relasi morph). Tanpa skema. Tes `ActivityReadabilityTest`. | Log terbaca pemilik |
| **1. Riwayat per rekaman** | **Dikerjakan (2026-10-21), belum diuji di server.** `ActivityHistoryRelationManager` generik (waktu WIB, pelaku, aksi, kalimat utuh, detail sama dengan halaman Histori Aktivitas) dipasang sebagai tab **Riwayat** di 22 resource: Booking, Garansi, Pelanggan, Invoice, Penawaran, Produk (SKU), Toko, User, SPK, Partner, Kendaraan, Supplier, Utang, Piutang, Transaksi Keuangan, Jurnal, Teknisi, Reward, Aset Tetap, Permohonan Pembelian, Stok Opname, Rekening Kas/Bank. Tab hanya muncul bagi yang boleh membuka Histori Aktivitas (isinya sama sensitifnya, mis. gaji pada User); setelah Fase 5 itu otomatis mengikuti Akses Menu. Tab lama Bahan Baku dan Barang Habis Pakai tidak diubah (tetap terlihat oleh yang boleh membuka rekamannya). Hanya perubahan pada rekaman itu sendiri yang tampil, bukan anak-anaknya (mis. baris penjualan di Booking). Resource tanpa halaman lihat/ubah (Payroll) belum diberi tab. Tes `ActivityHistoryTabTest`. | Pertanyaan harian terjawab di tempatnya |
| **2. Keamanan akun dan konteks** | **Dikerjakan (2026-10-21), belum diuji di server.** `AccountSecurityLog` mencatat `log_name = 'auth'` ("Keamanan Akun"): masuk, gagal masuk (dengan alasan: sandi salah, akun tidak ditemukan, akun nonaktif, kode salah), keluar, kata sandi diganti/direset. Jalur: aplikasi staf (login, logout, ganti sandi, reset sandi), aplikasi pelanggan (verifikasi OTP berhasil/gagal, logout), panel admin (event bawaan Laravel Login/Failed/Logout/PasswordReset pada guard `web`), dan perubahan sandi staf oleh admin di halaman Edit User. Percobaan gagal ke akun yang dikenali tampil juga di tab Riwayat akun itu. `ActivityContext` (hook pada model Activity) menambah `properties.context` ke SETIAP catatan baru: asal (Panel Admin, Aplikasi Staf, Aplikasi Pelanggan, API, Web, Sistem/konsol) dan, **hanya bila pelakunya staf (atau percobaan masuk gagal ke akun staf), alamat IP dan ringkasan perangkat**; pelanggan hanya asal aksi (keputusan 2). Kata sandi, token, dan kode OTP tidak pernah dicatat; email pelanggan pada kode OTP salah disamarkan (`b***@domain`). Menu: kolom dan filter **Asal**, filter **Pelaku (Pelanggan)**, detail memuat Asal, IP, Perangkat; label aksi Masuk/Gagal Masuk/Keluar/Sandi Diganti/Sandi Direset. Pencatatan tidak pernah menggagalkan login (try/catch + report). Catatan lama tidak punya konteks (tampil "—"). Tes `AccountSecurityLogTest`. **Belum diuji dari klik nyata:** hook di halaman Edit User (ubah sandi oleh admin) hanya diuji lewat pemanggilan langsung, dan event login panel hanya lewat event buatan. | Jejak insiden |
| **3. Cakupan model uang** | **Dikerjakan (2026-10-21), belum diuji di server.** Ke-30 model yang belum tercatat ditinjau satu per satu (cara mengubah/menghapusnya dicek di kode). **Dicatat:** (a) rincian penjualan booking, (b) pembayaran pelunasan booking, (c) item invoice, masing-masing **satu entri per penggantian pada rekaman induknya** (tab Riwayat Booking/Invoice) berisi baris sebelum dan sesudah serta total; penyebabnya layanan-layanannya memakai hapus massal lalu buat ulang, yang tidak memicu pencatatan model, dan pencatatan per baris hanya menghasilkan deretan "dibuat". Penggantian dengan isi yang sama persis tidak dicatat (layanan dipanggil tiap simpan). Modul baru: Rincian Penjualan Booking, Pembayaran Pelunasan Booking, Item Invoice. (d) `NotificationCampaign` dan (e) `NotificationTemplate` dicatat otomatis lewat LogsActivity (siapa membuat, membatalkan, mengubah isi). **Tidak dicatat dengan alasan:** mutasi stok, poin, gulungan, aset, dan buku besar tambah-saja lain (`InventoryMovement`, `RawMaterialMovement`, `ConsumableItemMovement`, `RawMaterialBatch`, `PointTransaction`, `ScrollCodeUsage`, `StockOpnameItem`); catatan perpindahan yang menyimpan pelaku dan waktunya sendiri (`AssetTransfer`, `ScrollCodeTransfer`, `WarrantyOwnershipTransfer`, `EmployeeCareerHistory`, `BookingHandover`, `BookingQcResult(+Item)`, `WarrantyMaintenanceVisit`, `PolicyAcknowledgment`, `AccountingPeriodEvent`, `BankStatementImportBatch`); tanpa jalur ubah/hapus di kode (`SpkDamageMark`, `SpkChecklistItem`, `BookingIntakePhoto`); tanpa nilai uang dan berisiko berisik (`BookingFilmProduct`, `QuotationItem`); data pribadi tanpa manfaat audit (`CustomerVehicle`); log pengiriman (`NotificationDelivery`), pesan chat, token, OTP, dan preferensi widget. Tes `LineReplacementLogTest`. | Perubahan uang punya pelaku |
| **4. Indeks dan pengelolaan tabel** | **Dikerjakan (2026-10-21), belum diuji di server.** Migrasi `2026_11_18_000001` menambah indeks `activity_log_created_at_index` dan `activity_log_log_name_created_at_index` (idempotent: dilewati bila sudah ada; isi tabel tidak disentuh; down() menghapusnya). Perintah baca-saja `php artisan activity:size-report` (jumlah catatan, terlama/terbaru, ukuran MB dari information_schema, rata-rata per hari, perkiraan setahun, modul terbanyak dengan porsi, jumlah per bulan 12 bulan terakhir). Ringkasan satu baris di atas daftar Histori Aktivitas ("N catatan sejak tanggal, disimpan tanpa batas waktu", di-cache 5 menit). Tes `ActivityLogReportTest`. Rencana awal: Indeks `created_at` dan `(log_name, created_at)`. **Dipersempit oleh keputusan 3 dan 4:** tidak ada anonimisasi log dan tidak ada penghapusan otomatis (retensi tanpa batas), jadi `config/activitylog.php` dan `activitylog:clean` tidak dibuat; yang ditambah hanya perintah baca-saja yang melaporkan ukuran tabel per modul supaya keputusan retensi bisa ditinjau dengan angka | Daftar tetap cepat; ukuran terpantau |
| **5. Akses dan laporan** | Kotak Akses Menu (hanya lihat dan ekspor); ekspor terfilter (tercatat); tampilan Perubahan Berisiko | Auditor bisa bekerja |
| ~~6. Integritas~~ | Tidak dikerjakan (keputusan 7) | , |

### 5.1 Kenapa bertahap
Fase 4 menghapus isi data permanen, jadi dijalankan setelah fase 0 sampai 3 terbukti dan dengan cadangan; fase 2 menyentuh jalur login yang kritikal sehingga perlu bendera fitur dan tes. Suite penuh dan rencana rilis tertulis sebelum rilis, seperti pengerjaan ulang lainnya.

## 6. Keputusan yang dibutuhkan (dengan rekomendasi)

| # | Pertanyaan | Mengapa penting | Rekomendasi saya |
|---|---|---|---|
| 1 | Catat login berhasil, login gagal, logout, ganti dan reset sandi? | Dasar fase 2 | Ya |
| 2 | Simpan alamat IP dan ringkasan perangkat pada catatan? | IP termasuk data pribadi; dasar fase 2 | Ya untuk staf; untuk pelanggan hanya asal aksi (app), tanpa IP |
| 3 | Anonimkan isi pribadi di log saat akun pelanggan dihapus? | Dasar fase 4; menghapus isi permanen | Ya (jejak "siapa mengubah apa" tetap, nama dan telepon diganti) |
| 4 | Masa simpan log? | Dasar fase 4, UU 8/1997 untuk data keuangan | Keuangan dan penjualan 10 tahun; garansi 5 tahun; keamanan akun 1 tahun; modul lain 3 tahun. Perlu disahkan pemilik |
| 5 | Beri akses lihat Histori Aktivitas ke Spv Finance dan Spv IT lewat Akses Menu? | Dasar fase 5 | Ya, hanya lihat dan ekspor |
| 6 | Sediakan ekspor log terfilter? | Dasar fase 5 | Ya, dan ekspornya sendiri dicatat |
| 7 | Cap hash harian untuk mendeteksi pengubahan log? | Dasar fase 6 | Tidak sekarang |
| 8 | Catat model uang yang belum tercatat (baris penjualan, pembayaran pelunasan, kampanye notifikasi)? | Dasar fase 3 | Ya; mutasi stok dan poin tetap tanpa duplikasi karena sudah buku besar tambah-saja |

## 7. Batasan riset

- Isi pasal jejak audit PP 71/2019 dan ketentuan UU PDP tentang penghapusan data tidak terverifikasi; rujukan retensi dan penghapusan perlu disahkan pemilik atau penasihat hukum.
- Zona waktu production, ukuran tabel, dan laju pertumbuhan belum diukur (lihat langkah verifikasi di bawah).
- Penilaian model uang di H4 berasal dari daftar nama; tiap model akan ditinjau sebelum diputuskan.
- Perilaku guard JWT pada pencatatan pelaku di API tidak diuji di perangkat nyata (pelaku staf lewat API diperkirakan benar karena guard `api` dipakai sebagai guard bawaan, tetapi belum dibuktikan dengan tes).

### Verifikasi yang perlu dijalankan di production (hanya baca)
```bash
php artisan tinker --execute="echo config('app.timezone');"
php artisan tinker --execute="echo \Spatie\Activitylog\Models\Activity::count();"
```

## 8. Sumber
- [OWASP Logging Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html)
- [Spatie laravel-activitylog: Cleaning up the log](https://spatie.be/docs/laravel-activitylog/v4/basic-usage/cleaning-up-the-log)
- [Hukumonline: Jangka waktu penyimpanan dokumen perusahaan](https://www.hukumonline.com/klinik/a/jangka-waktu-penyimpanan-dokumen-perusahaan-lt646efc4036ca5)
- [DDTC: Alasan dokumen dasar pembukuan wajib disimpan 10 tahun](https://news.ddtc.co.id/berita/nasional/1805429/alasan-dokumen-dasar-pembukuan-wajib-disimpan-selama-10-tahun)
- [UU 8/1997 tentang Dokumen Perusahaan](https://peraturan.go.id/files/uu8-1997.pdf)
- [PP 71/2019 (PDF pindai, teks tidak terbaca di sini)](https://jdih.kemenkeu.go.id/FullText/2019/71TAHUN2019PP.pdf)
