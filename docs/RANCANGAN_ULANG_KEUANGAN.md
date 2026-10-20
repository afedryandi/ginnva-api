# Rancangan Ulang Sistem Keuangan Ginnva

Dibuat 2026-11-04. **Ini rancangan dan audit, belum ada yang dibangun.** Cakupan: data, alur kerja, fitur, menu, dan UI/UX di panel admin, API, dan aplikasi mobile untuk usaha PPF, kaca film, detailing, dan premium wash. Sasaran: dipakai sungguhan sesuai standar akuntansi dan perpajakan Indonesia, angka laporan bisa dipercaya, tanpa galat, dan mudah dipakai.

## 1. Ringkasan

Fondasi Keuangan Ginnva **sudah jauh lebih lengkap dari sistem kecil pada umumnya**: Bagan Akun (COA) 70+ akun, Jurnal Umum dengan debit-kredit seimbang dan pembalikan (reversal), tutup periode bulanan dengan daftar periksa, Laba Rugi, Neraca, Arus Kas, Buku Besar, Neraca Saldo, Hutang dan Piutang beserta aging, rekonsiliasi bank dengan impor mutasi, penyusutan aset, tagihan berulang, persetujuan pengeluaran berjenjang, refund, DP booking, dan banyak tes.

Namun ada **celah mendasar yang membuat angka laporan belum bisa dianggap sebagai pembukuan yang benar**, dan hampir tidak ada fitur keuangan di mobile:

1. **HPP (beban pokok) tidak pernah dijurnal.** Hanya perkiraan di laporan penjualan. Laba kotor di Laba Rugi dan nilai persediaan di Neraca keduanya tidak akurat.
2. **Semua uang masuk dan keluar dicatat ke satu akun "Kas di Tangan" (1101)**, termasuk transfer bank. Tidak ada pemisahan kas toko dan rekening bank, sehingga rekonsiliasi bank membandingkan dengan akun yang bukan rekening bank sebenarnya.
3. **PPN hanya rincian informasi di booking** (tarif 11% tertulis di kode, asumsi harga sudah termasuk). Tidak ada jurnal PPN Keluaran/Masukan, tidak ada data faktur pajak, tidak ada rekap untuk SPT Masa.
4. **Tidak ada penutupan tahun buku** (laba tahun berjalan tidak dipindah ke Laba Ditahan; hanya ditampilkan sebagai baris virtual "belum ditutup").
5. **Tidak ada**: pajak penghasilan (PPh 23 dipotong pelanggan, PPh final/badan), kas kecil per toko, anggaran dan realisasi, ekspor paket untuk akuntan, dan **tidak ada layar keuangan di aplikasi mobile** selain slip gaji dan permohonan pembelian.

Keputusan pemilik yang dibutuhkan ada di bagian 6. Beberapa angka pajak di bagian 2 **belum terverifikasi ke teks resmi dan sebagian saling bertentangan**; semuanya harus diperiksa konsultan pajak sebelum dijadikan aturan di sistem.

## 2. Riset: standar dan aturan

Catatan keandalan: pencarian hanya menemukan skripsi, artikel praktisi, dan situs berita pajak; teks resmi PMK, PP, dan SAK dari IAI **tidak** muncul. Tanda *[lemah]* = satu sumber sekunder atau sumber saling berbeda. **Semua angka wajib diverifikasi ke konsultan pajak/akuntan sebelum dijadikan aturan.**

### 2.1 Standar akuntansi
- SAK EMKM (IAI, terbit 2016, efektif 2018) dipakai usaha mikro/kecil/menengah; studi kasus bengkel menunjukkan masalah umum: pencatatan kas masuk-keluar saja, penyusutan aset tetap tidak dicatat, laporan posisi keuangan dan catatan atas laporan keuangan tidak lengkap, pengendalian internal lemah. Ginnva sudah melampaui pola itu, tetapi **tidak diketahui apakah Ginnva memakai SAK EMKM atau SAK lain**; menentukan standar adalah keputusan akuntan/pemilik. [S1]
- Pembayaran di muka untuk jasa yang belum dikerjakan dicatat sebagai **liabilitas (pendapatan diterima di muka)**, baru diakui pendapatan saat jasa diserahkan. Sistem Ginnva sudah mengikuti pola ini untuk DP (akun 2140). Perlakuan menurut SAK EMKM tidak terkonfirmasi dari sumber. [S2]
- Praktik usaha tint/PPF/wrap: pisahkan **pendapatan dan HPP bahan per lini layanan sejak faktur pertama**; tanpa pemisahan tidak terlihat lini mana yang menutup gaji. KPI umum: margin kotor per layanan, persentase biaya bahan, jam tagih rata-rata per pekerjaan, utilisasi teknisi. Angka patokan margin (tint 50-80%, PPF/wrap 45-65%) berasal dari templat vendor dan **tidak boleh dipakai sebagai target** tanpa data Ginnva sendiri. *[lemah]* [S3]

### 2.2 Pajak (semua perlu verifikasi) *[lemah]*
- **PPN:** untuk barang/jasa kena pajak bukan mewah, tarif 12% dikalikan DPP nilai lain 11/12 dari harga jual (PMK 131/2024), sehingga **beban efektif 11% dari harga jual (DPP)**. Kode faktur pajak untuk skema ini disebut 04. Coretax disebut belum menghitung DPP 11/12 otomatis (status 2026 tidak terkonfirmasi). Sistem Ginnva memakai "11%" tertulis di kode; angkanya setara, tetapi **istilah, kolom DPP nilai lain, dan nomor faktur belum ada**. [S4]
- **Batas pengusaha kena pajak (PKP):** Rp4,8 miliar per tahun menurut siaran pers DJP 2014 (PMK 197/2013); usulan Bank Dunia 2026 menurunkannya menjadi Rp500 juta masih **usulan**. Apakah Ginnva wajib atau memilih menjadi PKP **tidak diketahui**. Apakah jasa pemasangan kaca film/PPF/detailing kena PPN tidak terjawab dari sumber. [S5]
- **PPh final UMKM 0,5% (PP 55/2022):** batas peredaran Rp4,8 miliar; untuk PT berlaku 3 tahun sejak NPWP terdaftar. Satu laporan menyebut **PP 20/2026 mencabut fasilitas ini untuk PT non-perorangan** (diganti tarif umum dengan pengurangan), sumber DDTC lain tidak menyebutnya: **sumber bertentangan**. Status pajak Ginnva menentukan apakah sistem perlu menghitung PPh sama sekali. [S6]
- **PPh Pasal 23:** jasa tertentu (teknik, manajemen, konstruksi, konsultan, dst.) 2% dari bruto, dipotong oleh pembayar badan; yang dipotong menerima bukti potong dan mengakuinya sebagai **pajak dibayar di muka (aset)**, bukan beban. Tarif 2% berlaku untuk jenis jasa yang disebut dalam peraturan; **apakah jasa instalasi Ginnva termasuk jenis itu tidak terkonfirmasi**. Relevan bila ada pelanggan korporasi (armada, dealer). [S7]
- **Penyimpanan pembukuan:** buku, catatan, dan dokumen dasar pembukuan wajib disimpan **10 tahun** di Indonesia (Pasal 28 ayat 11 UU KUP, dilaporkan); untuk badan di tempat kedudukan; dokumen elektronik termasuk. Konsekuensi desain: data dan lampiran keuangan **tidak boleh dihapus permanen** dan harus ikut cadangan. [S8]

### 2.3 Pengendalian internal
- **Kas kecil sistem imprest:** saldo selalu tetap, pengisian ulang sebesar yang terpakai, bukti (nota/kuitansi) dikumpulkan dan diverifikasi bagian akuntansi, peran dipisah: yang menyetujui dana, yang memegang kas, yang memverifikasi, yang memeriksa. Studi kasus menemukan kelemahan pada pemisahan tugas, kelengkapan dokumen, dan otorisasi. [S9]
- **Tutup buku bulanan:** daftar periksa dengan satu penanggung jawab per langkah; rekonsiliasi bank sebaiknya rutin (mingguan), bukan sekali sebulan; pisahkan penyusun, pemeriksa, dan penyetuju; selisih kecil di bawah ambang diperlakukan sebagai penyesuaian. Satu sumber vendor *[lemah]*. [S10]

## 3. Keadaan sekarang (audit kode, 2026-11-04)

### 3.1 Yang sudah ada dan kuat
| Area | Isi |
|---|---|
| Bagan Akun | 70+ akun terstruktur (aset, kewajiban, modal, pendapatan per layanan, HPP, beban per kelompok), klasifikasi arus kas per akun, akun kontra |
| Jurnal | Jurnal Umum draft/posted, seimbang, pembalikan, nomor, referensi sumber, kunci baris di dalam transaksi |
| Pendapatan booking | Dijurnal dengan pecahan per jenis layanan (PPF, kaca film, detailing, premium wash), DP lewat 2140, piutang otomatis, penggantian bila nominal berubah |
| Hutang/Piutang | Pembuatan, pembayaran parsial, aging, pengingat jatuh tempo, tagihan berulang |
| Periode | Daftar periksa tutup bulan (jurnal draft, tidak seimbang, persetujuan tertunda, payroll, mutasi bank belum cocok, penyusutan), buka kembali dengan alasan, perubahan di periode tertutup ditolak |
| Laporan | Laba Rugi, Neraca, Arus Kas, Buku Besar, Neraca Saldo, aging, penjualan per periode/produk, refund |
| Pengendalian | Persetujuan pengeluaran berjenjang (staf, manajer, direksi), refund/referral butuh persetujuan direksi, jejak aktivitas, tes otomatis banyak |
| Aset | Daftar aset, penyusutan terjadwal diposting, mutasi aset |
| Bank | Impor mutasi, pencocokan dengan jurnal, jejak status |

### 3.2 Temuan (terverifikasi di kode kecuali ditandai)
| # | Temuan | Bukti | Dampak | Prioritas |
|---|---|---|---|---|
| 1 | **HPP tidak dijurnal.** Akun 5100/5200 tidak pernah diposting; hanya `BookingCogsService` yang menghitung perkiraan untuk laporan penjualan. Pemakaian bahan baku dan barang habis pakai tidak mengurangi akun persediaan 1130/1131/1132 (hanya pembelian dan penghapusan stok yang menyentuhnya) | `grep` kode: 5100/5200 tidak diposting; 1130/1131 hanya di pembelian dan write-off | Laba kotor terlalu tinggi, persediaan di Neraca terus membengkak, margin per layanan tidak bisa dipercaya | **T** |
| 2 | **Hanya satu akun kas (1101)** dipakai booking, transaksi, hutang/piutang (default), payroll, refund, DP. Transfer bank ke rekening perusahaan tercatat sebagai "Kas di Tangan". Akun bank 1102 ada tetapi tidak pernah dipakai otomatis | `CASH_ACCOUNT_CODE = '1101'` di 8 layanan | Neraca menunjukkan kas yang tidak ada di laci, rekonsiliasi bank membandingkan akun yang salah, tidak ada kas per toko | **T** |
| 3 | **PPN hanya rincian informasi** (`dpp_amount`, `ppn_amount`), tarif 0,11 tertulis di `Booking::PPN_RATE`. Tidak ada jurnal PPN Keluaran, PPN Masukan tidak dicatat dari pembelian, tidak ada data faktur pajak | `BookingPostingService` mengkredit seluruh nominal ke pendapatan | Bila Ginnva PKP: pendapatan terlalu tinggi dan kewajiban PPN tidak tercatat; bila bukan PKP: tulisan "PPN 11%" di laporan menyesatkan | **T** (bergantung status PKP) |
| 4 | **Tidak ada penutupan tahun buku.** Laba tahun berjalan tidak dipindah ke Laba Ditahan (3200); ditampilkan sebagai baris virtual | `FinancialStatementService` baris "belum ditutup" | Neraca tahun kedua mengandalkan tampilan virtual; sulit diaudit | S |
| 5 | **Akun payroll bentrok.** `PayrollItemCategory::ensureDefaults()` memakai akun yang sudah ada di seeder: 6130 bernama "Beban Tunjangan & BPJS" dan 6140 "Beban Bonus/Insentif", sehingga tunjangan/bonus masuk 6130 dan **THR masuk 6140 "Beban Bonus/Insentif"**, bukan "Beban THR" seperti tertulis di rancangan Karyawan Fase 1 | Seeder 6130/6140 vs `ensureDefaults` | Salah label di laporan; perlu diputuskan akuntan | S |
| 6 | **Tidak ada pajak penghasilan**: PPh 23 yang dipotong pelanggan korporasi tidak punya tempat pencatatan (jadi selisih pembayaran piutang), PPh final/badan tidak ada selain akun 8100 kosong | `grep` pph tidak ada | Piutang tampak kurang bayar; rekap pajak manual | S |
| 7 | **Persetujuan pengeluaran: semua nominal wajib direksi.** Aman, tetapi pengeluaran kecil harian (parkir, bensin, ATK) ikut antre ke direksi | Keputusan 2026-09-15 | Hambatan harian; mendorong pengeluaran tidak dicatat | S |
| 8 | **Tidak ada kas kecil per toko**, tidak ada anggaran vs realisasi | Tidak ada model | Pengeluaran kecil tidak terkendali | S |
| 9 | **Mobile: tidak ada fitur keuangan** selain slip gaji dan permohonan pembelian (tidak ada catat pengeluaran dengan foto struk, ringkasan harian, persetujuan, status piutang) | `routes/api.php` | Pencatatan menunggu admin di panel | S |
| 10 | **Tidak ada ekspor paket untuk akuntan** (ditunda pemilik 2026-09-22 untuk 11 halaman laporan) | Memori proyek | Akuntan eksternal mengetik ulang | R |
| 11 | **Rekonsiliasi kas fisik** (hitung laci/penutupan kas harian per toko) tidak ada; rekonsiliasi shift kasir menunggu POS | Memori proyek | Selisih kas tidak terdeteksi harian | R |
| 12 | **Penghapusan data keuangan**: belum diperiksa apakah ada jalur hapus permanen yang melanggar masa simpan 10 tahun *(perlu audit lanjutan)* | Belum diaudit | Risiko hukum | S |

Prioritas: **T** = membuat angka laporan salah, **S** = standar/kendali penting, **R** = rapikan.

Yang **belum diaudit** dan akan dilakukan di Fase 0: alur Hutang/Piutang secara rinci, refund, tagihan berulang, nomor jurnal dan ketahanan ganda-posting, perilaku zona waktu pada tanggal jurnal, hak akses per peran di menu Keuangan, dan konsistensi saldo awal.

## 4. Rancangan target

### 4.1 Prinsip
1. **Benar dulu, lengkap kemudian**: perbaiki yang membuat angka salah (HPP, kas/bank, PPN) sebelum menambah fitur.
2. **Satu sumber kebenaran**: laporan dari jurnal; sub-buku (persediaan, piutang, hutang, aset) selalu bisa direkonsiliasi ke akun kontrolnya.
3. **Aturan di pengaturan, bukan di kode**: tarif pajak, akun default per metode bayar, batas persetujuan, ambang selisih rekonsiliasi.
4. **Tidak ada penghapusan permanen** data keuangan; koreksi lewat pembalikan.
5. **Pemisahan tugas**: yang membuat, memeriksa, dan menyetujui orang berbeda; nominal besar naik tingkat.
6. **Dihitung akuntan, dicatat sistem** untuk pajak yang rumit (selaras keputusan Karyawan: BPJS/PPh 21 di luar sistem) kecuali pemilik memutuskan lain.
7. Setiap fase dirilis sendiri dengan tes dan daftar uji.

### 4.2 Model data (usulan, tambahan)
| Entitas | Isi | Catatan |
|---|---|---|
| `payment_accounts` | akun kas/bank per toko atau pusat: nama, jenis (kas toko, bank), akun COA, toko, rekening | Menggantikan 1101 tunggal |
| Kolom `bookings.payment_account_id` atau pemetaan `payment_method -> akun` | Tujuan uang tiap pembayaran | Pembayaran ganda (DP + pelunasan) bisa beda rekening |
| `inventory_cost_postings` (atau jurnal bersumber `inventory_usage`) | Posting HPP per booking/penggunaan bahan | Debit 5100/5200, kredit 1130/1131/1132 |
| `tax_settings` | status PKP, tanggal pengukuhan, tarif PPN, skema DPP, tarif PPh, mode pencatatan | Dapat diubah akuntan |
| `tax_documents` | faktur pajak keluaran/masukan: nomor, tanggal, DPP, PPN, kode, pihak, NPWP | Hanya jika PKP |
| `withholding_slips` | bukti potong PPh yang dipotong pelanggan (PPh 23): nomor, tanggal, nilai, piutang terkait | Mengurangi piutang tanpa jadi selisih |
| `petty_cash_funds` + `petty_cash_vouchers` | dana kas kecil per toko, pengeluaran dengan foto bukti, pengisian ulang | Sistem imprest |
| `budgets` | anggaran per akun/kelompok per bulan per toko | Anggaran vs realisasi |
| `year_end_closings` | penutupan tahun buku | Memindah laba ke 3200 |
| `cash_counts` | hitung kas fisik harian per toko dan selisih | Rekonsiliasi harian |
| `finance_attachments` | lampiran bukti (privat) terhubung ke jurnal/transaksi | Disimpan 10 tahun |

### 4.3 Alur target (ringkas)
**Harian (toko):** booking selesai -> pembayaran masuk ke rekening yang benar (tunai ke kas toko, transfer ke bank) -> pendapatan dan (bila PKP) PPN keluaran dijurnal -> pemakaian bahan menjurnal HPP -> pengeluaran kecil lewat kas kecil dengan foto struk -> hitung kas akhir hari dan catat selisih.
**Mingguan:** rekonsiliasi bank; tinjau piutang jatuh tempo; hutang yang akan jatuh tempo.
**Bulanan:** daftar periksa tutup bulan (sudah ada) ditambah: HPP sinkron dengan stok opname, PPN dicocokkan, kas kecil diisi ulang, penyusutan, payroll; tutup periode; paket laporan untuk akuntan.
**Tahunan:** stok opname akhir tahun, penutupan tahun buku, rekap pajak untuk akuntan.

### 4.4 Fitur dan menu
**Panel (cluster Keuangan):** Rekening Kas & Bank; Kas Kecil (dana, voucher, pengisian ulang); Pajak (Pengaturan Pajak, Faktur Pajak, Bukti Potong); Anggaran; Penutupan Tahun; Hitung Kas Harian; Dasbor Keuangan (kas per rekening, piutang jatuh tempo, hutang jatuh tempo, pengeluaran menunggu persetujuan, margin per layanan); Paket Akuntan (ekspor jurnal, buku besar, neraca saldo, Laba Rugi, Neraca, aging dalam satu berkas).
**API/mobile:** (peran manajer/direksi/kasir sesuai hak) catat pengeluaran kas kecil dengan foto struk, daftar dan keputusan persetujuan, ringkasan keuangan harian toko (kas, penjualan, piutang), hitung kas harian, status piutang pelanggan.
**Aturan UI/UX:** satu layar per tugas; angka selalu bisa ditelusuri ke jurnal; istilah akuntansi dijelaskan singkat; form pengeluaran maksimal lima isian; kamera langsung untuk bukti; status berwarna dan berlabel; pesan galat menjelaskan perbaikan.

### 4.5 Keandalan
Semua posting uang di dalam transaksi dengan kunci baris dan tes (pola sudah ada); idempotensi posting (kunci referensi); tes rekonsiliasi sub-buku ke akun kontrol (persediaan, piutang, hutang, aset) yang berjalan terjadwal dan memberi peringatan; cadangan database memuat lampiran keuangan; masa simpan 10 tahun.

## 5. Peta jalan (usulan; menunggu keputusan di bagian 6)

| Fase | Isi | Terlihat |
|---|---|---|
| **0. Audit lanjutan dan perbaiki** | **Dikerjakan (2026-11-04), belum diuji di server.** Hasil audit lanjutan: JournalEntryService, ReceivableService, dan pola hapus data dinilai kuat; ditemukan dan diperbaiki (1) nomor jurnal bisa bentrok bila dua proses membuat jurnal bersamaan (kolom unik, sekarang diulang otomatis), (2) transaksi keuangan yang dihapus kini dihapus lunak agar tersimpan 10 tahun, (3) akun payroll bentrok (bonus ke 6140 bawaan seeder, THR ke akun baru 6145), (4) tidak ada pemeriksaan sub-buku ke Buku Besar: ditambah halaman Rekonsiliasi Sub-Buku (Piutang, Hutang, Aset Tetap, Persediaan). Catatan: bawaan `APP_TIMEZONE` di config dan `.env.example` masih UTC (production sudah Asia/Jakarta); tanggal jurnal memakai `now()` sehingga server baru yang lupa mengisinya salah tanggal 00.00-07.00 WIB. Refund, tagihan berulang (idempotensi lewat source_key, kunci baris, catch-up terbatas), dan penyusutan (cek duplikat per bulan) juga diaudit dan tidak ditemukan galat; catatan saja: refund langsung mengurangi akun pendapatan sesuai proporsi jurnal asli dan tidak memakai akun kontra 4900 Retur, sehingga Laba Rugi hanya menampilkan pendapatan bersih. Belum diaudit: hak akses per peran di tiap menu Keuangan. Semula: Audit bagian yang belum diperiksa (3.2 akhir); perbaiki bentrok akun payroll; jalur hapus data keuangan; tes rekonsiliasi sub-buku ke akun kontrol; peringatan | Daftar galat dan celah yang sudah dipastikan, tanpa fitur baru |
| **1. Rekening kas & bank** | **Dikerjakan (2026-11-05), belum diuji di server.** Menu Rekening Kas & Bank (rekening terhubung ke satu akun kas di Bagan Akun, per toko atau pusat, metode bayar yang diterima, rekening bawaan, nomor rekening terenkripsi, tidak bisa dihapus), Pemindahan Kas (antar akun kas/bank, dibatalkan dengan jurnal pembalik), dan Saldo Kas & Bank. Pembayaran booking, refund booking, gaji (transfer), DP, dan transaksi keuangan memilih akun kas menurut metode bayar dan toko lewat `PaymentAccountResolver`; **tanpa rekening terdaftar semuanya tetap ke akun 1101 seperti sebelumnya**. DP mencatat rekening penerimanya dan dikembalikan dari rekening yang sama (DP lama = 1101). Piutang/Hutang mengusulkan rekening toko di form pelunasan. **Belum ada:** pilihan rekening di form DP booking (memakai rekening bawaan toko; menunggu penyambungan ke alur persetujuan DP), rekonsiliasi bank yang menyaring per rekening di layar impor. Semula: `payment_accounts`; pembayaran booking, DP, piutang, hutang, refund, payroll, transaksi memilih rekening menurut metode bayar dan toko; pemindahan kas antar rekening; saldo awal; rekonsiliasi bank memakai akun bank sebenarnya | Kas toko dan bank terpisah, rekonsiliasi benar |
| **2. HPP dan persediaan** | **Dikerjakan (2026-11-06), belum diuji di server; MATI bawaan (FEATURE_INVENTORY_GL).** HPP tiap booking selesai dijurnal otomatis dari pemakaian bahan (Debit 5100 untuk film dan bahan baku, 5200 untuk barang habis pakai; Kredit 1132 gulungan film, 1130 bahan baku, 1131 barang habis pakai), satu jurnal aktif per booking yang dibalik dan dibuat ulang bila pemakaian berubah; selisih Stok Opname dijurnal ke 6520; perintah `cogs:sync` harian jam 05.00 mengejar pemakaian yang baru tercatat; peringatan HPP di daftar periksa tutup bulan; tombol **Sinkronkan Persediaan** di Rekonsiliasi Sub-Buku (satu jurnal penyesuaian dengan akun lawan yang dipilih manusia: 3200/3100/5100/5200/6520) untuk saldo awal persediaan dan koreksi periodik; Rekonsiliasi Sub-Buku kini juga membandingkan gulungan film (1132). **Urutan menyalakan: (1) Sinkronkan Persediaan dengan akun lawan 3200 setelah akuntan setuju, (2) isi FEATURE_INVENTORY_GL=true, (3) `config:cache`, (4) `cogs:sync --days=45`.** Batasan: nilai memakai harga modal terkini bahan (bukan FIFO per batch) dan harga beli gulungan; selisih ke sub-buku dikoreksi lewat Sinkronkan Persediaan; penerimaan stok di luar Permohonan Pembelian tidak dijurnal; belum ada laporan margin per layanan baru (Laba Rugi kini memuat HPP). Semula: Jurnal HPP otomatis dari pemakaian bahan (gulungan film, bahan baku, barang habis pakai), rekonsiliasi persediaan ke stok opname, margin per layanan | Laba kotor dan persediaan akurat |
| **3. Pajak** | Pengaturan pajak; bila PKP: PPN keluaran/masukan, faktur pajak, rekap; PPh 23 dipotong pelanggan; rekap untuk akuntan | Pajak tercatat benar, rekap siap untuk akuntan |
| **4. Pengendalian kas harian** | Kas kecil imprest, hitung kas harian, batas persetujuan per tingkat, anggaran vs realisasi | Pengeluaran kecil tertib dan terkendali |
| **5. Mobile keuangan** | Catat pengeluaran dengan foto, persetujuan, ringkasan harian, hitung kas | Keuangan toko dari HP |
| **6. Penutupan dan laporan** | Penutupan tahun buku, Dasbor Keuangan, Paket Akuntan (ekspor), daftar periksa tutup bulan diperluas | Tutup tahun dan serahkan ke akuntan tanpa mengetik ulang |

## 6. Keputusan yang dibutuhkan (dengan rekomendasi)

| # | Pertanyaan | Mengapa penting | Rekomendasi saya |
|---|---|---|---|
| 1 | Apakah PT Ginnva **PKP** (menerbitkan faktur pajak)? Apakah harga di daftar harga sudah termasuk PPN? | Menentukan apakah PPN dijurnal, faktur pajak dibuat, dan "PPN 11%" tampil di laporan | Bila PKP: bangun Fase 3 penuh. Bila bukan: **hapus tulisan PPN dari laporan** dan tidak membangun faktur pajak |
| 2 | Pajak penghasilan dihitung di mana (PPh final/badan, PPh 23)? | Selaras BPJS/PPh 21: dihitung di sistem atau akuntan | **Akuntan menghitung, sistem mencatat** (bukti potong dan rekap); tidak membangun penghitung pajak |
| 3 | Berapa rekening bank dan kas yang dipakai, per toko atau pusat? Pembayaran transfer masuk ke rekening mana? | Dasar Fase 1 | Satu kas per toko + rekening bank per PT; metode bayar menentukan tujuan |
| 4 | HPP dijurnal per booking (otomatis dari pemakaian bahan) atau bulanan dari stok opname? | Dasar Fase 2; otomatis lebih rinci tapi butuh data pemakaian akurat | **Otomatis per booking** dengan koreksi bulanan dari stok opname |
| 5 | Persetujuan pengeluaran: tetap semua nominal wajib direksi, atau ada batas di mana manajer cukup? | Hambatan harian | Sediakan **kas kecil dengan batas** (manajer menyetujui di bawah batas), nominal besar tetap direksi; batas diisi pemilik |
| 6 | Apakah perlu kas kecil per toko (dana tetap + isi ulang)? | Dasar Fase 4 | Ya |
| 7 | Siapa yang memakai keuangan di mobile (kasir, manajer, direksi) dan untuk apa? | Dasar Fase 5 | Kasir: catat pengeluaran + hitung kas; manajer: setujui + ringkasan toko; direksi: ringkasan semua toko |
| 8 | Apakah ada akuntan eksternal dan format apa yang dibutuhkan? | Dasar Paket Akuntan; Ekspor Laporan sebelumnya ditunda | Satu paket Excel/PDF per bulan; **jawaban Anda menentukan apakah ini dikerjakan** |
| 9 | Standar akuntansi: SAK EMKM atau lain? Akuntan menyetujui bagan akun yang ada? | Penamaan akun, penyajian laporan | Akuntan memeriksa Bagan Akun sebelum rilis |
| 10 | Apakah anggaran (budget) per bulan dibutuhkan? | Dasar anggaran | Ditunda sampai Fase 0-2 selesai |

## 7. Batasan riset
- Pencarian berbahasa Indonesia dan Inggris, sebagian besar skripsi dan artikel praktisi; teks resmi PMK 131/2024, PP 55/2022, PP 20/2026, UU KUP, dan SAK EMKM tidak diperoleh langsung. **Tidak menggantikan nasihat konsultan pajak atau akuntan.**
- Status PKP, status PPh final PT, perlakuan PPN untuk jasa kaca film/PPF/detailing, dan jenis jasa yang kena PPh 23 **belum terverifikasi**.
- Angka margin dan KPI bisnis tint/PPF berasal dari templat vendor, bukan survei, dan sebagian terlihat rusak (garbled); dipakai hanya sebagai daftar metrik, bukan target.

## 8. Sumber
- **[S1]** Studi kasus SAK EMKM pada bengkel: [Fifa Wijaya Motor (jurnal)](https://jurnal.ardenjaya.com/index.php/ajsh/article/view/1479), [Bengkel Berkat Motor (UMM)](https://eprints.umm.ac.id/9835/), [Bengkel Garasinos (PNJ)](https://repository.pnj.ac.id/20807/1/HalamanIdentitasSkripsi_Naila%20Ramadhanti.pdf)
- **[S2]** [Pengakuan pendapatan jasa dan pendapatan diterima di muka (Jurnal.id)](https://www.jurnal.id/id/blog/service-revenue/)
- **[S3]** [Pembukuan toko tint dan wrap (Beancount)](https://beancount.io/blog/2026/07/09/vehicle-wrap-window-tint-shop-bookkeeping-guide), [KPI PPF](https://financialmodelslab.com/blogs/kpi-metrics/paint-protection-film), [KPI window tint](https://financialmodelslab.com/blogs/kpi-metrics/window-tint-production)
- **[S4]** [DPP nilai lain 11/12 dan kode 04 (DDTC)](https://news.ddtc.co.id/berita/nasional/1807950/ppn-dihitung-dengan-dpp-1112-dari-harga-jual-djp-kode-fakturnya-04), [Coretax belum menghitung otomatis (DDTC)](https://news.ddtc.co.id/berita/nasional/1808421/djp-coretax-belum-bisa-hitung-ppn-dengan-dpp-1112-secara-otomatis)
- **[S5]** [Batas omzet PKP Rp4,8 miliar (DJP)](https://www.pajak.go.id/sites/default/files/d7//Media%20Brief%20Batasan%20Omzet%20Pengusaha%20Kecil%20Wajib%20PPN%20Dinaikkan.pdf), [Usulan Bank Dunia Rp500 juta (IKPI)](https://ikpi.or.id/bank-dunia-usul-ambang-batas-pkp-dipangkas-jadi-rp-500-juta/)
- **[S6]** [UMKM yang tak lagi dapat tarif 0,5% (IKPI)](https://ikpi.or.id/ini-umkm-yang-tak-lagi-dapat-tarif-pph-05/), [Omzet Rp500 juta bebas pajak tidak berlaku untuk PT (DDTC)](https://news.ddtc.co.id/berita/nasional/1818545/jangan-salah-omzet-rp500-juta-bebas-pajak-tidak-berlaku-untuk-pt)
- **[S7]** [Jurnal PPh 23 (Klikpajak)](https://klikpajak.id/blog/jurnal-pph-23/), [Penjelasan PPh 23 (Jurnal.id)](https://www.jurnal.id/id/blog/2018-penjelasan-lengkap-tentang-pph-pasal-23/)
- **[S8]** [Pembukuan WP badan disimpan 10 tahun (DDTC)](https://news.ddtc.co.id/berita/daerah/1811869/ingat-pembukuan-penting-untuk-wp-badan-dokumennya-disimpan-10-tahun), [Imbauan DJP simpan pembukuan 10 tahun](https://www.pajak.go.id/id/berita/lakukan-visit-kp2kp-enrekang-imbau-simpan-pembukuan-minimal-10-tahun)
- **[S9]** [SOP kas kecil (Gajihub)](https://files.sop.gajihub.com/sops/33/sop-pengelolaan-kas-kecil-petty-cash.pdf), [Pengendalian kas kecil, studi kasus](https://jurnal.undhirabali.ac.id/index.php/sintesa/article/download/3561/3898/11907)
- **[S10]** [Checklist tutup buku bulanan (Ecosire)](https://ecosire.com/es/blog/month-end-close-checklist), [Month-end close (Hyperbots)](https://www.hyperbots.com/glossary/month-end-close-checklist)
