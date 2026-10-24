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

## Temuan pass 4 (menu Daftar: Mata Uang, Daftar Akun, Buku Besar, Kas & Bank, Pelanggan; berkas ExportFile.xls)

Dibaca 2026-10-24 dari 30 tangkapan layar dan `ExportFile.xls` (hasil Expor Daftar Akun). Semua data di sini adalah **data bawaan template ACCURATE**, bukan data Ginnva.

| Fitur ACCURATE | Di Ginnva sekarang | Relevansi untuk Ginnva | Catatan |
|---|---|---|---|
| **Daftar Akun**: ±110 akun template (induk-anak lewat "Bagian dari Akun", tipe akun: Kas/Bank, Akun Piutang, Persediaan, Aktiva Tetap, Akumulasi Penyusutan, Hutang, Ekuitas, Pendapatan, HPP, Beban, dll.), filter Non Aktif, **Impor dari File**, Expor Excel, Print. | Bagan akun 88 akun di `ChartOfAccountSeeder` (1100-8100), seed tetap | Sudah sesuai | Ginnva lebih ringkas dan sesuai bisnis. Akun template yang **tidak ada** padanannya di Ginnva dan patut dipertimbangkan Spv Finance: **Biaya Perjalanan Dinas, STNK/KIR/Pajak Kendaraan, Biaya Retribusi, Pendapatan Jasa Giro, Pajak Jasa Giro, Biaya Adm Bank**. Cocokkan dengan 6xxx/7xxx Ginnva sebelum disimpulkan kurang. |
| **Akun baru**: tipe akun, nomor, nama, non-aktif, bagian dari akun (induk), saldo awal per tanggal, tab Catatan. | Master akun di panel Keuangan | Sudah sesuai | Saldo awal per akun lewat modul yang ada. |
| **Impor Akun dari file** (pemetaan kolom, format tanggal, pemisah ribuan, pratinjau data sumber, tombol Import Data) | Impor Produk dengan riwayat; tidak ada impor akun | Rendah | Pola pemetaan kolom dan pratinjau patut ditiru **bila** ada impor baru (mis. Luas Kaca). Akun tidak perlu impor, jumlahnya kecil. |
| **Expor ke Excel** ada di setiap laporan (tombol Expor, Email, Print, Simpan, Buka, Memorize, Desain, Cari Kata, Ubah Tampilan). `ExportFile.xls` = Excel 97 biner, berisi judul laporan, tabel, dan **ikut menyertakan pembatas halaman cetak** (baris "ACCURATE Accounting System Report / Cetak di ... / -1.0" di tengah data). | Ekspor Laporan (f11) ditunda pemilik | Ini membuktikan kebutuhan | Hasil ekspor Accurate tidak bersih untuk diolah (angka sebagai teks "0,00", header halaman di tengah). Ekspor Ginnva sebaiknya: satu header, angka numerik, tanpa pembatas halaman. Ikut keputusan f11 (jangan bangun sampai diminta). |
| **Bukti Jurnal Umum**: nomor voucher otomatis, tanggal, deskripsi, **Multi Mata Uang**, grid Akun/Debit/Kredit/Memo, total debit dan kredit, **Transaksi berulang**, **Salin Transaksi**, tombol Simpan & Baru / Simpan & Tutup, filter tipe (Bukti, Barang Roll Over, **Akhir Periode**, **Depresiasi Aktiva**, **Pembayaran Upah**). | Jurnal otomatis dari transaksi; **tidak ada form jurnal manual** (keputusan: Keuangan simple, bukan double-entry penuh) | **Layak, kecil:** jurnal penyesuaian manual untuk akuntan/Spv Finance (koreksi, akrual, penyusutan) dengan validasi debit = kredit, terkunci pada periode tutup, dan tercatat di Histori Aktivitas. | Terhubung dengan kandidat 1 (peran Akuntan). Perlu keputusan pemilik: apakah jurnal manual dibuka. **Transaksi berulang** dan **Salin Transaksi** berguna untuk pengeluaran rutin (kandidat 4). |
| **Histori Buku Besar**: filter tipe sumber, no. sumber, akun, tipe akun, pelanggan/pemasok, rentang tanggal; kolom tanggal, sumber, no. sumber, akun, keterangan, debit, kredit. | Buku besar/jurnal per akun di Keuangan | Sudah sesuai; **periksa** filter "pelanggan/pemasok" | Pastikan Ginnva bisa menelusuri entri jurnal ke dokumen sumber dan ke pelanggan/pemasok. |
| **Saldo Akun** per periode 1-12 dan **Anggaran Akun** (anggaran per akun per bulan, setahun, dengan saldo awal) | Tidak ada anggaran | **Layak, nilai sedang:** anggaran bulanan per akun beban (iklan, listrik, sewa, transport) dan perbandingan realisasi vs anggaran di laporan. | Relevan untuk CFO mengendalikan biaya 3+ toko. Butuh keputusan pemilik (siapa mengisi anggaran, tingkat akun). |
| **Buku Bank**: pilih bank, rentang tanggal, saldo awal "As of", kolom Pemasukan (Dr), Pengeluaran (Cr), Saldo, **Terekonsil**. | Kas/Bank dengan mutasi; Rekonsiliasi Bank via BankStatementLine | Sudah sebagian | Kolom **Terekonsil** per baris di buku bank adalah ide baik untuk dipastikan ada di Ginnva. |
| **Pembayaran / Penerimaan lain** (Other Payment, Other Deposit): kas/bank asal, no. voucher, no. cek, **Cek Kosong**, memo, penerima (payee), jumlah, "Say" (terbilang), rincian per akun; filter "Kosong". | Pengeluaran (dengan approval berjenjang) dan pemasukan lain | Sudah sesuai | Dua hal kecil: **terbilang** (jumlah dalam kata) di bukti pengeluaran cetak, dan **no. cek/bilyet**. Opsional. |
| **Pelanggan**: alamat, **alamat pajak** terpisah, kota/provinsi/kode pos, telepon, faks, **personal kontak**, email, web; tab **Termin** (termin bayar, **batasan piutang** berdasarkan hari faktur beredar dan nilai maksimum), mata uang, saldo awal, pesan, cetak statement pelanggan; tab **Penjualan** (Pajak 1/2, "harga termasuk pajak", **NPWP**, **NIK**, tipe pajak, **tipe pelanggan**, **tingkatan harga jual**, **default diskon**); tab **Kontak** (banyak kontak per pelanggan); Catatan; **Kolom Tambahan** (10 karakter, 3 angka, 2 tanggal); Impor dari File; **Ekspor e-Faktur**. | Pelanggan dengan telepon, email, kendaraan, poin, referral; tanpa NPWP, tanpa batas piutang | **Sebagian layak.** Untuk **pelanggan perusahaan/armada** (B2B): NPWP/NIK, alamat pajak, kontak ganda, termin bayar, batas piutang. Untuk pelanggan walk-in tidak perlu. | Terikat keputusan PKP dan apakah Ginnva punya pelanggan korporat/armada. Tanyakan ke CCO. **Kolom Tambahan** generik = tidak (kompleks). |
| **Tingkatan harga jual** per pelanggan dan **Default diskon** per pelanggan | Price Rule (harga per toko/kategori) | Periksa | Mirip harga khusus pelanggan korporat; hanya bila ada kontrak armada. |
| **Mata Uang**: IDR saja, form nama, nilai tukar, negara, simbol | Rupiah saja | Tidak perlu | Pelanggan asing (+61) cukup nomor telepon, tanpa mata uang asing. |

Catatan: entri uji lain (Pelanggan Umum 1000, Bukti Jurnal 1000, Bank 1000.03) adalah bawaan instalasi baru, bukan data Ginnva.

### Tambahan kandidat dari pass 4 (belum ada keputusan bangun)
8. **Jurnal penyesuaian manual** (tergabung dengan kandidat 1, peran Akuntan).
9. **Anggaran bulanan per akun beban** dan laporan realisasi vs anggaran.
10. **Data pelanggan korporat** (NPWP/NIK, alamat pajak, banyak kontak, termin, batas piutang), hanya bila ada pelanggan armada/korporat.
11. **Terbilang** dan **no. bilyet** di bukti pengeluaran cetak; kolom **Terekonsil** di buku bank (periksa dulu).
12. **Ekspor Excel bersih** (satu header, angka numerik) saat f11 dibuka lagi.

## Temuan pass 5 (menu Daftar: Penjualan, Otorisasi Pengembalian Barang/RMA, Pemasok)

Dibaca 2026-10-24 dari 36 tangkapan layar. Semua layar kosong (instalasi baru); yang dinilai adalah struktur dan alur.

**Rantai penjualan Accurate:** Penawaran → Pesanan → Pengiriman → Faktur → Penerimaan (pembayaran), dengan Retur dan RMA di samping. Tiap dokumen bisa dibuat dari dokumen sebelumnya ("Pilih Penawaran/Pesanan/Pengiriman/Uang Muka/Klaim"). Ginnva memakai rantai yang lebih pendek: Quotation → Booking → Invoice → Pembayaran (DP, pelunasan, refund).

| Fitur ACCURATE | Di Ginnva sekarang | Relevansi untuk Ginnva | Catatan |
|---|---|---|---|
| **Penawaran Penjualan**: no. penawaran, tanggal, pemesan, alamat, item (qty, harga, diskon %, pajak), status Menunggu/Mengantri/Terproses/Ditutup, diskon, biaya kirim, deskripsi; tombol **Jadikan Pesanan**; **Get from Memorize** (pakai templat tersimpan). | Quotation dengan konversi ke booking | Sudah sesuai | **Templat/Memorize** (paket yang sering dipakai) sebanding dengan paket layanan di Ginnva; sudah ada lewat pricelist. |
| **Pesanan Penjualan**: no. PO pelanggan, tanggal kirim, FOB, termin, **Uang Muka (DP) dengan Akun DP**, "Uang Muka 0 / Terpakai 0 / Sisa 0", Salin/Simpan Transaksi. | Booking dengan DP (akun 2140), pelunasan saat selesai | Sudah sesuai | Tampilan ringkas **DP / terpakai / sisa** di dokumen adalah pola bagus; periksa Ginnva menampilkan sisa DP dengan jelas di detail booking dan invoice. |
| **Pengiriman Pesanan** (Delivery Order) dengan nomor seri, filter "hanya yang belum ditagih" | Tidak relevan (jasa pasang, bukan kirim barang) | Tidak perlu | Setara terdekat: **Berita acara serah terima** kendaraan. Periksa apakah SPK/serah terima sudah ada dan tercetak. |
| **Faktur Penjualan**: kolom Umur, Down Payment, Nilai Terbayar, Diskon, **PPh Ps. 23**, Retur, **Owing** (sisa); tab **Uang Muka**; termin (C.O.D.); **Transaksi berulang**; tombol **Bayar** langsung dan **Terima Bayaran/Terima Pajak** (bukti potong PPh); "Hanya yang belum lunas". | Modul Invoice (V1 dokumen), piutang, pembayaran per booking | Sebagian | Kolom **Umur** dan **Sisa (Owing)** dengan filter belum lunas = pola piutang yang berguna (periksa laporan umur piutang Ginnva). **PPh 23** hanya untuk pelanggan korporat (dipotong klien), terkait pelanggan B2B pass 4. Tombol **Bayar** langsung dari faktur: periksa alur Ginnva. |
| **Retur Penjualan** wajib menunjuk faktur asal; diskon; tanpa persetujuan | **Refund** dengan approval full-access (lebih ketat) | Ginnva lebih kuat | |
| **Penerimaan Penjualan** (Cust. Receipt): bank/kas, no. dan tanggal cek, kosong, **Pembayaran Pajak**, jumlah, **Alokasi otomatis** ke beberapa faktur, saldo kredit, kelebihan bayar, diskon pembayaran lebih awal, filter void dan **belum direkonsiliasi**, kolom **Terekonsil**. | Pembayaran per booking, rekonsiliasi bank (BankStatementLine) | Sebagian | **Satu pembayaran dialokasikan ke beberapa faktur** relevan untuk pelanggan korporat/armada yang bayar sekaligus. Walk-in tidak perlu. Diskon bayar cepat: tidak. |
| **Otorisasi Pengembalian Barang (RMA)**: Daftar Klaim Pelanggan (status Terima, Batalkan, Diperbaiki, Digantikan, Dikembalikan); form RMA (no., tanggal, **perkiraan selesai**, pelanggan, barang, nomor seri, no. faktur, **deskripsi masalah**); **Aktifitas Proses Klaim** (teknisi, **aksi**: batal/perbaiki/ganti/kembalikan, barang pengganti, komponen/servis yang dipakai, gudang stok/gudang rusak). | **Garansi dan Klaim Garansi** (kode garansi, status klaim), komisi teknisi | **Sangat relevan sebagai pembanding.** Alur klaim Ginnva sebaiknya punya: tanggal perkiraan selesai, deskripsi masalah, teknisi pelaksana, aksi hasil (perbaiki/ganti/tolak/batal), dan **bahan yang terpakai** untuk penggantian (mengurangi roll). | Periksa apakah Klaim Garansi sudah mencatat teknisi, aksi, bahan terpakai ke stok, dan perkiraan selesai. Kandidat terbaik dari pass ini. |
| **Pemasok**: alamat, **alamat pajak**, kontak ganda, termin, mata uang, saldo awal, **default keterangan**, pajak 1/2, **NPWP pemasok, No. PKP**, tipe pajak, catatan, kolom tambahan; filter **tipe barang**; Impor dari File; Ekspor e-Faktur. | Pemasok di Master Data; pembelian film dan hutang | Sebagian | **NPWP dan status PKP pemasok** menentukan PPN masukan bisa dikreditkan (terikat PKP, pass 3). **Filter tipe barang** pemasok (film PPF vs WF vs perlengkapan) berguna. Kolom tambahan generik: tidak. |
| **Kolom Tambahan** (10 teks, 3 angka, 2 tanggal) di Pelanggan dan Pemasok | Kolom tetap | Tidak perlu | Fleksibilitas ini yang membuat sistem sulit dipelajari. |
| **Status dokumen** Menunggu / Mengantri / Terproses / Ditutup | Status booking dan invoice | Sudah sesuai | |

### Tambahan kandidat dari pass 5 (belum ada keputusan bangun)
13. **Klaim Garansi lengkap** (perkiraan selesai, deskripsi masalah, teknisi, aksi hasil, bahan terpakai ke stok). Periksa dulu apa yang sudah ada.
14. **Ringkasan DP: dibayar / terpakai / sisa** di detail booking dan invoice (periksa dulu).
15. **Umur piutang dan sisa (owing)** dengan filter "belum lunas" di Invoice (periksa dulu).
16. **Satu pembayaran dialokasikan ke banyak invoice** untuk pelanggan korporat (hanya bila ada armada, bersama kandidat 10).
17. **NPWP/status PKP pemasok** (bersama keputusan PKP).

Ditolak: pengiriman barang, nomor seri, FOB/ship via, diskon bayar cepat, kolom tambahan generik.

## Berikutnya
Menu **Daftar** sisanya (Pembelian, Barang dan Jasa, Persediaan, Aktiva Tetap, Transaksi berulang, Salinan Transaksi, Daftar Lain), menu **Aktifitas**, menu **Laporan**, **Aktifitas** (Jurnal Umum, Kas Bank, rekonsiliasi), **Penjualan**, **Pembelian**, **Aset Tetap**, **Persediaan**, dan **e-Faktur**. Hal yang masih dicari: daftar laporan keuangan dan pajak, dan apakah laporan bisa diekspor ke Excel.
