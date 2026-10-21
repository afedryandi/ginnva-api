# Rancangan Ulang Menu Master Data Ginnva

Dibuat 2026-10-21 atas permintaan pemilik: rancang ulang menu Master Data (data, sistem, alur kerja, fungsi, fitur, menu, UI/UX di API dan mobile) untuk PPF, kaca film, detailing, dan premium wash, sesuai standar, tanpa bug, mudah dipakai, dengan riset internet. **Dokumen ini hanya audit, riset, dan rancangan. Belum ada kode yang diubah.** Pola sama dengan `RANCANGAN_ULANG_KEUANGAN.md` dan `RANCANGAN_ULANG_PENJUALAN.md`.

## Keputusan pemilik yang sudah ada (2026-10-21)

- **Luas kaca dipindahkan ke database;** Google Sheet hanya sumber impor sekali (keputusan 1).
- **Pemilik data: satu peran khusus** yang boleh mengubah ukuran dan luas kaca; staf toko mengajukan (keputusan 2).
- **Kendaraan dinonaktifkan, tidak dihapus;** hapus hanya untuk yang belum dipakai (keputusan 4).
- **Pusat Master Data dibangun,** kecil dan hanya baca (keputusan 8).
- Keputusan 3 (kriteria ukuran), 5 (kode toko/zona waktu), 6 (impor CSV), 7 (pengajuan dari HP) belum dijawab.

## 1. Ringkasan

Menu "Master Data" sekarang kecil dan sebagian tersembunyi: yang terlihat hanya **Kendaraan** dan **Toko/Dealer**; **Kode Gulungan** dan **Koefisien Harga** sengaja disembunyikan. Master data lain (produk, resep, grup pelanggan, jenis layanan, bagan akun, rekening, jenis karyawan, dan seterusnya) tersebar di enam menu lain. Masalahnya bukan banyaknya fitur, melainkan tiga hal:

1. **Data kendaraan terpecah dua sumber** yang tidak terhubung: tabel `vehicles` (ukuran S sampai XXL) dan Google Sheet "ukuran" (luas kaca dalam m² per posisi) yang dibaca halaman daftar harga. Luas kaca, data yang paling dekat dengan biaya bahan kaca film, tidak ada di database.
2. **Kendaraan tidak bisa dinonaktifkan atau diarsipkan**, hanya dihapus, dan penghapusan hanya dijaga sebagian.
3. **Aturan data tidak tertulis dan kepemilikan tidak jelas**: kategori ukuran dipilih manual per baris tanpa kriteria; staf toko bisa mengubah data nasional; teks bantuan di form masih menyebut model harga yang sudah ditinggalkan.

Usulan: bereskan kebersihan, jadikan database satu-satunya sumber data kendaraan (termasuk luas kaca), beri siklus hidup (aktif/nonaktif) dan aturan ukuran yang tertulis, tambah beberapa atribut toko, atur hak ubah, dan sediakan satu halaman "Pusat Master Data" yang menunjukkan kelengkapan data. Terbagi 7 fase (0 sampai 6), tiap fase bisa dirilis sendiri. **Ada 8 keputusan pemilik** (bagian 6).

## 2. Riset

Catatan kejujuran: hasil pencarian umumnya halaman vendor dan blog; untuk kaca film hasilnya bahkan saling bertentangan. Dipakai sebagai arah, bukan sebagai standar yang harus ditiru.

**Pengelolaan master data (MDM).** Praktik yang konsisten antar sumber: setiap entitas punya **pengenal unik** (master record); ada **siklus hidup/status** (bukan hanya ada atau terhapus); ada **steward** yang memutuskan siapa mendefinisikan elemen data dan menyetujui perubahan; **keunikan** dan kelengkapan diukur sebagai kualitas data; duplikat menyesatkan laporan dan layanan ([Master Data Stewardship](https://geosurge.ai/corpus/gen-3181/master-data-management/master-data-stewardship.html), [Dun & Bradstreet](https://www.dnb.com/en-us/blog/master-data/five-best-practices-master-data-management.html), [Ampliz](https://www.ampliz.com/resources/master-data-management-best-practices/)). Pencarian tidak menemukan panduan khusus "nonaktifkan, jangan hapus" untuk usaha kecil; kesimpulan itu adalah penalaran saya dari prinsip siklus hidup dan menjaga tautan riwayat.

**Data kendaraan.** Industri suku cadang memakai **VCdb/ACES**: kendaraan dijelaskan sebagai tahun, merek, model, submodel, mesin, dan tipe bodi/penggerak, dengan kualifikasi supaya suatu produk tidak dianggap cocok untuk semua trim; **VIN** menentukan konfigurasi, tetapi data VIN dan data kecocokan produk adalah dua hal berbeda ([Auto Care VCdb](https://www.autocare.org/what-we-do/technology/product-areas/vcdb/), [MOTOR VIN-to-VCdb](https://www.motor.com/wp-content/uploads/2025/08/MOTOR_VIN_to_VCdb_US_ContentDevKit.pdf), [DataOne](https://vin.dataonesoftware.com/vin_basics_blog/vin-data-vs-vehicle-parts-fitment-data)). Pelajaran untuk Ginnva: merek, model, dan varian sudah benar; yang kurang adalah **tipe bodi** dan **rentang tahun/generasi**, karena kaca dan panel berbeda antar generasi. VCdb sendiri terlalu besar dan berlisensi untuk dipakai penuh.

**Luas kaca dan harga kaca film.** Sumber yang ditemukan saling bertentangan (mis. 5 sampai 8 sq ft untuk sedan kompak menurut satu panduan, sekitar 50 sq ft menurut yang lain), dan banyak halaman tampak berkualitas rendah, jadi **tidak ada angka luar yang boleh dipakai**. Yang konsisten hanya metodenya: luas = panjang x tinggi tiap kaca (inci), dijumlahkan, dibagi 144 untuk kaki persegi; lebar gulungan (20 atau 24 inci untuk kaca samping) ikut memengaruhi sisa bahan ([Flex Film: panduan pengukuran](https://flexfilmstore.com/pages/tint-measurement-guide)). Ginnva sudah punya cara itu di Google Sheet (depan, samping, belakang, sunroof); angkanya dari pengukuran sendiri, itulah yang perlu dijaga.

**Batas riset.** Tidak ditemukan standar resmi Indonesia khusus daftar kendaraan bengkel, dan tidak ada acuan ukuran kendaraan S sampai XXL yang seragam antar bengkel. Kriteria ukuran harus ditetapkan Ginnva sendiri.

## 3. Audit sistem sekarang

Semua butir **diverifikasi di kode** (berkas disebut) kecuali ditandai.

### 3.1 Peta master data (di mana tiap master tinggal)

| Master | Menu sekarang | Siklus hidup | Jejak audit |
|---|---|---|---|
| Kendaraan | Master Data | **tidak ada** (tanpa kolom aktif/arsip; hapus langsung) | ya |
| Toko/Dealer | Master Data | `is_active`; hapus dijaga ringkasan pemakaian | ya |
| Kode Gulungan, Koefisien Harga | Master Data (**disembunyikan**) | n/a | n/a |
| Produk Film, Master Resep | Produk | hapus lunak + aktif | ya |
| Grup Pelanggan | Pelanggan | aktif | ya |
| Jenis Layanan Booking | Booking | aktif | ya |
| Bagan Akun, Rekening Kas/Bank | Keuangan | aktif | ya |
| Jenis Karyawan, Jenis Izin | Karyawan | aktif | ya |
| Kategori Bahan, Bahan Baku, Barang Habis Pakai, Produk PPF/WF, Aset | Inventori | tidak ada kolom `is_active` di model | ya |
| Teknisi | Booking | tidak ada kolom `is_active` di model | ya |

*(Kolom `is_active` yang tidak ada di model belum berarti tidak ada cara menonaktifkan: bisa memakai status lain. Perlu dicek per master saat fase terkait.)*

### 3.2 Celah

| # | Temuan | Bukti | Dampak |
|---|---|---|---|
| M1 | **Dua sumber data kendaraan tanpa penghubung.** `vehicles` di database (merek, model, varian, ukuran S sampai XXL) dipakai booking, penawaran, dan kendaraan pelanggan. Luas kaca per posisi (depan, samping, belakang, sunroof) dan harga hanya ada di Google Sheet yang dibaca `PricelistDataService`. | `Vehicle`, `PricelistDataService::getCarSqm`, `VehicleCatalogService` | Nama merek/model bisa berbeda antar dua sumber; memperbarui kendaraan baru harus dikerjakan dua kali; luas kaca tidak bisa dipakai oleh sistem (bahan, HPP, penjualan) |
| M2 | **Kendaraan tidak bisa diarsipkan.** Hapus hanya ditolak bila masih dipakai penawaran/studi kasus (FK `restrict`). Kendaraan pelanggan yang menautnya ke katalog (`customer_vehicles.vehicle_id`) berstatus `nullOnDelete`: menghapus kendaraan katalog diam-diam memutus tautan itu. | `VehicleResource`, migrasi `customer_vehicles` | Hilangnya tautan katalog tanpa peringatan; tidak ada cara "berhenti ditawarkan" tanpa menghapus |
| M3 | **Kategori ukuran tanpa kriteria tertulis.** Dipilih manual per baris; satu-satunya penjelasan adalah komentar seeder "dimensi umum". Ukuran inilah yang menentukan harga PPF dan kaca film. | `VehicleResource`, `VehicleSeeder` | Konsistensi bergantung pada ingatan staf; dua kendaraan serupa bisa berbeda kelas |
| M4 | **Teks bantuan usang.** Form Kategori Ukuran masih menyebut "koefisien harga (base_price × coefficient)", padahal harga memakai matriks per ukuran (`PriceCalculator`). | `VehicleResource` | Menyesatkan staf yang mengubah ukuran |
| M5 | **Duplikasi kode dan seeder.** Daftar katalog dibangun di dua tempat (`QuotationController::options` dan `VehicleCatalogService`); tiga seeder kendaraan yang tumpang tindih (`VehicleSeeder`, `VehicleBrandSeeder`, `VehicleModelSeeder`). | file terkait | Risiko perilaku menyimpang antar tempat; data awal tidak jelas dari mana |
| M6 | **Atribut kendaraan sangat sedikit**: hanya merek, model, varian, ukuran. Tidak ada tipe bodi atau rentang tahun/generasi. | `Vehicle` | Kaca dan panel berbeda antar generasi; ukuran tidak bisa diturunkan atau diperiksa dari tipe bodi |
| M7 | **Toko tidak punya kode singkat dan zona waktu.** Jam buka sudah per hari dan per kapasitas, jarak absen, dan ringkasan pemakaian sudah baik. | `Store`, migrasi `stores` | Laporan dan nomor dokumen hanya mengenali nama; toko di luar WIB kelak butuh zona waktu |
| M8 | **Data master nasional dapat diubah staf toko.** Kendaraan memakai izin "semua staf dengan akses menu" dan aksi bawaan diizinkan; tidak ada pemilik data atau persetujuan perubahan. | `VehicleResource::canEdit` dan komentar kelas | Satu perubahan ukuran mengubah harga untuk semua toko |
| M9 | **Master data sulit ditemukan.** Menu "Master Data" hanya berisi dua item terlihat; widget "Master Data" di dashboard hanya menampilkan Toko Aktif dan Produk Film. Tidak ada tempat melihat kelengkapan (kendaraan tanpa ukuran, produk tanpa harga, toko tanpa koordinat). | `MasterDataCluster`, `MasterDataStatsWidget` | Kekurangan data baru ketahuan ketika harga atau booking error |
| M10 | **Mobile**: aplikasi hanya membaca katalog kendaraan publik (`/vehicles/catalog`) dan daftar toko; staf tidak punya cara mengajukan kendaraan yang belum ada. | `routes/api.php` | Kendaraan baru bergantung pada admin web |

### 3.3 Yang sudah baik (dipertahankan)
- Jejak audit pada kendaraan dan toko; proteksi hapus toko lewat `usageSummary()` yang menghitung semua tabel yang memakai toko itu.
- Keunikan merek+model+varian (dengan penjagaan tambahan untuk varian kosong), casing merek dinormalkan di katalog.
- Hak akses per aksi (`hasModuleAction`) sudah ada sebagai mekanisme untuk membatasi.
- Jam buka per hari, kapasitas pemasangan per hari, kapasitas layanan ringan per slot, dan radius absen di toko.

## 4. Rancangan target

### 4.1 Prinsip
1. **Satu sumber per master.** Database; spreadsheet hanya untuk impor.
2. **Siklus hidup, bukan hapus.** Aktif lalu nonaktif; hapus hanya untuk data yang salah ketik dan belum dipakai.
3. **Aturan tertulis di tempat data diisi.** Kriteria ukuran muncul di form.
4. **Ada pemilik data.** Siapa boleh mengubah apa ditentukan, perubahan tercatat.
5. **Ukur kelengkapan.** Kekurangan data terlihat sebelum menyebabkan galat.

### 4.2 Model data (usulan)
- `vehicles` ditambah: `is_active`, `body_type` (hatchback, sedan, SUV, MPV, pikap, minibus, lainnya), `year_from` dan `year_to` (generasi, opsional), `notes`. Ukuran tetap `size_category` S sampai XXL.
- `vehicle_glass_areas`: `vehicle_id`, `position` (depan, samping, belakang, sunroof), `area_sqm`, `source` (pengukuran, impor), `measured_at`. Diisi satu kali dari Google Sheet; setelah itu database yang diedit.
- `stores` ditambah: `code` (singkat dan unik), `timezone` (bawaan Asia/Jakarta), `manager_user_id` (opsional).
- Tabel tak berubah: master lain sementara dibiarkan; fase terakhir hanya memeriksa siklus hidupnya per master (bagian 3.1).

### 4.3 Alur target
1. Kendaraan baru diminta (admin atau staf) dan dilengkapi: merek, model, varian, tipe bodi, tahun, ukuran (dengan kriteria terlihat), luas kaca bila ada.
2. Perubahan ukuran atau luas kaca hanya oleh pemilik data (atau lewat permintaan yang disetujui), tercatat di log.
3. Kendaraan yang tidak ditawarkan lagi dinonaktifkan, tidak dihapus; tautan di kendaraan pelanggan tetap utuh.
4. Halaman daftar harga dan penghitung bahan membaca luas kaca dari database.
5. "Pusat Master Data" menampilkan jumlah kendaraan tanpa ukuran atau luas kaca, produk tanpa harga, toko tanpa koordinat atau kode, dan tautan langsung ke perbaikannya.

### 4.4 Fitur dan menu
- **Master Data**: Kendaraan (dengan filter aktif, tanpa ukuran, tanpa luas kaca, impor CSV), Toko/Dealer, dan halaman indeks "Pusat Master Data" yang menautkan master di menu lain tanpa memindahkannya.
- **Mobile**: katalog kendaraan tetap hanya baca; opsional, pengajuan kendaraan baru oleh staf (keputusan 7).
- **Disembunyikan tetap disembunyikan**: Kode Gulungan dan Koefisien Harga (tidak dipakai).

### 4.5 Aturan UI/UX
Satu form satu tujuan; kriteria ukuran tampil di samping pilihan; pesan galat menjelaskan apa yang harus dilakukan ("kendaraan dipakai 12 kendaraan pelanggan; nonaktifkan, jangan hapus"); filter dan pencarian merek/model yang toleran huruf besar kecil; tabel mudah dibaca di layar kecil.

### 4.6 Keandalan dan keamanan
Perubahan ukuran tercatat di log aktivitas dan, bila dipilih, melewati persetujuan; impor massal berjalan sebagai pratinjau lalu simpan dan bisa dibatalkan (mengikuti pola impor produk yang sudah ada); tes otomatis untuk setiap aturan.

### 4.7 Ukuran keberhasilan
- Nol kendaraan aktif tanpa ukuran; persentase kendaraan aktif dengan luas kaca terlihat di Pusat Master Data dan naik.
- Nol selisih nama merek/model antara database dan daftar harga (karena satu sumber).
- Nol penghapusan kendaraan yang memutus tautan kendaraan pelanggan.

## 5. Peta jalan

| Fase | Isi | Hasil |
|---|---|---|
| **0. Kebersihan** | **Dikerjakan (2026-10-21), belum diuji di server.** Teks bantuan ukuran diperbarui; `GET /api/quotation/options` memakai `VehicleCatalogService` (bentuk respons tidak berubah); `VehicleModelSeeder` tidak lagi membuat baris kedua untuk merek+model yang sama (peringatan bila ukurannya berbeda); peran tiga seeder kendaraan didokumentasikan. **Temuan baru:** daftar seeder menyebut `HYUNDAI IONIQ 5` di ukuran S **dan** M (satu-satunya konflik dari 253 entri); pemilik data perlu memutuskan ukuran yang benar. Seeder lama tetap tidak diubah isinya. Semula: Perbaiki teks bantuan usang; satu implementasi katalog; satu seeder; tes pengaman | Tidak ada teks menyesatkan atau logika ganda |
| **1. Siklus hidup kendaraan** | **Dikerjakan (2026-10-21), belum diuji di server.** Kolom `is_active`, `body_type`, `year_from`, `year_to`, `notes` (kendaraan lama semua aktif); aksi Aktifkan/Nonaktifkan (satuan dan massal); filter Status (bawaan: aktif); hapus hanya untuk yang belum dipakai penawaran, studi kasus, atau kendaraan pelanggan (pesan mengarahkan ke Nonaktifkan); katalog publik hanya kendaraan aktif dan merek tanpa kendaraan aktif tidak ditawarkan; panduan ukuran di form menampilkan contoh model nyata per kelas dari data (keputusan pemilik: contoh per kelas). Formulir admin Studi Kasus dan Penawaran masih menampilkan semua kendaraan (tidak diubah). Semula: `is_active`, nonaktifkan menggantikan hapus, peringatan pemakaian, atribut tipe bodi dan tahun, aturan ukuran tertulis di form | Kendaraan bisa diarsipkan tanpa memutus riwayat |
| **2. Luas kaca di database** | **Dikerjakan (2026-10-21), belum diuji di server; bendera `FEATURE_PRICELIST_FROM_DATABASE` MATI bawaan.** Tabel `vehicle_glass_areas` (dikunci merek+model ternormalisasi, bukan varian, mengikuti Sheet); menu Master Data > Luas Kaca (ubah/tambah/hapus: akses penuh); aksi "Impor dari Google Sheet" dengan pratinjau (baru, sama, berubah, bermasalah, ganda, belum ada di katalog Kendaraan) dan pilihan memperbarui yang berbeda (mati bawaan; tidak menimpa tanpa izin); Sheet tidak pernah ditulis. Daftar harga (kalkulator) membaca database hanya bila bendera menyala, dengan jawaban yang sama persis dengan dari Sheet (diuji). Harga produk (sheet "Harga") tidak ikut. Semula: `vehicle_glass_areas`, impor sekali dari Google Sheet dengan pratinjau dan laporan ketidakcocokan nama, daftar harga membaca database | Satu sumber data kendaraan |
| **3. Toko** | **Dikerjakan (2026-10-21), belum diuji di server.** Kolom `stores.code` (singkat, unik, huruf besar otomatis, boleh kosong untuk toko lama; peringatan "Belum ada" dan filter "Belum punya kode toko" di daftar) dan `manager_user_id` (penanggung jawab opsional; mitra tidak ditawarkan). **Zona waktu TIDAK dibuat** (keputusan pemilik: belum ada toko di luar WIB). Semula: kode toko, zona waktu, penanggung jawab | Identitas toko lengkap |
| **4. Hak ubah** | **Dikerjakan (2026-10-21), belum diuji di server.** Keputusan pemilik: pemilik data = akses penuh (tanpa peran baru, tanpa alur pengajuan). Kendaraan dan Luas Kaca: staf dengan akses menu hanya MELIHAT; tambah, ubah, nonaktifkan, hapus butuh akses penuh atau izin aksi eksplisit di Hak Akses Detail (bawaan berubah dari "boleh" ke "tidak"; staf yang selama ini mengubah kendaraan perlu diberi izin bila masih dibutuhkan). Impor dari Google Sheet tetap hanya akses penuh. Semula: pemilik data, perubahan ukuran/luas kaca hanya oleh yang berwenang (atau lewat persetujuan) | Data nasional terlindungi |
| **5. Pusat Master Data** | halaman indeks dan ukuran kelengkapan, widget dashboard yang berguna | Kekurangan data terlihat lebih awal |
| **6. Mobile** | pengajuan kendaraan baru dari HP bila diputuskan | Staf tidak bergantung pada admin web |

### 5.1 Kenapa bertahap
Master kendaraan memengaruhi harga dan booking yang sedang berjalan. Semua perubahan memakai pola tambah-dulu: kolom nullable, bawaan aman (kendaraan lama aktif), sumber lama tetap sampai yang baru terbukti, tes per fase, suite penuh sebelum rilis, dan rencana rilis tertulis.

## 6. Keputusan yang dibutuhkan (dengan rekomendasi)

| # | Pertanyaan | Mengapa penting | Rekomendasi saya |
|---|---|---|---|
| 1 | Luas kaca (m²) dipindahkan ke database dan Google Sheet dipensiunkan sebagai sumber? | Dasar fase 2 | Ya; Sheet dipakai sekali untuk impor |
| 2 | Siapa pemilik data kendaraan (yang boleh mengubah ukuran dan luas kaca)? | Dasar fase 4 | Satu orang atau peran (mis. kepala instalasi atau pemilik), bukan semua staf toko |
| 3 | Kriteria kategori ukuran S sampai XXL (panjang atau tipe bodi) | Menentukan harga | Tetapkan tertulis oleh kepala instalasi; contoh tiap kelas ditampilkan di form |
| 4 | Kendaraan yang tidak ditawarkan lagi: nonaktifkan atau hapus? | Dasar fase 1 | Nonaktifkan; hapus hanya yang belum dipakai |
| 5 | Perlu kode toko dan zona waktu? | Dasar fase 3 | Kode toko ya; zona waktu hanya bila akan ada toko di luar WIB |
| 6 | Perlu impor massal kendaraan dari berkas CSV? | Kecepatan mengisi katalog | Ya, dengan pratinjau |
| 7 | Staf toko boleh mengajukan kendaraan baru dari HP? | Dasar fase 6 | Ya, sebagai pengajuan yang disetujui pemilik data |
| 8 | Perlu halaman "Pusat Master Data"? | Dasar fase 5 | Ya, kecil dan hanya baca |

## 7. Batasan riset

- Hasil riset kaca film saling bertentangan dan berkualitas rendah; tidak ada angka luar yang dipakai.
- Tidak ditemukan standar Indonesia untuk daftar kendaraan bengkel; kriteria ukuran harus berasal dari Ginnva.
- Pencarian MDM berupa artikel umum, bukan panduan khusus usaha kecil.
- Audit kode terhadap `development` per 2026-10-21; data produksi nyata (jumlah kendaraan, kualitas nama, isi Google Sheet) belum diperiksa.

## 8. Sumber
- [Master Data Stewardship](https://geosurge.ai/corpus/gen-3181/master-data-management/master-data-stewardship.html)
- [Dun & Bradstreet: Five best practices of MDM](https://www.dnb.com/en-us/blog/master-data/five-best-practices-master-data-management.html)
- [Ampliz: MDM best practices](https://www.ampliz.com/resources/master-data-management-best-practices/)
- [Auto Care Association: VCdb](https://www.autocare.org/what-we-do/technology/product-areas/vcdb/)
- [MOTOR: VIN to VCdb](https://www.motor.com/wp-content/uploads/2025/08/MOTOR_VIN_to_VCdb_US_ContentDevKit.pdf)
- [DataOne: VIN data vs fitment data](https://vin.dataonesoftware.com/vin_basics_blog/vin-data-vs-vehicle-parts-fitment-data)
- [Flex Film: Automotive window tint measurement guide](https://flexfilmstore.com/pages/tint-measurement-guide)
