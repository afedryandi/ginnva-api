# Rancangan Ulang Alur Booking, SPK, dan Memo Barang

Disusun 2026-11-27. Dasar: `docs/AUDIT_ALUR_BOOKING_SPK_MEMO.md` (temuan dan data) dan sembilan keputusan pemilik di bawah.
Status: semua keputusan terjawab, rencana disetujui sebagai arah, **belum ada kode yang dibangun**. Mulai dari Fase 0 setelah pemilik memberi aba-aba.

Tujuan: satu pekerjaan yang utuh dari booking sampai laporan bulanan, semua langkah di aplikasi staf, tanpa data yang diketik dua kali.

## 1. Keputusan pemilik (final)

| # | Keputusan |
|---|---|
| 1 | Booking induk wajib. SPK manual dan memo tanpa booking dihapus (kebutuhannya dijawab Buat Booking di aplikasi staf) |
| 2 | Satu catatan bahan. **Perlu dikonfirmasi ulang (27 Nov):** ditemukan bahwa roll PPF/WF sengaja dipisah dari memo (keputusan pemilik; memo hanya untuk bahan baku dan barang habis pakai, roll lewat Catat Pemakaian per SPK). Usulan baru: dua jalur itu tetap, tetapi keduanya terikat ke booking (roll lewat SPK) dan keduanya muncul di sheet Bahan laporan |
| 3 | SPK dan memo membaca pelanggan dan kendaraan dari booking, tidak menyalin |
| 4 | Selesai wajib: SPK keluar, memo ditutup (bila ada bahan), penjualan tercatat (invoice terbit) |
| 5 | Penjualan di HP hanya spv finance dan akses penuh. Invoice terbit setelah pengerjaan selesai (QC lolos), sebelum serah terima |
| 5a | Pengecualian: serah terima dulu, bayar menyusul. Lihat bagian 3 |
| 6 | Data lama dibiarkan, hanya data baru memakai alur baru |
| 7 | Laporan bulanan: acuan tanggal selesai, dua tanggal di tiap baris, memuat jumlah dan nilai bahan |
| 8 | Panel tetap untuk koreksi darurat akses penuh, kalender kapasitas, master data, pengaturan dan hak akses, laporan dan ekspor, riwayat aktivitas. Buat dan ubah SPK dan memo hilang dari panel |
| 9 | Jenis tenaga kendaraan: bensin, listrik, hybrid. SPK menampilkan BBM, Battery, atau keduanya |

## 2. Alur target

1. **Booking dibuat** (pelanggan di aplikasi, atau staf di aplikasi staf). Pelanggan, kendaraan (termasuk jenis tenaga), layanan, produk per bagian, dan jadwal diisi **sekali**.
2. **Konfirmasi**: kapasitas, installer, lama perkiraan. Sistem membuat **invoice draf** otomatis (uang muka dicatat di sini).
3. **Mobil diterima**: Foto Penerimaan dan **Lembar Penerimaan (SPK)** terbuka dari booking, satu per booking, tanpa memilih. Berisi hanya pemeriksaan fisik: jenis bodi, km, VIN, BBM atau Battery sesuai jenis tenaga, titik kerusakan, perlengkapan, catatan. Jam masuk terisi otomatis.
4. **Pengerjaan**: tahap per produk. Bahan diambil lewat **Lembar Bahan (Memo)** yang dibuat dari booking; kendaraan dibaca dari booking.
5. **QC lolos**: spv finance diberi tahu.
6. **Invoice diterbitkan** (spv finance atau akses penuh), harga akhir, diskon, voucher, kode referral.
7. **Serah terima dan pelunasan** di hari yang sama. Pembayaran boleh menyusul (tercatat sebagai piutang di invoice).
8. **Checklist Selesai**: SPK sudah keluar, memo ditutup (bila ada bahan), invoice terbit. Lalu **Selesai**.
9. **Laporan bulanan** satu file.

## 3. Pengecualian: serah terima dulu, bayar menyusul

Dua tingkat, dari yang paling sering ke yang jarang:

- **Bayar menyusul (invoice sudah terbit).** Serah terima dicatat, pelunasan belum. Booking boleh Selesai karena penjualan sudah tercatat. Sisa tagihan muncul di daftar piutang invoice sampai lunas.
- **Invoice belum terbit saat pelanggan harus pulang** (spv finance tidak ada). Store Manager boleh mencatat serah terima dengan **alasan wajib**. Booking **belum bisa Selesai**: statusnya "Menunggu invoice", spv finance diberi tahu, dan Selesai terbuka setelah invoice terbit. Dicatat di Riwayat Aktivitas.

Ini tafsiran saya atas "serah terima dulu, bayar menyusul"; bila yang dimaksud lain, bagian ini disesuaikan sebelum Fase 4.

## 4. Perubahan model data

| Tabel | Perubahan |
|---|---|
| `bookings` | + `vehicle_power` (bensin, listrik, hybrid). Sumber pelanggan dan kendaraan satu-satunya |
| `customer_vehicles` | + `vehicle_power`; Booking dari aplikasi menyalinnya saat dibuat |
| `spks` | `booking_id` wajib untuk SPK baru (SPK lama tetap, kolom tetap nullable). Kolom salinan (nama, telepon, alamat, plat, merek, model, varian, tahun) tidak diisi lagi untuk SPK baru dan dibaca dari booking lewat accessor. Kolom pemeriksaan fisik tetap |
| `material_memos` | `booking_id` wajib untuk memo baru; `spk_number` teks bebas dan kolom kendaraan tidak diisi lagi untuk memo baru. Memo lama tetap |
| `material_memo_items` dan `scroll_code_usages` | **Revisi 27 Nov:** tidak digabung. Item memo (bahan baku, barang habis pakai) dan pemakaian roll (per SPK) tetap dua jalur; Fase 2 memastikan keduanya terikat ke booking dan tampil di satu sheet Bahan |
| `invoices` dan `invoice_payments` | Sesuai `RANCANGAN_BOOKING_INVOICE_DP.md` (draf otomatis, pembayaran uang muka dan pelunasan) |
| `bookings` | + penanda "menunggu invoice" untuk pengecualian serah terima |

Kolom lama tidak dihapus di tahap awal (data lama dibiarkan). Penghapusan kolom salinan dipertimbangkan jauh sesudah data lama tidak dipakai lagi.

## 5. Rencana bertahap

Tiap fase punya hasil yang berdiri sendiri dan bisa dirilis sendiri. Ukuran: K kecil, S sedang, B besar (perkiraan kasar, belum termasuk uji di HP oleh pemilik).

| Fase | Isi | Ukuran |
|---|---|---|
| 0 | **Fondasi data.** Kolom `vehicle_power` di booking dan kendaraan pelanggan; pelanggan dan kendaraan di SPK dan memo dibaca dari booking lewat **cermin otomatis** (`BookingMirror`: nilai booking menimpa isian dan ikut berubah bila booking dikoreksi; kolom lama tetap ada supaya pencarian, PDF, ekspor, dan data lama tidak berubah); aturan server: SPK dan memo baru wajib terikat booking, diaktifkan lewat `FEATURE_BOOKING_REQUIRED_FOR_SPK_MEMO` setelah build aplikasi baru beredar. Aplikasi lama tidak rusak | S |
| 1 | **Lembar Penerimaan (SPK).** Dibuka dari "Mobil diterima" di detail booking (satu per booking, tanpa pilih booking). Hanya pemeriksaan fisik; BBM atau Battery sesuai jenis tenaga. Buat SPK manual dihapus dari aplikasi dan panel | S |
| 2 | **Lembar Bahan.** Memo dibuat dari SPK atau booking (tanpa ketik nomor SPK bebas), kendaraan dibaca. Dua jalur bahan (memo dan roll per SPK) sama-sama terikat booking dan tampil di satu sheet laporan | B |
| 3 | **Penjualan dan invoice di HP.** Draf otomatis saat konfirmasi; layar Invoice untuk spv finance dan akses penuh (baris, diskon, voucher, referral, uang muka, pelunasan, terbitkan); notifikasi saat QC lolos; daftar piutang. Aksi Catat Penjualan dan DP di panel dihapus | B |
| 4 | **Checklist Selesai dan pengecualian.** `completionBlocker` diperluas (SPK keluar, memo ditutup, invoice terbit); alur "Menunggu invoice"; serah terima dengan alasan | S |
| 5 | **Laporan bulanan satu file** (bagian 6) di panel (Laporan) | S |
| 6 | **Bersih-bersih panel.** Buat dan ubah SPK dan memo jadi lihat saja; Panduan Penggunaan, istilah baku, dan tes diperbarui | K |

Urutan: 0, lalu 1 dan 2 (bisa berurutan), 3, 4, 5, 6. Fase 3 bisa jalan paralel dengan 1 dan 2 karena menyentuh tabel yang berbeda.

### Rilis
- Tanpa server dev (keputusan 21 Nov): tes penuh di laptop, rilis langsung ke VPS.
- Server dirilis dulu, aplikasi menyusul. Selama aplikasi lama masih beredar, endpoint lama tetap bekerja (kompatibel). Build aplikasi dikelompokkan: satu setelah Fase 1 dan 2, satu setelah Fase 3 dan 4.
- Tiap fase membawa migrasi; tinjau migrasi sebelum rilis (RUNBOOK §3).

## 6. Laporan bulanan

Satu file Excel, tiga sheet, kunci **No Booking**. Filter bulan memakai **tanggal selesai** (dua tanggal tetap tampil di tiap baris).

**Sheet Pekerjaan (satu baris per booking):** No Booking, toko, sumber, status; pelanggan, telepon; plat, merek, model, varian, tahun, warna, ukuran, jenis tenaga; layanan, produk; installer; tanggal booking, tanggal masuk (jam masuk SPK), tanggal selesai; durasi rencana (hari), durasi aktual (jam); No SPK, jenis bodi, km masuk, BBM atau Battery; No Memo, status memo, jumlah item, **nilai bahan**; No Invoice, harga akhir, diskon, uang muka, pelunasan, sisa tagihan, metode.
**Sheet Bahan (satu baris per item):** No Booking, No Memo, jenis barang, nama, diambil, dikembalikan, terpakai, meter, harga satuan, **nilai**.
**Sheet Pembayaran (satu baris per pembayaran):** No Booking, No Invoice, jenis (uang muka atau pelunasan), metode, nominal, tanggal.

Nilai bahan: bahan baku memakai harga beli per batch (FIFO), barang habis pakai memakai harga beli terakhir, roll PPF/WF memakai harga beli per meter dikali meter terpakai. Barang tanpa harga tampil kosong dan ditandai. Booking yang Pekerjaannya selesai tetapi invoice belum terbit tetap tampil di bulan tanggal selesainya, dengan kolom penjualan kosong dan keterangan "menunggu invoice".

## 7. Risiko

| Risiko | Mitigasi |
|---|---|
| spv finance tidak ada saat serah terima | Notifikasi saat QC lolos; pengecualian "Menunggu invoice" (bagian 3) |
| Staf terbiasa menyalin dan mengubah data di SPK | Data jadi baca-saja; koreksi di booking, satu tempat |
| Nilai bahan kosong karena harga beli belum diisi | Ditandai di laporan; master data dilengkapi sebelum Fase 5 |
| Aplikasi lama memanggil endpoint yang berubah | Kompatibilitas sampai build baru beredar; flag fitur bila perlu |
| Data lama bercampur dengan data baru di laporan | Data lama dibiarkan; kolom baru kosong untuk data lama dan ditandai |
| Rombakan besar mengganggu pekerjaan harian | Rilis bertahap per fase, bisa dihentikan di fase mana pun tanpa merusak |

## 8. Ukuran keberhasilan

- 100% booking baru yang Selesai memiliki SPK keluar, memo tertutup (bila ada bahan), dan invoice terbit.
- Tidak ada SPK atau memo baru tanpa booking.
- Laporan bulanan keluar dalam satu file tanpa menyambung manual.
- Pelanggan, telepon, dan kendaraan hanya satu tempat untuk dikoreksi.
