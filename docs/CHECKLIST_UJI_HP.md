# Daftar Centang Uji Aplikasi Mobile (HP)

Dibuat 2026-10-13, setelah tiga lapisan lain selesai: tes otomatis server (2.153 tes), sapuan semua halaman panel admin
(`php artisan smoke:panel`), dan audit kontrak aplikasi ↔ API. Daftar ini mencakup **semua layar aplikasi**
(`ginnva-mobile/app/`, ±73 layar). Tanda ⚠ = bagian rawan (kamera, unggah berkas, tanggal/jam, izin perangkat, notifikasi),
yang paling mungkin lolos dari tes otomatis. Kerjakan yang ⚠ lebih dulu.

## Persiapan

- [ ] Pakai build aplikasi yang menunjuk ke **server dev** (`ginnva-api-dev`), BUKAN production: banyak langkah mengubah data.
- [ ] Data demo ada di dev: `php artisan demo:presentasi` (akun demo tidak bisa login, jadi siapkan akun uji di bawah).
- [ ] Akun uji (buat lewat panel admin dev, Pengguna): **installer**, **installer_leader**, **store_manager**, **direksi** (atau super_admin),
      **partner** (Partner), dan satu **akun pelanggan** (login OTP lewat email yang bisa Anda buka).
- [ ] Satu HP Android (utama) dan, kalau ada, satu iPhone. Izin kamera, lokasi, dan notifikasi diizinkan.
- [ ] Cara mencatat: ✅ lolos, ❌ gagal (tulis pesan error persis + screenshot), ➖ tidak relevan. Kirim yang ❌ ke saya.

---

## Sebelum uji HP: sapuan otomatis dari command (server dev saja)

Dua perintah menjalankan sebagian besar kontrak server yang dipakai aplikasi, jadi checklist HP bisa fokus pada hal yang butuh manusia (kamera, push sungguhan, tampilan):

- `php artisan smoke:api` membuka semua endpoint BACA sebagai pelanggan, staf, dan partner pada data dev.
- `php artisan smoke:flow` menjalankan alur booking lengkap lewat HTTP: pelanggan memesan (persetujuan privasi, kunci idempotensi), Store Manager mengonfirmasi, foto penerimaan, QC, serah terima, selesai, tandai tidak datang, pengingat, dan laporan SLA. Membuat data bertanda `[SMOKE]` dan membersihkannya; `--keep` mempertahankannya. Catatan: saat booking uji dibuat, staf toko dev yang berhak ikut menerima notifikasi/email "booking baru".

## A. Pelanggan (login lewat OTP email)

### A1. Tanpa login
- [ ] Beranda (tab Beranda): banner, produk unggulan, promo tampil; tidak ada error.
- [ ] Produk → daftar kategori → detail produk (`products/[category]`); gambar muncul.
- [ ] Seri Produk → daftar → lihat seri (`seri-produk/view`).
- [ ] Toko (tab Toko): daftar toko, alamat, tombol peta/WhatsApp berfungsi (nomor tidak salah format).
- [ ] Berita → daftar → baca satu berita (`news/[slug]`).
- [ ] Studi Kasus (`case`) dan Brand (`brand`) terbuka.
- [ ] **Cek Garansi** (`warranty/check`): masukkan kode garansi valid → detail muncul; kode salah → pesan jelas.
- [ ] **Minta Penawaran** (`quotation`): pilih kendaraan, produk, toko; isi nama + telepon (email boleh kosong) → terkirim.
- [ ] Inquiry Produk (`inquiry`) dan Kemitraan (`partnership`): kirim form → pesan sukses.
- [ ] Asisten AI (`assistant/ai`) dan tab Chat: kirim pertanyaan → jawaban datang.

### A2. Masuk & profil
- [ ] Login: email → OTP dikirim → verifikasi kode (`auth/login`, `auth/verify`).
- [ ] Kode OTP salah → pesan jelas; "Kirim ulang" berfungsi.
- [ ] Pelanggan baru: Lengkapi Profil (`auth/complete-profile`): kolom telepon hanya menerima angka; kode referral opsional.
- [ ] Edit Profil (`account/edit-profile`): ubah nama/telepon/gender/alamat → tersimpan. Telepon dengan huruf/tanda → ditolak/dibersihkan.
- [ ] ⚠ Buka tautan referral (`r/[code]`) → profil tersimpan, kode terpakai.
- [ ] Logout lalu login lagi → data tetap ada.

### A3. Booking
- [ ] ⚠ Booking baru (`booking/index`): pilih toko → layanan (Kaca Film / PPF / keduanya / Lainnya) → **tanggal** → jam → catatan → kirim.
      Tanggal penuh/libur tidak bisa dipilih; ada penanda "hampir penuh"; "Lainnya" wajib catatan.
- [ ] Booking Saya (`account/my-bookings`): tab Aktif dan Riwayat, muat halaman berikutnya (scroll), badge pesan baru.
- [ ] ⚠ Batalkan booking berstatus Menunggu (alasan opsional).
- [ ] ⚠ Ajukan pembatalan booking yang sudah Dikonfirmasi (alasan wajib) → status "menunggu keputusan toko".
- [ ] ⚠ Ajukan pindah jadwal (pilih tanggal baru, alasan opsional) → permintaan terkirim; tanggal penuh ditolak.
- [ ] Chat booking (`booking/[id]/chat`): kirim teks; pesan staf (termasuk foto tahap) tampil; foto bisa dibuka.
- [ ] Review Toko setelah booking selesai: pilih sentimen + tag + komentar → terkirim; tidak bisa ganda.

### A4. Garansi & maintenance
- [ ] Garansi Saya (`account/my-warranties`) dan Detail (`warranty-detail`): kode, masa berlaku, sisa kuota maintenance.
- [ ] ⚠ Konfirmasi jadwal maintenance; Tolak jadwal; **pilih tanggal baru** (setelah toleransi) → tanggal tersimpan benar.
- [ ] ⚠ Klaim garansi (`warranty-claim`): kategori, deskripsi, tanggal pilihan (tidak boleh kemarin) → terkirim.

### A5. Poin, reward, voucher, tagihan
- [ ] Poin (`account/points`): saldo + riwayat, muat berikutnya.
- [ ] Reward (`rewards`): daftar; tukar reward → saldo berkurang; ditolak jika poin kurang (pesan jelas).
- [ ] Riwayat Penukaran (`reward-redemptions`) dan Voucher Saya (`vouchers`): voucher dari reward tampil, kode `GNV-…`, masa berlaku.
- [ ] Promo (`account/promo`) terbuka.
- [ ] Invoice (`account/invoices` → detail) tampil dan bisa dibuka.
- [ ] Galeri Saya (`my-gallery`): foto tampil.
- [ ] Notifikasi (`account/notifications`): daftar, tandai dibaca, tandai semua dibaca.
- [ ] Hapus Akun (di Edit Profil): hanya uji dengan akun uji sekali pakai di akhir.

---

## B. Staf (login email + kata sandi)

### B1. Semua role staf
- [ ] Login staf; kata sandi salah → pesan jelas.
- [ ] Lupa Sandi (`staff-forgot-password`): kode ke email → atur sandi baru (aturan: ≥8, ada huruf besar, kecil, angka).
- [ ] Ganti Sandi (`staff/change-password`).
- [ ] Beranda staf (`staff/index`): hanya menu yang sesuai role/akses; tidak ada menu yang seharusnya tertutup.
- [ ] Notifikasi push diterima (buat booking baru / pesan chat) dan ketukan membuka layar yang benar.
- [ ] Logout.

### B2. Absensi (semua role)
- [ ] ⚠ **Absen Masuk** (`staff/attendance`): izin lokasi → selfie kamera depan → berhasil. Di luar radius toko → ditolak dengan jarak.
- [ ] ⚠ Absen Keluar dengan foto; absen masuk dua kali di hari sama → ditolak.
- [ ] ⚠ GPS palsu (aplikasi mock location) → ditolak "GPS palsu".
- [ ] Riwayat bulan berjalan dan ganti bulan; total menit telat benar.
- [ ] ⚠ **Koreksi Absen** (`attendance/correction`): pilih tanggal (cek tanggalnya benar bila diuji pukul 00.00–07.00 WIB), jam format 24 jam `08:30`.
- [ ] ⚠ **Izin/Cuti** (`attendance/leave`): tanpa lampiran, dengan lampiran foto/PDF; batalkan pengajuan; tanggal "hari ini" diterima.
- [ ] Surat Peringatan (`warning-letters`): daftar dan tombol "Saya sudah membaca".
- [ ] Jadwal Kerja (`staff/schedule`): minggu ini, geser minggu; hari libur terlihat.
- [ ] Gaji (`staff/payroll`): slip bulan berjalan tampil (hanya milik sendiri).

### B3. Booking & SPK (installer, leader, store_manager)
- [ ] Daftar Booking (`staff/bookings`): ringkasan kapasitas 14 hari tampil.
- [ ] ⚠ Detail Booking (`bookings/[id]`): chat grup dengan customer; **kirim teks**, **kirim foto dari galeri (banyak)**, **kirim foto dari kamera**.
- [ ] ⚠ Tandai tahap pengerjaan + susulkan foto tahap (maksimal 10 foto) → muncul di chat customer.
- [ ] Konfirmasi booking (durasi hari, pratinjau kapasitas); tolak/batalkan (alasan wajib untuk booking terkonfirmasi).
- [ ] Pindah jadwal oleh staf; setujui/tolak permintaan pindah jadwal customer; setujui/tolak pembatalan customer (catatan wajib saat menolak).
- [ ] Koreksi tahap (alasan wajib); Selesaikan booking; kirim pengingat maintenance.
- [ ] Penugasan installer dan pemantau (direksi) → daftar tersimpan; installer **tidak** bisa membuat SPK, leader dan atasannya bisa.
- [ ] Installer yang sudah ditugaskan **tidak** ada di chat booking; leader installer ada.
- [ ] SPK (`staff/spks`): daftar; Buat SPK dari booking terkonfirmasi (tombol hanya untuk leader/atasan).
- [ ] ⚠ SPK detail: isi data kendaraan (KM angka bulat), centang checklist, simpan; **Kondisi Kendaraan** (`spks/damage`): tap titik kerusakan, pilih kode, simpan.
- [ ] SPK: "Kendaraan Keluar" (checkout) → tersimpan; selesai SPK bergantung catat pemakaian roll bila produk berbatch.
- [ ] Quotation/Lead (`staff/quotations`): daftar, filter status, buka detail, ubah status.

### B4. Inventaris & stok (sesuai hak akses role)
- [ ] ⚠ Scan QR/barcode barang (`inventory/scan`, `assets/scan`): izin kamera; kode tidak dikenal → pesan jelas; cari manual.
- [ ] Detail barang inventaris (`inventory/[code]`): riwayat mutasi (muat berikutnya), mutasi masuk/keluar (toko tujuan), **catat pemakaian meter** (meter desimal, booking opsional), tandai kode roll terpakai.
- [ ] Aset (`assets/[code]`): ubah status/toko/catatan → tersimpan.
- [ ] Bahan Baku (`materials`, `materials/[id]`): cari, mutasi masuk/keluar (tanggal terima ≤ hari ini, kedaluwarsa), penyesuaian stok.
- [ ] Barang Habis Pakai (`consumables`): cari, mutasi, penyesuaian.
- [ ] Memo Barang (`memos`): buat memo (store_manager/direksi: pilih toko); tambah item (bahan baku/habis pakai/inventaris), ubah qty, kembalikan item, hapus item, ubah memo, hapus memo.
- [ ] Permintaan Pembelian (`purchase-requests`): buat untuk bahan baku/habis pakai/aset baru; daftar status.

### B5. Pembeda per role (uji akun masing-masing)
- [ ] **installer:** hanya booking yang ditugaskan; tanpa tombol Buat SPK; tanpa menu yang bukan haknya.
- [ ] **installer_leader:** bisa Buat SPK; ada di chat booking; tidak melihat menu keuangan.
- [ ] **store_manager:** hanya data tokonya sendiri; bisa menugaskan installer.
- [ ] **direksi/super_admin:** semua toko; Memo wajib pilih toko.

---

## C. Partner (login email + kata sandi)

- [ ] Login partner → Dashboard (`partner/dashboard`): kode referral, saldo poin.
- [ ] Referral (`partner/referrals`), Poin (`partner/points`), Penukaran (`partner/redemptions`): daftar dan muat berikutnya.
- [ ] Edit Profil Partner (`partner/edit-profile`): nama usaha, telepon (unik); ganti sandi.
- [ ] Notifikasi Partner: daftar, tandai dibaca, tandai semua.

---

## D. Lintas fitur

- [ ] ⚠ **Jaringan lemah/mati:** matikan data saat mengirim foto absen atau chat → pesan error jelas, teks yang diketik tidak hilang; tidak ada duplikat saat diulang.
- [ ] ⚠ **Izin ditolak:** tolak izin kamera, lokasi, dan notifikasi → aplikasi menjelaskan dan menawarkan buka Pengaturan, tidak crash.
- [ ] Tema gelap dan terang: teks terbaca, tidak ada kontras buruk, ukuran huruf nyaman.
- [ ] Rotasi/keyboard: kolom isian tidak tertutup keyboard di form panjang (SPK, Izin, Booking).
- [ ] Sesi habis (token kedaluwarsa): diarahkan ke login dengan pesan, bukan layar kosong.
- [ ] Buka aplikasi dari notifikasi saat aplikasi tertutup dan saat berjalan.
- [ ] ⚠ **Zona waktu:** uji sekali pada pukul 00.00–06.59 WIB (koreksi absen, izin "hari ini", jadwal minggu ini) setelah build dengan perbaikan tanggal dirilis.

---

## Catatan hasil

| Layar / langkah | Hasil | Pesan error / catatan |
|---|---|---|
| | | |

---

## E. Layar baru Fase 1 booking (uji setelah API Fase 1 aktif di server yang dipakai build)

Prasyarat: API Fase 1 sudah dimigrasi di server yang dituju; harga Detailing dan Premium Wash sudah diisi di panel admin.

- [ ] **Kendaraan Saya** (Akun → Kendaraan Saya): kosong → tombol Tambah Kendaraan; tambah dari katalog (pilih merek → model), ukuran mobil tampil otomatis; tambah manual ("Mobil saya tidak ada di daftar") dengan ukuran opsional.
- [ ] ⚠ Plat nomor: ketik huruf kecil dan tanda baca (`b 1234-abc`) → tampil `B 1234 ABC`; plat tidak valid (`ABC`) ditolak dengan pesan; plat yang sama dua kali ditolak.
- [ ] Ubah kendaraan (warna, tahun, jadikan utama), hapus kendaraan (kendaraan utama berpindah), batas 10 kendaraan.
- [ ] ⚠ **Booking 5 langkah** (Toko → Kendaraan → Layanan → Jadwal → Ringkasan): dari tab Toko (toko terpilih) langsung ke langkah Kendaraan; kendaraan utama terpilih otomatis; tombol Lanjut mati sampai langkah terisi.
- [ ] Langkah Layanan: empat layanan tampil dengan deskripsi dan lama pengerjaan, **tanpa harga**; pilih lebih dari satu; "Lainnya" menghapus pilihan lain dan mewajibkan catatan.
- [ ] Ringkasan: toko, kendaraan, layanan, tanggal, jam, dan perkiraan lama tampil benar (tanpa harga); kirim → booking masuk dengan plat di Booking Saya.
- [ ] ⚠ Matikan data saat menekan Kirim Booking lalu nyalakan dan tekan lagi → hanya satu booking terbentuk (kunci idempotensi).
- [ ] Staf: daftar booking menampilkan plat dan merek/model; detail booking menampilkan bar kendaraan; installer melihat plat, kendaraan, warna, dan ukuran.
- [ ] Panel admin: kolom Plat di daftar booking bisa dicari; detail booking punya bagian Kendaraan.
- [ ] Layar "Perbarui Aplikasi": hanya muncul bila server mengirim 426 (uji dengan `APP_MIN_VERSION_ANDROID` lebih tinggi dari versi build di dev).

## F. Jadwal layanan ringan Fase 2 (uji setelah migrasi `2026_10_16_000001` dan `000002` aktif)

Prasyarat: kapasitas layanan ringan per jam toko sudah diatur (form Toko, "Kapasitas Layanan Ringan / Jam"; kosong = 2).

- [ ] ⚠ Booking **hanya Detailing atau Premium Wash**: di langkah Jadwal, label berubah menjadi "Jam Kedatangan (wajib)"; sebelum tanggal dipilih tampil petunjuk, sesudahnya daftar jam muncul; tombol Lanjut mati sampai jam dipilih.
- [ ] ⚠ Konfirmasikan satu booking ringan di panel/app staf pada tanggal dan jam tertentu sampai kapasitas jam itu habis → di app pelanggan jam itu tampil redup "(penuh)" dan tidak bisa dipilih; jam lain masih bisa.
- [ ] Semua jam satu tanggal penuh → tanggal itu nonaktif di pemilih tanggal untuk layanan ringan, tetapi tetap bisa dipilih untuk PPF/kaca film.
- [ ] ⚠ Booking PPF atau kaca film yang mengisi kapasitas harian **tidak** menutup jam layanan ringan di tanggal yang sama (dan sebaliknya).
- [ ] Booking **campuran** (PPF + Detailing): jam tetap opsional dan tanggal mengikuti kapasitas harian layanan berat.
- [ ] Matikan data saat memuat jam → muncul pesan "Jam gagal dimuat. Ketuk untuk coba lagi."; ketuk setelah data menyala → jam muncul.
- [ ] Ganti tanggal setelah memilih jam yang di tanggal baru penuh → pilihan jam otomatis dikosongkan.
- [ ] Panel admin: Buat/Ubah Booking layanan ringan pada jam yang penuh ditolak dengan notifikasi "Jam layanan ringan penuh".
- [ ] Panel admin: menu Operasional → Layanan Booking; ubah kategori sebuah layanan lalu cek perilaku kapasitasnya berubah (hanya akun akses penuh yang bisa mengubah).
- [ ] ⚠ **PPF 3 hari**: pilih toko yang hari ke-2 atau ke-3 setelah sebuah tanggal sudah penuh → tanggal mulai itu nonaktif di pemilih tanggal (bukan hanya tanggal yang penuh sendiri).
- [ ] Staf: konfirmasi booking PPF saat installer yang masuk kerja lebih sedikit dari booking berat aktif → konfirmasi tetap berhasil dan respons memuat peringatan (cek lewat panel/aplikasi staf yang sudah menampilkannya).

## G. Quality Check Fase 3A (uji setelah migrasi `2026_10_17_000001` aktif)

Prasyarat: ada booking terkonfirmasi yang semua tahap produknya sudah selesai (atau tahap Quality Check sudah ditandai); satu akun Store Manager atau Leader yang BUKAN penugasan installer booking itu.

- [ ] ⚠ Detail booking (staf, terkonfirmasi) punya ikon **Quality Check**; ketuk → daftar periksa tampil per layanan yang dipesan.
- [ ] ⚠ Tandai satu butir **Tidak lulus** tanpa catatan → tombol Kirim tetap mati dan tertulis berapa butir butuh catatan; isi catatan → aktif.
- [ ] Kirim dengan satu butir tidak lulus → pesan "Belum lulus"; pelanggan TIDAK mendapat notifikasi; buka lagi → hasil terakhir menampilkan butir yang gagal dan catatannya.
- [ ] Periksa ulang, semua lulus → pesan "Lulus"; pelanggan menerima notifikasi "Kendaraan Siap Diambil"; di Booking Saya pelanggan label booking menjadi **Siap Diambil**.
- [ ] ⚠ Login sebagai installer yang mengerjakan unit itu (atau leader yang ditugaskan di booking itu) → layar hanya menampilkan hasil dan alasan "tidak bisa memeriksa sendiri", tanpa tombol kirim.
- [ ] Installer biasa tidak melihat tombol Quality Check (layar installer tidak punya aksi booking).
- [ ] Booking layanan ringan saja (Detailing/Premium Wash) bisa diperiksa tanpa tahap kerja.
- [ ] Panel admin: Operasional → Daftar Periksa QC; tambah/ubah/nonaktifkan butir; akun non-akses-penuh hanya bisa melihat. Setelah butir diubah, pemeriksaan berikutnya memakai daftar baru sedangkan hasil lama tetap menampilkan butir lamanya.
- [ ] Setelah `BOOKING_QC_REQUIRED=true`: "Selesaikan Booking" ditolak bila QC terbaru belum lulus, dan berhasil setelah lulus.

## H. Serah terima Fase 3B (uji setelah migrasi `2026_10_18_000001` aktif)

- [ ] ⚠ Detail booking (staf, terkonfirmasi) punya ikon **kunci** (Serah Terima Unit); installer tidak melihatnya.
- [ ] ⚠ Booking **PPF atau kaca film**: tombol Catat Serah Terima mati sampai petunjuk perawatan dan tanda tangan basah dicentang; teks bantuan di bawah menjelaskan yang kurang.
- [ ] Booking **Detailing/Premium Wash**: cukup centang petunjuk perawatan; tanda tangan basah opsional.
- [ ] Ambil foto akhir dari kamera → pratinjau tampil; simpan → foto tersimpan; buka lagi layar yang sama → ringkasan serah terima (oleh siapa, kapan, foto) tampil dan tidak ada tombol catat lagi.
- [ ] Catat serah terima untuk booking yang sama dua kali (dari dua HP) → yang kedua ditolak dengan pesan.
- [ ] Setelah `BOOKING_QC_REQUIRED=true`: serah terima ditolak sebelum QC lulus.
- [ ] Setelah `BOOKING_HANDOVER_REQUIRED=true`: "Selesaikan Booking" ditolak sebelum serah terima dicatat, berhasil sesudahnya.

## I. Foto penerimaan dan tidak datang, Fase 3C (uji setelah migrasi `2026_10_19_000001` aktif)

- [ ] ⚠ Detail booking (staf, terkonfirmasi) punya ikon **kamera** (Foto Penerimaan); installer tidak melihatnya.
- [ ] Ketuk kotak Depan → kamera terbuka → foto tersimpan dan kotak menampilkan fotonya; ulangi untuk Belakang, Kiri, Kanan → teks berubah menjadi "Keempat sudut sudah difoto".
- [ ] Foto ulang di sudut yang sama → foto lama diganti, bukan bertambah.
- [ ] ⚠ Foto kerusakan: tombol Foto kerusakan mati sampai keterangan diisi; setelah foto, muncul di daftar dengan keterangannya; hapus foto kerusakan berhasil, foto sudut baku tidak punya tombol hapus.
- [ ] Izin kamera ditolak → muncul penjelasan, aplikasi tidak error.
- [ ] ⚠ Tombol **Tandai Tidak Datang** (ikon orang dicoret) hanya muncul untuk Store Manager/akses penuh pada booking confirmed yang tanggalnya hari ini atau lewat dan belum ada tahap pengerjaan; tidak muncul untuk tanggal besok.
- [ ] Tandai Tidak Datang → konfirmasi → status booking menjadi Dibatalkan, alasan "Pelanggan tidak datang (no-show)", slot tanggal itu kembali kosong di kalender kapasitas, DP (bila ada) dikembalikan penuh.

## J. Komunikasi Fase 4 (uji setelah migrasi `2026_10_20_000001` aktif)

- [ ] ⚠ Ringkasan booking (langkah terakhir) menampilkan blok **Kebijakan Pembatalan** dengan teks dari admin; ubah teks di panel (Operasional → Teks Aplikasi), tunggu sampai 5 menit (cache), buka ulang → teks baru tampil tanpa rilis aplikasi.
- [ ] ⚠ Booking terkonfirmasi untuk lusa → keesokan pagi (08:00) pelanggan menerima push **"Pengingat Jadwal 2 Hari Lagi"** yang menyebut layanan, toko, tanggal, jam; ketuk → terbuka Booking Saya dengan tombol ubah jadwal/batal.
- [ ] Besok pagi → push H-1 (sore 16:00 juga bila belum terkirim); hari-H pagi → push "Jadwal Anda Hari Ini". Masing-masing hanya sekali per booking.
- [ ] Pindah jadwal booking yang sudah diingatkan → pengingat untuk tanggal baru dikirim lagi sesuai jadwalnya.
- [ ] Booking pending atau dibatalkan tidak menerima pengingat.
- [ ] Staf: detail booking (pending/confirmed) punya ikon **WhatsApp**; pilih "Konfirmasi jadwal" → WhatsApp terbuka dengan chat ke nomor pelanggan dan pesan terisi (nama, layanan, toko, tanggal, jam); "Kendaraan siap diambil" hanya muncul setelah QC lulus. Pesan baru terkirim bila staf menekan kirim.
- [ ] Nomor pelanggan berformat "08xx" atau "+62xx" sama-sama membuka chat yang benar.

## K. Privasi dan laporan, Fase 5 (uji setelah migrasi `2026_10_21_000001` aktif)

- [ ] ⚠ Ringkasan booking menampilkan **Pemberitahuan Privasi** dengan kotak centang; tombol Kirim Booking mati sampai dicentang; kirim → booking masuk.
- [ ] Ubah teks pemberitahuan di panel (Operasional → Teks Aplikasi), tunggu sampai 5 menit → teks baru tampil di booking berikutnya; booking lama tetap tercatat pada versi lamanya (kolom `consent_version`).
- [ ] Alur "Lainnya" (jalur lama) juga mengirim persetujuan dan tercatat.
- [ ] Panel admin: Laporan Jasa → **Laporan SLA & Tidak Datang** terbuka; ganti periode dan cabang (akses penuh); akun staf toko hanya melihat toko sendiri.
- [ ] Konfirmasi satu booking pending, buka laporan → "Konfirmasi dalam Target" dan "Lama Tunggu" terisi; tandai satu booking tidak datang → "Tingkat Tidak Datang" bertambah dan tidak ikut di "Dibatalkan".
- [ ] Server dev: `php artisan bookings:prune-photos --dry-run` melaporkan jumlah foto yang akan dihapus tanpa menghapus (jalankan di server dev, bukan production, sebelum menyalakan jadwalnya).

## L. Penutupan celah (2026-10-22)

- [ ] ⚠ Booking terkonfirmasi untuk lusa → push H-2 menyebut layanan/toko/jam dan **kalimat kebijakan**; ketuk → langsung terbuka **form Ajukan Jadwal Ulang** booking itu (bukan daftar saja). Booking yang sudah dikerjakan atau punya pengajuan menunggu: daftar tampil biasa tanpa form.
- [ ] Push H-1 juga membuka form jadwal ulang; push "Booking Dikonfirmasi" memuat kalimat kebijakan.
- [ ] ⚠ Panel admin, Buat/Ubah Booking dengan Detailing atau Premium Wash saja: kolom **Jam Diinginkan** menyarankan slot (daftar saat diketik), teks bantuan menampilkan jam tersedia dan penuh untuk toko+tanggal yang dipilih; mengetik jam di luar slot (mis. 10:30) ditolak dengan pesan; booking PPF/kaca film tetap bisa teks bebas.
- [ ] Panel admin: simpan booking berat terkonfirmasi saat booking berat aktif lebih banyak daripada installer yang masuk kerja → muncul notifikasi kuning "Installer mungkin kurang"; booking tetap tersimpan.
- [ ] ⚠ Panel admin, detail Booking (terkonfirmasi/selesai): bagian **Penerimaan, QC, dan Serah Terima** menampilkan empat foto sudut, foto kerusakan beserta keterangan, hasil QC terbaru (pemeriksa, butir gagal), dan catatan serah terima (tanda tangan basah, foto akhir); klik foto membukanya di tab baru.

