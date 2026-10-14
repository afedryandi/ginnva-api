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
- [ ] Langkah Layanan: empat layanan tampil dengan harga (Detailing dan Premium Wash harga tetap, PPF dan Kaca Film "Mulai dari" sesuai ukuran mobil), lama pengerjaan, pilih lebih dari satu; "Lainnya" menghapus pilihan lain dan mewajibkan catatan.
- [ ] Ganti kendaraan (ukuran berbeda) → harga "Mulai dari" PPF/Kaca Film ikut berubah.
- [ ] Ringkasan: kendaraan, layanan, perkiraan lama dan harga tampil benar; kirim → booking masuk dengan plat di Booking Saya.
- [ ] ⚠ Matikan data saat menekan Kirim Booking lalu nyalakan dan tekan lagi → hanya satu booking terbentuk (kunci idempotensi).
- [ ] Staf: daftar booking menampilkan plat dan merek/model; detail booking menampilkan bar kendaraan; installer melihat plat, kendaraan, warna, dan ukuran.
- [ ] Panel admin: kolom Plat di daftar booking bisa dicari; detail booking punya bagian Kendaraan.
- [ ] Layar "Perbarui Aplikasi": hanya muncul bila server mengirim 426 (uji dengan `APP_MIN_VERSION_ANDROID` lebih tinggi dari versi build di dev).
