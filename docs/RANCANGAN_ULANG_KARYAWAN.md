# Rancangan Ulang Sistem Karyawan (API, Panel, Mobile)

Dibuat 2026-10-17. **Ini rancangan, belum ada yang dibangun.** Cakupan: data karyawan, absensi, jadwal kerja, cuti, penggajian, komisi, kontrak, surat peringatan, dan hal yang belum ada (lembur, tunjangan, BPJS/PPh 21, THR, kompetensi installer, K3). Sasaran: dipakai sungguhan sesuai standar perusahaan dan hukum ketenagakerjaan Indonesia untuk bisnis PPF, kaca film, detailing, dan premium wash; tanpa galat; mudah dipakai.

## Status dan keputusan (diperbarui 2026-10-17)

**Keputusan pemilik 2026-10-17 (jawaban atas pertanyaan awal):**

- **BPJS dan PPh 21 dihitung di luar sistem** (akuntan atau aplikasi lain). Sistem tidak menghitung pajak dan iuran; sistem mencatat hasilnya di slip.
- **Tidak ada lembur.** Fitur lembur tidak dibangun.
- **Jumlah karyawan 10 sampai 99:** Peraturan Perusahaan wajib dan perlu disahkan; P2K3/SMK3 umumnya belum wajib kecuali tempat kerja berisiko tinggi.
- **Ginnva tidak memakai sistem shift dan pola mingguan.** Modul Jadwal Kerja (shift, pola, penugasan, override, kalender) tidak relevan; lihat bagian 3.4.
- **Karyawan mengikuti jadwal operasional tetap toko** (jam buka toko; konfirmasi pemilik 2026-10-17). Telat, pulang cepat, dan Alpha dihitung dari jam dan hari buka toko.
- **Hari dan jam kerja (2026-10-17):** Senin-Jumat 08.30-17.00 (Senin-Kamis istirahat 12.00-13.00, Jumat istirahat 11.30-13.00); Sabtu 09.00-13.00 (istirahat 12.00-13.00); Minggu tutup. Enam hari per minggu, sekitar 40 jam kerja efektif per minggu.
- **Aturan potongan (disepakati 2026-10-17), rumus di bagian 4.8:** telat dipotong per **blok penuh 15 menit** setelah toleransi bebas **15 menit per bulan** (akumulatif); contoh Rp50.000 per blok. **Alpha dipotong berdasarkan jam kerja**: tarif per jam = gaji pokok dibagi total jam kerja terjadwal sebulan, dikali jam kerja hari itu (Sabtu 3 jam, Senin-Kamis 7,5 jam, Jumat 7 jam). **Pulang cepat tidak dipotong**, hanya dicatat.
- **THR dan uang kompensasi PKWT: masih dihitung akuntan** (belum yakin dihitung di sistem); sistem mencatat hasilnya.
- **Perhitungan BPJS/PPh 21 di sistem: DITUNDA (2026-10-17).** Pemilik belum yakin dan meminta tidak dibangun dulu. Berlaku keputusan semula: dihitung di luar sistem, sistem hanya mencatat hasilnya. Analisis (BPJS di sistem, PPh 21 dengan masa jalan paralel) tetap tersimpan di bagian 6 butir 1 bila diangkat lagi.

**Progres Fase 0 (2026-10-28):** aturan potongan telat dan Alpha (bagian 4.8) sudah ditulis, tetapi **potongan Alpha mengikuti keputusan terbaru pemilik:** tarif per jam x jam kerja hari itu **ditambah denda tetap per hari Alpha**, dan telat per blok penuh dari TOTAL menit sebulan **tanpa toleransi bebas** (14 menit = Rp0, 20 menit = 1 blok, 40 menit = 2 blok, 45 menit = 3 blok). Nominal (ukuran blok, potongan per blok, denda Alpha) diisi staf di menu Karyawan > Penggajian > Aturan Potongan Absensi, dengan tanggal berlaku; aturan yang sudah dipakai payroll terkunci. Jam istirahat per hari diisi di Jam Operasional toko. **Berkas sensitif dan enkripsi (2026-10-28):** selfie absen, lampiran izin/sakit, dan scan surat peringatan kini disimpan di disk privat dan dibuka lewat tautan sementara; berkas lama dipindah dengan `php artisan files:move-sensitive-to-private` (boleh `--dry-run`); NIK, NPWP, nomor BPJS, dan nomor rekening terenkripsi di database (migrasi mengenkripsi data yang ada; **APP_KEY wajib disimpan bersama cadangan**, karena tanpa itu data identitas tidak terbaca). **Sisa Fase 0 selesai ditulis (2026-10-28):** libur nasional dan cuti bersama **ditentukan manual oleh staf** (keputusan pemilik), jadi tidak ada kalender libur tersendiri: Tanggal Tidak Tersedia yang sudah ada dipakai, ditambah aksi **Tutup Semua Toko** untuk menutup semua toko aktif sekaligus; Jadwal Kerja disembunyikan lewat flag `FEATURE_WORK_SCHEDULES` (bawaan mati, menu panel dan layar aplikasi); halaman **Kelengkapan Data Karyawan** menunjukkan data wajib (gaji, toko, tanggal bergabung, tipe, kontrak, jam operasional toko) dan yang disarankan (rekening, NIK, NPWP, BPJS, kontak darurat).

Pertanyaan yang masih terbuka ada di bagian 6. Keputusan lama yang tetap berlaku dan **tidak diubah** oleh rancangan ini:

1. Semua installer pada satu booking tim mendapat **komisi penuh masing-masing**, tanpa peran Lead/Asisten (2026-09-22).
2. CEO, CCO, dan CFO setara sebagai `direksi` (akses penuh); tidak ada routing persetujuan khusus CFO (2026-09-29).
3. **Absen offline tidak dibangun** (2026-10-13); jalur pemulihan adalah Koreksi Absen dan input manual admin.
4. Persetujuan keuangan (termasuk Payroll) dipegang akses penuh; pengajuan oleh Spv Finance (2026-09-27).

## 1. Ringkasan

Sistem Karyawan Ginnva sudah luas (absensi dengan GPS dan selfie, cuti/izin/sakit, koreksi absen, jadwal kerja, payroll dengan approval dan jurnal, komisi teknisi, surat peringatan, kontrak, riwayat karir, dokumen karyawan, tes otomatis yang banyak). Fondasi bagus, tetapi untuk dipakai **sebagai sistem penggajian dan kepegawaian yang sah**, ada tiga kelompok masalah:

1. **Slip gaji belum memuat komponen yang dibayar sebenarnya.** Payroll hanya menghitung gaji pokok, potongan telat/alpha, dan komisi. Belum ada tempat untuk tunjangan, potongan BPJS dan PPh 21 (yang dihitung akuntan di luar sistem), THR, uang kompensasi kontrak, kasbon, dan potongan/tambahan lain, sehingga angka di sistem bisa berbeda dari yang benar-benar dibayarkan.
2. **Aturan yang tidak jelas dapat menghasilkan potongan salah.** Contoh: toleransi keterlambatan dipakai dua kali; libur nasional tidak dikenal. Modul Jadwal Kerja yang tidak dipakai Ginnva menambah menu dan layar yang membingungkan.
3. **Data sensitif dan hal yang belum ada.** Surat sakit, selfie absen, dan surat peringatan tersimpan di penyimpanan publik; NIK, NPWP, dan rekening tersimpan tanpa enkripsi. Tidak ada catatan kompetensi/pelatihan installer, K3, onboarding/offboarding, atau penilaian kinerja, padahal kualitas PPF dan kaca film bergantung pada keterampilan.

Rekomendasi urutan (disesuaikan dengan jawaban pemilik): perbaiki yang berisiko salah dan rapikan yang tidak relevan (Fase 0), slip gaji yang cocok dengan akuntan (Fase 1), absensi dan cuti yang lengkap serta Profil Saya di mobile (Fase 2), siklus kepegawaian dan Peraturan Perusahaan (Fase 3), kompetensi installer (Fase 4), K3 dan kinerja (Fase 5).

## 2. Riset: standar dan aturan

Catatan keandalan: sebagian besar angka berasal dari artikel praktisi (HR/pajak) karena teks resmi tidak selalu muncul di hasil pencarian. **Semua angka wajib diverifikasi ke teks resmi atau konsultan pajak/ketenagakerjaan sebelum dijadikan aturan di sistem.** Tanda *[lemah]* = hanya satu sumber sekunder atau sumber saling berbeda.

### 2.1 Pengupahan dan penggajian
- **PPh 21 bulanan** memakai tarif efektif rata-rata (TER) menurut PMK 168/2023 dengan kategori A, B, C sesuai status PTKP; **masa Desember dihitung ulang** dengan tarif Pasal 17 dan dikurangi pemotongan Januari-November. THR atau gaji ke-13 yang dibayar bersama gaji menaikkan penghasilan bruto bulan itu. Tarif progresif tahunan 5/15/25/30/35%; sumber menyatakan tidak ada perubahan tarif 2026. Tabel TER lengkap **tidak** ada di hasil riset; harus diambil dari lampiran PMK. [S1]
- **BPJS Ketenagakerjaan:** JHT 5,7% (3,7% perusahaan + 2% karyawan); JP 3% (2% + 1%); JKK 0,24-1,74% (perusahaan, sesuai risiko); JKM 0,30% (perusahaan); JKP besarannya tidak terverifikasi. **BPJS Kesehatan:** 5% (4% perusahaan + 1% karyawan) dengan batas upah Rp12.000.000 *[lemah]*. Batas upah JP 2026 dilaporkan Rp11.086.300 sejak 1 Maret 2026 *[lemah]* (sebelumnya Rp10.547.400). JHT tidak punya batas atas. [S2]
- **Lembur (PP 35/2021):** maksimal 4 jam/hari dan 18 jam/minggu pada hari kerja; waktu kerja normal 7 jam/hari (6 hari) atau 8 jam/hari (5 hari), 40 jam/minggu; upah sejam = 1/173 upah sebulan; hari kerja: jam pertama 1,5x lalu 2x; hari libur lebih tinggi (6 hari kerja: 2x sampai jam ke-7 dan 3x jam ke-8; skema 5 hari berbeda); dasar upah = pokok + tunjangan tetap; makan dan minum minimal 1.400 kkal bila lembur 4 jam atau lebih (tidak boleh diganti uang). [S3]
- **THR keagamaan (Permenaker 6/2016):** berlaku untuk PKWT dan PKWTT dengan masa kerja minimal 1 bulan; 12 bulan atau lebih = 1 bulan upah, kurang dari itu proporsional (masa kerja/12 x 1 bulan upah); magang tidak berhak. [S4]
- **Struktur dan skala upah** wajib disusun pengusaha dan dikomunikasikan ke pekerja (Permenaker 1/2017, status setelah UU Cipta Kerja perlu dicek); komponen slip: upah pokok, tunjangan tetap, tunjangan tidak tetap; upah pokok sedikitnya 75% dari total upah *[lemah]*. [S11]
- **Potongan karena denda keterlambatan** boleh bila diatur dalam perjanjian kerja, peraturan perusahaan, atau PKB; batas potongan dilaporkan maksimal 50% dari tiap pembayaran upah *[lemah]*. Isi Pasal 40 dan 57 PP 36/2021 **tidak** terkonfirmasi. [S10]
- **Upah minimum 2026:** UMP DKI Jakarta Rp5.729.876; UMK Kabupaten Tangerang Rp5.210.377 atau Rp5.219.789 (**dua sumber berbeda**), Kota Tangerang sekitar Rp5.399.406. Perlu dicek ke keputusan gubernur dan dipakai sebagai batas bawah gaji pokok. [S9]

### 2.2 Hubungan kerja, cuti, peringatan
- **PKWT:** paling lama 5 tahun termasuk perpanjangan; tidak untuk pekerjaan tetap; berakhir dengan **uang kompensasi** (bukan pesangon), dibayar sebelum perpanjangan dimulai. [S5]
- **Cuti tahunan:** sedikitnya 12 hari kerja setelah 12 bulan bekerja terus-menerus, dibayar penuh, pelaksanaan diatur perjanjian/peraturan perusahaan; **cuti melahirkan** 3 bulan (1,5 sebelum dan 1,5 sesudah); **cuti haid** hari pertama dan kedua bila sakit; **sakit** mengikuti surat dokter dan tidak memotong cuti tahunan. Isi pasal tidak terkonfirmasi dari teks resmi. [S12]
- **Surat peringatan (PP 35/2021 Pasal 52):** masing-masing berlaku 6 bulan kecuali diatur lain; berurutan SP1, SP2, SP3; bila lewat 6 bulan, pelanggaran baru kembali SP1; SP3 bukan sendirinya PHK. Aturan "mangkir 5 hari kerja" **tidak** terkonfirmasi. [S13]
- **Peraturan Perusahaan** wajib untuk perusahaan dengan 10 pekerja atau lebih (belum ada PKB) dan harus disahkan. **P2K3 dan SMK3** umumnya wajib untuk 100 pekerja atau lebih atau risiko tinggi. [S14]
- **Perubahan hukum yang akan datang:** Putusan MK 168/PUU-XXI/2023 memerintahkan undang-undang ketenagakerjaan baru **dalam 2 tahun (batas dilaporkan 31 Oktober 2026)**; sumber menyebut upah, lembur, dan cuti termasuk yang diatur ulang, dan satu berita menyebut UU baru sudah disahkan (belum terkonfirmasi). Konsekuensi desain: **aturan upah, lembur, cuti, dan potongan harus bisa diatur di pengaturan, bukan ditulis tetap di kode.** [S15]

### 2.3 Keselamatan kerja (K3) di bengkel otomotif
- Standar OSHA untuk bengkel otomotif menempatkan **komunikasi bahaya (lembar data keselamatan bahan, SDS), APD, dan perlindungan pernapasan** sebagai temuan paling sering. Pelatihan harus tercatat. Pemaparan isosianat dan pelarut umum di pengecatan; relevansi ke bahan kaca film/coating Ginnva **tidak terkonfirmasi** (cek SDS produk). Ini standar AS dan dipakai sebagai pembanding, bukan hukum Indonesia. [S7]

### 2.4 Kompetensi installer
- Produsen besar (contoh XPEL) menjalankan pelatihan installer bertingkat (dasar dan lanjutan; kursus beberapa hari dengan praktik pada panel). Sertifikasi itu **khusus produsen**, bukan lisensi pemerintah atau standar independen; tidak ditemukan kerangka tingkat keterampilan independen untuk PPF. Karena Ginnva memakai film sendiri, matriks keterampilan internal per layanan adalah pendekatan yang masuk akal. [S6]

### 2.5 Skema upah teknisi
- Industri mengenal upah per jam, **flat-rate** (waktu baku per pekerjaan), dan **gabungan gaji + insentif**; sumber menyatakan gabungan sering dianggap terbaik. Contoh pengupahan detailer berupa tarif per jam baku; sumbernya anekdot lama. Tidak ditemukan sumber tentang KPI pekerjaan ulang. Skema Ginnva sekarang (gaji pokok + komisi tetap per pekerjaan) sudah berbentuk gabungan. [S8]

### 2.6 Absensi
- Praktik umum: perimeter geofence (sering 100-200 m), swafoto atau biometrik, jejak waktu, dan penyimpanan lokal untuk offline; data biometrik menimbulkan isu privasi. Ginnva sudah memiliki GPS, deteksi lokasi palsu, selfie, dan Koreksi Absen berjejak. Tidak ditemukan sumber tentang aturan koreksi/riwayat edit yang baku. [S16]

### 2.7 Perlindungan data karyawan
- UU PDP (27/2022) berlaku penuh sejak 17 Oktober 2024 dan mencakup data karyawan (NIK, NPWP, riwayat gaji, kesehatan, log absen). Data kesehatan (surat sakit) tergolong data pribadi spesifik. Aturan masa simpan dokumen kepegawaian **tidak** terkonfirmasi. [S17]

## 3. Audit sistem sekarang

### 3.1 Yang sudah baik (dipertahankan)
- Absensi dari aplikasi dengan radius GPS, penanda lokasi palsu, selfie wajib, hitung telat dan pulang cepat, pengingat lupa absen/lupa keluar, Koreksi Absen dengan persetujuan dan jejak.
- Jadwal Kerja: shift, pola 7 hari, penugasan dengan riwayat, kalender mingguan, override harian; **shift sudah dipakai menghitung telat**.
- Payroll dengan approval berjenjang, pembukuan ke Jurnal Umum, pengunci balapan data, slip PDF, 27 tes.
- Komisi teknisi per pekerjaan dan laporan utilisasi/durasi; kebijakan komisi penuh sudah konsisten.
- Cuti: kuota tahunan diprorata per bulan kerja; izin dan sakit tidak memotong cuti.
- Kontrak: pengingat H-30 dan penonaktifan otomatis saat berakhir; riwayat karir; dokumen karyawan di penyimpanan **privat**.
- Cakupan tes besar (Attendance, Payroll, Leave, Schedule, Technician, Warning, Contract).

### 3.2 Temuan dibanding standar
| # | Area | Kondisi sekarang (bukti) | Standar / risiko | Ukuran |
|---|---|---|---|---|
| 1 | Komponen gaji | `Payroll`: hanya gaji pokok (prorata), potongan telat/alpha, komisi; tidak ada tunjangan, lembur, bonus, kasbon | Struktur upah: pokok, tunjangan tetap/tidak tetap; slip rinci | **B** |
| 2 | BPJS dan PPh 21 | NIK, NPWP, nomor BPJS tersimpan di profil tetapi **tidak dipakai** `Payroll::generateForMonth`; tidak ada iuran maupun pajak | Dihitung akuntan **di luar sistem** (keputusan 2026-10-17): sistem hanya perlu **mencatat** potongan BPJS/PPh 21 di slip | **M** |
| 3 | Lembur | Absen mencatat masuk/keluar, tetapi **tidak ada** perhitungan lembur | **Gugur:** tidak ada lembur di Ginnva (2026-10-17); tidak dibangun | - |
| 4 | THR dan kontrak | Tidak ada THR; `contracts:deactivate-expired` menonaktifkan PKWT yang berakhir tanpa menghitung **uang kompensasi**; masa percobaan tidak dimodelkan | Permenaker 6/2016; PP 35/2021 | **M** |
| 5 | Alpha vs jadwal | `MarkAbsences` tidak membaca jadwal pribadi | **Gugur:** Ginnva tidak memakai shift, jadi semua karyawan mengikuti jam buka toko dan perilaku ini sudah sesuai. Yang perlu dibereskan adalah modul jadwal yang tidak relevan (bagian 3.4) | - |
| 6 | Toleransi telat dipakai dua kali (kebijakan: 15 menit per bulan) | `late_minutes` sudah dikurangi toleransi harian (`Attendance::calculateLateMinutes`), lalu Payroll menjumlahkan menit itu dan membandingkan lagi dengan toleransi yang sama | Maksud kebijakan perlu dipastikan; kemungkinan potongan terlalu kecil | **K-M** (butuh keputusan) |
| 7 | Libur nasional | Hanya Tanggal Diblokir per toko (manual); tidak ada kalender libur nasional/cuti bersama | Memengaruhi alpha dan hari kerja (penyebut gaji harian) | **M** |
| 8 | Jenis cuti | Hanya cuti, izin, sakit; tidak ada cuti haid, melahirkan, ibadah, keluarga (menikah dsb.), cuti bersama; sakit tanpa syarat surat dokter yang bisa diatur | Hak cuti menurut UU 13/2003 | **M** |
| 9 | Data sensitif | Surat sakit (`leave-requests`), selfie (`attendance-photos`), surat peringatan (`warning-letters`) disimpan di disk **publik**; NIK, NPWP, rekening tanpa enkripsi (tidak ada cast `encrypted`); selfie tanpa masa simpan | UU PDP: data kesehatan dan identitas; akses terbatas, masa simpan | **B (keamanan)** |
| 10 | Kompetensi installer | `Technician.level` hanya label dan `commission_amount`; tidak ada keterampilan per layanan, sertifikasi, pelatihan, atau tinjauan SOP | Kualitas PPF/kaca film bergantung keterampilan; pelatihan K3 wajib tercatat | **B** |
| 11 | Mutu per installer | Hasil Quality Check booking tersedia, tetapi tidak dikaitkan ke installer; tidak ada ukuran pekerjaan ulang | KPI mutu dan pembelajaran | **M** |
| 12 | K3 | Tidak ada catatan pembagian APD, pelatihan K3, insiden/kecelakaan, atau SDS bahan | Bahan kimia dan alat panas/tajam; JKK | **M-B** |
| 13 | Siklus karyawan | Hanya "Nonaktifkan"; tidak ada daftar periksa onboarding/offboarding, pengembalian aset, masa percobaan | Standar HR; aset tetap sudah ada modulnya | **M** |
| 14 | Mandiri karyawan (mobile) | Layar: absen, cuti, koreksi, slip, jadwal, surat peringatan; **tidak ada Profil Saya** (data diri, kontrak, masa kerja, sisa cuti, dokumen), tidak ada riwayat lembur/kasbon | Layanan mandiri karyawan | **M** |
| 15 | Aturan dan dokumen perusahaan | Potongan telat/alpha tertulis di sistem, tetapi tidak ada tempat menyimpan Peraturan Perusahaan atau bukti karyawan menerimanya | Potongan denda sah bila diatur di PK/PP/PKB; PP wajib untuk 10+ pekerja | **M** |
| 16 | Kinerja | Tidak ada penilaian berkala; ada laporan utilisasi dan durasi sebagai bahan | Evaluasi periodik dasar kenaikan gaji/promosi | **S-M** |
| 17 | Hierarki atasan | Persetujuan menurut peran, tidak menurut atasan langsung | Opsional | **S** |
| 18 | Ringkasan HR | Tidak ada dasbor komposisi tim, kontrak berakhir, ulang tahun kerja, tingkat absen | Pemantauan | **S** |

Ukuran: **S** kecil, **M** menengah, **B** besar, **K** kecil dampaknya.

### 3.3 Yang belum bisa dipastikan dari kode (perlu data Anda)
- Jumlah karyawan sekarang dan tipenya (tetap, kontrak, magang, lepas), apakah sudah ada Peraturan Perusahaan atau PKB.
- **Apakah gaji, BPJS, dan PPh 21 sekarang dihitung di luar sistem** (aplikasi lain atau akuntan) dan seperti apa slip yang dipakai.
- Apakah installer dibayar lembur, dan bagaimana kebijakan hari kerja (5 atau 6 hari, jam kerja shift).
- Apakah jadwal kerja sudah benar-benar diisi (berdampak ke temuan 5).
- Siapa yang bertanggung jawab K3 dan bahan apa yang dipakai (SDS).

### 3.4 Pembaruan setelah jawaban pemilik (2026-10-17)
- **Jadwal Kerja tidak relevan.** Ginnva tidak punya sistem shift dan pola mingguan. Modul itu dibangun dari audit Majoo (2026-09-22) tetapi tidak sesuai cara kerja Ginnva (lihat juga aturan relevansi: jangan mengikuti desain Majoo mentah-mentah). Yang terdampak: menu Shift, Jadwal Kerja, dan Kalender Jadwal di panel; layar **Jadwal Kerja di aplikasi staf yang tampil untuk SEMUA karyawan** (selalu terlihat), yang bagi Ginnva hanya akan kosong; perhitungan telat memakai shift **bila ada** (sekarang jatuh ke jam buka toko, jadi tetap benar); laporan Utilisasi Teknisi **memakai jam buka toko, bukan shift**, jadi tidak terdampak (sudah dicek di kode). **Usulan:** sembunyikan layar dan menu jadwal (bukan dihapus dulu), pastikan laporan pola absensi yang membaca jadwal tetap bermakna atau disembunyikan, lalu putuskan apakah modulnya dipensiunkan.
- **Lembur tidak dibangun.** Absen keluar tetap dicatat untuk pulang cepat dan lupa absen keluar.
- **Payroll tidak menghitung pajak dan iuran.** Sistem menyediakan komponen yang diisi manual atau diimpor dari hasil akuntan, supaya slip dan jurnal sama dengan yang dibayarkan.
- **Peraturan Perusahaan wajib** (10 sampai 99 karyawan). Potongan telat dan alpha harus tertulis di sana, dan sistem perlu menyimpan dokumennya serta bukti karyawan menerimanya.

## 4. Rancangan target

### 4.1 Prinsip
1. **Benar dulu, lengkap kemudian:** perbaiki yang berisiko salah (Fase 0) sebelum menambah fitur.
2. **Aturan di pengaturan, bukan di kode:** tarif lembur, batas, persentase iuran, jenis cuti, dan potongan dapat diubah tanpa developer (hukum masih berubah).
3. **Satu sumber kebenaran per angka;** slip gaji bisa ditelusuri ke absen, lembur, komisi, dan komponen lain.
4. **Perubahan bersifat tambahan,** aplikasi yang beredar tetap jalan.
5. **Data sensitif dilindungi:** disk privat, akses berperan, enkripsi untuk identitas dan rekening, masa simpan jelas.
6. **Mudah dipakai:** karyawan melihat semua miliknya dari satu tempat; admin tidak mengetik ulang.
7. Setiap fase dirilis sendiri dengan tes dan daftar uji, dan statusnya dilaporkan jujur.

### 4.2 Model data (usulan, tambahan)
| Entitas | Isi | Catatan |
|---|---|---|
| `public_holidays` | tanggal, nama, jenis (nasional/cuti bersama), berlaku untuk toko | Dipakai absensi, alpha, hari kerja, lembur |
| `overtime_requests` | karyawan, tanggal, jam, alasan, status, persetujuan | Dihitung dari absen keluar atau diajukan; batas dari pengaturan |
| `payroll_components` + `employee_components` | tunjangan/potongan per karyawan (tetap/tidak tetap), berlaku dari-sampai | Slip membaca dari sini |
| Kolom `payrolls` / rincian baris slip | lembur, tunjangan, BPJS karyawan dan perusahaan, PPh 21, THR, kasbon | Rincian per baris, bukan satu angka |
| `payroll_settings` | persentase iuran, batas upah, tarif lembur, kategori PTKP/TER | Dapat diubah tanpa developer |
| `employee_loans` | pinjaman/kasbon dan cicilan | Dipotong otomatis dari slip |
| Kolom `users` | status kerja (tetap/kontrak/magang), masa percobaan, PTKP, penanggung jawab | Enkripsi untuk NIK/NPWP/rekening |
| `leave_types` | jenis cuti, hak, dibayar atau tidak, wajib surat, jatah | Menggantikan daftar tetap cuti/izin/sakit |
| `skills` + `employee_skills` | keterampilan per layanan (PPF, kaca film, detailing, premium wash), tingkat, tanggal dinilai | Dasar penugasan dan tingkat |
| `trainings` + `employee_trainings` | pelatihan, sertifikasi internal/produsen, berlaku sampai | Termasuk pelatihan K3 |
| `safety_equipment_issues`, `incidents` | pembagian APD, kejadian/kecelakaan | K3 |
| `employee_onboarding` / `offboarding` checklist | butir dan status | Termasuk pengembalian aset |
| `performance_reviews` | periode, nilai, catatan | Opsional |
| `company_policies` + `policy_acknowledgments` | dokumen aturan perusahaan dan bukti diterima | Dasar potongan sah |

### 4.3 Alur target (ringkas)
**Harian:** jadwal (shift atau libur) -> absen masuk/keluar -> telat/pulang cepat dihitung menurut shift -> lembur (diajukan/dihitung, disetujui atasan) -> hari tanpa absen: Alpha **hanya bila hari kerja menurut jadwal dan bukan libur/cuti**.
**Bulanan:** tutup absen -> hitung gaji (pokok, tunjangan, lembur, komisi) -> potongan (telat/alpha, iuran, PPh 21, kasbon) -> periksa -> diajukan -> disetujui direksi -> dibayar dan diposting -> slip lengkap ke karyawan.
**Tahunan:** THR sebelum hari raya; hitung ulang PPh 21 di Desember; tinjau kontrak berakhir (uang kompensasi); evaluasi kinerja.
**Siklus:** rekrut -> onboarding (dokumen, APD, pelatihan, penerimaan aturan, aset) -> masa percobaan -> tetap/kontrak -> perpanjangan -> offboarding (aset, hak, nonaktifkan).
**Installer:** keterampilan per layanan -> penugasan sesuai -> QC dan pekerjaan ulang terhubung -> tingkat dan komisi sesuai kebijakan.

### 4.4 Fitur dan menu
**Panel admin (cluster Karyawan):** Kalender Libur Nasional; Lembur (pengajuan dan persetujuan); Pengaturan Penggajian; Komponen Gaji; Kasbon; Payroll dengan rincian lengkap dan slip; THR; Keterampilan dan Pelatihan; K3 (APD, insiden); Onboarding/Offboarding; Kebijakan Perusahaan; Dasbor HR.
**Mobile (karyawan):** **Profil Saya** (data, kontrak, masa kerja, sisa cuti, dokumen), Jadwal dan Absen dalam satu alur, Lembur (ajukan dan lihat), Slip gaji rinci, Kasbon, jenis cuti yang lengkap, pelatihan dan sertifikat saya, penerimaan kebijakan.
**API:** endpoint untuk semua di atas; pengambilan berkas sensitif lewat otorisasi, bukan URL publik.

### 4.8 Aturan potongan telat dan Alpha (disepakati 2026-10-17)
Jam kerja per hari diambil dari pengaturan toko (jam buka, jam tutup, dan **jam istirahat**, yang perlu ditambahkan per hari di pengaturan toko): Senin-Kamis 08.30-17.00 dikurangi istirahat 12.00-13.00 = 7,5 jam; Jumat 08.30-17.00 dikurangi 11.30-13.00 = 7 jam; Sabtu 09.00-13.00 dikurangi 12.00-13.00 = 3 jam; Minggu dan tanggal diblokir tidak dihitung.

- **Telat:** menit telat tiap hari dicatat apa adanya (tanpa toleransi harian). Dalam satu bulan: menit dipotong = total menit telat dikurangi **15 menit** (toleransi bebas); jumlah blok = hasil bagi **bulat ke bawah** (hanya blok penuh) dengan 15; potongan = blok x jumlah per blok (pengaturan toko, contoh Rp50.000). Contoh: total telat 40 menit -> (40-15)=25 menit -> 1 blok -> Rp50.000; total 14 menit -> Rp0; total 45 menit -> 2 blok -> Rp100.000.
- **Alpha:** tarif per jam = gaji pokok / total jam kerja terjadwal bulan itu (jumlah jam kerja semua hari buka). Potongan Alpha satu hari = tarif per jam x jam kerja hari itu. Contoh: bulan 4 minggu penuh = 160 jam; gaji Rp5.200.000 -> Rp32.500 per jam; Alpha Sabtu = 3 x 32.500 = Rp97.500; Alpha Senin = 7,5 x 32.500 = Rp243.750.
- **Pulang cepat:** dicatat saja, tidak dipotong.
- **Hari bergabung di tengah bulan** (prorata) memakai jam kerja terjadwal sejak tanggal bergabung dibagi total jam bulan itu, konsisten dengan rumus di atas.
- Semua angka (toleransi, ukuran blok, jumlah per blok) disimpan di pengaturan, bukan di kode. Aturan ini harus tertulis di Peraturan Perusahaan agar potongan sah.

### 4.5 Aturan UI/UX
- Satu layar per tugas: absen cepat dua ketukan; ajukan cuti memilih jenis dan langsung melihat sisa hak dan dampaknya.
- Angka uang selalu menunjukkan rinciannya (ketuk untuk melihat asalnya); status berwarna dan berlabel.
- Pesan galat menjelaskan perbaikan; tidak ada layar kosong tanpa petunjuk.
- Ukuran sentuh dan kontras mengikuti panduan yang sudah dipakai; tidak ada elemen yang terhimpit (contoh: baris filter yang baru diperbaiki).

### 4.6 Keandalan dan keamanan
- Semua perhitungan uang di dalam transaksi dengan kunci baris dan tes; payroll periode terkunci setelah dibayar.
- Berkas sensitif di disk privat dengan tautan sementara; NIK, NPWP, rekening terenkripsi dan disamarkan di tampilan.
- Masa simpan dan penghapusan untuk selfie, surat sakit, dan dokumen; akses tercatat.
- Aturan upah di pengaturan dengan riwayat perubahan; tes memuat contoh hitungan resmi (lembur, TER, THR).

### 4.7 Ukuran keberhasilan
Tidak ada potongan yang salah dalam uji bulan paralel; slip cocok dengan perhitungan akuntan; waktu admin menutup payroll turun; tidak ada berkas sensitif yang bisa dibuka tanpa login; karyawan dapat melihat semua haknya dari aplikasi.

## 5. Peta jalan (disesuaikan dengan jawaban pemilik 2026-10-17)

| Fase | Isi | Terlihat |
|---|---|---|
| **0. Perbaiki dan rapikan** | Berkas sensitif (surat sakit, selfie, surat peringatan) ke penyimpanan privat dan enkripsi NIK/NPWP/rekening; **aturan potongan telat (blok 15 menit, toleransi 15 menit per bulan) dan Alpha per jam kerja sesuai 4.8, termasuk jam istirahat di pengaturan toko dan penyesuaian jam buka tiap toko**; kalender libur nasional; sembunyikan Jadwal Kerja yang tidak relevan (layar mobile dan menu panel) dan pastikan telat dan laporan pola absensi benar tanpa jadwal; pemeriksaan data (karyawan tanpa gaji/toko/kontrak) | Tidak ada potongan salah; data aman; menu sesuai cara kerja Ginnva |
| **1. Slip gaji yang cocok dengan akuntan** | **Ditulis (2026-10-29), belum diuji di server.** Komponen gaji per slip (tambahan: tunjangan, bonus, THR, uang kompensasi; potongan: BPJS karyawan, PPh 21, kasbon, potongan lain) yang angkanya dihitung akuntan di luar sistem dan hanya dicatat; **kategori dengan akun pembukuan yang bisa diatur akuntan** di menu Karyawan > Penggajian > Kategori Komponen Gaji (akun baru 6130 Beban Tunjangan & Bonus, 6140 Beban THR, 6150 Beban Kompensasi Kontrak, 2180 Hutang BPJS, 2190 Hutang Potongan Lainnya; PPh 21 ke 2130, kasbon ke 1120); input manual lewat aksi Komponen Gaji di tiap slip draft plus Salin dari Bulan Lalu; jurnal gaji memuat tiap komponen dan tetap balance; slip PDF dan aplikasi menampilkan komponen. **Tidak dibangun (keputusan pemilik):** iuran BPJS bagian perusahaan, modul pinjaman dengan saldo, impor Excel dari akuntan. |
| **2. Absensi dan cuti lengkap, Profil Saya** | **Ditulis (2026-10-30), belum diuji di server.** Menu Karyawan > Cuti & Kontrak > Jenis Izin & Cuti: jenis yang bisa diatur staf (maks. hari, lampiran wajib mulai N hari, memotong jatah tahunan atau tidak); bawaan Cuti Tahunan, Izin, Sakit (surat dokter wajib mulai 2 hari), Haid, Melahirkan, Keguguran, Menikah, Menikahkan/Khitan anak, Istri melahirkan, Keluarga meninggal, Ibadah (angka bawaan dari UU 13/2003 menurut sumber sekunder, **perlu dicocokkan dengan Peraturan Perusahaan**); cuti khusus tidak memotong jatah tahunan; aplikasi memilih jenis dari server; layar **Profil Saya** (masa kerja, kontrak, sisa cuti, data diri tersamar, dokumen). Semula: Jenis cuti lengkap (haid, melahirkan, ibadah, keluarga), sakit dengan surat dokter, cuti bersama dan libur, sisa hak jelas; **Profil Saya** di mobile (data diri, kontrak, masa kerja, sisa cuti, dokumen); alur absen sederhana | Karyawan melihat semua haknya dari aplikasi |
| **3. Siklus kepegawaian dan Peraturan Perusahaan** | Penyimpanan Peraturan Perusahaan dan bukti diterima, onboarding/offboarding, masa percobaan, aturan PKWT (maks. 5 tahun, kompensasi), surat peringatan terhubung (masa berlaku 6 bulan), struktur dan skala upah | Kepegawaian tertib dan potongan sah |
| **4. Kompetensi installer** | Matriks keterampilan per layanan (PPF, kaca film, detailing, premium wash), pelatihan dan sertifikasi, penugasan sesuai keterampilan, mutu (QC dan pekerjaan ulang) per installer untuk pembinaan | Kualitas terukur |
| **5. K3 dan kinerja** | APD, pelatihan K3, insiden, penilaian berkala, dasbor HR | Aman dan terpantau |

Fase 0 dikerjakan lebih dulu karena memperbaiki risiko yang sudah ada tanpa menambah fitur besar. Lembur dan perhitungan pajak/iuran tidak masuk peta jalan.

## 6. Keputusan yang dibutuhkan (dengan rekomendasi)

| # | Pertanyaan | Status | Rekomendasi saya |
|---|---|---|---|
| 1 | Di mana BPJS dan PPh 21 dihitung? | **Dijawab:** di luar sistem. Menghitung di sistem **ditunda** (pemilik belum yakin, 2026-10-17) | Sistem mencatat hasilnya. Bila diangkat lagi: BPJS di sistem (persentase dan batas upah di pengaturan), PPh 21 TER dengan masa jalan paralel 2-3 bulan terhadap akuntan dan tabel TER yang diverifikasi akuntan |
| 2 | Lembur | **Dijawab:** tidak ada lembur | Tidak dibangun |
| 3 | Jumlah karyawan | **Dijawab:** 10-99 | Peraturan Perusahaan wajib dan perlu disahkan |
| 4 | Jadwal kerja (shift) | **Dijawab:** tidak dipakai | Sembunyikan dulu, putuskan pensiun modul setelah dicek dampak ke laporan pola absensi |
| 5 | Hari dan jam kerja | **Dijawab:** Senin-Jumat 08.30-17.00, Sabtu 09.00-13.00, Minggu tutup | Pastikan jam buka tiap toko di sistem persis sama (jam masuk per hari menentukan telat) |
| 6 | Toleransi dan potongan telat | **Dijawab:** toleransi 15 menit per bulan; potongan per blok penuh 15 menit (contoh Rp50.000) | Rumus di 4.8 |
| 7 | Potongan telat dan alpha | **Dijawab:** telat per blok 15 menit; Alpha per jam kerja; pulang cepat tidak dipotong | **Tuliskan di Peraturan Perusahaan** dan simpan bukti diterima |
| 8 | THR dan uang kompensasi PKWT | **Dijawab:** masih dihitung akuntan | Sistem mencatat hasilnya di slip |
| 9 | Bentuk komponen gaji (tunjangan, bonus, kasbon) | Terbuka | Komponen per karyawan yang diisi tiap bulan, dengan salinan bulan lalu |
| 10 | Keterampilan installer | Terbuka | **Matriks per layanan** (PPF, kaca film, detailing, premium wash) |
| 11 | Komisi dan mutu | Terbuka | Pertahankan komisi penuh; mutu hanya untuk **pembinaan**, bukan pemotongan |
| 12 | Absensi | Tetap | Tidak ada perubahan; absen offline tetap ditunda |
| 13 | Data sensitif ke penyimpanan privat dan enkripsi | Terbuka | **Ya** (Fase 0) |
| 14 | Penilaian kinerja | Terbuka | **Ditunda ke Fase 5** |

## 7. Batasan riset
- Pencarian berbahasa Indonesia dan Inggris, sebagian besar dari artikel praktisi; teks resmi PMK 168/2023, PP 35/2021, PP 36/2021, dan Permenaker tidak diperoleh langsung. **Tidak menggantikan nasihat konsultan pajak atau ketenagakerjaan.**
- Tabel TER lengkap, batas upah BPJS 2026 yang resmi, angka UMK Kabupaten Tangerang yang pasti, dan status undang-undang ketenagakerjaan baru **belum terverifikasi**.
- K3 diambil dari standar OSHA sebagai pembanding; kewajiban Indonesia (P2K3, SMK3) hanya dari ringkasan.
- Praktik pengupahan detailer berasal dari forum lama dan bersifat anekdot.

## 8. Sumber
- **[S1]** [Cara hitung PPh 21 bulanan dengan TER (DDTC)](https://news.ddtc.co.id/literasi/tips-trik/1803939/cara-hitung-pph-pasal-21-bulanan-pegawai-tetap-di-kalkulator-ddtcnews), [Cara menghitung THR dan simulasi pajak 2026 (Bisnis)](https://kabar24.bisnis.com/read/20260227/243/1956376/cara-menghitung-thr-karyawan-dan-simulasi-pajak-di-tahun-2026), [Cara perhitungan PPh 21 (Online Pajak)](https://www.online-pajak.com/id/cara-perhitungan-pph-21)
- **[S2]** [Iuran BPJS Ketenagakerjaan (BPJS Ketenagakerjaan)](https://www.bpjsketenagakerjaan.go.id/artikel/18913/artikel-berapa-besaran-iuran-jht,-jkk,-jkm,-jp-dan-jkp), [Potongan BPJS 2026 (Dealls)](https://dealls.com/pengembangan-karir/potongan-bpjs-ketenagakerjaan), [Harga BPJS karyawan 2026 (Kantorku)](https://kantorku.id/blog/harga-bpjs-karyawan-per-bulan), [Batas upah Jaminan Pensiun](https://www.talenta.co/blog/iuran-jaminan-pensiun-terbaru/)
- **[S3]** [Lembur hari libur (Hukumonline)](https://www.hukumonline.com/klinik/a/lembur-pada-hari-libur-mingguan-dan-atau-libur-resmi-lt4c286f2b3a1b8/), [Rumus menghitung lembur (Gadjian)](https://www.gadjian.com/blog/2021/07/28/rumus-menghitung-lembur-karyawan-terbaru/)
- **[S4]** [THR karyawan kontrak (Gadjian)](https://www.gadjian.com/blog/2023/03/22/cara-perhitungan-thr-karyawan-kontrak/), [THR magang (Bisnis)](https://ekonomi.bisnis.com/read/20250307/12/1859293/pekerja-magang-dapat-thr-atau-tidak-ini-penjelasan-kemnaker)
- **[S5]** [Pengaturan PP PKWT dan PHK (Hukumonline)](https://www.hukumonline.com/berita/a/begini-pengaturan-lengkap-pp-pkwt-phk-lt603395220adef), [Uang kompensasi PKWT (Gadjian)](https://www.gadjian.com/blog/2022/11/07/uang-kompensasi-karyawan-pkwt/)
- **[S6]** [Pelatihan dan sertifikasi installer XPEL](https://www.xpel.com/training-certifications)
- **[S7]** [Persyaratan OSHA untuk bengkel (Mass.gov)](https://www.mass.gov/info-details/understanding-osha-requirements-for-auto-shops), [OSHA di bengkel body (FIT)](https://www.fit.edu/media/site-specific/wwwfitedu/environmental-health-and-safety-ehs/documents/OSHA-in-Auto-Body-Shops_-Respiratory-Protection,-Hazard-Communication-Are-Most-Frequently-Cited-Violations.pdf)
- **[S8]** [Hourly vs flat rate (Autoleap)](https://autoleap.com/blog/hourly-rate-vs-flat-rate-how-auto-mechanics-are-paid/), [Detail/prep pay plans (forum)](https://forums.dealersedge.com/viewtopic.php?p=47162)
- **[S9]** [UMP dan UMK Jabodetabek 2026 (Metro TV)](https://www.metrotvnews.com/read/bmRCYvo2-daftar-umpumk-di-jabodetabek-tahun-2026-bekasi-tertinggi), [UMK dan UMP 2026 (Industry.co.id)](https://www.industry.co.id/read/155730/standar-gaji-di-indonesia-2026-daftar-lengkap-ump-38-provinsi-dan-umk-jabodetabek)
- **[S10]** [Potong gaji karena terlambat (Hukumonline)](https://www.hukumonline.com/klinik/a/gaji-dipotong-karena-terlambat-masuk-kerja-lt52fc407948dc9)
- **[S11]** [Struktur dan skala upah (Gadjian)](https://www.gadjian.com/blog/2020/11/20/perbedaan-struktur-dan-skala-upah-karyawan/), [Komponen gaji (Talenta)](https://www.talenta.co/blog/ragam-komponen-gaji-tidak-tetap-karyawan-di-indonesia)
- **[S12]** [Jenis cuti karyawan (HashMicro)](https://www.hashmicro.com/id/blog/jenis-cuti/), [Aturan cuti karyawan swasta (Kantorku)](https://kantorku.id/blog/aturan-cuti-karyawan-swasta/)
- **[S13]** [Aturan pemberian surat peringatan (Hukumonline)](https://www.hukumonline.com/klinik/a/aturan-pemberian-surat-peringatan-karyawan-lt54f683eedcdf2), [Panduan menyusun PP dan PKB (Talenta)](https://www.talenta.co/blog/panduan-penyusunan-pp-pkb-phk-tahan-uji-phi/)
- **[S14]** [PP 50/2012 SMK3](https://peraturan.go.id/files/pp50-2012bt.pdf), [Permenaker 13/2025](https://peraturan.go.id/files/permenaker-no-13-tahun-2025.pdf), [Kewajiban peraturan perusahaan](https://news.sah.co.id/kewajiban-perusahaan-dalam-membuat-peraturan-perusahaan/?amp=1)
- **[S15]** [Putusan MK 168/PUU-XXI/2023 (MKRI)](https://www.mkri.id/public/content/persidangan/putusan/putusan_mkri_11215_1730375514.pdf), [Implikasi putusan MK (SIP Law Firm)](https://siplawfirm.id/putusan-mk/?lang=id), [Update UU Cipta Kerja 2026](https://blog.smartsalarypro.com/update-uu-cipta-kerja-2026/)
- **[S16]** [Geofencing attendance (FactoHR)](https://factohr.com/geofencing-attendance-system/), [Mobile time tracking (MiHCM)](https://mihcm.com/resources/blog/mobile-time-tracking-app-self-service-attendance-guide/), [Mencegah buddy punching (OpenTimeClock)](https://www.opentimeclock.com/blog2026/how-to-prevent-buddy-punching-1.html)
- **[S17]** [UU PDP dan HR (Talenta)](https://www.talenta.co/en/blog/hr-uu-pdp-compliance-roadmap/)
