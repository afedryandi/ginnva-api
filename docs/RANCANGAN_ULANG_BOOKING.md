# Rancangan Ulang Sistem Booking Ginnva

Dibuat 2026-10-14. Status: **usulan untuk diputuskan, belum ada yang dibangun.**
Cakupan: PPF, Kaca Film, Detailing, Premium Wash; data, alur kerja, fungsi, fitur, menu, UI/UX di API, aplikasi mobile (pelanggan dan staf), dan panel admin.

## Status dan keputusan (diperbarui 2026-10-16)

**Keputusan pemilik:**
1. Model kapasitas **dua kategori** (berat: PPF/kaca film; ringan: detailing/premium wash). Disetujui.
2. **Detailing dan Premium Wash berharga tetap untuk semua ukuran mobil** (tidak per ukuran). PPF dan kaca film tetap memakai matriks harga per ukuran. Keduanya dibuka di aplikasi pelanggan dengan paket tetap.
3. **Ginnva tidak menjual coating.** Semua rujukan coating di dokumen ini berasal dari riset industri umum dan **tidak berlaku** untuk Ginnva (abaikan prasyarat "koreksi cat" dan coating).
4. **Deposit tidak wajib, persentase belum ditentukan, dan tidak hangus** bila pelanggan tidak datang. Bagian deposit di Fase 4 dan keputusan nomor 5 dan 6 pada bagian 6 **ditunda/tidak dikerjakan**; kebijakan batal tetap perlu ditampilkan.
5. Urutan pengerjaan: mulai dari **Fase 0**, lanjut Fase 1.
6. **Harga TIDAK ditampilkan di aplikasi pelanggan** (keputusan 2026-10-15, menggantikan keputusan Fase 1 tentang estimasi harga). Harga dikonfirmasi toko. API katalog layanan tidak mengirim harga (`price: null`) kecuali `BOOKING_SHOW_PRICES=true`. Data ukuran mobil tetap dikumpulkan untuk keperluan toko.
7. **Fase 2 (2026-10-15):** jam kedatangan **wajib** untuk layanan ringan; kapasitas ringan dihitung **per jam**; booking campuran berat+ringan dihitung **berat**; menu Layanan Booking di panel admin dibuat sekarang.

**Status pengerjaan:**

| Fase | Status |
|---|---|
| 0. Fondasi | **Sisi API selesai dan di-push (`b4e8bb3`), menunggu uji di server dev.** Isi: versi minimum aplikasi (`X-App-Version`, 426, `GET /api/app/config`, variabel `APP_MIN_VERSION_ANDROID/IOS`), kunci idempotensi pada `POST /api/customer/bookings` (header `Idempotency-Key`, tabel `idempotency_keys`, pembersihan harian), dan tes kontrak multipart. Sisi aplikasi (header versi, layar "Perbarui Aplikasi", kunci idempotensi di booking) sudah ditulis di working copy mobile, **belum di-commit** (developer yang meng-commit). |
| 1. Kendaraan dan katalog | **Sisi API dan panel admin selesai dan di-push (`44bfdf2`, `5e50189`, `a02e994`), tes dev hijau (16 tes Fase 1).** Isi: tabel `customer_vehicles` dan endpoint `/api/customer/vehicles`, katalog `GET /api/vehicles/catalog` dan `GET /api/booking/services` (harga tetap Detailing/Premium Wash, "mulai dari" PPF/kaca film per ukuran), booking v2 (`services` + `customer_vehicle_id`, payload lama tetap diterima), salinan kendaraan di booking, SPK terisi dari booking, kolom plat dan bagian Kendaraan di panel admin. Sisi aplikasi (layar Kendaraan Saya, booking 5 langkah, kendaraan di aplikasi staf dan Booking Saya) sudah ditulis di working copy mobile, **belum di-commit dan belum diuji di HP**. **Syarat rilis:** API Fase 1 (migrasi) harus naik ke production SEBELUM build mobile ini dirilis; harga Detailing dan Premium Wash harus diisi di panel admin (sekarang Rp 0 dari migrasi). |
| 2. Kapasitas dan jadwal | **Selesai di sisi API dan panel admin (di-push sampai `8e1a9ca`), tes dev hijau (13 tes baru + regresi booking).** Isi: kapasitas dua kategori. Layanan **berat** (PPF, kaca film) memakai kapasitas harian toko; layanan **ringan** (Detailing, Premium Wash) memakai slot jam (09-11, 11-13, 13-15, 15-17) dengan kapasitas per jam (`stores.light_capacity_per_slot`, bawaan 2, diatur di form Toko). Booking ringan tidak memakan kapasitas harian berat; booking campuran dihitung berat; booking tanpa penanda produk dihitung berat. Jam **wajib** untuk pelanggan pada layanan ringan. Endpoint publik `GET /api/stores/{id}/light-slots?date=` dan `/light-full-dates`. Aturan berlaku di booking pelanggan, konfirmasi staf, pindah jadwal, dan form Create/Edit Booking di panel admin. Menu **Layanan Booking** (Operasional) mengatur nama, deskripsi, durasi, dan kategori berat/ringan tiap layanan. Sisi aplikasi (langkah Jadwal: pemilih jam wajib dan tanggal penuh khusus ringan) sudah ditulis di working copy mobile, **belum di-commit dan belum diuji di HP**. **Syarat rilis:** migrasi `2026_10_16_000001` dan `000002` harus naik ke production SEBELUM build mobile dirilis. **Tambahan Fase 2 (selesai, tes dev hijau):** `capacity-overview` dan `capacity-preview` staf menambah field `light_slots` (kapasitas harian berat tidak berubah); Kalender Kapasitas diberi keterangan "angka = kapasitas harian layanan berat"; peringatan ketersediaan installer (cuti disetujui, libur jadwal) saat staf mengonfirmasi booking berat, dikirim di field `warnings` dan tidak memblokir, plus `installers {total, available}` di `capacity-overview` (keputusan pemilik: peringatan saja); `GET /api/stores/{id}/full-dates?duration_days=N` membuat tanggal mulai PPF multi-hari nonaktif bila hari lanjutannya penuh (konfirmasi staf sudah sejak awal memeriksa seluruh rentang hari kerja). **Ditunda:** antrean tunggu (keputusan pemilik 2026-10-16: lewati dulu). **Belum:** layar kapasitas aplikasi staf belum menampilkan `light_slots`, `installers`, dan `warnings`; peringatan installer belum ada di form Filament. Hanya booking berstatus `confirmed` yang mengunci slot; `pending` tidak. |
| 3. Penerimaan, QC, serah terima | **3B (serah terima) selesai di sisi API (`985921e`, tes dev hijau, 8 tes):** `GET/POST /api/staff/bookings/{id}/handover`, tabel `booking_handovers` (migrasi `2026_10_18_000001`), petunjuk perawatan wajib, **tanda tangan basah** wajib untuk layanan berat dan opsional untuk layanan ringan (sistem hanya mencatat bahwa tanda tangan di kertas sudah diterima), foto akhir dan catatan opsional, sekali per booking; bila `BOOKING_QC_REQUIRED` menyala kendaraan harus lulus QC dulu; gerbang penyelesaian `BOOKING_HANDOVER_REQUIRED` (bawaan **mati**, nyalakan bersama `BOOKING_QC_REQUIRED` pada langkah 4 urutan rilis). Layar serah terima mobile (tombol kunci di detail booking staf) ada di working copy, belum di-commit dan belum diuji di HP. **3A (Quality Check) selesai di sisi API dan panel admin (`1bce863`, `e37ba62`), tes dev hijau (11 tes).** Isi: daftar periksa QC per layanan (menu Operasional → Daftar Periksa QC, bawaan 5 butir per layanan, hanya akses penuh yang mengubah); `GET/POST /api/staff/bookings/{id}/qc`; pemeriksa Leader, Store Manager, atau akses penuh dan **bukan pengerja unit itu**; semua butir harus dijawab dan butir tidak lulus wajib ada catatan; QC lulus mengisi `bookings.ready_at` ("siap diambil", bukan status baru) dan mengirim notifikasi ke pelanggan; hasil tersimpan sebagai riwayat (pemeriksaan ulang diperbolehkan). Gerbang penyelesaian booking: `BOOKING_QC_REQUIRED` (bawaan **mati**). Sisi aplikasi (layar Quality Check di detail booking staf, label "Siap Diambil" di Booking Saya) sudah ditulis di working copy mobile, **belum di-commit dan belum diuji di HP**. **Keputusan pemilik:** QC oleh Leader/Store Manager bukan pengerja; foto penerimaan 4 sudut plus foto kerusakan tambahan; tanda tangan serah terima **basah di kertas** (bukan digital). **Belum:** foto penerimaan terpandu (4 sudut plus kerusakan), `no_show`, tahap kerja untuk layanan ringan, pengingat registrasi garansi setelah serah terima. **Urutan rilis QC:** (1) deploy API + migrasi `2026_10_17_000001`; (2) build mobile baru dirilis dan beredar; (3) naikkan `APP_MIN_VERSION_ANDROID/IOS` ke versi build itu (app lama diarahkan memperbarui, bukan rusak); (4) baru nyalakan `BOOKING_QC_REQUIRED=true` lalu `php artisan config:cache`. Sebelum langkah 4 pastikan ada Store Manager (atau Leader) di tiap toko; Installer Leader masih vacant, jadi sementara Store Manager yang memeriksa. |
| 4–5 | Belum dimulai. |

---

Dokumen ini terdiri dari: (1) ringkasan, (2) riset standar industri, (3) audit sistem sekarang, (4) rancangan target, (5) peta jalan bertahap, (6) keputusan yang dibutuhkan, (7) batasan riset, (8) sumber.

---

## 1. Ringkasan

**Sistem booking sekarang bukan sistem yang rusak.** Fondasinya kuat dan sebagian besar sudah di atas rata-rata: pencegahan booking ganda dengan penguncian transaksi, kapasitas per tanggal, alur pindah jadwal dan pembatalan dengan persetujuan toko, DP dan jurnal, SPK dengan peta kerusakan, penugasan installer, chat dengan foto tahap, pengingat otomatis, garansi, dan jadwal maintenance PPF. Ribuan tes otomatis menjaganya.

**Celahnya ada di tiga tempat, dan semuanya bisa dibenahi bertahap tanpa menulis ulang dari nol:**

1. **Data booking terlalu tipis.** Booking tidak menyimpan kendaraan (plat, merek/model, warna, tahun, ukuran), paket/produk yang dipilih, atau estimasi harga dan durasi. Semua itu baru diketahui staf belakangan (saat SPK atau saat proses transaksi). Pelanggan juga tidak punya "garasi" kendaraan yang bisa dipakai ulang. Detailing dan Premium Wash ada di data dan panel admin, tapi **belum bisa dipesan dari aplikasi pelanggan** (hanya Kaca Film, PPF, dan "Lainnya").
2. **Model kapasitas terlalu kasar.** Kapasitas dihitung sebagai "berapa mobil per hari per toko", sehingga cuci premium 1 jam memakan satu slot yang sama dengan PPF 3 hari. Tidak ada jam kedatangan yang nyata (`preferred_time` hanya teks), tidak ada ketersediaan installer, dan data slot fisik (`detailing_slot_count`, `instalasi_qc_slot_count`) belum dipakai untuk menerima booking.
3. **Alur kerja di bengkel belum sepenuhnya setara standar industri.** SPK sudah ada, tapi belum menjadi work order lengkap: foto penerimaan terpandu, persetujuan pelanggan saat serah terima, QC yang punya daftar periksa per layanan, catatan serah terima dengan petunjuk perawatan, status `no_show`, dan pengingat batas registrasi garansi.

**Rekomendasi:** evolusi bertahap dalam enam fase (bagian 5), bukan tulis ulang total. Alasannya ada di bagian 5.2. Setiap fase dirilis dan teruji sendiri, dan API versi lama tetap berjalan sehingga aplikasi Play Store yang beredar tidak rusak.

---

## 2. Riset: standar industri

Sebagian besar sumber adalah halaman vendor dan blog, jadi angka dan klaimnya **indikatif**, bukan standar baku. Yang bersumber dari regulasi atau dokumen produsen ditandai tersendiri. Daftar sumber ada di bagian 8.

### 2.1 Booking dan penjadwalan
- Perangkat lunak bengkel dan detailing modern menyediakan booking online 24 jam, kalender bersama, penjadwalan drag-and-drop, **pencegahan bentrok slot, termasuk pekerjaan multi-hari**, dan pengingat otomatis [S1].
- Penjadwalan yang baik memperlakukan **bay (stall) dan teknisi sebagai sumber daya**. Sebuah pekerjaan butuh bay, teknisi berkualifikasi, dan peralatan yang tersedia bersamaan. Pekerjaan multi-hari harus memblokir bay dan installer **pada setiap hari** yang dipakainya, bukan hanya hari pertama. Hambatan sering ada di bay atau installer ahli, bukan jumlah karyawan [S14].
- Durasi disimpan per layanan dan bervariasi menurut ukuran kendaraan (mobil kecil beberapa jam, SUV besar bisa dua kali lipat). Layanan diatur sebagai varian per ukuran, dan durasinya ikut memanjang [S10].
- Pencegahan booking ganda harus ditegakkan **di database**, bukan hanya pengecekan di aplikasi ("cek lalu simpan" rawan balapan). Pola umum: batasan unik untuk slot tetap, penghitung bersyarat untuk kapasitas, dan penahanan sementara (hold) dengan masa berlaku bila pelanggan membayar setelah memilih slot [S5].
- Tindakan yang bisa terulang (jaringan putus, ketukan ganda) perlu **kunci idempotensi**: klien membuat satu kunci per tindakan, server menyimpan hasilnya, dan kiriman ulang dengan kunci sama mengembalikan hasil asli, bukan membuat booking kedua [S11].

### 2.2 Penerimaan kendaraan dan inspeksi
- Standar sebelum pekerjaan PPF: inspeksi bersama pelanggan untuk mencatat cacat cat, goresan, penyok, karat. Dokumentasikan semua yang sudah ada sebelum pemasangan karena garansi produsen mengecualikan cacat yang sudah ada [S2].
- Foto saat penerimaan, setelah persiapan, dan inspeksi akhir, dengan cap waktu yang terikat ke nomor pekerjaan atau VIN. Klaim garansi bergantung pada bukti ini [S2].
- Aplikasi bengkel menyimpan foto, catatan, diagram kerusakan, dan tanda tangan digital pada work order, dan bisa mengirim laporan inspeksi ke pelanggan [S1].

### 2.3 Alur status pekerjaan
- Status umum: Checked In, Menunggu Persetujuan, Menunggu Bahan, Dalam Pengerjaan, Pemeriksaan Mutu (QC), Siap Diambil, Selesai/Ditutup. Pemisahan status "menunggu" dari "dalam pengerjaan" memperlihatkan di mana pekerjaan macet. Setiap status punya pemilik dan aturan pemicu berikutnya [S13].
- Perubahan status memicu pemberitahuan otomatis ke pelanggan (kendaraan siap diambil, dan seterusnya), sehingga pelanggan tidak perlu menelepon [S13].
- Pemeriksaan akhir idealnya oleh inspektor yang bukan pengerja unit tersebut, dengan daftar periksa digital [S15].

### 2.4 PPF
- Alur: persiapan permukaan (cuci, dekontaminasi, pembersihan alkohol) → pemotongan dan pemasangan metode basah → penyelesaian tepi → inspeksi di bawah cahaya kuat → masa pengeringan (curing) sekitar 7–10 hari tanpa dicuci [S2].
- Garansi produsen sering mewajibkan **pemasang tersertifikasi**, registrasi dengan data kendaraan dan pemasang, dan kadang **inspeksi berkala tahunan** dengan kartu garansi serta kuitansi asli [S2].
- Di Indonesia garansi umumnya 5–12 tahun menurut merek, dengan **kunjungan maintenance gratis** (misalnya 6 bulan sekali atau setahun sekali) dan dua kunjungan perawatan di bulan pertama [S8]. Ini sudah selaras dengan modul maintenance PPF kita.

### 2.5 Kaca film
- Pekerjaan lebih singkat (umumnya beberapa jam hingga satu hari), dan durasi bergantung pada paket dan ukuran kendaraan [S3].
- Garansi film punya **batas waktu registrasi** (contoh: kartu harus dikirim dalam 30 hari setelah pemasangan agar sah) dan syarat tidak dapat dipindahtangankan [S3]. Sistem sebaiknya membuat tenggat registrasi saat pekerjaan selesai dan menandai yang masih tertunda.
- **Regulasi Indonesia:** acuannya Pasal 58 UU 22/2009 dan KM 439/U/Phb-76. Sumber populer **tidak konsisten soal angka** (ada yang menulis penembusan cahaya minimal 70% untuk kaca samping, ada yang menulis kegelapan maksimal 70%, dan batas 40% untuk kaca depan), karena banyak yang mencampur istilah VLT dan persentase kegelapan [S6]. **Teks resmi harus dicek sebelum angkanya dipasang sebagai aturan di sistem.** Yang aman dilakukan sekarang: mencatat VLT film per kaca di work order dan menampilkan imbauan umum.

### 2.6 Detailing dan Premium Wash
- Menu bertingkat: perawatan ringan (cuci), detail penuh, layanan interior/eksterior terpisah, **add-on yang bisa ditumpuk** (mesin, lampu, bau), lalu tingkat koreksi cat dan coating/PPF yang berhari-hari dan butuh deposit serta garansi [S10].
- Harga mengikuti kelas ukuran kendaraan (selisih tarif per kelas atau grid harga), dan sering ditampilkan sebagai "mulai dari" disertai rentang waktu [S10]. Kita sudah punya matriks harga per ukuran (`PriceCalculator`), dan katalog kendaraan sudah punya `size_category`.
- Persiapan seperti koreksi cat adalah prasyarat coating, sehingga paket sebaiknya bisa menautkan layanan prasyarat.

### 2.6a SOP bengkel Indonesia
Jaringan detailing besar di Indonesia menekankan **SOP seragam di semua gerai yang dikendalikan lewat akademi**, serta pemeriksaan akhir dengan daftar periksa [S15]. Tidak ada standar SOP baku yang ditemukan; yang praktis adalah merumuskan SOP sendiri dari alur di 2.2–2.4 dan memuatnya sebagai daftar periksa per layanan di sistem.

### 2.7 Komunikasi, deposit, no-show
- Urutan pengingat yang disarankan: konfirmasi langsung, pengingat 48 jam dengan tautan pindah jadwal, konfirmasi 24 jam, dorongan hari-H. Pesan yang menyebut nama, layanan, dan staf bekerja lebih baik daripada pesan generik [S4].
- Cara pindah jadwal atau batal harus **satu ketukan dari pesan pengingat**. Jalur batal yang sulit mendorong pelanggan diam-diam tidak datang [S4].
- Beberapa taruhan keuangan mengubah perilaku: deposit yang dikreditkan ke tagihan akhir, atau kartu tersimpan untuk biaya terlambat. Pesan sebagai "deposit untuk menjamin slot", bukan "denda". Bukti efeknya campuran, jadi angka klaim vendor (mis. penurunan hingga 65%) jangan dijadikan target [S4].
- Kebijakan pembatalan harus tampil **di alur booking**, di konfirmasi, dan di pengingat, dan ditegakkan konsisten [S4].
- **WhatsApp di Indonesia:** pesan template jenis utilitas (pengingat transaksi) tarifnya sekitar Rp357 per pesan ditambah PPN 11% menurut vendor BSP (angka bervariasi antar sumber, dan ada laporan perubahan aturan jendela layanan mulai 1 Oktober 2026). Pesan yang berisi promosi diklasifikasikan lebih mahal. Perlu dicek ke halaman resmi Meta sebelum menganggarkan [S7].

### 2.8 UX mobile
- Formulir pendek: tunda isian yang tidak perlu sampai setelah booking; default cerdas (tanggal terdekat yang tersedia); tombol utama menempel di bawah layar; indikator langkah ("Langkah 2 dari 4"); keyboard sesuai isian [S9].
- Pemilih tanggal: dukung ketikan selain kalender, jangan wajib menyeret, beri label teks pada ikon, dan kembalikan fokus ke isian saat ditutup [S9].
- Area sentuh 44–48 px sebagai target desain; batas minimum kepatuhan WCAG 2.2 sekitar 24×24 px (angka ini dari pengetahuan saya, bukan dari hasil pencarian; verifikasi ke teks W3C) [S9].
- Kecepatan: satu kolom, tanpa gulir horizontal.

### 2.9 Perlindungan data pribadi
- UU PDP (27/2022): perlu dasar pemrosesan yang sah; persetujuan harus spesifik, terinformasi, terpisah dari syarat lain, dengan masa retensi dan hak subjek data (akses, koreksi, tarik persetujuan, hapus). Aturan pelaksanaan **PP 33/2026 berlaku efektif 16 Januari 2027**, dengan denda administratif hingga 2% pendapatan tahunan [S12]. Kita sudah punya hapus akun, anonimisasi, dan retensi OTP; yang perlu ditambah adalah **catatan persetujuan dan pemberitahuan privasi di alur booking**, serta jadwal retensi tertulis untuk foto kendaraan.

---

## 3. Audit sistem sekarang

### 3.1 Yang sudah baik (dipertahankan)
| Area | Kondisi |
|---|---|
| Integritas | Kapasitas dikunci di dalam transaksi saat konfirmasi (`fullDatesInRangeLocked`), peralihan status dijaga satu tabel (`STATUS_TRANSITIONS`), batas 3 booking pending dan cek duplikat per pelanggan. |
| Penjadwalan | Kapasitas per tanggal (`StoreCapacityOverride`), tanggal blokir, jam operasional, penghitungan hari kerja dan durasi multi-hari, penanda tanggal "hampir penuh". |
| Perubahan | Pindah jadwal dan pembatalan dengan persetujuan toko, SLA pengingat bagi toko, riwayat. |
| Pengerjaan | Tahap per produk (Kaca Film, PPF, paralel), tahap bersama QC dan Serah Terima, foto tahap via chat grup, installer dan pemantau ditugaskan, komisi. |
| Dokumen | SPK (checklist, peta kerusakan, data kendaraan, jam masuk/keluar), DP, invoice, jurnal pendapatan, PPN. |
| Purna jual | Garansi, jadwal maintenance PPF (toleransi 30 hari), klaim, ulasan toko, poin. |
| Pengingat | Pengingat **H-1** (push) ke pelanggan dan installer yang ditugaskan, ringkasan "perlu tindakan" ke Store Manager untuk booking lewat tanggal, pengingat servis, dan pengingat booking pending ke staf. Belum ada pengingat H-2 atau hari-H untuk pelanggan. |
| Kualitas | 2.153 tes otomatis, sapuan semua halaman admin dan endpoint baca. |

### 3.2 Celah, dibandingkan standar
Prioritas: **T** tinggi, **S** sedang, **R** rendah.

| # | Area | Kondisi sekarang (dari kode) | Standar industri | Dampak | Prio |
|---|---|---|---|---|---|
| 1 | Data kendaraan | Booking **tidak punya plat, merek/model, warna, tahun, VIN**. Hanya `vehicle_size` yang diisi staf saat proses transaksi. Tidak ada "Kendaraan Saya". | Kendaraan dicatat saat booking dan dipakai ulang; menentukan harga dan durasi. | Staf harus menelepon untuk tahu mobilnya; ukuran dan harga baru diketahui belakangan; garansi dan SPK mengetik ulang. | **T** |
| 2 | Katalog layanan | App pelanggan hanya menawarkan Kaca Film, PPF, keduanya, dan "Lainnya". **Detailing dan Premium Wash tidak bisa dipesan dari app** walau datanya ada. Tidak ada paket, add-on, atau pilihan film. | Menu bertingkat, paket, add-on, estimasi harga "mulai dari" dan durasi. | Pelanggan Detailing/Wash harus lewat WhatsApp; staf memetakan manual. | **T** |
| 3 | Kapasitas | "Mobil per hari per toko" (`install_capacity_per_day`, default 3), tanpa membedakan berat dan ringan. Hanya booking `confirmed` yang dihitung. | Kapasitas per sumber daya (bay, installer), durasi per layanan dan ukuran. | Cuci 1 jam menghabiskan slot PPF; slot ringan kosong tapi tampil penuh, atau sebaliknya. | **T** |
| 4 | Jam kedatangan | `preferred_time` teks bebas (mis. "09:00 - 11:00"), tidak divalidasi dan tidak memengaruhi kapasitas. | Slot jam nyata untuk layanan beberapa jam; serah-terima (drop-off) dan estimasi selesai untuk PPF. | Tidak ada pengaturan antrean harian; bentrok jam tidak terdeteksi. | **S** |
| 5 | Ketersediaan installer | Installer ditugaskan setelah konfirmasi, dengan peringatan jika bentrok; tidak ikut menentukan apakah slot tersedia. | Pekerjaan butuh bay + installer berkualifikasi sekaligus. | Booking terkonfirmasi tanpa installer yang bisa mengerjakannya. | **S** |
| 6 | Work order | SPK ada, tapi **dibuat manual oleh leader** dan terpisah dari booking; tidak ada foto penerimaan terpandu, tanda tangan/persetujuan pelanggan, atau catatan serah terima. | Penerimaan terpandu (sudut foto wajib), persetujuan, serah terima dengan petunjuk perawatan dan tanda tangan. | Sengketa "sudah tergores sebelum dipasang" sulit dibuktikan. | **T** |
| 7 | QC | Tahap `qc` hanya penanda; tidak ada daftar periksa per layanan; tidak diwajibkan orang selain pengerja. | Daftar periksa digital per layanan, inspektor independen, foto bawah cahaya. | Mutu bergantung kebiasaan. | **S** |
| 8 | Status | `pending → confirmed → completed/cancelled`. **Tidak ada `no_show`**, `checked_in`, `in_progress`, `ready`. Kemajuan hanya lewat kolom tahap. | Status eksplisit dengan pemilik dan pemicu; `no_show` untuk kebijakan dan statistik. | Tidak bisa melaporkan tingkat tidak-datang atau lama antrean; "siap diambil" tidak diumumkan otomatis. | **S** |
| 9 | Deposit | DP hanya dicatat staf (`DownPaymentService`); **pelanggan tidak melihat atau membayar deposit dari app**, dan kebijakan batal/deposit tidak ditampilkan. | Deposit untuk menjamin slot (terutama PPF/coating), kebijakan tampak di alur dan pengingat. | Slot PPF bernilai besar bisa kosong karena tidak datang. | **S** |
| 10 | Komunikasi | Notifikasi ke pelanggan **push saja**; email hanya ke staf; tidak ada WhatsApp otomatis; pengingat tidak membawa tautan pindah jadwal satu ketukan. | Urutan pengingat multi-kanal dengan tautan aksi; WhatsApp dominan di Indonesia. | Pelanggan tanpa push aktif tidak terjangkau. | **S** |
| 11 | Idempotensi | `POST /api/customer/bookings` tanpa kunci idempotensi. Pengamanan: batas 3 pending dan cek duplikat (toko+tanggal+layanan). | Kunci idempotensi per tindakan. | Jika respons sukses hilang di jaringan, ketukan ulang mendapat "booking sudah ada" (error palsu) padahal berhasil. | **S** |
| 12 | Booking dari staf | Booking walk-in/WhatsApp hanya bisa dibuat di panel admin; **tidak ada endpoint/layar staf di aplikasi**. | Staf membuat booking cepat di tempat. | Staf toko harus membuka panel admin. | **S** |
| 13 | Garansi | Ada modul garansi; **tidak ada tenggat registrasi garansi merek** per pekerjaan dengan penanda tertunda, dan VLT film per kaca tidak dicatat terstruktur. | Tenggat registrasi, pencatatan film, dan batas waktu. | Garansi merek bisa gugur karena registrasi telat. | **R–S** |
| 14 | Kepatuhan data | Hapus akun, anonimisasi, retensi OTP sudah ada. **Persetujuan eksplisit dan catatannya di alur booking, serta jadwal retensi foto, belum ada.** | UU PDP/PP 33/2026 berlaku 16 Jan 2027. | Risiko kepatuhan. | **S** |
| 15 | Versi aplikasi | Tidak ada pemeriksaan versi minimum aplikasi. | Mekanisme minimum versi untuk menghentikan app lama saat kontrak berubah. | Perubahan kontrak berisiko memutus app lama (seperti selfie absen). | **T** (prasyarat rilis) |

Catatan: item 3 dan 4 saling terkait. Cuci premium yang 1 jam tidak seharusnya memakai satu slot PPF seharian, dan data zona fisik (`detailing_slot_count`, `instalasi_qc_slot_count`) sudah ada di tabel toko sehingga bisa dipakai tanpa data baru.

---

## 4. Rancangan target

### 4.1 Prinsip
1. **Setiap fase dirilis sendiri dan teruji**, dan tidak memutus aplikasi yang beredar (versi API lama tetap hidup; fitur baru berupa penambahan).
2. **Data lebih kaya di awal, tidak diketik ulang kemudian**: kendaraan, layanan, ukuran, estimasi sekali, lalu mengalir ke SPK, garansi, invoice.
3. **Kapasitas mencerminkan kenyataan kerja**: berat vs ringan, jam vs hari, installer yang tersedia.
4. **Setiap status punya pemilik, pemicu, dan notifikasi.**
5. **Aturan ditegakkan di server dan database**, bukan hanya UI.
6. **Setiap perubahan berpasangan dengan tes** (alur API, kontrak aplikasi, sapuan halaman).

### 4.2 Model data (usulan)
| Entitas | Isi utama | Catatan |
|---|---|---|
| `customer_vehicles` ("Kendaraan Saya") | pelanggan, `vehicle_id` (katalog), plat, tahun, warna, VIN opsional, `size_category` turunan dari katalog | Dipakai ulang di booking, SPK, garansi, maintenance. |
| `services` (katalog layanan) | jenis (`ppf`, `kaca_film`, `detailing`, `premium_wash`), nama, deskripsi, kategori kapasitas (berat/ringan), aktif | Satu sumber untuk app, admin, dan laporan. |
| `service_packages` | layanan, nama paket, cakupan (mis. full body/front end, atau jumlah kaca), prasyarat (koreksi cat), garansi | Memetakan ke `film_products` bila perlu. |
| `service_durations` | paket × ukuran → jam atau hari kerja | Menggantikan `duration_days` default. |
| `service_addons` | nama, harga, tambahan durasi | Dapat ditumpuk di paket. |
| `booking_items` | booking, paket, produk film (opsional), add-on, estimasi harga, estimasi durasi | Satu booking, banyak layanan, menggantikan flag produk yang terpisah-pisah (flag lama tetap diisi agar kompatibel). |
| `bookings` (tambahan) | `customer_vehicle_id` + salinan plat/merek/model/warna/ukuran, `arrival_window_start/end`, `estimated_finish_date`, `deposit_required/paid`, `consent_at/version`, `no_show_at`, kunci idempotensi | Salinan kendaraan agar riwayat tidak berubah. |
| `booking_intake_photos` | booking/SPK, sudut (daftar baku), foto, cap waktu, pengambil | Penerimaan terpandu. |
| `qc_checklists` / `qc_results` | daftar periksa per layanan, hasil, pemeriksa, foto | Pemeriksa ≠ pengerja bila memungkinkan. |
| `handover_records` | serah terima, persetujuan/tanda tangan, petunjuk perawatan terkirim, foto akhir | Memicu registrasi garansi dan jadwal maintenance. |
| `capacity_rules` | toko × kategori (berat/ringan) × hari → kapasitas (atau poin beban), dengan pengganti per tanggal | Menggantikan `install_capacity_per_day`, tetap membaca `StoreCapacityOverride`. |

### 4.3 Alur target (ringkas)
**Pelanggan (aplikasi):** pilih/tambah **kendaraan** → pilih **layanan dan paket** (harga "mulai dari" dan durasi tampil) → pilih **tanggal dan jam kedatangan** (hanya yang tersedia untuk kategori layanan itu) → **ringkasan** (kebijakan batal, deposit bila ada, persetujuan data) → kirim dengan kunci idempotensi → konfirmasi dan pengingat → **lacak kemajuan** → **serah terima** (foto akhir, petunjuk perawatan) → ulasan → garansi dan jadwal maintenance otomatis.

**Staf toko:** terima booking (ada kendaraan, layanan, estimasi) → konfirmasi (cek kapasitas dan installer tersedia) → **penerimaan** (foto sudut terpandu, catatan kerusakan, persetujuan) → kerjakan (tahap dan foto) → **QC** (daftar periksa, pemeriksa berbeda) → **siap diambil** (notifikasi otomatis) → **serah terima** (tanda tangan, petunjuk) → tutup (jurnal, komisi, garansi).

**Status baru (usulan):** `pending → confirmed → checked_in → in_progress → qc → ready → completed`, ditambah `cancelled` dan `no_show`. Kolom tahap lama tetap sebagai detail di dalam `in_progress`, dan aturan peralihan tetap satu tabel yang dijaga di server.

### 4.4 Fitur dan menu
**Aplikasi pelanggan:** Kendaraan Saya; katalog layanan dengan paket dan add-on; booking 4 langkah; pelacak kemajuan; chat; pindah jadwal dan batal satu ketukan dengan kebijakan terlihat; deposit dan bukti transfer; ulasan; garansi dan maintenance; riwayat dan faktur.
**Aplikasi staf:** booking baru walk-in/WhatsApp; penerimaan terpandu; kerja dan tahap; QC; serah terima; ketersediaan installer; daftar tugas hari ini.
**Panel admin:** kalender kapasitas per kategori; katalog layanan, paket, add-on, durasi; daftar periksa QC per layanan; laporan (SLA konfirmasi, tingkat no-show, utilisasi, lama pengerjaan, konversi pending→confirmed, alasan batal).

### 4.5 Aturan UI/UX
- Booking maksimal 4 langkah, satu kolom, tombol utama menempel di bawah, indikator langkah.
- Tanggal terdekat yang tersedia terpilih dulu; ketikan tanggal didukung.
- Area sentuh ≥ 44 px; teks minimal 13 px (sudah kita terapkan); kontras dan mode gelap diuji.
- Pesan error menjelaskan masalah dan cara memperbaiki (sudah menjadi kebiasaan kita).
- Keadaan kosong dan galat jaringan punya teks dan tindakan "coba lagi".
- Setiap layar baru masuk daftar uji HP.

### 4.6 Keandalan dan keamanan
- Kunci idempotensi pada pembuatan booking dan tindakan penting (24 jam).
- Kapasitas ditegakkan di transaksi dengan penguncian; pertimbangkan penahanan sementara (hold) bila deposit dibayar setelah memilih slot.
- Semua perubahan status lewat satu jalur dengan jejak audit.
- Kontrak aplikasi↔API diuji otomatis (lanjutan audit kontrak tadi), termasuk bentuk multipart.
- Versi minimum aplikasi (item 15) sebelum perubahan kontrak berat.

### 4.7 Ukuran keberhasilan
Tingkat konfirmasi dalam SLA, tingkat no-show dan pembatalan menurut alasan, utilisasi slot berat/ringan, lama dari booking ke selesai, jumlah booking yang butuh telepon untuk melengkapi data (target mendekati nol), dan jumlah error booking di Sentry.

---

## 5. Peta jalan

### 5.1 Fase (perkiraan kasar satu developer, belum termasuk pengujian di HP oleh Anda)
| Fase | Isi | Perkiraan | Terlihat bagi pengguna |
|---|---|---|---|
| **0. Fondasi** | Versi minimum aplikasi; kunci idempotensi di `POST bookings` (+ aplikasi); kerangka tes kontrak; metrik dasar | 1 minggu | Tidak ada error palsu saat jaringan buruk; app lama diarahkan memperbarui. |
| **1. Kendaraan dan katalog** | `customer_vehicles`, katalog layanan/paket/add-on/durasi, booking 4 langkah v2 (Detailing & Premium Wash ikut), harga "mulai dari", API v1 tetap | 2–3 minggu | Pelanggan memesan semua layanan dari app dengan data lengkap. |
| **2. Kapasitas dan jadwal** | Kapasitas berat/ringan, slot jam untuk layanan ringan, blok multi-hari PPF, ketersediaan installer, antrean tunggu | 2–3 minggu | Tanggal/jam yang tampil sesuai kenyataan; tidak ada penuh palsu. |
| **3. Penerimaan, QC, serah terima** | Work order dari SPK (foto terpandu, persetujuan), daftar periksa QC per layanan, serah terima + petunjuk, status baru + `no_show`, tenggat registrasi garansi | 3 minggu | Bukti kondisi awal; mutu lebih konsisten; "siap diambil" otomatis. |
| **4. Komunikasi dan deposit** | Urutan pengingat dengan tautan aksi (push + WhatsApp), deposit dan bukti transfer, kebijakan batal tampil | 2 minggu | Lebih sedikit tidak-datang; pelanggan paham aturan. |
| **5. Polesan, laporan, kepatuhan** | Booking staf di app, laporan SLA/no-show, persetujuan data dan retensi foto, aksesibilitas, uji beban | 2 minggu | Alat kerja staf lengkap; siap PP 33/2026 (16 Jan 2027). |

Total sekitar 12–16 minggu kalender. Angka ini **estimasi kasar**; setiap fase punya keputusan yang bisa menggeser ukurannya (bagian 6).

### 5.2 Kenapa bertahap, bukan tulis ulang
Tulis ulang total berarti membangun ulang ratusan perilaku yang sekarang sudah teruji (kapasitas terkunci, pindah jadwal, DP, jurnal, SPK, komisi, garansi), menghentikan aplikasi di Play Store, dan menunda perbaikan sampai semuanya selesai. Pendekatan bertahap mengirim nilai di tiap fase dan menjaga yang sudah berjalan.

### 5.3 Cara menjaga "tanpa bug"
Tidak ada perangkat lunak yang bisa dijamin nol bug, tapi risiko ditekan dengan: tes otomatis per perubahan, tes kontrak aplikasi↔API, `smoke:panel` dan `smoke:api` sebelum rilis, daftar uji HP per fase, rilis ke dev dulu dengan data demo, pemantauan Sentry dan pemantau uptime setelah rilis, dan versi API lama yang tetap hidup. Ini tepat pola yang kita pakai pada rilis 13 Oktober.

---

## 6. Keputusan yang dibutuhkan (dengan rekomendasi)

| # | Pertanyaan | Opsi | Rekomendasi saya |
|---|---|---|---|
| 1 | Model kapasitas | A. tetap mobil/hari per toko; **B. dua kategori (berat: PPF/kaca film/coating, ringan: detailing/premium wash)**; C. bay dan poin beban penuh | **B** dulu (memakai `detailing_slot_count` dan `instalasi_qc_slot_count`), siapkan C bila nanti perlu. |
| 2 | Slot jam untuk layanan ringan? | ya / tidak | **Ya**, jendela kedatangan per jam. PPF tetap tanggal serah-terima + estimasi selesai. |
| 3 | Harga di app | tampilkan "mulai dari" / sembunyikan | **Tampilkan** estimasi per ukuran (data harga sudah ada); final tetap dikonfirmasi toko. |
| 4 | Detailing dan Premium Wash dibuka di app | ya / belum | **Ya**, dengan paket tetap dan add-on terbatas dulu. |
| 5 | Deposit | wajib untuk PPF/coating? nominal atau persen? hangus bila no-show? | Wajib untuk PPF dan layanan di atas batas harga tertentu, persen dari estimasi, metode transfer manual + unggah bukti (QRIS sudah diputuskan tidak dipakai). **Butuh angka dan kebijakan hangus dari Anda.** |
| 6 | Jendela batal gratis | mis. 24 jam / 48 jam | **24 jam** untuk ringan, 48 jam untuk PPF. |
| 7 | Kanal WhatsApp | tautan `wa.me` manual dulu / Meta Cloud API lewat BSP | **`wa.me` dulu** (gratis, staf mengirim dari tombol), pertimbangkan API resmi setelah volume jelas (biaya indikatif Rp357 per pesan utilitas + PPN; verifikasi ke Meta). |
| 8 | Siapa melakukan QC | leader / store manager / orang lain | **Leader atau store manager, bukan pengerja unit tersebut.** |
| 9 | Foto penerimaan | wajib berapa sudut? | **Minimal 8 sudut baku + catatan kerusakan** untuk PPF; lebih ringan untuk wash. |
| 10 | Tanda tangan serah terima di HP | wajib / opsional | **Wajib untuk PPF dan kaca film, opsional untuk wash.** |
| 11 | Regulasi kaca film | pasang aturan VLT di sistem? | **Belum.** Catat VLT per kaca dan tampilkan imbauan umum sampai teks resmi diverifikasi. |
| 12 | Cakupan fase pertama | Fase 0+1 dulu | **Fase 0 dan 1** sebagai langkah pertama (nilai terbesar, risiko paling rendah). |

---

## 7. Batasan riset
- Banyak sumber adalah halaman vendor, blog, dan artikel otomotif. Itu cukup untuk pola umum, tetapi bukan standar baku.
- **Tidak ditemukan** standar SOP Indonesia yang baku untuk poles/coating/PPF, ataupun syarat registrasi garansi PPF yang rinci untuk merek yang kita jual. Syarat registrasi harus diambil dari distributor masing-masing merek.
- Angka regulasi kaca film **belum diverifikasi ke teks resmi** dan sumbernya saling bertentangan. Jangan jadikan aturan di sistem sebelum dicek.
- Biaya WhatsApp berasal dari ringkasan vendor dan bervariasi; ada laporan perubahan aturan jendela layanan per 1 Oktober 2026 yang harus dicek ke Meta.
- Angka WCAG 2.2 (24×24 px) berasal dari pengetahuan saya, bukan hasil pencarian.
- Informasi UU PDP dan PP 33/2026 berasal dari ulasan firma hukum; rujuk teks resmi bila akan dijadikan kebijakan tertulis. Ada satu sumber yang menyebut UU PDP diubah oleh UU No. 1 Tahun 2026, yang tidak terkonfirmasi di sumber lain.

---

## 8. Sumber
- **[S1]** Fitur perangkat lunak bengkel/detailing: [RO App Car Detailing](https://roapp.io/car-detailing/), [Workshop Software](https://www.workshopsoftware.com/features), [Workliner](https://apps.apple.com/th/app/workliner-workshop-manager/id6740831760)
- **[S2]** Alur PPF, persiapan, garansi: [CDC Auto Detailing, PPF Workflow](https://cdcautodetailing.com/paint-protection-film-workflow/), [3M Vehicle Prep Guide](https://multimedia.3m.com/mws/media/2429871O/3m-vehicle-prep-for-success-guide-considerations-before-installation-en.pdf), [Avery Dennison SPF Warranty 2025](https://graphics.averydennison.com/content/dam/averydennison/graphics/eu/en/Warranty/spfdirseries/SPF%20DIR%20Series%20Warranty%20-%20Conditions%20and%20Limitations%202025.pdf), [3M Paint Protection Installer Warranty Form](https://3m.com/3M/en_US/post-factory-installation-us/resources/paint-protection-installer-warranty-form)
- **[S3]** Kaca film dan garansi: [Mobile Tech RX, Window Tint](https://www.mobiletechrx.com/window-tint/), [Tint Club Warranty](https://tintclub.com/pages/warranty), [Turbo Tint](https://turbotint.com/blogs/news/the-questions-to-ask-about-auto-tinting-installation)
- **[S4]** No-show, deposit, pengingat: [SimplyBook.me](https://simplybook.me/blog/how-to-reduce-no-shows/), [Square Appointments](https://squareup.com/us/en/appointments/features/cancellation-policy)
- **[S5]** Booking ganda dan konkurensi: [DEV, race conditions multi-tenant](https://dev.to/prontodev/how-i-solved-double-booking-race-conditions-in-a-multi-tenant-saas-no-redis-no-distributed-locks-3gfa), [Appointment Booking LLD](https://www.techinterview.org/post/3233470340/lld-appointment-booking/)
- **[S6]** Kaca film Indonesia: [Auto2000, Aturan Kaca Film](https://auto2000.co.id/berita-dan-tips/aturan-kaca-film-mobil), [Gridoto, VLT dan IRR](https://www.gridoto.com/amp/224395331/jangan-salah-pilih-ketahui-arti-vlt-dan-irr-pada-kaca-film-mobil?page=all), [Auto2000, Kaca Film 80 Persen](https://auto2000.co.id/berita-dan-tips/kaca-film-80-persen-ditilang)
- **[S7]** WhatsApp Indonesia: [ChatMaxima](https://chatmaxima.com/whatsapp-api-pricing/indonesia/), [Qontak, Oktober 2026](https://help-center.qontak.com/hc/en-us/articles/48430892733465-Latest-WhatsApp-Message-Prices-October-2026)
- **[S8]** Garansi PPF Indonesia: [Gridoto, KITAWRAPIN](https://www.gridoto.com/amp/224378828/minat-pasang-ppf-premium-kitawrapin-kasih-garansi-8-sampai-10-tahun?page=all), [detikOto, perawatan PPF](https://oto.detik.com/tips-and-tricks-mobil/d-8600702/pasang-antigores-mobil-bisa-puluhan-juta-rupiah-perlu-perawatan-lagi), [Autofun](https://www.autofun.co.id/berita/mau-pasang-ppf-mobil-segini-biaya-dan-cara-pilih-tempatnya-yang-terbaik-83834/)
- **[S9]** UX mobile: [CXL, Mobile Forms](https://cxl.com/blog/mobile-forms/), [Uxcel, Booking Best Practices](https://uxcel.com/lessons/booking-best-practices-107), [US Web Design System, Date Picker](https://designsystem.digital.gov/components/date-picker/)
- **[S10]** Menu dan harga detailing: [Servicify](https://www.getservicify.com/industries/auto-detailing), [CDC, How to Price Car Detailing 2026](https://cdcautodetailing.com/how-to-price-car-detailing-2026-buyers-guide)
- **[S11]** Idempotensi: [Whop Docs](https://docs.whop.com/developer/api/idempotency), [DEV, Idempotency Keys](https://dev.to/137foundry/how-to-design-idempotency-keys-for-retry-prone-web-actions-without-doubling-user-requests-1fon)
- **[S12]** UU PDP: [Rajah & Tann, PP PDP](https://www.rajahtannasia.com/viewpoints/pdp-law-updates-the-pdp-implementing-regulation-is-out-and-it-clarifies-some-key-questions-under-indonesias-pdp-law/), [Conventus Law](https://conventuslaw.com/featured-content/2022-indonesian-data-protection-law-overview/)
- **[S13]** Status pekerjaan: [Clue, Work Order Workflow](https://www.getclue.com/learn/work-order-workflow), [MOTOR, AutoVitals Workflow Notifications](https://www.motor.com/2025/01/autovitals-releases-enhanced-workflow-status-notifications/)
- **[S14]** Penjadwalan sumber daya: [SchedulingKit, Car Detailing](https://schedulingkit.com/team-scheduling/car-detailing), [Oxmaint, Bay Scheduling](https://oxmaint.com/industries/fleet-management/fleet-shop-capacity-bay-scheduling-guide-2026)
- **[S15]** SOP detailing Indonesia: [Gridoto, Scuto Academy](https://otomotifnet.gridoto.com/read/233209883/scuto-indonesia-ajak-jurnalis-praktek-car-detailing-di-scuto-academy?page=all)
