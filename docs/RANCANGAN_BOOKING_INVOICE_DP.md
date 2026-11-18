# Rancangan: Booking terhubung ke Invoice dan DP (tanpa Catat Penjualan)

Disusun 2026-12-16 atas permintaan pemilik: "fitur DP dan Catat Penjualan terlalu aneh; booking terhubung ke DP dan Invoice, tidak perlu Catat Penjualan".
Status: rancangan disetujui, belum dibangun.

## Keputusan pemilik

| # | Pertanyaan | Keputusan |
|---|---|---|
| 1 | Kapan booking dianggap terjual | Saat booking SELESAI; nilainya dari Total Tagihan invoice booking itu |
| 2 | Cara invoice dibuat | OTOMATIS draf saat booking dikonfirmasi (isi awal dari `InvoiceBookingPrefill`) |
| 3 | Tempat DP | Baris pembayaran di invoice; aksi DP di menu Booking dihapus |
| 4 | Persetujuan diskon di atas batas nego | TIDAK ADA; aturan batas nego dan antrean persetujuan diskon dihapus |
| 5 | Voucher, promo total pembelian, kode referral | Diisi di FORM INVOICE |
| 6 | Data lama | DIBIARKAN; hanya booking baru memakai alur baru |

## Yang dihapus

- Aksi panel: **Catat Penjualan** (`process_referral`), **Catat Uang Muka (DP)** (`receive_down_payment`), **Kembalikan Sisa DP** (`refund_unused_dp`).
- API staf: `GET/POST /api/staff/bookings/{id}/sale` (`BookingSaleController`). Layar `app/staff/bookings/sale.tsx` di aplikasi (sudah tidak terhubung dari menu).
- `BookingSaleProcessor` (diganti sinkronisasi dari invoice), aturan batas nego (`Booking::isWithinNegotiationCap`) dan pengajuan `TransactionApprovalRequest` jenis penjualan dan DP.
- Teks panduan Penjualan dan Booking yang menyebut Catat Penjualan dan DP; label `Catat Penjualan` di `GuideCatalog::MOBILE_LABELS` sudah dihapus.

## Model data

1. `invoice_payments` (baru): `invoice_id`, `type` (`dp` | `settlement`), `method` (tunai/transfer/QRIS/EDC, kunci sama dengan `Booking::PAYMENT_METHOD_LABELS`), `amount`, `paid_at`, `notes`, `created_by`. `invoices.amount_paid` menjadi jumlah baris pembayaran (kolom dipertahankan sebagai cache); status `unpaid`/`paid` dihitung otomatis dari jumlahnya.
2. `invoice_items.service_type` (baru, nullable): jenis layanan baris itu (kunci `BookingSaleLine::SERVICE_TYPES`), dasar pembagian pendapatan per jenis layanan.
3. `invoices`: `voucher_claim_id`, `spend_promo_id`, `spend_promo_discount`, `voucher_discount`, `referral_code`, `vehicle_size` (dipindah dari Catat Penjualan).
4. Satu invoice aktif (bukan `void`) per booking: aturan di service, bukan hanya form.

## Alur baru

1. **Konfirmasi booking** (`BookingController::confirm` dan aksi panel) membuat invoice draf dari booking. Jika sudah ada invoice aktif, tidak membuat lagi.
2. Admin membuka invoice, melengkapi harga, diskon, voucher, promo, dan kode referral, lalu **menerbitkan** (draf menjadi belum lunas).
3. **Pembayaran** (aksi "Catat Pembayaran" di invoice): jenis Uang Muka atau Pelunasan, metode, nominal, tanggal. Boleh sebelum booking selesai (DP) atau sesudahnya. Sisa tagihan = Total Tagihan - jumlah pembayaran. Pembayaran tidak boleh melebihi sisa tagihan.
4. **Booking selesai** (`complete`) memicu sinkronisasi `InvoiceSaleSync`:
   - `bookings.transaction_amount` = Total Tagihan invoice, `sale_date` = tanggal selesai, `amount_received` = jumlah pembayaran, `payment_method` = metode dominan.
   - Rincian per layanan (`booking_sale_lines`) diisi dari baris invoice ber-`service_type`.
   - Voucher dan promo ditandai terpakai; poin referral partner dan bonus ajak teman diberikan (logika `ReferralPointService` dipakai ulang).
   - Booking selesai TANPA invoice terbit: tidak masuk laporan sampai invoice diterbitkan; sinkronisasi dijalankan lagi saat invoice diterbitkan.
5. **Booking dibatalkan**: pembayaran DP tercatat sebagai dikembalikan penuh (kebijakan DP saat ini dipertahankan); invoice di-void.
6. Invoice yang diubah setelah booking selesai menyinkronkan ulang nilainya (hanya akses penuh yang boleh mengubah invoice berstatus terbit).

## Tahap pembangunan

1. **Data dan service pembayaran invoice**: migrasi, `Invoice::payments()`, `InvoiceService::addPayment()`, aksi "Catat Pembayaran" di panel, baris Uang Muka dan Total Terbayar di cetakan invoice. Tes.
2. **Invoice otomatis saat konfirmasi** + satu-invoice-aktif-per-booking. Form invoice: voucher, promo, kode referral, jenis layanan per baris. Tes.
3. **Sinkronisasi saat selesai** (`InvoiceSaleSync`) + pembatalan. Tes memastikan laporan penjualan, komisi, dan poin tetap benar untuk booking baru.
4. **Penghapusan** aksi, endpoint, layar mobile, dan batas nego/persetujuan terkait; panduan diperbarui; tes lama disesuaikan.

## Risiko dan catatan

- 49 berkas membaca `transaction_amount`; semuanya tetap membaca kolom yang sama, jadi laporan tidak diubah. Risiko ada pada tahap 3: sinkronisasi harus mengisi semua kolom turunan (`dpp_amount`, `ppn_amount`, `sale_date`, jurnal bila `FEATURE_FINANCE` menyala) lewat jalur yang sudah ada.
- Booking lama yang belum selesai saat rilis tetap memakai data DP lama (`booking_down_payments`); aksi DP-nya hilang, jadi sisa DP lama dibaca saja dan dilunasi manual lewat invoice.
- Aplikasi karyawan versi lama masih memanggil `/sale`; endpoint dihapus hanya setelah build baru beredar atau dibiarkan mengembalikan 410 dengan pesan jelas.
