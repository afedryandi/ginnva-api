<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\ChartOfAccount;
use App\Models\JournalEntryLine;
use App\Models\PaymentAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pelepasan aset tetap (audit ACCURATE 5, tombol "Dispos"): aset dijual atau hilang/dibuang dikeluarkan dari pembukuan dengan satu jurnal.
 *
 *   Debit  Rekening kas/bank penerima   hasil penjualan (bila ada)
 *   Debit  Akumulasi penyusutan aset     penyusutan yang SUDAH diposting sampai tanggal pelepasan
 *   Debit  7900 Beban Non-Operasional    rugi pelepasan (bila hasil < nilai buku)
 *   Kredit Akun aset                     harga perolehan
 *   Kredit 7200 Pendapatan Non-Operasional  laba pelepasan (bila hasil > nilai buku)
 *
 * Nilai buku = harga perolehan dikurangi penyusutan yang benar-benar sudah diposting, bukan hitungan dari tanggal. Bulan pelepasan
 * tidak disusutkan (aset yang sudah dilepas dilewati jadwal penyusutan). Aset tanpa harga perolehan atau akun hanya dicatat statusnya
 * (tanpa jurnal) dan hasilnya memberi tahu itu. Satu aset hanya bisa dilepas satu kali.
 */
class AssetDisposalService
{
    public const TYPES = [
        'dijual' => 'Dijual',
        'hilang' => 'Hilang / dibuang',
    ];

    private const GAIN_ACCOUNT_CODE = '7200';

    private const LOSS_ACCOUNT_CODE = '7900';

    /**
     * Gambaran angka sebelum melepas (dipakai tampilan konfirmasi): tidak menulis apa pun.
     *
     * @return array{journalable: bool, cost: float, accumulated: float, book_value: float, proceeds: float, gain_loss: float, warnings: list<string>}
     */
    public function preview(Asset $asset, Carbon $date, float $proceeds = 0.0): array
    {
        $journalable = $this->canJournal($asset);
        $cost = (float) ($asset->purchase_cost ?? 0);
        $accumulated = $journalable ? $this->accumulatedDepreciation($asset, $date) : 0.0;
        $bookValue = round($cost - $accumulated, 2);
        $warnings = [];

        if (! config('features.finance')) {
            $warnings[] = 'Pembukuan aset dilakukan di Accurate; di sini hanya status pelepasan aset yang dicatat.';
        } elseif (! $journalable) {
            $warnings[] = 'Aset ini belum punya harga perolehan atau akun Bagan Akun, jadi hanya statusnya yang dicatat, tanpa jurnal.';
        } elseif ($this->missingDepreciationBefore($asset, $date)) {
            $warnings[] = 'Penyusutan bulan sebelum tanggal pelepasan belum diposting seluruhnya. Jalankan penyusutan dulu agar nilai buku dan laba/rugi tepat.';
        }

        return [
            'journalable' => $journalable,
            'cost' => $cost,
            'accumulated' => $accumulated,
            'book_value' => $bookValue,
            'proceeds' => round($proceeds, 2),
            'gain_loss' => $journalable ? round($proceeds - $bookValue, 2) : 0.0,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array{type: string, date: string, proceeds?: float|int|string|null, payment_account_id?: int|string|null, notes?: ?string}  $data
     * @return array{asset: Asset, journaled: bool, gain_loss: float}
     *
     * @throws RuntimeException
     */
    public function dispose(Asset $asset, array $data, ?int $userId): array
    {
        $type = $data['type'] ?? '';
        if (! array_key_exists($type, self::TYPES)) {
            throw new RuntimeException('Jenis pelepasan tidak dikenal.');
        }

        $date = Carbon::parse($data['date'])->startOfDay();
        if ($date->isFuture()) {
            throw new RuntimeException('Tanggal pelepasan tidak boleh di masa depan.');
        }

        // Hanya aset yang dijual punya hasil; hilang/dibuang selalu nol.
        $proceeds = $type === 'dijual' ? round((float) ($data['proceeds'] ?? 0), 2) : 0.0;
        if ($proceeds < 0) {
            throw new RuntimeException('Hasil penjualan tidak boleh negatif.');
        }

        return DB::transaction(function () use ($asset, $data, $type, $date, $proceeds, $userId) {
            // Kunci baris aset dulu: dua orang yang melepas aset yang sama bersamaan tidak boleh sama-sama lolos.
            $locked = Asset::query()->where('id', $asset->id)->lockForUpdate()->firstOrFail();

            if ($locked->disposed_at !== null) {
                throw new RuntimeException('Aset ini sudah dilepas pada ' . $locked->disposed_at->translatedFormat('j F Y') . '.');
            }

            if ($locked->purchase_date && $date->lt($locked->purchase_date)) {
                throw new RuntimeException('Tanggal pelepasan tidak boleh sebelum tanggal beli.');
            }

            $journalId = null;
            $gainLoss = 0.0;

            if ($this->canJournal($locked)) {
                $accumulated = $this->accumulatedDepreciation($locked, $date);
                $cost = round((float) $locked->purchase_cost, 2);
                $bookValue = round($cost - $accumulated, 2);
                $gainLoss = round($proceeds - $bookValue, 2);

                $lines = $this->journalLines($locked, $cost, $accumulated, $proceeds, $gainLoss, $data['payment_account_id'] ?? null);

                $service = app(JournalEntryService::class);
                $entry = $service->create([
                    'entry_date' => $date->toDateString(),
                    'store_id' => $locked->store_id,
                    'description' => 'Pelepasan aset ' . self::TYPES[$type] . " — {$locked->name} ({$locked->asset_tag})",
                    'reference_type' => 'asset_disposal',
                    'reference_id' => $locked->id,
                    'created_by' => $userId,
                ], $lines);
                $journalId = $service->post($entry, $userId)->id;
            }

            $locked->update([
                'status' => $type === 'dijual' ? 'dijual' : 'hilang',
                'disposed_at' => $date->toDateString(),
                'disposal_type' => $type,
                'disposal_proceeds' => $proceeds,
                'disposal_journal_entry_id' => $journalId,
                'disposal_notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
            ]);

            return ['asset' => $locked->refresh(), 'journaled' => $journalId !== null, 'gain_loss' => $gainLoss];
        });
    }

    private function canJournal(Asset $asset): bool
    {
        // Pembukuan dipegang Accurate 5 (FEATURE_FINANCE mati): pelepasan aset hanya mengubah status, tanpa jurnal.
        if (! config('features.finance')) {
            return false;
        }

        return $asset->purchase_cost !== null
            && (float) $asset->purchase_cost > 0
            && $asset->chart_of_account_id
            && $asset->accumulated_depreciation_account_id;
    }

    /** Penyusutan yang benar-benar sudah diposting untuk aset ini, dengan tanggal jurnal sampai dan termasuk $date. */
    private function accumulatedDepreciation(Asset $asset, Carbon $date): float
    {
        $sum = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.reference_type', 'asset_depreciation')
            ->where('journal_entries.reference_id', $asset->id)
            ->where('journal_entries.status', 'posted')
            ->whereDate('journal_entries.entry_date', '<=', $date->toDateString())
            ->where('journal_entry_lines.chart_of_account_id', $asset->accumulated_depreciation_account_id)
            ->selectRaw('COALESCE(SUM(journal_entry_lines.credit), 0) - COALESCE(SUM(journal_entry_lines.debit), 0) as net')
            ->value('net');

        return round(max(0.0, (float) $sum), 2);
    }

    /** Ada bulan penuh sebelum bulan pelepasan (sejak aset dibeli) yang belum punya jurnal penyusutan? */
    private function missingDepreciationBefore(Asset $asset, Carbon $date): bool
    {
        if (! $asset->purchase_date || ! $asset->useful_life_years) {
            return false;
        }

        $lastFullMonth = $date->copy()->startOfMonth()->subMonthNoOverflow();
        if ($asset->purchase_date->gt($lastFullMonth->copy()->endOfMonth())) {
            return false;
        }

        return ! \App\Models\JournalEntry::query()
            ->where('reference_type', 'asset_depreciation')
            ->where('reference_id', $asset->id)
            ->where('status', 'posted')
            ->whereYear('entry_date', $lastFullMonth->year)
            ->whereMonth('entry_date', $lastFullMonth->month)
            ->exists()
            && ((float) $asset->purchase_cost - (float) ($asset->salvage_value ?? 0)) > 0;
    }

    /** @return list<array{chart_of_account_id: int, debit?: float, credit?: float}> */
    private function journalLines(Asset $asset, float $cost, float $accumulated, float $proceeds, float $gainLoss, int|string|null $paymentAccountId): array
    {
        $lines = [];

        if ($proceeds > 0) {
            $payment = $paymentAccountId ? PaymentAccount::query()->where('is_active', true)->find($paymentAccountId) : null;

            if (! $payment || ! $payment->chart_of_account_id) {
                throw new RuntimeException('Pilih rekening kas/bank penerima hasil penjualan.');
            }

            $lines[] = ['chart_of_account_id' => $payment->chart_of_account_id, 'debit' => $proceeds];
        }

        if ($accumulated > 0) {
            $lines[] = ['chart_of_account_id' => $asset->accumulated_depreciation_account_id, 'debit' => $accumulated];
        }

        if ($gainLoss < 0) {
            $lines[] = ['chart_of_account_id' => $this->account(self::LOSS_ACCOUNT_CODE), 'debit' => abs($gainLoss)];
        }

        $lines[] = ['chart_of_account_id' => $asset->chart_of_account_id, 'credit' => $cost];

        if ($gainLoss > 0) {
            $lines[] = ['chart_of_account_id' => $this->account(self::GAIN_ACCOUNT_CODE), 'credit' => $gainLoss];
        }

        return $lines;
    }

    private function account(string $code): int
    {
        $id = ChartOfAccount::where('code', $code)->value('id');

        if (! $id) {
            throw new RuntimeException("Akun {$code} tidak ditemukan di Bagan Akun.");
        }

        return (int) $id;
    }
}
