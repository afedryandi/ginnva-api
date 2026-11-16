# Audit navigasi panel admin Ginnva (2026-11-16)

Sumber: seluruh `app/Filament/{Resources,Pages,Clusters}` dan `AdminPanelProvider`. Dibaca dari kode (belum dilihat di browser). Panel memakai `topNavigation()`:
satu entri bilah atas = satu **kluster**; kluster dan halaman lepas yang punya `$navigationGroup` ('Penjualan', 'Lainnya') dikelompokkan jadi **dropdown**.

## 1. Bentuk bilah atas sekarang

| Posisi | Isi | Jumlah item |
|---|---|---|
| Dashboard | DashboardHome | 1 |
| Booking (10) | 5 grup: Order & Lead, Operasional, Dokumen, Setelah Layanan, Approval | 12 |
| Inventori (20) | 6 grup | 16 |
| Marketing/Konten (20) | 5 grup | 14 |
| Karyawan (30) | 8 grup | 36 |
| Keuangan (50) | 5 grup (dimatikan flag `FEATURE_FINANCE`) | 29 |
| **Penjualan ▾** | Dashboard (penjualan), Laporan (15), Analisa Laporan (16), Produk (17), Pelanggan (19), Promosi (20) | 6 kluster/halaman |
| **Lainnya ▾** | Master Data (60), Notifikasi (61), Sistem (62), Panduan (70), Catatan Panduan (71) | 5 |

Total: 13 kluster + 4 halaman lepas. Laporan Jenis Order, Laporan Voucher, dan Komisi Tetap mewarisi kluster induknya dan hanya tampil bila flag fiturnya hidup.

## 2. Temuan

1. **Dua halaman bernama "Dashboard"**: DashboardHome dan SalesDashboard (di dropdown Penjualan). Ejaan juga tidak seragam: "Dashboard Inventaris", "Dasbor HR", "Dasbor Keuangan", "Dasbor Lead".
2. **Dropdown "Penjualan" bercampur**: isinya Dashboard penjualan, Laporan, Analisa Laporan, Produk, Pelanggan, Promosi. Produk dan Pelanggan itu data induk, bukan penjualan.
3. **Kluster sangat kecil**: Sistem (1 item), Pelanggan (2), Produk (3), Analisa Laporan (4), Master Data (5), Promosi (5). Tiap kluster memakan satu entri menu dan satu sidebar.
4. **Laporan tersebar di 6 tempat**: kluster Laporan (±23), Analisa Laporan (4), Karyawan (Laporan Absensi, 4 laporan Kinerja Installer, Kelengkapan Data, Jadwal Kerja Karyawan), Inventori (Daftar Stok), Keuangan (±14), Marketing (Dasbor Lead).
5. **Urutan tidak pasti**: Inventori, Marketing/Konten, dan Promosi semuanya `navigationSort = 20`.
6. **Karyawan terlalu panjang**: 36 item dalam 8 grup. Termasuk grup Jadwal Kerja (3 item; keputusan 2026-10-17 menyebut tanpa shift) dan Penggajian (4 item; dimatikan flag `FEATURE_PAYROLL`).
7. **Grup berisi satu item**: Booking > Dokumen (Invoice), Booking > Approval (Persetujuan Transaksi), Laporan > Laporan Pelanggan, Laporan Karyawan, Laporan Fasilitas, Laporan Produk.
8. **Laporan Utilisasi Zona/Bay**: Ginnva tidak punya entitas bay (teguran 2026-09-21), tetapi laporan ini masih terdaftar.
9. **Panduan dobel**: ada di dropdown Lainnya dan sudah ada di menu avatar. Catatan Panduan adalah alat admin, bukan bahan baca staf.
10. **Penamaan**: "Inventori" (kluster) vs "Inventaris" (kelas, Dashboard Inventaris, glosarium).

## 3. Usulan struktur (8 entri, tanpa dropdown)

| Entri | Isi | Perubahan |
|---|---|---|
| Dashboard | Dashboard utama | tetap |
| Booking | Order & Lead: Quotation, Booking, SPK, Invoice, Persetujuan Transaksi · Operasional · Setelah Layanan | Invoice masuk alur order; grup Dokumen dan Approval hilang |
| Inventori | seperti sekarang | hanya penyeragaman nama |
| Karyawan | Absensi & Cuti · Kinerja & K3 · Kepegawaian · Pengaturan | Jadwal Kerja dan Penggajian disembunyikan (menunggu keputusan) |
| Marketing | Inquiry & Kemitraan · Konten · Promo & Loyalti · Klaim · Materi · Rekrutmen · Notifikasi | Promosi dan Notifikasi digabung ke sini |
| Laporan | Dashboard Penjualan · Penjualan · Jasa · Produk · Persediaan · Pelanggan · Analisa | Analisa Laporan digabung; "Dashboard" penjualan jadi "Dashboard Penjualan" |
| Master Data | Pusat Master Data · Produk · Pelanggan · Kendaraan · Luas Kaca · Toko | Produk dan Pelanggan pindah ke sini |
| Sistem | Riwayat Aktivitas · Panduan · Catatan Panduan | gabungan Sistem dan sisa Lainnya |
| Keuangan | seperti sekarang | muncul hanya bila flag hidup |

## 4. Risiko

- Memindahkan resource antar kluster **mengubah URL** (slug kluster ada di jalur). Tautan lama, bookmark, dan tautan notifikasi yang menunjuk URL lama perlu dicek.
- Teks **Panduan** (`GuideCatalog`) menyebut jalur menu, dan `GuideAccuracyTest` mencocokkannya dengan label nyata, jadi teks panduan harus ikut diperbarui.
- Kunci hak akses memakai nama kelas, jadi **tidak terpengaruh**.
- Filament melarang grup dan item di dalamnya sama-sama berikon (pernah menyebabkan error fatal 2026-09-15).

## Koreksi (2026-11-16)

Setelah dibaca ulang, beberapa temuan sudah teratasi lewat flag fitur: grup **Jadwal Kerja** (flag `work_schedules`), **Penggajian** (`payroll`), Laporan Jenis Order, Laporan Voucher, dan Lap. Detail Persediaan sudah **tersembunyi bawaan**. Jadi bilah atas yang benar-benar tampil lebih pendek daripada tabel di atas; temuan 3 sampai 5 dan 9 yang paling nyata.

## 5. Tahap pengerjaan yang disarankan

- **A. Tanpa risiko URL**: urutan unik, penamaan seragam (Dashboard), "Dashboard Penjualan", grup satu-item digabung, Panduan dirapikan.
- **B. Gabung kluster** (mengubah URL): Master Data, Laporan, Marketing, Sistem.
- **C. Pangkas Karyawan** sesuai keputusan di bawah.

Tiap tahap diuji dan di-commit terpisah.

## 6. Tahap A: sudah dikerjakan (2026-11-16)

- Kluster stok memakai nama **Inventaris** (label, breadcrumb, Hak Akses, Panduan).
- Urutan bilah atas unik: Inventaris 20, Marketing/Konten 21.
- Nama dashboard seragam: Dashboard, **Dashboard Penjualan**, Dashboard HR, Dashboard Keuangan, Dashboard Lead.
- **Invoice** dan **Persetujuan Transaksi** masuk grup *Order & Lead* (grup Dokumen dan Approval di Booking hilang).
- **Laporan Produk** (satu item) digabung ke *Laporan Penjualan*.
- **Laporan Utilisasi Zona/Bay** disembunyikan dari menu lewat flag `FEATURE_REPORT_BAY_ZONE` (bawaan mati).
- Tidak ada URL kluster yang berubah.
- Belum: Tahap B (gabung kluster) dan C (pangkas Karyawan).
