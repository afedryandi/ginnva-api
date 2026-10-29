# Fitur yang Disembunyikan (saklar `config/features.php`)

Fitur yang tidak dipakai disembunyikan dengan saklar, bukan dihapus: kode dan data tetap ada, dan menyala lagi dengan mengisi `true` di `.env` lalu `php artisan config:clear` dan `config:cache`. `phpunit.xml` menyalakan semuanya supaya tesnya tetap berjalan.

| Saklar (`.env`) | Menyembunyikan | Keputusan |
|---|---|---|
| `FEATURE_FINANCE` | Seluruh modul Keuangan, endpoint `/finance`, jurnal otomatis. Lihat [KEUANGAN_DIMATIKAN.md](KEUANGAN_DIMATIKAN.md). | 2026-10-29, pembukuan di Accurate 5 |
| `FEATURE_K3` | Jenis APD, Pembagian APD, Kejadian K3 | 2026-10-29, belum ada kewajiban K3 |
| `FEATURE_PERFORMANCE_REVIEWS` | Penilaian Kinerja | 2026-10-29 |
| `FEATURE_EMPLOYEE_TRAINING` | Pelatihan Karyawan (sertifikat installer tetap di Kompetensi Installer) | 2026-10-29 |
| `FEATURE_SALARY_GRADES` | Struktur & Skala Upah (dan pilihan Golongan Upah di form User) | 2026-10-29, audit modul Karyawan |
| `FEATURE_EMPLOYEE_CHECKLISTS` | Checklist Masuk & Keluar dan Template Checklist | 2026-10-29, audit modul Karyawan |
| `FEATURE_INSTALLER_UTILIZATION` | Utilisasi Installer (jam pekerjaannya estimasi; Durasi Servis memakai jam nyata SPK) | 2026-10-29, audit modul Karyawan |
| `FEATURE_WORK_SCHEDULES` | Shift, Jadwal Kerja, kalender jadwal (sejak 2026-10-29 juga tertutup dari akses langsung) | 2026-10-12 |
| `FEATURE_ADMIN_TOUR` | Tur pengenalan panel | 2026-10-12 |

Saklar K3, Penilaian Kinerja, dan Pelatihan Karyawan menutup menunya dari akses (`canViewAny()` false, termasuk lewat alamat langsung) dan juga:
- layar "K3 & Penilaian" di aplikasi staf (`has_safety_access` false; endpoint `/api/staff/safety` mengirim daftar kosong untuk bagian yang mati, dan pengakuan penilaian ditolak 404);
- butir terkait di Dasbor HR (APD, kejadian K3, belum dinilai; butir sertifikat kedaluwarsa hanya menghitung sertifikat installer bila Pelatihan Karyawan mati);
- panduan "K3 dan Kinerja" (hilang bila ketiganya mati);
- pengisian data demo (`demo:lengkapi`).

Kotak izin menu di User tetap ada supaya izin yang tersimpan tidak hilang.

## Tata letak klaster Karyawan (audit 2026-10-29)
- **Kinerja Installer**: Durasi Servis, Kompetensi Installer, Pekerjaan Ulang (dan Utilisasi bila dinyalakan).
- **Cuti & Kontrak**: Izin & Cuti, Jenis Izin & Cuti, Riwayat Kontrak, Surat Peringatan.
- **Kebijakan & Checklist**: Peraturan & Kebijakan (dan Checklist Masuk & Keluar, Template Checklist bila dinyalakan).
- Nama baru: Role & Divisi, Kelengkapan Data Karyawan, Riwayat Kontrak (tombol di dalamnya tetap "Perpanjang Kontrak"), Checklist Masuk & Keluar.
