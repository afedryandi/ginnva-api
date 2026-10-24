# Catatan Eksplorasi: ACCURATE 5 dan Keuangan Ginnva

Dibuat 2026-10-24. **Status: catatan, belum ada keputusan dan belum ada pekerjaan di sistem.** Pemilik sedang mengumpulkan tangkapan layar semua halaman dan fitur ACCURATE 5 untuk dinilai.

## 1. Mengapa dieksplorasi

Keuangan Ginnva sengaja belum menutup beberapa area yang biasanya diurus software akuntansi (lihat `RANCANGAN_ULANG_KEUANGAN.md`): PPN dan faktur pajak (hanya rincian di booking, tanpa jurnal PPN Keluaran/Masukan atau faktur pajak), HPP dan persediaan (tidak dijurnal), serta PPh, BPJS, dan SPT (di luar sistem). Sebaliknya keuangan Ginnva terikat erat dengan booking, DP, komisi installer, penggajian, kas toko, dan persetujuan berjenjang, yang tidak ada di software akuntansi umum.

## 2. Yang sudah diketahui (dari tangkapan layar beranda, 2026-10-24)

- Judul jendela: **ACCURATE 5 - Belum Teregistrasi - PT Ginnva Shield Indonesia [ginnva.gdb]**. Status "Belum Teregistrasi" berarti belum diregistrasi dengan lisensi (mungkin mode percobaan). **Status lisensi harus dipastikan** sebelum dipakai sebagai pembukuan resmi.
- Berkas data `ginnva.gdb` adalah basis data lokal di komputer; tidak ada API. Pertukaran data hanya lewat berkas (impor dan ekspor).
- Periode aktif 10/2026, login sebagai SUPERVISOR.
- Menu utama: Berkas, Persiapan, Daftar, Aktifitas, Laporan, Jendela, Bantuan.
- Modul di Penjelajah: Buku Besar, Kas Bank, Persediaan, Penjualan, Pembelian, Aset Tetap, Daftar, RMA, e-Faktur.
- Menu e-Faktur adalah alur lama (ekspor ke aplikasi e-Faktur DJP). Pajak nasional sudah berpindah ke Coretax; dukungan Coretax di ACCURATE 5 Standard **belum diketahui** (pencarian web tidak menemukan jawabannya).

## 3. Yang belum diketahui (menentukan arah)

| Pertanyaan | Mengapa penting |
|---|---|
| Apakah PT Ginnva **PKP**? (keputusan nomor 1 di dokumen Keuangan) | Bukan PKP: tidak perlu faktur pajak, Accurate untuk pajak tidak perlu. PKP: perlu faktur pajak dan lapor PPN lewat Coretax. |
| Apakah **harga di daftar harga sudah termasuk PPN**? | Menentukan dasar PPN dan tampilan laporan. |
| Bagaimana **SPT Masa PPN dan PPh** dilaporkan sekarang, dan oleh siapa? | Menentukan siapa pemakai dan bentuk laporan yang dibutuhkan. |
| Pajak apa yang paling memakan waktu atau paling sering salah? | Menentukan prioritas. |
| Status **lisensi** ACCURATE 5 ("Belum Teregistrasi")? | Kelayakan dipakai untuk pembukuan resmi. |
| Apakah ada **impor Excel/CSV** dan untuk transaksi apa? | Penentu terbesar alur Ginnva ke Accurate. |
| Apakah **Coretax** didukung di versi ini? | Penentu apakah bisa dipakai untuk faktur pajak. |
| Apakah berkas data sudah berisi data atau masih kosong? | Menentukan apakah perlu migrasi saldo awal. |

## 4. Pilihan yang dipertimbangkan

| Pilihan | Isi | Penilaian sementara |
|---|---|---|
| A. Tetap, ekspor ke akuntan | Ginnva pembukuan utama; akuntan menerima laporan dan jurnal | Termurah, tanpa pekerjaan baru. |
| B. Accurate sebagai "buku pajak" | Jurnal Ginnva diimpor ke Accurate untuk PPN, HPP, dan SPT | Masuk akal bila pajak jadi beban nyata dan Accurate 5 menyediakan impor. Lisensi sudah dimiliki (tanpa biaya software baru), tetapi harus teregistrasi. |
| C. Ganti keuangan Ginnva dengan Accurate | Pembukuan penuh pindah | **Tidak disarankan**: hubungan dengan booking, DP, komisi, dan persetujuan harus dibangun ulang, dan data ganda mudah selisih. |
| D. Bangun modul Pajak di Ginnva | Sudah dirancang di `RANCANGAN_ULANG_KEUANGAN.md` (pengaturan pajak, faktur pajak, rekap) | Relevan bila PKP dan Accurate 5 tidak mendukung Coretax. |

## 5. Daftar tangkapan layar yang dikumpulkan pemilik

Tiga yang paling menentukan (prioritas):
1. **Bantuan, Tentang**: versi, edisi, dan status registrasi.
2. **Menu Berkas dalam keadaan terbuka**: apakah ada Impor dan Ekspor.
3. **Aktifitas, Buku Besar, Jurnal Umum**: layar entri jurnal (kosong), untuk melihat kolom yang dibutuhkan.

Tambahan yang membantu:
- Menu **Persiapan** terbuka (pengaturan perusahaan, pajak, mata uang, periode).
- **Daftar, Akun**: bagan akun (daftar atau Excel).
- **Menu e-Faktur** terbuka (pengaturan, ekspor, versi format).
- **Laporan**: daftar laporan yang tersedia (neraca, laba rugi, buku besar, PPN).
- Daftar templat **Impor** (bila ada), dan layar entri **Penjualan**, **Pembelian**, **Persediaan**, **Aset Tetap**.

Jangan kirim data keuangan sungguhan, kata sandi, atau berkas `.gdb`. Tangkapan layar menu dan layar kosong sudah cukup; contoh isian cukup 2 sampai 3 baris yang disamarkan.

## 6. Pertanyaan untuk CFO dan Spv Finance

1. Apakah PT Ginnva sudah dikukuhkan sebagai PKP? Kalau ya, sejak kapan?
2. Apakah harga di daftar harga sudah termasuk PPN?
3. Apakah Ginnva menerbitkan faktur pajak untuk pelanggan, dan lewat apa (Coretax langsung atau software)?
4. Bagaimana SPT Masa PPN dan PPh (PPh 21, PPh 23, PPh final) dilaporkan sekarang, dan oleh siapa (internal atau akuntan eksternal)?
5. Software akuntansi apa yang dipakai sekarang? ACCURATE 5 Standard dipakai untuk apa dan oleh siapa, dan apakah sudah teregistrasi?
6. Pajak apa yang paling memakan waktu atau paling sering salah?

## 7. Langkah berikutnya

1. Pemilik meneruskan pertanyaan bagian 6 ke finance dan mengumpulkan tangkapan layar bagian 5.
2. Setelah keduanya ada: menilai apakah jurnal Ginnva bisa diekspor dalam format yang diterima impor Accurate, seberapa besar pekerjaan memetakan bagan akun, dan apakah alurnya layak dijalankan bulanan oleh staf keuangan.
3. Memutuskan A, B, atau D (bagian 4). **Sampai ada jawaban PKP dan hasil tangkapan layar, tidak ada perubahan di sistem Ginnva untuk pajak atau Accurate.**

## 8. Temuan: menu Berkas (tangkapan layar 2026-10-24)

Isi menu: Data Baru, Membuka Data, Menutup Data, Simpan dengan Alias, Cadangan Data, Pakai Data Cadangan, Perbaiki Data, **Review Akuntan** (ada submenu), **Impor Jurnal**, **Batal Impor Jurnal**, **Expor Impor Transaksi**, Buat Data Cabang Baru, **NPWP** (ada submenu), Keluar.

Artinya:
- **Ada Impor Jurnal**, dan ada **Batal Impor Jurnal** (membatalkan impor yang salah). Ini membuat opsi B (jurnal Ginnva diimpor berkala ke Accurate) tampak layak, dengan jaring pengaman bila impor salah. Format berkas yang diterima (Excel, CSV, atau lainnya) dan kolom yang wajib **belum diketahui**.
- **Expor Impor Transaksi** kemungkinan alat pertukaran transaksi antar-data Accurate (misalnya antar-cabang); belum diketahui formatnya dan apakah berguna untuk Ginnva.
- **Review Akuntan** dan **NPWP** punya submenu yang belum terlihat; perlu dibuka untuk melihat isinya.
- **Cadangan Data** dan **Pakai Data Cadangan** menunjukkan pemulihan ada, tetapi perlu rutinitas cadangan manual karena berkas data lokal.

Yang masih harus dilihat berikutnya:
1. **Dialog Impor Jurnal** (klik menunya, jangan impor apa pun): jenis berkas yang didukung, kolom, dan apakah ada tombol templat contoh.
2. Submenu **Review Akuntan** dan **NPWP**.
3. **Daftar, Akun** (bagan akun) untuk pemetaan ke bagan akun Ginnva.

## 9. Temuan: dialog impor, Review Akuntan, NPWP, dan Ekspor Impor Transaksi (tangkapan layar 2026-10-24)

1. **Dialog "Impor dari Perusahaan Lain"** (diduga dari menu Impor Jurnal; perlu dikonfirmasi): pilihan **Lokal** atau **Mesin lain**, dengan isian **Lokasi Database**. Artinya impor ini mengambil jurnal dari **basis data ACCURATE lain** (berkas `.gdb`), **bukan dari Excel atau CSV**.
2. **Review Akuntan:** Buat Salinan Akuntan, Batal Salinan Akuntan, Impor Perubahan Akuntan, Ekspor Perubahan untuk Klien. Alur klien dan akuntan **antar-ACCURATE**: klien membuat salinan, akuntan menyesuaikan, perubahan diekspor kembali. Berguna bila akuntan eksternal memakai ACCURATE; tidak terhubung ke Ginnva.
3. **NPWP:** pilihan **NPWP 15 Digit** dan **NPWP 16 Digit**. Ada dukungan format NPWP baru, petunjuk bahwa versi ini cukup mutakhir. Dukungan Coretax secara penuh **tetap belum diketahui**.
4. **Ekspor Impor Transaksi:** tab Ekspor Impor Data dan Histori Ekspor Impor; filter tanggal, status sumber (Masukan, Terhapus), nama pengguna, dan tipe transaksi (Bukti Jurnal Umum, Pembayaran, Penerimaan, Pindah Barang, Pajak, dan lainnya); tombol Ekstrak Data Terpilih dan Impor Data. Ini juga pertukaran **antar-data ACCURATE** (cabang atau mesin lain).

**Kesimpulan sementara:** semua fasilitas impor yang terlihat bersifat **ACCURATE ke ACCURATE**. Belum ada impor Excel atau CSV yang ditemukan. Dengan begitu, mengirim jurnal Ginnva ke ACCURATE secara otomatis **tidak mungkin lewat fitur resmi**; satu-satunya jalan adalah menulis langsung ke basis data `.gdb` ACCURATE, yang **tidak didukung dan berisiko merusak data atau lisensi**, jadi tidak direkomendasikan.

**Alternatif realistis (opsi A+):** Ginnva membuat **rekap bulanan** (mis. pendapatan per kategori dan toko, PPN bila PKP, kas dan bank, piutang, penyusutan) dengan nomor akun yang sudah dipetakan ke bagan akun ACCURATE, dalam Excel atau PDF. Staf finance memasukkan **satu jurnal umum ringkasan per bulan** secara manual di ACCURATE (sekitar belasan baris). Volumenya kecil, jadi masuk akal.

**Yang masih perlu dicari:**
1. Apakah ada impor **Excel atau CSV** di tempat lain: toolbar jendela Jurnal Umum, daftar Akun, Pelanggan, Barang; menu **Persiapan**; dan menu **Daftar**.
2. Apakah laporan ACCURATE bisa **diekspor ke Excel** (untuk arah sebaliknya, ACCURATE ke Ginnva atau akuntan).
3. **Bagan akun** (Daftar, Akun) untuk pemetaan.
4. Status lisensi dan jawaban finance (PKP, SPT, Coretax) tetap menjadi penentu.
