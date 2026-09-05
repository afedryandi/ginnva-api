<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\Payable;
use App\Models\PayablePayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Satu-satunya jalur resmi menulis Hutang Usaha (Payable) & pembayarannya
 * — konsisten dengan pola JournalEntryService/RewardRedemptionService
 * (validasi + tulis DB selalu di service, bukan Resource langsung).
 *
 * PENTING: create() di sini TIDAK memposting jurnal baru — jurnal yang
 * menaikkan saldo 2110 Hutang Usaha SUDAH dibuat sebelumnya oleh
 * pemanggil (mis. PurchaseRequestPostingService), create() cuma
 * mencatat DETAIL tagihannya (supplier, jatuh tempo) sambil menautkan
 * ke jurnal yang sudah ada itu — supaya saldo 2110 TIDAK dobel dicatat.
 * Kalau suatu saat ada tagihan yang dicatat manual TANPA jurnal dari
 * modul lain, panggil createWithJournal() sebagai gantinya.
 */
class PayableService
{
    private const HUTANG_USAHA_ACCOUNT_CODE = '2110';
    private const CASH_ACCOUNT_CODE = '1101';

    /**
     * @param array{supplier_name: string, store_id: ?int, amount: float, due_date: ?string, source_type?: ?string, source_id?: ?int, journal_entry_id?: ?int, notes?: ?string, created_by?: ?int} $data
     */
    public function create(array $data): Payable
    {
        return Payable::create($data + [
            'payable_number' => self::generatePayableNumber(),
            'amount_paid' => 0,
            'status' => 'unpaid',
        ]);
    }

    /**
     * Dipakai kalau tagihan dicatat manual (bukan dari modul lain yang
     * sudah bikin jurnalnya sendiri) — sekaligus posting jurnal Debit
     * akun yang dipilih (mis. Beban/Persediaan) Kredit 2110 Hutang
     * Usaha, baru catat baris Payable-nya menautkan ke jurnal itu.
     */
    public function createWithJournal(array $data, int $debitAccountId): Payable
    {
        return DB::transaction(function () use ($data, $debitAccountId) {
            $hutangUsaha = ChartOfAccount::where('code', self::HUTANG_USAHA_ACCOUNT_CODE)->first();
            if (! $hutangUsaha) {
                throw new RuntimeException('Akun Hutang Usaha (kode ' . self::HUTANG_USAHA_ACCOUNT_CODE . ') tidak ditemukan di Bagan Akun.');
            }

            $service = app(JournalEntryService::class);

            $entry = $service->create([
                'entry_date' => now()->toDateString(),
                'store_id' => $data['store_id'] ?? null,
                'description' => "Hutang usaha — {$data['supplier_name']}" . (! empty($data['notes']) ? " ({$data['notes']})" : ''),
                'reference_type' => 'payable',
                'reference_id' => null,
                'created_by' => $data['created_by'] ?? null,
            ], [
                ['chart_of_account_id' => $debitAccountId, 'debit' => (float) $data['amount']],
                ['chart_of_account_id' => $hutangUsaha->id, 'credit' => (float) $data['amount']],
            ]);

            $service->post($entry, $data['created_by'] ?? null);

            $payable = $this->create($data + ['journal_entry_id' => $entry->id]);

            // reference_id jurnal di atas menunjuk ke Payable yang baru
            // dibuat (id-nya baru ada SETELAH create()) — diisi belakangan
            // supaya "Payable X" bisa ditelusuri balik dari Jurnal Umum.
            $entry->update(['reference_id' => $payable->id]);

            return $payable;
        });
    }

    /**
     * Bayar (sebagian/penuh) 1 tagihan — Debit 2110 Hutang Usaha, Kredit
     * 1101 Kas, sejumlah $amount. Status payable otomatis dihitung ulang
     * (unpaid/partial/paid) dari total pembayaran yang terkumpul.
     *
     * @throws RuntimeException kalau tagihan sudah lunas, nominal
     *         melebihi sisa tagihan, atau akun/periode bermasalah
     *         (diteruskan dari JournalEntryService).
     */
    public function recordPayment(Payable $payable, float $amount, Carbon $date, ?int $userId, ?string $notes = null): PayablePayment
    {
        if ($payable->status === 'paid') {
            throw new RuntimeException('Tagihan ini sudah lunas.');
        }

        if ($amount <= 0) {
            throw new RuntimeException('Nominal pembayaran harus lebih besar dari 0.');
        }

        $remaining = $payable->remainingAmount();
        if ($amount > $remaining) {
            $selisih = number_format($amount - $remaining, 0, ',', '.');
            throw new RuntimeException("Nominal melebihi sisa tagihan sebesar Rp {$selisih}.");
        }

        return DB::transaction(function () use ($payable, $amount, $date, $userId, $notes) {
            $hutangUsaha = ChartOfAccount::where('code', self::HUTANG_USAHA_ACCOUNT_CODE)->first();
            $cash = ChartOfAccount::where('code', self::CASH_ACCOUNT_CODE)->first();

            if (! $hutangUsaha || ! $cash) {
                throw new RuntimeException('Akun Hutang Usaha/Kas tidak ditemukan di Bagan Akun.');
            }

            $service = app(JournalEntryService::class);

            $entry = $service->create([
                'entry_date' => $date->toDateString(),
                'store_id' => $payable->store_id,
                'description' => "Pembayaran hutang {$payable->payable_number} — {$payable->supplier_name}",
                'reference_type' => 'payable_payment',
                'reference_id' => $payable->id,
                'created_by' => $userId,
            ], [
                ['chart_of_account_id' => $hutangUsaha->id, 'debit' => $amount],
                ['chart_of_account_id' => $cash->id, 'credit' => $amount],
            ]);

            $service->post($entry, $userId);

            $payment = PayablePayment::create([
                'payable_id' => $payable->id,
                'amount' => $amount,
                'payment_date' => $date->toDateString(),
                'journal_entry_id' => $entry->id,
                'notes' => $notes,
                'created_by' => $userId,
            ]);

            $newPaid = round((float) $payable->amount_paid + $amount, 2);
            $payable->update([
                'amount_paid' => $newPaid,
                'status' => $newPaid >= (float) $payable->amount ? 'paid' : 'partial',
            ]);

            return $payment;
        });
    }

    public static function generatePayableNumber(): string
    {
        do {
            $candidate = 'AP-' . now()->format('Ym') . '-' . Str::upper(Str::random(4));
        } while (Payable::where('payable_number', $candidate)->exists());

        return $candidate;
    }
}
