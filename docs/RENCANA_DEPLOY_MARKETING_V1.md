# Rencana Deploy Production: Marketing/Konten (Fase 1–5)

Dibuat 2026-10-28. Melanjutkan `RENCANA_DEPLOY_BOOKING_V2.md`. **Dokumen ini belum dijalankan.** Perintah untuk server cPanel Ginnva, user `ginnva`, folder production `ginnva-api` (perintah tidak diawali `cd`; terminal Anda sudah di sana). Fase 0 (atribusi lead) sudah ada di production sejak 2026-10-22; yang naik sekarang adalah Fase 1 sampai 5.

## 1. Keadaan dan cakupan

| Hal | Production sekarang | Yang akan naik |
|---|---|---|
| Kode API | sampai Fase 0 (kolom atribusi lead, Catat Lead) | `origin/development` (Fase 1–5) |
| Migrasi | sampai `2026_10_22_000001` | **8 migrasi baru** (bagian 3) |
| Web (`ginnva-web`) | Fase 0 (UTM, klik WhatsApp) di `main` | `development` ke `main`: JobPosting di /karier, Article dan SEO berita, poster hero 660 KB → 52 KB |
| Jadwal | seperti sekarang | + `leads:remind-stale` harian 08:00 |
| `.env` | tidak ada yang wajib | dua opsional: `LEAD_RESPONSE_SLA_HOURS` (bawaan 24), `PROMO_MAX_CAMPAIGNS_PER_WEEK` (bawaan 2) |
| Tes | n/a | seluruh suite hijau di dev, dijalankan per kelompok huruf (2306 tes); tes baru Fase 2–5 dijalankan per bagian |
| Mobile | tidak ikut | **tidak ikut deploy ini**; belum di-commit dan belum pernah dicoba di HP (bagian 9) |

Yang naik: pemilik dan hasil lead (Deal/Tidak Jadi) di tiga jenis lead, waktu respons, pengingat lead telat, Dasbor Lead; jadwal tayang dan teks alternatif banner, SEO berita, arsip Klaim & Bukti (internal); izin foto pelanggan, nominasi galeri dari booking; masa berlaku lowongan; persetujuan promo opt-in, jenis pesan Promosi/Informasi, batas kampanye per minggu, hitung klik banner.

## 2. Perubahan perilaku yang terlihat SEGERA setelah deploy

1. **Pengingat lead telat pertama (08:00 berikutnya).** Inquiry Produk dan Kemitraan lama yang masih berstatus Baru dianggap "belum ditindak" (kolom waktu respons baru kosong untuk data lama). Pemilik lead (tidak ada untuk data lama) atau semua staf bermenu akan menerima **satu notifikasi per jenis** berisi jumlah lead telat, tiap hari sampai ditindak. Hitung dulu angkanya (bagian 4 butir 4) dan rapikan lead lama bila banyak.
2. **Promosi berhenti sampai pelanggan menyetujui.** Semua pelanggan lama dimulai **tanpa** persetujuan promo. Menu Kirim Notifikasi sekarang punya Jenis Pesan (bawaan *Promosi*, hanya ke yang setuju). Pesan non-promosi harus dikirim sebagai *Informasi penting*. Saat ini belum ada pelanggan yang memakai aplikasi, jadi tidak ada promosi yang hilang.
3. **Tawaran ulasan Google ke semua pelanggan.** API sekarang mengembalikan "tawarkan ulasan Google" untuk semua sentimen. **Aplikasi lama (Play Store) masih memakai kalimat "Senang mendengarnya!"**, jadi pelanggan yang memberi sentimen netral/negatif akan melihat kalimat itu sebelum ulasan Google ditawarkan. Aman selama belum ada pelanggan aktif; sebelum aplikasi dipakai pelanggan, rilis build baru (teks sudah diganti di mobile).
4. **Panel admin berubah:** grup "Inquiry & Kemitraan" jadi grup pertama di Marketing/Konten dan berisi Dasbor Lead; menu baru Klaim & Bukti dan Izin Foto Pelanggan; form lead punya Penanggung Jawab, Hasil, Alasan Tidak Jadi; form banner, berita, lowongan punya kolom baru. **Staf dengan hak akses menu terbatas tidak otomatis melihat menu baru** (bagian 8 butir 1).
5. **Banner, berita, lowongan lama tetap tayang seperti sebelumnya** (jadwal dan batas berlaku kosong berarti tanpa batas). Lowongan lama mendapat tanggal terbit dari tanggal dibuatnya.
6. **Endpoint `/api/product-claims` tidak ada** (sengaja; klaim hanya arsip internal). Tampilan angka di halaman kampanye web tidak berubah.

## 3. Migrasi yang akan jalan

| Migrasi | Isi | Risiko |
|---|---|---|
| `2026_10_23_000001_add_lead_management_columns` | kolom pemilik, hasil, alasan, waktu respons di 3 tabel lead (nullable) | rendah |
| `2026_10_24_000001_add_banner_schedule_and_news_seo_columns` | kolom jadwal/alt di `carousels`, kolom SEO di `news` (nullable) | rendah |
| `2026_10_24_000002_create_product_claims_table` | tabel baru (kosong) | rendah |
| `2026_10_25_000001_create_marketing_consents_table` | tabel baru (kosong) | rendah |
| `2026_10_25_000002_add_consent_columns_to_case_studies` | kolom baru di `case_studies` **dan `vehicle_id`/`film_product_id` dijadikan boleh kosong** | **sedang**: satu-satunya yang mengubah kolom yang sudah ada (tabel kecil; data tidak berubah; sudah sukses di database dev yang sama jenisnya) |
| `2026_10_26_000001_add_validity_to_job_openings` | kolom tanggal terbit/berlaku + mengisi tanggal terbit lama dari tanggal dibuat | rendah |
| `2026_10_27_000001_add_promo_consent_and_banner_clicks` | kolom persetujuan di `customers` (bawaan tidak), kategori dan kunci kampanye di `customer_notifications` (bawaan `info`), hitung klik di `carousels` | rendah (menambah kolom di tabel notifikasi; lama menyesuaikan jumlah baris) |
| `2026_10_27_000002_add_promo_prompted_at_to_customers` | satu kolom nullable | rendah |

Semuanya memeriksa dulu apakah kolom/tabel sudah ada. Tidak ada yang menghapus data. Kebijakan RUNBOOK §3 dipenuhi: migrasi tidak destruktif, jadi cadangan manual tambahan tidak diperlukan (cron 03:00 sudah ada).

## 4. Sebelum deploy (persiapan, tidak menyentuh kode)

1. **Pastikan backup 03:00 hari itu ada** (lokal dan Drive):

```bash
find ~ storage -maxdepth 6 -name 'ginnva_backup_*.sql.gz' -mtime -1 -printf '%TY-%Tm-%Td %TH:%TM  %10s bytes  %p\n' 2>/dev/null | sort; rclone lsl gdrive-crypt: | sort -k2,3 | tail -n 3
```

2. **Cek cabang dan keadaan Git production:**

```bash
git branch --show-current && git status --short && git log --oneline -1
```

   Harapan: cabang `development`, `status` kosong. Kalau ada perubahan lokal yang tidak dikenal, **berhenti dan lapor**.
3. **Konfirmasi tidak ada pelanggan yang menunggu promosi** (aplikasi belum dipakai pelanggan), dan siapa yang akan mengirim promosi nanti harus tahu aturan Jenis Pesan (butir 2 bagian 2).
4. **Hitung lead lama yang akan dianggap telat** (hanya membaca):

```bash
php artisan tinker --execute="echo App\Models\ProductInquiry::where('status','new')->count().' inquiry produk, '.App\Models\PartnershipInquiry::where('status','new')->count().' kemitraan berstatus Baru';"
```

   Bila angkanya besar dan sebagian besar sudah tidak relevan, tindak lanjuti atau tutup dulu lewat panel supaya pengingat pertama tidak membingungkan. Pengingat tidak bisa ditarik setelah terkirim.
5. Pilih waktu sepi (setelah backup 03:00, sebelum pengingat 08:00 lebih aman), satu orang memantau.

## 5. Deploy API

```bash
git checkout development && git pull origin development && php artisan migrate --force && php artisan optimize:clear && php artisan optimize && php artisan filament:optimize && php artisan queue:restart
```

Lalu langsung:

```bash
php artisan migrate:status | tail -n 10; php artisan schedule:list | grep -i lead
```

Harapan: 8 migrasi baru berstatus `Ran`, dan `leads:remind-stale` terdaftar jam 08:00. Variabel `.env` opsional (`LEAD_RESPONSE_SLA_HOURS`, `PROMO_MAX_CAMPAIGNS_PER_WEEK`) tidak perlu diubah.

## 6. Deploy web (setelah API selesai)

Urutannya API lebih dulu; web membaca kolom baru dengan aman bila belum ada (JobPosting hanya muncul kalau API mengirim tanggal terbit). Untuk web, sesuai kebiasaan Anda, gabungkan `development` ke `main` (Vercel menerbitkan dari `main`). Perubahan bersih di web: JobPosting di `/karier`, Article dan judul/deskripsi SEO di `/news/<slug>`, poster hero yang diperkecil. Halaman kampanye kaca film **tidak berubah** (perubahan klaim yang sempat dibuat sudah dibatalkan).

## 7. Verifikasi setelah deploy (production, tanpa membuat data)

1. Endpoint publik:

```bash
curl -s -o /dev/null -w "stores %{http_code}\n" https://api.ginnva.id/api/stores; curl -s https://api.ginnva.id/api/job-openings | head -c 300; echo; curl -s -o /dev/null -w "carousels %{http_code}\n" https://api.ginnva.id/api/carousels; curl -s -o /dev/null -w "case-studies %{http_code}\n" https://api.ginnva.id/api/case-studies; curl -s -o /dev/null -w "product-claims (harus 404) %{http_code}\n" https://api.ginnva.id/api/product-claims; curl -s -o /dev/null -w "promo-preference tanpa login (harus 401) %{http_code}\n" https://api.ginnva.id/api/customer/promo-preference
```

   Harapan: 200 / 200 / 200, 404, 401; lowongan memuat `posted_at` dan `valid_through`.
2. Panel admin (login akses penuh), tiap halaman terbuka tanpa error 500: Dasbor Lead (ganti rentang tanggal), Inquiry Produk (daftar, Catat Lead Manual, edit satu lead), Kemitraan, Quotation (edit satu lalu simpan), Klaim & Bukti, Izin Foto Pelanggan, Galeri Pemasangan (daftar dan jendela Nominasikan dari Booking dibuka), Banner (form dan kolom Status Tayang/Klik), Berita (form), Lowongan Kerja (form, kolom Status), Kirim Notifikasi (ada Jenis Pesan), Pelanggan (kolom dan filter Setuju Promo).
3. Web setelah `main` terbit: `/karier` memuat data terstruktur (uji dengan Rich Results Test Google bila ada lowongan), `/news/<slug>` memuat Article, beranda memuat poster hero dengan cepat.
4. Aplikasi mobile lama: buka daftar booking dan satu booking; harus berjalan seperti sebelumnya.
5. Pantau Sentry dan `storage/logs/laravel.log` minimal 1 jam, lalu setelah run 08:00 berikutnya (pengingat lead pertama: cek notifikasi yang masuk ke staf wajar) dan 03:00 (backup tetap satu file per hari).

`leads:remind-stale` tidak punya mode uji, jadi jangan dijalankan manual di production; biarkan jadwal 08:00.

## 8. Setelah server stabil (dikerjakan admin di panel)

1. **Hak akses menu:** untuk staf dengan akses terbatas, centang menu baru di pengaturan pengguna/peran: *Dasbor Lead*, *Klaim & Bukti Produk*, *Izin Foto Pelanggan*.
2. **Klaim & Bukti:** jalankan `php artisan claims:seed-drafts` (mengisi draft dari angka di halaman kampanye), lalu lampirkan bukti atau catatan sumber dan tanggalnya, dan setujui. Arsip ini tidak mengubah tampilan web.
3. **Lead:** tetapkan siapa yang jadi penanggung jawab lead, dan biasakan menandai Deal/Tidak Jadi beserta alasannya (dasar Dasbor Lead).
4. **Banner dan lowongan:** isi jadwal dan teks alternatif banner, serta batas berlaku lowongan.
5. **Aturan Kirim Notifikasi:** pesan promosi dipilih sebagai *Promosi*, pemberitahuan penting sebagai *Informasi penting*.
6. **Serah terima:** setelah build mobile baru dirilis, staf menanyakan izin foto pelanggan di serah terima dan memastikan plat nomor tertutup stiker sebelum menominasikan foto ke galeri.

## 9. Mobile (terpisah, setelah server stabil)

Perubahan mobile Marketing/Konten (belum di-commit, belum pernah dijalankan di HP): layar Catat Lead dan atribusi Install Referrer (Fase 0); penanggung jawab dan hasil lead serta layar Lead Masuk (Fase 1; istilah Deal/Tidak Jadi); kotak centang izin foto di serah terima dan teks tawaran ulasan Google yang netral (Fase 3); kartu dan pertanyaan persetujuan promo, serta hitung klik banner (Fase 5); perbaikan chip filter yang terhimpit.

1. Developer meng-commit.
2. Bangun preview internal yang menunjuk ke **server dev**, jalankan uji HP: Catat Lead, ubah pemilik/hasil di detail Lead Quotation, layar Lead Masuk (ubah status Baru/Sudah Dihubungi), serah terima dengan dan tanpa izin foto, pertanyaan promo muncul sekali lalu tidak muncul lagi, saklar promo di layar Notifikasi, ketuk banner menambah angka Klik di panel, tawaran ulasan Google muncul untuk sentimen apa pun dengan teks netral.
3. Rilis produksi bertahap. Server harus sudah di production lebih dulu (sudah, setelah bagian 5).

## 10. Jalur mundur

- **Mundur kode saja:** migrasi hanya menambah (kecuali kolom yang dijadikan boleh kosong di `case_studies`), jadi kode lama mengabaikan kolom baru:

```bash
git checkout <commit-production-sebelumnya> && php artisan optimize:clear && php artisan optimize && php artisan filament:optimize && php artisan queue:restart
```

  Catat commit production sebelum deploy dengan `git log --oneline -1` (bagian 4 butir 2). Jalur ini belum pernah dilatih. Akibatnya: menu baru hilang, pengingat lead berhenti; data yang sudah dicatat (hasil lead, izin foto, persetujuan promo) tetap tersimpan di kolomnya.
- **Pulihkan database dari backup** hanya bila data rusak (bukan karena kode); `migrate:rollback` tidak dipakai (RUNBOOK §3).
- Notifikasi yang sudah terkirim tidak bisa ditarik.

## 11. Risiko dan hal yang masih terbuka

- **Pengingat lead pertama bisa terasa ramai** bila banyak lead lama berstatus Baru (bagian 4 butir 4).
- **Persetujuan promo hanya lewat aplikasi** dan promosi push baru bisa menjangkau pelanggan setelah build baru dirilis dan pelanggan memilih "Ya".
- **Plat nomor bergantung pada staf** (stiker lalu centang); sistem tidak bisa memeriksa isi foto.
- **JobPosting dan Article** belum terbukti terbaca Google; periksa dengan Rich Results Test setelah naik.
- **Core Web Vitals resmi belum diukur** (PageSpeed menolak karena kuota); pengukuran lab hanya sebagian.
- **Suite penuh belum dijalankan ulang** setelah Fase 2–5 (hanya tes terkait tiap bagian); `php artisan test` penuh juga masih berhenti diam-diam di server dev (penyebab belum diketahui), jadi suite dijalankan per kelompok.
- **Memori server production:** MySQL pernah restart sendiri dan swap hampir penuh (lihat rencana Booking); fitur baru menambah sedikit beban.
- Yang sengaja tidak dibuat: banner bersegmen, persetujuan promo dicatat staf, promosi lewat WhatsApp otomatis, foto sebelum/sesudah di galeri web, skema Service, alur tinjau-terbit berita.
