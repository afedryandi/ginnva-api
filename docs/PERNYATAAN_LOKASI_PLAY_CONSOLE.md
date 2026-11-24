# Draf Pernyataan Izin Lokasi Presisi (Google Play Console)

Disiapkan 2026-10-30. Kebijakan lokasi baru Google Play wajib berlaku **27 Januari 2027**; aplikasi yang memakai `ACCESS_FINE_LOCATION` harus mengisi pernyataan Konsol Play yang menjelaskan mengapa lokasi perkiraan atau tombol lokasi Android tidak memadai.

Catatan: draf ini ditulis dari teks pengumuman kebijakan dan dari kode aplikasi. Isian dan batas karakter di formulir Konsol Play yang sebenarnya belum saya lihat, jadi sesuaikan dengan kolom yang muncul di sana. Bagian bertanda **[ISI]** perlu diisi atau dikonfirmasi pemilik.

## Fakta dari kode aplikasi (dasar pernyataan)

| Hal | Fakta | Sumber |
|---|---|---|
| Fitur yang memakai lokasi presisi | Absen masuk dan absen keluar karyawan | `app/staff/attendance/index.tsx` (`getCurrentPositionAsync`, akurasi `Highest`) |
| Kapan diminta | Hanya saat karyawan menekan tombol absen atau "Cek jarak"; izin foreground saja | `requestForegroundPermissionsAsync`; tidak ada izin lokasi latar belakang, tidak ada pelacakan berkelanjutan |
| Cara dipakai | Server menghitung jarak koordinat ke koordinat toko dan menolak absen bila melebihi radius toko | `Attendance::assertWithinRadius()`; radius bawaan 100 m, diatur per toko |
| Pengaman tambahan | Deteksi lokasi palsu (mock location) memakai penanda `mocked` dari lokasi presisi | `Attendance::assertNotMocked()` |
| Lokasi lain di aplikasi | Layar permintaan penawaran (pelanggan) mengurutkan toko dari yang terdekat, akurasi `Balanced`, izin foreground | `app/quotation/index.tsx` |
| Penyimpanan | Koordinat dan jarak absen disimpan di baris absensi karyawan untuk tinjauan HR | tabel `attendances` |

## Pernyataan (Bahasa Indonesia)

**Fitur inti yang memerlukan lokasi presisi**
Ginnva adalah aplikasi internal operasional untuk karyawan toko dan pelanggan Ginnva (pemasangan kaca film). Fitur inti yang memerlukan lokasi presisi adalah **absensi karyawan**: karyawan mencatat jam masuk dan jam keluar hanya bila mereka berada di lokasi toko tempat mereka bekerja.

**Mengapa lokasi perkiraan tidak memadai**
Absen diterima hanya bila jarak karyawan ke koordinat toko berada dalam radius yang ditetapkan per toko (bawaan 100 meter). Lokasi perkiraan Android dapat meleset hingga sekitar 2 kilometer, jadi karyawan yang berada di luar toko bisa tercatat hadir, atau karyawan yang sebenarnya di toko bisa ditolak. Validasi dengan radius sekecil ini tidak mungkin dilakukan dengan lokasi perkiraan.

**Mengapa tombol lokasi Android tidak memadai**
Hasil lokasi harus diterima bersama penanda yang menunjukkan apakah lokasi berasal dari penyedia lokasi palsu (mock location). Penanda ini dipakai untuk menolak absen dengan GPS palsu. Pemilih lokasi atau tombol lokasi sistem tidak memberikan penanda ini, sehingga kontrol anti-kecurangan absensi tidak dapat dijalankan.
**[ISI: konfirmasi bahwa saat tombol lokasi Android dicoba, penanda `mocked` memang tidak tersedia. Saya belum menguji ini.]**

**Cakupan penggunaan**
- Lokasi hanya diminta saat karyawan menekan tombol absen atau "Cek jarak". Tidak ada izin lokasi latar belakang dan tidak ada pelacakan berkelanjutan.
- Lokasi presisi dipakai hanya untuk absensi. Untuk layar pelanggan yang mengurutkan toko terdekat, aplikasi meminta akurasi seimbang dan berfungsi dengan lokasi perkiraan.
- Koordinat dan jarak absen disimpan pada catatan absensi karyawan yang bersangkutan untuk tinjauan HR, dan tidak dibagikan ke pihak ketiga.
  **[ISI: konfirmasi tidak ada SDK iklan atau analitik yang membaca lokasi.]**

## Statement (English, if the form requires it)

**Core feature requiring precise location:** employee attendance. Staff clock in and out of work only when physically at their assigned store.

**Why approximate location is insufficient:** a clock-in is accepted only if the staff member is within a per-store radius of the store coordinates (default 100 m). Android approximate location can be off by roughly 2 km, so it cannot reliably tell whether someone is at the store.

**Why the Android location button is insufficient:** the app must read the "mocked location" flag returned with the precise fix to reject spoofed GPS. The system location button/picker does not supply it, so the attendance anti-fraud check could not run.

**Scope:** location is requested only when the user taps the clock-in/out (or "check distance") button, foreground only, no background or continuous tracking. A separate customer screen that sorts stores by distance uses balanced accuracy and works with approximate location. Attendance coordinates are stored on the employee's own attendance record for HR review and are not shared with third parties.

## Yang perlu dicek sebelum mengirim

1. **Penanda `mocked` dengan tombol lokasi Android.** Pernyataan "tombol lokasi tidak memberi penanda" belum saya uji. Kalau salah, bagian itu perlu diganti dengan alasan lain, atau aplikasi perlu diubah.
2. **Android 17 (API 37).** Aplikasi baru wajib memakai tombol lokasi Android bila menarget API 37 ke atas. Versi target sekarang (Expo SDK 54) lebih rendah, jadi belum berlaku. Cek lagi saat naik SDK.
3. **Satu izin untuk dua fitur.** `expo-location` meminta lokasi presisi dan perkiraan sekaligus. Layar penawaran pelanggan hanya butuh perkiraan; pengguna Android 12 ke atas dapat memilih "perkiraan" di dialog izin. Pertimbangkan, bila kelak diminta Google, memisahkan alasan per fitur.
4. **Teks izin.** Teks izin lokasi di `app.json` hanya menyebut absensi masuk/keluar. Tambahkan kalimat tentang urutan toko terdekat bila Google meminta teks yang sesuai dengan semua penggunaan.
5. **Tenggat.** 27 Januari 2027. Sebaiknya diisi bersamaan dengan rilis aplikasi berikutnya.
