<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Satu-satunya jalur resmi menulis Piutang Usaha (Receivable) &
 * pelunasannya — cermin PayableService, arahnya kebalik: uang yang
 * MASIH HARUS DITERIMA dari customer, bukan yang harus dibayar ke
 * supplier.
 *
 * create() TIDAK memposting jurnal baru — jurnal yang mendebit 1110
 * Piutang Usaha SUDAH dibuat sebelumnya oleh pemanggil (mis.
 * BookingPostingService), create() cuma mencatat DETAIL piutangnya
 * (customer, jatuh tempo) sambil menautkan ke jurnal yang sudah ada.
 */
class ReceivableService
{
    private const PIUTANG_USAHA_ACCOUNT_CODE = '1110';
    private const CASH_ACCOUNT_CODE = '1101';

    /**
     * @param array{customer_name: string, store_id: ?int, amount: float, due_date: ?string, source_type?: ?string, source_id?: ?int, journal_entry_id?: ?int, notes?: ?string, created_by?: ?int} $data
     */
    public function create(array $data): Receivable
    {
        return Receivable::create($data + [
            'receivable_number' => self::generateReceivableNumber(),
            'amount_paid' => 0,
            'status' => 'unpaid',
        ]);
    }

    /**
     * Dipakai kalau piutang dicatat manual (bukan dari modul lain yang
     * sudah bikin jurnalnya sendiri) — sekaligus posting jurnal Debit
     * 1110 Piutang Usaha, Kredit akun Pendapatan yang dipilih.
     */
    public function createWithJournal(array $data, int $creditAccountId): Receivable
    {
        return DB::transaction(function () use ($data, $creditAccountId) {
            $piutangUsaha = ChartOfAccount::where('code', self::PIUTANG_USAHA_ACCOUNT_CODE)->first();
            if (! $piutangUsaha) {
                throw new RuntimeException('Akun Piutang Usaha (kode ' . self::PIUTANG_USAHA_ACCOUNT_CODE . ') tidak ditemukan di Bagan Akun.');
            }

            $service = app(JournalEntryService::class);

            $entry = $service->create([
                'entry_date' => now()->toDateString(),
                'store_id' => $data['store_id'] ?? null,
                'description' => "Piutang usaha — {$data['customer_name']}" . (! empty($data['notes']) ? " ({$data['notes']})" : ''),
                'reference_type' => 'receivable',
                'reference_id' => null,
                'created_by' => $data['created_by'] ?? null,
            ], [
                ['chart_of_account_id' => $piutangUsaha->id, 'debit' => (float) $data['amount']],
                ['chart_of_account_id' => $creditAccountId, 'credit' => (float) $data['amount']],
            ]);

            $service->post($entry, $data['created_by'] ?? null);

            $receivable = $this->create($data + ['journal_entry_id' => $entry->id]);

            $entry->update(['reference_id' => $receivable->id]);

            return $receivable;
        });
    }

    /**
     * Terima (sebagian/penuh) pelunasan 1 piutang — Debit 1101 Kas,
     * Kredit 1110 Piutang Usaha, sejumlah $amount.
     *
     * @throws RuntimeException kalau piutang sudah lunas, nominal
     *         melebihi sisa piutang, atau akun/periode bermasalah
     *         (diteruskan dari JournalEntryService).
     */
    public function recordPayment(Receivable $receivable, float $amount, Carbon $date, ?int $userId, ?string $notes = null): ReceivablePayment
    {
        if ($receivable->status === 'paid') {
            throw new RuntimeException('Piutang ini sudah lunas.');
        }

        if ($amount <= 0) {
            throw new RuntimeException('Nominal pelunasan harus lebih besar dari 0.');
        }

        $remaining = $receivable->remainingAmount();
        if ($amount > $remaining) {
            $selisih = number_format($amount - $remaining, 0, ',', '.');
            throw new RuntimeException("Nominal melebihi sisa piutang sebesar Rp {$selisih}.");
        }

        return DB::transaction(function () use ($receivable, $amount, $date, $userId, $notes) {
            $piutangUsaha = ChartOfAccount::where('code', self::PIUTANG_USAHA_ACCOUNT_CODE)->first();
            $cash = ChartOfAccount::where('code', self::CASH_ACCOUNT_CODE)->first();

            if (! $piutangUsaha || ! $cash) {
                throw new RuntimeException('Akun Piutang Usaha/Kas tidak ditemukan di Bagan Akun.');
            }

            $service = app(JournalEntryService::class);

            $entry = $service->create([
                'entry_date' => $date->toDateString(),
                'store_id' => $receivable->store_id,
                'description' => "Pelunasan piutang {$receivable->receivable_number} — {$receivable->customer_name}",
                'reference_type' => 'receivable_payment',
                'reference_id' => $receivable->id,
                'created_by' => $userId,
            ], [
                ['chart_of_account_id' => $cash->id, 'debit' => $amount],
                ['chart_of_account_id' => $piutangUsaha->id, 'credit' => $amount],
            ]);

            $service->post($entry, $userId);

            $payment = ReceivablePayment::create([
                'receivable_id' => $receivable->id,
                'amount' => $amount,
                'payment_date' => $date->toDateString(),
                'journal_entry_id' => $entry->id,
                'notes' => $notes,
                'created_by' => $userId,
            ]);

            $newPaid = round((float) $receivable->amount_paid + $amount, 2);
            $receivable->update([
                'amount_paid' => $newPaid,
                'status' => $newPaid >= (float) $receivable->amount ? 'paid' : 'partial',
            ]);

            return $payment;
        });
    }

    public static function generateReceivableNumber(): string
    {
        do {
            $candidate = 'AR-' . now()->format('Ym') . '-' . Str::upper(Str::random(4));
        } while (Receivable::where('receivable_number', $candidate)->exists());

        return $candidate;
    }
}
