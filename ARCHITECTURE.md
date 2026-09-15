# Ginnva — Arsitektur & Keputusan Teknis

> Konsolidasi keputusan desain penting yang sebelumnya cuma ada di komentar kode tersebar atau riwayat sesi asisten AI (audit framework 2026-09-14, "Dokumentasi arsitektur & keputusan teknis"). Untuk SOP operasional (deploy/rollback/backup) lihat [RUNBOOK.md](RUNBOOK.md); untuk prosedur insiden besar lihat [DRP.md](DRP.md).

## 1. Gambaran Sistem

Platform operasional bisnis instalasi Window Film & Paint Protection Film (PPF) multi-cabang: booking & penjadwalan, inventaris roll film per meter, garansi per kendaraan, keuangan & jurnal umum, payroll/absensi, promo/loyalti & referral.

3 basis kode terpisah:
- `ginnva-api` — backend Laravel 12 + panel admin Filament v3.3.54.
- `ginnva-mobile` — Expo/React Native, dipakai staff & customer.
- `ginnva-web` — situs publik Next.js.

Guard terpisah: `web` (Filament, sesi Laravel), `api` (staff & partner, JWT — `tymon/jwt-auth`, **bukan Sanctum**), `customer` (customer mobile app, JWT terpisah).

## 2. Pola Store-Scoping (Isolasi Multi-Toko)

Aturan yang berlaku di HAMPIR SEMUA model bertransaksi:

```php
$storeId = ($user?->isFullAccess() ?? false) ? null : $user?->store_id;
$query->when($storeId, fn ($q) => $q->where('store_id', $storeId));
```

`null` = lintas-toko (cuma full-access/super_admin/direksi); user lain WAJIB dikunci ke `store_id` akunnya sendiri.

**Dua lapis proteksi berjalan bersamaan** (disengaja, bukan redundan tanpa alasan):
1. Manual di tiap `Resource::getEloquentQuery()`/Controller — pola di atas.
2. Eloquent **Global Scope** (`App\Models\Scopes\StoreScope` + trait `App\Models\Concerns\HasStoreScope`) — jaring pengaman untuk query LANGSUNG ke model dari widget/report/service yang lupa scoping manual. Dipasang di 14 model (Booking, MaterialMemo, PurchaseRequest, Attendance, LeaveRequest, WarningLetter, BlockedDate, FinanceTransaction, Payable, Receivable, StoreReview, Technician, JournalEntry, StockWriteOff).

**Model yang SENGAJA TIDAK dipasangi Global Scope** — jangan tambahkan tanpa desain ulang:
- `Asset`, `ScrollCode` — `store_id = NULL` punya arti khusus (aset pusat / belum dialokasikan), scope generik akan merusak alur alokasi.
- `Quotation`, `Warranty` — pakai `orWhereNull('store_id')` disengaja (lead/garansi belum ditugaskan toko).
- `Payroll` — sudah tertutup total lewat `canViewAny()` full-access-only.
- `User` — dipakai lintas-toko SECARA DISENGAJA di banyak tempat (cari direksi/installer toko booking, watcher notifikasi, penerima notifikasi sistem, dropdown assignee). Scope generik akan MERUSAK fungsi-fungsi ini, bukan cuma berisiko.

## 3. Pola Otorisasi Filament (Gate Default-Deny)

Tanpa `Policy` terdaftar DAN tanpa override `canView()/canEdit()/canCreate()/canDelete()` eksplisit, Laravel Gate **default-deny** untuk semua orang:
- **Action bawaan Filament** (Edit/Delete/Create) → tombol tidak pernah muncul untuk siapa pun (aman tapi silently broken).
- **Action custom** (`Tables\Actions\Action::make()`) → **TIDAK** otomatis terikat ke Gate, lolos TANPA proteksi kalau tidak diberi `->visible()` manual. Bug class paling sering ditemukan di seluruh audit sistem (30+ instance diperbaiki).

Resource dengan `Policy` resmi (`app/Policies/`, tidak perlu override manual): `JobOpeningResource`, `NewsResource`, `QuotationResource`, `StoreResource`, `WarrantyResource`.

## 4. Posting Keuangan — Satu Sumber Kebenaran

`App\Services\JournalEntryService` adalah SATU-SATUNYA jalur resmi tulis-menulis `JournalEntry` (create/update/post/reverse). Tidak ada kode lain yang boleh panggil `JournalEntry::create()` langsung — memastikan validasi balance debit=kredit selalu tertegak di satu tempat.

Jurnal `posted` TERKUNCI total — koreksi lewat jurnal PEMBALIK (`reverse()`), bukan edit/hapus langsung. Praktik akuntansi standar: entry historis tidak pernah diedit.

Split pendapatan per produk (PPF/Kaca Film, 50/50 kalau booking punya keduanya) dipusatkan di `App\Services\BookingRevenueSplitter` — dipakai `BookingPostingService` & `RefundService`, JANGAN duplikasi ulang logika ini di tempat lain.

Operasi yang mengubah stok/saldo WAJIB `DB::transaction()` + `lockForUpdate()` di dalam transaction (bukan validasi dari data yang dibaca sebelum lock) — lihat `RefundService`, `PayableService`, `ReceivableService`, `StockWriteOffService` sebagai contoh pola yang benar.

Nominal transaksi/refund di atas **semua ambang** dari staff non-full-access wajib approval full-access dulu sebelum ter-posting (`App\Services\TransactionApprovalService`) — lihat `TransactionApprovalRequestResource`.

## 5. Keterbatasan Model Stok (BELUM Diputuskan)

Model stok saat ini **nasional/gudang pusat** (bukan per-cabang) — belum ada keputusan final apakah dirombak jadi per-cabang, tetap nasional, atau bertahap (Fase 1: mutasi roll + PO formal + penanda cabang; Fase 2: pecah stok kalau perlu). Dokumen keputusan formal ada di `Downloads/Keputusan-PPN-DP-Produk-Stok-Ginnva.docx` (Topik 4), menunggu jawaban atasan.

**Terkait**: fitur "Sisa Roll" (`RollScrapPool`, mengumpulkan sisa material lintas-ScrollCode per toko) sempat dibangun 2026-09-14 lalu **dihapus total 2026-09-15** (diminta user) karena desainnya menabrak gap arsitektur traceability yang sama: roll_number Warranty seharusnya 1 roll = 1 garansi, tapi pool sisa menggabungkan banyak roll jadi satu tanpa kode tunggal. Pertanyaan traceability ini sendiri **belum terjawab** — kalau dibahas lagi, itu diskusi baru dari nol, bukan lanjutan Sisa Roll (lihat memory `project_sisa_roll_dibatalkan`).

## 6. Struktur Navigasi Filament

Sidebar dikelompokkan lewat Filament **Cluster** (`PenjualanCluster`, `InventarisCluster`, `KeuanganCluster`, `KaryawanCluster`, `MasterDataCluster`, `NotifikasiCluster`, `SistemCluster`, `PelangganCluster`). Urutan ITEM di dalam satu cluster ditentukan `navigationSort` PER-ITEM (global, lintas-grup dalam cluster yang sama) — BUKAN posisi di array `navigationGroups()`. `NavigationGroup::sort()` tidak ada di Filament v3.3.54.

Konvensi band 100-lebar per grup dalam 1 cluster (mis. grup A pakai sort 0-99, grup B 100-199) supaya urutan antar-grup tetap stabil walau item baru ditambahkan di tengah.

## 7. Pajak (PPN) — BELUM Diputuskan

Sistem **sengaja belum punya Laporan Pajak** — metode perhitungan PPN (inclusive/exclusive), dasar pengenaan pajak belum diputuskan manajemen. UI menampilkan "Tidak berlaku"/"Belum tersedia" secara eksplisit di baris terkait (bukan Rp 0 palsu), invoice PDF booking mencantumkan footer "bukan Faktur Pajak". Jangan bangun Laporan Pajak sampai ada keputusan formal.

## 8. Referensi Dokumen Lain

- [RUNBOOK.md](RUNBOOK.md) — SOP deploy/rollback/backup, lokasi kredensial, kebijakan retensi data, review migrasi.
- [DRP.md](DRP.md) — prosedur insiden besar (server down, database hilang, kredensial bocor), target RTO/RPO.
- `tests/Feature/FilamentResourceAuthorizationTest.php` — test struktural otomatis untuk pola item 3.
