<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Sinkronisasi Persediaan (Keuangan Fase 2, 2026-11-06): menyamakan saldo akun persediaan di Buku Besar (1130 bahan baku, 1131 barang habis
 * pakai, 1132 gulungan film) dengan nilai sub-bukunya dengan SATU jurnal penyesuaian. Dipakai (1) untuk saldo awal persediaan sebelum
 * HPP otomatis dinyalakan, dan (2) sebagai koreksi periodik atas selisih (harga modal terkini vs FIFO, penerimaan stok tanpa jurnal).
 * Akun lawan dipilih manusia dari daftar terbatas: 3200 Laba Ditahan untuk saldo awal, 5100/5200 untuk koreksi HPP, 6520 untuk kerugian
 * persediaan, 3100 Modal Disetor. Tidak pernah berjalan otomatis.
 */
class InventorySyncService
{
    /** key rekonsiliasi => kode akun persediaan */
    public const ACCOUNTS = ['raw_material' => '1130', 'consumable' => '1131', 'film_roll' => '1132'];

    public const COUNTER_CODES = ['3200', '3100', '5100', '5200', '6520'];

    /**
     * @return Collection<int, array{key: string, label: string, account: string, ledger: float, subledger: float, adjustment: float}>
     *         hanya akun yang berselisih; adjustment = sub-buku dikurangi Buku Besar (positif = persediaan perlu ditambah)
     */
    public function plan(): Collection
    {
        return app(LedgerReconciliationService::class)->run()
            ->filter(fn (array $row) => isset(self::ACCOUNTS[$row['key']]) && ! $row['ok'])
            ->map(fn (array $row) => [
                'key' => $row['key'],
                'label' => $row['label'],
                'account' => $row['account'],
                'ledger' => $row['ledger'],
                'subledger' => $row['subledger'],
                'adjustment' => round($row['subledger'] - $row['ledger'], 2),
            ])
            ->values();
    }

    /** @return array<int, string> id akun => nama, untuk pilihan akun lawan */
    public static function counterOptions(): array
    {
        return ChartOfAccount::whereIn('code', self::COUNTER_CODES)->where('is_postable', true)->where('is_active', true)->orderBy('code')->get()
            ->mapWithKeys(fn (ChartOfAccount $a) => [$a->id => $a->display_name])
            ->all();
    }

    /**
     * @param  list<string>  $keys kunci akun yang disinkronkan (raw_material, consumable, film_roll)
     *
     * @throws RuntimeException
     */
    public function post(array $keys, int $counterAccountId, ?string $note, ?int $userId): JournalEntry
    {
        $counter = ChartOfAccount::find($counterAccountId);

        if (! $counter || ! in_array($counter->code, self::COUNTER_CODES, true)) {
            throw new RuntimeException('Akun lawan harus salah satu dari: ' . implode(', ', self::COUNTER_CODES) . '.');
        }

        $plan = $this->plan()->whereIn('key', $keys)->values();

        if ($plan->isEmpty()) {
            throw new RuntimeException('Tidak ada selisih persediaan yang perlu disinkronkan.');
        }

        $lines = [];
        $net = 0.0;

        foreach ($plan as $row) {
            $inventory = ChartOfAccount::where('code', self::ACCOUNTS[$row['key']])->firstOrFail();
            $lines[] = $row['adjustment'] > 0
                ? ['chart_of_account_id' => $inventory->id, 'debit' => $row['adjustment']]
                : ['chart_of_account_id' => $inventory->id, 'credit' => abs($row['adjustment'])];
            $net += $row['adjustment'];
        }

        $net = round($net, 2);

        // Lawan: kredit bila persediaan bertambah, debit bila berkurang. Bila dua akun saling meniadakan, tidak perlu baris lawan.
        if ($net > 0) {
            $lines[] = ['chart_of_account_id' => $counter->id, 'credit' => $net];
        } elseif ($net < 0) {
            $lines[] = ['chart_of_account_id' => $counter->id, 'debit' => abs($net)];
        }

        $service = app(JournalEntryService::class);
        $entry = $service->create([
            'entry_date' => now()->toDateString(),
            'store_id' => null,
            'description' => 'Sinkronisasi persediaan ke sub-buku: ' . $plan->pluck('label')->implode(', ') . ($note ? " — {$note}" : ''),
            'reference_type' => 'inventory_sync',
            'reference_id' => null,
            'created_by' => $userId,
        ], $lines);

        return $service->post($entry, $userId);
    }
}
