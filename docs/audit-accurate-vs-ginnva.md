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

## Temuan pass 2 (menu Berkas: Review Akuntan, Ekspor Impor, Cadangan, Cabang)

| Fitur ACCURATE | Di Ginnva sekarang | Relevansi untuk Ginnva | Catatan |
|---|---|---|---|
| **Salinan Akuntan dengan "Periode Pembatas"**: akuntan mengerjakan transaksi pada atau sebelum periode pembatas, klien melanjutkan transaksi sesudahnya; perubahan akuntan dikirim kembali (XML) dan digabung. Akuntan memakai "edisi Akuntan" dengan akun bawaan "akuntan". | Satu basis data; **Tutup Periode** mengunci bulan; tidak ada peran akuntan | **Layak, tanpa menyalin data.** Karena Ginnva satu basis data, cukup **peran "Akuntan"**: lihat semua, dan membuat dan memposting **jurnal penyesuaian** hanya pada periode yang masih terbuka, sambil klien terus bekerja di periode berjalan. Pemisahan tugas dan Histori Aktivitas sudah ada. | Kandidat paling bernilai dari pass ini. Perlu keputusan pemilik: apakah akuntan eksternal akan diberi akses panel. |
| **Riwayat Ekspor Impor** (nama, tanggal, waktu, pengguna, status, pesan) dan tombol **Munculkan Error Log** | Pola serupa ada di **Riwayat Impor Produk** (detail baris yang dilewati) | Sudah sejalan | Terapkan pola yang sama pada setiap impor baru (mis. Luas Kaca, Aset). |
| **Preferensi Ekspor Impor**: sandi enkripsi, folder default, pemetaan pelanggan, gudang, dan departemen | Tidak ada; tidak ada pertukaran antar-basis data | Tidak perlu | Hanya relevan untuk multi-basis data. |
| **Data Cabang Baru** (ekspor pelanggan dan pemasok ke berkas cabang) | Cabang baru cukup dibuat sebagai toko di Master Data | Tidak perlu | |
| **Cadangan Data** manual (nama berkas `.gbk`), **Pakai Data Cadangan**, **Perbaiki Data** | Cadangan otomatis harian 03:00 ke lokal dan Drive; pemulihan lewat prosedur server (DRP) | Cadangan sudah lebih kuat; **status cadangan tidak terlihat di panel** | Kandidat kecil: kartu **"Cadangan terakhir: tanggal, jam, ukuran"** untuk akses penuh (kepercayaan pemilik). |
| **Simpan dengan Alias** | Tidak relevan | Tidak perlu | |
| **NPWP 15 dan 16 digit** | Kolom NPWP ada di data personalia | Kecil | Sudah tercatat di pass 1. |

Catatan dari layar Ekspor Impor Transaksi: ada satu entri uji, **Bukti Jurnal Umum nomor 1000** (24/10/2026, dibuat SUPERVISOR). Itu entri uji dari instalasi baru, bukan data Ginnva.

## Temuan pass 3 (Preferensi lanjutan, Profil Pengguna, Persiapan Singkat, Rancangan Formulir)

Dibaca dari tangkapan layar 2026-10-24 (Preferensi: Set Rancangan, Pengingat, Penjualan, Pembelian, Pembiayaan Pesanan, Set Huruf, Perpajakan, Lain-lain, Skin; menu Persiapan; Edit Profil Pengguna 8 tab; Ubah Kata Kunci; Persiapan Singkat 7 langkah; Rancangan Formulir).

| Fitur ACCURATE | Di Ginnva sekarang | Relevansi untuk Ginnva | Catatan |
|---|---|---|---|
| **Profil Pengguna: hak akses per aktivitas** dengan 6 kolom (Buat, Ubah, Hapus, Laporan, Lihat, Daftar) di 8 tab (Penjualan, Pembelian, Barang/Jasa/Persediaan, Buku Besar & Lain-lain, Bank & Aktiva Tetap, Laporan Tersimpan, Laporan Terdesain, Transaksi Tersimpan), ditambah centang aksi khusus: **Merubah harga jual, Melebihi batas (kredit), Cetak ulang faktur, Ubah akun barang, Rekonsiliasi Bank, Ekspor Data, Impor Data**. Ada tombol **Salin Hak** (salin dari pengguna lain). | Akses per **peran** dan per **menu** (centang menu eksplisit), Filament policy per resource; aksi sensitif (refund, referral) lewat approval | **Sebagian layak.** Aksi sensitif sebagai izin tersendiri sudah sebagian ada (approval). Yang belum: **izin ekspor/impor data** dan **izin ubah harga jual** sebagai hak terpisah dari menu. **Salin Hak** (salin pengaturan akses dari karyawan lain) berguna untuk 10-99 karyawan. | Kandidat kecil: tombol "Salin akses dari pengguna lain". Izin ekspor terpisah ikut tema pengerjaan ulang Ekspor Laporan (ditunda pemilik). |
| **Laporan Tersimpan/Terdesain dibatasi per pengguna** | Laporan dibatasi per menu | Tidak perlu | Ginnva tidak punya pembangun laporan bebas. |
| **Pengingat** saat aplikasi dibuka: barang untuk dipesan, nomor seri kadaluarsa, piutang jatuh tempo, diskon piutang, belum direkonsiliasi (penerimaan/pembayaran), hutang jatuh tempo, **transaksi berulang** (jurnal, pembayaran, penerimaan, faktur), masing-masing dengan "N hari sebelum jatuh tempo". | Notifikasi (kotak masuk, push) sudah dibangun; piutang dan hutang ada modulnya | **Layak.** Pengingat **piutang dan hutang jatuh tempo** dan **stok minimum** sudah wajar di Notifikasi; periksa apakah semuanya terkirim ke CFO/Spv Finance dengan jendela "N hari sebelum". **Transaksi berulang** (sewa, langganan, gaji tetap) relevan untuk pengeluaran rutin. | Periksa dulu apa yang sudah ada di Notifikasi; kemungkinan hanya perlu pengingat jatuh tempo hutang dan pengeluaran berulang. |
| **Penjualan**: peringatan jika harga jual di bawah HPP; "Gunakan Pelanggan terakhir"; peringatan nomor seri sudah pernah dijual; konfirmasi sebelum membuat faktur; pesan saat pembayaran berlebih; Retur: batasi ke faktur belum lunas. | Harga lewat Price Rule; refund lewat approval | **Sebagian layak.** Peringatan **harga di bawah HPP** relevan untuk diskon manual; peringatan **pembayaran berlebih** relevan untuk DP dan pelunasan. Nomor seri tidak relevan (PPF dilacak per gulungan dan garansi). | Cek: apakah input pembayaran menolak atau memperingatkan kelebihan bayar; apakah diskon manual menampilkan margin. |
| **Pembelian**: pemasok terakhir, peringatan nomor seri, konfirmasi, "Tampilkan Informasi Biaya"; **Penerimaan Barang: biaya penerimaan diperbarui oleh Tagihan** dan **Akun Selisih barang belum tertagih**. | Penerimaan roll/film dan hutang ada; tidak ada pemisahan "terima barang" vs "tagihan" | Rendah | Pemisahan penerimaan barang dan tagihan pemasok relevan hanya bila pembelian film sering datang sebelum faktur. Tanyakan Spv Finance apakah itu terjadi. |
| **Pembiayaan Pesanan** (Akun penampung selisih biaya) | Tidak ada | Tidak perlu | Fitur manufaktur/proyek. |
| **Perpajakan**: kode dalam nomor faktur pajak; **angka untuk setiap faktur: dibulatkan ke bawah**, nilai 1; akun **Pajak Penghasilan**. | PPN hanya rincian (tarif efektif 11% dan DPP 11/12); modul Pajak dirancang tapi belum dibangun | Ikut keputusan PKP | **Pembulatan pajak per faktur** (ke bawah) adalah titik yang perlu diputuskan bersama Spv Finance supaya angka PPN Ginnva sama dengan e-Faktur. Menunggu jawaban status PKP. |
| **Lain-lain**: **Umur Faktur** (rentang 30 hari; dihitung dari jatuh tempo atau tanggal faktur), format tanggal dan angka, bahasa, "Simpan posisi tabel". | Aging piutang ada di laporan | Kecil | Pastikan aging piutang/hutang memakai kelompok umur yang bisa dipahami (0-30, 31-60, dst.) dan jelas dihitung dari mana. |
| **Set Rancangan / Rancangan Formulir**: 24 jenis formulir (Faktur Penjualan, Pesanan, Pengiriman, Retur, Faktur Pembelian, Penerimaan Barang, Pindah Barang, **Faktur PPN lokal/asing**, Pembayaran, Penerimaan, Bukti Jurnal Umum, Penawaran Penjualan, Permintaan Barang, RMA, Nomor Seri, **PPh Ps.23**, Proses Klaim), tiap jenis punya beberapa rancangan dan tema (**A4 Form, A5 Form, Simple**), dengan editor desain. | Dokumen cetak (invoice, SPK, slip, penawaran) tetap, tanpa editor desain | **Tidak perlu editor.** Yang relevan: **bukti potong PPh 23** dan **faktur pajak** sebagai dokumen. Pilihan ukuran A4/A5/nota kecil bisa dipertimbangkan untuk toko. | Editor formulir bebas = tidak (terlalu kompleks, itu alasan Majoo ditinggalkan). |
| **Persiapan Singkat** (wizard 7 langkah): bahasa; tipe Standar/Mahir; info perusahaan dan mata uang; kas/bank beserta saldo; pelanggan dan saldo piutang awal; pemasok dan saldo hutang awal; **metode persediaan (Rata-rata atau FIFO, tak bisa diganti setelah transaksi)**; barang dan stok awal; jasa. Ringkasan akhir. | Tidak ada wizard; saldo awal lewat modul masing-masing (Wizard Saldo Awal f51 sudah diputuskan ditunda) | Rendah | Pola "buat checklist data awal" hanya relevan bila ada pengguna baru dari nol; Ginnva sudah berjalan. Peringatan "tidak bisa diganti setelah transaksi" untuk keputusan permanen patut ditiru pada pengaturan sensitif. Akun kas bawaan wizard: Kas Kecil, Kas Besar, Bank, Kas Transfer, Deposito (Ginnva: semua ke 1101, masih dalam keputusan pengerjaan ulang Keuangan). |
| **Ubah Kata Kunci** (lama, baru, konfirmasi) | Ada di profil dan lupa kata sandi lewat OTP | Sudah sesuai | |
| **Skin/tema, set huruf** | Satu tampilan | Tidak perlu | |

Catatan: nama pengguna bawaan **SUPERVISOR** bertingkat **Admin** dengan semua hak aktif adalah akun bawaan instalasi baru; bukan data Ginnva. Kata sandi tidak dibaca dari layar.

### Ringkasan kandidat dari pass 1-3 (belum ada keputusan bangun)
1. Peran **Akuntan** (lihat semua, jurnal penyesuaian pada periode terbuka). Butuh keputusan: apakah akuntan eksternal diberi akses.
2. Kartu **Cadangan terakhir** di panel untuk akses penuh.
3. Tombol **Salin akses dari pengguna lain** pada pengelolaan pengguna.
4. Pengingat **hutang jatuh tempo** dan **pengeluaran berulang** (cek dulu cakupan Notifikasi).
5. Peringatan **harga di bawah HPP** dan **pembayaran berlebih** (cek dulu perilaku yang ada).
6. **Pembulatan pajak per faktur** disepakati dengan Spv Finance (menunggu status PKP).
7. Pola **riwayat impor dengan error log** pada setiap impor baru.

## Berikutnya
Menu **Laporan**, **Aktifitas** (Jurnal Umum, Kas Bank, rekonsiliasi), **Penjualan**, **Pembelian**, **Aset Tetap**, **Persediaan**, dan **e-Faktur**. Hal yang masih dicari: daftar laporan keuangan dan pajak, dan apakah laporan bisa diekspor ke Excel.
