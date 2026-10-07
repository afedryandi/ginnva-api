<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Payable;
use App\Models\PayablePayment;
use Illuminate\Support\Arr;
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
 *
 * Audit Hutang Usaha 2026-09-29: nominal divalidasi & dibulatkan di service
 * (bukan cuma di form), perbandingan sisa/lunas dalam sen, tanggal bayar
 * dibatasi, akun pembayaran bisa Kas/Bank, pemisahan tugas (pembuat tagihan
 * tidak boleh membayarnya sendiri), source_key unik anti-duplikat, plus jalur
 * koreksi resmi (voidPayment / cancelPayable) berbasis jurnal pembalik.
 */
class PayableService
{
    private const HUTANG_USAHA_ACCOUNT_CODE = '2110';
    private const CASH_ACCOUNT_CODE = '1101';

    /** Sama dengan batas Jurnal Umum (kolom decimal(14,2) muat jauh di atas ini). */
    public const MAX_AMOUNT = JournalEntryService::MAX_AMOUNT;

    /**
     * Kunci unik idempotensi per sumber: satu Permohonan Pembelian = satu tagihan;
     * satu template rutin = satu tagihan per tanggal jalan.
     */
    public static function sourceKey(?string $type, ?int $id, ?string $date = null): ?string
    {
        if (! $type || ! $id) {
            return null;
        }

        return $type === 'recurring_bill_template'
            ? "recurring_bill_template:{$id}:{$date}"
            : "{$type}:{$id}";
    }

    /**
     * @param array{supplier_name: string, store_id: ?int, amount: float, due_date: ?string, source_type?: ?string, source_id?: ?int, source_key?: ?string, supplier_id?: ?int, invoice_number?: ?string, attachment?: ?string, journal_entry_id?: ?int, notes?: ?string, created_by?: ?int} $data
     */
    public function create(array $data): Payable
    {
        $data['amount'] = $this->normalizeAmount($data['amount'] ?? null, 'Nominal tagihan');

        if (! array_key_exists('source_key', $data)) {
            $data['source_key'] = self::sourceKey($data['source_type'] ?? null, isset($data['source_id']) ? (int) $data['source_id'] : null);
        }

        if ($data['source_key'] && Payable::withoutGlobalScopes()->where('source_key', $data['source_key'])->exists()) {
            throw new RuntimeException('Tagihan untuk sumber ini sudah pernah dicatat.');
        }

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
     *
     * $data['entry_date'] (opsional, Y-m-d): tanggal jurnal — default hari ini.
     * Tidak boleh di masa depan; periodenya harus terbuka (dicek JournalEntryService).
     */
    public function createWithJournal(array $data, int $debitAccountId): Payable
    {
        $amount = $this->normalizeAmount($data['amount'] ?? null, 'Nominal tagihan');
        $entryDate = ! empty($data['entry_date']) ? Carbon::parse($data['entry_date'])->toDateString() : now()->toDateString();

        if ($entryDate > now()->toDateString()) {
            throw new RuntimeException('Tanggal jurnal tagihan tidak boleh di masa depan.');
        }

        // Dropdown akun di form hanya membatasi di UI; nilai kiriman tidak divalidasi. Tanpa pengecekan ini,
        // tagihan bisa mendebit akun yang tidak masuk akal (Hutang Usaha itu sendiri, Pendapatan, Modal) dan
        // merusak laporan keuangan tanpa peringatan. Yang sah: akun aktif, bisa diposting, bersaldo normal debit.
        $debitAccount = ChartOfAccount::find($debitAccountId);
        if (! $debitAccount || ! $debitAccount->is_active || ! $debitAccount->is_postable
            || ! in_array($debitAccount->type, ['aset', 'beban_pokok', 'beban_operasional', 'beban_lain', 'pajak'], true)) {
            throw new RuntimeException('Akun yang didebit harus akun Aset atau Beban yang aktif.');
        }

        $data = Arr::except($data, ['entry_date']);
        $data['amount'] = $amount;

        return DB::transaction(function () use ($data, $debitAccountId, $amount, $entryDate) {
            $hutangUsaha = ChartOfAccount::where('code', self::HUTANG_USAHA_ACCOUNT_CODE)->first();
            if (! $hutangUsaha) {
                throw new RuntimeException('Akun Hutang Usaha (kode ' . self::HUTANG_USAHA_ACCOUNT_CODE . ') tidak ditemukan di Bagan Akun.');
            }

            $service = app(JournalEntryService::class);

            $entry = $service->create([
                'entry_date' => $entryDate,
                'store_id' => $data['store_id'] ?? null,
                'description' => "Hutang usaha — {$data['supplier_name']}" . (! empty($data['notes']) ? " ({$data['notes']})" : ''),
                'reference_type' => 'payable',
                'reference_id' => null,
                'created_by' => $data['created_by'] ?? null,
            ], [
                ['chart_of_account_id' => $debitAccountId, 'debit' => $amount],
                ['chart_of_account_id' => $hutangUsaha->id, 'credit' => $amount],
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
     * akun Kas/Bank ($paymentAccountId, default 1101 Kas), sejumlah $amount.
     * Status payable otomatis dihitung ulang (unpaid/partial/paid).
     *
     * @throws RuntimeException kalau tagihan sudah lunas/dibatalkan, nominal
     *         melebihi sisa tagihan, tanggal tidak valid, pembayar = pembuat
     *         tagihan, atau akun/periode bermasalah (diteruskan dari JournalEntryService).
     */
    public function recordPayment(Payable $payable, float $amount, Carbon $date, ?int $userId, ?string $notes = null, ?int $paymentAccountId = null): PayablePayment
    {
        $amount = $this->normalizeAmount($amount, 'Nominal pembayaran');
        $cents = $this->toCents($amount);

        if ($date->toDateString() > now()->toDateString()) {
            throw new RuntimeException('Tanggal pembayaran tidak boleh di masa depan.');
        }

        // Audit framework 2026-09-14, "Integritas transaksi finansial"
        // -- lockForUpdate() dulu, baru validasi pakai data yang sudah dikunci
        // (2 pembayaran hampir bersamaan tidak boleh sama-sama lolos dari data basi).
        return DB::transaction(function () use ($payable, $amount, $cents, $date, $userId, $notes, $paymentAccountId) {
            $payable = Payable::query()->where('id', $payable->id)->lockForUpdate()->firstOrFail();

            if ($payable->status === 'paid') {
                throw new RuntimeException('Tagihan ini sudah lunas.');
            }

            if ($payable->status === 'cancelled') {
                throw new RuntimeException('Tagihan ini sudah dibatalkan.');
            }

            // Pemisahan tugas: yang mencatat tagihan tidak boleh sekaligus membayarnya.
            // Tagihan rutin otomatis dikecualikan (dibuat sistem, bukan oleh individu).
            if ($userId && $payable->created_by === $userId && $payable->source_type !== 'recurring_bill_template') {
                throw new RuntimeException('Pembuat tagihan tidak boleh membayar tagihannya sendiri (pemisahan tugas) — minta direksi lain yang membayar.');
            }

            if ($date->toDateString() < $payable->created_at->toDateString()) {
                throw new RuntimeException('Tanggal pembayaran tidak boleh sebelum tagihan dicatat (' . $payable->created_at->format('d M Y') . ').');
            }

            $remainingCents = $this->toCents($payable->amount) - $this->toCents($payable->amount_paid);
            if ($cents > $remainingCents) {
                $selisih = number_format(($cents - $remainingCents) / 100, 0, ',', '.');
                throw new RuntimeException("Nominal melebihi sisa tagihan sebesar Rp {$selisih}.");
            }

            $hutangUsaha = ChartOfAccount::where('code', self::HUTANG_USAHA_ACCOUNT_CODE)->first();
            $cash = $paymentAccountId
                ? ChartOfAccount::whereKey($paymentAccountId)->where('is_cash', true)->where('is_postable', true)->where('is_active', true)->first()
                : ChartOfAccount::where('code', self::CASH_ACCOUNT_CODE)->first();

            if (! $hutangUsaha) {
                throw new RuntimeException('Akun Hutang Usaha tidak ditemukan di Bagan Akun.');
            }

            if (! $cash) {
                throw new RuntimeException($paymentAccountId
                    ? 'Akun pembayaran harus akun Kas/Bank yang aktif.'
                    : 'Akun Kas tidak ditemukan di Bagan Akun.');
            }

            $service = app(JournalEntryService::class);

            $entry = $service->create([
                'entry_date' => $date->toDateString(),
                'store_id' => $payable->store_id,
                'description' => "Pembayaran hutang {$payable->payable_number} — {$payable->supplier_name} ({$cash->name})",
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

            $newPaidCents = $this->toCents($payable->amount_paid) + $cents;
            $payable->update([
                'amount_paid' => $newPaidCents / 100,
                'status' => $newPaidCents >= $this->toCents($payable->amount) ? 'paid' : 'partial',
            ]);

            return $payment;
        });
    }

    /**
     * Batalkan 1 pembayaran yang keliru: jurnal pembayarannya DIBALIK (bukan
     * dihapus), pembayaran ditandai void, amount_paid & status tagihan dihitung
     * ulang dari pembayaran yang masih aktif -- subledger dan buku besar tetap sinkron.
     */
    public function voidPayment(PayablePayment $payment, ?int $userId, string $reason): PayablePayment
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Alasan pembatalan wajib diisi.');
        }

        return DB::transaction(function () use ($payment, $userId, $reason) {
            // Urutan lock selalu payable dulu, baru payment (konsisten dgn recordPayment).
            $payable = Payable::query()->where('id', $payment->payable_id)->lockForUpdate()->firstOrFail();
            $locked = PayablePayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->voided_at !== null) {
                throw new RuntimeException('Pembayaran ini sudah dibatalkan.');
            }

            if ($locked->journal_entry_id) {
                $entry = JournalEntry::whereKey($locked->journal_entry_id)->first();
                if ($entry) {
                    $reversal = app(JournalEntryService::class)->reverse($entry, $userId, "Batal bayar hutang {$payable->payable_number}: {$reason}");
                    $locked->void_journal_entry_id = $reversal->id;
                }
            }

            $locked->voided_at = now();
            $locked->voided_by = $userId;
            $locked->void_reason = $reason;
            $locked->save();

            $paidCents = $this->toCents(
                PayablePayment::where('payable_id', $payable->id)->whereNull('voided_at')->sum('amount')
            );

            $payable->update([
                'amount_paid' => $paidCents / 100,
                'status' => $paidCents <= 0 ? 'unpaid' : ($paidCents >= $this->toCents($payable->amount) ? 'paid' : 'partial'),
            ]);

            return $locked;
        });
    }

    /**
     * Batalkan tagihan yang salah input (belum ada pembayaran aktif): jurnal
     * pengakuan hutangnya DIBALIK, status jadi 'cancelled'.
     *
     * Tagihan dari Permohonan Pembelian TIDAK boleh dibatalkan di sini karena
     * jurnalnya juga menaikkan Persediaan/Aset milik permohonan itu.
     */
    public function cancelPayable(Payable $payable, ?int $userId, string $reason): Payable
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Alasan pembatalan wajib diisi.');
        }

        return DB::transaction(function () use ($payable, $userId, $reason) {
            $locked = Payable::query()->where('id', $payable->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'cancelled') {
                throw new RuntimeException('Tagihan ini sudah dibatalkan.');
            }

            if ($locked->source_type === 'purchase_request') {
                throw new RuntimeException('Tagihan ini berasal dari Permohonan Pembelian — koreksi lewat Jurnal Umum (jurnal pembalik) karena jurnalnya juga mencatat Persediaan/Aset.');
            }

            if (PayablePayment::where('payable_id', $locked->id)->whereNull('voided_at')->exists()) {
                throw new RuntimeException('Masih ada pembayaran aktif — batalkan pembayarannya dulu.');
            }

            if ($locked->journal_entry_id) {
                $entry = JournalEntry::whereKey($locked->journal_entry_id)->first();
                if ($entry) {
                    $reversal = app(JournalEntryService::class)->reverse($entry, $userId, "Batal tagihan {$locked->payable_number}: {$reason}");
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
     * Rekonsiliasi cepat: saldo akun 2110 di buku besar (jurnal posted) vs
     * total sisa tagihan aktif di subledger. Selisih = ada jurnal manual ke
     * 2110 di luar modul ini, atau data lama sebelum modul Hutang Usaha.
     *
     * @return array{gl: float, subledger: float, diff: float}
     */
    public function reconcile(): array
    {
        $account = ChartOfAccount::where('code', self::HUTANG_USAHA_ACCOUNT_CODE)->first();

        $glCents = 0;
        if ($account) {
            $row = DB::table('journal_entry_lines as l')
                ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->where('e.status', 'posted')
                ->where('l.chart_of_account_id', $account->id)
                ->selectRaw('ROUND(SUM(l.credit - l.debit) * 100) as balance')
                ->first();
            $glCents = (int) ($row->balance ?? 0);
        }

        $subCents = $this->toCents(
            DB::table('payables')->where('status', '!=', 'cancelled')->sum(DB::raw('amount - amount_paid'))
        );

        return ['gl' => $glCents / 100, 'subledger' => $subCents / 100, 'diff' => ($glCents - $subCents) / 100];
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

    public static function generatePayableNumber(): string
    {
        do {
            $candidate = 'AP-' . now()->format('Ym') . '-' . Str::upper(Str::random(4));
        } while (Payable::withoutGlobalScopes()->where('payable_number', $candidate)->exists());

        return $candidate;
    }
}
