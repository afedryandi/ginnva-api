# Modul Keuangan Dimatikan (FEATURE_FINANCE)

Keputusan pemilik 2026-10-29: pembukuan Ginnva memakai **Accurate 5**. Modul Keuangan buatan sendiri tidak dipakai, jadi dimatikan dengan satu saklar. Kode dan tabel tetap ada, data lama tidak dihapus, dan modulnya bisa dinyalakan lagi.

Saklar: `FEATURE_FINANCE` di `.env` (config `features.finance`, bawaan **mati**). `phpunit.xml` menyalakannya supaya tes keuangan tetap berjalan.

## Yang terjadi saat saklar mati

**Disembunyikan**
- Klaster Keuangan: 13 resource (jurnal, kas, rekening, anggaran, hutang/piutang, kategori, bagan akun, rekonsiliasi bank, tagihan rutin, hitung kas, pemindahan kas, transaksi, persetujuan pengeluaran) dan 16 halaman laporan/proses. Ditutup lewat `canViewAny()`/`canAccess()` masing-masing, jadi tidak bisa dibuka lewat alamat langsung juga.
- Endpoint `/api/staff/finance/*` (404 lewat middleware `feature:finance`) dan tombol Keuangan di aplikasi staf (`has_finance_access` false).
- Jadwal otomatis: pengingat persetujuan pengeluaran, hutang, piutang, penyusutan aset, tagihan rutin, dan sinkron HPP.
- Panduan Penggunaan modul Keuangan (`GuideCatalog::FINANCE_GUIDE_IDS`). Teks panduan Penjualan, Invoice, Aset Tetap, Permohonan Pembelian, Laporan Penjualan, Penggajian, Admin/Kasir, Direksi, dan Dashboard sudah dirapikan: tidak lagi menyebut jurnal, rekening, atau penutupan pembukuan.
- Kotak "Keuangan" di Akses Menu user tetap ada (diberi tanda "modul nonaktif") supaya izin yang tersimpan tidak hilang saat user disimpan.

**Dipindah supaya tetap tampil** (operasional, bukan akuntansi)
- Pengaturan Keuangan -> **Profil Perusahaan** di klaster Sistem (nama PT, NPWP, alamat untuk kop dan kaki invoice); bagian persetujuan pengeluaran disembunyikan.
- Supplier -> klaster Inventori (dipakai Permohonan Pembelian).
- Persetujuan Transaksi (approval Referral/Refund/DP) -> klaster Booking.

**Fitur lain yang tidak lagi membuat jurnal/piutang** (catatan operasionalnya tetap berjalan)
- Proses Referral/penjualan booking: tidak ada jurnal dan piutang; tanggal penjualan disimpan di `bookings.sale_date`.
- DP: dicatat tanpa rekening dan jurnal; bisa dikembalikan.
- Refund: dicatat tanpa jurnal; syaratnya nominal transaksi sudah diisi dan tidak melebihi uang yang diterima.
- Pembayaran pelunasan: hanya metode dan nominal (tanpa pilihan rekening).
- Penggajian: ditandai dibayar tanpa jurnal; kolom akun di Kategori Komponen Gaji disembunyikan.
- Stok terbuang: nilai tetap dicatat, jurnal kerugian tidak dibuat.
- Permohonan Pembelian: ditandai terpenuhi tanpa jurnal dan tagihan; biaya aktual dan supplier opsional.
- Aset tetap: akun penyusutan disembunyikan; pelepasan hanya mengubah status.
- HPP/persediaan di buku besar: ikut mati.

**Laporan penjualan sekarang bersumber dari booking**
Dashboard Penjualan, Penjualan per Periode, Layanan, Penjualan per Outlet, Produk, Pelanggan, Jam Ramai (2), Komisi Installer, Detail Penjualan (+ ekspor), tiga grafik, widget pendapatan, dan KPI penjualan memakai scope `Booking::soldBetween()/sold()/unsold()/soldBefore()`. Saklar menyala: tanggal jurnal (perilaku lama). Saklar mati: `bookings.sale_date`. Migrasi `2026_11_23_000001` mengisi `sale_date` dari tanggal jurnal untuk booking lama, jadi riwayat laporan tidak berubah.

## Yang belum disentuh (sisa)
- Entri Katalog Notifikasi yang terkait keuangan (tidak pernah terkirim karena tidak ada pemicunya).
- Perintah demo lama (`demo:presentasi`) yang masih membuat data keuangan.
- Tabel dan kolom keuangan tetap ada di database (`journal_entry_id` di booking, DP, refund, dan lain-lain); sengaja tidak dihapus.

## Menyalakan lagi
Isi `FEATURE_FINANCE=true`, lalu `php artisan config:clear` dan `config:cache`. Booking yang diproses saat saklar mati tidak punya jurnal; jurnalnya terbentuk bila "Proses Referral" disimpan ulang.
