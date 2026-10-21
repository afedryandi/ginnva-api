<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingSettlementPayment;
use App\Models\PaymentAccount;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pembayaran yang diterima saat penjualan diproses (Penjualan Fase 2, 2026-11-11). Dipanggil dari aksi Proses Referral (dan approve()
 * permintaan yang sama) di dalam transaksi yang sama dengan penyimpanan nominal, SEBELUM jurnal pendapatan dibuat ulang.
 *
 * Aturan: jumlah semua pembayaran + DP yang terpakai tidak boleh melebihi nominal transaksi (ditolak, tidak dipotong diam-diam). Sisa yang
 * belum terbayar menjadi Piutang Usaha seperti biasa. Daftar kosong berarti tidak ada uang diterima selain DP.
 */
class BookingSettlementPaymentService
{
    /**
     * Bersihkan dan periksa baris dari form/payload. Baris kosong total diabaikan.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array{method: string, payment_account_id: ?int, amount: float}>
     *
     * @throws RuntimeException pesan siap tampil bila ada baris tidak valid
     */
    public function normalize(array $rows, ?int $storeId = null): array
    {
        $clean = [];

        foreach (array_values($rows) as $i => $row) {
            $n = $i + 1;
            $method = $row['method'] ?? null;
            $amount = $row['amount'] ?? null;

            if (($method === null || $method === '') && ($amount === null || $amount === '')) {
                continue;
            }

            if (! isset(Booking::PAYMENT_METHOD_LABELS[$method])) {
                throw new RuntimeException("Pembayaran {$n}: metode belum dipilih.");
            }

            if (! is_numeric($amount) || (float) $amount <= 0) {
                throw new RuntimeException("Pembayaran {$n}: nominal harus lebih dari Rp 0.");
            }

            $accountId = ! empty($row['payment_account_id']) ? (int) $row['payment_account_id'] : null;

            if ($accountId) {
                $account = PaymentAccount::with('chartAccount')->find($accountId);

                if (! $account || ! $account->isUsable()) {
                    throw new RuntimeException("Pembayaran {$n}: rekening tidak aktif atau akun kasnya tidak bisa dipakai.");
                }

                if ($storeId && $account->store_id && (int) $account->store_id !== $storeId) {
                    throw new RuntimeException("Pembayaran {$n}: rekening itu milik toko lain.");
                }
            }

            $clean[] = ['method' => $method, 'payment_account_id' => $accountId, 'amount' => round((float) $amount, 2)];
        }

        return $clean;
    }

    public static function total(array $rows): float
    {
        return round((float) array_sum(array_column($rows, 'amount')), 2);
    }

    /** DP yang akan terpakai melunasi nominal ini (sisa DP melebihi nominal tetap di 2140, seperti sebelumnya). */
    public static function dpApplicable(Booking $booking, float $nominal): float
    {
        return round(min((float) $booking->outstanding_down_payment, max(0.0, $nominal)), 2);
    }

    /**
     * @param  list<array{method: string, payment_account_id: ?int, amount: float}>  $rows
     *
     * @throws RuntimeException bila jumlah pembayaran + DP melebihi nominal transaksi
     */
    public function assertWithinNominal(Booking $booking, array $rows, float $nominal): void
    {
        $paid = self::total($rows);
        $dp = self::dpApplicable($booking, $nominal);

        if ($paid + $dp > $nominal + 0.009) {
            $text = fn (float $n) => 'Rp ' . number_format($n, 0, ',', '.');

            throw new RuntimeException(
                'Total pembayaran ' . $text($paid) . ($dp > 0 ? ' + DP ' . $text($dp) : '') . ' melebihi nominal transaksi ' . $text($nominal)
                . '. Kurangi nominal pembayaran atau perbaiki nominal transaksi.'
            );
        }
    }

    /** Total yang dianggap diterima (pembayaran + DP terpakai), dasar kolom amount_received. */
    public function amountReceived(Booking $booking, array $rows, float $nominal): float
    {
        return round(self::total($rows) + self::dpApplicable($booking, $nominal), 2);
    }

    /** Metode utama = pembayaran terbesar; null bila tidak ada pembayaran. */
    public static function primaryMethod(array $rows): ?string
    {
        if ($rows === []) {
            return null;
        }

        $byMethod = [];
        foreach ($rows as $row) {
            $byMethod[$row['method']] = ($byMethod[$row['method']] ?? 0.0) + $row['amount'];
        }

        arsort($byMethod);

        return array_key_first($byMethod);
    }

    /**
     * Ganti seluruh pembayaran booking dengan $rows (sudah dinormalkan). Daftar kosong menghapus semua baris.
     *
     * @param  list<array{method: string, payment_account_id: ?int, amount: float}>  $rows
     */
    public function replace(Booking $booking, array $rows, ?int $userId = null): void
    {
        DB::transaction(function () use ($booking, $rows, $userId) {
            $before = BookingSettlementPayment::where('booking_id', $booking->id)->orderBy('id')->get();
            $beforeLines = $before->map(fn (BookingSettlementPayment $p) => self::describe($p->method, $p->payment_account_id, (float) $p->amount))->all();
            $beforeTotal = (float) $before->sum('amount');

            BookingSettlementPayment::where('booking_id', $booking->id)->delete();

            foreach ($rows as $row) {
                BookingSettlementPayment::create($row + [
                    'booking_id' => $booking->id,
                    'paid_at' => now()->toDateString(),
                    'received_by' => $userId,
                ]);
            }

            $booking->unsetRelation('settlementPayments');

            // Histori Aktivitas Fase 3: satu catatan per penggantian (hapus massal tidak memicu pencatatan model).
            \App\Support\LineReplacementLog::record(
                $booking,
                'booking_settlement_payment',
                'Pembayaran pelunasan',
                $beforeLines,
                array_map(fn (array $r) => self::describe($r['method'], $r['payment_account_id'] ?? null, (float) $r['amount']), $rows),
                $beforeTotal,
                self::total($rows)
            );
        });
    }

    /** Teks satu pembayaran untuk Histori Aktivitas: "transfer (BCA Operasional): Rp 500.000". */
    private static function describe(string $method, ?int $accountId, float $amount): string
    {
        $account = $accountId ? PaymentAccount::find($accountId)?->name : null;

        return $method . ($account ? " ({$account})" : '') . ': ' . \App\Support\LineReplacementLog::money($amount);
    }
}
