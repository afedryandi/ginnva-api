# Rancangan Ulang Sistem Marketing/Konten Ginnva

Dibuat 2026-10-15. Status: **usulan untuk diputuskan, belum ada yang dibangun.**
Cakupan: menu Marketing/Konten di panel admin (Konten Beranda, Materi, Rekrutmen, Inquiry & Kemitraan), API publik yang melayaninya, situs web `ginnva-web`, dan aplikasi mobile; untuk PPF, kaca film, detailing, dan premium wash.

## Status dan keputusan (diperbarui 2026-10-15)

**Keputusan pemilik:**
1. **Lead WhatsApp dicatat manual dulu**, bukan WhatsApp Business API. Catatan penting: `https://api.whatsapp.com/send?phone=628118681678` yang dipakai di situs adalah **tautan klik-untuk-chat biasa**, bukan API yang terhubung ke sistem kita; setelah diketuk, percakapan terjadi di WhatsApp di luar sistem.
2. **Satu orang** mengelola dan menyetujui konten serta klaim produk.
3. **Foto pekerjaan pelanggan di galeri pemasaran hanya dengan izin opt-in** saat serah terima (bawaan tidak), dan plat nomor diburamkan sebelum tayang.
4. **Notifikasi promosi push: opt-in eksplisit.** Notifikasi transaksional (booking, QC, serah terima, pengingat jadwal) tidak terpengaruh.
5. Keputusan nomor 5, 7 dari bagian 6 mengikuti rekomendasi (ulasan Google tanpa imbalan; Rekrutmen dan Materi masuk kecil).

**Status pengerjaan:**

| Fase | Status |
|---|---|
| 0. Ukur | **Sebagian besar ditulis (2026-10-22), belum di-commit/diuji di server.** API: kolom atribusi (kanal, UTM, click id, halaman asal, perujuk) di `quotations`, `product_inquiries`, `partnership_inquiries` (migrasi `2026_10_22_000001`); `LeadAttribution` menyaring data klien (kanal ditentukan server: aplikasi = `app`, web = `web_form`); `POST /api/staff/leads` dan halaman **Catat Lead Manual** di Inquiry Produk (kanal WhatsApp/telepon/datang langsung/DM/lainnya, tanpa notifikasi "inquiry baru"); kolom dan filter Kanal/Kampanye di tiga tabel. Web: `lib/tracking.ts` + `TrackingInit` menangkap UTM dan click id (30 hari), mengirimnya bersama formulir quotation/inquiry/kemitraan, dan mengirim event GA4 `generate_lead` serta `whatsapp_click` (terverifikasi di browser lokal). Mobile (working copy): layar Catat Lead di daftar Lead Quotation staf. **Belum:** dasbor (Fase 1), pembacaan UTM di aplikasi mobile pelanggan, konfigurasi GTM/Meta Pixel. |
| 1–5 | Belum dimulai. |

**Isi container GTM `GTM-5PJP992L` (dibaca dari skrip publiknya, 100.000 dari 385.505 karakter pertama; pembacaan sebagian):** tag Google (`G-62PM39HVCD`), event GA4 bernama `pageview`, Meta Pixel `1099983105814889` (PageView), dan event Meta kustom `click_whatsapp` untuk klik pada tautan yang memuat `api.whatsapp`. **Tidak ada** tag atau pemicu untuk `generate_lead` (tidak ada event Lead ke Meta, jadi iklan Meta tidak bisa dioptimalkan untuk lead sungguhan), dan tidak ada tag Google Ads. Container memuat skrip `meta-capi-param-builder-clientjs` dari jsDelivr dan unpkg (opsi "opt-in Meta CAPI" menyala), yang diblokir CSP situs. Temuan turunan: (1) tag Google `G-62PM39HVCD` kemungkinan terpasang **dua kali** (langsung lewat `GoogleAnalytics.tsx` dan lewat GTM); periksa kemungkinan penghitungan ganda di GA4. (2) Event GA4 `whatsapp_click` dari kode kita melengkapi `click_whatsapp` di GTM (yang hanya ke Meta), bukan menggandakannya. (3) Perbaikan CAPI yang disarankan: matikan opsi opt-in CAPI di template tag Meta di GTM, bukan melonggarkan CSP. (4) Untuk event Lead ke Meta, buat tag Meta `Lead` dengan pemicu Custom Event `generate_lead` (kode situs sudah mendorong event itu ke `dataLayer`).

**Temuan baru saat verifikasi Fase 0 (2026-10-22):** konsol browser menunjukkan **Content Security Policy situs memblokir sebagian komponen Meta Pixel/CAPI**: bingkai `facebook.com` (frame-src), pengiriman formulir ke `facebook.com/tr/` (form-action), skrip `meta-capi-param-builder-clientjs` dari jsDelivr/unpkg (script-src), dan titik akhir gateway CAPI (connect-src). Tidak diubah: melonggarkan CSP memperluas permukaan serangan. Periksa dulu di Meta Events Manager (Test Events) apakah event Pixel/CAPI dari situs production sampai; baru putuskan apakah perlu mengizinkan domain tertentu atau mengatur ulang tag di GTM.

---

## 1. Ringkasan

Sistem sekarang sudah punya **semua kepingan konten dasar** (banner, seri produk, berita, galeri pemasangan, materi unduhan, lowongan, inquiry) dan jejak audit. Yang belum ada adalah **sambungan antara pemasaran dan penjualan**:

1. **Pemasaran tidak bisa diukur.** Iklan berbayar (terlihat dari Sentry: lalu lintas dari Instagram ke `/ppf`) menuju halaman kampanye yang tombol utamanya membuka WhatsApp. Tidak ada catatan lead yang lahir dari klik itu, dan tabel lead (quotation, inquiry produk, kemitraan) tidak menyimpan kanal, kampanye, atau UTM. Tidak ada jawaban untuk "iklan mana yang menghasilkan booking".
2. **Lead tidak dikejar dengan disiplin.** Quotation punya pengingat telat; inquiry produk dan kemitraan tidak. Status hanya Baru/Dihubungi/Selesai, tanpa pemilik, tanpa hasil (menang/kalah), tanpa ukuran waktu respons.
3. **Klaim produk ditulis tangan di kode.** Halaman kampanye memuat angka (TSER, UV, VLT, garansi 8–10 tahun) sebagai teks tetap. Tidak ada satu sumber kebenaran, tidak ada bukti uji tercatat, dan mengubahnya butuh developer. Itu risiko hukum (UU Perlindungan Konsumen) dan risiko konsistensi.
4. **Bukti sosial tidak terkelola.** Galeri pemasangan tidak mencatat izin pelanggan, tidak ada langkah buram plat, tidak ada pasangan sebelum/sesudah, tidak ada teks alternatif gambar.
5. **Kepatuhan data pemasaran belum ada.** Tidak ada pemisahan notifikasi transaksional vs promosi, tidak ada opt-in/opt-out promosi, dan tidak ada izin pemakaian foto pelanggan.

Sama seperti booking: **bukan sistem rusak, dan bukan tulis ulang.** Pendekatannya evolusi bertahap, tiap fase dirilis dan teruji sendiri.

## 2. Riset: standar industri

**Catatan keterbatasan (penting):** hampir semua sumber adalah blog agensi/vendor; tidak ditemukan tolok ukur konversi yang diukur khusus untuk bengkel PPF/kaca film/detailing, dan tidak ada standar Indonesia yang baku untuk pemasaran di bidang ini. Sumber hukum dan platform saya cek ke teks resmi bila tersedia. Yang bertanda *[primer]* berasal dari dokumen resmi; sisanya arahan praktik.

### 2.1 Penanganan lead
- Satu tempat untuk semua lead (formulir web, pesan Google Business Profile, telepon, formulir iklan), notifikasi internal instan, dan balasan otomatis singkat; SMS lebih efektif daripada email untuk hal mendesak. [S1]
- Kecepatan respons adalah pembeda; satu-satunya angka keras yang ditemukan berasal dari studi dealer mobil (49% merespons dalam 5 menit; 75% gagal melakukan 8+ percobaan menghubungi), **bukan** bengkel detailing. Jadikan arahan, bukan target terbukti. [S2]
- Ukur waktu respons dan tingkat booking **per sumber lead**; tanpa itu kebocoran tidak terlihat. [S1]

### 2.2 Atribusi iklan dan WhatsApp
- Iklan Klik-ke-WhatsApp melewati situs, jadi Pixel biasa tidak melihat apa pun; Ads Manager hanya tahu "percakapan dimulai", bukan apakah jadi lead atau penjualan. Hasil harus dikirim balik dari sistem sendiri. [S3]
- Kunci atribusi sebenarnya adalah `ctwa_clid` yang dikirim Meta pada pesan masuk pertama lewat WhatsApp Business API; UTM hanya label pelengkap. Tanpa API WhatsApp Business (hanya aplikasi WhatsApp biasa), `ctwa_clid` **tidak tersedia**. [S3]
- Untuk situs: GA4 punya event rekomendasi `generate_lead` (parameter `value`, `currency`); klik WhatsApp tidak punya event bawaan sehingga dibuat event kustom (mis. `whatsapp_click`) lewat GTM atau `dataLayer`. [S4]
- Ketentuan teknis Meta/GA4 berubah; cek dokumen resmi versi API yang dipakai sebelum membangun.

### 2.3 Konten, galeri, dan SEO lokal
- Halaman layanan terpisah (PPF sendiri, layanan + kota), galeri sebelum/sesudah dengan sudut foto konsisten, deskripsi masalah per kasus, teks alternatif deskriptif. [S5]
- Data terstruktur: Organization di beranda, LocalBusiness per lokasi, Service per layanan, FAQPage di bagian FAQ; hanya menandai yang terlihat di halaman; jangan memakai rating bila tidak terlihat akurat. Bukan jaminan peringkat. [S5]
- Gambar: `next/image`, dimensi eksplisit (mencegah CLS), `sizes` sesuai grid, `priority` hanya untuk gambar LCP, WebP/AVIF; ukur sendiri hasilnya. [S6]
- Lowongan: Google mensyaratkan `title`, `datePosted`, `description`, `hiringOrganization`, `jobLocation`; `validThrough` direkomendasikan; **lowongan kedaluwarsa tidak diperbolehkan** (hapus, 404/410, atau `validThrough` lampau) dan isi markup harus terlihat di halaman. *[primer]* [S7]

### 2.4 Ulasan dan bukti sosial
- Meminta ulasan Google boleh. **Dilarang:** imbalan/diskon/poin untuk ulasan, meminta ulasan hanya dari pelanggan puas, menekan saat di tempat, meminta isi tertentu, target jumlah ulasan untuk staf. *[primer]* [S8]
- Foto/testimoni pelanggan butuh izin yang jelas lingkupnya (apa, di mana, berapa lama, cara menarik), disimpan bersama catatan pekerjaan; plat nomor diburamkan sebelum diunggah. [S9]

### 2.5 Hukum dan kepatuhan Indonesia (verifikasi ke teks resmi sebelum dijadikan kebijakan)
- UU Perlindungan Konsumen (8/1999): Pasal 9 dan 10 melarang menawarkan/mengiklankan barang-jasa secara tidak benar atau menyesatkan (harga, kegunaan, kondisi, jaminan); Pasal 17 tentang iklan yang mengelabui; Pasal 8(1)(f) produk tidak sesuai janji iklan; Pasal 62: pidana hingga 5 tahun atau denda Rp2 miliar. [S10]
- UU PDP (27/2022): persetujuan eksplisit untuk tujuan tertentu adalah dasar pemrosesan; persetujuan tertulis atau terekam; hak penolakan pemrosesan. PP 33/2026 (efektif 16 Jan 2027) memperketat persetujuan. Tidak ditemukan pasal khusus "pemasaran langsung" dalam sumber yang diperoleh. [S11]
- WhatsApp: kebijakan platform mensyaratkan opt-in eksplisit untuk pesan pemasaran (syarat platform, terpisah dari hukum). [S11]
- Kaca film: batas VLT (kegelapan) di Indonesia dijelaskan **berbeda-beda** antar sumber; jangan dijadikan aturan di sistem sebelum dicek ke teks resmi. TSER/IR/VLT adalah ukuran berbeda; tidak ditemukan aturan Indonesia yang spesifik tentang pembuktian klaim penolak panas, sehingga berlaku prinsip umum UUPK. [S12]

### 2.6 Notifikasi dan banner di aplikasi
- Pisahkan transaksional (boleh sering) dan promosi (dibatasi); segmentasi; kendali jenis dan frekuensi oleh pengguna; uji waktu kirim; patokan awal 2–3 push promosi per pekan hanya titik awal, bukan aturan. [S13]

## 3. Audit sistem sekarang

### 3.1 Yang sudah baik (dipertahankan)
- Semua model konten punya jejak audit (`LogsActivity`) dan toggle aktif; berita punya jadwal terbit dan slug; sitemap menyertakan berita (kecuali tautan eksternal).
- Inquiry produk menotifikasi staf yang berhak lewat panel; quotation punya pengingat telat dan pemisahan toko.
- Situs punya data terstruktur untuk produk dan FAQ, GA dan GTM terpasang, `llms.txt`.
- Unggahan banner dibatasi 3 MB dengan rasio ideal tertulis.

### 3.2 Celah dibanding standar
| # | Area | Kondisi sekarang (bukti) | Standar | Dampak | Ukuran |
|---|---|---|---|---|---|
| 1 | Atribusi | Tabel `quotations`, `product_inquiries`, `partnership_inquiries` tidak punya kanal/kampanye/UTM/click id; `source` quotation hanya `customer`/`staff`; tidak ditemukan penangkapan UTM di formulir lead web; tidak ditemukan pemanggilan event GA4 di kode (mungkin di GTM, belum diperiksa) | Kanal + kampanye per lead; `generate_lead`; `whatsapp_click` | Tidak tahu iklan mana yang jadi booking | **M** |
| 2 | WhatsApp | Halaman `/ppf`, `/kaca-film`, `campaign`, `campaign2` mengutamakan tautan `api.whatsapp.com`; klik tidak membuat catatan apa pun | Lead tercatat; klik dihitung; (opsional) `ctwa_clid` lewat API WhatsApp Business | Lead di WhatsApp tak terlihat sistem | **M–B** |
| 3 | Tindak lanjut | Inquiry produk dan kemitraan: status 3 langkah, tanpa pemilik, tanpa hasil/alasan kalah, tanpa pengingat telat (hanya quotation yang punya); notifikasi hanya ke panel | Pemilik, SLA, pengingat berulang, hasil, waktu respons | Lead dingin tanpa disadari | **S–M** |
| 4 | Klaim produk | Spesifikasi (TSER 61%, UV 99%, VLT, garansi 8–10 tahun) tertulis di `kaca-film/campaign*/page.tsx`; dua halaman kampanye hampir kembar; tidak terhubung ke data produk; tanpa bukti uji | Satu sumber data klaim, bukti uji dan tanggal, persetujuan | Risiko UUPK pasal 9/10 dan klaim tak konsisten | **M** |
| 5 | Banner | Tidak ada jadwal tayang (mulai/selesai), teks alternatif, pratinjau, atau hitung klik; audiens hanya pelanggan/partner/keduanya; gambar disajikan asli dari `storage` tanpa pengecilan | Jadwal, alt text, ukuran responsif, ukuran klik | Banner usang tayang terus; tak terukur | **S** |
| 6 | Berita | Tidak ada judul/deskripsi SEO, kategori, atau teks alternatif; tidak ada skema Article; siapa pun dengan akses menu langsung menerbitkan (tanpa tinjauan) | Meta SEO, Article schema, alur tinjau-terbit | SEO lemah; risiko salah terbit | **S** |
| 7 | Galeri pemasangan | `CaseStudy` = gambar + kendaraan + produk film; tanpa jenis layanan (Detailing/Premium Wash tidak terwakili), tanpa pasangan sebelum/sesudah, tanpa catatan izin pelanggan, tanpa langkah buram plat, tanpa alt text; foto sumber (penerimaan, progres, serah terima) tak bisa "dinominasikan" dengan izin | Sebelum/sesudah, izin tercatat, plat buram, alt text | Risiko privasi; galeri tak mencerminkan semua layanan | **M** |
| 8 | Ulasan | Ulasan internal toko ada; tidak ada alur meminta ulasan Google setelah serah terima | Permintaan ke semua pelanggan, tanpa imbalan | Ulasan lokal tidak tumbuh | **S** |
| 9 | Rekrutmen | `JobOpening` tanpa tanggal terbit, tanpa batas berlaku, tanpa lamaran terlacak; halaman karier tanpa `JobPosting` | `datePosted`, `validThrough`, penghapusan otomatis | Lowongan kedaluwarsa; tak muncul di Google Jobs | **S** |
| 10 | Materi unduhan | Endpoint `/api/materials` publik; tanpa versi, tanpa kedaluwarsa, tanpa hitung unduhan, tanpa pemilahan audiens | Audiens, versi, ukuran penggunaan | Dokumen lama/internal bisa terbuka | **S** |
| 11 | Komunikasi promosi | Notifikasi broadcast ke semua pengguna ada (terlihat di daftar notifikasi pelanggan), tetapi tidak ditemukan bidang preferensi/opt-in promosi, pemisahan transaksional vs promosi, atau batas frekuensi | Opt-in eksplisit, pemisahan, batas frekuensi | Risiko UU PDP; pelanggan mematikan semua notifikasi | **M** |
| 12 | Ukuran | Tidak ada dasbor pemasaran (lead per sumber, waktu respons, konversi ke booking, biaya per lead) | Dasbor sederhana | Keputusan anggaran iklan tanpa data | **M** |
| 13 | SEO teknis | Skema ada untuk produk/FAQ/dealer; tidak ada untuk berita dan lowongan; Core Web Vitals belum pernah diukur di dokumen kita | Skema lengkap; pengukuran berkala | Peluang SEO terlewat | **S** |

Ukuran: **S** kecil, **M** menengah, **B** besar.

### 3.3 Yang belum bisa dipastikan dari kode (perlu data Anda)
- Konfigurasi GTM (event apa yang sudah terkirim) dan apakah Meta Pixel aktif; tidak terlihat di kode.
- Status Google Business Profile tiap toko dan apakah ulasan sudah dikelola.
- Siapa pengguna panel Marketing/Konten (satu orang, tim, atau agensi) dan siapa yang menyetujui isi.
- Anggaran dan platform iklan (Meta saja? Google Ads?) serta berapa lead bulanan dari WhatsApp vs formulir.

## 4. Rancangan target

### 4.1 Prinsip
1. Ukur dulu, baru optimalkan: tidak ada fitur kampanye sebelum atribusi dasar.
2. Satu sumber kebenaran untuk klaim produk; halaman kampanye membaca data, bukan teks tetap.
3. Perubahan bersifat **tambahan** (kolom/menu baru), aplikasi dan situs yang beredar tetap jalan.
4. Izin pelanggan dicatat sebelum foto/data dipakai untuk pemasaran.
5. Setiap fase dirilis sendiri dengan tes dan daftar uji, dan status dilaporkan jujur (selesai / sebagian / ditunda).

### 4.2 Model data (usulan, tambahan)
| Entitas | Isi | Catatan |
|---|---|---|
| Kolom atribusi pada lead | `channel`, `utm_source/medium/campaign/content`, `click_id`, `landing_path`, `referrer` | Pada `quotations`, `product_inquiries`, `partnership_inquiries`; diisi dari formulir web dan app |
| `lead_events` / pemilik lead | pemilik, `first_response_at`, hasil (`won`/`lost`), alasan kalah | Memakai pola waktu respons yang sama untuk ketiga tipe lead |
| `product_claims` | produk, nama klaim (TSER/UV/VLT/garansi), nilai, sumber/bukti (berkas), tanggal uji, disetujui oleh/kapan | Sumber tunggal angka di web, app, dan materi |
| Kolom pada `carousels` | `starts_at`, `ends_at`, `alt_text`, hitung klik | Tampil hanya dalam jendela jadwal |
| Kolom pada `news` | `meta_title`, `meta_description`, `category`, `reviewed_by` | + skema Article di web |
| Kolom pada `case_studies` | `service_type`, `after_image`, `alt_text`, `marketing_consent_id`, `plate_blurred` | Pasangan sebelum/sesudah, jenis layanan Detailing/Premium Wash terwakili |
| `marketing_consents` | pelanggan/booking, lingkup (foto, testimoni), kanal, masa berlaku, ditarik atau tidak | Opsi dicatat saat serah terima (kita sudah punya langkah serah terima) |
| Kolom pada `job_openings` | `posted_at`, `valid_through`, opsional gaji | Tutup otomatis setelah batas |
| Preferensi promosi pelanggan | `promo_opt_in`, waktu, sumber | Transaksional tetap terkirim; promosi hanya bila opt-in |

### 4.3 Alur target (ringkas)
**Lead:** pengunjung dari iklan → halaman kampanye (UTM tertangkap, klik WhatsApp dihitung, atau formulir singkat) → lead tercatat dengan sumber → pemilik ditetapkan dan notifikasi instan → SLA dan pengingat → hasil (menang/kalah + alasan) → laporan per sumber.
**Konten:** usulan konten → tinjauan → terjadwal → tayang dalam jendela jadwal → diarsipkan; klaim hanya dari `product_claims` yang disetujui.
**Bukti sosial:** serah terima mencatat izin foto (opt-in) → staf menominasikan foto pekerjaan → plat diburamkan, alt text diisi → tinjauan → tayang di galeri; permintaan ulasan Google dikirim ke semua pelanggan setelah serah terima (tanpa imbalan).

### 4.4 Fitur dan menu
**Panel admin:** Lead gabungan dengan sumber dan pemilik; dasbor pemasaran; Klaim Produk; jadwal dan alt text banner; SEO berita; galeri dengan izin; lowongan dengan batas berlaku; preferensi promosi.
**Situs web:** UTM dan klik WhatsApp tertangkap; halaman kampanye dari data; skema Article, JobPosting, Service; gambar dioptimalkan.
**Aplikasi mobile:** pengaturan notifikasi (transaksional vs promosi); banner terjadwal dan bersegmen; formulir lead mengirim sumber.

### 4.5 Aturan UI/UX
- Formulir lead pendek (nama, kontak, kebutuhan); pesan galat menjelaskan perbaikannya.
- Setiap gambar punya alt text; kontras dan ukuran sentuh mengikuti panduan yang sudah kita pakai.
- Tombol WhatsApp membawa teks awal yang menyebut halaman/kampanye asal.

### 4.6 Keandalan dan keamanan
- Endpoint publik tetap dibatasi laju; tidak ada data pelanggan di respons publik.
- Perubahan klaim dan banner tercatat (siapa, kapan, apa) lewat jejak audit yang sudah ada.
- Berkas unduhan diberi audiens dan masa berlaku.

### 4.7 Ukuran keberhasilan
Persentase lead dengan sumber tercatat; waktu respons pertama (median) per sumber; tingkat lead → booking per kampanye; jumlah halaman dengan klaim dari sumber tunggal; persentase foto galeri dengan izin tercatat; jumlah ulasan baru per toko per bulan; opt-out promosi.

## 5. Peta jalan

| Fase | Isi | Terlihat |
|---|---|---|
| **0. Ukur** | Kolom atribusi pada tiga tipe lead; penangkapan UTM di formulir web dan app; event `generate_lead` dan `whatsapp_click`; audit GTM/Pixel | Setiap lead punya sumber |
| **1. Lead terkelola** | Pemilik, hasil, alasan kalah, waktu respons, pengingat telat untuk inquiry produk dan kemitraan; dasbor pemasaran dasar | Lead tidak dingin; tahu iklan mana yang jadi |
| **2. Klaim dan konten** | `product_claims` dengan persetujuan; halaman kampanye membaca data; jadwal dan alt text banner; SEO berita | Klaim konsisten dan terbukti; banner usang berhenti sendiri |
| **3. Bukti sosial** | Izin foto saat serah terima; nominasi foto ke galeri; plat buram; sebelum/sesudah; permintaan ulasan Google | Galeri sah dan mencakup semua layanan |
| **4. SEO dan lowongan** | Skema Article/JobPosting/Service; masa berlaku lowongan; optimalisasi gambar; pengukuran Core Web Vitals | Lebih mudah ditemukan |
| **5. Komunikasi promosi** | Preferensi promosi vs transaksional dengan opt-in; batas frekuensi; banner bersegmen; ukuran klik | Patuh UU PDP, notifikasi relevan |

Urutan ini memprioritaskan **mengukur dan mengejar lead** (Fase 0–1) karena dampaknya langsung pada pendapatan dan tidak menyentuh isi yang dilihat publik; klaim dan bukti sosial (Fase 2–3) menutup risiko hukum; sisanya mengoptimalkan.

## 6. Keputusan yang dibutuhkan (dengan rekomendasi)

| # | Pertanyaan | Opsi | Rekomendasi saya |
|---|---|---|---|
| 1 | Cara menangkap lead WhatsApp | Manual (staf mencatat + kode sumber) / WhatsApp Business API (atribusi `ctwa_clid` otomatis, ada biaya dan kerja integrasi) | **Manual dulu** dengan tombol catat cepat; API nanti bila volume WhatsApp tinggi |
| 2 | Struktur lead | Satu tabel lead baru / perluas tiga tabel yang ada + tampilan gabungan | **Perluas yang ada** (tambahan, risiko rendah); gabungkan di tampilan |
| 3 | Penyetuju klaim produk | Satu orang (direksi/lead marketing) / siapa pun | **Satu penyetuju**, dicatat |
| 4 | Izin foto pelanggan untuk pemasaran | Opt-in di serah terima / tidak dipakai foto pelanggan | **Opt-in (kotak centang, bawaan tidak)** saat serah terima; tanpa izin, foto tidak boleh masuk galeri |
| 5 | Permintaan ulasan Google | Ya, ke semua pelanggan tanpa imbalan / tidak | **Ya, tanpa imbalan apa pun** (jangan kaitkan dengan poin atau diskon) |
| 6 | Notifikasi promosi | Opt-in eksplisit / opt-out | **Opt-in eksplisit**; transaksional tidak terpengaruh |
| 7 | Cakupan Rekrutmen dan Materi | Masuk / ditunda | **Masuk kecil** (masa berlaku lowongan dan audiens materi) |
| 8 | Siapa mengelola konten | Satu orang / tim / agensi | **Perlu jawaban Anda** (menentukan hak akses dan alur tinjau) |

## 7. Batasan riset
- Hampir semua rujukan praktik adalah blog agensi dan vendor dengan kepentingan menjual jasa; angka konversi dan persentase dari mereka **tidak dipakai sebagai target**.
- Tidak ditemukan standar Indonesia yang baku untuk pemasaran bengkel PPF/kaca film/detailing, ataupun aturan Indonesia spesifik tentang pembuktian klaim TSER/IR.
- Batas VLT kaca film di Indonesia dilaporkan berbeda-beda antar sumber; **belum diverifikasi ke teks resmi**.
- UU PDP: tidak ditemukan pasal khusus pemasaran langsung; ketentuan PP 33/2026 bersumber dari ringkasan berita. Rujuk teks resmi dan penasihat hukum sebelum menjadikannya kebijakan tertulis.
- Aturan Meta/WhatsApp dan GA4 berubah; cek dokumentasi resmi versi terbaru sebelum membangun.

## 8. Sumber
- **[S1]** [The 7 Automations Every Wrap, Tint & PPF Shop Needs in 2026 (AutoStyle Marketing)](https://autostylemarketing.com/the-7-automations-every-wrap-tint-ppf-shop-needs-in-2026/2/): vendor.
- **[S2]** [Study: Car dealers are best in effective lead follow-up (Auto Remarketing)](https://www.autoremarketing.com/ar/analysis/study-car-dealers-are-best-effective-lead-follow/): studi dealer mobil, bukan bengkel detailing.
- **[S3]** [Track CTWA conversions with CAPI (Wati)](https://www.wati.io/en/blog/track-ctwa-conversions-capi/), [Track WhatsApp ad campaigns with UTMs (Kommo)](https://www.kommo.com/support/messenger-apps/whatsapp-analytics-utms), [Track click-to-WhatsApp ctwa_clid (Whapi)](https://whapi.cloud/blog/track-click-to-whatsapp-ctwa-clid): vendor; verifikasi ke dokumentasi Meta.
- **[S4]** [GA4 recommended events (Google)](https://support.google.com/analytics/answer/9267735?hl=en-EN), [Track WhatsApp clicks in GTM + GA4 (Boei)](https://boei.help/blog/track-whatsapp-clicks-google-tag-manager/).
- **[S5]** [Schema playbook (AOK Marketing)](https://aokmarketing.com/ai-readable-seo-schema-playbook/), [The Auto Detailing Website Landscape (CyberOptik)](https://cyberoptik.net/blog/best-auto-detailing-websites-success-secrets): vendor.
- **[S6]** [Building an effective Image Component (Chrome)](https://developer.chrome.com/blog/image-component/), [Optimize images in Next.js (OpenReplay)](https://blog.openreplay.com/optimize-images-nextjs-performance/).
- **[S7]** [Job posting structured data (Google)](https://developers.google.com/search/docs/appearance/structured-data/job-posting): primer.
- **[S8]** [Prohibited and restricted content: review solicitation (Google)](https://support.google.com/contributionpolicy/answer/7400114): primer.
- **[S9]** [Customer photos and testimonials in marketing (Sprintlaw)](https://sprintlaw.co.uk/articles/customer-photos-testimonials-marketing/), [Why blur license plates (Folio3)](https://www.folio3.ai/blog/why-blur-license-plates-in-videos): hukum Inggris/praktik umum, bukan hukum Indonesia.
- **[S10]** [Sanksi iklan overclaim (Hukumonline)](https://www.hukumonline.com/klinik/a/buat-iklan-produk-ioverclaim-i--ini-sanksi-hukumnya-lt66d7a1842aa18) dan jurnal akademik tentang Pasal 9/10/17 UUPK.
- **[S11]** [Persetujuan pemrosesan data pribadi (Hukumonline)](https://www.hukumonline.com/berita/a/persetujuan-pemrosesan-data-pribadi-dilakukan-melalui-persetujuan-tertulis-atau-terekam-lt6565d6fcdac34/), [Aturan turunan UU PDP (Bisnis.com, Agustus 2026)](https://teknologi.bisnis.com/read/20260829/84/1999753/aturan-turunan-uu-pdp-persetujuan-data-konsumen-lebih-ketat), [UU 27/2022 (Pasal.id)](https://pasal.id/peraturan/uu/uu-no-27-tahun-2022).
- **[S12]** [Aturan kaca film mobil sesuai hukum (Auto2000)](https://auto2000.co.id/berita-dan-tips/aturan-kaca-film-mobil), [Salah kaprah kaca film "lebih gelap lebih adem" (Otodriver)](https://otodriver.com/berita/2019/salah-kaprah-kaca-film-lebih-gelap-lebih-adem-salbijgidem): sumber berita; batas VLT tidak konsisten.
- **[S13]** [Push notification best practices (GoodBarber)](https://www.goodbarber.com/blog/6-best-practices-to-get-the-most-value-for-your-push-notifications-a975/), [AppLikeWeb](https://applikeweb.com/blog/push-notification-best-practices/): vendor.
