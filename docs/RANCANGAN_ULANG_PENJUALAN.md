# Rancangan Ulang Sistem Penjualan Ginnva

Dibuat 2026-11-09 atas permintaan pemilik: rancang ulang Penjualan (data, sistem, alur kerja, fungsi, fitur, menu, UI/UX di API dan mobile) untuk PPF, kaca film, detailing, dan premium wash, sesuai standar perusahaan di bidang itu, tanpa bug, mudah dipakai, dengan riset internet. **Dokumen ini hanya audit, riset, dan rancangan. Belum ada kode yang diubah.** Pola sama dengan `RANCANGAN_ULANG_KEUANGAN.md`, `RANCANGAN_ULANG_BOOKING.md`.

## Keputusan pemilik yang sudah ada (2026-11-09)

- Pendapatan diakui **saat pekerjaan selesai** (pilihan A), bukan saat lunas atau saat kasir menyimpan; DP tetap kewajiban (2140) sampai selesai. Konfirmasi akuntan tetap disarankan.
- Rincian harga per layanan **wajib** untuk booking baru.
- Kasir **mencatat pembayaran di HP**.
- Penjualan eceran/POS **tidak dibangun** (Fase 6 hanya KPI dan dasbor).
- Keputusan 5 sampai 11 di bagian 6 belum dijawab.

## 1. Ringkasan

"Penjualan" di Ginnva sekarang punya dua wajah yang tidak terhubung rapi:

1. **Tempat penjualan terjadi** ada di cluster Booking (Booking, Penawaran/Quotation, Invoice, DP, Refund). Nilai uangnya hanya **satu angka** per booking (`transaction_amount`), diisi admin di panel web lewat aksi "Proses Referral".
2. **Tempat penjualan dilaporkan** adalah menu top-nav "Penjualan": Dasbor, Laporan (23 halaman + Detail Penjualan), Analisa, Produk, Inventori, Pelanggan, Promosi. Laporan ini dibangun meniru Majoo (retail/F&B), lalu disesuaikan sepotong-sepotong.

Masalah intinya bukan tampilan, melainkan **data dasarnya terlalu kasar untuk melaporkan penjualan dengan benar**: tidak ada rincian harga per layanan, pendapatan dibagi rata antar jenis layanan, tanggal pengakuan pendapatan mengikuti kapan kasir menekan simpan, dan kasir di toko tidak bisa mencatat pembayaran dari HP. Akibatnya angka "penjualan per jenis" dan "per produk" bisa salah walau total benar.

Usulan: perbaiki fondasi data dulu (rincian nilai per layanan, buku pembayaran, tanggal pengakuan), baru rapikan alur, mobile, dan laporan di atasnya. Rancangan terbagi 7 fase (0 sampai 6), tiap fase bisa dirilis sendiri. **Ada 11 keputusan pemilik** (bagian 6); sebagian menentukan apakah fase tertentu perlu dibangun sama sekali.

## 2. Riset (apa yang menjadi standar)

Catatan kejujuran: hasil pencarian umumnya halaman vendor dan blog, bukan standar resmi. Angka patokan di bawah **perlu diuji dengan data Ginnva sendiri**, bukan dijadikan target mentah.

**Alur penjualan jasa pemasangan di bengkel PPF/kaca film/detailing (perangkat lunak khusus industri):**
- Alurnya: **estimasi (quote) berbasis diagram kendaraan atau paket, persetujuan pelanggan, deposit, perintah kerja (work order) ke teknisi, status pengerjaan, faktur, pembayaran.** Perangkat PPF seperti Mobile Tech RX menekankan estimasi dengan diagram kendaraan dan pembayaran dari alat yang sama dengan pembuat estimasi; Shopmonkey, Shop Manager App, dan EstiMate menghubungkan estimasi, work order, dan faktur dalam satu rantai. Vendor sendiri mengingatkan agar diperiksa apakah deposit terbawa dari estimasi ke work order ke faktur ([Mobile Tech RX](https://www.mobiletechrx.com/paint-protection-film/), [daftar perangkat lunak PPF](https://worldmetrics.org/best/paint-protection-film-software/), [Shop Manager App](https://mwm.ai/apps/shop-manager-app/1477892394)).
- **Harga bertingkat per paket dan ukuran kendaraan** adalah praktik umum: PPF sebagian/track/seluruh kendaraan, kaca film lebih mahal untuk SUV/truk karena kaca lebih luas (15% sampai 40% menurut satu panduan), dan **paket gabungan** (kaca film + coating/PPF) dipakai sebagai alat penjualan ([contoh daftar harga bengkel](https://www.mkautodesign.com/pricing), [panduan biaya](https://www.586tintfactory.com/2025/04/15/breaking-down-the-cost-of-window-tinting-ppf-and-ceramic-coating)). Ginnva sudah punya matriks harga per ukuran; yang belum ada adalah **paket** dan **rincian harga per layanan di transaksi**.

**Ukuran kinerja penjualan yang lazim untuk bengkel detailing (kisaran, bukan standar):** rata-rata nilai per kendaraan, tingkat tambahan layanan (attach rate, mis. coating pada detailing penuh), tingkat pelanggan kembali (repeat), pemesanan ulang dalam 90 hari, dan hasil per teknisi. Satu sumber menyebut repeat 60% sampai 72%, sumber lain 40% sampai 60%, jadi **angkanya tidak bisa dijadikan patokan** ([PulseRevOps](https://pulserevops.com/knowledge/ik0425), [The Stacc](https://thestacc.com/blog/auto-detailing-marketing-kpis/), [FranConnect](https://franconnect.com/?p=279806)). Tingkat konversi penawaran ke pekerjaan tidak punya patokan industri yang ditemukan; cukup dihitung sendiri: pekerjaan terpesan dibagi penawaran terkirim.

**Standar akuntansi Indonesia untuk pengakuan pendapatan (PSAK 72, kini diberi nomor PSAK 115):** pendapatan diakui **saat kewajiban pelaksanaan dipenuhi** (jasa selesai), dan uang yang diterima sebelum itu dicatat sebagai **liabilitas** (kewajiban kontrak/pendapatan diterima dimuka). Ini yang membenarkan akun 2140 untuk DP yang sudah ada, dan menjadi dasar usulan memindahkan tanggal pengakuan ke saat pekerjaan selesai ([ringkasan PSAK 72](https://www.jurnal.id/id/blog/psak-72/), [pendapatan diterima dimuka](https://www.hashmicro.com/id/blog/pendapatan-diterima-dimuka/), [DDTC](https://news.ddtc.co.id/berita/nasional/23155/psak-72-ciptakan-standar-tunggal-pengakuan-pendapatan)). Sumbernya blog dan jurnal, bukan teks resmi IAI; akuntan harus memastikan.

**Pajak:** ambang wajib PKP omzet Rp4,8 miliar per tahun (PMK 197/2013) masih berlaku; ada rekomendasi Bank Dunia (Juli 2026) menurunkan ke Rp500 juta, **belum berlaku** ([IKPI](https://ikpi.or.id/bank-dunia-usul-ambang-batas-pkp-dipangkas-jadi-rp-500-juta/), [DDTC](https://news.ddtc.co.id/berita/daerah/1797536/wp-ajukan-pkp-meski-omzet-di-bawah-rp-48-m-ternyata-ini-sebabnya)). Aturan DPP nilai lain untuk jasa 2026 **tidak terverifikasi** dalam pencarian ini. Karena itu urusan faktur pajak tetap di Keuangan Fase 3 (menunggu status PKP), bukan dibangun di Penjualan.

**Batas riset:** tidak ditemukan dokumentasi resmi Tekmetric/Shopmonkey, data konversi penawaran, maupun standar struk/nota Indonesia untuk jasa. Hal-hal itu sengaja tidak dijadikan dasar rancangan.

## 3. Audit sistem sekarang

Semua butir di bagian ini **diverifikasi di kode** (berkas disebut) kecuali bila ditandai.

### 3.1 Yang sudah baik (dipertahankan)
- Pencatatan pendapatan memakai satu jalur layanan (`BookingPostingService`) dengan kunci baris dan transaksi basis data; refund parsial berjurnal kontra (`RefundService`); DP dengan akun 2140 dan pengembalian; PPN inklusif 11% dengan DPP/PPN terhitung otomatis (`Booking::applyPpnBreakdown`).
- Dasbor Penjualan dan angka bersih setelah refund sudah dirapikan (`SalesSnapshotService`); izin menu sendiri.
- Matriks harga produk per ukuran kendaraan dan harga khusus per grup pelanggan (`PriceCalculator`); batas negosiasi 5% terhadap harga acuan.
- Voucher, promo belanja, poin, dan referral sudah ada beserta laporannya.

### 3.2 Celah, dibandingkan standar

| # | Temuan | Bukti | Dampak |
|---|---|---|---|
| P1 | **Tidak ada rincian harga per layanan.** Satu booking hanya punya satu `transaction_amount`. `booking_film_products` hanya menyimpan produk dan posisi, **tanpa harga**. | `Booking` (fillable), `BookingFilmProduct` | Tidak bisa tahu berapa PPF dan berapa kaca film dalam satu transaksi |
| P2 | **Pendapatan dibagi rata antar jenis layanan.** Booking PPF + wash menjadi 50/50 ke akun 4100 dan 4600 walau harganya jauh berbeda; sisa pembulatan ke jenis terakhir. | `BookingRevenueSplitter` | Laporan per jenis layanan (dan akun pendapatan per jenis) salah untuk booking campuran; total tetap benar |
| P3 | **Laporan Penjualan per Produk memakai `film_product_id` tunggal**, mengabaikan `booking_film_products` (multi-produk) yang dibangun belakangan. | `ProductSalesReport` (baris ~294 sampai 388) | Booking dengan beberapa produk dihitung ke satu produk atau masuk "belum dikaitkan" |
| P4 | **Tanggal pendapatan = hari kasir menekan simpan**, bukan tanggal pekerjaan selesai. | `BookingPostingService` (`entry_date => now()`, komentar baris 33) | Pekerjaan selesai tanggal 30 yang diproses tanggal 2 jatuh ke bulan berikutnya; laporan harian/bulanan bergeser; bertentangan dengan prinsip PSAK 72 |
| P5 | **Kasir toko tidak bisa mencatat nominal bayar dari HP.** Aplikasi staf hanya menandai selesai; nominal, metode bayar, kode referral hanya diisi admin di panel web lewat "Proses Referral". | `BookingController::complete` (komentar), `BookingResource` | Penjualan baru tercatat setelah ada yang membuka panel web; pekerjaan tertunda, risiko lupa; banner "selesai belum diproses" sudah menjadi tambalan |
| P6 | **Satu metode bayar per booking** (`payment_method`), dan pembayaran bertahap hanya lewat DP lalu pelunasan sekali serta piutang. Tidak ada buku pembayaran: siapa membayar apa, kapan, lewat apa, ke rekening mana. | `Booking`, `DownPaymentService`, `BookingPostingService` | Pembayaran campur (sebagian tunai, sebagian transfer) tidak terwakili; tidak terhubung ke Rekening Kas/Bank (Keuangan Fase 1) kecuali lewat penyesuaian |
| P7 | **Penawaran (Quotation) tanpa harga.** Item penawaran hanya produk dan jumlah; kolom harga ditandai "belum dipakai". Tidak ada masa berlaku, persetujuan pelanggan, atau konversi tercatat ke booking. | `QuotationItem`, `Quotation` (tidak ada relasi booking) | Tidak ada konversi penawaran ke penjualan yang bisa diukur; alur "estimasi, deposit, work order" standar industri belum lengkap |
| P8 | **Invoice terpisah dari booking dan tanpa jurnal.** Bisa dibuat standalone atau mengacu satu booking, tanpa PPN. Dua sumber kebenaran nominal (invoice dan `transaction_amount`). | `InvoiceService` (catatan modul Invoice) | Risiko invoice dan transaksi tidak sama; invoice "lunas" tidak mengubah pendapatan |
| P9 | **Diskon tidak seragam.** Voucher dan promo belanja disimpan sebagai potongan snapshot; `transaction_amount` disimpan bersih; nominal kotor tidak tersimpan; negosiasi manual hanya dijaga oleh batas 5% saat input. | `Booking` fillable, `BookingResource` (~baris 1576) | Tidak bisa melaporkan "diskon yang diberikan" per alasan dengan andal |
| P10 | **Menu Laporan memuat banyak topik di luar penjualan** (Absensi, Karyawan, Persediaan, Stok Kedaluwarsa, Serial Number, Komisi Teknisi), walau sudah dipisah per grup di sidebar (meniru Majoo). *Koreksi 2026-11-09:* "Laporan Void" **berfungsi** (membaca log aktivitas, bukan jurnal); yang praktis kosong hanya badge/filter "Void" di Detail Penjualan, dan tes `SalesDetailTest` justru memakainya, jadi tidak disentuh. | `PenjualanCluster` (anggota), `SalesResource`, `VoidReport` | Menu panjang; bukan error data |
| P11 | **Tidak ada penjualan produk eceran (kasir/POS).** Tidak ada model penjualan atau kasir; rencana POS 2026-08-27 belum dibangun. Penjualan hanya lewat booking jasa. | `app/Models` (tidak ada `Sale`), memori `project_pos_plan` | Bila toko menjual produk tanpa jasa (mis. sisa film, perawatan), tidak ada catatannya (perlu keputusan: apakah ada) |
| P12 | **Belum ada ukuran kinerja penjualan lintas funnel**: konversi penawaran ke booking, tingkat tambahan layanan, nilai rata-rata, pelanggan kembali. Sebagian angka ada tersebar (Dasbor Lead, Laporan Pelanggan). | Laporan yang ada | Sulit menilai efektivitas penjualan toko |

### 3.3 Sesuatu yang sengaja tidak diubah
- Jurnal, refund, DP, dan PPN yang sudah berjalan tidak ditulis ulang; rancangan menambah lapisan di bawahnya dan menggeser sumber angka bertahap.
- Pajak/faktur pajak dan ekspor laporan mengikuti keputusan Keuangan (Fase 3 pajak ditunda, Paket Akuntan menunggu).
- Rekonsiliasi QRIS dan shift kasir tetap ditunda sampai ada kasir/QRIS sungguhan ([[project_qris_reconciliation_pending]], [[project_shift_reconciliation_pending]]).

## 4. Rancangan target

### 4.1 Prinsip
1. **Satu transaksi, banyak baris.** Nilai penjualan = jumlah baris layanan/produk (harga, diskon, jumlah), bukan satu angka.
2. **Pembayaran adalah catatan sendiri**, terpisah dari nilai penjualan: banyak pembayaran per transaksi, tiap satu punya metode, rekening, tanggal, dan pencatat.
3. **Satu sumber angka**: laporan, invoice, dan jurnal membaca dari baris dan pembayaran yang sama.
4. **Pendapatan diakui saat jasa selesai**, uang di muka tetap kewajiban (sesuai PSAK 72); angka lama tidak diubah diam-diam.
5. **Dikerjakan di tempat kerjanya**: kasir toko mencatat bayar dari HP; admin hanya memeriksa dan mengoreksi.
6. **Peringatan, bukan blokir** untuk kebiasaan kerja (diskon besar, harga di bawah acuan), konsisten dengan keputusan pemilik di modul lain.

### 4.2 Model data (usulan)
- `booking_sale_lines` (baris penjualan): `booking_id`, `service_type` (ppf/kaca_film/detailing/premium_wash), `film_product_id` (opsional), `position`/bagian, `description`, `quantity`, `unit_price`, `discount_amount`, `discount_reason`, `line_total`, `reference_price` (harga matriks saat itu). Total booking = jumlah `line_total` dikurangi diskon transaksi (voucher/promo).
- `booking_payments` (buku pembayaran): `booking_id`, `kind` (deposit/pelunasan/cicilan/refund), `amount`, `method`, `payment_account_id` (Rekening Kas/Bank), `paid_at`, `received_by`, `reference`, `journal_entry_id`. DP lama dipetakan ke sini tanpa mengubah jurnalnya.
- `quotation_items` diberi harga dan jumlah (kolom harga yang sudah dicadangkan dipakai); `quotations` diberi `valid_until`, `approved_at`, `booking_id` (hasil konversi), `lost_reason`.
- Opsional (tergantung keputusan 6): `service_packages` (paket gabungan dengan harga paket) dan `sales_targets` (target per toko/bulan).
- Tidak ada tabel baru untuk laporan; laporan dihitung dari yang di atas.

### 4.3 Alur target (ringkas)
1. Prospek memberi detail kendaraan dan layanan, **penawaran berharga** dibuat (dari matriks atau paket), punya masa berlaku, dikirim lewat WhatsApp/tautan.
2. Pelanggan menyetujui, **booking dibuat otomatis dari penawaran** (tanpa mengetik ulang), deposit dicatat di buku pembayaran.
3. Pekerjaan berjalan (alur Booking V2 yang sudah ada: status, SPK, QC, serah terima).
4. Saat selesai, **kasir di HP mencatat pembayaran** (satu atau beberapa metode); sistem membuat jurnal pendapatan per baris layanan, bertanggal selesai.
5. Invoice dibuat dari booking (satu klik), nominal pasti sama; dibagikan ke pelanggan.
6. Laporan dan KPI membaca baris dan pembayaran yang sama.

### 4.4 Fitur dan menu
- **Mobile (kasir/Store Manager):** layar "Pembayaran Booking" (catat bayar, banyak metode), "Rincian Harga" saat konfirmasi, dasbor kecil penjualan toko hari ini dan bulan ini (hanya bila keputusan 4 menyetujui), kirim invoice ke WhatsApp.
- **Panel:** Penawaran berharga + konversi, Rincian Penjualan di Edit Booking, Buku Pembayaran, Dasbor Penjualan dengan KPI (bagian 4.7), Laporan dikelompokkan ulang (hanya yang soal penjualan di "Laporan Penjualan"; laporan karyawan/persediaan dipindah ke cluster yang sesuai atau diberi tautan).
- **Dihapus/disembunyikan:** laporan yang pasti kosong (Void tanpa jurnal), diganti catatan batal di Laporan Booking.

### 4.5 Aturan UI/UX
Satu layar satu tugas; nominal rupiah dengan pemisah ribuan dan keyboard angka; total selalu terlihat; tombol utama jelas ("Catat Pembayaran", "Kirim Penawaran"); kesalahan menjelaskan apa yang salah dan cara memperbaiki; angka bertanda dari mana asalnya; uji di HP kecil.

### 4.6 Keandalan dan keamanan
Semua pembuatan jurnal tetap lewat satu layanan dengan `lockForUpdate()` di dalam `DB::transaction()`; kunci idempotensi pada API pencatatan pembayaran (jangan dobel saat sinyal buruk); izin menu dan toko dipatuhi (kasir hanya tokonya); tes otomatis untuk setiap aturan uang; setiap perubahan nominal tercatat di log aktivitas.

### 4.7 Ukuran keberhasilan
- Jumlah akun pendapatan per jenis di Jurnal Umum **sama** dengan Laporan Penjualan per jenis untuk setiap booking (nol selisih).
- Booking selesai yang belum dicatat penjualannya > 24 jam: mendekati nol.
- Penjualan tercatat tanggal selesai (nol booking yang bergeser bulan).
- Dasbor menampilkan: nilai rata-rata per booking, konversi penawaran ke booking, tingkat tambahan layanan, pelanggan kembali 90 hari, penjualan per toko dan per jenis, diskon diberikan. **Target angkanya ditetapkan pemilik dari data nyata**, bukan dari patokan luar.

## 5. Peta jalan

Perkiraan kasar satu developer, belum termasuk uji manual di HP oleh Anda.

| Fase | Isi | Hasil |
|---|---|---|
| **0. Kebenaran laporan** | Laporan produk memakai `booking_film_products`; kelompokkan ulang menu Laporan; sembunyikan laporan yang pasti kosong; catat tanpa mengubah jurnal lama | Laporan tidak menyesatkan, tanpa perubahan skema besar |
| **1. Rincian penjualan** | **Dikerjakan (2026-11-10), belum diuji di server.** Tabel `booking_sale_lines`; Repeater "Rincian Harga per Layanan" di aksi Proses Referral (juga lewat jalur persetujuan); pendapatan per jenis dibagi menurut nilai baris dan diskalakan ke nominal bersih (potongan voucher/promo/nego dibagi proporsional); booking tanpa rincian tetap dibagi rata; laporan Layanan, grafik kategori, dan grafik Layanan di dashboard memakai pembagi yang sama. Kewajiban diatur `BOOKING_SALE_LINES_REQUIRED_FROM` (tanggal Y-m-d; kosong = tidak wajib; booking yang dibuat sebelum tanggal itu tidak pernah dipaksa). **Belum:** layar mobile (Fase 5), cap nego memakai total rincian, laporan Penjualan Produk per baris produk. Semula: `booking_sale_lines`, form rincian di Edit Booking, pembagian pendapatan per nilai baris (menggantikan 50/50) untuk booking baru; booking lama tetap 50/50 dan diberi penanda | Angka per jenis layanan benar |
| **2. Buku pembayaran** | **Dikerjakan (2026-11-11), belum diuji di server.** Tabel `booking_settlement_payments`; daftar "Pembayaran diterima" di Proses Referral menggantikan "Total Diterima" dan "Metode Pembayaran"; jurnal kas satu baris per rekening; kelebihan bayar ditolak; Buku Pembayaran menyatukan DP, pembayaran saat selesai, dan pelunasan piutang; grafik metode bayar membaca daftar baru (DP dan piutang belum punya metode, masuk "Belum Diisi"). Booking lama dan daftar kosong tetap jalur lama. Semula: `booking_payments`, pemetaan DP lama, banyak metode bayar, hubungan ke Rekening Kas/Bank, laporan metode bayar | Pembayaran tercatat lengkap |
| **3. Tanggal pengakuan** | Pendapatan bertanggal selesai (atau pelunasan, sesuai keputusan 2), peringatan bulan tertutup, migrasi aman untuk data lama | Laporan bulanan tidak bergeser |
| **4. Penawaran berharga** | Harga di penawaran, masa berlaku, persetujuan, konversi ke booking, alasan kalah | Alur estimasi sampai deposit lengkap |
| **5. Mobile kasir** | Catat pembayaran, rincian harga saat konfirmasi, kirim invoice, dasbor toko | Kasir mencatat sendiri di HP |
| **6. KPI dan dasbor** | Dasbor Penjualan baru (bagian 4.7), target bila diputuskan; penjualan eceran bila diputuskan ada | Penjualan terukur |

Urutannya sengaja: Fase 0 aman dan segera berguna; Fase 1 dan 2 adalah fondasi; Fase 3 baru dilakukan setelah 1 dan 2 stabil karena menyentuh tanggal jurnal.

### 5.1a Rancangan rinci Fase 2: buku pembayaran (2026-11-10; disetujui pemilik dan dikerjakan 2026-11-11, belum diuji di server)

**Keadaan sekarang (diverifikasi di kode).** Uang masuk dicatat di tiga tempat berbeda: (1) DP lewat `DownPaymentService` (Dr kas / Kr 2140, tabel `booking_down_payments`, sudah punya rekening); (2) saat Proses Referral, satu angka `amount_received` dan satu `payment_method`, jurnalnya satu baris kas lewat `PaymentAccountResolver` menurut metode itu (`BookingPostingService::post`); (3) pelunasan piutang lewat `ReceivableService` (sudah punya rekening). Akibatnya pembayaran campur di hari penyelesaian (mis. Rp 5 juta tunai + Rp 10 juta transfer) tidak bisa dicatat benar, dan laporan metode bayar hanya melihat satu metode per booking.

**Usulan: satu tabel baru kecil, tanpa memindahkan DP dan piutang.**
- `booking_settlement_payments`: `booking_id`, `payment_account_id` (nullable = bawaan menurut metode), `method` (tunai/transfer/qris/edc), `amount`, `paid_at`, `received_by`. Diganti seluruhnya setiap Proses Referral disimpan (pola sama dengan rincian harga).
- Di aksi Proses Referral, isian tunggal "Total Diterima" dan "Metode Pembayaran" diganti daftar **Pembayaran diterima** (metode, rekening, nominal). Total diterima = jumlah daftar + DP yang terpakai; `amount_received` dan `payment_method` tetap diisi otomatis (metode utama = nominal terbesar) agar laporan dan layar lama tidak patah.
- Jurnal pendapatan memakai satu baris debit kas **per pembayaran** (rekening sendiri-sendiri), 2140 untuk DP yang terpakai, dan Piutang untuk sisanya. Total jurnal tidak berubah, hanya baris kas yang terpisah.
- Bila daftar kosong, perilaku sekarang dipakai persis (booking lama, aplikasi lama).
- **Buku Pembayaran** (laporan baca-saja) menyatukan tiga sumber lewat kueri gabungan, bukan menyalin data: DP, pembayaran saat penyelesaian, pelunasan piutang. Satu tempat untuk melihat siapa membayar apa, kapan, lewat apa, ke rekening mana.
- Laporan metode bayar (grafik Dasbor Penjualan) membaca daftar ini bila ada, jatuh ke `payment_method` lama bila tidak.
- Aturan: jumlah pembayaran + DP terpakai tidak boleh melebihi nominal transaksi (kelebihan ditolak dengan pesan jelas, bukan diam-diam dipotong seperti sekarang); koreksi tetap lewat Proses Referral (yang sudah terkunci bila piutangnya punya pelunasan).
- Mobile (Fase 5) memakai layanan yang sama lewat API.

**Risiko dan penjagaan.** Menyentuh jurnal kas, jadi: tes selisih nol (jumlah debit = kredit, total kas sama dengan sebelum untuk kasus tunggal), uji ulang semua tes jurnal booking/refund, dan booking tanpa daftar tetap lewat jalur lama. Refund tetap dari kas sesuai logika sekarang; refund per rekening asal pembayaran bisa menyusul bila diperlukan.

### 5.2 Kenapa bertahap
Alur uang yang sedang berjalan di production (Proses Referral, refund, DP, PPN) harus tetap hidup selama pekerjaan. Semua perubahan memakai pola tambah-dulu-lalu-geser: kolom/tabel baru nullable, sumber angka lama tetap sampai yang baru terbukti, bendera fitur untuk perubahan perilaku (seperti `FEATURE_INVENTORY_GL` di Keuangan).

### 5.3 Cara menjaga "tanpa bug"
Tes khusus per fase (selisih nol antara jurnal dan laporan), uji beban pada pencatatan pembayaran ganda, suite penuh per kelompok sebelum rilis, rencana rilis tertulis dengan jalur mundur (seperti `RENCANA_DEPLOY_KARYAWAN_KEUANGAN.md`), uji manual di panel dan HP sebelum production.

## 6. Keputusan yang dibutuhkan (dengan rekomendasi)

| # | Pertanyaan | Mengapa penting | Rekomendasi saya |
|---|---|---|---|
| 1 | Apakah Ginnva menjual **produk eceran tanpa jasa** (mis. film sisa, cairan perawatan)? | Menentukan perlu tidaknya POS (Fase 6) | Bila jarang/tidak ada, **tidak dibangun**; catat penjualan itu sebagai booking tipe produk bila perlu |
| 2 | Pendapatan diakui **saat pekerjaan selesai** atau saat **dibayar lunas**? | Menentukan tanggal jurnal (P4) | Saat selesai (sesuai PSAK 72), piutang bila belum lunas; konfirmasikan dengan akuntan |
| 3 | Apakah **rincian harga per layanan** wajib diisi pada booking? | Dasar P1, P2, P3 | Wajib untuk booking baru; booking lama dibiarkan (tanpa diubah retroaktif) |
| 4 | Apakah **kasir/Store Manager mencatat pembayaran di HP**, dan siapa boleh mengoreksi? | Dasar P5 | Kasir mencatat; koreksi nominal hanya Store Manager ke atas dengan alasan |
| 5 | **Aturan diskon**: siapa boleh memberi, batas berapa, tindakan di atas batas? | P9 | Peringatan di atas batas (sekarang 5% terhadap acuan), persetujuan Store Manager di atas batas yang Anda tentukan |
| 6 | Apakah perlu **paket** (mis. kaca film + PPF dengan harga paket)? | Memengaruhi model data harga | Mulai tanpa paket; tambah bila penjualan paket memang terjadi |
| 7 | **Invoice** dibuat dari booking (satu sumber) atau tetap boleh berdiri sendiri? | P8 | Dari booking untuk penjualan jasa; standalone hanya untuk kasus khusus dengan alasan |
| 8 | Apakah ada **komisi sales atau target penjualan** per orang/toko? | Menentukan tabel target dan laporan | Tidak ada komisi sales sekarang (komisi installer sudah tetap); tetapkan target toko saja bila perlu |
| 9 | **Metode bayar** apa yang benar-benar dipakai: tunai, transfer, EDC, QRIS? | Daftar di buku pembayaran | Hanya yang dipakai; QRIS tetap ditunda sampai dipakai |
| 10 | **Status PKP dan faktur pajak** (sama dengan Keuangan Fase 3) | Menentukan apakah invoice perlu memuat PPN/NPWP | Tetap menunggu keputusan Keuangan; tidak dibangun di Penjualan |
| 11 | **Data lama**: biarkan apa adanya, atau koreksi tanggal/pembagian jenis untuk booking yang sudah ada? | Menyentuh laporan masa lalu | Biarkan; hanya tandai bahwa angka lama memakai pembagian rata dan tanggal simpan |

## 7. Batasan riset

- Sumber riset sebagian besar halaman vendor dan blog; angka patokan industri tidak seragam antar sumber dan tidak dijadikan target.
- Tidak ada standar resmi Indonesia khusus bengkel detailing/PPF yang ditemukan; praktik "estimasi sampai pembayaran" diambil dari perangkat lunak industri luar negeri dan disesuaikan.
- Pengenaan PPN jasa, DPP nilai lain, dan faktur pajak 2026 tidak terverifikasi di sini; wajib dikonfirmasi konsultan pajak.
- Audit kode dilakukan terhadap keadaan `development` (commit terakhir 2026-11-09); perilaku dengan data produksi nyata belum diuji.

## 8. Sumber
- [Mobile Tech RX: PPF & Vinyl Business Management Software](https://www.mobiletechrx.com/paint-protection-film/)
- [Daftar perangkat lunak PPF (Worldmetrics)](https://worldmetrics.org/best/paint-protection-film-software/)
- [Shop Manager App](https://mwm.ai/apps/shop-manager-app/1477892394)
- [MK Auto Design: contoh daftar harga tint/PPF](https://www.mkautodesign.com/pricing)
- [586 Tint Factory: biaya tint, PPF, coating](https://www.586tintfactory.com/2025/04/15/breaking-down-the-cost-of-window-tinting-ppf-and-ceramic-coating)
- [PulseRevOps: KPI bengkel detailing](https://pulserevops.com/knowledge/ik0425)
- [The Stacc: KPI pemasaran detailing](https://thestacc.com/blog/auto-detailing-marketing-kpis/)
- [FranConnect](https://franconnect.com/?p=279806)
- [Jurnal.id: PSAK 72](https://www.jurnal.id/id/blog/psak-72/)
- [HashMicro: pendapatan diterima dimuka](https://www.hashmicro.com/id/blog/pendapatan-diterima-dimuka/)
- [DDTC: PSAK 72](https://news.ddtc.co.id/berita/nasional/23155/psak-72-ciptakan-standar-tunggal-pengakuan-pendapatan)
- [IKPI: usulan ambang PKP](https://ikpi.or.id/bank-dunia-usul-ambang-batas-pkp-dipangkas-jadi-rp-500-juta/)
- [DDTC: PKP dengan omzet di bawah Rp4,8 miliar](https://news.ddtc.co.id/berita/daerah/1797536/wp-ajukan-pkp-meski-omzet-di-bawah-rp-48-m-ternyata-ini-sebabnya)
