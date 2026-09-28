<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use Illuminate\Support\Arr;
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
 *
 * Audit Piutang Usaha 2026-09-29: nominal divalidasi & dibulatkan di service,
 * perbandingan dalam sen, tanggal terima dibatasi, akun penerimaan Kas/Bank,
 * source_key unik (1 booking = 1 piutang), pelunasan ikut memperbarui
 * bookings.amount_received (dashboard konsisten), plus jalur koreksi resmi
 * (voidPayment / cancelReceivable) berbasis jurnal pembalik.
 */
class ReceivableService
{
    private const PIUTANG_USAHA_ACCOUNT_CODE = '1110';
    private const CASH_ACCOUNT_CODE = '1101';

    public const MAX_AMOUNT = JournalEntryService::MAX_AMOUNT;

    public static function sourceKey(?string $type, ?int $id): ?string
    {
        return ($type && $id) ? "{$type}:{$id}" : null;
    }

    /**
     * @param array{customer_name: string, store_id: ?int, amount: float, due_date: ?string, source_type?: ?string, source_id?: ?int, source_key?: ?string, journal_entry_id?: ?int, notes?: ?string, created_by?: ?int} $data
     */
    public function create(array $data): Receivable
    {
        $data['amount'] = $this->normalizeAmount($data['amount'] ?? null, 'Nominal piutang');

        if (! array_key_exists('source_key', $data)) {
            $data['source_key'] = self::sourceKey($data['source_type'] ?? null, isset($data['source_id']) ? (int) $data['source_id'] : null);
        }

        if ($data['source_key'] && Receivable::withoutGlobalScopes()->where('source_key', $data['source_key'])->exists()) {
            throw new RuntimeException('Piutang untuk sumber ini sudah pernah dicatat.');
        }

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
     *
     * $data['entry_date'] (opsional, Y-m-d): tanggal jurnal — default hari ini,
     * tidak boleh di masa depan, periodenya harus terbuka.
     */
    public function createWithJournal(array $data, int $creditAccountId): Receivable
    {
        $amount = $this->normalizeAmount($data['amount'] ?? null, 'Nominal piutang');
        $entryDate = ! empty($data['entry_date']) ? Carbon::parse($data['entry_date'])->toDateString() : now()->toDateString();

        if ($entryDate > now()->toDateString()) {
            throw new RuntimeException('Tanggal jurnal piutang tidak boleh di masa depan.');
        }

        $data = Arr::except($data, ['entry_date']);
        $data['amount'] = $amount;

        return DB::transaction(function () use ($data, $creditAccountId, $amount, $entryDate) {
            $piutangUsaha = ChartOfAccount::where('code', self::PIUTANG_USAHA_ACCOUNT_CODE)->first();
            if (! $piutangUsaha) {
                throw new RuntimeException('Akun Piutang Usaha (kode ' . self::PIUTANG_USAHA_ACCOUNT_CODE . ') tidak ditemukan di Bagan Akun.');
            }

            $service = app(JournalEntryService::class);

            $entry = $service->create([
                'entry_date' => $entryDate,
                'store_id' => $data['store_id'] ?? null,
                'description' => "Piutang usaha — {$data['customer_name']}" . (! empty($data['notes']) ? " ({$data['notes']})" : ''),
                'reference_type' => 'receivable',
                'reference_id' => null,
                'created_by' => $data['created_by'] ?? null,
            ], [
                ['chart_of_account_id' => $piutangUsaha->id, 'debit' => $amount],
                ['chart_of_account_id' => $creditAccountId, 'credit' => $amount],
            ]);

            $service->post($entry, $data['created_by'] ?? null);

            $receivable = $this->create($data + ['journal_entry_id' => $entry->id]);

            $entry->update(['reference_id' => $receivable->id]);

            return $receivable;
        });
    }

    /**
     * Terima (sebagian/penuh) pelunasan 1 piutang — Debit akun Kas/Bank
     * ($receiveAccountId, default 1101 Kas), Kredit 1110 Piutang Usaha.
     * Kalau piutang berasal dari Booking, bookings.amount_received ikut bertambah
     * supaya widget/laporan penjualan tidak menampilkan "belum lunas" selamanya.
     *
     * @throws RuntimeException kalau piutang sudah lunas/dibatalkan, nominal
     *         melebihi sisa piutang, tanggal tidak valid, atau akun/periode bermasalah.
     */
    public function recordPayment(Receivable $receivable, float $amount, Carbon $date, ?int $userId, ?string $notes = null, ?int $receiveAccountId = null): ReceivablePayment
    {
        $amount = $this->normalizeAmount($amount, 'Nominal pelunasan');
        $cents = $this->toCents($amount);

        if ($date->toDateString() > now()->toDateString()) {
            throw new RuntimeException('Tanggal penerimaan tidak boleh di masa depan.');
        }

        $sourceType = $receivable->source_type;
        $sourceId = $receivable->source_id;

        // Audit framework 2026-09-14 -- lockForUpdate() dulu, baru validasi pakai data yang
        // sudah dikunci. Urutan lock: booking dulu, baru piutang (sama dgn BookingPostingService::sync,
        // supaya tidak deadlock).
        return DB::transaction(function () use ($receivable, $amount, $cents, $date, $userId, $notes, $receiveAccountId, $sourceType, $sourceId) {
            if ($sourceType === 'booking' && $sourceId) {
                DB::table('bookings')->where('id', $sourceId)->lockForUpdate()->first(['id']);
            }

            $receivable = Receivable::query()->where('id', $receivable->id)->lockForUpdate()->firstOrFail();

            if ($receivable->status === 'paid') {
                throw new RuntimeException('Piutang ini sudah lunas.');
            }

            if ($receivable->status === 'cancelled') {
                throw new RuntimeException('Piutang ini sudah dibatalkan.');
            }

            // Pemisahan tugas: yang mencatat piutang MANUAL tidak boleh sekaligus menerima
            // pelunasannya. Piutang dari Booking dikecualikan (dibuat otomatis dari alur
            // "Proses Referral", bukan pencatatan piutang oleh individu).
            if ($userId && $receivable->created_by === $userId && $receivable->source_type === null) {
                throw new RuntimeException('Pembuat piutang tidak boleh menerima pelunasannya sendiri (pemisahan tugas) — minta direksi lain yang mencatat pelunasan.');
            }

            if ($date->toDateString() < $receivable->created_at->toDateString()) {
                throw new RuntimeException('Tanggal penerimaan tidak boleh sebelum piutang dicatat (' . $receivable->created_at->format('d M Y') . ').');
            }

            $remainingCents = $this->toCents($receivable->amount) - $this->toCents($receivable->amount_paid);
            if ($cents > $remainingCents) {
                $selisih = number_format(($cents - $remainingCents) / 100, 0, ',', '.');
                throw new RuntimeException("Nominal melebihi sisa piutang sebesar Rp {$selisih}.");
            }

            $piutangUsaha = ChartOfAccount::where('code', self::PIUTANG_USAHA_ACCOUNT_CODE)->first();
            $cash = $receiveAccountId
                ? ChartOfAccount::whereKey($receiveAccountId)->where('is_cash', true)->where('is_postable', true)->where('is_active', true)->first()
                : ChartOfAccount::where('code', self::CASH_ACCOUNT_CODE)->first();

            if (! $piutangUsaha) {
                throw new RuntimeException('Akun Piutang Usaha tidak ditemukan di Bagan Akun.');
            }

            if (! $cash) {
                throw new RuntimeException($receiveAccountId
                    ? 'Akun penerimaan harus akun Kas/Bank yang aktif.'
                    : 'Akun Kas tidak ditemukan di Bagan Akun.');
            }

            $service = app(JournalEntryService::class);

            $entry = $service->create([
                'entry_date' => $date->toDateString(),
                'store_id' => $receivable->store_id,
                'description' => "Pelunasan piutang {$receivable->receivable_number} — {$receivable->customer_name} ({$cash->name})",
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
                'receipt_number' => self::generateReceiptNumber(),
                'amount' => $amount,
                'payment_date' => $date->toDateString(),
                'journal_entry_id' => $entry->id,
                'notes' => $notes,
                'created_by' => $userId,
            ]);

            $newPaidCents = $this->toCents($receivable->amount_paid) + $cents;
            $receivable->update([
                'amount_paid' => $newPaidCents / 100,
                'status' => $newPaidCents >= $this->toCents($receivable->amount) ? 'paid' : 'partial',
            ]);

            $this->adjustBookingReceived($sourceType, $sourceId, $cents);

            return $payment;
        });
    }

    /**
     * Batalkan 1 pelunasan yang keliru: jurnalnya DIBALIK (bukan dihapus), pelunasan
     * ditandai void, amount_paid/status piutang dan bookings.amount_received dihitung
     * ulang -- subledger, buku besar, dan data booking tetap sinkron.
     */
    public function voidPayment(ReceivablePayment $payment, ?int $userId, string $reason): ReceivablePayment
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Alasan pembatalan wajib diisi.');
        }

        $parent = Receivable::withoutGlobalScopes()->findOrFail($payment->receivable_id);
        $sourceType = $parent->source_type;
        $sourceId = $parent->source_id;

        return DB::transaction(function () use ($payment, $userId, $reason, $sourceType, $sourceId) {
            if ($sourceType === 'booking' && $sourceId) {
                DB::table('bookings')->where('id', $sourceId)->lockForUpdate()->first(['id']);
            }

            $receivable = Receivable::query()->where('id', $payment->receivable_id)->lockForUpdate()->firstOrFail();
            $locked = ReceivablePayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->voided_at !== null) {
                throw new RuntimeException('Pelunasan ini sudah dibatalkan.');
            }

            if ($locked->journal_entry_id) {
                $entry = JournalEntry::whereKey($locked->journal_entry_id)->first();
                if ($entry) {
                    $reversal = app(JournalEntryService::class)->reverse($entry, $userId, "Batal pelunasan piutang {$receivable->receivable_number}: {$reason}");
                    $locked->void_journal_entry_id = $reversal->id;
                }
            }

            $locked->voided_at = now();
            $locked->voided_by = $userId;
            $locked->void_reason = $reason;
            $locked->save();

            $paidCents = $this->toCents(
                ReceivablePayment::where('receivable_id', $receivable->id)->whereNull('voided_at')->sum('amount')
            );

            $receivable->update([
                'amount_paid' => $paidCents / 100,
                'status' => $paidCents <= 0 ? 'unpaid' : ($paidCents >= $this->toCents($receivable->amount) ? 'paid' : 'partial'),
            ]);

            $this->adjustBookingReceived($sourceType, $sourceId, -$this->toCents($locked->amount));

            return $locked;
        });
    }

    /**
     * Batalkan piutang manual yang salah input (belum ada pelunasan aktif): jurnal
     * pengakuannya DIBALIK, status jadi 'cancelled'. Piutang dari Booking TIDAK boleh
     * dibatalkan di sini -- koreksi lewat nominal transaksi booking ("Proses Referral").
     */
    public function cancelReceivable(Receivable $receivable, ?int $userId, string $reason): Receivable
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Alasan pembatalan wajib diisi.');
        }

        return DB::transaction(function () use ($receivable, $userId, $reason) {
            $locked = Receivable::query()->where('id', $receivable->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'cancelled') {
                throw new RuntimeException('Piutang ini sudah dibatalkan.');
            }

            if ($locked->source_type === 'booking') {
                throw new RuntimeException('Piutang ini berasal dari Booking — koreksi lewat nominal transaksi booking ("Proses Referral"), bukan dibatalkan di sini.');
            }

            if (ReceivablePayment::where('receivable_id', $locked->id)->whereNull('voided_at')->exists()) {
                throw new RuntimeException('Masih ada pelunasan aktif — batalkan pelunasannya dulu.');
            }

            if ($locked->journal_entry_id) {
                $entry = JournalEntry::whereKey($locked->journal_entry_id)->first();
                if ($entry) {
                    $reversal = app(JournalEntryService::class)->reverse($entry, $userId, "Batal piutang {$locked->receivable_number}: {$reason}");
                    $locked->cancel_journal_entry_id = $reversal->id;
                }
            }

            $locked->status = 'cancelled';
            $locked->cancelled_at = now();
            $locked->cancelled_by = $userId;
            $locked->cancel_reason = $reason;
            $locked->save();

            return $locked;
        });
    }

    /**
     * Tambah/kurangi bookings.amount_received (dalam sen) sebesar pelunasan. Lewat query
     * builder (tanpa event model/observer Booking). Dibatasi 0..transaction_amount; NULL
     * (= dianggap lunas penuh) dibiarkan.
     */
    private function adjustBookingReceived(?string $sourceType, ?int $sourceId, int $deltaCents): void
    {
        if ($sourceType !== 'booking' || ! $sourceId || $deltaCents === 0) {
            return;
        }

        $booking = DB::table('bookings')->where('id', $sourceId)->first(['id', 'amount_received', 'transaction_amount']);

        if (! $booking || $booking->amount_received === null) {
            return;
        }

        $newCents = max(0, min($this->toCents($booking->transaction_amount), $this->toCents($booking->amount_received) + $deltaCents));

        DB::table('bookings')->where('id', $sourceId)->update(['amount_received' => $newCents / 100]);
    }

    /**
     * Rekonsiliasi cepat: saldo akun 1110 di buku besar (jurnal posted) vs total sisa piutang
     * aktif di subledger. Selisih = ada jurnal manual ke 1110 di luar menu ini, atau data lama.
     *
     * @return array{gl: float, subledger: float, diff: float}
     */
    public function reconcile(): array
    {
        $account = ChartOfAccount::where('code', self::PIUTANG_USAHA_ACCOUNT_CODE)->first();

        $glCents = 0;
        if ($account) {
            $row = DB::table('journal_entry_lines as l')
                ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->where('e.status', 'posted')
                ->where('l.chart_of_account_id', $account->id)
                ->selectRaw('ROUND(SUM(l.debit - l.credit) * 100) as balance')
                ->first();
            $glCents = (int) ($row->balance ?? 0);
        }

        $subCents = $this->toCents(
            DB::table('receivables')->where('status', '!=', 'cancelled')->sum(DB::raw('amount - amount_paid'))
        );

        return ['gl' => $glCents / 100, 'subledger' => $subCents / 100, 'diff' => ($glCents - $subCents) / 100];
    }

    public static function generateReceiptNumber(): string
    {
        do {
            $candidate = 'RC-' . now()->format('Ym') . '-' . Str::upper(Str::random(4));
        } while (ReceivablePayment::where('receipt_number', $candidate)->exists());

        return $candidate;
    }

    /** Nominal wajib angka > 0, <= batas, dibulatkan 2 desimal. */
    private function normalizeAmount(mixed $amount, string $label): float
    {
        if (! is_numeric($amount)) {
            throw new RuntimeException("{$label} harus berupa angka.");
        }

        $rounded = round((float) $amount, 2);

        if ($rounded <= 0) {
            throw new RuntimeException("{$label} harus lebih besar dari 0.");
        }

        if ($rounded > self::MAX_AMOUNT) {
            throw new RuntimeException("{$label} melebihi batas maksimum yang diizinkan.");
        }

        return $rounded;
    }

    private function toCents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    public static function generateReceivableNumber(): string
    {
        do {
            $candidate = 'AR-' . now()->format('Ym') . '-' . Str::upper(Str::random(4));
        } while (Receivable::withoutGlobalScopes()->where('receivable_number', $candidate)->exists());

        return $candidate;
    }
}
