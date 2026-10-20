<?php

namespace App\Services;

use App\Models\CashTransfer;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pemindahan uang antar akun kas/bank (Keuangan Fase 1, 2026-11-05). Jurnal: Debit akun tujuan, Kredit akun asal, sama nominal. Akun harus akun
 * kas/bank yang aktif dan boleh dipakai jurnal, dan berbeda. Pembatalan membuat jurnal pembalik (bukan menghapus), dengan alasan wajib.
 */
class CashTransferService
{
    public function create(int $fromAccountId, int $toAccountId, float $amount, Carbon $date, ?int $storeId, ?string $notes, ?int $userId): CashTransfer
    {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw new RuntimeException('Nominal pemindahan harus lebih dari Rp 0.');
        }

        if ($amount > JournalEntryService::MAX_AMOUNT) {
            throw new RuntimeException('Nominal pemindahan terlalu besar.');
        }

        if ($fromAccountId === $toAccountId) {
            throw new RuntimeException('Akun asal dan tujuan tidak boleh sama.');
        }

        if ($date->toDateString() > now()->toDateString()) {
            throw new RuntimeException('Tanggal pemindahan tidak boleh di masa depan.');
        }

        $accounts = ChartOfAccount::whereIn('id', [$fromAccountId, $toAccountId])->get()->keyBy('id');

        foreach ([$fromAccountId => 'asal', $toAccountId => 'tujuan'] as $id => $label) {
            $account = $accounts->get($id);

            if (! $account || ! $account->is_cash || ! $account->is_active || ! $account->is_postable) {
                throw new RuntimeException("Akun {$label} harus akun Kas/Bank yang aktif.");
            }
        }

        return DB::transaction(function () use ($fromAccountId, $toAccountId, $amount, $date, $storeId, $notes, $userId, $accounts) {
            $service = app(JournalEntryService::class);
            $number = self::generateNumber();

            $entry = $service->create([
                'entry_date' => $date->toDateString(),
                'store_id' => $storeId,
                'description' => "Pemindahan kas {$number}: {$accounts[$fromAccountId]->name} ke {$accounts[$toAccountId]->name}" . ($notes ? " — {$notes}" : ''),
                'reference_type' => 'cash_transfer',
                'reference_id' => null,
                'created_by' => $userId,
            ], [
                ['chart_of_account_id' => $toAccountId, 'debit' => $amount],
                ['chart_of_account_id' => $fromAccountId, 'credit' => $amount],
            ]);
            $service->post($entry, $userId);

            $transfer = CashTransfer::create([
                'transfer_number' => $number,
                'from_account_id' => $fromAccountId,
                'to_account_id' => $toAccountId,
                'store_id' => $storeId,
                'amount' => $amount,
                'transfer_date' => $date->toDateString(),
                'notes' => $notes,
                'journal_entry_id' => $entry->id,
                'created_by' => $userId,
            ]);

            $entry->update(['reference_id' => $transfer->id]);

            return $transfer;
        });
    }

    public function void(CashTransfer $transfer, ?int $userId, string $reason): CashTransfer
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Alasan pembatalan wajib diisi.');
        }

        return DB::transaction(function () use ($transfer, $userId, $reason) {
            $locked = CashTransfer::withoutGlobalScopes()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if ($locked->isVoided()) {
                throw new RuntimeException('Pemindahan ini sudah dibatalkan.');
            }

            $entry = JournalEntry::withoutGlobalScopes()->whereKey($locked->journal_entry_id)->first();
            $reversal = $entry ? app(JournalEntryService::class)->reverse($entry, $userId, "Batal pemindahan kas {$locked->transfer_number}: {$reason}") : null;

            $locked->update([
                'voided_at' => now(),
                'voided_by' => $userId,
                'void_reason' => $reason,
                'void_journal_entry_id' => $reversal?->id,
            ]);

            return $locked->fresh();
        });
    }

    public static function generateNumber(): string
    {
        $prefix = 'TRF-' . now()->format('Ym') . '-';

        $last = CashTransfer::withoutGlobalScopes()
            ->where('transfer_number', 'like', $prefix . '%')
            ->pluck('transfer_number')
            ->map(fn (string $n) => (int) substr($n, strlen($prefix)))
            ->max() ?? 0;

        return $prefix . str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }
}
