<?php

namespace App\Services;

use App\Models\BankStatementLine;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\JournalEntryLine;
use App\Models\PaymentAccount;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Mutasi bank yang belum punya jurnal (biaya admin, bunga, setoran yang belum dicatat) dicatat langsung dari layar Rekonsiliasi Bank
 * (audit ACCURATE 5: "catat biaya atau bunga bank dari layar rekonsiliasi"). Satu langkah membuat Transaksi Keuangan, memposting
 * jurnalnya lewat jalur yang sama dengan pencatatan biasa, dan mencocokkan mutasi ke baris jurnal bank yang baru terbentuk.
 *
 * Arah mengikuti mutasi: uang keluar menjadi Pengeluaran, uang masuk menjadi Pemasukan; kategori harus bertipe sama. Rekening yang
 * dipakai adalah rekening kas/bank terdaftar yang akunnya sama dengan akun mutasi. Seluruhnya satu transaksi basis data: gagal di
 * tengah jalan (periode tertutup, kategori belum terhubung akun) tidak meninggalkan transaksi atau jurnal setengah jadi.
 */
class BankLineBookingService
{
    /**
     * @throws RuntimeException
     */
    public function book(BankStatementLine $line, int $categoryId, int $storeId, ?string $description, int $userId): FinanceTransaction
    {
        return DB::transaction(function () use ($line, $categoryId, $storeId, $description, $userId) {
            $locked = BankStatementLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'unmatched') {
                throw new RuntimeException('Mutasi ini sudah diproses (mungkin oleh pengguna lain). Muat ulang halaman.');
            }

            $type = (float) $locked->amount >= 0 ? 'in' : 'out';

            $category = FinanceCategory::query()->find($categoryId);
            if (! $category || ! $category->is_active || $category->is_group) {
                throw new RuntimeException('Pilih kategori yang aktif dan bukan kategori grup.');
            }
            if ($category->type !== $type) {
                throw new RuntimeException('Kategori harus bertipe ' . ($type === 'in' ? 'pemasukan' : 'pengeluaran') . ' sesuai arah mutasi.');
            }

            $payment = PaymentAccount::query()
                ->where('chart_of_account_id', $locked->chart_of_account_id)
                ->where('is_active', true)
                ->orderByRaw('store_id = ? desc', [$storeId])
                ->orderBy('id')
                ->first();

            if (! $payment) {
                throw new RuntimeException('Belum ada rekening kas/bank terdaftar untuk akun mutasi ini. Daftarkan dulu di menu Rekening Kas & Bank.');
            }

            $amount = round(abs((float) $locked->amount), 2);

            // Kas kecil tidak boleh melampaui saldonya, sama seperti pencatatan pengeluaran biasa.
            if ($type === 'out') {
                $petty = app(PettyCashService::class);
                if ($pettyAccount = $petty->find($payment->id)) {
                    $petty->assertSufficient($pettyAccount, $amount);
                }
            }

            $transaction = FinanceTransaction::create([
                'type' => $type,
                'finance_category_id' => $category->id,
                'payment_account_id' => $payment->id,
                'store_id' => $storeId,
                'amount' => $amount,
                'transaction_date' => $locked->statement_date->toDateString(),
                'description' => filled($description) ? trim($description) : $locked->description,
                'created_by' => $userId,
            ]);

            $entry = app(FinanceTransactionPostingService::class)->post($transaction, $userId);
            $transaction->update(['journal_entry_id' => $entry->id]);

            // Baris jurnal di akun bank: debit untuk uang masuk, kredit untuk uang keluar.
            $journalLine = JournalEntryLine::query()
                ->where('journal_entry_id', $entry->id)
                ->where('chart_of_account_id', $locked->chart_of_account_id)
                ->when($type === 'in', fn ($q) => $q->where('debit', '>', 0), fn ($q) => $q->where('credit', '>', 0))
                ->first();

            if (! $journalLine) {
                throw new RuntimeException('Jurnal yang terbentuk tidak menyentuh akun bank mutasi ini. Periksa akun rekening kas/bank yang dipilih.');
            }

            app(BankReconciliationService::class)->match($locked, $journalLine);

            return $transaction;
        });
    }
}
