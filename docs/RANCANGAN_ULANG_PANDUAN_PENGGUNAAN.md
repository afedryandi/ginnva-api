# Rancangan Ulang Menu Panduan Penggunaan Ginnva

Dibuat 2026-10-22 atas permintaan pemilik: rancang ulang menu Panduan Penggunaan supaya benar-benar bisa dipakai staf untuk menjalankan sistem ini pada empat lini layanan (PPF, kaca film, detailing, premium wash), sesuai alur kerja perusahaan, tanpa error, dan mudah dipakai, dengan riset internet. **Dokumen ini hanya audit, riset, dan rancangan. Belum ada kode yang diubah.** Pola sama dengan `RANCANGAN_ULANG_NOTIFIKASI.md` dan `RANCANGAN_ULANG_HISTORI_AKTIVITAS.md`.

## Keputusan pemilik yang sudah ada (2026-10-22)

- **Pembaca: staf saja** (panel admin dan aplikasi staf). Panduan untuk pelanggan dan pembaruan asisten AI pelanggan **di luar cakupan**.
- **Isi: cara memakai sistem.** Panduan menjelaskan apa yang dilakukan staf di layar untuk tiap layanan dan tugas. Standar teknis lapangan (batas kegelapan kaca film, angka perawatan pasca-layanan, teknik pemasangan) **di luar cakupan** panduan ini. Draf awal dokumen ini sempat melebar ke sana; sudah dikoreksi.
- **Pengelolaan isi: campuran.** Cara memakai layar tetap di kode (teruji terhadap sistem); catatan khusus perusahaan (aturan toko, batas, kebijakan) dapat diubah pemilik di panel.
- **Penanggung jawab isi: belum ditentukan** (kolom penanggung jawab boleh kosong).
- **Tur pengenalan tetap dimatikan.** Tidak ada tombol Panduan kontekstual di halaman kerja. **Teks saja**, tanpa gambar atau tangkapan layar. **Tanpa umpan balik** "membantu?".

## 1. Ringkasan

Panduan Penggunaan sekarang adalah satu halaman di panel admin (`Panduan`, dropdown Lainnya dan menu avatar) dengan **7 panduan statis** di kode (`GuideCatalog`): Booking, Garansi dan Maintenance, Reward dan Voucher, Approval, Stok, Absensi dan Jadwal, serta Istilah. Fondasinya baik: bahasa staf, pencarian instan di browser, daftar disaring menurut akses menu, dan aturan intinya cocok dengan kode (chat booking tanpa installer, toleransi maintenance 30 hari terverifikasi).

Masalahnya: **panduan belum menjadi alat kerja untuk sistem yang sekarang.**

1. **Cakupan sempit.** Panduan merujuk 18 dari sekitar 155 menu panel (±12%).
2. **Isi Booking tertinggal dari rework.** Hanya menyebut "Kaca Film / PPF"; tidak menyebut Detailing dan Premium Wash, kapasitas berat/ringan dan per jam, foto penerimaan, QC, serah terima, tandai tidak datang, rincian harga, pelunasan, dan DP.
3. **Tidak ada panduan per layanan.** Empat layanan punya jalur berbeda di sistem (misalnya Detailing dan Premium Wash tidak punya tahap per produk), tetapi tidak ada panduan yang menjelaskan "untuk layanan ini, langkah di sistem seperti apa".
4. **Aplikasi staf tanpa bantuan**, dan installer tidak punya akses panel sama sekali, jadi pengguna paling lapangan tidak terjangkau.
5. **Tidak dikelola**: tanpa versi, tanggal tinjau, tautan dari halaman kerja, umpan balik, atau pengaman agar isi ikut berubah saat sistem berubah.

Usulan: lima fase (0 sampai 4), tiap fase bisa dirilis sendiri. Semua keputusan sudah dijawab (bagian 6).

## 2. Riset

Catatan kejujuran: riset di sini hanya untuk **cara menyusun panduan yang baik**. Isi alur layanan diambil dari sistem itu sendiri dan dokumen rancangan Booking yang sudah ada (riset industri untuk alur penerimaan, QC, dan serah terima sudah dibahas di `RANCANGAN_ULANG_BOOKING.md`).

**Jenis dokumen (Diátaxis).** Dokumentasi yang baik memisahkan empat kebutuhan: *tutorial* (belajar dengan dipandu), *panduan cara* (menyelesaikan satu tugas), *referensi* (fakta untuk dicari), dan *penjelasan* (alasan di balik aturan). Panduan cara berangkat dari tujuan pembaca, berisi urutan tindakan tanpa pengajaran atau penjelasan di tengahnya, berjudul jelas, dan mengikuti urutan kerja pembaca ([Diátaxis](https://diataxis.fr/), [panduan cara](https://diataxis.fr/how-to-guides/)). Panduan Ginnva sekarang mencampur keempatnya dalam satu kartu.

**Bantuan dalam aplikasi.** Heuristik NN/g nomor 10: bantuan harus mudah dicari, berfokus pada tugas pengguna, singkat, dan berisi langkah konkret; sebaiknya tampil **dalam konteks** pada saat dibutuhkan, bukan tutorial panjang yang harus dihafal ([NN/g](https://www.nngroup.com/articles/ten-usability-heuristics/)). Pencarian dan keringkasan sudah dipenuhi panduan sekarang; konteks belum.

**Pengendalian dokumen kerja.** Praktik ISO 9001 untuk informasi terdokumentasi, seperti dirangkum satu blog vendor (teks standar tidak dibaca): versi, pemeriksa, tanggal berlaku, satu lokasi terkendali, dan jejak siapa mengubah apa ([Trupeer](https://www.trupeer.ai/blog/sop-version-control)). Dalam sistem ini jejak perubahan sudah tersedia lewat Histori Aktivitas.

## 3. Audit sistem sekarang

Semua butir **diverifikasi di kode** kecuali ditandai.

### 3.1 Yang ada

| Bagian | Isi |
|---|---|
| Panel admin | Halaman Panduan (slug `panduan`), dropdown Lainnya dan menu avatar; 7 panduan di `GuideCatalog`; disaring menurut akses menu (akses penuh melihat semua); pencarian di browser (Alpine) |
| Bantuan lain di panel | Tooltip badge status booking, sumber booking, status garansi (`Tooltips`); sekitar 314 teks bantuan di form; tur pengenalan 5 langkah (`AdminTour`, **dimatikan** lewat `FEATURE_ADMIN_TOUR=false`) |
| Aplikasi staf | 20 area (absensi, booking, SPK, stok, lead, penawaran, slip gaji, K3, kebijakan, dan lainnya); **tanpa layar bantuan** |
| Tes | `PanduanPageTest` (struktur katalog, akses, pencarian, tautan menu) |

### 3.2 Celah

| # | Temuan | Bukti | Dampak |
|---|---|---|---|
| P1 | **Cakupan sempit.** 18 dari sekitar 155 menu dirujuk panduan. Hampir tanpa panduan: Laporan (±35 menu), Keuangan (Jurnal & Akun, Piutang & Utang, periode, tutup tahun), Karyawan (cuti, kontrak, penggajian, K3), Master Data, Notifikasi, Konten Beranda, Inquiry & Kemitraan, Penjualan, Histori Aktivitas. | skrip pencacah menu vs `GuideCatalog` | Staf baru tidak punya panduan untuk sebagian besar pekerjaannya |
| P2 | **Panduan Booking tertinggal dari rework.** Tidak menyebut Detailing dan Premium Wash, kapasitas dua kategori dan per jam, jam kedatangan, foto penerimaan, QC per layanan, serah terima, tandai tidak datang, kebijakan batal, pengingat, rincian harga, pelunasan, DP. Tahap pengerjaan di kode hanya ada untuk kaca film dan PPF; Detailing dan Premium Wash langsung ke Quality Check dan Serah Terima, tanpa dijelaskan. | `GuideCatalog` vs `BookingMessage::PRODUCT_STAGES`, `RANCANGAN_ULANG_BOOKING.md` | Staf mengikuti panduan yang tidak cocok dengan layar |
| P3 | **Tidak ada panduan per layanan.** Empat layanan ada di sistem tetapi tidak ada panduan yang memetakan tugas staf per layanan (apa yang diisi, kapan, oleh siapa) dari pesanan sampai serah terima dan garansi. | tidak ada entri | Alur layanan bergantung pada ingatan perorangan |
| P4 | **Aplikasi staf tanpa bantuan, dan installer tidak terjangkau.** Installer tidak punya akses panel web (`NO_PANEL_ROLES`), jadi tidak pernah melihat Panduan; aplikasi staf tidak punya layar atau tautan bantuan. | `NO_PANEL_ROLES`, `app/staff` | Pengguna paling lapangan tanpa panduan |
| P5 | **Bantuan tidak kontekstual.** Tidak ada tautan "Panduan" dari halaman kerja; hanya dari dropdown Lainnya dan menu avatar. | `Panduan`, resource | Pengguna harus keluar dari tugasnya untuk mencari bantuan |
| P6 | **Isi tertanam di kode, tanpa pengendalian dokumen.** Mengubah satu aturan toko berarti rilis; tidak ada versi, tanggal tinjau, atau penanggung jawab. | struktur panduan | Aturan perusahaan tidak bisa diperbarui pemiliknya |
| P7 | **Tes hanya struktural.** Tidak ada pengaman agar panduan ikut diperbarui saat alur berubah (rework Booking tidak memicunya). Komentar di kode menyebut `GuideCatalogTest`, yang tidak ada (berkasnya `PanduanPageTest`). Delapan belas kelas menu yang dirujuk semuanya ada (diperiksa manual, tidak ada tes). | `PanduanPageTest` | Panduan basi tanpa peringatan |
| P8 | **Tur pengenalan mati dan satu untuk semua peran.** Lima langkah umum; tidak ada tur per peran. | `FEATURE_ADMIN_TOUR`, `AdminTour::steps` | Orientasi hari pertama bergantung pada orang lain |
| P9 | **Tidak ada umpan balik.** Tidak diketahui panduan mana yang dibuka atau membingungkan. | tidak ada tabel atau aksi | Perbaikan isi tanpa dasar |
| P10 | **Format hanya langkah dan tips teks.** Tidak ada prasyarat, peran pelaksana, hasil yang diharapkan, gambar atau tangkapan layar, atau pemisahan tutorial / panduan cara / referensi; pencarian sudah baik. | `panduan.blade.php` | Sulit dipakai untuk tugas yang rumit |

### 3.3 Yang sudah baik (dipertahankan)
- Bahasa staf, ringkas, dengan **tebal** untuk nama tombol dan status.
- Pencarian di browser tanpa permintaan server (menghindari 419 yang pernah terjadi).
- Penyaringan menurut akses menu; tautan ke Panduan di dua tempat; tooltip status.
- Aturan inti panduan Booking dan Garansi cocok dengan kode.

## 4. Rancangan target

### 4.1 Prinsip
1. **Berorientasi tugas dan peran**: "Saya ingin menerima unit PPF di sistem", bukan "Modul Booking".
2. **Satu sumber kebenaran** untuk panel dan aplikasi staf (API yang sama).
3. **Mudah ditemukan**: satu halaman Panduan yang bisa dicari, dikelompokkan per layanan, peran, dan jenis dokumen, dengan tautan di dropdown Lainnya dan menu avatar (sudah ada). Tombol kontekstual di halaman kerja tidak dibuat (keputusan pemilik).
4. **Jenis dokumen terpisah**: tutorial hari pertama, panduan cara, referensi (status, istilah, angka sistem), penjelasan singkat bila perlu.
5. **Terkendali dan tidak basi**: versi, tanggal tinjau, dan tes yang gagal bila ada menu tanpa panduan atau rujukan menu yang tidak valid.
6. **Hanya cara memakai sistem**: tidak memuat standar teknis lapangan atau angka yang tidak berasal dari sistem.

### 4.2 Model data (usulan)
- Panduan sistem (cara memakai layar) tetap di `GuideCatalog` (kode), dengan tambahan field: `service` (ppf, kaca_film, detailing, premium_wash, atau kosong), `roles`, `type` (tutorial, panduan_cara, referensi), `prerequisites`, `expected_result`, `version`, `reviewed_at`.
- `guide_notes` (bagian "Catatan perusahaan" yang diubah pemilik di panel): `guide_slug`, `body`, `updated_by`, `updated_at`; tercatat otomatis di Histori Aktivitas; ditampilkan di bawah panduan terkait.

### 4.3 Alur target
1. Staf membuka Panduan dari menu atau dari aplikasi staf; daftar disaring menurut peran dan akses menu.
2. Panel dan aplikasi staf (`GET /api/staff/guides`) menampilkan isi yang sama; halaman Panduan dikelompokkan per **layanan**, **peran**, dan **jenis dokumen**.
3. Pemilik menambah "Catatan perusahaan" di bawah panduan tertentu lewat panel (tanpa rilis).
4. Setiap panduan menampilkan versi dan tanggal tinjau; panduan yang lewat masa tinjau diberi penanda di halaman Panduan.
5. Tes menjaga agar setiap menu terdaftar punya panduan atau pengecualian tertulis.

### 4.4 Isi yang diperlukan (inti permintaan)
**A. Satu panduan "cara memakai sistem" per layanan**, masing-masing: prasyarat, peran pelaksana, langkah di sistem dari pesanan sampai serah terima dan garansi, hasil yang diharapkan, dan yang harus dicatat:
- **PPF**: konfirmasi booking (tanggal mulai multi-hari, kapasitas berat), penugasan installer, foto penerimaan, SPK, tahap pengerjaan (Proses Cuci, Detailing, Pemasangan PPF) lewat chat booking, Quality Check dengan daftar periksa layanan, serah terima (petunjuk perawatan, tanda tangan basah bila diwajibkan), Proses Referral (rincian harga, pembayaran, DP), penerbitan garansi, jadwal maintenance.
- **Kaca Film**: sama, dengan tahap Pembersihan, Pemanasan, Instalasi Kaca Film, dan kombinasi dengan PPF dalam satu booking (dua jalur tahap).
- **Detailing** dan **Premium Wash**: jam kedatangan wajib, kapasitas ringan per jam, harga paket tetap, tanpa tahap per produk (langsung Quality Check dan Serah Terima), foto penerimaan dan QC per layanan, serah terima, Proses Referral.

**B. Panduan per peran**: tutorial hari pertama dan tugas harian untuk Store Manager, Admin/Kasir toko, Installer Leader, Installer (aplikasi staf), Finance, HR, Direksi.

**C. Panduan per area yang belum ada**: Penjualan (rincian harga, pelunasan, DP, Proses Referral), Keuangan (jurnal, periode, tutup tahun, rekonsiliasi), Karyawan (cuti, kontrak, penggajian, K3), Laporan (membaca dan mengekspor), Master Data, Kirim Notifikasi, Konten Beranda, Lead dan Kemitraan, Histori Aktivitas dan hak akses.

**D. Referensi**: status dan tahap per layanan, istilah, angka sistem (kapasitas, toleransi 30 hari, SLA, kebijakan batal), matriks hak akses per peran.

### 4.5 Aturan UI/UX
Judul berbentuk tugas; setiap panduan menampilkan peran pelaksana, prasyarat, langkah bernomor, hasil yang diharapkan, dan versi serta tanggal tinjau di kaki; **teks saja**, tanpa gambar; di aplikasi staf daftar disaring menurut peran, bisa dicari, dan teks singkat untuk layar kecil.

### 4.6 Keandalan dan keamanan
Panduan tidak memuat data pribadi atau kredensial; API panduan staf hanya untuk pengguna terautentikasi dan disaring menurut peran dan akses menu; tes memastikan setiap menu terdaftar punya panduan atau pengecualian eksplisit (pola `NotificationCatalogTest`), setiap rujukan menu valid, dan setiap panduan punya versi dan tanggal tinjau.

### 4.7 Ukuran keberhasilan
- 100% menu terdaftar punya panduan atau pengecualian tertulis.
- Empat panduan layanan terbit dan cocok dengan layar (diperiksa lewat tes dan uji klik).
- Installer dan leader dapat membuka panduan dari aplikasi staf.
- Nol panduan melewati masa tinjau tanpa penanda.

## 5. Peta jalan

| Fase | Isi | Hasil |
|---|---|---|
| **0. Akurasi dan pengaman** | **Dikerjakan (2026-10-22), belum diuji di server.** Delapan panduan (tujuh lama diperbarui + **Penjualan** baru) ditulis ulang dari perilaku sistem yang diverifikasi di kode. Booking: empat layanan, konfirmasi dan penugasan, foto penerimaan, SPK, tahap per layanan (Detailing dan Premium Wash tanpa tahap, langsung Quality Check), daftar periksa QC, serah terima, tandai tidak datang, kapasitas berat per hari dan ringan per jam, pengingat; Penjualan: Proses Referral (rincian harga, nominal, pembayaran, DP, voucher, referral) dan persetujuan. **Kesalahan nyata yang diperbaiki:** panduan Absensi menyuruh staf mengatur shift di menu Jadwal Kerja yang disembunyikan dan tidak dipakai Ginnva; tombol disebut "Clock In/Out" padahal di aplikasi "Absen Masuk/Absen Keluar"; nama menu stok dan persetujuan disesuaikan dengan label sebenarnya (Produk PPF/WF, Persetujuan Transaksi, Persetujuan Pengeluaran). Angka dan label diambil dari kode (tahap dari `BookingMessage`, layanan dari `BookingSaleLine`, slot jam dan batas respons dari konfigurasi, toleransi maintenance dari `GRACE_DAYS`, batas nego dari `Booking`). Perintah baca-saja `php artisan guides:coverage [--list]` melaporkan cakupan menu. Tes `GuideAccuracyTest`: rujukan menu valid dan tidak tersembunyi, istilah tebal harus ada sebagai label di kode (label khusus aplikasi staf di `GuideCatalog::MOBILE_LABELS`), isi mengikuti konstanta kode. **Tes cakupan penuh (setiap menu punya panduan atau pengecualian) ditunda ke fase 2**, setelah laporan memberi daftar dasar. Tanpa skema | Panduan sesuai layar |
| **1. Panduan per layanan** | **Dikerjakan (2026-10-22), belum diuji di server.** Empat panduan baru: **Layanan PPF**, **Layanan Kaca Film**, **Layanan Detailing**, **Layanan Premium Wash**. Layanan berat (PPF, Kaca Film): konfirmasi dengan lama pengerjaan, catat gulungan film lewat Produk PPF/WF → Catat Pemakaian (dan aturan Wajib Lacak Roll), tahap pengerjaan dari kode, daftar periksa QC bawaan, tanda tangan basah wajib, pembuatan Garansi (PPF: posisi pemasangan, kode gulungan kedua, kuota dan interval maintenance; Kaca Film: kode gulungan per posisi kaca), review. Layanan ringan (Detailing, Premium Wash): jam wajib dan slot dari konfigurasi, tanpa tahap pengerjaan (langsung Quality Check), tanda tangan basah opsional, tanpa garansi atau maintenance di sistem. Masa simpan foto (24 bulan bawaan) diambil dari konfigurasi. Tes keselarasan ditambahkan ke `GuideAccuracyTest`. **Perbaikan teks menyesatkan yang ditemukan saat verifikasi:** pesan SPK dan teks bantuan di Daftar Produk, Garansi, dan Kode Gulungan menyuruh staf ke "menu Kode Gulungan" untuk Catat Pemakaian / Tandai Habis, padahal kedua aksi itu hanya ada di menu Produk PPF/WF; keempatnya dikoreksi. | Alur layanan terdokumentasi |
| **2. Panduan per peran dan area** | Tutorial hari pertama per peran dan panduan area yang belum tercakup; halaman Panduan dikelompokkan per layanan, peran, dan jenis dokumen. **Dikerjakan per kelompok menu, satu komit dan satu putaran tes per kelompok.** **Kelompok Booking selesai (2026-10-22, belum diuji di server):** tujuh panduan baru untuk delapan menu: SPK, Quotation (Lead), Invoice, Teknisi, Tanggal Tidak Tersedia, Review Toko, dan Pengaturan booking (Daftar Periksa QC dan Teks Aplikasi). Seluruh istilah tebal (126) cocok dengan label di kode. Kelompok Booking dikunci di `GuideAccuracyTest::COMPLETED_CLUSTERS`: menu baru di dalamnya tanpa panduan membuat tes gagal. **Kelompok Keuangan selesai (2026-10-22, belum diuji di server):** tujuh panduan baru untuk 26 menu (Transaksi Keuangan, Kas dan bank, Jurnal Umum dan Bagan Akun, Hutang/Piutang/Tagihan Rutin, Tutup periode dan tutup tahun buku, Anggaran, Laporan keuangan) dan perbaikan panduan Approval: batas nominal Store Manager di Pengaturan Keuangan ternyata membuat tulisan lama "semua nominal wajib direksi" hanya benar bila batas itu kosong. 250 istilah tebal cocok dengan label di kode; kelompok Keuangan ikut dikunci tes (30 dari 30 menu tercakup). **Belum:** Karyawan (25), Laporan (26), Marketing/Konten (14), Inventori (7), Notifikasi (7), Master Data (4), Analisa Laporan (4), Produk (3), Promosi (3), Pelanggan (2), Penjualan (1), Sistem (1); panduan per peran | Cakupan menu lengkap |
| **3. Panduan di aplikasi staf** | `GET /api/staff/guides` dan layar Panduan (disaring peran, pencarian) di aplikasi staf, dari tile di beranda staf | Installer dan leader terjangkau |
| **4. Pengelolaan** | Catatan perusahaan yang bisa diubah pemilik di panel (tercatat di Histori Aktivitas), versi dan tanggal tinjau tiap panduan, penanda panduan yang lewat masa tinjau | Panduan tidak basi |

Tidak dikerjakan atas keputusan pemilik: tombol Panduan kontekstual, tur pengenalan per peran (bendera tetap mati), gambar atau tangkapan layar, dan umpan balik "membantu?".

### 5.1 Kenapa bertahap
Fase 0 memperbaiki yang salah sekarang tanpa risiko; fase 1 dan 2 menghasilkan isi (bagian yang paling banyak pekerjaannya); fase 3 menyentuh aplikasi staf yang harus dirilis lewat toko aplikasi dan belum pernah diuji di HP.

## 6. Keputusan

Semua sudah dijawab pemilik (2026-10-22), lihat bagian "Keputusan pemilik" di atas. Satu hal yang masih terbuka dan tidak menghalangi pembangunan: **apa saja yang diisi pemilik di "Catatan perusahaan"** (rekomendasi: teks tambahan di bawah panduan seperti aturan toko, batas, dan kebijakan, bukan langkah di sistem); bisa diputuskan saat fase 4.

## 7. Batasan riset

- Riset hanya mencakup cara menyusun dokumentasi; teks standar ISO 9001 tidak dibaca (ringkasan dari satu blog vendor).
- Alur layanan diambil dari sistem dan `RANCANGAN_ULANG_BOOKING.md`; tidak ada pembanding Indonesia khusus panduan sistem bengkel PPF.
- Aplikasi staf belum pernah dijalankan di HP untuk layar baru mana pun; layar bantuan nanti perlu uji nyata.
- Kecocokan isi panduan dengan sistem hanya diperiksa untuk butir yang disebut di bagian 3.2; panduan Garansi, Reward, Approval, Stok, dan Absensi belum diperiksa baris per baris.
- **Di luar cakupan** (dibahas di draf awal lalu dikeluarkan atas arahan pemilik): standar teknis lapangan seperti batas kegelapan kaca film, angka perawatan pasca-layanan, dan panduan atau asisten AI untuk pelanggan.

## 8. Sumber
- [Diátaxis](https://diataxis.fr/) dan [panduan cara](https://diataxis.fr/how-to-guides/)
- [NN/g: 10 usability heuristics (bantuan dan dokumentasi)](https://www.nngroup.com/articles/ten-usability-heuristics/)
- [Trupeer: version control SOP (ringkasan ISO 9001)](https://www.trupeer.ai/blog/sop-version-control)
