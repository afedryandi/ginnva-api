<?php

namespace App\Services;

use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Models\PayrollItemCategory;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Mengelola komponen gaji sebuah slip (Karyawan Fase 1, 2026-10-29): menyimpan daftar komponen sekaligus dan menyalin komponen tetap dari
 * bulan lalu. Hanya payroll draft yang boleh diubah; payroll dikunci di dalam transaksi supaya tidak bertabrakan dengan pengajuan
 * pembayaran yang berjalan bersamaan.
 */
class PayrollItemService
{
    /**
     * Menyimpan daftar komponen lengkap: baris dengan id diperbarui, baris tanpa id dibuat, komponen yang tidak ada di daftar dihapus.
     *
     * @param  array<int, array{id?: ?int, payroll_item_category_id: int, label?: ?string, amount: numeric, is_recurring?: bool, notes?: ?string}>  $rows
     */
    public function sync(Payroll $payroll, array $rows, ?int $userId = null): void
    {
        DB::transaction(function () use ($payroll, $rows, $userId) {
            $locked = $this->lockDraft($payroll);
            $keep = [];

            foreach ($rows as $row) {
                $category = PayrollItemCategory::find($row['payroll_item_category_id'] ?? null);

                if (! $category || ! $category->is_active) {
                    throw new RuntimeException('Kategori komponen tidak valid atau sudah dinonaktifkan.');
                }

                $amount = (float) ($row['amount'] ?? 0);

                if ($amount <= 0) {
                    throw new RuntimeException("Nominal komponen \"{$category->name}\" harus lebih dari 0.");
                }

                $attributes = [
                    'payroll_item_category_id' => $category->id,
                    'label' => filled($row['label'] ?? null) ? trim($row['label']) : null,
                    'amount' => $amount,
                    'is_recurring' => (bool) ($row['is_recurring'] ?? false),
                    'notes' => filled($row['notes'] ?? null) ? trim($row['notes']) : null,
                ];

                $item = ! empty($row['id']) ? $locked->items()->whereKey($row['id'])->first() : null;

                if ($item) {
                    $item->update($attributes);
                } else {
                    $item = $locked->items()->create($attributes + ['created_by' => $userId]);
                }

                $keep[] = $item->id;
            }

            foreach ($locked->items()->whereNotIn('id', $keep)->get() as $stale) {
                $stale->delete();
            }

            $locked->refresh()->recalculateTotals();
        });
    }

    /**
     * Menyalin komponen yang ditandai "ikut disalin" dari slip bulan lalu karyawan yang sama. Komponen yang sudah ada di slip ini
     * (kategori dan nama sama) dilewati, jadi aman ditekan berulang. Mengembalikan jumlah komponen yang disalin.
     */
    public function copyRecurring(Payroll $target, ?int $userId = null): int
    {
        return DB::transaction(function () use ($target, $userId) {
            $locked = $this->lockDraft($target);

            $previous = Payroll::withoutGlobalScopes()
                ->where('user_id', $locked->user_id)
                ->whereDate('period_month', $locked->period_month->copy()->subMonth()->startOfMonth()->toDateString())
                ->first();

            if (! $previous) {
                return 0;
            }

            $copied = 0;

            foreach ($previous->items()->where('is_recurring', true)->with('category')->get() as $source) {
                if (! $source->category?->is_active) {
                    continue;
                }

                $exists = $locked->items()
                    ->where('payroll_item_category_id', $source->payroll_item_category_id)
                    ->when($source->label === null, fn ($q) => $q->whereNull('label'), fn ($q) => $q->where('label', $source->label))
                    ->exists();

                if ($exists) {
                    continue;
                }

                $locked->items()->create([
                    'payroll_item_category_id' => $source->payroll_item_category_id,
                    'label' => $source->label,
                    'amount' => $source->amount,
                    'is_recurring' => true,
                    'notes' => $source->notes,
                    'created_by' => $userId,
                ]);

                $copied++;
            }

            return $copied;
        });
    }

    private function lockDraft(Payroll $payroll): Payroll
    {
        $locked = Payroll::withoutGlobalScopes()->whereKey($payroll->id)->lockForUpdate()->first();

        if (! $locked || $locked->status !== 'draft') {
            throw new RuntimeException('Komponen gaji hanya bisa diubah selama payroll masih draft.');
        }

        return $locked;
    }
}
