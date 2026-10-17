<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Payroll;
use RuntimeException;

/**
 * Auto-posting Penggajian → Jurnal Umum, dipicu saat Payroll ditandai
 * "Dibayar" (bukan saat digenerate — payroll 'draft' bisa direvisi/
 * dihapus, belum jadi fakta keuangan sampai benar-benar dibayar).
 *
 * Pola jurnal (baris komisi ditambahkan 2026-09-23, integrasi Komisi
 * Teknisi → Payroll):
 * - TANPA potongan & TANPA komisi (kasus lama): 2 baris —
 *     Debit 6110 Beban Gaji Pokok = net_pay
 *     Kredit 1101 Kas = net_pay
 * - DENGAN potongan dan/atau komisi: gaji POKOK (gross, sebelum
 *   potongan), komisi, dan potongannya ditampilkan TERPISAH per baris —
 *   bukan langsung dinetkan jadi net_pay di 1 baris — supaya laporan
 *   per-akun tetap bisa lihat "berapa total gaji kotor" vs "berapa
 *   komisi" vs "berapa yang dipotong karena telat/alpha" secara
 *   terpisah, konsisten dengan deskripsi akun 6120 di ChartOfAccountSeeder:
 *     Debit 6110 Beban Gaji Pokok = prorated_base_salary (gross)
 *     Debit 5300 Upah Langsung Teknisi = total_commission (kalau > 0)
 *     Kredit 6120 Beban Potongan Telat/Alpha = total_deduction (kalau > 0)
 *     Kredit 1101 Kas = net_pay
 *   (total debit = prorated_base_salary + total_commission = total
 *   kredit = total_deduction + net_pay, balance terjaga)
 */
class PayrollPostingService
{
    private const CASH_ACCOUNT_CODE = '1101';
    private const GAJI_POKOK_ACCOUNT_CODE = '6110';
    private const POTONGAN_ACCOUNT_CODE = '6120';
    private const KOMISI_TEKNISI_ACCOUNT_CODE = '5300';

    /**
     * @throws RuntimeException kalau payroll ini sudah pernah diposting
     *         sebelumnya, akun yang dibutuhkan tidak ditemukan di Bagan
     *         Akun, atau periode tanggal posting sudah ditutup
     *         (diteruskan dari JournalEntryService).
     */
    public function post(Payroll $payroll): JournalEntry
    {
        if ($payroll->journal_entry_id) {
            throw new RuntimeException('Payroll ini sudah pernah diposting ke Jurnal Umum sebelumnya.');
        }

        $cash = ChartOfAccount::where('code', self::CASH_ACCOUNT_CODE)->first();
        $gajiPokok = ChartOfAccount::where('code', self::GAJI_POKOK_ACCOUNT_CODE)->first();
        $potongan = ChartOfAccount::where('code', self::POTONGAN_ACCOUNT_CODE)->first();
        $komisiTeknisi = ChartOfAccount::where('code', self::KOMISI_TEKNISI_ACCOUNT_CODE)->first();

        $commission = (float) $payroll->total_commission;
        $netPay = (float) $payroll->net_pay;
        $items = $payroll->items()->with('category.account')->get();

        // Potongan yang BENAR-BENAR berlaku = gaji kotor + komisi - gaji bersih. Sama dengan
        // total_deduction kecuali kalau potongan lebih besar dari gaji: net_pay dijepit di 0
        // (lihat Payroll::generateForMonth), sehingga mengkredit total_deduction penuh membuat
        // jurnal tidak balance dan gaji itu tidak bisa ditandai dibayar.
        $deduction = max(0.0, round((float) $payroll->prorated_base_salary + $commission - $netPay, 2));

        if (! $cash || ! $gajiPokok || ($deduction > 0 && ! $potongan) || ($commission > 0 && ! $komisiTeknisi)) {
            throw new RuntimeException('Akun Bagan Akun yang dibutuhkan (Kas/Beban Gaji Pokok/Beban Potongan/Upah Langsung Teknisi) tidak ditemukan — periksa menu Bagan Akun.');
        }


        if ($items->isNotEmpty()) {
            $lines = $this->linesWithItems($payroll, $items, $gajiPokok, $komisiTeknisi, $potongan, $cash, $commission, $netPay);
        } elseif ($deduction > 0 || $commission > 0) {
            $lines = [
                ['chart_of_account_id' => $gajiPokok->id, 'debit' => (float) $payroll->prorated_base_salary],
            ];

            if ($commission > 0) {
                $lines[] = ['chart_of_account_id' => $komisiTeknisi->id, 'debit' => $commission];
            }

            if ($deduction > 0) {
                $lines[] = ['chart_of_account_id' => $potongan->id, 'credit' => $deduction];
            }

            // Gaji bersih 0 (potongan menghabiskan gaji): tidak ada kas yang keluar, dan JournalEntryService
            // menolak baris tanpa nominal -- jadi baris Kas dilewati, beban gaji vs potongan sudah balance.
            if ($netPay > 0) {
                $lines[] = ['chart_of_account_id' => $cash->id, 'credit' => $netPay];
            }
        } else {
            $lines = [
                ['chart_of_account_id' => $gajiPokok->id, 'debit' => $netPay],
                ['chart_of_account_id' => $cash->id, 'credit' => $netPay],
            ];
        }

        $periodLabel = $payroll->period_month->translatedFormat('F Y');
        $description = "Gaji {$payroll->user?->name} periode {$periodLabel}";

        $service = app(JournalEntryService::class);

        $entry = $service->create([
            'entry_date' => now()->toDateString(),
            'store_id' => $payroll->store_id,
            'description' => $description,
            'reference_type' => 'payroll',
            'reference_id' => $payroll->id,
            'created_by' => $payroll->paid_by,
        ], $lines);

        return $service->post($entry, $payroll->paid_by);
    }

    /**
     * Jurnal untuk slip yang punya komponen (Karyawan Fase 1): tiap komponen tambahan didebit ke akun kategorinya, tiap potongan komponen
     * dikredit ke akun kategorinya (BPJS -> Hutang BPJS, PPh 21 -> Hutang Pajak, kasbon -> Piutang Karyawan, dst., bisa diatur akuntan),
     * potongan telat/Alpha tetap ke 6120, dan kas = gaji bersih. Bila potongan lebih besar dari gaji (gaji bersih dijepit 0), potongan
     * komponen diperkecil sebanding agar jurnal tetap balance.
     */
    private function linesWithItems(Payroll $payroll, \Illuminate\Support\Collection $items, ?ChartOfAccount $gajiPokok, ?ChartOfAccount $komisiTeknisi, ?ChartOfAccount $potongan, ?ChartOfAccount $cash, float $commission, float $netPay): array
    {
        foreach ($items as $item) {
            $account = $item->category?->account;

            if (! $account || ! $account->is_active || ! $account->is_postable) {
                throw new RuntimeException("Akun pembukuan untuk komponen \"{$item->displayLabel()}\" belum diatur atau tidak aktif — atur di menu Karyawan > Kategori Komponen Gaji.");
            }
        }

        $earnings = $items->filter(fn ($i) => $i->category->isEarning());
        $itemDeductions = $items->filter(fn ($i) => ! $i->category->isEarning());

        $additions = round((float) $earnings->sum('amount'), 2);
        $itemDeductionTotal = round((float) $itemDeductions->sum('amount'), 2);
        $gross = round((float) $payroll->prorated_base_salary + $commission + $additions, 2);
        $available = max(0.0, round($gross - $netPay, 2));            // potongan yang benar-benar berlaku

        $scale = $itemDeductionTotal > $available && $itemDeductionTotal > 0 ? $available / $itemDeductionTotal : 1.0;
        $itemCreditTotal = $scale < 1.0 ? $available : $itemDeductionTotal;
        $attendanceCredit = max(0.0, round($available - $itemCreditTotal, 2));

        if ($attendanceCredit > 0 && ! $potongan) {
            throw new RuntimeException('Akun Beban Potongan Telat/Alpha (6120) tidak ditemukan — periksa menu Bagan Akun.');
        }

        $lines = [['chart_of_account_id' => $gajiPokok->id, 'debit' => (float) $payroll->prorated_base_salary]];

        if ($commission > 0) {
            $lines[] = ['chart_of_account_id' => $komisiTeknisi->id, 'debit' => $commission];
        }

        foreach ($earnings->groupBy(fn ($i) => $i->category->chart_of_account_id) as $accountId => $group) {
            $lines[] = ['chart_of_account_id' => (int) $accountId, 'debit' => round((float) $group->sum('amount'), 2)];
        }

        $creditByAccount = [];

        foreach ($itemDeductions as $item) {
            $accountId = $item->category->chart_of_account_id;
            $creditByAccount[$accountId] = ($creditByAccount[$accountId] ?? 0.0) + (float) $item->amount * $scale;
        }

        // Pembulatan: selisih sen akibat penskalaan dibebankan ke baris potongan terbesar supaya jumlahnya tepat.
        if ($creditByAccount) {
            $creditByAccount = array_map(fn ($v) => round($v, 2), $creditByAccount);
            $diff = round($itemCreditTotal - array_sum($creditByAccount), 2);
            if ($diff != 0.0) {
                arsort($creditByAccount);
                $firstKey = array_key_first($creditByAccount);
                $creditByAccount[$firstKey] = round($creditByAccount[$firstKey] + $diff, 2);
            }
        }

        foreach ($creditByAccount as $accountId => $amount) {
            if ($amount > 0) {
                $lines[] = ['chart_of_account_id' => (int) $accountId, 'credit' => $amount];
            }
        }

        if ($attendanceCredit > 0) {
            $lines[] = ['chart_of_account_id' => $potongan->id, 'credit' => $attendanceCredit];
        }

        if ($netPay > 0) {
            $lines[] = ['chart_of_account_id' => $cash->id, 'credit' => $netPay];
        }

        return $lines;
    }
}
