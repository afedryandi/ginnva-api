<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingDownPayment;
use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Uang Muka (DP) booking -- keputusan atasan 2026-09-19 (Topik 2,
 * "Keputusan-PPN-DP-Produk-Stok-Ginnva.docx"):
 * - DP BUKAN syarat wajib saat booking -- diterima FLEKSIBEL kapan saja
 *   selama proses instalasi berjalan (staff catat manual saat uang
 *   BENAR-BENAR diterima, tidak otomatis saat status berubah).
 * - Nominal BEBAS diisi staff manual per booking (tidak ada aturan
 *   tetap/persentase baku).
 * - Kalau booking dibatalkan setelah DP dibayar: DIKEMBALIKAN PENUH ke
 *   customer (keputusan eksplisit "untuk sementara" -- SENGAJA tidak
 *   di-hardcode jadi "hangus"/parsial supaya gampang direvisi nanti
 *   kalau kebijakannya berubah, lihat refundAllOnCancellation()).
 *
 * Akuntansi: DP BUKAN pendapatan (jasanya belum diberikan) -- Debit Kas
 * / Kredit 2140 "Pendapatan Diterima Dimuka" saat diterima, dibalik
 * (Debit 2140 / Kredit Kas) saat dikembalikan. Pola jurnal SAMA PERSIS
 * dengan RefundService (entry baru, tidak pernah mengedit entry lama).
 */
class DownPaymentService
{
    private const CASH_ACCOUNT_CODE = '1101';
    private const DEFERRED_REVENUE_ACCOUNT_CODE = '2140';

    /**
     * Catat DP baru diterima untuk 1 booking + posting jurnalnya.
     *
     * @throws RuntimeException kalau nominal <= 0, atau booking sudah
     *         berstatus final (completed/cancelled) -- setelah selesai,
     *         pembayaran lewat "Proses Referral" (transaction_amount),
     *         bukan DP lagi; setelah dibatalkan, tidak relevan menerima
     *         uang baru untuk booking yang batal.
     */
    public function receive(Booking $booking, float $amount, ?string $notes, ?int $userId): BookingDownPayment
    {
        if ($amount <= 0) {
            throw new RuntimeException('Nominal DP harus lebih dari Rp 0.');
        }

        return DB::transaction(function () use ($booking, $amount, $notes, $userId) {
            $locked = Booking::query()->where('id', $booking->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, ['completed', 'cancelled'], true)) {
                throw new RuntimeException("Booking ini sudah berstatus \"{$locked->status}\" -- tidak bisa mencatat DP baru.");
            }

            $cash = ChartOfAccount::where('code', self::CASH_ACCOUNT_CODE)->first();
            if (! $cash) {
                throw new RuntimeException('Akun kas (kode ' . self::CASH_ACCOUNT_CODE . ') tidak ditemukan di Bagan Akun.');
            }

            $deferredRevenue = ChartOfAccount::where('code', self::DEFERRED_REVENUE_ACCOUNT_CODE)->first();
            if (! $deferredRevenue) {
                throw new RuntimeException('Akun Pendapatan Diterima Dimuka (kode ' . self::DEFERRED_REVENUE_ACCOUNT_CODE . ') tidak ditemukan di Bagan Akun.');
            }

            $service = app(JournalEntryService::class);
            $entry = $service->create([
                'entry_date' => now()->toDateString(),
                'store_id' => $locked->store_id,
                'description' => "DP diterima -- booking {$locked->booking_number} ({$locked->customer_name})",
                'reference_type' => 'booking_down_payment',
                'reference_id' => $locked->id,
                'created_by' => $userId,
            ], [
                ['chart_of_account_id' => $cash->id, 'debit' => $amount],
                ['chart_of_account_id' => $deferredRevenue->id, 'credit' => $amount],
            ]);
            $entry = $service->post($entry, $userId);

            return BookingDownPayment::create([
                'booking_id' => $locked->id,
                'amount' => $amount,
                'received_at' => now()->toDateString(),
                'notes' => $notes,
                'journal_entry_id' => $entry->id,
                'created_by' => $userId,
            ]);
        });
    }

    /**
     * Kembalikan 1 DP secara PENUH (bukan parsial -- keputusan atasan
     * belum mengizinkan potongan) + posting jurnal pembaliknya.
     *
     * @throws RuntimeException kalau DP ini sudah pernah dikembalikan.
     */
    public function refund(BookingDownPayment $downPayment, ?int $userId, ?string $reason = null): BookingDownPayment
    {
        return DB::transaction(function () use ($downPayment, $userId, $reason) {
            $locked = BookingDownPayment::query()->where('id', $downPayment->id)->lockForUpdate()->firstOrFail();

            if ($locked->isRefunded()) {
                throw new RuntimeException('DP ini sudah pernah dikembalikan sebelumnya.');
            }

            $booking = $locked->booking;

            $cash = ChartOfAccount::where('code', self::CASH_ACCOUNT_CODE)->first();
            $deferredRevenue = ChartOfAccount::where('code', self::DEFERRED_REVENUE_ACCOUNT_CODE)->first();
            if (! $cash || ! $deferredRevenue) {
                throw new RuntimeException('Akun Kas / Pendapatan Diterima Dimuka tidak ditemukan di Bagan Akun.');
            }

            $amount = (float) $locked->amount;

            $service = app(JournalEntryService::class);
            $entry = $service->create([
                'entry_date' => now()->toDateString(),
                'store_id' => $booking->store_id,
                'description' => "Pengembalian DP -- booking {$booking->booking_number} ({$booking->customer_name})" . ($reason ? " -- {$reason}" : ''),
                'reference_type' => 'booking_down_payment_refund',
                'reference_id' => $locked->id,
                'created_by' => $userId,
            ], [
                ['chart_of_account_id' => $deferredRevenue->id, 'debit' => $amount],
                ['chart_of_account_id' => $cash->id, 'credit' => $amount],
            ]);
            $entry = $service->post($entry, $userId);

            $locked->update([
                'refund_journal_entry_id' => $entry->id,
                'refunded_at' => now(),
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Kembalikan SEMUA DP booking ini yang belum di-refund -- dipanggil
     * dari alur pembatalan booking (Filament quickCancel, Staff & Customer
     * API cancel -- SEMUA 3 titik panggil method ini supaya aturan
     * "DP dikembalikan penuh saat batal" konsisten di mana pun booking
     * dibatalkan, bukan cuma salah satu jalur).
     *
     * Aman dipanggil untuk booking yang TIDAK PUNYA DP sama sekali --
     * cuma no-op (tidak melempar error), supaya bisa dipanggil tanpa
     * syarat di setiap alur pembatalan.
     *
     * @param  int|null  $bookingId  ID booking yang SUDAH dikunci
     *         (lockForUpdate) oleh pemanggil -- method ini TIDAK mengunci
     *         Booking lagi (sudah dikunci di transaction pemanggil),
     *         cuma mengunci tiap baris BookingDownPayment saat di-refund.
     * @return list<BookingDownPayment>
     */
    public function refundAllOnCancellation(int $bookingId, ?int $userId): array
    {
        $unrefunded = BookingDownPayment::query()
            ->where('booking_id', $bookingId)
            ->whereNull('refunded_at')
            ->get();

        return $unrefunded
            ->map(fn (BookingDownPayment $dp) => $this->refund($dp, $userId, 'Booking dibatalkan'))
            ->all();
    }
}
