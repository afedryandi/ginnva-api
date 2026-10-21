# Rancangan Ulang Sistem Notifikasi Ginnva

Dibuat 2026-10-21 atas permintaan pemilik: rancang ulang menu Notifikasi (data, sistem, alur kerja, fungsi, fitur, menu, UI/UX di API dan mobile) untuk PPF, kaca film, detailing, dan premium wash, sesuai standar, tanpa bug, mudah dipakai, dengan riset internet. **Dokumen ini hanya audit, riset, dan rancangan. Belum ada kode yang diubah.** Pola sama dengan `RANCANGAN_ULANG_KEUANGAN.md`, `RANCANGAN_ULANG_PENJUALAN.md`, `RANCANGAN_ULANG_MASTER_DATA.md`.

## Keputusan pemilik yang sudah ada (2026-10-21)

- **Kotak masuk staf di mobile, dan semua notifikasi staf juga masuk kotak masuk** (keputusan 1 dan 2).
- **Periksa kuitansi Expo dan bersihkan token mati otomatis** (keputusan 3).
- **Jam tenang promosi pelanggan 21:00 sampai 08:00 WIB;** notifikasi booking/transaksi tidak terpengaruh (keputusan 4).
- **Kirim Notifikasi: pratinjau penerima + kirim terjadwal + templat kampanye + hasil per kampanye** (keputusan 6).
- Belum dijawab: 5 (pilihan kategori), 7 (WhatsApp, rekomendasi: tunda), 8 (retensi, rekomendasi: pertahankan 180 hari).

## 1. Ringkasan

Menu "Notifikasi" di panel kecil (Kirim Notifikasi dan Riwayat Notifikasi Pelanggan/Mitra), tetapi sistem di belakangnya besar: sekitar 35 titik kode mengirim push ke staf, lebih dari 15 perintah terjadwal mengirim pengingat, pelanggan dan mitra punya kotak masuk, dan WhatsApp dipakai untuk pengingat servis berkala. Masalahnya bukan satu bug, melainkan **tidak ada satu jalur pengiriman yang konsisten**:

1. **Staf: push tanpa jejak.** Dari sekitar 35 berkas yang mengirim push ke staf, hanya sekitar 12 yang juga membuat notifikasi di lonceng panel. Sisanya push saja. Aplikasi mobile staf **tidak punya kotak masuk sama sekali**, jadi push yang terlewat (telepon mati, izin dicabut, token usang) hilang tanpa bekas.
2. **"Terkirim" belum berarti sampai.** Sistem menghitung status `ok` dari Expo, yaitu tiket (pesan diterima Expo), bukan kuitansi pengiriman ke Google/Apple. Kuitansi tidak pernah diperiksa, dan pengiriman otomatis (non-manual) tidak meninggalkan catatan hasil apa pun.
3. **Kontrol penerima terbatas.** Pelanggan punya izin promo dan batas mingguan, tetapi tidak ada jam tenang atau pilihan per kategori; staf tidak bisa mengatur apa pun.
4. **Menu Kirim Notifikasi belum menjawab "siapa yang akan menerima dan apa hasilnya"**: tidak ada pratinjau jumlah penerima sebelum kirim, tidak ada jadwal kirim, tidak ada hasil per kampanye selain hitungan saat dikirim.

Usulan: satu pengirim (dispatcher) dengan kategori dan jalur yang jelas, kotak masuk staf di mobile yang memakai tabel notifikasi yang sudah ada, log pengiriman dengan pemeriksaan kuitansi Expo, dan perbaikan menu Kirim Notifikasi. Terbagi 7 fase (0 sampai 6), tiap fase bisa dirilis sendiri. **Ada 8 keputusan pemilik** (bagian 6).

## 2. Riset

Catatan kejujuran: sumber berupa dokumentasi resmi (Expo) dan blog/panduan vendor; angka statistik dari blog marketing tidak dipakai.

**Push lewat Expo (dokumentasi resmi).** Pengiriman dua tahap: **tiket** berarti Expo menerima pesan; **kuitansi** tersedia setelah Expo mencoba mengirim ke FCM/APNs. Dokumentasi menyatakan kuitansi **harus diperiksa**, disarankan menunggu sekitar 15 menit, dan kuitansi dihapus setelah 24 jam. Bila kuitansi berisi `DeviceNotRegistered`, token harus berhenti dipakai sampai perangkat mendaftar ulang; terus mengirim ke perangkat yang tidak terdaftar berisiko membuat aplikasi dibatasi oleh Apple/Google ([Expo: Send notifications](https://docs.expo.dev/push-notifications/sending-notifications), [forum Expo: receipt errors](https://forums.expo.dev/t/push-notification-receipt-errors-how-to-handle-them-correctly/38928)). Ginnva sudah menghapus token pada `DeviceNotRegistered` di tahap tiket; pada tahap kuitansi belum.

**Desain notifikasi.** Konsisten antar panduan: pisahkan **kategori** (transaksional/penting, operasional, promosi) dengan anggaran frekuensi terpisah; hitung batas pada jendela bergulir; **jam tenang** bawaan malam hari untuk notifikasi tidak mendesak; status berhenti/izin diperiksa **saat kirim** sebagai filter keras; notifikasi tidak mendesak masuk **kotak masuk** (pusat notifikasi) alih-alih mengganggu; hindari isi sensitif (jumlah uang) pada layar kunci ([Courier: pusat notifikasi](https://www.courier.com/guides/how-to-build-a-notification-center/chapter-3-best-practices-for-notification-centers), [Produktly](https://produktly.com/guides/how-to-design-notification-systems), [Plotline: pembatasan frekuensi](https://www.plotline.so/blog/frequency-capping-in-app-messaging/)). Angka seperti "1 sampai 3 per hari" hanyalah titik awal untuk diuji, bukan standar.

**WhatsApp Business Platform.** Tiga kategori templat: **Utility** (non-promosi, terkait pesanan/transaksi/keselamatan pengguna), **Marketing**, **Authentication**. Templat yang bercampur utility dan promosi atau kontennya tidak jelas dianggap Marketing dan ditagih lebih mahal; Meta dapat mengubah kategori templat. Aturan persetujuan (opt-in) untuk Marketing disebut vendor, tetapi kata-kata resminya **tidak terverifikasi** dalam pencarian ini; periksa langsung ke Meta ([Meta: pedoman kategori templat](https://developers.facebook.com/docs/whatsapp/updates-to-pricing/new-template-guidelines/), [Courier: jenis pesan WhatsApp](https://www.courier.com/guides/whatsapp-message-types)).

**Batas riset.** Tidak ada standar resmi Indonesia khusus notifikasi aplikasi bengkel. Izin menerima promosi sudah mengikuti prinsip persetujuan (lihat pengerjaan ulang Marketing). Biaya WhatsApp per templat tidak dicari.

## 3. Audit sistem sekarang

Semua butir **diverifikasi di kode** kecuali ditandai.

### 3.1 Yang ada

| Bagian | Isi |
|---|---|
| Menu | Notifikasi: Kirim Notifikasi (`SendNotification`), Riwayat Notifikasi Pelanggan, Riwayat Notifikasi Mitra |
| Pengirim | `PushNotificationService` (Expo): ke pelanggan (dengan baris kotak masuk), ke staf toko, ke pemantau booking, ke daftar pengguna; pemecahan 100 token per permintaan, jeda antar-batch, percobaan ulang satu-satu bila token asing, hapus token `DeviceNotRegistered` |
| Kotak masuk | Pelanggan dan mitra: ada, dengan status baca (siaran memakai tabel baca terpisah). Staf web: lonceng panel (notifikasi basis data Laravel/Filament). **Staf mobile: tidak ada.** |
| Terjadwal | Lebih dari 15 perintah (pengingat booking, kontrak, garansi, bahan kedaluwarsa, aset, utang/piutang, persetujuan tertunda, lead telat, absen lupa clock-out/belum absen, dan lainnya) |
| Pelanggan | Kategori `promo` atau `info`; promo hanya untuk yang setuju, dibatasi per minggu (`PROMO_MAX_CAMPAIGNS_PER_WEEK`); ada kunci kampanye |
| WhatsApp | `WhatsAppService` (templat Meta) untuk pengingat servis berkala dan pendaftaran mitra; diam-diam dilewati bila kredensial kosong |
| Retensi | `notifications:prune-old` menghapus notifikasi lebih dari 180 hari |

### 3.2 Celah

| # | Temuan | Bukti | Dampak |
|---|---|---|---|
| N1 | **Push ke staf tanpa jejak di sekitar dua pertiga titik pengiriman.** Dari 35 berkas yang memanggil `sendToStoreStaff`, `sendToUsers`, atau `sendToBookingWatchers`, hanya sekitar 12 juga memanggil `sendToDatabase`. Contoh push saja: chat booking, booking baru, SPK, kuitansi penagihan, pengingat booking harian, absen belum masuk, izin/cuti, pengajuan pembelian. | pencarian `sendToDatabase` vs pemanggil push | Pesan penting hilang bila push tidak sampai; tidak ada riwayat untuk ditelusuri |
| N2 | **Aplikasi mobile staf tidak punya kotak masuk.** Tidak ada layar atau endpoint riwayat notifikasi untuk staf; hanya pendaftaran token. | `app/staff` mobile, `routes/api.php` | Staf mobile bergantung penuh pada push yang mungkin terlewat |
| N3 | **Kuitansi Expo tidak diperiksa.** Status `ok` dari tiket dihitung "terkirim"; token mati baru terhapus jika tiket langsung menolak. | `PushNotificationService::pushToTokens` | Jumlah "terkirim" menyesatkan; token usang tetap dipakai sampai ketahuan |
| N4 | **Pengiriman otomatis tidak dicatat hasilnya.** Hanya kiriman manual (Kirim Notifikasi) menampilkan jumlah terkirim/gagal; sisanya hanya `Log::warning` yang tidak terstruktur. | `PushNotificationService` | Tidak bisa menjawab "apakah pengingat X sampai ke siapa?" |
| N5 | **Tidak ada pengatur untuk penerima.** Tidak ada jam tenang untuk pelanggan, tidak ada pilihan per kategori, dan staf tidak punya pengaturan sama sekali. Pengingat terjadwal berjalan pada jam tetap tanpa memeriksa preferensi. | `SendNotification`, perintah terjadwal | Risiko mengganggu dan dimatikan pengguna lewat pengaturan sistem (tidak bisa dipulihkan dari sisi kami) |
| N6 | **Menu Kirim Notifikasi tanpa pratinjau dan jadwal.** Penerima baru terlihat setelah kirim (mis. "N dilewati karena belum menyetujui promo"); tidak ada kirim terjadwal, tidak ada templat tersimpan, tidak ada hasil per kampanye sesudahnya. | `SendNotification` | Kesalahan sasaran baru ketahuan setelah terlanjur terkirim |
| N7 | **Isi pesan tersebar sebagai teks di kode.** Judul/isi dan penerima ditulis langsung di tiap pemanggil; tidak ada daftar tunggal "jenis notifikasi", jadi tidak ada tempat memeriksa siapa menerima apa. | pemanggil terpencar | Sulit mengaudit dan mengubah isi atau penerima |
| N8 | **Pengiriman push sinkron dalam permintaan.** Pengiriman ke banyak token dan jeda antar-batch berjalan di dalam permintaan atau perintah. *(Perlu diukur di server sebelum diputuskan antrean diperlukan.)* | `PushNotificationService` | Broadcast besar memperlambat permintaan; tanpa antrean tidak ada percobaan ulang bila server berhenti di tengah |
| N9 | **WhatsApp bergantung kredensial dan dilewati diam-diam.** Tidak ada penanda bahwa pengingat WhatsApp tidak terkirim karena belum dikonfigurasi. | `WhatsAppService::isConfigured` | Pemilik bisa mengira pengingat berjalan padahal tidak |

### 3.3 Yang sudah baik (dipertahankan)
- Pembagian batch, percobaan ulang bila token asing, dan penghapusan token `DeviceNotRegistered` pada tahap tiket.
- Kotak masuk pelanggan dan mitra dengan status baca dan retensi.
- Izin promo yang dicek saat kirim, dengan batas mingguan, dan pemisahan `promo` dari `info`.
- Penyaringan penerima staf menurut akses menu (`hasMenuAccess`) sehingga staf tidak kebanjiran notifikasi yang bukan menunya.
- Laporan error pengiriman ke Sentry.

## 4. Rancangan target

### 4.1 Prinsip
1. **Satu jalur kirim** untuk semua notifikasi staf: sekali panggil menghasilkan push dan baris kotak masuk.
2. **Kotak masuk sebagai sumber kebenaran**; push hanya pengingat. Pesan yang terlewat tetap ada.
3. **Kategori menentukan perilaku**: penting (selalu sampai, mengabaikan jam tenang), operasional (jam kerja), promosi (izin, jam tenang, batas frekuensi).
4. **Setiap pengiriman tercatat dan dapat ditelusuri**, termasuk hasil kuitansi.
5. **Isi dan penerima punya satu daftar** (katalog) yang bisa diperiksa.

### 4.2 Model data (usulan)
- Memakai tabel `notifications` Laravel yang sudah ada untuk kotak masuk staf (panel dan mobile membaca yang sama).
- `notification_deliveries`: `channel` (push, whatsapp), `notifiable_type/id` atau `user_id/customer_id`, `category`, `type_key`, `token_id`, `ticket_id`, `status` (diterima, terkirim, gagal, token_mati), `error`, `sent_at`, `checked_at`. Dibersihkan menurut retensi.
- `notification_types` (katalog sebagai kode, bukan tabel): kunci, label, kategori, penerima, jalur. Mulai sebagai kelas konstanta yang bisa diaudit.
- Preferensi (hanya bila diputuskan): `notification_preferences` per pelanggan/pengguna untuk jam tenang dan kategori.

### 4.3 Alur target
1. Pemanggil menyebut **jenis notifikasi** dan penerima; `NotificationDispatcher` memeriksa kategori, preferensi, jam tenang, dan kunci anti-ganda.
2. Dispatcher membuat baris kotak masuk, lalu mengirim push; hasil tiket dicatat di `notification_deliveries`.
3. Pekerjaan terjadwal ±15 menit kemudian memeriksa kuitansi dan menandai token mati.
4. Aplikasi mobile menampilkan kotak masuk dengan badge belum dibaca; menekan notifikasi membuka layar tujuan (deep link yang sudah ada).
5. Admin melihat Log Notifikasi (hasil per jenis/kampanye) dan angka kegagalan.

### 4.4 Fitur dan menu
- **Kirim Notifikasi**: pratinjau jumlah penerima sebelum kirim (termasuk yang dilewati dan alasannya), kirim terjadwal, templat tersimpan, hasil per kampanye.
- **Riwayat** (pelanggan dan mitra) digabung dengan **Log Notifikasi** berfilter jenis, hasil, dan tanggal.
- **Katalog Notifikasi** (baca-saja): daftar jenis, siapa menerima, kapan dikirim, kategori.
- **Mobile**: kotak masuk staf, badge, pengaturan (bila diputuskan). Pelanggan: pilihan jam tenang/kategori bila diputuskan.

### 4.5 Aturan UI/UX
Judul singkat yang menyebut tindakan; isi tanpa jumlah uang untuk kategori sensitif (diputuskan per jenis); satu notifikasi satu tujuan layar; kotak masuk berurut waktu dengan pengelompokan kategori; status baca konsisten antar panel dan mobile.

### 4.6 Keandalan dan keamanan
Anti-ganda per jenis dan per sasaran; pengiriman lewat antrean (setelah diukur, N8) dengan percobaan ulang; token mati dibersihkan otomatis; kegagalan beruntun dilaporkan; tes otomatis untuk kategori, jam tenang, dan anti-ganda.

### 4.7 Ukuran keberhasilan
- 100% notifikasi staf punya baris kotak masuk.
- Persentase kuitansi terkirim terlihat per hari; token mati dibersihkan dalam 24 jam.
- Nol notifikasi promosi di luar jam tenang atau di atas batas frekuensi.
- Pemilik dapat menjawab "apakah notifikasi X sampai ke Y" dari Log Notifikasi.

## 5. Peta jalan

| Fase | Isi | Hasil |
|---|---|---|
| **0. Katalog dan kebersihan** | **Dikerjakan (2026-10-21), belum diuji di server.** Kelas NotificationCatalog (86 entri dari 49 berkas pengirim, dihasilkan dari pemindaian kode lalu dirapikan; judul dinamis diberi nama deskriptif); halaman baca-saja Katalog Notifikasi (ringkasan dan pencarian, menandai pengirim staf yang hanya push); tes pengaman NotificationCatalogTest yang gagal bila ada berkas pengirim push baru yang belum didaftarkan atau entri yang tidak lagi bermakna. Kolom lonceng panel berlaku per berkas, bukan per pesan. Tidak ada perubahan perilaku atau skema. Semula: Daftar semua jenis notifikasi yang ada (kode), halaman Katalog baca-saja, temuan yang bisa dirapikan tanpa skema | Satu daftar yang bisa diaudit |
| **1. Dispatcher untuk staf** | **Sebagian dikerjakan (2026-10-21), belum diuji di server.** Kelas StaffNotifier (toUsers, toStoreStaff, toBookingWatchers): setiap pesan = satu baris kotak masuk di tabel notifications (format Filament, muatan push disimpan di viewData untuk aplikasi mobile) + push; kegagalan satu sisi tidak menggagalkan sisi lain; kunci penggabungan (coalesce) untuk pesan berfrekuensi tinggi seperti chat. **Kelompok 1 dimigrasikan:** keputusan yang menyangkut satu karyawan (izin disetujui/ditolak, permohonan pembelian disetujui/ditolak/terpenuhi, gaji dibayar/pengajuan ditolak, akun teknisi diaktifkan/ditolak, surat peringatan, perpanjangan kontrak) = 11 titik. **Tahap 2 (2026-10-21):** booking dan SPK (booking baru, dibatalkan, jadwal berubah, penugasan, SPK baru/selesai, pengajuan pembatalan/jadwal ulang dari pelanggan, pesan pelanggan di booking dengan penggabungan per booking), penawaran/lead baru, invoice, pengingat booking harian/menunggu, absensi (belum absen masuk, lupa absen pulang), dan jadwal kerja/shift = 27 titik lagi. **Belum:** titik yang sudah punya notifikasi lonceng terpisah (persetujuan keuangan/transaksi, tagihan, lead telat, kontrak) dan pengirim ke pelanggan (kotak masuk pelanggan sudah ada); keputusan pemilik: bertahap per kelompok. Semula: Satu jalur kirim (push + kotak masuk panel) dan anti-ganda; migrasi bertahap titik push-saja | Tidak ada push tanpa jejak |
| **2. Kotak masuk staf di mobile** | **Dikerjakan (2026-10-21); API belum diuji di server, layar mobile belum dicoba di HP dan tidak di-commit.** API GET /api/staff/inbox (terbaru dulu, filter belum dibaca, 30 per halaman), GET unread-count, POST {id}/read dan read-all; setiap pengguna hanya melihat miliknya (milik orang lain 404); mencakup notifikasi StaffNotifier dan notifikasi lonceng panel lama. Layar mobile app/staff/inbox.tsx (daftar, tarik untuk muat ulang, muat lanjutan, tandai semua, rute tujuan divalidasi dengan daftar rute yang diizinkan) dan kotak Kotak Masuk di beranda staf dengan jumlah belum dibaca. Isi kotak masuk baru memuat titik yang sudah dimigrasikan ke StaffNotifier (Fase 1 tahap 1). Semula: Endpoint dan layar dari tabel notifikasi, badge, tandai baca | Pesan terlewat tetap ada |
| **3. Pengiriman terlacak** | **Dikerjakan (2026-10-21), belum diuji di server.** Tabel `notification_deliveries` (satu baris per token yang dikirimi push: pemilik, judul, nomor tiket, status accepted/delivered/failed/dead_token/unknown, keterangan; tanpa foreign key supaya log bertahan). `PushNotificationService::pushToTokens` mencatat hasil tahap tiket (juga kegagalan HTTP dan pengecualian) tanpa mengubah perilaku lama; kegagalan mencatat tidak pernah menggagalkan pengiriman. Perintah `notifications:check-receipts` tiap 15 menit memeriksa kuitansi Expo (lebih dari 15 menit sejak kirim): ok = terkirim, `DeviceNotRegistered` = token dihapus, kuitansi tak muncul dalam 23 jam = tidak diketahui. Halaman baca-saja **Log Pengiriman** di menu Notifikasi (filter hasil dan tanggal, ringkasan 24 jam dengan persentase terkirim; hak akses menu 'Log Pengiriman'). Retensi 90 hari lewat `notifications:prune-old`. Tes `NotificationDeliveryTest`. Catatan: log baru mencatat tingkat token, belum "jenis notifikasi"; kolom jenis menunggu dispatcher penuh. | "Terkirim" berarti terkirim |
| **4. Preferensi dan batas** | **Dikerjakan (2026-10-21), belum diuji di server.** Jam tenang promosi pelanggan 21:00 sampai 08:00 WIB (keputusan 4): `App\Support\QuietHours` + `config/marketing.php` (`promo_quiet_hours`, bisa diatur lewat env PROMO_QUIET_HOURS_*; rentang melewati tengah malam didukung, zona waktu WIB tidak bergantung zona server). Kiriman promosi pada jam tenang ditolak (422) dengan pesan yang menyebut jamnya dan tidak mengirim apa pun; informasi penting dan notifikasi booking/transaksi tidak terpengaruh. Teks bantuan di form Kirim Notifikasi menyebut jam tenang. Batas frekuensi: promosi sudah dibatasi per 7 hari sejak Marketing Fase 5, jadi ukuran keberhasilan "nol promosi di luar jam tenang atau di atas batas" terpenuhi tanpa tabel baru. **Tidak dikerjakan:** pilihan kategori oleh staf/pelanggan dan tabel `notification_preferences` (keputusan 5 belum dijawab; rekomendasi: tidak, kecuali ada keluhan terlalu banyak notifikasi). Tes `QuietHoursTest`; `PromoConsentTest` mematikan jam tenang supaya tidak bergantung jam jalan tes. Penjadwalan ulang otomatis ke pagi menunggu kirim terjadwal (Fase 5). | Penerima terlindungi |
| **5. Menu Kirim Notifikasi** | Pratinjau penerima, jadwal kirim, templat, hasil kampanye, Log Notifikasi | Kesalahan sasaran terlihat sebelum kirim |
| **6. WhatsApp** | Hanya bila diputuskan: templat utility untuk pengingat booking, penanda bila tidak terkonfigurasi | Pengingat tidak diam-diam gagal |

### 5.1 Kenapa bertahap
Notifikasi menyentuh hampir semua modul. Migrasi titik pengiriman dilakukan bertahap dengan perilaku lama dijaga (pesan dan penerima tidak berubah) dan bendera fitur untuk jalur baru, tes per fase, suite penuh sebelum rilis, dan rencana rilis tertulis.

## 6. Keputusan yang dibutuhkan (dengan rekomendasi)

| # | Pertanyaan | Mengapa penting | Rekomendasi saya |
|---|---|---|---|
| 1 | Aplikasi mobile staf punya kotak masuk notifikasi? | Dasar fase 2 | Ya |
| 2 | Semua notifikasi staf juga masuk kotak masuk (bukan push saja)? | Dasar fase 1 | Ya |
| 3 | Periksa kuitansi Expo dan bersihkan token mati otomatis? | Dasar fase 3 | Ya |
| 4 | Jam tenang untuk promosi pelanggan? | Dasar fase 4 | Ya, 21:00 sampai 08:00 WIB; notifikasi transaksi (booking) tidak terpengaruh |
| 5 | Pilihan kategori notifikasi oleh pelanggan/staf? | Dasar fase 4 | Pelanggan: promo saja (sudah ada); staf: belum, kecuali ada keluhan terlalu banyak notifikasi |
| 6 | Kirim terjadwal dan templat kampanye di Kirim Notifikasi? | Dasar fase 5 | Ya, kecil |
| 7 | WhatsApp untuk pengingat booking pelanggan? | Dasar fase 6, ada biaya per templat | Tunda; push dan kotak masuk dulu |
| 8 | Retensi notifikasi (sekarang 180 hari)? | Ukuran data | Pertahankan |

## 7. Batasan riset

- Sumber desain notifikasi adalah blog/panduan vendor; angka statistik tidak dipakai.
- Aturan persetujuan resmi WhatsApp tidak terverifikasi di sini.
- Audit kode terhadap `development` per 2026-10-21; perilaku di perangkat nyata (push sampai atau tidak) belum diuji.
- Beban pengiriman (N8) belum diukur di server.

## 8. Sumber
- [Expo: Send notifications with the Expo Push Service](https://docs.expo.dev/push-notifications/sending-notifications)
- [Forum Expo: Push Notification Receipt Errors](https://forums.expo.dev/t/push-notification-receipt-errors-how-to-handle-them-correctly/38928)
- [Courier: Best practices for notification centers](https://www.courier.com/guides/how-to-build-a-notification-center/chapter-3-best-practices-for-notification-centers)
- [Produktly: How to design in-app notification systems](https://produktly.com/guides/how-to-design-notification-systems)
- [Plotline: Frequency capping in in-app messaging](https://www.plotline.so/blog/frequency-capping-in-app-messaging/)
- [Meta: Template categorization guidelines](https://developers.facebook.com/docs/whatsapp/updates-to-pricing/new-template-guidelines/)
- [Courier: WhatsApp message types](https://www.courier.com/guides/whatsapp-message-types)
