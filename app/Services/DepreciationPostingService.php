<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Penyusutan Aset Tetap otomatis — 1 aset yang memenuhi syarat (punya
 * purchase_cost/purchase_date/useful_life_years, terhubung ke 2 akun
 * Bagan Akun, belum habis disusutkan) diposting 1 jurnal per bulan:
 *   Debit  6420 Beban Penyusutan Aset Tetap
 *   Kredit <akun Akumulasi Penyusutan aset itu> (mis. 1211/1221/1231)
 *
 * Metode garis lurus (straight-line) — SAMA rumus dengan
 * Asset::currentBookValue() (yang menghitung nilai buku on-the-fly
 * dari tanggal, TANPA jurnal), supaya nilai buku hasil jurnal di sini
 * pada akhirnya konvergen ke angka yang sama dengan currentBookValue()
 * kalau dijalankan rutin tiap bulan tanpa terlewat.
 *
 * Idempotent per aset per bulan — dicek via reference_type=
 * 'asset_depreciation' + reference_id=asset->id + entry_date di bulan
 * yang sama, supaya command yang dijalankan ulang tidak dobel posting.
 */
class DepreciationPostingService
{
    private const BEBAN_PENYUSUTAN_ACCOUNT_CODE = '6420';

    /**
     * @return array{posted: int, skipped: int, messages: string[]}
     */
    public function postForMonth(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $bebanPenyusutan = ChartOfAccount::where('code', self::BEBAN_PENYUSUTAN_ACCOUNT_CODE)->first();

        if (! $bebanPenyusutan) {
            return ['posted' => 0, 'skipped' => 0, 'messages' => ['Akun ' . self::BEBAN_PENYUSUTAN_ACCOUNT_CODE . ' (Beban Penyusutan Aset Tetap) tidak ditemukan di Bagan Akun.']];
        }

        $assets = Asset::where('status', '!=', 'dijual')
            ->whereNotNull('purchase_cost')
            ->whereNotNull('purchase_date')
            ->whereNotNull('useful_life_years')
            ->where('useful_life_years', '>', 0)
            ->get();

        $posted = 0;
        $skipped = 0;
        $messages = [];

        foreach ($assets as $asset) {
            $result = $this->postForAsset($asset, $start, $bebanPenyusutan);

            if ($result === null) {
                $skipped++;
            } elseif ($result === true) {
                $posted++;
            } else {
                $skipped++;
                $messages[] = "{$asset->name} ({$asset->asset_tag}): {$result}";
            }
        }

        return ['posted' => $posted, 'skipped' => $skipped, 'messages' => $messages];
    }

    /**
     * @return true|string|null true = berhasil diposting, string = gagal
     *         (pesannya), null = dilewati wajar (sudah pernah/sudah habis
     *         disusutkan bulan ini, tidak perlu dilaporkan sebagai masalah).
     */
    private function postForAsset(Asset $asset, Carbon $monthStart, ChartOfAccount $bebanPenyusutan): true|string|null
    {
        $alreadyPostedThisMonth = JournalEntry::where('reference_type', 'asset_depreciation')
            ->where('reference_id', $asset->id)
            ->whereYear('entry_date', $monthStart->year)
            ->whereMonth('entry_date', $monthStart->month)
            ->exists();

        if ($alreadyPostedThisMonth) {
            return null;
        }

        if (! $asset->chart_of_account_id || ! $asset->accumulated_depreciation_account_id) {
            return 'belum dihubungkan ke akun Bagan Akun (Aset & Akumulasi Penyusutan) — lewati dulu.';
        }

        $cost = (float) $asset->purchase_cost;
        $salvage = (float) ($asset->salvage_value ?? 0);
        $depreciable = max(0, $cost - $salvage);
        $monthlyAmount = round($depreciable / ($asset->useful_life_years * 12), 2);

        if ($monthlyAmount <= 0) {
            return null;
        }

        // Jumlah bulan yang SUDAH diposting sebelumnya (lewat command ini
        // sendiri) — dipakai membatasi total akumulasi supaya tidak
        // pernah melebihi (purchase_cost - salvage_value), bahkan kalau
        // aset dipakai lebih lama dari useful_life_years-nya.
        $monthsAlreadyPosted = JournalEntry::where('reference_type', 'asset_depreciation')
            ->where('reference_id', $asset->id)
            ->where('status', 'posted')
            ->count();

        $accumulatedSoFar = round($monthsAlreadyPosted * $monthlyAmount, 2);
        $remaining = round($depreciable - $accumulatedSoFar, 2);

        if ($remaining <= 0) {
            return null;
        }

        $amount = min($monthlyAmount, $remaining);

        try {
            $service = app(JournalEntryService::class);

            $entry = $service->create([
                'entry_date' => $monthStart->copy()->endOfMonth()->toDateString(),
                'store_id' => $asset->store_id,
                'description' => "Penyusutan {$asset->name} ({$asset->asset_tag}) — " . $monthStart->translatedFormat('F Y'),
                'reference_type' => 'asset_depreciation',
                'reference_id' => $asset->id,
                'created_by' => null,
            ], [
                ['chart_of_account_id' => $bebanPenyusutan->id, 'debit' => $amount],
                ['chart_of_account_id' => $asset->accumulated_depreciation_account_id, 'credit' => $amount],
            ]);

            $service->post($entry, null);

            return true;
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    }
}
