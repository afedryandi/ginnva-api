# Audit ACCURATE 5 vs Ginnva

Dibuat 2026-10-24. **Tujuan:** menemukan fitur, halaman, dan kebiasaan di ACCURATE 5 yang relevan dan bisa diterapkan di sistem Ginnva, supaya semua staf (termasuk finance) memakai Ginnva dan tidak perlu Majoo atau ACCURATE lagi. Bukan rencana integrasi.

## Aturan penyaringan (sama dengan audit Majoo)
- Terjemahkan ke konteks Ginnva: jasa pemasangan **PPF, kaca film, detailing, dan premium wash** untuk mobil. Jangan meniru fitur retail atau manufaktur mentah-mentah.
- Tiap halaman diperiksa dari enam sisi: data, metrik, UI/UX, fitur, menu, kolom dan filter.
- Hasil dicatat sebagai temuan; keputusan membangun diambil bersama pemilik per item, tidak otomatis.
- Sumber: tangkapan layar dari instalasi baru ACCURATE 5 Standard (belum berisi data Ginnva). Hanya yang terlihat di layar yang diaudit; hal yang tidak terlihat ditandai "perlu dilihat".

## Temuan pass 1 (menu Berkas, Persiapan, Info Perusahaan, Preferensi)

| Fitur ACCURATE | Di Ginnva sekarang | Relevansi untuk Ginnva | Catatan |
|---|---|---|---|
| Jejak Audit (opsi) | Ada dan lebih kaya: Histori Aktivitas (siapa, kapan, asal, IP, nilai sebelum/sesudah) | Sudah terpenuhi | Tidak perlu apa pun. |
| Cadangan Data saat keluar | Ada: cadangan harian 03:00 ke lokal dan Drive | Sudah terpenuhi | Lebih kuat dari ACCURATE. |
| Peringati/Cegah transaksi jauh dari periode default | Sebagian: tanggal transaksi keuangan dibatasi (tidak boleh masa depan) dan periode tutup mengunci | Kecil | Perlu dilihat apakah ada peringatan bila tanggal sangat mundur tetapi periodenya masih terbuka. |
| Pengingat saat buka: piutang/hutang jatuh tempo, belum direkonsiliasi, transaksi berulang | Ada laporan umur Piutang dan Hutang, Template Tagihan Rutin, dan Rekonsiliasi Bank, tetapi **pengingat terpadu di Dasbor Keuangan belum terkonfirmasi** | **Layak**: finance terbantu | **Perlu dicek** apa yang sudah ada di Dasbor Keuangan; kandidat kartu "Perlu Perhatian" keuangan. |
| Akun default per jenis transaksi (hutang, piutang, uang muka, HPP, persediaan, laba/rugi) | Pemetaan akun ada di kategori keuangan dan layanan posting; **apakah akuntan bisa mengubahnya tanpa kode belum terkonfirmasi** | **Layak** untuk pengaturan oleh akuntan | Kandidat halaman "Pengaturan Akun Default" di Pengaturan Keuangan. |
| Metode biaya persediaan (Average) | Persediaan bahan dan barang habis pakai ada; **HPP belum dijurnal** (keputusan Keuangan) | **Layak** bila HPP dijurnal | Terkait keputusan HPP di dokumen Keuangan. |
| Pengaturan pajak: nomor seri faktur, rangkap faktur PPN, pembulatan, akun PPh | PPN hanya rincian; belum ada faktur pajak | **Bergantung status PKP** | Modul Pajak sudah dirancang di `RANCANGAN_ULANG_KEUANGAN.md`; ACCURATE hanya patokan fitur. |
| NPWP 15 dan 16 digit | Kolom NPWP ada di data personalia; **validasi 16 digit belum dicek** | Kecil | Pastikan kolom menerima 15 dan 16 digit. |
| Review Akuntan (salinan akuntan, ekspor perubahan untuk klien) | Tidak ada; akuntan eksternal memakai ekspor | Menengah | Kandidat peran "Akuntan (hanya baca)" dengan ekspor jurnal dan laporan. |
| Multi cabang (ID Cabang, Data Cabang Baru, Ekspor Impor Transaksi) | Satu basis data dengan penandaan toko | Tidak perlu | Ginnva sudah satu basis data. |
| Rancangan Formulir (perancang faktur dan kuitansi) | Faktur dan dokumen PDF dengan tata letak tetap | Rendah | Kandidat bila pelanggan meminta format dokumen berbeda. |
| Multi Gudang, Multi Unit, Pakai Penjual, Kuantitas < 0 | Stok per toko dan per gulungan; komisi per teknisi | Tidak perlu | Sudah sesuai kebutuhan Ginnva. |
| Format angka dan tanggal, bahasa, skin | Satu tampilan | Tidak perlu | |

## Yang belum diaudit (menunggu tangkapan layar)
- Menu **Daftar** (master data), **Aktifitas** (transaksi), **Laporan**, dan modul **Buku Besar, Kas Bank, Persediaan, Penjualan, Pembelian, Aset Tetap, RMA, e-Faktur**.
- Daftar laporan keuangan dan pajak yang tersedia, dan apakah laporan bisa diekspor ke Excel.
- Alur Rekonsiliasi Bank, jurnal umum, penyusutan aset tetap, dan penutupan periode, untuk dibandingkan dengan Ginnva.
