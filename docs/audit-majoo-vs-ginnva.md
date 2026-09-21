# Audit Majoo vs Ginnva — 2026-09-17

Tujuan: menyusuri screenshot semua halaman Majoo (`C:\Users\Antony\Documents\Ginnva\Majoo`)
untuk melihat data/fitur apa yang bisa diterapkan ke sistem Ginnva, disaring khusus untuk
konteks Ginnva sebagai perusahaan jasa instalasi PPF/Kaca Film (Window Film) pada mobil —
BUKAN retail/F&B. Banyak fitur Majoo murni pola retail (ongkos kirim, platform marketplace,
meja/dapur) yang sengaja diabaikan.

**Catatan metodologi (ditambahkan 2026-09-17 setelah user menegur)**: audit awal cuma fokus ke
DATA/metrik yang ditampilkan tiap halaman, bukan FITUR UI/interaksinya (export, search, filter,
custom kolom, dst). Sejak temuan ini, fitur cross-cutting (dipakai berulang di banyak halaman
Majoo) dicek sekali di bagian "Fitur UI/Interaksi Lintas Halaman" di bawah, bukan diulang-ulang
per laporan.

Status per baris: **Sudah Ada** (setara/lebih baik di Ginnva) / **Belum Ada — Relevan** (kandidat
dibangun) / **Tidak Relevan** (pola retail/F&B, tidak cocok bisnis jasa).

Belum ada keputusan final untuk membangun apa pun — ini murni daftar temuan riset, menunggu
prioritas dari user sebelum eksekusi.

**⚠️ PERINGATAN PENTING (ditambahkan 2026-09-17)**: audit Majoo yang JAUH LEBIH DETAIL sudah
pernah dilakukan sebelumnya (2026-09-08 s/d 2026-09-14, terdokumentasi lengkap di memory
`project_penjualan_majoo_blocked_items`) — SEBELUM audit ini dimulai. Beberapa temuan di dokumen
ini ternyata SUDAH tercakup/sudah dibangun/sudah sengaja diputuskan tidak relevan di audit lama
itu (mis. temuan "Detailing belum ada" ternyata SALAH — sudah dibangun penuh 2026-09-10, cuma
harga blocked). **SEBELUM menindaklanjuti temuan apa pun di dokumen ini jadi keputusan/kode,
WAJIB cek dulu ke `project_penjualan_majoo_blocked_items` — banyak kemungkinan sudah dibahas
tuntas di sana dgn detail jauh lebih dalam.** Dokumen ini (audit-majoo-vs-ginnva.md) lebih
berguna sbg PELENGKAP (menemukan halaman yang BELUM tersentuh audit lama, mis. Laporan
Utilisasi/Absensi/dsb) drpd menggantikan audit lama.

---

## Penjualan / Dashboard

Sumber: `Majoo/Penjualan/Dashboard`

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Total Penjualan + growth % vs periode sebelumnya | Sudah Ada | `SalesDashboard.php` + `SalesSnapshotService` — malah lebih baik (net revenue setelah refund, growth apple-to-apple berbasis hari berjalan) |
| Penjualan Terbayar vs Belum Dibayar (piutang) | Sudah Ada | sudah dihitung di `SalesSnapshotService::summarize()` |
| Grafik tren harian vs bulan lalu | Sudah Ada | `BookingRevenueTrendChart` |
| Penjualan per Kategori | Sudah Ada | `BookingRevenueByCategoryChart` (split Kaca Film vs PPF) |
| Penjualan per Outlet/Cabang | Sudah Ada | `SalesByOutletChart` + filter cabang di `SalesDashboard` |
| Stok Terendah (widget di dashboard) | Sudah Ada (di tempat lain, sudah linked) | Sengaja dipindah ke `InventoryDashboard` (audit 2026-09-11 #6), badge ringkas + link di `sales-dashboard.blade.php:313-341` — sudah diverifikasi linked dengan benar 2026-09-17 |
| Penjualan per Transaksi (avg job value) sbg KPI tersendiri + growth % | **Belum Ada — Relevan** | Ginnva punya jumlah transaksi di snapshot tapi belum ditonjolkan sbg KPI card dgn growth sendiri. Insight: revenue naik tapi transaksi turun = job makin mahal rata-rata, beda makna dari growth revenue total. |
| Penjualan per Kasir / Komisi per Kasir (ranking teknisi) | **Belum Ada — Relevan** | Ada modul Komisi tapi belum ada widget ranking "siapa closing/mengerjakan job terbanyak & terbesar nilainya" di dashboard. Berguna evaluasi performa teknisi/installer bulanan. |
| Layanan/Paket Terlaris (bukan cuma kategori besar) | **Belum Ada — Relevan** | Breakdown per varian/paket spesifik (PPF Depan vs Full Body, Kaca Film Ceramic 40% dst), bukan cuma Kaca Film vs PPF. Berguna utk keputusan stok bahan baku & strategi promosi paket. |
| Breakdown Metode Pembayaran | **Belum Ada — Relevan** | Belum ada di dashboard penjualan sama sekali. Berguna utk rekonsiliasi kas/rekening, terhubung ke modul Keuangan yg sudah ada. |
| Kontrol Fraud (nilai promo dipakai, indikasi abuse) | Tidak Relevan | Hanya relevan kalau Ginnva jalankan program promo/referral aktif & curiga penyalahgunaan — belum jadi concern saat ini |
| Growth % per KPI individual (Transaksi, Penjualan per Transaksi, Produk Terjual, dst — bukan cuma Total Penjualan) | **Belum Ada — Relevan** | Dicek `SalesSnapshotService.php` — cuma hitung 1 `growthDelta` utk total net revenue keseluruhan, TIDAK per-metrik. Majoo tampilkan panah ↘/↗ + % di SETIAP KPI tile secara independen. Insight: revenue turun 88% tapi Produk per Transaksi malah naik 100% — dua sinyal berbeda yg keduanya penting, hilang kalau cuma lihat 1 angka growth agregat. |

**Re-audit teliti 2026-09-17 (per elemen)**: Banner iklan "mCapital powered by GOtyme" (produk
pinjaman modal Majoo sendiri, "Modal hingga 280jt") — murni marketing Majoo, tidak relevan.
**7 KPI tiles lengkap** dikonfirmasi: Total Penjualan (+ 2 sub-metrik kecil: "Akumulasi dari
Awal Bulan", "Proyeksi Bulan Ini"), Penjualan Belum Dibayar, Transaksi, **Penjualan per
Transaksi**, Penjualan Terbayar, **Produk Terjual**, **Produk per Transaksi**. Chart granularitas
PER JAM (00:00-23:00, bukan cuma per hari) + legend "Periode Sebelumnya" vs "Total Penjualan".
Grid 8 widget (2 baris × 4 kolom): Kontrol Fraud, Metode Pembayaran, Jenis Order, Penjualan per
Kategori / Produk Terlaris, Komisi per Kasir, Penjualan per Kasir, Stok Terendah — SEMUA 8
widget seragam punya ikon "..." (bawah-kiri) DAN "Lihat Semua ›" (bawah-kanan), KECUALI
"Kontrol Fraud" yg cuma punya "..." tanpa "Lihat Semua" (kemungkinan widget ini tidak py
laporan detail terpisah). Karena "..." muncul konsisten berpasangan dgn "Lihat Semua" di
hampir semua widget, kemungkinan besar BUKAN menu kustomisasi/hapus-widget seperti dugaan saya
sebelumnya — lebih mungkin cuma alias/shortcut ke aksi yg sama atau menu kecil (export widget
ini saja, dsb). Tetap belum diklik, jadi masih belum pasti.

**Re-audit dgn filter "Bulan" (data riil terisi)**: **Setiap KPI tile (bukan cuma Total
Penjualan) punya growth % sendiri** dgn panah merah↘/hijau↗: Total Penjualan ↘88.18%, Transaksi
↘50%, Penjualan per Transaksi ↘76.35%, Produk per Transaksi ↗100%, dst. Isi widget mini row
riil: Kontrol Fraud cuma nampilkan nilai "Promosi" terpakai (Rp 6.116.000) — jadi SEBENARNYA
cuma monitoring nilai promo dipakai, bukan deteksi fraud canggih (mengonfirmasi ulang penilaian
awal "Tidak Relevan" sudah tepat). Metode Pembayaran cuma "Bank Transfer" (100%). Jenis Order
cuma "Invoice". Penjualan per Kategori: "Window Film" Rp16.640.000 (angkanya beda dari Total
Penjualan periode — kemungkinan basis kalkulasi beda, bukan bug, cuma dicatat sbg observasi).
Produk Terlaris: "Ginnva Signature" & "Panoramic" masing-masing 2 unit. **Penjualan per Kasir
atas nama "PT. Ginnva Shie..." (nama OUTLET, bukan nama staff individual)** — di trial ini
transaksi tidak diatribusikan ke kasir perorangan, cuma fallback ke nama perusahaan/outlet.

**Fitur UI Dashboard** (ditambahkan retroaktif 2026-09-17): toggle Harian/Mingguan/Bulan +
navigasi tanggal (‹ ›) sudah ada & setara di `SalesDashboard.php`. Ikon "..." di tiap widget card
kemungkinan menu kustomisasi/hapus-widget milik user (belum dicek apakah relevan/ada
padanannya — Filament widget dashboard biasanya fixed per role, bukan per-user customizable,
jadi kemungkinan **Tidak Prioritas** kecuali user secara eksplisit minta dashboard yang bisa
diatur sendiri tata letaknya). "Lihat Semua" link per widget → ke laporan detail: pola ini
otomatis akan ada begitu laporan detail terkait dibangun.

## Penjualan / Laporan / Laporan Penjualan / Ringkasan Penjualan

Sumber: `Majoo/Penjualan/Laporan/Laporan Penjualan/Ringkasan Penjualan`

Laporan waterfall P&L sisi penjualan:
Pendapatan (Penjualan Kotor + Ongkir + Biaya Pelayanan + MDR + Pembulatan + Pajak + Asuransi +
Platform + Lainnya) → dikurangi Biaya Promosi (Promo Pembelian/Produk/Komplimen) → Total
Penjualan → dikurangi Biaya Administrasi → Penjualan Bersih (dikurangi Pengembalian/refund) →
Laba Kotor (dikurangi Biaya MDR, HPP, Komisi, Ongkos Kirim, Asuransi).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Ongkos Kirim, Biaya Pelayanan MDR, Platform | Tidak Relevan | khas retail/F&B/marketplace |
| Laporan Laba Kotor per periode (Penjualan Bersih − HPP − Komisi) dalam SATU laporan | **SUDAH ADA — TODO terjawab 2026-09-17** | `app/Filament/Pages/SalesSummaryReport.php` SUDAH ADA (dibangun 2026-09-08), analog waterfall Ringkasan Penjualan Majoo, dgn komentar eksplisit di kode: baris Ongkos Kirim/Biaya Pelayanan-MDR/Platform/Asuransi/HPP versi Majoo SENGAJA tidak ditiru krn tidak relevan bisnis jasa PPF/Kaca Film. Referensi QRIS/MDR ditemukan di file ini & `SalesSummaryExport.php` — perlu dibaca detail kalau mau tahu persis apa yg sudah/belum dicakup, tapi TIDAK PERLU dibangun dari nol lagi. |

## Penjualan / Laporan / Laporan Penjualan / Detail Penjualan

Sumber: `Majoo/Penjualan/Laporan/Laporan Penjualan/Detail Penjualan`

Laporan transaksi level baris (bukan agregat): tabel No Transaksi, Waktu Order, Waktu Bayar,
Outlet, Jenis Order, Total Penjualan, Metode Pembayaran, Bayar, Order (ikon lihat detail).
Search, filter tanggal/waktu order, "Atur Tabel" (custom kolom), Ekspor. 5 KPI card: Total
Penjualan, Total Transaksi, Penjualan Bersih, Total Pembayaran, Total Piutang.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Tabel transaksi per baris + KPI ringkas + filter + ekspor | Sudah Ada (perlu verifikasi ringan) | Polanya generik, hampir pasti tertutup oleh `BookingResource`/`InvoiceResource` yang sudah ada (list dengan filter tanggal/status bayar). Belum diverifikasi detail — kalau ternyata kolom "Metode Pembayaran" tidak ada di tabel booking Ginnva, ini nyambung ke temuan "Breakdown Metode Pembayaran" di Dashboard yang sudah dicatat Belum Ada — Relevan. Re-audit: "Atur Tabel" & "Filter" ternyata 2 tombol terpisah dgn ikon masing2 (kolom vs corong), bukan 1 fitur generik — kolom Metode Pembayaran di sini detail sampai nama bank ("Bank Transfer, Transfer BCA"), memperkuat kebutuhan field kanal bayar di Booking. |

## Penjualan / Laporan / Laporan Penjualan / Penjualan Per Periode

Sumber: `Majoo/Penjualan/Laporan/Laporan Penjualan/Penjualan Per Periode`

Grafik tren multi-metrik yang bisa ditoggle (Penjualan, Transaksi, Laba Kotor, Produk) dalam 1
chart, grouping Hari/Minggu/Bulan (dropdown) + filter Jenis Order. Tabel di bawah, per-periode
(baris = tanggal/minggu/bulan): Penjualan, Laba Kotor, Total Produk, Total Transaksi,
Pengembalian, Komisi, **Order/Transaksi** (avg nilai per transaksi), Produk/Transaksi.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Grafik multi-metrik toggleable + grouping periode fleksibel (hari/minggu/bulan) | **Belum Ada — Relevan** | `BookingRevenueTrendChart` cuma 1 metrik (revenue) & fixed bulan berjalan vs bulan lalu, tidak flexible grouping. Ini lebih dari sekadar "nice to have" kalau owner mau lihat pola per hari-dalam-minggu (mis. weekend vs weekday) sepanjang rentang custom. |
| Kolom Order/Transaksi (avg job value) per periode | **Belum Ada — Relevan** | Konsisten dengan temuan Dashboard — ini bentuk tabularnya, per periode bukan cuma snapshot 1 angka. |
| Kolom Laba Kotor per periode (Penjualan − Komisi − Pengembalian) | **Belum Ada — Relevan (parsial)** | Catatan: versi Majoo ini TIDAK termasuk HPP (murni retail sederhana). Untuk Ginnva versi yang benar harus include HPP bahan baku (PPF/kaca film terpakai) juga dikurangkan, supaya "Laba Kotor" beneran mencerminkan margin jasa instalasi — jangan asal contek definisi Majoo mentah-mentah. Terhubung ke TODO audit `SalesSummaryReport.php` di baris atas. |
| Grafik multi-metrik dgn checkbox toggle langsung di legend (Penjualan/Transaksi/Laba Kotor/Produk nyala-mati bareng dlm 1 chart) | **Belum Ada — Relevan (detail UI)** | Lebih canggih dari sekadar dropdown pilih 1 metrik. Memperjelas temuan "grafik multi-metrik toggleable" di atas — implementasinya pakai checkbox legend, bukan dropdown terpisah. |

## Penjualan / Laporan / Laporan Penjualan / Penjualan Outlet

Sumber: `Majoo/Penjualan/Laporan/Laporan Penjualan/Penjualan Outlet`

Tabel per-outlet: Penjualan, Laba Kotor, Jumlah Produk, Jumlah Transaksi, lalu kolom % kontribusi
tiap outlet ke total (Penjualan %, Laba Kotor %, Produk %, Transaksi %), plus Penjualan/Transaksi
& Produk/Transaksi per outlet. Chart bisa pilih outlet mana yang ditampilkan via "+ Perbandingan"
(pilih outlet, plot berdampingan di 1 grafik).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Tabel per-outlet dgn kontribusi % ke total (bukan cuma nominal) | **Belum Ada — Relevan (kalau multi-cabang aktif)** | `SalesByOutletChart` cuma line chart nominal per toko, belum ada tabel ranking dgn % kontribusi & avg per transaksi per outlet. Baru bernilai kalau Ginnva sudah/akan punya >1 cabang aktif secara bersamaan — cek dulu jumlah store aktif saat ini sebelum prioritaskan. |
| Fitur "+ Perbandingan" pilih outlet mana yg diplot | Tidak Prioritas | nice-to-have UI, bukan kebutuhan mendesak selama jumlah cabang masih sedikit |

## Penjualan / Laporan / Laporan Penjualan / Laporan Uang Muka

Sumber: `Majoo/Penjualan/Laporan/Laporan Penjualan/Laporan Uang Muka`

Laporan DP (down payment), 3 tab:
- **Pembayaran**: No Transaksi, Tanggal Bayar, Outlet, Pelanggan, Uang Muka Dibayar, Metode
  Pembayaran, Jenis Order
- **Void**: Total Uang Muka Dikembalikan & Total Uang Muka Hangus (booking dibatalkan → DP
  hangus atau dikembalikan, per transaksi)
- **Kembalian**: Total Tagihan, Uang Muka Dibayar, Uang Muka Dikembalikan, Status, Tanggal
  Rekonsiliasi

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Tracking DP (dibayar / hangus jika void / dikembalikan) per transaksi | **Sudah Tercatat & Sedang Menunggu Keputusan — bukan temuan baru** | Koreksi: ini BUKAN gap yg baru ditemukan — `project_penjualan_majoo_blocked_items` (#2) sudah mencatat persis ini sejak 2026-09-08/09: "Uang Muka (DP) — tahap penerimaan, nominal (tetap/persen/bebas), aturan pembatalan belum diputuskan", sudah jadi Topik 2 di dokumen keputusan resmi yg dikirim ke atasan (`Keputusan-PPN-DP-Produk-Stok-Ginnva.docx`). Grep migrasi saya (nihil) cuma mengonfirmasi ulang fakta yg sudah diketahui, bukan penemuan baru. **Tindakan yg benar: tanyakan ke user apakah jawaban Topik 2 sudah turun dari atasan** — jangan didesain ulang dari nol di sini. |
| Checkbox bulk-select + kolom "Status"/"Tanggal Rekonsiliasi" pada tab Void & Kembalian (re-audit UI 2026-09-17) | **Detail penting untuk desain DP** | Laporan Uang Muka Majoo bukan cuma pasif menampilkan angka — ada ALUR KERJA rekonsiliasi: staff keuangan bulk-select baris DP yang sudah benar-benar ditransfer balik ke customer, lalu ditandai "sudah rekonsiliasi". Kalau skema DP Ginnva dibangun nanti, perlu dipikirkan lifecycle status-nya (dibayar → dipakai/hangus/dikembalikan → direkonsiliasi), bukan cuma kolom angka datar. |

## Penjualan / Laporan / Laporan Penjualan / Laporan Jenis Bayar

Sumber: `Majoo/Penjualan/Laporan/Laporan Penjualan/Laporan Jenis Bayar`

Breakdown lengkap per metode pembayaran: Jumlah Transaksi, Transaksi %, Jml Transaksi Deposit,
Jml Transaksi Uang Muka, Penjualan (Rp), Penjualan %, Penjualan Deposit, Penerimaan Uang Muka.
Bisa drill-down per metode pembayaran → breakdown per outlet.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Laporan breakdown metode pembayaran lengkap + drill-down per outlet | **Sudah Tercatat di Memory Lain** | Cross-check `project_penjualan_majoo_blocked_items` (#3): "sistem tidak pernah mencatat kanal bayar (cash/transfer/QRIS) sama sekali... BUKAN keputusan bisnis, field baru yg perlu ditambahkan ke Booking (mirip pola `film_product_id`)". Konsisten dgn temuan sy — bedanya ini sudah ditandai jelas sbg "field baru, bukan keputusan bisnis" (lebih actionable drpd yg sy tulis, tidak perlu nunggu approval atasan). |
| Kolom Deposit & Uang Muka menempel di laporan ini juga | Terhubung ke temuan DP | Menguatkan temuan "Laporan Uang Muka" sebelumnya — kalau skema DP dibangun, laporan jenis bayar ini idealnya sekalian include kolom DP-nya, bukan laporan terpisah-terpisah. |

## Penjualan / Laporan / Laporan Penjualan / Laporan Jenis Order

Sumber: `Majoo/Penjualan/Laporan/Laporan Penjualan/Laporan Jenis Order`

Breakdown per jenis order (dine-in/online/delivery dst di Majoo asli — data contoh cuma
"Invoice" krn tidak relevan buat Ginnva): Jumlah Transaksi, Jumlah Transaksi %, Penjualan (Rp),
Penjualan %.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Breakdown per jenis order/channel | Tidak Relevan (bentuk aslinya) | Ginnva bukan multi-channel dine-in/delivery — hampir semua job lewat 1 alur (booking → invoice). |
| Konsep breakdown per SUMBER booking (walk-in/website/WA/referral) | Sudah dicatat sebelumnya | Pola yang sama tapi datanya beda — lihat catatan "Jenis Order → sumber booking" di temuan Dashboard Penjualan. Tidak perlu dicatat dobel, cukup 1 fitur ini kalau dibangun. |

## Penjualan / Laporan / Laporan Penjualan / Laporan Void

Sumber: `Majoo/Penjualan/Laporan/Laporan Penjualan/Laporan Void`

Laporan pembatalan transaksi: No Nota, Tanggal Void, Tanggal Order, Kasir, **Otorisasi** (siapa
yang approve), Produk, Subtotal Void, Jenis Order, Nama Meja.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Void transaksi butuh otorisasi terpisah (anti-fraud) | Sudah Ada (jalur berbeda) | Dicek `BookingResource.php:1310-1349` — `quickCancel` cuma untuk status pending/confirmed (SEBELUM uang masuk), tanpa approval, cuma modal konfirmasi. Kalau booking sudah dibayar/selesai, jalurnya lewat Refund yang SUDAH wajib approval (`project_segregation_of_duties`). Tidak ada celah keamanan nyata — desainnya sudah tepat (cancel pra-bayar memang tak perlu approval berlapis). |
| Laporan/daftar ringkas "Booking Dibatalkan" per periode | **Belum Ada — Prioritas Rendah** | Nice-to-have visibilitas untuk owner (pola/frekuensi pembatalan, alasan), tapi bukan kebutuhan mendesak — datanya sudah ada di kolom `status`+`notes` Booking, tinggal query/laporan tampilan saja kalau suatu saat dibutuhkan. |

## Penjualan / Laporan / Laporan Penjualan / Laporan Refund

Sumber: `Majoo/Penjualan/Laporan/Laporan Penjualan/Laporan Refund`

Tabel: No Transaksi Refund, Tanggal, Refund (Rp), Metode Pembayaran, Nama Outlet. Filter
Semua/Tunai/Non Tunai. KPI: Total Transaksi Refund, Total Refund. Ada Atur Tabel + Ekspor
(sudah dicatat di bagian Fitur UI Lintas Halaman).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Laporan daftar refund per periode + filter metode bayar | Sudah Ada (perlu verifikasi ringan) | Ditemukan `app/Filament/Pages/RefundReport.php` — kemungkinan besar sudah menutupi ini, mengingat `RefundService` dgn approval flow sudah ada (`feedback_financial_transaction_integrity_audit`, `project_segregation_of_duties`). Belum dibaca detail isinya — kalau nanti relevan, cek apakah sudah ada breakdown metode bayar & filter tunai/non-tunai sama persis. |

---

## Fitur UI/Interaksi Lintas Halaman

Fitur yang muncul BERULANG di hampir semua laporan Majoo yang sudah dicek (Ringkasan Penjualan,
Detail Penjualan, Penjualan Per Periode, Penjualan Outlet, Laporan Uang Muka, Jenis Bayar, Jenis
Order, Void) — dicek sekali di sini per fitur, bukan diulang per halaman.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| **Ekspor Laporan** (ke Excel/PDF/CSV) — ada di HAMPIR SEMUA halaman laporan Majoo | **BELUM ADA SAMA SEKALI — Prioritas Tinggi** | Dicek 2026-09-17: grep `ExportAction`/`ExportBulkAction`/`Export::make` di seluruh `app/` = NIHIL di 54+ resource Filament. Ini gap besar yang lolos di audit awal krn fokusnya cuma ke data, bukan fitur. Owner/akuntan hampir pasti butuh export laporan (rekap bulanan, kirim ke akuntan/investor, arsip). Filament punya plugin resmi `filament/filament` export action (`pxlrbt/filament-excel` atau native Filament v3 export) yang tinggal dipasang per tabel/resource yang relevan. |
| **Atur Tabel** (toggle/custom kolom yang ditampilkan) | Sudah Ada (luas) | `->toggleable()` sudah dipakai di 54+ file resource Filament — setara. |
| Search (cari di tabel) | Sudah Ada (asumsi default Filament) | Filament table punya global/column search built-in, dipakai luas di resource yang sudah ada — belum ada indikasi ini kurang. |
| Filter tanggal / filter dropdown (jenis order, metode bayar, dst) | Sudah Ada (pola umum Filament) | `Tables\Filters\SelectFilter`/`Filter` sudah pola umum di resource Ginnva — tinggal disesuaikan per laporan baru yang dibangun. |
| "+ Perbandingan" (pilih outlet/metode utk diplot bareng di 1 chart) | Tidak Prioritas | nice-to-have UI, bukan kebutuhan mendesak, sudah dicatat di temuan Penjualan Outlet |
| Filter pakai tombol tab (Pembayaran/Void/Kembalian, Tunai/Non-Tunai) vs dropdown ("Semua Jenis Order") — 2 pola berbeda dipakai Majoo sendiri | Bukan Gap (referensi pola) | Filament bisa dua-duanya (`Tables\Filters\Filter` dropdown, atau `ButtonGroup`/Tabs). Cukup dipilih sesuai konteks laporan saat dibangun, tidak perlu dipaksa satu pola untuk semua. |
| Drill-down klik baris → sub-halaman berbreadcrumb ("‹ Kembali") | Bukan Gap | Pola navigasi standar Filament Page, bisa direplikasi langsung kalau laporan detail dibangun. |
| Section chart bisa di-collapse ("Sembunyikan"/"Tampilkan") | Nice-to-have kecil | Polesan UX, bukan prioritas. |
| Timestamp "Terakhir Diperbarui: X detik lalu" | Nice-to-have kecil | Indikator kesegaran data di laporan real-time-ish, murah untuk ditambahkan kalau laporan baru dibangun, tapi bukan prioritas. |

---

## Penjualan / Laporan / Laporan Dapur / Laporan Proses Order

Sumber: `Majoo/Penjualan/Laporan/Laporan Dapur/Laporan Proses Order`

Laporan waktu proses order dari sisi dapur (pola KDS/Kitchen Display System): No Order, Periode,
Produk, Jumlah, Order-Proses(Produk), Proses-Selesai(Produk), Total(Produk), + versi per-Order.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Framing "dapur"/KDS (order masuk → proses → selesai per menu) | Tidak Relevan | murni pola resto |
| **Konsep inti: laporan durasi pengerjaan per job/teknisi/jenis layanan** | **Belum Ada — Relevan (data mentahnya sudah ada!)** | `Spk` model SUDAH punya `checked_in_at`/`checked_out_at` (waktu kendaraan masuk & keluar), tapi belum ada laporan/analitik yang mengagregasi durasi pengerjaan (mis. rata-rata jam PPF Full Body vs Kaca Film, atau teknisi mana yang paling cepat/lambat). Datanya sudah ada di sistem, cuma belum diolah jadi laporan. Lihat struktur kolom konkret di temuan "Laporan Proses Produk" (folder sama) di bawah. |

## Penjualan / Laporan / Laporan Dapur / Laporan Proses Produk

Sumber: `Majoo/Penjualan/Laporan/Laporan Dapur/Laporan Proses Produk`

Agregat waktu proses PER PRODUK/LAYANAN (bukan per order individual seperti Laporan Proses
Order di atas): Produk, Jumlah, Rata-rata Order-Proses, Rata-rata Proses-Selesai, **Rata-rata
Total Waktu**, **Waktu Tercepat**, **Waktu Terlama**.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Agregat durasi pengerjaan per layanan: rata-rata, tercepat, terlama | **Belum Ada — Relevan, struktur kolom jelas** | Untuk Ginnva: "Produk" → jadi per jenis layanan (PPF Depan/Full Body, Kaca Film varian tertentu). Sumber data: `Spk::checked_in_at` & `checked_out_at`, dikelompokkan per `checklistItems`/kategori layanan pada SPK tsb. Berguna untuk owner menilai estimasi waktu pengerjaan yang realistis per paket (utk penjadwalan booking berikutnya) & mendeteksi anomali (job yang "Waktu Terlama"-nya jauh di atas rata-rata mungkin ada masalah). |

## Penjualan / Laporan / Laporan Dapur / Laporan Kembalikan Status

Sumber: `Majoo/Penjualan/Laporan/Laporan Dapur/Laporan Kembalikan Status`

Log audit perubahan status order (mis. status "Selesai" dikembalikan lagi ke status
sebelumnya): Produk, Jumlah, Nama Outlet, Waktu Kejadian, Staf Pemohon, Approval, Perubahan
Status — pola 2-orang (yang minta ubah + yang approve).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Log audit + approval utk revert status order (khusus kitchen order) | Tidak Relevan (bentuk aslinya) | konteks dapur, tidak applicable |
| Konsep: approval wajib kalau staff mau mengembalikan status SPK/Booking yang sudah "Selesai" ke status sebelumnya | Rendah Prioritas (sudah longgar tertutup) | Ginnva sudah punya `LogsActivity` generik yg mencatat perubahan status (`feedback_audit_trail_sweep`), tapi belum ada APPROVAL wajib khusus utk revert status non-finansial (beda dari Refund/Referral yg sudah wajib approval). Belum ada indikasi masalah nyata (staff asal-asalan mengubah status SPK selesai) — cukup dicatat sebagai opsi kalau nanti muncul kebutuhan/kasus penyalahgunaan konkret, tidak perlu dibangun preventif tanpa alasan bisnis jelas. |

**Temuan sampingan penting**: sidebar di screenshot ini menyingkap submenu Laporan yang BELUM
ada di folder screenshot yang di-share user — Laporan Meja, Laporan Produk, **Laporan Jasa**,
Laporan Fasilitas, Laporan Promo & Loyalti, Laporan Pajak, **Laporan Kasir**. Dua yang terakhir
(Jasa, Kasir) berpotensi sangat relevan untuk Ginnva sbg perusahaan jasa — disarankan discreenshot
juga kalau belum, supaya audit ini tidak melewatkan halaman penting.

---

## Penjualan / Laporan / Laporan Meja / Laporan Reservasi Meja

Sumber: `Majoo/Penjualan/Laporan/Laporan Meja/Laporan Reservasi Meja`

Reservasi meja resto: Tanggal & Waktu, Nama Outlet, Nomor Reservasi, Nama Tamu, Jumlah
Tamu/Pax, No HP, Jenis Acara, Meja, Catatan, Status. Filter: search, date range, Semua Status,
Semua Outlet.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Framing "meja/reservasi resto" | Tidak Relevan | murni pola resto |
| Konsep inti: reservasi dgn nama+telepon+jenis acara+catatan+status, filterable per status/outlet | Sudah Ada (lebih matang) | Persis analog `BookingResource` Ginnva — booking adalah bisnis inti Ginnva (bukan fitur pelengkap spt di Majoo), jadi kemungkinan besar Ginnva sudah lebih lengkap di sini. Tidak perlu tindakan. |

**Temuan sampingan**: sidebar menyingkap submenu baru lagi — **Laporan Deposit** (menguatkan
temuan DP/Uang Muka), **Laporan Pelanggan** — belum ada screenshot-nya, disarankan dicek kalau
ada.

## Penjualan / Laporan / Laporan Produk / Penjualan Produk

Sumber: `Majoo/Penjualan/Laporan/Laporan Produk/Penjualan Produk`

**Laporan paling komprehensif** di grup "Laporan Produk" — level paling detail (per produk/SKU
individual, bukan agregat kategori/departemen). Kolom: Produk, SKU, Departemen, Kategori,
Jenis Produk, Jumlah, Penjualan(Rp), Refund(Rp), Jumlah Refund, Penjualan(%), **HPP(Rp)**, HPP
Refund(Rp), **Laba Kotor(Rp)**. KPI: Total Penjualan Per Produk, Total Produk Terjual, Total
Laba Kotor Per Produk. Filter: Semua Kategori, Semua Departemen, Pilih Jenis Order (multi-select,
"46 jenis order terpilih"), Semua Jenis Produk.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| **Laporan per-layanan lengkap: penjualan + HPP + Laba Kotor + refund, per produk individual** | **Belum Ada — Relevan, INI LAPORAN INTINYA** | Ini menyatukan 3 temuan terpisah sebelumnya jadi 1 laporan: "Layanan/Paket Terlaris" (Dashboard), "HPP per kategori" (Penjualan Kategori), dan "Laba Kotor per periode" (Ringkasan/Per Periode) — tapi di level GRANULARITAS PALING DETAIL (per paket/layanan spesifik, mis. "PPF Full Body Sedan" vs "Kaca Film Ceramic 40%"), bukan cuma per kategori besar (PPF vs Kaca Film). **Ini kandidat laporan tunggal paling bernilai buat owner** — langsung kelihatan paket mana yang paling menguntungkan (bukan cuma paling laku), krn HPP bahan beda-beda per jenis PPF/film yg dipakai. |

## Penjualan / Laporan / Laporan Produk / Penjualan Sub Ekstra

Sumber: `Majoo/Penjualan/Laporan/Laporan Produk/Penjualan Sub Ekstra`

Struktur identik Penjualan Ekstra, 1 level lebih detail (sub-varian dari suatu ekstra): Sub
Ekstra, Ekstra, Jumlah, Jumlah%, Penjualan(Rp), Penjualan%, Laba Kotor(Rp), Laba Kotor%, Jumlah
Refund, Refund(Rp).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Level "Sub Ekstra" (varian dari varian ekstra) | Tidak Relevan | Terlalu granular utk Ginnva — extra service (Watermark Remover dkk) belum (dan kemungkinan tidak perlu) punya sub-varian sendiri. Menuntaskan hierarki Produk→Departemen→Kategori→Ekstra→Sub Ekstra: yang benar-benar applicable cuma level Produk (laporan inti) dan pertanyaan bisnis soal Ekstra (apakah dijual terpisah). |

## Penjualan / Laporan / Laporan Produk / Penjualan Kategori

Sumber: `Majoo/Penjualan/Laporan/Laporan Produk/Penjualan Kategori`

Kategori, Jumlah Produk, Produk %, Penjualan (Rp), Penjualan %, **HPP (Rp)**. Filter tambahan:
"Semua Departemen" (drill dari level Departemen).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Kolom HPP per kategori | **Sudah Tercatat di Memory Lain — bukan temuan baru** | Ini akar masalah yg SAMA dgn item HPP di `project_penjualan_majoo_blocked_items` (#4): "cara hitung HPP per booking masih didiskusikan (bahan aktual vs estimasi standar)". Memory itu eksplisit sebut `SalesByPeriodReport`/`SalesByPeriodChart`/`SalesByOutletReport` SENGAJA tidak sertakan kolom Laba Kotor krn alasan yg sama. Tidak perlu dicatat sbg gap terpisah per laporan (kategori/periode/outlet) — semua nunggu 1 keputusan HPP yg sama. |

---

## Penjualan / Laporan / Laporan Produk / Penjualan Departemen

Sumber: `Majoo/Penjualan/Laporan/Laporan Produk/Penjualan Departemen`

Breakdown per Departemen (level di atas Kategori dlm hierarki produk Majoo): Jumlah Produk,
Produk %, Penjualan (Rp), Penjualan %.

Sidebar menyingkap struktur hierarki lengkap grup "Laporan Produk": **Penjualan Produk →
Penjualan Departemen → Penjualan Kategori → Penjualan Ekstra → Penjualan Sub Ekstra** (umum ke
detail).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Level "Departemen" sbg dimensi laporan terpisah dari Kategori | Rendah Prioritas / Kemungkinan Redundan | `BookingRevenueByCategoryChart` sudah split PPF vs Kaca Film (ini sudah setara level "Departemen" utk Ginnva, bukan level "Kategori" yg lebih detail). Menambah 1 level dimensi lagi di atasnya kemungkinan cuma duplikasi kalau Ginnva memang cuma punya 2 lini bisnis besar. Skip kecuali nanti Ginnva ekspansi ke lini bisnis baru (mis. Detailing/Coating) yg butuh 1 level pengelompokan lagi. |
| Level "Ekstra"/"Sub Ekstra" → analog `extra_service` checklist di SPK | **Sudah Tercatat di Memory Lain — bukan temuan baru** | Koreksi: `project_penjualan_majoo_blocked_items` sudah catat ini persis sbg "Produk Layanan / Produk Ekstra / Produk Paket → 🟡 BLOCKED, nunggu user. User 'masih belum tau' (2026-09-10) apakah Ginnva: jual jasa tanpa film, charge add-on terpisah, atau jual paket kombo." — pertanyaan bisnis yg SAMA PERSIS dgn yg saya rumuskan ulang di sini, sudah pernah ditanyakan & masih menunggu jawaban user. Tidak perlu tanya ulang dgn kata-kata baru — cek dulu apakah user sudah kasih jawaban soal ini sebelum membahasnya lagi. |

---

## Penjualan / Laporan / Laporan Jasa / Laporan Jasa

Sumber: `Majoo/Penjualan/Laporan/Laporan Jasa/Laporan Jasa`

Laporan transaksi jasa cukup generik: Tanggal, No Transaksi, Pelanggan, Order, Kasir, Status.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Daftar transaksi jasa per periode | Sudah Ada (kemungkinan besar) | Mirip Detail Penjualan, tertutup oleh `BookingResource`/laporan transaksi yang sudah ada. |

**Temuan sampingan penting**: sidebar menyingkap submenu grup "Laporan Jasa": Laporan Jasa,
**Laporan Reservasi**, **Laporan Reservasi & Utilisasi** — yang terakhir sangat relevan (metrik
utilisasi kapasitas/slot bay-teknisi klasik utk bisnis jasa), terhubung ke logika
`fullDatesInRange()` yang sudah ada di `BookingResource`. Perlu discreenshot & dicek lebih lanjut.

## Penjualan / Laporan / Laporan Jasa / Laporan Reservasi

Sumber: `Majoo/Penjualan/Laporan/Laporan Jasa/Laporan Reservasi`

Daftar reservasi/booking: Tanggal Reservasi, No Reservasi, Pelanggan, Order, Tanggal Buat,
Status Layanan.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Daftar reservasi dgn tanggal dibuat vs tanggal reservasi + status layanan | Sudah Ada (kemungkinan besar) | Setara `BookingResource` yang sudah ada — booking adalah bisnis inti Ginnva, kemungkinan sudah lebih lengkap. |

## Penjualan / Laporan / Laporan Jasa / Laporan Reservasi & Utilisasi

Sumber: `Majoo/Penjualan/Laporan/Laporan Jasa/Laporan Reservasi & Utilisasi`

**Temuan paling relevan sejauh ini untuk bisnis jasa.** Dua tab:

- **Tab Reservasi**: KPI Total Reservasi Dibuat, Total Reservasi Selesai, Total Reservasi
  Dibatalkan, **Tingkat Pembatalan (%)**. Tabel: Tanggal Reservasi, No Reservasi, Pelanggan,
  Nama Layanan, **Penyedia Jasa** (staff/teknisi yang ditugaskan), Total Tagihan, Status
  Layanan.
- **Tab Utilisasi**: KPI Total Jam Kerja, Total Jam Kerja Aktual, Total Durasi Tidak Tersedia,
  **Rata-rata Utilisasi (%)**. Tabel per staff: Nama, Cabang, Jam Kerja (dijadwalkan), Jam Kerja
  Aktual (benar-benar terpakai kerja), Durasi Tidak Tersedia, **Utilisasi %**, eye icon detail.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| **Metrik Utilisasi Teknisi** (jam kerja dijadwalkan vs aktual terpakai vs idle, dlm %) | **BELUM ADA — PRIORITAS TINGGI** | Ini metrik manajemen kapasitas tenaga kerja yang krusial utk bisnis jasa: apakah teknisi kelebihan/kekurangan kapasitas, berapa % jam kerja mereka benar-benar terpakai mengerjakan job vs idle/nganggur. Ginnva punya jadwal kerja (Absensi/Jadwal) & data booking per teknisi, tapi belum ada laporan yg menyilangkan keduanya jadi 1 metrik utilisasi. Sangat applicable & actionable utk owner (keputusan hire teknisi baru vs optimalkan jadwal yg ada). |
| **Tingkat Pembatalan Booking (%)** sbg KPI eksplisit | **Belum Ada — Relevan** | Melengkapi temuan "Laporan Booking Dibatalkan" (rendah prioritas sebelumnya) — sekarang ada bentuk KPI konkret (rasio %, bukan cuma daftar) yg lebih actionable & gampang dipantau trennya dari waktu ke waktu. |
| Kolom "Penyedia Jasa" per reservasi (siapa yg ditugaskan) | Sudah Ada | Booking Ginnva sudah punya assignment installer/teknisi. |

**Re-audit teliti**: Tab Utilisasi diisi data jadwal kerja RIIL staff Ginnva (bukan dummy) — Total
Jam Kerja 883,5, daftar nama: Yuindah Hidajat, Antony Fedryandi, Sugiman, Elroy Yeda Valentino
Zega, Raymond, Oman, dst, masing2 46,5 jam kerja terjadwal (per minggu). Jam Kerja Aktual &
Utilisasi semua 0% krn belum ada data booking-vs-jadwal yg disilangkan di trial ini. Memperkuat
keseriusan temuan ini (fitur diuji dgn niat sungguhan, bukan cuma dilihat sekilas).



---

## Penjualan / Laporan / Laporan Fasilitas / Laporan Reservasi Fasilitas

Sumber: `Majoo/Penjualan/Laporan/Laporan Fasilitas/Laporan Reservasi Fasilitas`

Reservasi ruangan/fasilitas fisik: Waktu Reservasi, No Reservasi, Pelanggan, Nama Produk
Fasilitas, **Ruangan**, Total Tagihan, Status, Durasi, Waktu Buat, **Waktu Check In**, Waktu
Bayar. KPI: Reservasi Dibuat/Selesai/Dibatalkan + Tingkat Pembatalan (sama pola dgn Reservasi
Jasa).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Reservasi per RUANGAN/bay fisik (bukan per staff) | **Belum Ada — Relevan, dimensi kapasitas berbeda** | Melengkapi temuan Utilisasi Teknisi sebelumnya dgn dimensi kapasitas FISIK: "Ruangan" utk Majoo (hotel/salon) = bay/stall instalasi PPF-Kaca Film utk Ginnva. Dua kendala kapasitas berbeda (teknisi cukup ≠ bay cukup) — kalau nanti dibangun laporan utilisasi, sebaiknya pisah 2 dimensi ini, jangan digabung jadi satu angka. Ada "Laporan Utilisasi Ruangan" terpisah di sidebar (folder berikutnya) yg lebih pas dicek. |

**Temuan sampingan**: sidebar menyingkap lebih banyak submenu grup Laporan yang belum
di-screenshot: Laporan Utilisasi Ruangan, Laporan Karyawan, Laporan Persediaan, Laporan
Settlement — dan menu-menu besar baru di luar "Laporan": Analisa Laporan, Produk, Inventori,
Pelanggan.

## Penjualan / Laporan / Laporan Fasilitas / Laporan Utilisasi Ruangan

Sumber: `Majoo/Penjualan/Laporan/Laporan Fasilitas/Laporan Utilisasi Ruangan`

Pasangan lengkap dari "Utilisasi Teknisi" — sekarang utk bay/ruangan fisik. Kolom per ruangan:
Ruangan, Jenis Ruangan, Jam Operasional, Jam Tidak Tersedia, Jam Dipesan, Jam Selesai, Jam
Dibatalkan, **Jam Kosong**, **Rata-rata Utilisasi**. Filter: Semua Jenis Ruangan.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| **Metrik Utilisasi Bay/Stall Instalasi** (jam operasional vs terpakai vs kosong vs dibatalkan, per bay) | **BELUM ADA — PRIORITAS TINGGI (pasangan Utilisasi Teknisi)** | Menyatu dengan temuan "Metrik Utilisasi Teknisi" sebelumnya jadi 1 kebutuhan besar: **dashboard kapasitas operasional** yg menjawab 2 pertanyaan sekaligus — apakah bottleneck-nya di jumlah teknisi atau di jumlah bay/stall instalasi. Data mentah (booking + jadwal per store/bay) kemungkinan sudah ada di sistem, cuma belum diagregasi jadi laporan utilisasi. Direkomendasikan dibangun BERSAMAAN dgn Utilisasi Teknisi sbg 1 fitur kapasitas, bukan 2 fitur terpisah. |

---

## Penjualan / Laporan / Laporan Promo & Loyalti / Laporan Promo

Sumber: `Majoo/Penjualan/Laporan/Laporan Promo & Loyalti/Laporan Promo`

Total Transaksi dengan Promo, Nilai Promo, Total Penjualan dengan Promo. Tabel: Tanggal, Promo,
Jenis, Outlet, Jumlah Transaksi, Nilai. Grup ini juga punya Laporan Poin, Laporan Kupon,
Laporan Komplimen (belum di-screenshot).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Laporan performa promo/poin/kupon/komplimen | Sudah Tercatat di Memory Lain | Sudah ada dalam `project_penjualan_majoo_blocked_items` — halaman terkait promo/reward poin/komisi partner di Ginnva memang SENGAJA "Belum tersedia" menunggu keputusan boss/adopsi data staff. Tidak perlu dicatat ulang sbg temuan baru di sini — cek memory itu kalau keputusan sudah turun. Dikonfirmasi lagi via "Laporan Poin" (Poin Didapat/Ditukar/Dibatalkan per transaksi) & "Laporan Kupon" (Penukaran/Nilai Tukar per kupon) — kategori sama, keputusan sama, tidak perlu detail terpisah per sub-laporan (Poin/Kupon/Komplimen). Satu detail baru dari "Laporan Komplimen": ada kolom **Otorisasi** (komplimen/gratis butuh approval terpisah dari kasir, pola sama dgn Laporan Void) — relevan kalau nanti Ginnva izinkan staff memberi diskon/komplimen manual saat transaksi, tapi ini tetap masuk kategori blocked/pending sampai keputusan boss soal promo turun. |

---

## Penjualan / Laporan / Laporan Pajak / Laporan Pajak

Sumber: `Majoo/Penjualan/Laporan/Laporan Pajak/Laporan Pajak`

Jenis Pajak, Transaksi, DPP(Rp), Total Pajak(Rp).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Laporan pajak (PPN) per jenis | Sudah Tercatat di Memory Lain | Persis kategori PPN yang sudah pending keputusan boss di `project_penjualan_majoo_blocked_items` — tidak perlu tindakan baru sampai keputusan turun. |

**Catatan sampingan**: banner di halaman ini bilang "Service Charge" baru saja DIPISAH dari
Laporan Pajak jadi menu sendiri ("Laporan Service Charge") — bukan temuan penting, cuma info
housekeeping Majoo sendiri.

## Penjualan / Laporan / Laporan Pajak / Laporan Service Charge

Sumber: `Majoo/Penjualan/Laporan/Laporan Pajak/Laporan Service Charge`

Tanggal, Transaksi, Penjualan(Rp), Service Charge(Rp).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Laporan biaya layanan (%) tambahan di luar harga produk | Tidak Relevan | Pola resto/hotel — Ginnva kemungkinan sudah all-inclusive per paket PPF/Kaca Film, tidak charge biaya jasa terpisah. |

---

## Penjualan / Laporan / Laporan Kasir / Laporan Kas Kasir

Sumber: `Majoo/Penjualan/Laporan/Laporan Kasir/Laporan Kas Kasir`

Log kas kasir (uang masuk/keluar per shift POS): Transaksi, Tanggal, Outlet, Masuk(Rp),
Keluar(Rp), Kategori, Nama Login, Nama Device.

Sidebar menyingkap submenu grup "Laporan Kasir" lengkap: Laporan Kas Kasir, **Penjualan Per
Kasir**, **Penjualan Per Terminal**, Laporan Tutup Kasir, Laporan Tutup Toko.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Log kas kasir/cash drawer per shift+device | Belum Relevan Sekarang (terikat rencana POS) | Ini murni tracking POS fisik (cash drawer, tutup kasir/shift, per terminal). Ginnva belum punya modul POS/Kasir walk-in (`project_pos_plan`: belum dibangun). Seluruh grup "Laporan Kasir" (Kas Kasir, Tutup Kasir, Tutup Toko, Per Terminal) baru relevan SETELAH modul POS/Kasir dibangun — jangan bangun laporan ini duluan sebelum POS-nya sendiri ada. Dikonfirmasi lagi via "Penjualan Per Terminal" (Terminal, Outlet, Penjualan, Laba Kotor, Transaksi, Produk, Pengembalian) — sama-sama murni device/POS-specific. "Laporan Tutup Kasir" jauh lebih detail lagi: rekonsiliasi kas fisik (Modal Awal, Kas Masuk/Keluar, Saldo Akhir, Total Tunai Aktual vs Sistem, **Selisih**) — pola anti-fraud standar POS, tetap sama sekali tidak applicable sebelum ada modul POS. "Laporan Tutup Toko" (agregat semua kasir di 1 outlet) menambah kolom **Otorisasi** (approval tutup toko) — menuntaskan seluruh grup "Laporan Kasir", semuanya terikat rencana POS. |
| **Penjualan Per Kasir** (ranking staff yg closing) | **Dikoreksi setelah dicek langsung — TERIKAT POS** | Filter dropdown-nya eksplisit "Kasir Bayar" (bukan nama generik), jadi laporan ini memang spesifik role kasir pembayaran POS, bukan performa teknisi/installer. Kolom: Kasir, Outlet, Penjualan(Rp/%), Laba Kotor(Rp/%), Jumlah Transaksi(%), Jumlah Produk(%), Pengembalian(Rp) — semua berbasis siapa yg MEMPROSES PEMBAYARAN, bukan siapa yg MENGERJAKAN JOB. Kebutuhan "ranking teknisi/installer paling produktif" TETAP relevan buat Ginnva (temuan Dashboard Penjualan sebelumnya), tapi HARUS dibangun sbg laporan terpisah berbasis assignment booking, BUKAN dgn mengadopsi laporan "Penjualan Per Kasir" Majoo ini mentah-mentah — supaya tidak salah kaprah menyamakan 2 konsep berbeda (pemroses bayar vs pengerja job). |

---

## Penjualan / Laporan / Laporan Deposit / Penjualan Deposit

Sumber: `Majoo/Penjualan/Laporan/Laporan Deposit/Penjualan Deposit`

**Penting: "Deposit" Majoo BEDA KONSEP dari "Uang Muka" yang sudah dicatat sebelumnya.** Kolom:
No Transaksi, Tanggal Transaksi, Kasir, Pelanggan, Nama Deposit, Jenis Deposit, Nilai Deposit,
**Kedaluwarsa**, Total Pembayaran, Metode Pembayaran. KPI: Jumlah Transaksi Deposit, Total
Deposit Diterima Pelanggan, Total Penjualan Deposit. Sidebar juga ada "Deposit Kadaluarsa" &
"Sisa Deposit" — mengonfirmasi ini **saldo prabayar/store credit customer** (top-up saldo/voucher
bernilai uang, punya masa berlaku, bisa dipakai transaksi apa saja nanti), BUKAN uang muka utk
1 booking spesifik.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Saldo prabayar/store credit customer dgn masa berlaku | **Belum Ada — Perlu Keputusan Bisnis Dulu** | Konsep terpisah dari DP: customer beli/top-up saldo di muka (mis. paket "beli saldo Rp 5jt, dipakai kapan saja utk servis apapun"), bukan DP khusus 1 booking. Relevan HANYA kalau Ginnva mau menawarkan skema pra-bayar/member-saldo semacam ini (mis. member VIP top-up saldo dulu, dipakai bertahap). Ini murni pertanyaan bisnis (apakah model ini mau diadopsi Ginnva atau tidak), bukan gap teknis yg otomatis perlu dibangun — TIDAK boleh disalahartikan sbg "DP" (skema Uang Muka tetap kebutuhan terpisah, prioritas tinggi, lihat temuan sebelumnya). Dikonfirmasi via "Deposit Kadaluarsa" (No Transaksi, Pelanggan, **Nama Paket**, Tanggal/Nominal Kedaluwarsa) — makin jelas ini saldo prabayar TERIKAT PAKET tertentu, bukan wallet umum. "Sisa Deposit" (snapshot Pelanggan + Sisa Deposit per tanggal) menuntaskan grup ini — sama kategori.

**Master-data "Deposit"** (`Produk/Deposit`, form "Tambah Deposit") melengkapi gambaran: Harga
Jual Deposit vs **Saldo Deposit bisa BEDA nominal** (mekanisme bonus, mis. beli Rp90rb dapat
saldo Rp100rb — insentif top-up di muka). Dibatasi ke **"Produk Pilihan"** (whitelist
barang/paket spesifik yang bisa dibayar pakai deposit ini, bukan bebas semua produk). **Tipe
Deposit**: Personal (1 pelanggan) vs **Grup** (1 saldo dipakai bersama 2-100 anggota terdaftar —
cocok utk keluarga/korporat). Masa Berlaku opsional dgn Batasan Hari (1 Bulan/Kustom). Peringatan
sistem: "Penjualan deposit di kasir harus dalam transaksi terpisah, tidak bisa digabung dgn
penjualan produk lain." Semua detail ini tetap kategori sama (pending keputusan bisnis
prabayar), bukan gap teknis mendesak. |

---

## Penjualan / Laporan / Laporan Pelanggan / Laporan Pelanggan

Sumber: `Majoo/Penjualan/Laporan/Laporan Pelanggan/Laporan Pelanggan`

Laporan customer lifetime value/repeat-rate: Nama, Alamat, No Ponsel, Tanggal Registrasi,
Outlet Registrasi, Total Transaksi, Total Penjualan(Rp), **Kunjungan Terakhir**, **Rata-rata
Kunjungan/Bulan**, **Rata-rata Penjualan/Bulan**.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Metrik repeat-customer: kunjungan terakhir + rata-rata frekuensi kunjungan/penjualan per bulan | **Belum Ada — Relevan** | Sangat applicable utk Ginnva: "kunjungan" → booking. Metrik frekuensi kembali penting utk bisnis jasa (re-service, re-coating, klaim garansi, deteksi customer loyal utk program referral). `CustomerResource` kemungkinan sudah ada tapi belum jelas apakah sudah menghitung agregat ini (kunjungan terakhir, rata-rata/bulan) — perlu dicek terpisah kalau mau diprioritaskan. |

---

## Penjualan / Laporan / Laporan Persediaan / Lap. Ringkasan Persediaan

Sumber: `Majoo/Penjualan/Laporan/Laporan Persediaan/Lap. Ringkasan Persediaan`

**Catatan konteks**: data contoh di halaman ini ("H10 Medium"/"H10 Small", Kategori "PPF")
tampak seperti produk ASLI Ginnva yang diinput ke akun trial Majoo, bukan data dummy generik —
memperkuat bahwa screenshot-screenshot ini dari eksperimen langsung dgn data Ginnva.

Kolom: Nama Produk, SKU, Jenis, Kategori, Kuantitas, Satuan, Harga Modal, Total Nilai
Persediaan. Filter: Semua Kategori, Semua Jenis.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Laporan nilai persediaan (stok x harga modal) | Sudah Ada (kemungkinan besar) | `InventoryStatsOverview` sudah menghitung nilai stok bahan baku/consumable — kemungkinan sudah setara atau lebih baik (sudah breakdown menipis/kedaluwarsa/dead-stock). |

## Penjualan / Laporan / Laporan Persediaan / Lap. Detail Persediaan

Sumber: `Majoo/Penjualan/Laporan/Laporan Persediaan/Lap. Detail Persediaan`

Mutasi stok per outlet+produk (wajib pilih outlet & produk dulu): Tanggal Transaksi, Transaksi,
No Transaksi, Kuantitas, Satuan, Harga Jual/Beli, Stok, Harga Modal, Total Nilai Persediaan.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Ledger mutasi stok per item | Sudah Ada (kemungkinan besar) | `RawMaterialMovementResource`/`InventoryMovementResource`/`ConsumableItemMovementResource` sudah ada — kemungkinan besar setara atau lebih lengkap. |

## Penjualan / Laporan / Laporan Persediaan / Laporan Stok Kedaluwarsa

Sumber: `Majoo/Penjualan/Laporan/Laporan Persediaan/Laporan Stok Kedaluwarsa`

SKU, Nama Produk, Batch Number, Tanggal Kedaluwarsa, Stok Kedaluwarsa.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Laporan stok kedaluwarsa per batch | Sudah Ada | `MaterialsNeedingAttentionWidget` sudah melacak kedaluwarsa per batch (alasan "Kedaluwarsa"/"Mendekati Kedaluwarsa"). |

## Penjualan / Laporan / Laporan Persediaan / Laporan Serial Number

Sumber: `Majoo/Penjualan/Laporan/Laporan Persediaan/Laporan Serial Number`

Outlet, Serial Number, Jenis Transaksi, No Transaksi, Tanggal, Stok, Stok Akhir.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Tracking per Serial Number (1 serial = 1 unit fisik utuh) | Tidak Cocok utk Kasus Roll PPF | Pola ini cocok utk barang seperti elektronik (1 serial = 1 unit tak terbagi). TIDAK cocok utk kasus roll PPF/kaca film Ginnva, dimana 1 roll dipotong-potong & dipakai di BANYAK kendaraan berbeda — beda dari 1-serial-1-unit. "Laporan Batch Number" (folder berikutnya) kemungkinan jauh lebih relevan, terhubung ke pertanyaan traceability yg belum terjawab di `project_sisa_roll_dibatalkan`. |

## Penjualan / Laporan / Laporan Persediaan / Laporan Batch Number

Sumber: `Majoo/Penjualan/Laporan/Laporan Persediaan/Laporan Batch Number`

**INI KEMUNGKINAN JAWABAN utk pertanyaan traceability roll_number vs Garansi yang belum
terjawab sejak `project_sisa_roll_dibatalkan` (2026-09-15).** Kolom: Outlet, Batch Number,
Tanggal Kedaluwarsa, Sisa Hari Kedaluwarsa, **Jenis Transaksi**, **No Transaksi**, Tanggal,
Stok, Stok Akhir — menampilkan daftar SEMUA transaksi yang mengonsumsi material dari 1 batch
tertentu, bukan cuma sisa stok batch itu.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Traceability: pilih 1 Batch Number → lihat semua transaksi/job yang konsumsi dari batch itu | **Belum Ada — SANGAT RELEVAN, kemungkinan jawaban gap lama** | Pola ini pas menjawab kebutuhan lama: kalau 1 roll PPF/kaca film (= 1 batch) bermasalah, owner perlu tahu SEMUA booking/kendaraan yang pernah dapat bahan dari roll itu utk recall/klaim garansi. Solusinya BUKAN fitur terpisah (RollScrapPool yg sudah dihapus), tapi memperlakukan roll_number SEBAGAI batch_number pada RawMaterial, dgn setiap konsumsi material tercatat menyimpan referensi ke booking/SPK terkait — lalu laporan ini query balik "batch X dipakai di transaksi mana saja". **Perlu didiskusikan dgn user dulu** sebelum desain data (apakah RawMaterial Ginnva sudah punya konsep batch tracking sama sekali, dan apakah movement/consumption record sudah/bisa disambungkan ke booking_id) — ini keputusan arsitektur, bukan langsung dibangun. |

**Rekomendasi**: bahas ulang `project_sisa_roll_dibatalkan` dengan user, sebutkan temuan ini
sbg kemungkinan pendekatan baru (batch-based traceability, bukan pool terpisah) sebelum
memutuskan bangun/tidak.

---

## Penjualan / Laporan / Laporan Settlement / Order Online

Sumber: `Majoo/Penjualan/Laporan/Laporan Settlement/Order Online`

Fitur payment gateway/wallet milik Majoo sendiri: Total Saldo Merchant, Tarik Saldo, Saldo
Ditahan, Saldo Pelanggan, aturan biaya admin & durasi pencairan (T+1, cut-off jam 14.00 WIB),
Riwayat Saldo (Pemasukan/Penarikan).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Settlement saldo dari payment gateway order online milik Majoo | Tidak Relevan | Ini infrastruktur payment gateway/marketplace Majoo sendiri (nahan saldo, biaya admin, jadwal pencairan). Ginnva tidak punya sistem serupa & booking dibayar langsung (transfer/cash/EDC), bukan lewat wallet marketplace. Relevan HANYA kalau Ginnva suatu saat bangun sistem booking-online dgn payment gateway sendiri — bukan kebutuhan sekarang. |

## Penjualan / Laporan / Laporan Settlement / QRIS

Sumber: `Majoo/Penjualan/Laporan/Laporan Settlement/QRIS`

Rekonsiliasi pembayaran QRIS riil ke rekening bank (BEDA dari "Order Online" — bukan wallet
marketplace Majoo). Tab: Transaksi Berhasil/Gagal, Settlement Diproses/Tertunda/Berhasil. KPI:
Total Penjualan, **Total MDR** (fee provider QRIS), Total Settlement, Settlement Tertunda.
Kolom: No Transaksi, Tanggal Transaksi, Tipe QRIS, Nomor Referensi, Total, Status Settlement,
Tanggal Settlement.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Rekonsiliasi QRIS: penjualan bruto vs settlement bersih (dikurangi MDR) + status pencairan | **Belum Ada — Relevan (kalau Ginnva terima QRIS)** | Kalau Ginnva menerima pembayaran via QRIS, ini berguna utk rekonsiliasi keuangan (uang yg benar2 masuk ke rekening = penjualan − MDR, dan kapan cair). Referensi QRIS/MDR sudah disebut di `SalesSummaryReport.php`/`SalesSummaryExport.php` (lihat temuan di atas) — perlu dicek apakah sudah cukup atau butuh laporan status-settlement terpisah. |

---

## Karyawan / Laporan Karyawan / Absensi

Sumber: `Majoo/Penjualan/Laporan/Laporan Karyawan/Absensi` (data pakai nama staff ASLI Ginnva,
termasuk Antony Fedryandi sendiri — bukan dummy)

Jauh lebih kaya dari sekadar clock-in/out:
- KPI 6 kategori: Masuk Tepat Waktu/Terlambat/Lebih Cepat, Keluar Tepat Waktu/Lebih Cepat/Lebih Lama
- **Foto verifikasi** saat absen masuk (selfie attendance) ditampilkan di tabel
- Total Jam Kerja Terjadwal vs Total Jam Kerja Aktual (perbandingan langsung per baris)
- **Alur approval**: ikon centang/silang + kolom Catatan per baris — manager approve/reject
  absensi yang anomali (terlambat dkk) dgn alasan tertulis
- Bulk-select checkbox per baris

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Foto selfie verifikasi saat absen | **Perlu Verifikasi** | `project_keuangan_absensi_plan` menyebut "clock in/out via mobile app" tapi tidak jelas apakah sudah ada verifikasi foto. Foto mencegah titip-absen (buddy punching) — relevan kalau belum ada. |
| Kategorisasi detail 6 jenis keterlambatan/kecepatan (bukan cuma "terlambat: ya/tidak") | **Belum Ada — Relevan** | Berguna utk pola analisis (siapa yg sering keluar lebih cepat dari jadwal vs siapa yg sering lembur/keluar lebih lama). |
| Alur approval utk absensi anomali (bukan cuma dicatat pasif) | **Belum Ada — Relevan** | Manager bisa approve/reject dgn catatan tertulis — relevan utk kasus lupa absen/error device yg butuh koreksi manual dgn jejak audit siapa yg approve. |

## Karyawan / Laporan Karyawan / Komisi Tetap

Sumber: `Majoo/Penjualan/Laporan/Laporan Karyawan/Komisi Tetap`

Komisi rate tetap per staff: Nama, Cabang, Penjualan, Produk, Komisi. KPI: Total Komisi, Komisi
Produk, Komisi Penjualan.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Laporan komisi rate tetap per staff | Sudah Ada (kemungkinan besar) | Modul Komisi Ginnva (Technician commission) kemungkinan sudah setara. |

Sidebar ada juga **"Komisi Bertingkat"** (tiered commission, mis. % komisi naik seiring volume
penjualan) — berpotensi lebih canggih dari skema flat-rate, perlu dicek terpisah kalau mau tahu
apakah Ginnva sudah punya skema serupa atau masih flat.

---

## Penjualan / Analisa Laporan / Waktu Teramai Produk

Sumber: `Majoo/Penjualan/Analisa Laporan/Waktu Teramai Produk` (data pakai nama produk ASLI
Ginnva: "Ginnva Signature", "Panoramic")

Analisis hari-paling-ramai per produk: grafik distribusi Minggu-Sabtu, bisa bandingkan
multi-produk ("+ Perbandingan"). Tabel: Produk, Waktu (hari), Jumlah Produk/%, Jumlah
Transaksi/%, Penjualan(Rp)/%.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Analisis hari/waktu puncak permintaan per layanan | **Belum Ada — Relevan** | Melengkapi temuan Utilisasi Teknisi/Ruangan: yg itu jawab "berapa % kapasitas terpakai", ini jawab "KAPAN puncaknya". Berguna utk penjadwalan staf (mis. kalau PPF ramai di hari Rabu, siapkan lebih banyak teknisi hari itu). |

"Waktu Teramai Penjualan" (re-audit teliti) — sama konsep tapi agregat keseluruhan (bukan per
produk): Total Penjualan/Transaksi/Produk/Pelanggan per hari-dalam-minggu. Chart legend juga
pakai **checkbox toggle 3-metrik** (Rata-rata Penjualan/Transaksi/Pelanggan) — pola sama persis
dgn Penjualan Per Periode. Kolom tabel pakai istilah "**Tamu**"/"Tamu %" utk pelanggan (sisa
istilah dine-in Majoo, harusnya "Pelanggan" kalau diadaptasi Ginnva). Data riil menunjukkan
puncak Rabu (55,3%) & Kamis (44,7%) dari total Rp 10.524.000. Tidak perlu entri terpisah, sama
rekomendasinya dgn temuan Waktu Teramai Produk di atas.

## Penjualan / Analisa Laporan / Perputaran Stok

Sumber: `Majoo/Penjualan/Analisa Laporan/Perputaran Stok`

Nama, Jenis, Terjual, Sisa, **Perputaran Stok** (turnover ratio), **Hari Terjual** (estimasi
berapa hari stok akan habis berdasarkan kecepatan konsumsi). Data pakai nama bahan ASLI Ginnva
(Actifoom Energy, Clay Blue, Clear Glass, dst).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Metrik turnover ratio + estimasi hari-stok-habis (forward-looking, berbasis kecepatan konsumsi) | **Belum Ada — Relevan** | `MaterialsNeedingAttentionWidget` sudah bandingkan stok vs reorder_point (snapshot statis), tapi belum ada metrik kecepatan konsumsi/proyeksi hari-habis yg lebih dinamis — berguna utk keputusan procurement (kapan & berapa banyak reorder bahan PPF/kaca film berdasarkan tren pemakaian, bukan cuma ambang tetap). |

## Penjualan / Analisa Laporan / Kepuasan Pelanggan

Sumber: `Majoo/Penjualan/Analisa Laporan/Kepuasan Pelanggan`

Rating kepuasan 5-bintang pasca-transaksi + ulasan teks bebas, breakdown donut chart Sangat
Puas s/d Sangat Tidak Puas.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Rating + ulasan pasca-transaksi | **Sudah Ada — LEBIH BAIK** | `StoreReview.php` sudah tersambung ke `booking_id`+`customer_id`, punya `sentiment`+`tags`+**alur follow-up** (followed_up_at/by/note) khusus utk ulasan negatif — Majoo cuma tampilkan bintang+teks tanpa jejak tindak lanjut. Tidak perlu tindakan. |

---

## Penjualan / Produk / Daftar Departemen

Sumber: `Majoo/Penjualan/Produk/Daftar Departemen`

**Temuan penting**: form "Tambah Departemen" menyingkap daftar Kategori produk ASLI Ginnva yang
sudah diinput ke trial Majoo: **PPF, Detailing, Window Film** — ternyata ADA 3 kategori, bukan 2
(PPF vs Kaca Film) seperti asumsi sebelumnya dari `BookingRevenueByCategoryChart`.

**Fitur CRUD master-data**: halaman "Tambah Departemen"/"Tambah Kategori" (form dgn nama,
urutan tampilan, ikon, toggle "Tampil di Menu", pilih departemen induk) + menu "..." per baris
(Ubah/Lihat Produk/Hapus) — pola CRUD master-data standar. Sudah setara: ditemukan
`FilmProductResource.php` & `MaterialCategoryResource.php` di Ginnva. Tidak perlu tindakan.

Dicek `BookingRevenueByCategoryChart.php:70-93` — Booking model CUMA punya flag `product_ppf`
dan `product_kaca_film` (boolean), **TIDAK ADA `product_detailing`** sama sekali. Query SQL split
50/50 cuma menghitung 2 kategori itu; booking tanpa flag PPF/Kaca Film sengaja masuk `ELSE 0`
(tidak dipaksa ke salah satu — desain yang benar), tapi ini juga berarti kalau ada booking
Detailing murni, revenue-nya HILANG dari breakdown kategori sama sekali (masuk `ELSE 0`, tidak
tercatat di kategori mana pun).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Kategori "Detailing" sbg lini bisnis terpisah dari PPF & Window Film | **✅ SUDAH ADA — koreksi 2026-09-17, temuan awal saya SALAH** | **Koreksi penting**: temuan awal saya (Detailing belum ada di Booking) SALAH — cuma cek `BookingRevenueByCategoryChart` tanpa cek memory `project_penjualan_majoo_blocked_items`. Faktanya: Detailing **SUDAH DIBANGUN PENUH 2026-09-10** — `Booking.product_detailing` (boolean+toggle "Termasuk Jasa Detailing"), `FilmProduct.product_type='detailing'` (SKU `SVC-DETAILING`), Master Resep Detailing, Memo Barang auto-isi dari 2 sumber (produk film + Detailing). **Satu-satunya yang masih blocked: `base_price` Detailing masih Rp 0** (sama kondisi dgn semua produk lain — HPP & harga jual belum final), makanya belum masuk laporan penjualan. `BookingRevenueByCategoryChart` MEMANG belum include Detailing (cuma split PPF/Kaca Film) — begitu harga final, chart ini perlu diupdate tambah 1 kategori. Bukan gap arsitektur, cuma nunggu harga final + update 1 widget chart. |

---

## Penjualan / Produk / Produk Barang

Sumber: `Majoo/Penjualan/Produk/Produk Barang` — 34 produk RIIL Ginnva terdaftar (mis. "Detailing
Sebelum PPF", "EV-07 Extra Large/Large/Medium/Small" — kemungkinan nama produk film × ukuran
kendaraan, harga jual Rp 13,8jt-20jt per varian ukuran).

**⚠️ Ketidaksesuaian perlu diklarifikasi**: `project_penjualan_majoo_blocked_items` mencatat
"Katalog FilmProduct final (11 produk)" per 2026-09-10 (Ginnva Signature, Ginnva Platinum, 4
PPF, Detailing, 3 Panoramic). Tapi trial Majoo di sini punya **34 produk**, termasuk nama yg
tidak muncul di daftar 11 itu (EV-07, "Detailig Sebelum PPF"). Kemungkinan: (a) trial Majoo diisi
LEBIH DULU/terpisah dari proses "aktifkan penuh pricing" 2026-09-10 shg datanya belum sinkron,
atau (b) katalog Ginnva sudah berkembang lagi setelah 2026-09-10 dan memory itu sudah usang.
**Perlu ditanyakan ke user**: yang 34 produk di trial Majoo ini SUMBER kebenarannya, atau yang
11 produk final di `FilmProduct` sistem Ginnva? Jangan asumsikan salah satu benar tanpa
konfirmasi.

**Fitur list halaman**: ikon panah melingkar = **"Log Aktivitas"** (terkonfirmasi user) — audit
trail perubahan data produk: Tanggal, Nama Produk, Jenis Perubahan, Sebelum/Sesudah, User, ikon
mata (detail), 33 entri berpaginasi, filter 14 hari terakhir + search
+ Impor Data + Ekspor Data (2 tombol teks, BEDA dari "Ekspor Laporan" biasa — ini bulk
import/export MASTER DATA produk via CSV, bukan laporan transaksi), Tambah Produk,
bulk-select checkbox, tab Semua/Tampil di Menu/Tidak Tampil di Menu, filter kategori, baris bisa
di-expand (chevron) menampilkan info "Grup" & status "Monitor Persediaan: Aktif".

**Form "Tambahkan Produk"** — wizard multi-tab: Informasi Produk, Varian, Ekstra, Resep, majoo
Order. Field-field kunci di tab Informasi Produk:
- Daftar Outlet (multi-select), Nama Produk, Deskripsi Produk, Foto Produk (maks 5 foto)
- Kategori Produk + link inline "Buat Kategori Baru" (tanpa keluar dari form produk)
- Opsi Lanjutan: checkbox Produk Favorit, Tampil di Menu
- **Monitor Persediaan** (toggle) + **Stok Minimum Produk** (reorder point per PRODUK, bukan cuma per bahan baku)
- **Serial Number** (toggle "Aktifkan produk memiliki Serial Number") — per produk, opsional
- **Batch Number** (toggle "Aktifkan produk memiliki Batch Number") — per produk, opsional
- Grup (dropdown) + checkbox "Tetapkan sebagai Induk" (parent-variant grouping)
- "Izinkan Ubah Produk Tidak Dijual" (toggle) — staff bisa tandai produk unavailable saat POS
- Counter karakter "0/255" di field Nama Produk; field "Stok Minimum Produk" disabled/abu-abu
  saat toggle Monitor Persediaan OFF (field kondisional); ikon info (i) di sebelah label Serial
  Number & Batch Number (tooltip, belum diklik isinya)

**Ditemukan di tabel list (re-audit)**: badge hijau **"Resep"** di bawah nama produk "Detailig
Sebelum PPF" — menandakan produk ini punya BOM/resep bahan baku terhubung (tidak semua produk
bertag ini). Kolom Harga Modal & Harga Beli Terakhir semuanya Rp 0 di semua baris yg terlihat
(kemungkinan data belum diisi di trial, bukan indikasi fitur). Pada baris yg di-expand (chevron),
sub-panel menampilkan "Grup" (kosong) & status "Monitor Persediaan: • Aktif" (hijau) — konfirmasi
toggle ini SUDAH diaktifkan manual utk produk "EV-07 Extra Large" spesifik.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Impor/Ekspor bulk data produk (CSV) + riwayat impor | **Belum Ada — Relevan** | Beda dari gap "Ekspor Laporan" yg sudah dicatat — ini utk bulk-update katalog produk (mis. update harga massal), bukan laporan. `FilmProductResource` blm dicek apakah punya import/export bulk maupun log riwayat impor. |
| **Toggle Batch Number PER PRODUK** (bukan global) | **SANGAT RELEVAN — mekanisme konkret utk traceability roll** | Ini menjawab detail teknis dari temuan "Laporan Batch Number" sebelumnya: Majoo mengaktifkan batch tracking di level PRODUK individual (toggle on/off), bukan fitur terpisah. Kalau Ginnva mau bangun traceability roll_number, pola yg tepat: tambah field opsional di `FilmProduct`/`RawMaterial` (mis. `tracks_batch: boolean`), bukan bikin tabel RollScrapPool terpisah (yg sudah dihapus). Perkuat rekomendasi di `project_sisa_roll_dibatalkan`. |
| Stok Minimum PER PRODUK (bukan cuma per bahan baku) | **Perlu Verifikasi** | Ginnva sudah punya reorder_point di RawMaterial, tapi FilmProduct (barang jadi/kit terjual) blm jelas apakah py reorder point sendiri. |
| Grup produk (parent-variant, mis. EV-07 S/M/L/XL sbg 1 grup) | **Perlu Verifikasi** | Berguna kalau Ginnva mau kelompokkan varian ukuran produk yg sama jadi 1 tampilan induk. |
| "Buat Kategori Baru" inline dari form produk (tanpa pindah halaman) | Nice-to-have UX | Polesan kecil, bukan prioritas. |
| **Serial Number & Batch Number MUTUAL EXCLUSIVE** (aktifkan salah satu otomatis nonaktifkan yg lain) + field wajib "Memiliki Tanggal Kedaluwarsa" (Ya/Tidak) muncul HANYA saat Batch Number ON | **Detail desain penting, terlewat di audit awal (ditemukan saat re-scan)** | 1 produk cuma boleh pakai SATU mekanisme tracking (serial ATAU batch, tidak dua2nya). Field expiry OPSIONAL per keputusan (batch tanpa expiry tetap valid, mis. kalau roll PPF/kaca film tidak py tanggal kedaluwarsa formal) — penting diperhatikan kalau Ginnva desain skema batch serupa: JANGAN paksa expiry wajib diisi. |
| Modal "Ubah Multiprice" → tabel harga riil + fitur "Preset Harga Produk" (bulk Markup/Markdown %/Rp ke banyak produk sekaligus, atau "Reset dan tentukan manual") | **Belum Ada — Relevan kecil** | Berguna kalau Ginnva mau update harga massal (mis. semua produk PPF naik 5%) tanpa edit satu-satu. Data harga riil terkonfirmasi lagi: Ginnva Signature S/M/L/XL Rp 6,11jt/7,02jt/7,93jt/10,66jt; Ginnva Platinum S Rp 3,51jt. |
| **Log Aktivitas perubahan produk** (audit trail: siapa ubah apa kapan, sebelum/sesudah) | **BELUM ADA — Relevan** | Dikonfirmasi: `FilmProduct.php` TIDAK pakai `LogsActivity` sama sekali. Perubahan harga/kategori/data produk lain saat ini tidak punya jejak audit. Tinggal tambah trait `LogsActivity` (pola sudah ada di banyak model lain per `feedback_audit_trail_sweep`) — perbaikan murah & cepat kalau diprioritaskan. |

**Sub-halaman "Harga dan Satuan"** (masih tab Informasi Produk, discroll ke bawah): Satuan
(dropdown), SKU, Konversi (disabled default 1), Min. Pembelian, Harga Jual*, Harga Beli
Terakhir (disabled), Dimensi Produk* (Volume P×L×T + info icon, Berat + info icon), tombol
"+ Tambah Satuan" (multi-satuan, mis. jual per pcs & per box beda harga). **"Ubah Harga Jual"**
toggle + field kondisional **"Maks. X%"** (izinkan kasir nego harga terbatas dgn cap %, TANPA
approval manual) + peringatan "Pastikan harga jual lebih tinggi dari harga beli". **"Harga
Grosir"** toggle + info icon, "Maksimal 5 harga grosir" (tiered wholesale pricing).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Multi-satuan per produk (jual per pcs vs per box/meter, beda harga) | Rendah Prioritas / Perlu Verifikasi | Bisa relevan kalau Ginnva jual bahan baku eceran (mis. per meter kaca film), tapi utk paket instalasi biasanya cuma 1 satuan. |
| **Kasir bisa nego harga terbatas dgn cap % (Maks. X%), tanpa approval manual** | **Belum Ada — Relevan, keputusan bisnis** | Beda dari Promo (kupon/diskon terjadwal) — ini kewenangan staff nego harga langsung di kasir dgn batas atas persentase. Perlu ditanyakan: apakah Ginnva mau staff booking punya kewenangan serupa (mis. nego harga PPF max 5% tanpa nunggu approval atasan)? Kalau ya, ini fitur baru; kalau prinsipnya harga selalu fix, TIDAK relevan. |
| Harga Grosir bertingkat (maks 5 tier) | Rendah Prioritas | Relevan cuma kalau ada skema partner/reseller/dealer beli banyak unit produk fisik — bukan pola jasa instalasi biasa. |

**Sub-halaman "Varian"**: toggle "Produk Memiliki Varian". Kalau ON: Tipe Varian → Nama Varian
(field 0/14 char, mis. "Ukuran") + trash-icon hapus, "Pilihan Varian 1" (field 0/30 char, mis.
"Small") + "+ Tambah Pilihan Varian" (nambah lebih banyak pilihan di 1 tipe varian) + "+ Tambah
Varian" (nambah tipe varian ke-2, mis. Warna DAN Ukuran sekaligus) → Daftar Varian: tabel hasil
kombinasi varian (Pilihan Varian, Harga Beli Terakhir, Harga Jual, SKU, Tampil di Menu) —
per-kombinasi punya harga & SKU sendiri.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Varian produk (1 produk induk, banyak kombinasi harga/SKU) | **✅ SUDAH SOLVED — koreksi, temuan awal saya keliru** | Sempat saya kira "EV-07 S/M/L/XL sbg 4 produk terpisah" adalah gap yg butuh pola Varian generik Majoo. Setelah cek `project_penjualan_majoo_blocked_items`: Ginnva SUDAH punya solusi lebih tepat guna — tabel `film_product_prices` (film_product_id, vehicle_size S/M/L/XL/XXL, price) + `PriceCalculator::priceFor(product, size)`/`matrix(product)`. 1 `FilmProduct` bisa py banyak harga per ukuran kendaraan lewat 1 baris/ukuran di tabel harga terpisah, BUKAN via 4 produk mandiri maupun via Varian generik ala Majoo. Model final ini juga sudah lolos uji riil: draft `base_price × koefisien` sempat dicoba lalu DITOLAK (2026-09-10) karena tidak bisa reproduksi harga Majoo yg sebenarnya matriks, bukan pengali tunggal. **Tidak perlu tindakan** — kemungkinan besar 4 baris "EV-07 X/L/M/S" yg saya lihat di Produk Barang Majoo cuma cerminan struktur ASLI Majoo (yg memang tidak punya konsep matriks harga per ukuran secantik solusi Ginnva), bukan berarti Ginnva perlu ikut pola itu. |

**Sub-halaman "Ekstra"**: toggle "Produk Memiliki Ekstra" + info icon, toggle **"Ubah Data
Ekstra"** + info icon (izinkan kasir ubah harga ekstra saat transaksi?), "Atur Ekstra" → dropdown
"Pilih Ekstra" (ekstra dipilih dari MASTER DATA ekstra yg sudah ada — bukan dibuat inline di
sini) + tombol "Tambah Ekstra" (disabled sampai ada yg dipilih).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Ekstra sbg master data terpisah, ditautkan per produk (bukan dibuat ulang tiap produk) | Terhubung ke temuan Ekstra sebelumnya | Menguatkan pertanyaan bisnis yg sama: apakah extra_service Ginnva (Watermark Remover dkk) perlu jadi master data dgn harga sendiri yg bisa ditautkan ke banyak produk/paket, bukan checklist statis di SPK. |

**Sub-halaman "Resep"**: banner "Baru! Gunakan Master resep untuk memilih resep yang sudah
tersedia, khusus pengguna paket **Advance, Prime dan Prime+**" (ini fitur berjenjang paket
subscription Majoo sendiri, cuma info konteks, bukan hal yg perlu ditiru). "Resep Produk"
toggle ON → "Atur Bahan Baku": Bahan Baku (dropdown), **Harga Modal (Rp, auto-terisi/disabled —
dihitung otomatis dari resep)**, Takaran + Satuan (disabled), "+ Tambah Bahan Baku".

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Resep/BOM yang AUTO-HITUNG Harga Modal (HPP) dari bahan baku × takaran | **Sudah Diketahui & Sengaja Ditunda** | Dicek `MasterResepResource.php` — SUDAH ADA & komentarnya eksplisit: "SENGAJA tanpa harga/HPP (keputusan Ginnva belum turun)". Bukan gap yg belum disadari — auto-hitung HPP dari resep ini persis fitur yg sedang menunggu keputusan bisnis tsb. Temuan ini MENGUATKAN urgensi keputusan itu (Majoo sudah biasa melakukannya, artinya technically feasible & lazim). |

**Sub-halaman "majoo Order"**: Daftar Outlet, toggle "majoo Order" (tampil di app order-online
Majoo — TIDAK relevan langsung, itu app Majoo sendiri). Checkbox **"Harga majoo Order"** + Rp
field kondisional (harga BEDA utk channel online vs in-store) + tombol "Lihat Preset". Checkbox
"Tampilkan Produk Rekomendasi" + info icon. **"Status Ketersediaan Produk"**: toggle "Ikuti
Stok" (status produk otomatis ikut stok sistem) + info icon. **"Produk Tidak Dijual"**: toggle
"Dapat Dijual" + info icon, deskripsi "Produk tetap tampil di katalog, tetapi tidak bisa
dibeli" (soft-disable tanpa hapus dari katalog). "Keterangan/Spesifikasi Produk": **rich text
editor** (Bold/Italic/Underline, ukuran font, alignment, bullet/numbered list), limit 5000 char.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Harga beda per channel (online vs in-store) | Tidak Relevan Sekarang | Terikat konsep "majoo Order" (app online Majoo) — relevan HANYA kalau Ginnva bangun booking-online dgn harga promo khusus online. |
| Toggle "Ikuti Stok" (status tersedia otomatis ikut stok sistem) | Perlu Verifikasi | Utk produk fisik (bukan jasa) — cek apakah `FilmProduct` Ginnva sudah auto-nonaktif kalau stok 0. |
| **"Produk Tidak Dijual" (soft-disable, tetap di katalog tapi tidak bisa dibeli)** | **Belum Ada — Relevan kecil** | Berguna utk produk yg sementara di-pause (mis. varian ukuran lagi kosong bahan) tanpa harus hapus/sembunyikan total dari katalog. Nice-to-have, bukan prioritas tinggi. |
| Rich text editor utk deskripsi/spesifikasi produk | Rendah Prioritas | Polesan UX, `FilmProduct` kemungkinan pakai textarea biasa — cukup kalau kebutuhan cuma teks deskriptif sederhana. |

**Modal "Ubah Multiprice"** (dari tombol "Lihat Preset"): Detail majoo Order (Daftar Outlet,
Status, Nama Tipe, Deskripsi) + **Grup Pelanggan** (dropdown pilih grup, mis. "majoo Order" sbg
1 grup) → "Atur Produk dan Harga": pilih produk mana yg kena harga khusus utk grup pelanggan
itu.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Harga berbeda per Grup Pelanggan (bukan cuma per channel) | **Beda dgn `PriceRule` Ginnva, DAN `PriceRule` sendiri sudah non-aktif** | Koreksi tambahan: `PriceRuleResource` ("Koefisien Harga") BUKAN cuma beda konsep dari Grup Pelanggan — per `project_penjualan_majoo_blocked_items`, model `base_price × koefisien` ini sudah DITOLAK 2026-09-10 (diganti model matriks `film_product_prices`), `PriceRuleResource` DI-HIDE dan tabel `price_rules` dibiarkan dormant (tidak dihapus). Jadi resource itu bukan cuma "belum menjawab" kebutuhan Grup Pelanggan — ia sendiri sudah tidak dipakai. Kalau Ginnva mau harga khusus per grup (partner/dealer/member), itu genuinely fitur baru, sama sekali belum ada infrastrukturnya. |

---

## Penjualan / Produk / Produk Layanan

Sumber: `Majoo/Penjualan/Produk/Produk Layanan` — **13 screenshot, diperiksa teliti krn terkait
langsung pertanyaan bisnis pending** (`project_penjualan_majoo_blocked_items`: "Produk Layanan /
Produk Ekstra / Produk Paket → BLOCKED, user 'masih belum tau'"). List kosong (0 Produk Layanan)
di trial Ginnva — belum pernah diisi, tapi STRUKTUR FORM-nya sendiri sangat informatif.

**Konsep inti**: "Produk Layanan" adalah TIPE PRODUK TERPISAH dari Produk Barang — berbasis
**Durasi (menit)** + **Satuan "Sesi"** (bukan unit fisik), dengan kolom list: Nama Produk,
Kategori, Durasi (Menit), Harga Layanan, **Penyedia Jasa**, Status. Banner fitur terbaru Majoo:
"Penyedia Jasa menjadi atribut khusus dan TERPISAH dari varian produk" (baru dipisah — dulu jadi
1 dgn Varian).

**Tab "Penyedia Jasa"** (paling relevan):
- **Metode Penetapan Tarif**: "Tarif Sama untuk Semua Penyedia Jasa" (1 tarif flat) VS **"Tarif
  Berbeda per Penyedia Jasa"** (tabel per-karyawan: Nama, Posisi, **Tarif (Rp) individual**,
  Tampil di Menu) — teknisi berbeda bisa dibayar beda utk layanan yang sama persis.
- **Penyedia Jasa**: multi-select karyawan (modal "Pilih Karyawan" — daftar berisi nama staff
  ASLI Ginnva: Antony Fedryandi, Dedi Iriyanto, Elroy Yeda Valentino Zega, Friecella, Irfan,
  Luthfiandi, Oman, Raihan Fahrezy Andana, dst).
- **"Produk Terdapat Asisten"** (toggle) → **Asisten Penyedia Jasa**: role kedua eksplisit
  "karyawan pendamping yang membantu penyedia jasa, bersifat opsional, TIDAK memiliki tarif
  sendiri dan mengikuti tarif penyedia jasa" — memodelkan tim lead+asisten installer dgn jelas.

**Tab "Ekstra"** (nama baru dari "Varian", per notifikasi in-app): toggle "Produk Memiliki
Ekstra" + toggle terpisah **"Pengaturan Penyedia Jasa"** khusus utk Ekstra — artinya add-on pun
bisa py penyedia jasa sendiri, independen dari penyedia jasa produk utama.

**Tab "Resep"**: sama pola dgn Produk Barang (Bahan Baku + Harga Modal + Takaran).

**Tab "majoo Order"**: berlabel **"Coming Soon"** di versi Majoo ini — fitur order-online utk
Produk Layanan belum sepenuhnya rilis bahkan di Majoo sendiri.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| **Tipe produk "Layanan" berbasis Durasi+Sesi (terpisah dari Barang)** | **SANGAT RELEVAN — kemungkinan jawaban konkret utk pertanyaan bisnis pending** | Ini persis pola yg dibutuhkan Ginnva utk memodelkan "jasa tanpa film" (murni instalasi/servis, bukan produk fisik) — salah satu dari 3 opsi yg ditanyakan ke user 2026-09-10 dan belum dijawab. Struktur field (Durasi menit, Penyedia Jasa wajib) bisa jadi referensi konkret desain `FilmProduct` varian baru `product_type='layanan'` ATAU model terpisah, TAPI ini keputusan bisnis dulu (apakah Ginnva memang mau jual jasa berdiri sendiri) — jangan bangun sebelum user jawab pertanyaan lama itu. |
| **Tarif berbeda per teknisi utk layanan yang sama** (bukan flat rate) | **Belum Ada — Relevan, detail penting utk Komisi** | Modul Komisi Ginnva (Technician.commission_amount) setahu kami flat/manual per teknisi, belum tentu terikat ke tarif per-jenis-layanan spesifik. Kalau Ginnva mau skema "PPF Full Body dikerjakan teknisi A dibayar beda dari teknisi B", ini referensi konkret. |
| **Role Asisten Penyedia Jasa terpisah** (tanpa tarif sendiri, ikut tarif penyedia utama) | **Belum Ada — Relevan** | Instalasi PPF/kaca film sering dikerjakan tim (installer utama + asisten). Ginnva Booking/SPK saat ini kemungkinan cuma py 1 field "teknisi" — kalau mau catat tim (lead+asisten) dgn jelas beda peran & pembagian komisi, pola ini referensi bagus. |
| Ekstra bisa py Penyedia Jasa sendiri (independen dari produk utama) | Rendah Prioritas | Nice detail tapi cukup niche — relevan hanya kalau extra_service (Watermark Remover dkk) dikerjakan teknisi BEDA dari yg mengerjakan servis utama. |
| Tab "majoo Order" berstatus "Coming Soon" utk Produk Layanan | Bukan Temuan | Konfirmasi bahwa order-online utk jasa emang belum matang bahkan di Majoo sendiri — bukan sesuatu yg perlu dikejar Ginnva. |

## Penjualan / Produk / Produk Fasilitas

Sumber: `Majoo/Penjualan/Produk/Produk Fasilitas` — list kosong (0 Produk Fasilitas) di trial
Ginnva.

**Konsep inti**: "Produk Fasilitas" = ruang/space fisik disewa **per DURASI (Jam)**, BUKAN per
orang/sesi kayak Produk Layanan — field kunci: Nama Fasilitas, Kategori Fasilitas, **Harga dan
Durasi** (dropdown Jam + Konversi + Harga Jual), SKU. Kolom list: Nama Produk, Kategori, SKU,
Harga Fasilitas, Grup, Status. Struktur tab lebih sederhana dari Produk Layanan: Informasi
Produk → Ekstra → majoo Order saja — **TIDAK ADA tab "Penyedia Jasa"** (krn ini soal ruang
fisik, bukan orang) — konfirmasi arsitektur Majoo memang memisahkan "siapa yang mengerjakan"
(Penyedia Jasa, di Produk Layanan) dari "di mana dikerjakan" (Fasilitas).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| **Fasilitas/ruang sbg produk tersewa per durasi jam, terpisah dari orang** | **Belum Ada — Relevan, pasangan Produk Layanan** | Ini pasangan konsep dari temuan "Utilisasi Ruangan"/"Reservasi Fasilitas" sebelumnya: kalau Ginnva mau formalkan bay/stall instalasi PPF-Kaca Film sbg resource yg bisa dijadwalkan (bukan cuma kapasitas implisit dari jumlah booking), pola field "Harga dan Durasi per Jam" ini referensi konkret. CATATAN: bay instalasi biasanya bukan dijual terpisah ke customer (customer bayar paket, bukan sewa bay), jadi kalau diadopsi, kemungkinan cuma dipakai internal utk penjadwalan/utilisasi, BUKAN utk pricing customer — beda tujuan dari Majoo (Majoo: fasilitas bisa DIJUAL ke pelanggan, mis. sewa lapangan). |

## Penjualan / Produk / Produk Ekstra

Sumber: `Majoo/Penjualan/Produk/Produk Ekstra` — list kosong (0 data) di trial Ginnva, tapi
STRUKTUR FORM sangat menjawab pertanyaan bisnis pending.

**Konsep inti**: "Produk Ekstra" = grup modifier/opsi yang bisa ditautkan ke produk lain (Barang/
Layanan/Fasilitas — lihat "Atur Ekstra" di form masing-masing sebelumnya). Field:
- **Nama Produk Ekstra** (mis. "Ukuran", "Topping")
- **Jenis Ekstra**: Opsional (pelanggan boleh skip) VS **Wajib** (pelanggan wajib pilih sub
  ekstra yang tersedia)
- **Cara Pilih Ekstra**: **Pilih Salah Satu** (radio, single-choice) VS **Pilih Beberapa**
  (checkbox multi-choice, muncul field tambahan **Min. Pilih Minimal** & **Maks. Pilih Ekstra**
  utk batasi jumlah pilihan)
- **Tambah Produk Sub Ekstra**: **"Buat Baru"** (sub-ekstra standalone, isi Nama + **Harga
  Jual** sendiri) VS **"Pilih Dari Inventori"** (tautkan ke item stok yang sudah ada — begitu
  dipilih, muncul tabel **Bahan Baku** read-only di bawahnya, artinya sub-ekstra jenis ini
  otomatis MEMOTONG STOK saat terjual, bukan cuma harga tambahan)

| Fitur Majoo | Status | Catatan |
|---|---|---|
| **Grup modifier/opsi (Ekstra) dgn harga sendiri per sub-item, opsional/wajib, single/multi-choice, opsional tautan ke stok** | **Ini kemungkinan JAWABAN LEBIH DETAIL utk pertanyaan bisnis pending soal Produk Ekstra** | Menjawab langsung pertanyaan `project_penjualan_majoo_blocked_items` ("charge add-on terpisah?" — salah satu dari 3 opsi yg ditanyakan ke user 2026-09-10). Struktur ini jauh lebih canggih dari SPK `extra_service` (checklist boolean statis, tanpa harga/stok): setiap sub-ekstra (mis. "Watermark Remover") bisa py harga jual sendiri DAN opsional terhubung ke bahan baku (otomatis potong stok pas dipakai). Kalau user nanti jawab "ya, ekstra dijual terpisah", pola field ini (Wajib/Opsional × Single/Multi × harga per sub-item × opsional link stok) adalah referensi desain data yang solid & lengkap, bukan cuma nambah kolom harga di checklist SPK yang ada. |

## Penjualan / Produk / Produk Paket

Sumber: `Majoo/Penjualan/Produk/Produk Paket` — list kosong (0 data) di trial Ginnva.

**Konsep inti**: bundling BEBERAPA produk existing jadi 1 kombo dgn 1 harga flat. Field: Nama
Produk Paket, **Kode Paket** (auto-generate format "PG-{tanggal}-{random}"), Kategori Produk,
**Harga Produk Paket**, **Min. Pembelian**, Dimensi Produk (P×L×T + Berat). **"Isi Paket
Produk"**: "Pilih beberapa produk yang ingin dikemas menjadi satu paket" — repeater Nama Produk
(dropdown dari katalog Barang/Layanan/dst) + Jumlah + Satuan, tombol "+ Tambah Produk" utk
komponen lain.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| **Paket kombo: bundling produk existing jadi 1 SKU harga flat** | **Melengkapi trio pertanyaan bisnis pending** | Ini opsi ke-3 dari `project_penjualan_majoo_blocked_items` ("jual paket kombo bernama"). Struktur paling sederhana dari 3 pola Produk (Barang/Layanan/Fasilitas standalone vs Ekstra modifier vs Paket bundling) — cocok kalau Ginnva mau jual "Paket Combo PPF Depan + Kaca Film Ceramic" dgn 1 harga diskon, bukan 2 transaksi terpisah. Dengan ini, LENGKAP sudah gambaran 3 opsi yang ditanyakan ke user 2026-09-10 (Produk Layanan = jasa berdiri sendiri, Produk Ekstra = add-on bersyarat/modifier, Produk Paket = bundling combo) — siap dipakai sbg referensi konkret kalau user sudah punya jawaban/mau memutuskan sekarang. |

## Penjualan / Produk / Penjadwalan Perubahan Resep

Sumber: `Majoo/Penjualan/Produk/Penjadwalan Perubahan Resep` — list kosong di trial Ginnva.

**Konsep inti**: jadwalkan perubahan resep/BOM utk berlaku di WAKTU TERTENTU di masa depan
(bukan langsung berubah saat disimpan) — form: Nama Jadwal, Waktu Terjadwal (tanggal+jam WIB),
lalu "Atur Produk Resep" ATAU "Atur Master Resep" (2 mode terpisah): tabel menampilkan
**Komposisi Resep Saat Ini** vs **Komposisi Resep Baru** berdampingan, + tombol "Simpan Draf"
(bisa disiapkan dulu, belum langsung aktif).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Jadwalkan perubahan resep/BOM berlaku di waktu tertentu (bukan langsung) | Rendah Prioritas / Nice-to-have | `MasterResepResource` Ginnva saat ini cuma edit langsung (current-state, tanpa histori/jadwal). Fitur ini lebih relevan utk resto (ganti resep pas jam tertentu/promo), kurang mendesak utk Ginnva krn perubahan BOM PPF/kaca film (mis. ganti supplier film) biasanya tidak perlu presisi jadwal jam-menit — cukup diedit langsung saat memang berlaku. Skip kecuali ada kasus nyata yg butuh ini. |

## Penjualan / Produk / Daftar Harga Ojek Online

Sumber: `Majoo/Penjualan/Produk/Daftar Harga Ojek Online` — Grab & Go-Jek default (Tidak Aktif).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Harga markup khusus per platform ojek online (GoFood/GrabFood) | Tidak Relevan | Murni utk resto/retail yg jual via aplikasi delivery — Ginnva tidak menjual produk fisik via ojek online. |

## Penjualan / Produk / Penjadwalan Harga

Sumber: `Majoo/Penjualan/Produk/Penjadwalan Harga` — list kosong di trial Ginnva.

**Konsep inti**: jadwalkan perubahan **harga jual** (bukan resep) utk berlaku otomatis di
tanggal/jam tertentu, sekaligus utk banyak produk — Nama Program, Tanggal & Waktu Berlaku, lalu
"Atur Produk & Harga": tabel Harga Jual Saat Ini vs Harga Jual Baru per produk (bisa pilih dari
Produk atau Paket), + "Simpan Draf" (siapkan dulu sebelum aktif).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Jadwalkan kenaikan/perubahan harga massal berlaku otomatis di tanggal tertentu | **Belum Ada — Relevan kecil** | `FilmProductResource` Ginnva cuma edit harga langsung, tidak ada mekanisme "siapkan kenaikan harga sekarang, baru berlaku efektif tanggal X" — berguna kalau owner mau umumkan kenaikan harga dari jauh hari tapi tidak mau staff lupa update manual pas harinya. Nice-to-have, bukan mendesak. |

(Cek lanjutan) Tab "Ekstra & Harga" cuma memperluas jadwal yg sama ke item Ekstra (Sub Ekstra +
Harga Saat Ini vs Baru) — pola identik, tidak ada temuan baru. Bisa pilih Paket juga di "Tambah
Produk". Menuntaskan folder ini.

## Penjualan / Produk / Cetak Barcode

Sumber: `Majoo/Penjualan/Produk/Cetak Barcode`

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Cetak label barcode produk (ukuran kertas stiker, jumlah cetak per SKU) | Tidak Relevan | Ginnva tidak menjual barang yg di-scan di kasir/rak — PPF/kaca film dipasang teknisi, bukan barang retail perlu barcode fisik. |

## Penjualan / Produk / Daftar Kategori Catatan

Sumber: `Majoo/Penjualan/Produk/Daftar Kategori Catatan` — kategori catatan pesanan terstruktur
(contoh "Level Gula") + pilihan.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Kategori catatan pesanan terstruktur (preferensi customer saat order) | Tidak Relevan | Khas F&B (level pedas/gula/es). Kebutuhan serupa Ginnva (varian ukuran/warna film) sudah tercakup via konsep Varian, bukan catatan bebas terstruktur. |

## Penjualan / Produk / Master Resep (standalone)

Sumber: `Majoo/Penjualan/Produk/Master Resep` — resep independen (Kode Master Resep + Nama Resep
Produk + Bahan Baku), TIDAK terikat ke 1 produk tertentu (beda dari resep di dalam tab "Resep"
Produk Barang/Layanan yang anchor ke 1 produk).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Master Resep standalone (lepas dari anchor produk) | Sudah Tercatat di Memory Lain — Tidak Relevan | `project_penjualan_majoo_blocked_items` sudah tandai ini "❌ tidak relevan" — `MasterResepResource` Ginnva sengaja anchor langsung ke `FilmProduct` (1 produk = 1 resep), tidak butuh versi lepas/reusable. |

## Penjualan / Produk / Harga Berdasarkan Waktu

Tidak ada screenshot — fitur premium Majoo (di luar paket trial user), tidak bisa diakses utk
diaudit. Grup "Produk" dinyatakan TUNTAS dgn catatan ini (satu-satunya item tersisa memang tidak
bisa dicek, bukan terlewat).

## Penjualan / Inventori / Pembelian Stok (rantai PO Formal)

Sumber: `Majoo/Penjualan/Inventori/Pembelian Stok` — 6 sub-halaman: Permintaan Barang, Pemesanan
Stok, Pengiriman Pembelian, Faktur Pembelian, Pembayaran Faktur, Retur. **Terhubung LANGSUNG ke
item yang sudah diketahui perlu dibangun** (`project_penjualan_majoo_blocked_items`: "PO Formal
+ Pemasok + GRN + retur belum ada — user minta dibangun").

**Alur lengkap dikonfirmasi**: Permintaan Barang (internal request) → **Pemesanan Stok** (PO ke
Pemasok, 2 mode: "Tanpa Referensi" atau "Referensikan Permintaan Barang") → Pengiriman Pembelian
(GRN — penerimaan fisik) → **Faktur Pembelian** (invoice, 2 mode: "Tanpa Nomor Referensi" atau
"Referensikan Pemesanan Stok (PO)" — pola matching PO↔Invoice) → Pembayaran Faktur → Retur
(opsional).

**Detail baru yang memperkaya rekomendasi lama**:
- "Jenis Pemesanan"/"Jenis Pembelian" selalu ada 2 opsi: **Barang Jual vs Aset** — alur pembelian
  utk stok jual dan utk aset tetap dipisah jelas sejak awal (relevan krn Ginnva py `AssetResource`
  terpisah dari RawMaterial/ConsumableItem).
- Faktur Pembelian py KPI cards (Total/Belum Dibayar/Sudah Dibayar/**Jatuh Tempo**/**Void**) +
  status "Lunas" + aksi menu Detail/Cetak/**Kirim Email** (kirim faktur ke pemasok/akunting).
- Field "Nomor Pemesanan Stok" auto-generate kalau kosong (format `PO/{tanggal}/{urut}`).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Rantai PO Formal lengkap (Request→PO→GRN→Invoice→Payment→Retur) dgn matching antar-dokumen | **Sudah Diketahui Perlu Dibangun — memperkaya desain** | Bukan temuan baru (user sudah minta dibangun di audit 2026-09-10), tapi sesi ini memberi detail konkret struktur field & alur matching dokumen yg bisa jadi referensi desain langsung kalau mulai dikerjakan: pemisahan Barang Jual vs Aset sejak PO, opsi "tanpa referensi" vs "dgn referensi" di tiap tahap (fleksibel utk kasus mendadak vs terencana), KPI Jatuh Tempo/Void di level Faktur. |
| **Kolom "Serial Number/Batch Number" langsung di baris item Faktur Pembelian** | **SANGAT PENTING — titik input konkret utk traceability roll** | Ini menjawab pertanyaan teknis terakhir dari rangkaian temuan traceability sebelumnya (Laporan Batch Number, Toggle Batch Number per produk): nomor roll/batch bahan baku (mis. PPF/kaca film) diinput di SINI — saat faktur pembelian/barang masuk dicatat, bukan di tahap lain. Kalau Ginnva bangun PO Formal + traceability batch, field ini WAJIB ada di baris item `PurchaseInvoiceItem`/setara, terhubung ke `RawMaterialBatch` yg sudah ada. |
| Termin Pembayaran (7/14/30 hari otomatis hitung jatuh tempo dari Tanggal Masuk, atau manual) | Rendah Prioritas / Pelengkap | Detail kecil tapi berguna kalau PO Formal dibangun — standar fitur AP (Accounts Payable). |
| Penerimaan barang PARSIAL (kolom Diterima/Sisa per item saat mode "Referensikan PO") | **Relevan — pola penting** | 1 PO bisa diterima bertahap (kiriman dari pemasok datang beberapa kali), sistem lacak sisa quantity yg belum diterima per item. Penting kalau PO Formal Ginnva dibangun — jangan asumsikan 1 PO = 1 kali terima penuh. |
| Bulk Update faktur via import Excel, lampiran file (scan nota fisik, maks 5 file 2MB), Pajak Masukan (Jenis+Nominal), Biaya Lainnya (ongkos tambahan) | Pelengkap | Detail standar dokumen AP — berguna tapi bukan inti, tinggal ditambahkan kalau PO Formal sudah jalan dasarnya. |
| Pembayaran Faktur: bayar banyak faktur dari 1 pemasok sekaligus ("Bayar Semua") + Potongan/Sisa Tagihan per baris | Pelengkap | Pola AP standar (batch payment), melengkapi tahap akhir rantai PO Formal — tidak ada temuan baru di luar yg sudah dicatat. |
| Pengiriman Pembelian (GRN): wajib terhubung ke Nomor Pemesanan Stok (PO), Serial Number/Batch Number muncul LAGI di sini (titik ke-3 setelah toggle produk & Faktur Pembelian) | Konfirmasi pola, bukan temuan baru | Majoo sengaja fleksibel: batch number bisa diisi di titik mana pun tergantung alur dipakai (dgn/tanpa GRN sbg perantara). Kalau Ginnva bangun ini, cukup 1 titik wajib input batch (bukan di 3 tempat) — pilih yg paling natural sesuai alur staff Ginnva. |
| Permintaan Barang (tahap paling awal/ringan): Nama+Jumlah+Satuan+Catatan, field "Tanggal Dibutuhkan" terpisah dari "Tanggal Dibuat" | Sudah Ada (setara `PurchaseRequest`) | Konfirmasi ada 1 transaksi riil di trial (status Void, 11 Sep 2026) — pernah dicoba. `PurchaseRequest` Ginnva sudah punya alur approve/reject/fulfill yg lebih lengkap dari form dasar ini. Satu detail kecil yg belum tentu ada di `PurchaseRequest`: field "Tanggal Dibutuhkan" (kapan barang harus tersedia) terpisah dari tanggal permintaan dibuat — nice-to-have utk urgency tracking. |

| Retur → Rekonsiliasi Retur: alur pengembalian dana DARI pemasok (kebalikan dari Pembayaran Faktur), pilih Akun Bank tujuan dana masuk, Total/Sisa tracking | Pelengkap | Melengkapi sisi "refund dari supplier" utk barang cacat/salah kirim yg dikembalikan — mirror dari `RefundService` yg sudah ada di sisi penjualan (Ginnva→Customer), tapi ini arah sebaliknya (Pemasok→Ginnva). Konsisten dgn pola AP yg sudah matang. |
| Retur Pembelian: wajib rujuk No. Faktur Pembelian, Jumlah Retur per item (retur sebagian), Catatan alasan, Serial/Batch Number (titik ke-4) | Konfirmasi pola, bukan temuan baru | Sepenuhnya konsisten dgn rantai yg sudah didokumentasikan — retur bisa PARSIAL (sebagian qty), bukan cuma seluruh faktur dibatalkan. |

**Menuntaskan seluruh rantai "Pembelian Stok"** (6/6 sub-halaman sudah diperiksa: Permintaan
Barang, Pemesanan Stok, Pengiriman Pembelian, Faktur Pembelian, Pembayaran Faktur, Retur).

---

## Penjualan / Inventori / Kelola Stok / Daftar Stok

Sumber: `Majoo/Penjualan/Inventori/Kelola Stok/Daftar Stok` — kolom Awal/Masuk/Terjual/Akhir per
bahan baku.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Kartu stok (Awal·Masuk·Keluar·Akhir) per bahan baku | **Sudah Ada** | Persis `StockCardReport` yg sudah dibangun 2026-09-10 (`project_penjualan_majoo_blocked_items`). Tidak perlu tindakan. |
| Fitur tambah data | **Tidak Ada di halaman ini (by design Majoo)** | Ada banner "Menu Baru - Tambah Bahan Baku: Tambah dan ubah bahan baku dipindahkan ke halaman khusus bahan baku" — halaman ini murni read-only ledger, fungsi tambah/ubah sudah dipindah ke "Daftar Bahan Baku" terpisah. Konsisten dgn `StockCardReport` Ginnva yg juga read-only (rekonstruksi dari movement, bukan CRUD langsung). |

## Penjualan / Inventori / Kelola Stok / Stok Opname

Sumber: `Majoo/Penjualan/Inventori/Kelola Stok/Stok Opname` — form: Nomor Stok Opname, Tanggal
Transaksi, Catatan, Rincian Barang (Stok Sistem vs Stok Aktual vs **Selisih**, Harga Modal,
Serial/Batch Number — titik ke-5 field ini muncul), batas "Maks 300 per batch" pemilihan produk.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Stok Opname per-cabang (hitung fisik vs sistem, catat selisih) | **Sudah Tercatat — BLOCKED** | `project_penjualan_majoo_blocked_items`: "Stok Opname versi per-cabang TERBLOKIR, menunggu keputusan Model Stok Inventaris (Topik 4)". "Sesuaikan Stok" generik yg sudah ada di Ginnva = opname manual se-item, cukup sbg interim. Tidak perlu tindakan baru sampai keputusan Model Stok turun. |

## Penjualan / Inventori / Kelola Stok / Stok Terbuang

Sumber: `Majoo/Penjualan/Inventori/Kelola Stok/Stok Terbuang` — ada 1 transaksi riil (ST/260915/0001,
status Selesai). Form: Catatan (contoh "Sudah Kadaluwarsa"), Rincian Barang (Stok vs Stok
Terbuang, Harga Modal, Serial/Batch Number), batas "Maks 1.000 per batch".

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Catat stok terbuang/write-off + jurnal kerugian otomatis | **Sudah Ada** | Persis `StockWriteOffResource`/`StockWriteOffService` yg sudah dibangun 2026-09-10 (COA 6520 "Beban Kerugian Persediaan"). Tidak perlu tindakan. |

## Penjualan / Inventori / Produksi Stok

Sumber: `Majoo/Penjualan/Inventori/Produksi Stok/Acuan Produksi Stok` — template konversi Stok
Sumber → Stok Hasil (manufaktur barang jadi dari bahan baku, utk disimpan sbg stok gudang).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Produksi Stok (manufaktur barang jadi ke gudang) | **Sudah Tercatat — Tidak Relevan** | `project_penjualan_majoo_blocked_items`: "Ginnva memasang [langsung ke kendaraan], tidak produksi barang jadi untuk stok". Konfirmasi ulang via "Daftar Produksi Stok" (form sama: Buat Data Baru vs Gunakan Template) — tidak ada perubahan konteks. |

## Penjualan / Inventori / Mutasi Antar Outlet / Permintaan Stok

Sumber: `Majoo/Penjualan/Inventori/Mutasi Antar Outlet/Permintaan Stok`

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Mutasi/permintaan stok antar outlet | **Sudah Tercatat — BLOCKED (bahan/consumable)** | `project_penjualan_majoo_blocked_items`: terblokir oleh masalah fundamental model stok (RawMaterial/ConsumableItem tidak py `store_id`, pool nasional). Bukti visual konkret: sistem Majoo sendiri menolak dgn error "Outlet penerima sama dgn outlet pengirim" krn trial Ginnva cuma py 1 outlet terdaftar — fitur ini secara inheren butuh ≥2 cabang aktif utk bisa diuji/dipakai sama sekali. Tidak perlu tindakan sampai keputusan Model Stok (Topik 4) turun. Dikonfirmasi via "Kirim Stok" (pola sumber "Tanpa Referensi" vs "Referensikan Permintaan Stok" — sama dgn pola PO Formal), "Terima Mutasi Stok" (pola GRN sama: Jml Diterima/Sisa, Serial/Batch Number), & "Stok Transit" (read-only, tanpa tombol Tambah, murni monitoring barang "dalam perjalanan") — menuntaskan grup ini, tidak ada temuan baru. "Stok Harus Dikirim" (dicek ulang 2026-09-18) — tombol "Tambah Kirim Stok"-nya membuka form identik dgn "Kirim Stok", tidak ada perbedaan. |

---

## Penjualan / Inventori / Daftar Bahan Baku

Sumber: `Majoo/Penjualan/Inventori/Daftar Bahan Baku`

**Fitur multi-satuan dgn konversi**: 1 bahan baku bisa punya BEBERAPA baris satuan sekaligus
(mis. Botol, Liter, ml), masing2 dgn **faktor Konversi** ke satuan dasar + **SKU & Harga Beli
Terakhir SENDIRI per satuan**. Field lain: Monitor Persediaan (toggle) + Pengingat Stok Minimum
(reorder point).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Multi-satuan per bahan baku dgn faktor konversi (beli per Liter, konsumsi per ml, otomatis dikonversi) | **Belum Ada — Relevan** | Dicek `RawMaterial.php` — tidak ada konsep konversi satuan sama sekali, kemungkinan 1 bahan baku = 1 satuan tetap. Berguna kalau Ginnva beli bahan dalam satuan besar (mis. galon/liter) tapi pakainya dalam satuan kecil (ml/gram) di resep SPK — sekarang mungkin harus dikonversi manual di kepala staff, rawan salah hitung. |
| Monitor Persediaan + Pengingat Stok Minimum per bahan | Sudah Ada | Setara `reorder_point` yg sudah ada di RawMaterial. |

---

## Penjualan / Inventori / Daftar Pemasok

Sumber: `Majoo/Penjualan/Inventori/Daftar Pemasok` — struktur: Kode Pemasok (auto-generate),
Nama, Email, No. Telepon, Alamat + Negara/Provinsi/Kota, **Informasi Rekening** (Nama Pemilik +
Bank + No. Rekening, bisa multi via "Tambah Rekening"), Keterangan. Fitur Impor/Ekspor +
Template.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Master data Supplier/Pemasok (kontak + multi-rekening bank) | **Sudah Tercatat — perlu dibangun kalau PO Formal jalan** | `project_penjualan_majoo_blocked_items`: "Daftar Pemasok ❌ tidak ada model Supplier — perlu kalau PO Formal dibangun." Struktur field di atas jadi referensi desain konkret siap pakai (termasuk detail rekening bank multi-entry, berguna utk transfer pembayaran faktur langsung). |

---

## Penjualan / Pelanggan / Daftar Pelanggan

Sumber: `Majoo/Penjualan/Pelanggan/Daftar Pelanggan` — data riil (Alvin, Ardani, Suryo, Audy,
David, dst dari Tangerang/Surabaya). Kolom list: Nama, Kode Pelanggan, Alamat, Telepon, Jenis
Kelamin, Poin, Saldo Deposit. Form Tambah: Kode Pelanggan (auto), Nama, No. Telepon (+62),
Email, Jenis Kelamin, **Tanggal Lahir**, **Grup Pelanggan** (dropdown), Kota, Alamat, Catatan,
**Foto Pelanggan**.

Dicek `Customer.php` — sudah punya: name, email, phone_number, **gender**
(`GENDER_LABELS`), address, referral_code, loyalty_points. Sudah setara/lebih lengkap utk field
inti.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Tanggal Lahir pelanggan | **Belum Ada — Relevan kecil** | Berguna utk program ulang tahun (mis. WA ucapan + promo khusus), sudah lazim di CRM otomotif/service. Nice-to-have, bukan mendesak. |
| Foto Pelanggan | Rendah Prioritas | Kurang penting utk konteks B2C jasa instalasi (bukan member fisik yg perlu verifikasi wajah). |
| **Grup Pelanggan** sbg entitas master data lengkap (Nama, Status, Deskripsi, Urutan, Atur Pelanggan via checklist, **Atur Harga**) | **Belum Ada — Relevan, MENUNTASKAN temuan Multiprice** | Ini menjawab TUNTAS pertanyaan terbuka soal harga per grup pelanggan yg sblmnya dicatat di temuan Produk Barang/Multiprice — strukturnya konkret & sederhana (bukan sistem rumit). Kalau Ginnva mau bangun skema harga khusus VIP/partner/dealer, ini blueprint lengkapnya: tabel CustomerGroup (nama, status, urutan) + pivot ke Customer + pivot/relasi ke aturan harga produk. Ada juga grup bawaan sistem "majoo Order" (badge "Sistem", tak bisa dihapus) — pola grup auto-created utk kebutuhan sistem tertentu, bisa jadi referensi kalau Ginnva perlu grup serupa (mis. "Customer App Mobile" vs "Walk-in"). |
| **"Grup Harga Spesial"** — entitas TERPISAH dari Grup Pelanggan, menautkan 1 Grup Pelanggan (eksklusif, 1 grup pelanggan cuma bisa dipakai di 1 Grup Harga Spesial) ke 1 Cabang + tabel harga per produk + tabel harga per ekstra, masing2 dgn tombol bulk "Preset Harga" (Markup/Markdown %/Rp/manual) | **Blueprint lengkap, melengkapi temuan di atas** | Ini detail arsitektur konkret: bukan 1 tabel campur, tapi 2 layer — (1) Grup Pelanggan = siapa anggotanya, (2) Grup Harga Spesial = aturan harga apa yg berlaku utk grup itu, per cabang, terpisah antara produk utama & ekstra. Kalau nanti dibangun, pola 2-layer ini lebih rapi drpd taruh field harga langsung di tabel Grup Pelanggan. |
| Catatan bebas per pelanggan | Rendah Prioritas | Nice-to-have, staff bisa catat preferensi/riwayat komplain dsb. |

---

## Penjualan / Pelanggan / Pengaturan Data Pelanggan

Sumber: `Majoo/Penjualan/Pelanggan/Pengaturan Data Pelanggan` — toggle Wajib/Opsional per field
form pelanggan (Kode/Nama/No.Telepon selalu wajib-default, sisanya bisa diatur admin: Email,
Jenis Kelamin, Tanggal Lahir, Grup Pelanggan, Kota, Alamat, Catatan, Foto).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Admin bisa atur field mana yang wajib/opsional saat tambah pelanggan, tanpa ubah kode | **Belum Ada — Relevan kecil** | `CustomerResource` Ginnva kemungkinan field wajibnya hardcoded di kode (butuh developer utk ubah). Nice-to-have kalau owner ingin fleksibilitas atur sendiri (mis. suatu saat email jadi wajib utk semua cabang), tapi bukan kebutuhan mendesak — perubahan aturan wajib/opsional jarang terjadi & bisa dikerjakan lewat request ke developer. |

**"Kustom Data Pelanggan"** — fitur premium Majoo, tidak bisa diakses/discreenshot user. Grup
"Pelanggan" dinyatakan TUNTAS dgn catatan ini (item tersisa memang tidak bisa dicek, bukan
terlewat) — sama pola dgn "Harga Berdasarkan Waktu" sebelumnya.

---

## Penjualan / Promosi / Kupon / Daftar Kupon

Sumber: `Majoo/Penjualan/Promosi/Kupon/Daftar Kupon` — kolom: Kode Kupon, Nama Kupon, Besaran,
Durasi, Outlet, Status.

Sidebar mengonfirmasi struktur lengkap grup "Promosi": Promo, **Kupon** (Tambah/Daftar),
**Loyalty**, **Poin Reward**.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Poin Reward: earning 2 mode (Per Total Pembelian/Per Produk) + redemption 2 mode (Gratis Produk/Potongan Pembayaran %) | **Sudah Tercatat — bagian dari item #7 pending** | `project_penjualan_majoo_blocked_items` #7: "Reward Poin (nilai Rupiah) — `Reward` model cuma punya `points_cost`, tidak ada nilai Rp per poin". Struktur Majoo ini memperlihatkan rule engine yg lebih dalam (2 mode dpt poin × 2 mode tukar poin, + "Berlaku Kelipatan" utk scaling poin per kelipatan minimal pembelian, + pembatasan hari tertentu "Pilih Hari"). Dikonfirmasi via "Per Total Pembelian" (struktur identik, tanpa filter produk) — konsisten dgn gap yg sama, tidak perlu tindakan terpisah sampai keputusan nilai Rp per poin turun. |
| Kupon/Voucher diskon | **Sudah Ada (versi lebih sederhana)** | `VoucherResource` (Voucher/VoucherClaim) sudah ada, sesuai `project_penjualan_majoo_blocked_items` ("Kupon/Loyalty/Poin Reward ✅ sudah ada"). Dicek `Voucher.php` — modelnya jauh lebih sederhana dari Majoo: TIDAK ADA stacking rules ("Boleh digabung dgn kupon lain/sama"), kode unik per-kupon (bulk generate Manual/Impor Excel utk campaign giveaway), atau cap "Maksimal Penukaran (Rp)" utk kupon persen. Gap ini RENDAH PRIORITAS krn kebijakan promo/kupon Ginnva sendiri masih terikat keputusan boss yg pending (`project_penjualan_majoo_blocked_items`) — jangan bangun detail ini duluan sblm arah kebijakan promo jelas. |

---

## Penjualan / Promosi / Promo / Basic Promo

Sumber: `Majoo/Penjualan/Promosi/Promo/Basic Promo` — toggle Status Promo, Jenis Bonus (%
diskon blanket), "Promo Berlaku Untuk" (checklist channel: POS/majoo Order/Consumer
Apps/Kiosk/**Invoice**), Upload Banner.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Basic Promo: diskon % blanket ke semua transaksi, per channel | **Sudah Tercatat — Tidak Relevan** | `project_penjualan_majoo_blocked_items`: "Promo > Basic Promo ❌ — tidak ada pipeline harga otomatis di Ginnva (transaction_amount diketik manual)". Konfirmasi ulang, tidak ada perubahan konteks. Menarik: salah satu channel checklist-nya adalah "Invoice" — kalau nanti pipeline harga otomatis dibangun, modul Invoice Ginnva yg sudah ada bisa jadi salah satu titik integrasi. |

---

## Penjualan / Promosi / Promo / Per Produk

Sumber: `Majoo/Penjualan/Promosi/Promo/Per Produk` — sistem promo per-item yg cukup dalam: Jenis
Potongan (%/Rp/Bonus Produk/Harga Coret), Aktivasi Otomatis vs Manual, Platform, Promo
Berdasarkan (Min Pembelian Rp/Min Kuantitas), Pilih Produk (dgn peringatan "produk paket cuma
bisa terintegrasi ke POS"), Jenis Promo (Bundling wajib beli semua vs Satuan pilih salah satu).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Promo per-produk/line-item (diskon spesifik utk produk tertentu dlm 1 transaksi) | **Sudah Tercatat — Tidak Relevan (blocker arsitektur)** | `project_penjualan_majoo_blocked_items`: "Promo > Per Produk ❌ — tidak ada line item di booking." Detail baru MENGUATKAN ini genuinely gap arsitektur dalam, bukan cuma fitur belum dibangun: Majoo butuh booking berbasis LINE ITEM produk dgn kuantitas per baris, sementara `Booking` Ginnva cuma py 1 `transaction_amount` (tidak ada tabel line item). Kalau nanti Ginnva mau promo per-layanan spesifik dlm 1 booking, perlu redesain struktur Booking dulu (tambah tabel `booking_items`) — bukan sekadar tambah fitur Promo. |

---

## Penjualan / Promosi / Promo / Per Total Pembelian

Sumber: `Majoo/Penjualan/Promosi/Promo/Per Total Pembelian` — 1 data riil ("Cashback", tipe
Manual, Min Pembelian Rp28.000.000 → Bonus Rp10.000.000, Aktif, konsisten skala harga PPF).
Jenis Potongan: %, Rp, **atau Bonus Produk** (3 opsi).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Promo per total pembelian (3 jenis: %/Rp/Bonus Produk) | **Sudah Ada (versi lebih sederhana)** | `SpendPromo` sudah dibangun ✅ (`project_penjualan_majoo_blocked_items`), tapi cuma potongan flat Rp — TIDAK ada opsi % atau Bonus Produk. Gap kecil, rendah prioritas (bisa ditambah kalau ada kebutuhan konkret, mis. owner mau kasih promo "beli 10jt gratis 1 servis kecil" via Bonus Produk). |

**"Loyalty"** — fitur premium Majoo, tidak bisa diakses/discreenshot user. Grup "Promosi"
dinyatakan TUNTAS dgn catatan ini (item tersisa memang tidak bisa dicek, bukan terlewat) — sama
pola dgn "Harga Berdasarkan Waktu" & "Kustom Data Pelanggan" sebelumnya.

---

## Penjualan / Komisi / Daftar Grup Komisi

Sumber: `Majoo/Penjualan/Komisi/Daftar Grup Komisi` — 2 tipe: **Komisi Tetap** vs **Komisi
Bertingkat**.

**Komisi Tetap**: Tipe Komisi (Produk/Transaksi), Jenis Produk (**Barang/Layanan**), Cara Pilih
Karyawan (**Pilih Beberapa** — beberapa karyawan share 1 komisi dari 1 penjualan — atau Pilih
Salah Satu), Nilai Komisi (%/Rp), **Pengaturan Diskon** (toggle "Nilai Komisi Dihitung Setelah
Diskon" — pilih komisi dihitung dari harga net atau gross).

**Komisi Bertingkat**: khusus tipe produk **Layanan**, Parameter **"Durasi Layanan Selesai"**
("berdasarkan durasi layanan selesai dalam satu periode, HANYA tersedia utk produk layanan") —
makin banyak/lama servis diselesaikan dalam 1 periode, makin tinggi tier komisinya. Field:
Target Durasi Layanan, Nilai Komisi (bertingkat), Simulasi (preview hasil sebelum disimpan).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| **Komisi Bertingkat berbasis Durasi Layanan Selesai** — target komisi progresif utk teknisi/installer berdasar akumulasi durasi servis per periode | **SANGAT RELEVAN, terhubung langsung ke "Produk Layanan"** | Ini pasangan sempurna dari temuan Produk Layanan (Durasi menit + Penyedia Jasa) — bersama membentuk 1 ekosistem: definisikan durasi standar per layanan, lalu beri insentif progresif ke teknisi berdasar total durasi yg mereka selesaikan (bukan cuma jumlah job atau nilai Rp). Modul Komisi Ginnva (`Technician.commission_amount`) jauh lebih sederhana (flat manual per teknisi, tidak terikat target/durasi). Kandidat kuat kalau owner mau skema insentif produktivitas teknisi yg lebih canggih. |
| Multi-karyawan share 1 komisi dari 1 transaksi ("Pilih Beberapa") | **Belum Ada — Relevan** | Berguna kalau 1 job PPF dikerjakan tim (lead+asisten, sesuai temuan Penyedia Jasa sebelumnya) dan komisinya perlu dibagi/dihitung utk semua yg terlibat, bukan cuma 1 orang. |
| Toggle "Nilai Komisi Dihitung Setelah Diskon" | **Belum Ada — Relevan kecil** | Menjawab pertanyaan penting: kalau customer dapat diskon, komisi teknisi dihitung dari harga sebelum atau sesudah diskon? Ginnva blm punya pengaturan eksplisit ini — kemungkinan default konsisten (misal selalu dari transaction_amount net), tapi baik didokumentasikan sbg keputusan sadar, bukan default tersembunyi. |

---

## Penjualan / Invoice / Daftar Invoice

Sumber: `Majoo/Penjualan/Invoice/Daftar Invoice` — data riil (Alvin Rp5.824.000, Geraldi
Rp4.700.000, status Lunas). KPI: Invoice/Void/Lunas/Belum Lunas. Aksi: Lihat Detail/Unduh
PDF/Cetak Dot Matrix/Kirim Email. Form: Termin Pembayaran (auto-hitung jatuh tempo), Alamat
Penagihan vs **Alamat Pengiriman** terpisah, **Detail Produk multi-line-item** (Nama, Jumlah,
Satuan, Harga, Diskon, Total, Serial Number per baris) + Promo.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Invoice B2B multi-item (banyak produk per invoice, alamat kirim terpisah) | **Sudah Tercatat — Tidak Relevan (arsitektur sama dgn Promo Per Produk)** | `project_penjualan_majoo_blocked_items`: "Rantai dokumen B2B mayoritas ❌ tidak cocok Ginnva (jasa pasang, bukan distribusi)... Invoice PDF booking DIBANGUN ✅ — 1 baris layanan (gross)". Kemampuan multi-line-item ini butuh struktur `booking_items` yg sama dgn gap "Promo Per Produk" sebelumnya — kalau nanti itu dibangun, invoice multi-item bisa ikut nyusul sekalian, bukan proyek terpisah. |
| Kirim Email invoice langsung dari sistem | **Belum Ada — Relevan kecil** | Invoice PDF booking Ginnva saat ini cuma "Cetak"/download, blm ada aksi kirim langsung ke email customer. Nice-to-have kecil. |

---

## Penjualan / Invoice / Daftar Penawaran Penjualan

Sumber: `Majoo/Penjualan/Invoice/Daftar Penawaran Penjualan` — dokumen penawaran formal B2B
(Nomor Penawaran auto SQ-0001, Termin Penawaran/masa berlaku, Alamat Outlet+Pelanggan, multi
produk).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Penawaran/Quotation formal B2B dgn masa berlaku & multi-item | **Sudah Tercatat — Tidak Relevan, TAPI ada risiko penamaan** | `project_penjualan_majoo_blocked_items`: rantai B2B (Penawaran→Pesanan→...) mayoritas tidak cocok Ginnva. **Perlu diwaspadai**: Ginnva SUDAH punya `Quotation.php`/`QuotationResource`, TAPI itu utk konteks BEDA (penawaran ke lead/prospek sebelum booking, bukan dokumen B2B formal bermasa-berlaku dgn multi-item). Nama sama, konsep beda — jangan sampai tercampur kalau dibahas lagi nanti. |

---

## Penjualan / Invoice / Daftar Penerimaan Penjualan

Sumber: `Majoo/Penjualan/Invoice/Daftar Penerimaan Penjualan` — 5 transaksi riil (Alvin
Rp5.824.000, Geraldi Rp4.700.000, David Rp20.000.000, Ardani Rp18.000.000, Suryo Rp6.080.000),
semua status Selesai, terhubung ke No. Invoice. Form: Informasi Pembayaran (Opsi Bayar/Provider),
Keterangan, Syarat dan Ketentuan, **Tanda Tangan** (upload foto ttd + lokasi + tanggal, bisa
"Tambah Tanda Tangan" utk multi-penandatangan).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Bukti penerimaan pembayaran (receipt) dgn tanda tangan digital/foto | **Sudah Tercatat (konsep) — detail fitur baru** | `project_penjualan_majoo_blocked_items`: "Penerimaan = amount_received/Piutang Usaha" sudah ada di level data, tapi belum ada DOKUMEN bukti terima terpisah dgn tanda tangan spt ini. Terhubung ke tema "tanda tangan basah" yg pernah dibahas utk SPK — kalau nanti Ginnva mau bukti terima pembayaran formal (bukan cuma field amount_received), pola field ini (foto ttd + lokasi + tanggal + multi-signer) referensi yg baik. Rendah-menengah prioritas. |

---

## Penjualan / Invoice / Daftar Pengiriman Penjualan

Sumber: `Majoo/Penjualan/Invoice/Daftar Pengiriman Penjualan` — Surat Jalan (Delivery Order),
terhubung ke Pesanan Penjualan (SO), Alamat Pengiriman, Detail Produk + Serial Number.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Surat Jalan/Delivery Order pengiriman barang fisik | **Sudah Tercatat — Tidak Relevan** | Bagian rantai B2B (`project_penjualan_majoo_blocked_items`) — murni logistik pengiriman barang, tidak applicable krn Ginnva instalasi on-site (kendaraan datang ke bengkel/teknisi datang ke lokasi), bukan kirim barang ke alamat customer. |

---

## Penjualan / Invoice / Daftar Pesanan Penjualan

Sumber: `Majoo/Penjualan/Invoice/Daftar Pesanan Penjualan` — kolom list: Tagihan (RP), **Uang
Muka (RP)**, **Sisa Tagihan (RP)** — DP dicatat di level Pesanan (SO), bukan cuma Invoice. Form
sama pola B2B lain (Termin Pembayaran, Alamat Penagihan/Pengiriman terpisah).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Pesanan Penjualan (SO) dgn tracking Uang Muka/Sisa Tagihan | **Sudah Tercatat — "Pesanan = Booking"** | `project_penjualan_majoo_blocked_items`: "Pesanan = Booking". Kolom Uang Muka di sini MENGUATKAN LAGI (kali ke sekian) urgensi temuan DP — kalau dibangun, field DP idealnya ada di level Booking (spt di sini), bukan cuma di dokumen turunan (Invoice/SPK). |

Menuntaskan seluruh grup "Invoice" (6/6 sub-halaman).

---

## Penjualan / Marketing / Beli Kampanye Marketing

Sumber: `Majoo/Penjualan/Marketing/Beli Kampanye Marketing` — beli kredit "LBA Telkomsel" (SMS
blast marketing, mis. 1000 LBA Telkomsel Rp250.000/3 bulan).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Beli kredit SMS blast marketing dari vendor Telkomsel | Rendah Prioritas | Add-on komersial pihak ketiga milik ekosistem Majoo-Telkomsel, bukan fitur generik. Kalau Ginnva mau marketing blast, WhatsApp Blast (yg sudah ada indikasi terintegrasi via menu "Whatsapp" Majoo & kemungkinan sudah dipakai Ginnva sendiri utk notifikasi) lebih relevan drpd SMS. |

---

## Penjualan / Marketing / Kirim Kampanye Marketing

Sumber: `Majoo/Penjualan/Marketing/Kirim Kampanye Marketing` — kirim SMS/push blast via LBA
Telkomsel atau Consumer App (aplikasi Majoo). Target Marketing berbasis **database subscriber
Telkomsel sendiri** (Jenis Kelamin/HP/Kartu/Agama/Rentang Usia/Penggunaan Pulsa + radius dari
lokasi merchant) — BUKAN database pelanggan Ginnva.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Kirim kampanye SMS/push blast ke subscriber Telkomsel terdekat (radius lokasi) | **Tidak Relevan / Rendah Prioritas** | Ini iklan broadcast lokasi ke pengguna Telkomsel acak di sekitar toko, BUKAN alat segmentasi CRM pelanggan Ginnva sendiri. Menguatkan kategori "Rendah Prioritas" yg sudah dicatat di temuan "Beli Kampanye Marketing" — kalau Ginnva mau marketing ke pelanggan SENDIRI, WhatsApp Blast ke `Customer` list yg sudah ada jauh lebih relevan drpd produk iklan broadcast pihak ketiga ini. |

Menuntaskan seluruh grup "Marketing" (2/2) — dan dengan ini SELURUH cluster besar "Penjualan"
sudah tuntas diaudit.

---

## Keuangan / Dashboard Keuangan

Sumber: `Majoo/Keuangan/Dashboard Keuangan` — data riil: Aset Rp176.604.000, Liabilitas
Rp15.000.000, Equity Rp161.604.000. Widget: Laba Rugi (Laba Bersih/Total Beban/Total Pendapatan
setelah promo&HPP), Arus Kas (Saldo Keseluruhan/Kas Keluar/Kas Masuk), **Neraca** (Aset/
Liabilitas/Equity + timestamp "Terakhir update"), Biaya/Piutang/Hutang (per periode), dan
**"Tambah Widget"** — bisa pilih akun COA APAPUN (1-10001 Kas Outlet, 1-10005 Rekening BCA, dst)
jadi kartu KPI custom sendiri di dashboard.

**Catatan penting**: sempat mengira Keuangan Ginnva "simple, no double-entry" berdasar memory
lama, TERNYATA itu sudah usang — Ginnva sekarang sudah py Chart of Accounts + Jurnal Umum penuh
(lihat koreksi di `project_keuangan_absensi_plan`). Jadi perbandingan di bawah ini terhadap
sistem akuntansi double-entry Ginnva yg SUDAH ADA, bukan dari nol.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Dashboard Keuangan terpadu (Laba Rugi + Arus Kas + Neraca + Piutang/Hutang dlm 1 halaman) | **Perlu Verifikasi** | Ginnva sudah py `FinanceReport`/`JournalEntryResource`/dst tersebar di beberapa resource — belum jelas apakah sudah ada 1 halaman dashboard ringkasan terpadu spt ini (Neraca real-time + tren Laba Rugi + Arus Kas sekaligus), atau masih per-laporan terpisah. |
| **"Tambah Widget" — pilih akun COA apa pun jadi kartu KPI custom** | **Belum Ada — Relevan** | Fitur personalisasi dashboard yg cukup bagus: owner bisa pantau saldo akun spesifik (mis. "Rekening BCA-0358781678") langsung di depan tanpa buka laporan detail. Nice-to-have kalau mau tingkatkan dashboard keuangan Ginnva. |

---

## Keuangan / Buku Kas / Daftar Buku Kas & Bank

Sumber: `Majoo/Keuangan/Buku Kas/Daftar Buku Kas & Bank` — manajemen akun Kas & Bank sbg COA
(Kode 1-10001 s/d 1-10006, Tipe Header/Detail, Saldo per akun). Termasuk "Majoo Order Wallet"
sbg akun kas-setara (dana hasil order online yg parkir di wallet Majoo).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Kas & Bank sbg sub-akun COA dgn saldo per rekening | **Sudah Ada (kemungkinan besar)** | Ginnva sudah py `ChartOfAccountResource` + `BankStatementLine` (rekonsiliasi bank per akun) — konsep dasarnya sama (tiap rekening = 1 node akun terlacak). Tidak perlu tindakan. |

---

## Keuangan / Buku Kas / Daftar Transfer

Sumber: `Majoo/Keuangan/Buku Kas/Daftar Transfer` — transfer uang antar akun kas/bank sendiri
(Akun Asal → Akun Tujuan), No Transaksi auto (format BTU-xxxxx), status Draf/Selesai/Void,
lampiran bukti transfer (maks 1MB).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Pencatatan transfer uang antar akun kas/bank sendiri, dgn bukti lampiran & status draf/void | **Perlu Verifikasi** | Ginnva sudah punya Jurnal Umum (`JournalEntryResource`), tapi belum jelas apakah ada UI khusus "Transfer Uang" yg user-friendly (pilih akun asal/tujuan, auto-jurnal double-entry) atau staff harus input jurnal manual baris demi baris. Kalau yg terakhir, UI transfer sederhana spt ini bisa jadi peningkatan UX yg berguna. |

---

## Keuangan / Penerimaan / Daftar Penerimaan

Sumber: `Majoo/Keuangan/Penerimaan/Daftar Penerimaan` — entri manual "Penerimaan Kas & Bank"
(non-penjualan, mis. modal masuk): Akun Tujuan + Detail Akun (multi-baris jurnal) + lampiran.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Entri manual penerimaan kas/bank non-penjualan, multi-baris jurnal | **Sudah Ada (kemungkinan besar)** | Ginnva sudah punya `JournalEntryResource` — kemungkinan besar sudah menutupi kebutuhan ini (entri jurnal manual). |

## Keuangan / Penerimaan / Rekonsiliasi Penerimaan Penjualan

Sumber: `Majoo/Keuangan/Penerimaan/Rekonsiliasi Penerimaan Penjualan` — rekonsiliasi pembayaran
**non-tunai** (QRIS/kartu/transfer) yg tidak otomatis tercatat, dikelompokkan per Tutup Kasir +
Metode Bayar, status Terekonsiliasi/Belum Terekonsiliasi.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Rekonsiliasi penerimaan non-tunai per shift kasir | **Belum Ada — Relevan, "Fase 2" dari temuan Metode Pembayaran** | Fitur ini logisnya BARU relevan SETELAH field kanal pembayaran (QRIS/transfer/tunai) ditambahkan ke Booking — gap yg sudah dicatat sblmnya sbg "field baru, bukan keputusan bisnis". Urutan yg benar: (1) tambah field metode bayar di Booking dulu, (2) baru pikirkan rekonsiliasi spt ini kalau volume transaksi non-tunai sudah cukup besar utk butuh alat bantu pencocokan otomatis. Jangan dikerjakan terbalik. |

## Keuangan / Pengeluaran / Daftar Biaya

Sumber: `Majoo/Keuangan/Pengeluaran/Daftar Biaya` — AP (utang) utk biaya operasional ke Mitra:
"Bayar Sekarang" toggle (langsung bayar vs tunda dgn Tanggal Jatuh Tempo), multi-baris Nama
Akun+Jumlah, Potongan Harga. Dibayar terpisah via "Pembayaran Biaya" (Bayar Dari + No Referensi).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| AP biaya operasional (tunda bayar + jatuh tempo + bayar terpisah) | **Sudah Ada (kemungkinan besar)** | Ginnva sudah punya `PayableService`/`PayableResource` (`feedback_financial_transaction_integrity_audit`) — konsep sama (utang jatuh tempo, dibayar terpisah dari pencatatan biaya). Tidak perlu tindakan. |

Sidebar grup "Pengeluaran" jg ada: Daftar Pengeluaran, **Daftar Tagihan Rutin**, Daftar Mitra,
**Rekonsiliasi Refund Penjualan** — "Rekonsiliasi Refund Penjualan" khususnya menarik (pasangan
dari Rekonsiliasi Penerimaan Penjualan sebelumnya).

**"Daftar Mitra"** (dicek) — struktur identik "Daftar Pemasok" yg sudah dicatat (Kode/Nama/
Email/Telepon/Alamat + multi-rekening bank). Ini "Mitra" utk biaya operasional (beda konteks
dari Pemasok bahan baku), tapi pola datanya sama persis — kalau dibangun, satu model
Supplier/Vendor generik bisa dipakai utk dua-duanya, tidak perlu 2 tabel terpisah.

**"Daftar Pengeluaran"** (dicek) — mirror "Daftar Penerimaan" (pengeluaran kas langsung, BUKAN
lewat AP/Daftar Biaya), struktur identik (Detail Akun multi-baris, lampiran). Sudah tercakup
via `JournalEntryResource`, tidak perlu tindakan.

**"Daftar Tagihan Rutin"** (dicek) — template biaya berulang yg AUTO-GENERATE entri "Biaya" baru
secara berkala: pilih Nomor Biaya acuan, "Dibuat Tiap" N periode (Hari/Minggu/Bulan), Mulai
Tanggal, Batas Akhir opsional ("Setelah kali ke-X" atau "Pada Tanggal").

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Template tagihan rutin yg auto-generate Biaya/AP secara berkala | **Belum Ada — Relevan** | `PayableService` Ginnva kemungkinan cuma entry manual per transaksi. Berguna utk biaya rutin yg jumlahnya sama tiap periode (sewa toko, langganan software, dst) — hilangkan kerja input ulang manual tiap bulan. Nice-to-have produktivitas staff keuangan. |

## Keuangan / Pengeluaran / Rekonsiliasi Refund Penjualan

Sumber: `Majoo/Keuangan/Pengeluaran/Rekonsiliasi Refund Penjualan` — pasangan simetris dari
Rekonsiliasi Penerimaan: cocokkan transaksi refund yg sudah dicatat dgn dana yg BENAR-BENAR
dikirim ke customer, status Sudah/Belum Rekonsiliasi.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Rekonsiliasi refund tercatat vs dana benar2 terkirim | **Terhubung ke asumsi yg sudah tercatat** | `project_penjualan_majoo_blocked_items` #5: "ASUMSI: refund selalu dianggap dibayar tunai (kredit akun Kas 1101) — belum menangani kasus refund terhadap bagian yang masih Piutang Usaha". Fitur rekonsiliasi ini relevan justru utk menutup celah asumsi tsb — kalau refund TIDAK selalu instan tunai (mis. transfer manual belakangan), rekonsiliasi membantu pastikan janji refund benar2 dieksekusi. Prioritas menengah, terikat keputusan soal skenario refund non-tunai/Piutang di atas. |

Menuntaskan seluruh grup "Pengeluaran" (5/5 sub-halaman).

## Keuangan / Manajemen Aset

Fitur premium Majoo, tidak bisa diakses/discreenshot user. Tidak perlu tindakan — item tersisa
memang tidak bisa dicek, bukan terlewat (sama pola dgn "Harga Berdasarkan Waktu" & "Kustom Data
Pelanggan"/"Loyalty" sebelumnya). Catatan: Ginnva sudah punya `AssetResource` sendiri utk
manajemen aset tetap, jadi kemungkinan gap-nya rendah meski tidak bisa diverifikasi langsung.

---

## Keuangan / Laporan Keuangan / Laporan Jurnal

Sumber: `Majoo/Keuangan/Laporan Keuangan/Laporan Jurnal` — Jurnal Umum standar (No Transaksi,
Deskripsi, Kode/Nama Akun, Debit, Kredit, dikelompokkan per tanggal+transaksi, Total per
transaksi). Data riil (INV-00009: Diskon Penjualan, Piutang Usaha, Pendapatan).

Sidebar menyingkap seluruh grup "Laporan Keuangan": **Laporan Jurnal, Laporan Neraca, Laporan
Laba Rugi, Laporan Buku Besar, Laporan Arus Kas, Laporan Hutang, Laporan Piutang** — suite
laporan keuangan standar (7 laporan).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Suite laporan keuangan standar (Jurnal/Neraca/Laba Rugi/Buku Besar/Arus Kas/Hutang/Piutang) | **Perlu Verifikasi per-laporan** | Ginnva sudah punya `JournalEntryResource`+`ChartOfAccountResource` (data dasarnya ada), tapi belum jelas apakah SEMUA 7 laporan standar ini sudah py tampilan/export sendiri di Ginnva, atau baru sebagian (mis. cuma Jurnal Umum via resource, tanpa Neraca/Laba Rugi/Buku Besar terpisah). Perlu dicek satu-satu kalau mau tahu persis gap-nya — kemungkinan besar beberapa (terutama Buku Besar per-akun & Laporan Hutang/Piutang berumur) belum ada tampilan khusus. |

---

## Keuangan / Laporan Keuangan / Laporan Neraca

Sumber: `Majoo/Keuangan/Laporan Keuangan/Laporan Neraca` — Neraca standar berjenjang: Aset
Lancar (Kas&Setara Kas per rekening, Piutang Usaha, Persediaan) + Aset Tetap (dgn Akumulasi
Penyusutan), Hutang Jangka Pendek/Panjang, Ekuitas (Laba Ditahan + Laba Tahun Ini).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Laporan Neraca standar berjenjang per kategori akun | **Perlu Verifikasi** | Ginnva punya `ChartOfAccountResource` dgn hierarki akun (jadi datanya SIAP), tapi belum jelas apakah sudah ada halaman "Laporan Neraca" khusus yg menyusun otomatis per kategori (Aset Lancar/Tetap, dst) dgn subtotal berjenjang spt ini, atau staff harus susun manual dari daftar akun. |

---

## Keuangan / Laporan Keuangan / Laporan Laba Rugi

Sumber: `Majoo/Keuangan/Laporan Keuangan/Laporan Laba Rugi` — P&L berjenjang standar: Pendapatan
→ HPP → **Laba Kotor** → Beban Operasional → **Laba Operasional** → Pendapatan/Beban Lainnya →
**Laba Bersih**.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Laporan Laba Rugi berjenjang dari Jurnal (bukan agregat booking) | **Kemungkinan Overlap dgn SalesSummaryReport** | Konsepnya sama dgn `SalesSummaryReport` yg sudah dibangun (analog Ringkasan Penjualan Majoo) — bedanya laporan ini generik dari SEMUA jurnal (termasuk transaksi non-penjualan), bukan cuma dari booking. Kalau Ginnva mau laporan Laba Rugi resmi utk akuntan/investor (bukan cuma ringkasan penjualan operasional), laporan berbasis jurnal spt ini lebih akurat & lengkap. |

---

## Keuangan / Laporan Keuangan / Laporan Buku Besar

Sumber: `Majoo/Keuangan/Laporan Keuangan/Laporan Buku Besar` — buku besar per akun dgn **saldo
berjalan** (Saldo Awal → tiap transaksi Debit/Kredit → Saldo Akhir), bisa pilih multi-akun.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Buku Besar per akun dgn saldo berjalan (running balance) | **Belum Ada — Relevan** | Beda dari `JournalEntryResource` (kemungkinan daftar entri jurnal, bukan tampilan per-akun dgn saldo berjalan). "Riwayat 1 akun dari waktu ke waktu dgn saldo berjalan" adalah laporan akuntansi dasar yg berguna utk akuntan/audit — mis. lacak histori 1 rekening bank spesifik. Kandidat laporan baru yg cukup berguna. |

---

## Keuangan / Laporan Keuangan / Laporan Arus Kas

Sumber: `Majoo/Keuangan/Laporan Keuangan/Laporan Arus Kas` — laporan arus kas formal 3-bagian
(Aktivitas Operasional/Investasi/Pendanaan) + toggle **Langsung** vs **Tidak Langsung** (metode
tidak langsung mulai dari Laba Bersih, disesuaikan Piutang/Persediaan/Hutang), ditutup Saldo Kas
Awal → Kenaikan/Penurunan Kas → Saldo Kas Akhir Periode.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Laporan Arus Kas formal 3-bagian dgn 2 metode (Langsung/Tidak Langsung) | **Belum Ada — Relevan** | Dashboard Keuangan Ginnva cuma py chart arus kas sederhana (Saldo Masuk/Keluar/Keseluruhan), BUKAN laporan formal 3-aktivitas standar akuntansi ini. Laporan resmi spt ini biasanya diminta akuntan/investor/bank (mis. pengajuan kredit) — beda kelas dari sekadar chart dashboard. Kandidat laporan yg cukup penting kalau Ginnva mulai butuh laporan keuangan formal utk pihak eksternal. |

---

## Keuangan / Laporan Keuangan / Laporan Hutang

Sumber: `Majoo/Keuangan/Laporan Keuangan/Laporan Hutang` — ringkasan hutang teragregasi per
Pemasok (Grand Total Hutang), dgn "Filter Laporan" (kemungkinan aging/jatuh tempo).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Ringkasan hutang per pemasok (AP aging) | **Perlu Verifikasi** | `PayableResource` Ginnva kemungkinan list per transaksi, belum tentu ada ringkasan teragregasi per vendor + aging jatuh tempo. Berguna utk owner cek total kewajiban ke tiap vendor sekilas tanpa hitung manual dari daftar transaksi. |

## Keuangan / Laporan Keuangan / Laporan Piutang

Sumber: `Majoo/Keuangan/Laporan Keuangan/Laporan Piutang` — mirror simetris Laporan Hutang, tapi
per Pelanggan (Grand Total Piutang), dgn "Filter Laporan".

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Ringkasan piutang per pelanggan (AR aging) | **Perlu Verifikasi** | `ReceivableResource` Ginnva sama polanya dgn Payable — kemungkinan besar list per transaksi, belum tentu ada ringkasan teragregasi per customer + aging. Berguna utk follow-up penagihan piutang customer yg menunggak. |

Menuntaskan seluruh grup "Laporan Keuangan" (7/7 sub-laporan).

---

## Keuangan / Daftar Akun / Daftar Akun

Sumber: `Majoo/Keuangan/Daftar Akun/Daftar Akun` — manajemen COA (Header/Detail, Kode custom,
Kategori, lock icon = akun sistem tak bisa dihapus penuh vs "..." utk akun custom yg bisa
diedit/hapus).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Manajemen COA (tambah/edit akun custom, hierarki Header/Detail) | **Sudah Ada (kemungkinan besar)** | Ginnva sudah punya `ChartOfAccountResource`. Tidak perlu tindakan. |

**Temuan sampingan**: sidebar grup "Daftar Akun" jg ada **"Saldo Awal"** — fitur input saldo
awal tiap akun (opening balance) saat mulai pakai sistem/tahun buku baru. Perlu dicek apakah
Ginnva sudah punya mekanisme serupa (mis. saat migrasi data lama ke sistem baru, atau tutup buku
tahunan) — kalau belum, ini genuinely dibutuhkan kalau suatu saat ada reset/migrasi periode
akuntansi.

---

## Keuangan / Daftar Akun / Jurnal Umum

Sumber: `Majoo/Keuangan/Daftar Akun/Jurnal Umum` — entri jurnal manual, multi-baris akun, field
**"Selisih"** (real-time cek Debit vs Kredit balance, merah kalau belum seimbang) — safety-check
sebelum simpan. Fitur "Impor Jurnal Umum" (bulk import).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Validasi real-time keseimbangan Debit=Kredit sebelum simpan jurnal | **Perlu Verifikasi** | `JournalEntryResource` Ginnva HARUS sudah validasi ini di level backend (constraint akuntansi dasar), tapi belum jelas apakah ada indikator visual real-time "Selisih" spt ini di form Filament-nya, atau baru tervalidasi saat submit. UX kecil yg berguna. |
| Impor Jurnal Umum bulk via file | **Perlu Verifikasi** | Berguna kalau ada migrasi data lama/entri massal. |

## Keuangan / Daftar Akun / Saldo Awal

Sumber: `Majoo/Keuangan/Daftar Akun/Saldo Awal` — wizard onboarding SEKALI JALAN: set saldo
pembukaan (Debit/Kredit) per akun di "Tanggal Mulai Pencatatan" tertentu. Kalau ada transaksi
sebelum tanggal itu, otomatis buat jurnal pembalik. Status "Belum Diatur" utk Ginnva di trial.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Wizard set saldo pembukaan per akun + jurnal pembalik otomatis | **Belum Ada — Relevan kalau onboarding cabang baru/migrasi** | Ginnva kemungkinan mulai pencatatan dari 0 (tidak perlu saldo awal), jadi TIDAK mendesak sekarang. Tapi relevan kalau nanti: (a) buka cabang baru yg bawa saldo kas awal, atau (b) migrasi dari pembukuan manual/sistem lama ke Ginnva dgn saldo existing yg perlu dimasukkan. Simpan sbg referensi kalau kebutuhan itu muncul. |

Menuntaskan seluruh grup "Daftar Akun" (3/3) — dan dengan ini SELURUH cluster besar "Keuangan"
sudah tuntas diaudit.

---

## Karyawan / Dashboard Karyawan

Sumber: `Majoo/Karyawan/Dashboard Karyawan` — data riil 16 karyawan Ginnva (15 Pria/1 Wanita,
100% aktif akses majoo Teams). Widget: Rasio Gender, **Status Kepegawaian** (Tetap/Kontrak/
Pekerja Lepas — SEMUA 17 "Belum Diisi" di trial ini, artinya field-nya ada tapi belum pernah
diisi), Akses majoo Teams, Sebaran Lokasi Karyawan, Akses Cepat (shortcut ke Daftar Karyawan/
Jadwal Kerja/Kirim Akses Teams/Pengaturan Payroll/Pembayaran Payroll).

Sidebar mengonfirmasi struktur lengkap cluster "Karyawan": Dashboard Karyawan, Pengaturan
Karyawan, Payroll, Hak Akses, Absensi, majoo Teams, Jadwal Kerja, Pengaturan Master, Alur Kerja
Persetujuan.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Klasifikasi Status Kepegawaian (Tetap/Kontrak/Pekerja Lepas) per staff | **Belum Ada — Relevan** | Dicek `User.php` — tidak ada field ini sama sekali. Relevan krn mempengaruhi kewajiban payroll & kontrak kerja (UU Ketenagakerjaan RI berbeda utk pegawai tetap vs kontrak vs lepas) — terhubung ke `ContractExtension` yg sudah ada (utk perpanjangan kontrak, tersirat sudah ada konsep "kontrak" tapi mungkin belum eksplisit sbg field status). |
| Dashboard ringkasan HR (rasio gender, sebaran lokasi, status kepegawaian) | **Belum Ada — Relevan kecil** | Nice-to-have utk owner lihat komposisi tim sekilas — bukan mendesak, datanya bisa dihitung manual dari Daftar Karyawan yg sudah ada. |

---

## Karyawan / Pengaturan Karyawan / Daftar Karyawan

Sumber: `Majoo/Karyawan/Pengaturan Karyawan/Daftar Karyawan` — 19 screenshot, form karyawan
HRIS-grade paling lengkap yg ditemukan sepanjang audit ini.

**Field per kategori**:
- Data Utama: Foto, Nama, **NIP** (auto PT260020), Telepon, Email, Posisi, Hak Akses, Outlet, PIN
- Data Personal: Tempat/Tanggal Lahir, Jenis Kelamin, Agama, **Status** (kawin/dst), **NIK**,
  Email/Telepon Pribadi, Alamat KTP vs Domisili, **CV/Portofolio**, Pengalaman Bekerja,
  Pendidikan (Gelar/Jurusan/Lembaga/IPK), **NPWP**, **BPJS Ketenagakerjaan**, **BPJS Kesehatan**,
  upload KTP/KK/NPWP/Ijazah/Kartu BPJS/**Paklaring Perusahaan Sebelumnya**/**SK Saat Ini**,
  **Kontak Darurat** + **Tanggungan** (nama+nomor+hubungan), **Pembayaran Gaji** (Bank+No
  Rekening+Foto Buku Rekening+Nama Pemilik)
- Data Karyawan: Departemen, Posisi, Lokasi Perekrutan, Tanggal Bergabung, **Tipe Karyawan**,
  **Tanggal Awal Kontrak**, Struktur Organisasi (**Nama Atasan Langsung**)

**Fitur aksi per baris**: Detil/Ubah/**Kelola Kuota**/**Perpindahan Karyawan**/Lihat Hak
Akses/**Pengakhiran**/Hapus. "Perpindahan Karyawan" = wizard before→after per field (Departemen/
Posisi/Tipe/Outlet/Tanggal Efektif/Atasan) + checkbox "Data Tetap" + tombol **"Riwayat Karir"**
(log histori perubahan jabatan dari waktu ke waktu).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Profil karyawan HRIS lengkap (NIK/NPWP/BPJS/dokumen/kontak darurat/rekening gaji) | **Belum Ada — Relevan, gap besar** | `User.php` Ginnva kemungkinan cuma field dasar (nama/email/phone/role). Kalau Ginnva mau kelola data personalia formal (compliance BPJS/NPWP, bukan lewat Excel/paper terpisah), field2 ini jadi referensi lengkap. Prioritas tergantung apakah HR Ginnva saat ini sudah punya sistem lain (Excel dsb) yg "cukup", atau genuinely butuh 1 sistem terpusat. |
| Tipe Karyawan + Tanggal Awal Kontrak | **Belum Ada — Relevan** | Menguatkan temuan "Status Kepegawaian" di Dashboard Karyawan — sekarang jelas field-nya "Tipe Karyawan" + tanggal kontrak terpisah, bisa terhubung ke `ContractExtension` yg sudah ada. |
| Struktur Organisasi (Atasan Langsung) — relasi hierarki antar staff | **Belum Ada — Relevan kecil** | Berguna kalau nanti butuh alur approval berjenjang berbasis hierarki org (mis. cuti perlu approval atasan langsung otomatis, bukan role tetap), atau org chart visual. |
| Perpindahan Karyawan (wizard before→after) + Riwayat Karir (career history log) | **Belum Ada — Relevan** | Audit trail formal perubahan jabatan/departemen/outlet dari waktu ke waktu — beda dari `LogsActivity` generik (yg cuma catat perubahan kolom, bukan disajikan sbg "riwayat karir" yg mudah dibaca HR). |
| Pengakhiran (formal termination: Opsi Status + Alasan + Lampiran surat) | **Belum Ada — Relevan kecil** | Ginnva mungkin cuma py hard-delete atau toggle aktif/nonaktif user — "Pengakhiran" formal (dgn opsi status resign/PHK/dst, alasan, lampiran surat) beda dari sekadar nonaktifkan akun, berguna utk dokumentasi kepatuhan ketenagakerjaan. |
| Perpindahan Karyawan juga py field Alasan + Lampiran file | Sama kategori | Melengkapi detail wizard yg sudah dicatat. |
| "Lihat Hak Akses" — tree read-only per modul granular (Penjualan>Dashboard/Produk/Inventori/Pelanggan/Promosi/Komisi/Invoice/Marketing/Laporan Persediaan/dst) | Sudah Ada (kemungkinan besar) | Konsep serupa `hasMenuAccess()`/Role permission Ginnva yg sudah ada — tinggal cek apakah ada tampilan visual "lihat semua akses 1 staff" yg serapi ini, atau cuma bisa dilihat per-resource. |
| Kelola Kuota (Majoo SaaS license seats) | Tidak Relevan | Infrastruktur lisensi Majoo sendiri, tidak applicable ke arsitektur Ginnva. |

---

## Karyawan / Payroll / Pengaturan Payroll

Sumber: `Majoo/Karyawan/Payroll/Pengaturan Payroll` — 4/4 file diperiksa tuntas.

**Komponen Pendapatan**: Gaji Pokok, Tunjangan BPJS, **Kehadiran** ("Info kehadiran dapat
ditarik dari riwayat absensi karyawan pada Aplikasi Majoo" — toggle "Tarik data absensi"),
**Komisi** ("Info komisi dapat ditarik dari riwayat komisi karyawan" — toggle "Tarik data
komisi"), + "Tambah Komponen Pendapatan" custom.

**Komponen Potongan**: **Terlambat** ("dikenakan apabila karyawan masuk lewat dari tenggang
waktu jam masuk"), BPJS (potongan dari karyawan), + custom.

**Perhitungan Gaji**: periode custom (Tanggal Awal-Akhir, mis. 21→20, BUKAN kalender bulan
1-31/30). **Metode Pembayaran**: Transfer Otomatis (via rekening terhubung) vs Transfer Manual
(di luar sistem).

Sidebar mengonfirmasi grup "Payroll" lengkap: Pengaturan Payroll, Struktur Gaji, Daftar
Pemetaan Akun Gaji, Pembayaran Payroll, Laporan Pembayaran, **Rekonsiliasi Pembayaran**.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| **Payroll auto-tarik data dari Absensi (potongan terlambat) & Komisi** — integrasi lintas modul otomatis | **Belum Ada — Relevan, temuan penting** | Ginnva sudah punya modul Payroll + Absensi + Komisi terpisah (per memory lama), tapi belum jelas apakah SUDAH terintegrasi otomatis (payroll auto-hitung potongan telat dari data Absensi & auto-tambah komisi dari data Technician) atau staff HR masih input manual gabungan. Kalau manual, ini rawan human-error & re-entry ganda — integrasi otomatis spt ini bernilai tinggi utk akurasi payroll. |
| Periode gaji custom (potong tanggal, bukan kalender bulan) | **Perlu Verifikasi** | Umum di Indonesia (gajian tgl 25, periode kerja beda dgn kalender) — cek apakah `Payroll` Ginnva sudah fleksibel begini atau fixed kalender bulan. |
| Metode Pembayaran Transfer Otomatis vs Manual | **Perlu Verifikasi** | Transfer otomatis (integrasi bank/payment gateway utk disburse gaji massal) jauh lebih maju — kemungkinan besar Ginnva msh manual (staff transfer 1-1 dari rekening perusahaan), sesuai skala bisnis saat ini itu wajar & belum mendesak diubah. |

---

## Karyawan / Payroll / Struktur Gaji

Sumber: `Majoo/Karyawan/Payroll/Struktur Gaji` — 2/2 file diperiksa tuntas. Daftar Gaji Pokok
per karyawan (semua "-" belum diisi di trial). Impor/Ekspor Data. Aksi per baris: Ubah / **Log
Aktivitas**.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Log Aktivitas per perubahan gaji pokok karyawan | **Belum Ada — Relevan, data sensitif** | Data gaji adalah informasi paling sensitif di HR — audit trail wajib siapa-mengubah-berapa-kapan. Perlu dicek apakah `Payroll`/struktur gaji Ginnva sudah py `LogsActivity` (pola yg sama dgn gap `FilmProduct` sebelumnya — mungkin terlewat juga di sini). |

---

## Karyawan / Payroll / Daftar Pemetaan Akun Gaji

Sumber: `Majoo/Karyawan/Payroll/Daftar Pemetaan Akun Gaji` — 1/1 file diperiksa tuntas. Pemetaan
beban payroll per outlet ke akun COA tertentu (Terakhir Diubah + Oleh — ada log siapa yg atur).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Pemetaan otomatis beban payroll → akun COA spesifik (integrasi Payroll↔Jurnal) | **Belum Ada — Relevan, integrasi penting (dikonfirmasi)** | Ginnva punya Payroll DAN Jurnal Umum double-entry terpisah — pertanyaan kuncinya: apakah proses gajian bulanan otomatis bikin jurnal "Debit Beban Gaji / Kredit Kas" tanpa staff keuangan input ulang manual? **Dikonfirmasi via "Rekonsiliasi Pembayaran"**: deskripsi resminya eksplisit "menyesuaikan pencatatan JURNAL TRANSAKSI pembayaran gaji ke akun kas & bank" — jadi Majoo memang auto-generate jurnal payroll by design, lalu rekonsiliasi mencocokkannya ke mutasi bank riil (pola sama dgn Rekonsiliasi Penerimaan/Refund Penjualan di Keuangan). Kalau belum ada di Ginnva, ini integrasi bernilai tinggi (mirip `BookingPostingService` tapi utk Payroll). |

---

## Karyawan / Payroll / Pembayaran Payroll

Sumber: `Majoo/Karyawan/Payroll/Pembayaran Payroll` — 2/2 file diperiksa tuntas. Halaman kosong
di trial (belum setup rekening). Sistem cegah "Tambah Pembayaran" sebelum Pengaturan Payroll +
rekening lengkap — guardrail urutan setup, bukan fitur baru yg perlu dicatat terpisah.

Tidak ada temuan baru — konsisten dgn "Pengaturan Payroll" yg sudah dicatat.

---

## Karyawan / Payroll / Laporan Pembayaran

Sumber: `Majoo/Karyawan/Payroll/Laporan Pembayaran` — 1/1 file diperiksa tuntas. Laporan
sederhana (Nama Pembayaran, Outlet, Tanggal Transaksi, Penerima, Total). Kosong di trial, tidak
ada temuan baru di luar alur Payroll yg sudah dicatat.

---

## Karyawan / Payroll / Rekonsiliasi Pembayaran

Sumber: `Majoo/Karyawan/Payroll/Rekonsiliasi Pembayaran` — 1/1 file diperiksa tuntas. Sudah
dicatat & dikonfirmasi sbg pelengkap temuan "Daftar Pemetaan Akun Gaji" di atas.

Menuntaskan seluruh grup "Payroll" (6/6 sub-halaman: Pengaturan, Struktur Gaji, Pemetaan Akun,
Pembayaran, Laporan Pembayaran, Rekonsiliasi Pembayaran).

---

## Karyawan / Hak Akses / Daftar Hak Akses

Sumber: `Majoo/Karyawan/Hak Akses/Daftar Hak Akses` — 10/10 file diperiksa tuntas. Halaman
dokumentasi READ-ONLY (bukan editor) untuk **7 role tetap**: Owner, Admin, Manager, Warehouse,
Kasir, Waiters, Staff — masing2 dijelaskan kapabilitasnya dlm bahasa awam, dipisah kolom CMS vs
Mobile POS. Owner=akses penuh, Admin=setara Owner tanpa hapus produk penuh (?), Manager=terbatas
per-outlet (tambah produk boleh, hapus tidak, kecuali merchant 1-outlet baru setara Admin),
Warehouse=cuma modul inventori, Kasir=tidak bisa akses CMS sama sekali (cuma Mobile POS
terbatas: lihat produk, hardware, sinkronisasi, reservasi, **"Mengatur block out time"**),
Waiters=order tanpa bisa bayar, Staff=cuma bisa absen di Mobile POS org lain.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| 7 role tetap dgn kapabilitas baku (bukan per-menu granular kustom) | Bukan Gap (beda paradigma) | Ginnva sudah pakai sistem `hasMenuAccess()`/menu-access granular per-resource (lebih fleksibel drpd role tetap Majoo). Tidak perlu ditiru — pendekatan Ginnva justru lebih maju. |
| Halaman dokumentasi read-only "apa saja yg bisa dilakukan role X" dlm bahasa awam (utk onboarding staff baru/training) | **Belum Ada — Relevan kecil** | Nice-to-have utk training/onboarding staff baru paham batasan aksesnya tanpa tanya admin — Ginnva mungkin cuma py dokumentasi teknis (kode), bukan halaman awam spt ini. |
| "Mengatur block out time" (blokir slot waktu tertentu dari booking) — kapabilitas Kasir | **Belum Ada — Relevan, terhubung ke kapasitas booking** | Fitur block-out slot waktu (mis. tutup toko lebih awal hari tertentu, atau block slot krn maintenance bay) relevan utk `BookingResource`'s `fullDatesInRange()`/manajemen kapasitas yg sudah ada — perlu dicek apakah staff sudah bisa manual block 1 slot waktu tertentu tanpa mengubah jam operasional toko scr keseluruhan. |

---

## Karyawan / Hak Akses / Pengaturan Hak Akses

Sumber: `Majoo/Karyawan/Hak Akses/Pengaturan Hak Akses` — 32/32 file diperiksa tuntas. Ini
EDITOR untuk membuat/mengubah role custom (beda dari Daftar Hak Akses yg read-only) — 13 role
custom bisa dibuat via "Tambah Hak Akses". Struktur granularity JAUH lebih dalam drpd
`hasMenuAccess()` Ginnva: Modul (Penjualan/Order Online/Appointment/Karyawan/Keuangan/Whatsapp/
Chat Management/Pengaturan/Bantuan/Layanan/Inspirasi/Capital/Supplies) → toggle ON/OFF per modul
("Akses Modul X, jika dinonaktifkan pengguna tidak bisa akses menu X sama sekali") → Daftar
Fitur di dalam modul (mis. Penjualan: Dashboard/Laporan/Analisa Laporan/Produk/Inventori/
Pelanggan/Promosi/Komisi/Invoice/Marketing) → beberapa fitur expand jadi tabel **matriks
LIHAT/BUAT/UBAH/HAPUS/VOID per sub-fitur** (CRUD-level granular, bukan cuma bisa-akses/tidak).
Lalu ada tab TERPISAH "Hak Akses POS" (hak akses utk aplikasi mobile kasir, beda dari hak akses
CMS/dashboard) dgn modul sendiri: Kasir/Penjualan/Laporan/Absensi/Inventori/Pengaturan. Tab
terakhir "Pengaturan Lainnya" berisi **Otorisasi Refund/Otorisasi Void/Otorisasi Komplimen**
(toggle penanda role ini muncul sbg pilihan approver di layar "Otorisasi Oleh" POS saat kasir
minta approval refund/void/komplimen) + **Halaman Awal Dashboard dan POS** (pilih landing page
default saat role ini login, terpisah utk CMS vs POS).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Role custom dgn matriks modul→fitur→CRUD(Lihat/Buat/Ubah/Hapus/Void) granular per role | **Belum Ada — Relevan sebagian** | Ginnva `hasMenuAccess()` saat ini kemungkinan cuma toggle akses-menu ya/tidak per resource, TANPA breakdown CRUD terpisah (Lihat vs Buat vs Ubah vs Hapus vs Void) per role custom. Utk toko dgn banyak staff (kasir vs admin vs owner), granularity ini bisa berguna mis. staff boleh Lihat laporan tapi tidak boleh Hapus/Void — perlu verifikasi struktur permission Ginnva saat ini sebelum diputuskan perlu ditiru atau tidak. |
| Toggle "Void" sbg permission terpisah dari CRUD biasa (bukan bagian dari Hapus) | **Belum Ada — Relevan tinggi** | Void (pembatalan transaksi yg sudah tercatat, beda dari Hapus data master) adalah konsep permission tersendiri di Majoo. Perlu dicek: apakah Ginnva punya konsep otorisasi "Void" booking/transaksi terpisah dari hapus biasa? Ini terhubung ke [[feedback_financial_transaction_integrity_audit]] soal race-condition refund/void. |
| Hak Akses terpisah utk CMS vs Mobile POS (2 set permission berbeda per role) | **Bukan Gap langsung, tapi relevan konsep** | Ginnva juga punya 2 platform (Filament admin vs mobile app), tapi setahu ini permission mobile app kemungkinan belum sedetail/setara CMS. Worth dicek: apakah staff mobile app Ginnva (teknisi/kasir toko) punya kontrol akses granular per-fitur mobile, atau cuma role binary? |
| **Otorisasi Refund/Void/Komplimen** — toggle penanda role tertentu "boleh jadi approver" utk refund/void/komplimen yg diajukan kasir lain | **Belum Ada — Relevan TINGGI, terhubung [[project_segregation_of_duties]]** | Ginnva sudah punya `TransactionApprovalRequestResource` (approval Referral/Refund wajib full-access), TAPI mekanismenya global (siapapun full-access = approver), BUKAN per-role granular spt Majoo (bisa assign role spesifik mis. "Supervisor Toko" sbg approver refund tanpa full-access penuh). Pola Majoo lbh fleksibel utk struktur organisasi berjenjang. |
| "Halaman Awal Dashboard dan POS" — landing page default per role saat login (beda CMS vs POS) | **Belum Ada — Relevan kecil** | Nice-to-have UX: kasir login langsung ke halaman kasir, bukan dashboard umum yg tidak relevan utk role-nya. Ginnva mungkin selalu redirect ke halaman default yg sama utk semua role. |
| Daftar Ekspor (dalam modul Pengaturan → Daftar Fitur) — mengonfirmasi ulang fitur Ekspor Laporan cross-cutting | Sudah dicatat sebelumnya | Lihat bagian "Fitur UI/Interaksi Lintas Halaman" — tidak diulang di sini. |
| Modul "Appointment" dgn sub-fitur Tenaga Medis/Pasien/Preset Satu Sehat | **Tidak Relevan** | Ini fitur appointment klinik/kesehatan (integrasi Satu Sehat = platform kesehatan Kemenkes), tidak relevan utk bisnis PPF/Window Film. |
| "Akses Aplikasi Owner" (toggle khusus, "Hanya Owner yang dapat merubah") di form Informasi Hak Akses | **Bukan Gap** | Ini guardrail Majoo sendiri (siapa yg boleh assign akses setingkat Owner) — Ginnva sudah py konsep serupa via role `super_admin`/full-access di kode, bukan lewat UI form terpisah. |

---

## Karyawan / Absensi / Akses Absensi

Sumber: `Majoo/Karyawan/Absensi/Akses Absensi` — 1/1 file diperiksa tuntas. Halaman pengaturan
sangat sederhana: satu toggle "Fitur absensi fleksibel" — "Karyawan dapat melakukan absen
keluar di hari yang berbeda" (relevan utk shift yang melewati tengah malam, mis. absen masuk
jam 22:00 lalu absen keluar jam 06:00 keesokan harinya).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Toggle "absen keluar boleh beda hari dari absen masuk" (utk shift malam/lintas-hari) | **Belum Ada — Relevan kecil** | Terhubung ke [[project_keuangan_absensi_plan]] (rules dari Bu Yennie soal field-duty exception). Kalau desain Absensi Ginnva nanti pakai constraint "clock-out harus di hari yang sama dgn clock-in", perlu pengecualian eksplisit ini utk shift malam — kalau belum ada shift malam saat ini, prioritas rendah. |

**Temuan sampingan**: sidebar menyingkap sibling "Radius Absensi" (pengaturan radius GPS
absen) — belum di-screenshot, relevan tinggi krn `project_keuangan_absensi_plan` sudah
menyebut kebutuhan GPS/store-location check tapi belum dikonfirmasi radius-nya seperti apa.

## Karyawan / Absensi / Radius Absensi

Sumber: `Majoo/Karyawan/Absensi/Radius Absensi` — 2/2 file diperiksa tuntas. Fitur geofencing
absen: toggle "Fitur radius absensi" (ON) — "lokasi absen karyawan akan dibatasi sesuai radius
yang ditentukan", dgn warning eksplisit "Perangkat wajib terhubung ke internet saat absen
masuk/keluar". Tabel per-OUTLET (Nama Outlet, Alamat Outlet, **Jarak Radius** dalam meter — PT.
Ginnva Shield Indonesia diset 200 Meter). Tombol "Atur Jarak Radius" buka modal "Atur Outlet":
pilih outlet dari dropdown, input Jarak Radius (meter), Alamat Outlet (textarea), dan **peta
interaktif** (OpenStreetMap-based) dgn pin lokasi + tombol zoom +/- utk menentukan titik pusat
radius secara visual.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Radius absen (geofencing) per outlet, diset dalam meter via peta interaktif | **Belum Ada — Relevan tinggi, mengonfirmasi kebutuhan lama** | Langsung menjawab poin dari [[project_keuangan_absensi_plan]]: "ini implies needing GPS/store-location check ... worth asking when this is actually scoped". Sekarang ada contoh konkret desainnya: radius per-outlet (bukan global), input meter + pusat titik via peta, bukan cuma koordinat manual. Kalau Absensi mobile Ginnva mau clock-in/out dibatasi lokasi, pola ini bisa jadi referensi implementasi langsung. |
| Requirement eksplisit "perangkat wajib online saat absen" (bukan absen offline lalu sync nanti) | **Detail desain penting utk dicatat** | Menjawab pertanyaan implisit soal edge-case device offline — Majoo memilih pendekatan strict (harus online), BUKAN absen-offline-lalu-sync. Perlu didiskusikan apakah Ginnva mau pendekatan sama atau lebih toleran (mengingat rule "device/infra failure exception" dari Bu Yennie di `project_keuangan_absensi_plan` justru minta jalur manual-override saat infra down — jadi kemungkinan Ginnva perlu LEBIH fleksibel dari Majoo di titik ini, bukan menirunya). |
| Radius default 0 meter saat tambah outlet baru (harus diisi manual, tidak ada radius standar) | Detail kecil, bukan gap | Konfirmasi UX saja — tidak ada rekomendasi radius default dari Majoo (mis. 100m). Ginnva bebas menentukan default sendiri kalau dibangun. |

## Karyawan / majoo Teams / Akses majoo Teams

Sumber: `Majoo/Karyawan/majoo Teams/Akses majoo Teams` — 1/1 file diperiksa tuntas. "majoo Teams"
adalah aplikasi mobile terpisah utk karyawan (self-service: kemungkinan cek jadwal/absen/slip
gaji sendiri, beda dari aplikasi Kasir/POS). Halaman ini daftar status akses tiap karyawan ke
app tsb: checkbox bulk-select, kolom Nama Karyawan, No Telepon, **Akses majoo Teams** (badge
status "Aktif"), **Waktu Kirim Akses** (timestamp kapan invite/link akses dikirim ke karyawan
tsb), NIP, Outlet. Data riil staff Ginnva terisi penuh (Antony Fedryandi, Dedi Iriyanto, Elroy
Yeda Valentino, Friecella, Irfan, Luthfiandi — semua status "Aktif", terkirim 25-26 Agustus
2026). Sidebar menyingkap sibling "Kirim Notifikasi" (belum di-screenshot).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Aplikasi mobile self-service karyawan TERPISAH dari aplikasi Kasir/POS (2 app berbeda tujuan) | **Bukan Gap langsung, tapi worth verifikasi** | Ginnva sudah punya 1 mobile app (`ginnva-mobile`) — perlu dicek apakah app itu sudah melayani BAIK customer-facing (booking) MAUPUN staff-facing (absen/jadwal) dlm 1 app yg sama dgn role-based UI, atau perlu dipisah spt Majoo. Kemungkinan besar Ginnva sudah cukup dgn 1 app (tidak perlu ditiru dipisah), tapi baik dicatat sbg perbandingan arsitektur. |
| "Waktu Kirim Akses" — tracking kapan invite/link akses ke app dikirim per karyawan (bukan cuma status aktif/tidak) | **Belum Ada — Relevan kecil** | Kalau Ginnva punya alur "invite karyawan baru ke app mobile", audit trail kapan invite dikirim berguna utk support/troubleshooting ("karyawan blm terima link, kapan terakhir dikirim?"). Prioritas rendah, hanya relevan kalau proses onboarding karyawan ke app memang lewat invite manual spt ini. |
| Bulk-select checkbox di daftar karyawan (kemungkinan utk bulk kirim ulang akses/notifikasi) | Pola UI generik | Standar Filament table bulk action, tidak perlu dicatat terpisah. |

## Karyawan / majoo Teams / Kirim Notifikasi

Sumber: `Majoo/Karyawan/majoo Teams/Kirim Notifikasi` — 2/2 file diperiksa tuntas. Fitur
broadcast pengumuman internal ke karyawan via app "majoo Teams". List kosong (empty state
"Data tidak tersedia") dgn search + filter tanggal. Form "Tulis Notifikasi": Pilih Outlet,
Karyawan/Penerima (multi-select via modal "+ Pilih Karyawan" — bisa ditarget ke sebagian
staff, bukan cuma broadcast semua), **Tanggal Kirim Notifikasi** (bisa dijadwalkan ke masa
depan, bukan cuma kirim langsung — contoh default "17 September 2026, 09:00"), toggle
**"Sematkan Notifikasi"** (pin ke atas daftar notifikasi penerima), Subjek (maks 70 karakter),
Pesan (maks 250 karakter). Warning eksplisit: "Notifikasi yang sudah dikirim tidak dapat
diubah" (immutable setelah terkirim).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Broadcast pengumuman internal terjadwal ke karyawan tertentu/semua via mobile app, dgn opsi pin | **Belum Ada — Relevan kecil-menengah** | Ginnva blm terlihat punya kanal pengumuman internal terstruktur ke staff (mis. info cuti bersama, perubahan SOP, libur toko) — kemungkinan besar masih manual via WhatsApp grup. Fitur in-app notification dgn histori & jadwal kirim bisa berguna kalau jumlah cabang/staff bertambah dan WA grup mulai tidak scalable, tapi bukan kebutuhan mendesak selagi tim masih kecil. |
| Target penerima granular (outlet + pilih karyawan spesifik, bukan cuma broadcast semua) | Detail desain, sama kategori dgn di atas | Kalau fitur ini dibangun nanti, pola targeting per-outlet + per-individu ini bisa jadi referensi, bukan cuma broadcast all-or-nothing. |
| Notifikasi terjadwal (kirim di waktu tertentu di masa depan, bukan instan) | Detail desain, sama kategori | worth dicatat sbg detail implementasi kalau fitur ini prioritas nanti. |
| Notifikasi immutable setelah terkirim (tidak bisa diedit) | Bukan Gap, praktik wajar | Pola standar sistem notifikasi manapun — audit trail integrity, tidak perlu tindakan. |

## Karyawan / Jadwal Kerja / Daftar Jadwal Kerja

Sumber: `Majoo/Karyawan/Jadwal Kerja/Daftar Jadwal Kerja` — 3/3 file diperiksa tuntas. List
"Jadwal Kerja": Nama Jadwal Kerja, Jumlah Shift, Karyawan Terpilih, Tanggal Dibuat, Status —
1 contoh data riil "Jadwal Kerja Ginnva" (7 Shift, 17 Karyawan, dibuat 25 Agustus 2026, Aktif).
Tombol "Ekspor Daftar Jadwal Kerja" + "Tambah Jadwal Kerja". Form Tambah: Nama Jadwal Kerja,
Deskripsi, Status ON/OFF, **Pola Jadwal Kerja** — pilihan "Pola Standar" (7 Hari Kerja, template
tetap Senin-Minggu) vs "Pola Kustom" (Kustom Jadwal Kerja, kemungkinan siklus non-mingguan mis.
shift bergilir). Tabel per-hari (Senin s/d Minggu): kolom Shift (dropdown pilih dari Daftar
Shift), Jam Kerja, Jam Istirahat (otomatis terisi sesuai shift dipilih). Sidebar menyingkap
sibling "Daftar Shift" (definisi shift itu sendiri) dan "Jadwal Kerja Karyawan" (assignment ke
karyawan individual) — keduanya belum di-screenshot.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Template jadwal kerja mingguan (Pola Standar 7-hari) yang direuse ke banyak karyawan sekaligus | **Belum Ada — Relevan tinggi, langsung applicable** | Ini menjawab kebutuhan operasional nyata: bikin 1 "Jadwal Kerja Toko A" (Senin-Sabtu shift pagi, Minggu libur) lalu apply ke semua teknisi toko itu sekaligus, drpd input jadwal per orang manual. Terhubung ke [[project_keuangan_absensi_plan]] & data riil "Laporan Reservasi & Utilisasi" (883,5 jam kerja terjadwal 46,5 jam/minggu per staff) yg sudah ditemukan di audit Penjualan — mengonfirmasi Majoo memang punya modul jadwal terstruktur, bukan cuma expektasi implisit. |
| "Pola Kustom" (Kustom Jadwal Kerja) — kemungkinan siklus non-mingguan (shift bergilir/rolling) | **Belum Ada — Relevan sedang, perlu klarifikasi konsep dulu** | Isi detailnya belum kelihatan (cuma label di radio button) — kalau Ginnva ada kebutuhan shift bergilir (mis. teknisi kerja 6-hari-libur-1 dgn hari libur berputar, bukan fixed Minggu), pola ini relevan. Kalau semua staff Ginnva pakai jadwal fixed mingguan biasa, Pola Standar saja cukup. |
| "Ekspor Daftar Jadwal Kerja" | Konsisten dgn temuan Ekspor cross-cutting | Tidak dicatat ulang di sini — lihat "Fitur UI/Interaksi Lintas Halaman". |

## Karyawan / Jadwal Kerja / Daftar Shift

Sumber: `Majoo/Karyawan/Jadwal Kerja/Daftar Shift` — 2/2 file diperiksa tuntas. Definisi master
shift (dipakai Daftar Jadwal Kerja di atas). List: Nama Shift, Jam Kerja, Jam Istirahat, Warna
(swatch warna utk tampilan kalender), Status. 3 shift riil Ginnva terdaftar: "Jadwal Kerja
Ginnva" (08:30-17:00), "Jadwal Kerja Ginnva" (09:00-13:00, shift pendek/paruh waktu), "Jadwal
Kerja Ginnva (1)" (jam kosong). Form Tambah Shift: Nama Shift, Status ON/OFF, **Tipe Shift**
(dropdown, contoh terisi "Hari Kerja" — kemungkinan ada opsi lain spt "Hari Libur"/off-day),
Waktu Mulai/Selesai Kerja (wajib), Waktu Mulai/Selesai Istirahat (opsional), **Warna Shift**
(color picker hex, mis. #609966 — utk visual di kalender jadwal), Label (dropdown, default
"Tidak Ada Label" — kemungkinan label kustom spt "Shift Pagi"/"Shift Malam").

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Master data Shift terpisah dari Jadwal Kerja (shift didefinisikan sekali, direuse ke berbagai hari/jadwal) | **Belum Ada — Relevan tinggi, satu paket dgn temuan Daftar Jadwal Kerja** | Pola normalisasi yang baik: shift (jam kerja+istirahat+warna) adalah entity terpisah dari jadwal mingguan yang menugaskannya ke hari tertentu. Kalau Absensi/Jadwal Kerja Ginnva dibangun, disarankan ikuti pola 2-tabel ini (`shifts` + `work_schedules`) drpd hardcode jam kerja langsung di tabel jadwal. |
| Waktu istirahat (jam mulai/selesai) terpisah dari jam kerja, opsional per shift | **Belum Ada — Relevan kecil** | Berguna utk perhitungan jam kerja aktual vs terjadwal (worth dikaitkan ke temuan "Metrik Utilisasi Teknisi" dari audit Penjualan sebelumnya — jam istirahat harus dikeluarkan dari hitungan utilisasi). |
| Warna shift (color picker) utk visual kalender | Nice-to-have UI kecil | Murni polesan visual, bukan prioritas fungsional. |
| "Tipe Shift" dropdown (Hari Kerja vs kemungkinan Hari Libur) & "Label" dropdown kustom | Belum jelas cakupannya, worth verifikasi lanjut | Isi pilihan dropdown belum terlihat (cuma default value) — kalau field ini dibangun nanti, worth klik dropdownnya dulu utk tau opsi lengkapnya sebelum desain skema Ginnva. |

## Karyawan / Jadwal Kerja / Jadwal Kerja Karyawan

Sumber: `Majoo/Karyawan/Jadwal Kerja/Jadwal Kerja Karyawan` — 6/6 file diperiksa tuntas. Ini
halaman ASSIGNMENT — menyilangkan karyawan individual dgn Jadwal Kerja/Shift yg sudah
didefinisikan sebelumnya (2 folder di atas). Dua mode tampilan: **List** (Nama Karyawan, NIP,
Outlet, Departemen, Posisi, Jadwal Kerja Per Hari Ini) dan **Kalender** (grid mingguan per
karyawan × hari, tiap sel nampilin jam shift "08:30-17:00" + nama jadwal, dgn navigasi minggu
‹›, tampilan mingguan default "14 Sep 2026 - 20 Sep 2026"). Klik sel kalender → modal **"Ubah
Shift"**: ganti shift HARI ITU SAJA utk karyawan tsb (dropdown pilih dari shift yg ada) — override
per-hari tanpa mengubah keseluruhan jadwal mingguannya. Dari List, menu "..." per karyawan →
**"Atur Jadwal Kerja"** (assign jadwal + **Tanggal Efektif** — jadwal baru berlaku mulai tanggal
tsb, bukan langsung retroaktif) dan **"Riwayat Jadwal Kerja"** (log histori penugasan jadwal per
karyawan: Nama Jadwal Kerja, Tanggal Mulai, Tanggal Berakhir — audit trail perubahan jadwal
karyawan dari waktu ke waktu, mis. Antony Fedryandi mulai "Jadwal Kerja Ginnva" sejak 26 Agt
2026 tanpa tanggal berakhir = masih berlaku).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Tampilan Kalender mingguan (karyawan × hari, shift per sel) dgn quick-edit per hari | **Belum Ada — Relevan tinggi, ini bentuk UI paling actionable dari seluruh cluster Jadwal Kerja** | Menyatukan seluruh temuan Daftar Jadwal Kerja + Daftar Shift jadi 1 tampilan operasional yg langsung dipakai owner/admin sehari-hari: lihat siapa kerja kapan dlm 1 layar, dan override 1 hari spesifik (mis. tukar shift dadakan) tanpa ubah template jadwal permanen. Ini kandidat UI paling bernilai kalau modul Jadwal Kerja Ginnva dibangun — lebih penting drpd fitur "Daftar Jadwal Kerja"/"Daftar Shift" itu sendiri yg lebih backend/setup. |
| "Tanggal Efektif" saat assign jadwal baru ke karyawan (bukan langsung berlaku retroaktif) | **Belum Ada — Relevan sedang** | Penting utk kasus mis. karyawan pindah shift mulai bulan depan — assignment tidak langsung menimpa histori masa lalu. Terhubung ke temuan "Riwayat Jadwal Kerja" di bawah (perlu tanggal mulai/berakhir yg jelas per periode). |
| "Riwayat Jadwal Kerja" — log histori penugasan jadwal per karyawan (Tanggal Mulai/Berakhir per jadwal yg pernah di-assign) | **Belum Ada — Relevan sedang, terhubung [[feedback_audit_trail_sweep]]** | Kalau modul Jadwal Kerja dibangun, sebaiknya dari awal desain tabel `employee_schedule_assignments` dgn kolom mulai/berakhir (bukan cuma 1 kolom "jadwal_saat_ini" di tabel karyawan) supaya histori otomatis tercatat tanpa perlu `LogsActivity` tambahan. |
| Ekspor Jadwal Kerja Karyawan | Konsisten dgn temuan Ekspor cross-cutting | Tidak dicatat ulang — lihat "Fitur UI/Interaksi Lintas Halaman". |

**Penutup cluster Jadwal Kerja**: 3 folder (Daftar Shift, Daftar Jadwal Kerja, Jadwal Kerja
Karyawan) membentuk 1 sistem terpadu 3-layer: **Shift** (jam kerja mentah) → **Jadwal Kerja**
(template mingguan yg mengisi shift ke tiap hari) → **Jadwal Kerja Karyawan** (assignment +
kalender operasional + histori). Kalau Ginnva membangun modul serupa, disarankan ikuti 3-layer
ini secara utuh, bukan cuma sebagian, supaya fleksibilitas (ganti shift 1 hari tanpa ubah
template, riwayat penugasan) tidak hilang.

## Karyawan / Pengaturan Master / Tingkat Jabatan

Sumber: `Majoo/Karyawan/Pengaturan Master/Tingkat Jabatan` — 2/2 file diperiksa tuntas. Master
data hierarki jabatan (bukan cuma daftar posisi flat). List kosong (Kode, Nama, Tanggal
Diperbarui) + tombol "Ubah Urutan" (drag-reorder hierarki) + "Tambah Tingkat Jabatan". Form
Tambah: Kode (mis. "T01"), Nama (mis. "SPV"), **"Pilih Tingkat Di Atasnya"** (dropdown parent,
default placeholder "BOD/Owner" — hint teks: "Tingkat jabatan pertama adalah yang paling
tertinggi"). Jadi ini bikin RANTAI hierarki eksplisit (BOD/Owner → Manager → SPV → Staff, dst),
bukan cuma label jabatan independen. Sidebar menyingkap sibling: **Organisasi**, **Tipe
Karyawan**, **Quick PIN** — belum di-screenshot.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Master Tingkat Jabatan dgn hierarki eksplisit berjenjang (tiap level tau siapa atasannya) | **Belum Ada — Relevan sedang, worth verifikasi struktur User Ginnva dulu** | Perlu dicek: apakah `User.php` Ginnva punya field jabatan/level terstruktur sama sekali, atau cuma role akses (`hasMenuAccess`)? Kalau belum ada konsep "jabatan" formal (beda dari role akses sistem), fitur ini relevan utk keperluan HR/organisasi (struktur gaji berjenjang, approval berjenjang [[project_segregation_of_duties]], org chart) — TAPI jangan disamakan dgn role akses Filament yg sudah ada, itu 2 konsep berbeda (role akses = apa yg BOLEH dilakukan di sistem, jabatan = posisi di struktur organisasi perusahaan). |
| "Ubah Urutan" (drag-reorder daftar tingkat jabatan) | Detail UI kecil | Standar pola reorder, tidak perlu dicatat terpisah kalau fitur induknya (Tingkat Jabatan) dibangun. |

## Karyawan / Pengaturan Master / Organisasi

Sumber: `Majoo/Karyawan/Pengaturan Master/Organisasi` — 2/2 file diperiksa tuntas. Master data
struktur Departemen/Posisi organisasi, pola sama persis dgn "Tingkat Jabatan" (hierarki
Parent-child). List kosong: Kode Departemen/Posisi, Nama Departemen/Posisi, Type. Filter
"Semua Departemen". Form Tambah Departemen: **Parent** (dropdown, default "BOD/Owner", hint
"Departemen pertama adalah yang paling tertinggi"), Kode Departemen (mis. "Depart01"), Nama
Departemen (mis. "Sales").

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Struktur organisasi Departemen/Posisi berjenjang, terpisah dari "Tingkat Jabatan" | **Belum Ada — Relevan sedang, satu kategori dgn temuan Tingkat Jabatan** | Majoo pisahkan 2 dimensi organisasi: **Tingkat Jabatan** (level hierarki umum: BOD→Manager→Staff) vs **Organisasi/Departemen** (unit kerja: Sales, Finance, Operasional). Karyawan biasanya py 1 Tingkat Jabatan + 1 Departemen (2 sumbu berbeda). Kalau Ginnva mau bangun struktur organisasi formal, disarankan ikuti pemisahan 2-sumbu ini drpd digabung jadi 1 field "posisi" saja — supaya laporan bisa di-slice per-departemen ATAU per-level scr independen. |
| CATATAN JANGAN DISALAHARTIKAN: "Departemen" di sini (HR/organisasi) BEDA dari "Departemen" di Produk (Penjualan/Produk/Daftar Departemen — kategori produk PPF/Kaca Film) | **Perlu kehati-hatian penamaan** | Majoo sendiri pakai istilah "Departemen" utk 2 konsep berbeda total (unit kerja karyawan vs kategori produk) — kalau Ginnva mengadopsi salah satu/kedua konsep ini, sebaiknya pakai nama tabel/field yang jelas beda (mis. `employee_departments` vs `product_departments`) supaya tidak membingungkan di kode. |

## Karyawan / Pengaturan Master / Tipe Karyawan

Sumber: `Majoo/Karyawan/Pengaturan Master/Tipe Karyawan` — 2/2 file diperiksa tuntas. Master
data status kepegawaian, bisa custom (bukan enum tertutup). 3 data default: **Karyawan Tetap**
("Tanpa Tanggal Berakhir"), **Karyawan Kontrak** ("Dengan Tanggal Berakhir"), **Pekerja Lepas**
("Dengan Tanggal Berakhir"). Form Tambah: Nama Tipe Karyawan (placeholder contoh "Karyawan
Magang" — konfirmasi tipe custom dimungkinkan), checkbox **"Memiliki Tanggal Berakhir"** (kalau
dicentang, tipe ini nantinya minta input tanggal kontrak berakhir per karyawan).

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Master Tipe Karyawan custom (bukan enum tetap) dgn flag "Memiliki Tanggal Berakhir" | **Belum Ada — Relevan tinggi, menjawab gap lama** | Langsung menjawab gap yang sudah dicatat di temuan "Dashboard Karyawan" sebelumnya: "flagged missing `employment_type` field in `User.php`" — sekarang ada contoh konkret desainnya: bukan cuma enum Tetap/Kontrak/Lepas hardcoded, tapi tabel master terpisah yg bisa ditambah HR sendiri (mis. "Karyawan Magang", "Probation") + flag butuh tanggal berakhir atau tidak. Terhubung jg ke `ContractExtension` yg sudah ada di Ginnva (perpanjangan kontrak) — perlu ada field employment_type dulu supaya jelas mana karyawan yg butuh perpanjangan kontrak (Kontrak/Lepas) vs tidak (Tetap). |

## Karyawan / Pengaturan Master / Quick PIN

Sumber: `Majoo/Karyawan/Pengaturan Master/Quick PIN` — 2/2 file diperiksa tuntas. Toggle
"Login Cepat dengan PIN" (OFF) — "Staf bisa langsung masuk menggunakan PIN tanpa perlu memilih
nama terlebih dahulu. Fitur tersedia pada POS versi 3.2.6x". Klik aktifkan → modal konfirmasi
"Aktifkan Quick PIN": "Semua PIN karyawan akan diganti dengan PIN baru secara otomatis. Setelah
proses selesai, Anda dapat mengunduh daftar PIN baru untuk dibagikan ke karyawan."

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Login cepat kasir via PIN numerik (bukan username/password) di aplikasi POS | **Belum Ada — Terikat rencana POS** | Konsisten dgn `project_pos_plan` (POS/Kasir belum dibangun) — fitur ini murni utk kecepatan ganti kasir di 1 device fisik (shift berganti, staf beda-beda login cepat tanpa ketik password tiap kali). Baru relevan SETELAH modul POS/Kasir walk-in dibangun, jangan didahulukan. |
| Regenerasi massal PIN semua karyawan sekaligus + fitur unduh daftar PIN baru | Detail desain, sama kategori dgn di atas | Kalau PIN login dibangun nanti (setelah POS ada), pola ini (regenerate all + export list utk dibagikan manual ke staff) bisa jadi referensi UX-nya. |

## Karyawan / Alur Kerja Persetujuan

Fitur **premium/tidak dapat diakses** di akun trial Majoo yang dipakai user (sama seperti
"Manajemen Aset" dan "Harga Berdasarkan Waktu" yang ditemukan sebelumnya di cluster Keuangan/
Produk) — tidak ada screenshot isi halaman krn terkunci di balik paywall. Dari namanya sendiri
("Alur Kerja Persetujuan" / approval workflow) dan posisinya sejajar dgn "Hak Akses" di sidebar
Karyawan, kemungkinan besar ini adalah builder alur approval multi-step yang bisa dikonfigurasi
(mis. pengajuan cuti → approve atasan langsung → approve HR, atau pengajuan kasbon → approve
manager → approve keuangan), analog dgn `TransactionApprovalRequestResource` Ginnva tapi versi
generik/configurable utk berbagai jenis pengajuan, bukan cuma refund/referral.

| Fitur Majoo | Status | Catatan |
|---|---|---|
| Builder alur approval multi-step configurable (kemungkinan isinya, belum terverifikasi) | **Tidak Dapat Diverifikasi — Premium** | Tidak bisa dinilai lebih lanjut tanpa akses ke fitur ini. Kalau Ginnva suatu saat butuh approval berjenjang generik di luar Refund/Referral yang sudah ada (mis. approval cuti, approval kasbon, approval perubahan jadwal), [[project_segregation_of_duties]] sudah py pola dasarnya (approval wajib full-access) — tinggal digeneralisasi jadi builder kalau kebutuhannya makin beragam, tidak perlu menunggu riset fitur premium Majoo ini. |

## Belum disusuri (folder tersisa)

- Penjualan: SELESAI TUNTAS (seluruh cluster sudah diaudit & direkap).
- Keuangan: SELESAI TUNTAS (seluruh cluster sudah diaudit & direkap).
- Karyawan: SELESAI TUNTAS — seluruh cluster (Dashboard, Pengaturan Karyawan, Payroll, Hak
  Akses, Absensi, majoo Teams, Jadwal Kerja, Pengaturan Master, Alur Kerja Persetujuan [premium,
  tidak dapat diverifikasi]) sudah diaudit.

## Cara pakai file ini

Update tabel di atas setiap kali selesai menyusuri 1 folder. Setelah semua folder selesai,
rangkum keputusan final (dibangun / tidak) sebagai memory project baru, ambil dari file ini.
